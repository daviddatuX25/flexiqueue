# Phase C — Token–Program Association — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (Phase C), [central-edge-v2-final.md](../central-edge-v2-final.md) (Phase C — Token–Program Association)  
**Applied rules:** Database Optimizer (pivot table, indexes, SQLite + MariaDB), Security Engineer (no side effects on status/sessions, clear verification)

**Goal:** Many-to-many between tokens and programs via `program_token` pivot; assign/unassign (including bulk by pattern); token may belong to zero, one, or many programs; no change to token `status` or active sessions when assigning.

**Status:** Draft — ready for implementation when Phase C is prioritized.

---

## Delegateable tasks

### Task C.1 — `program_token` pivot migration (SQLite + MariaDB)

**Scope:** Pivot table, indexes, both drivers.

**Steps:**

1. **Migration: `program_token` table**
   - Columns: `program_id` (bigInteger unsigned, FK to `programs.id`), `token_id` (bigInteger unsigned, FK to `tokens.id`), `created_at` (timestamp nullable or default).
   - Primary key: composite `(program_id, token_id)`.
   - Foreign keys: `program_id` references `programs(id)`, `token_id` references `tokens(id)`. **Product choice: cascade** — deleting a program or token removes pivot rows (no orphans); implemented in migration `2026_03_13_200002_create_program_token_table.php`.
   - Implement for **both** SQLite and MariaDB. Use Laravel migration API so it stays portable (e.g. `Schema::create('program_token', ...)` with `$table->foreignId('program_id')->constrained()->cascadeOnDelete();` etc.).

2. **Indexes**
   - Composite primary key already gives index on `(program_id, token_id)`. For "all tokens of a program" and "all programs of a token" queries, consider: unique composite PK covers lookups by (program_id, token_id); add index on `token_id` if not covered (e.g. for `Token::programs()`). Many drivers create a unique index for PK; an extra index on `token_id` alone speeds reverse lookups. Per Database Optimizer: index FKs used in joins — here `token_id` is used when loading programs for a token.

3. **Reversible**
   - `down()`: `Schema::dropIfExists('program_token')`.

4. **Tests**
   - Migration runs on SQLite and MariaDB in CI.
   - After migration, table exists with correct columns and PK; FKs work (insert valid program_id and token_id; insert invalid id fails or is rejected).

**Files:**

- `database/migrations/xxxx_create_program_token_table.php`
- `tests/Feature/Migrations/ProgramTokenMigrationTest.php` (or in a general migration test)

---

### Task C.2 — Model relationships (`Program::tokens()`, `Token::programs()`)

**Scope:** Eloquent many-to-many; pivot access if needed.

**Steps:**

1. **Program model**
   - `Program::tokens()`: `return $this->belongsToMany(Token::class, 'program_token');` (with timestamps if `created_at` on pivot is used).
   - Ensure inverse is defined so that package exporter (Phase D) can use `$program->tokens` or `$program->tokens()->get()`.

2. **Token model**
   - `Token::programs()`: `return $this->belongsToMany(Program::class, 'program_token');`.

3. **Pivot**
   - Use default pivot table name `program_token` (Laravel convention). If pivot has only `program_id`, `token_id`, `created_at`, no custom pivot model required unless you need to add behavior.

4. **Tests**
   - Unit or feature: create program and token, attach via `$program->tokens()->attach($token->id)`; assert `$program->tokens->contains($token)` and `$token->programs->contains($program)`.
   - Attach same token to second program; assert token has 2 programs, each program has expected tokens. Detach from one program; assert counts.

**Files:**

- `app/Models/Program.php` (add `tokens()` relationship)
- `app/Models/Token.php` (add `programs()` relationship)
- `tests/Unit/Models/ProgramTokenRelationshipTest.php` or feature test

---

### Task C.3 — Admin UI: assign/unassign tokens to programs; bulk by pattern

**Scope:** API and/or Inertia actions for assign, unassign, and bulk assign by pattern (e.g. `physical_id` like `A*`).

**Steps:**

1. **Assign single token to program**
   - Endpoint or action: e.g. `POST /api/admin/programs/{program}/tokens` with `token_id` (or array of token ids). Idempotent: if already attached, no error. Attach via `$program->tokens()->syncWithoutDetaching([$tokenId])` (or attach). Return 200/201.

2. **Unassign token from program**
   - Endpoint or action: e.g. `DELETE /api/admin/programs/{program}/tokens/{token}`. Detach. No change to token’s `status` or any `queue_sessions` (only pivot row removed). Return 200/204.

3. **Bulk assign by pattern**
   - Endpoint or action: e.g. `POST /api/admin/programs/{program}/tokens/bulk` with body like `{ "pattern": "A*" }` or `{ "physical_id_pattern": "A%" }`. Interpret pattern as match on `tokens.physical_id` (e.g. SQL `LIKE` or safe pattern). Query tokens matching pattern (and optionally filter by site if multi-tenant), then `syncWithoutDetaching($tokenIds)`. Return count of tokens added (and optionally list of ids). Validate pattern to avoid full-table scan (e.g. require at least one non-wildcard character or limit length).

4. **Authorization**
   - Only users allowed to manage the program (and its site) can assign/unassign. Use existing admin auth and policy or scope by `program.site_id` vs current user’s site.

5. **Tests**
   - Assign token to program → token appears in `$program->tokens`. Unassign → token no longer in `$program->tokens`. Token’s `status` and existing sessions unchanged.
   - Bulk assign with pattern matching 3 tokens → program has 3 more tokens; idempotent second call → no duplicates. Bulk with pattern matching 0 → 0 attached, 200.

**Files:**

- `app/Http/Controllers/Api/Admin/ProgramTokenController.php` (or actions under ProgramController)
- `app/Services/ProgramTokenService.php` (optional: bulk pattern logic)
- `app/Http/Requests/AssignTokensRequest.php`, `BulkAssignTokensRequest.php` (validation for pattern)
- `routes/api.php` (program token routes)
- `tests/Feature/Api/Admin/ProgramTokenAssignmentTest.php`
- Frontend: admin program detail/edit page components for token list, assign/unassign buttons, bulk pattern input (if Inertia; list under same task or separate UI bead)

---

### Task C.4 — Verification: token in multiple programs; no side effects

**Scope:** Assert invariants (tests) and document behavior for package exporter (Phase D). Cover cases where tokens already participate in live sessions when pivot rows change.

**Steps:**

1. **Invariants**
   - Assigning or detaching a token to/from a program does **not** change `tokens.status`.
   - Assigning or detaching does **not** modify any existing `queue_sessions` rows (no session’s `program_id`, `token_id`, or `status` changed by pivot changes).
   - A token can be in 0, 1, or many programs simultaneously; a program has many tokens via pivot.

2. **Test scenarios (feature, API level)**
   - **C.4.1** — Token T in Program A. Create a session for T in Program A (`queue_sessions` row with `program_id = A.id`, `token_id = T.id`). Use the admin API to assign T to Program B via `POST /api/admin/programs/{programB}/tokens`. Assert:
     - The session row still exists.
     - The session’s `program_id` is still A and `status` is unchanged.
     - `program_token` now contains `(A.id, T.id)` and `(B.id, T.id)`.
     - `tokens.status` for T is unchanged.
   - **C.4.2** — Token T in Programs A and B. Create a session for T in Program A. Call `DELETE /api/admin/programs/{programA}/tokens/{T}`. Assert:
     - The session row still exists and still points to Program A and Token T.
     - `program_token` row `(A.id, T.id)` is deleted; `(B.id, T.id)` remains.
     - `tokens.status` for T is unchanged.
   - Package exporter (Phase D): when exporting Program A, only tokens in `program_token` for that program are included; tokens only in Program B are not in A’s package. (Asserted in Phase D; here we document that exporter will use `$program->tokens()`.)

3. **Edge cases**
   - Assign same token twice to same program → idempotent, no duplicate pivot row (already covered by existing tests).
   - Unassign token not in program → 200/204, no error (already covered by existing tests).
   - Bulk assign with pattern that includes tokens already in program → no duplicates (syncWithoutDetaching); covered by C.3 tests.

**Files:**

- `tests/Feature/Api/Admin/ProgramTokenSideEffectsTest.php`
- Optional: short note in `docs/plans/central-edge/specs/` or in code (e.g. ProgramTokenService) that package export uses `Program::tokens()` for token list

---

### Task C.S — Stabilize: bulk assignment edge cases, UI polish

**Scope:** Harden bulk assignment patterns and admin UI feedback without changing core assignment behavior.

**Behavior and edge cases**

1. **Pattern validation (backend)**
   - `BulkAssignTokensRequest` rejects patterns that are effectively “match everything” (e.g. `"*"`, `"%"`, `"%%"`, `"__"`): after trimming whitespace, if all characters are wildcards (`*`, `%`, `_`), validation fails with 422 and an error on `pattern` (e.g. “The pattern must include at least one non-wildcard character.”).
   - Existing length constraints (min/max) remain in place.

2. **Bulk assign response contract (backend)**
   - `ProgramTokenController::bulkStore()` continues to return:
     - `count` — number of tokens matched by the pattern and sent to `syncWithoutDetaching()`.
     - `token_ids` — array of token ids considered.
   - Additionally returns:
     - `added_count` — alias for `count` to simplify UI messages.

3. **Admin UI polish (Tokens tab)**
   - On success, the Tokens tab in `Admin/Programs/Show.svelte`:
     - Uses `count`/`added_count` from the API to compute the toast message (`"1 token assigned."` vs `"N tokens assigned."`).
     - Refreshes the token list after bulk assign so the table reflects changes.
   - On error, the UI:
     - Shows the server-side validation message for `pattern` (when present) instead of a generic “Failed to bulk assign tokens.”
     - Falls back to the API `message` or existing generic copy when no validation message is available.
   - Copy clarifies that patterns composed only of wildcards are not allowed.

**Tests (feature-level)**

- Extend `tests/Feature/Api/Admin/ProgramTokenAssignmentTest.php`:
  - **C.S.1** — `pattern="*"` returns 422 with `pattern` error; no `program_token` rows created.
  - **C.S.2** — `pattern="%"` (and similar all-wildcard values) returns 422; no `program_token` rows created.
  - Existing bulk-assign tests continue to pass (valid patterns, idempotency).

---

## File list (Phase C)

| Area | Files |
|------|--------|
| Migrations | `database/migrations/xxxx_create_program_token_table.php` |
| Models | `app/Models/Program.php`, `app/Models/Token.php` (relationships) |
| Controllers | `app/Http/Controllers/Api/Admin/ProgramTokenController.php` (or under ProgramController) |
| Services | `app/Services/ProgramTokenService.php` (optional) |
| Requests | `app/Http/Requests/AssignTokensRequest.php`, `BulkAssignTokensRequest.php` |
| Routes | `routes/api.php` (program token routes) |
| Tests | ProgramTokenMigrationTest, ProgramTokenRelationshipTest, ProgramTokenAssignmentTest, ProgramTokenSideEffectsTest |
| Frontend | Admin program token assignment UI (assign/unassign, bulk by pattern) — can be same or separate bead |

---

## Notes

- **Token deactivation:** Spec states that token deactivation on central does not propagate to Pi until next package re-download; `token_deactivation_list` in package (Phase D) is the mitigation. Phase C does not implement deactivation logic; it only ensures pivot and assignment behavior.
- **Package exporter (Phase D):** Will filter exported tokens by `program_token` for the target program; no code change in Phase C beyond having `Program::tokens()` available.
- **Bulk pattern:** Use parameterized queries for pattern (e.g. `where('physical_id', 'like', $safePattern)`) to avoid injection. Restrict pattern length and wildcards if needed (e.g. single `%` or `*` at end only).
