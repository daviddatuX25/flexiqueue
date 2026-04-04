# Consumable Tokens & Tokenless Mode

**Status:** Future feature — for planning only. Not in current scope until explicitly prioritized.

**Purpose:** Introduce two alternative token modes alongside the existing reusable-token system: **consumable tokens** (one-time, printed/handwritten at triage) and **tokenless mode** (no physical token; session identified by client name/alias). Document data model changes, service-layer design, UI/UX flows, integration points, edge mode compatibility, and the full interaction matrix with identity binding.

---

## Part 1 — Executive Summary

### Problem

FlexiQueue's current token system is built around **reusable physical tokens** — pre-created laminated cards with printed QR codes (the `tokens` table). Each token has a permanent `physical_id`, an immutable `qr_code_hash`, and optional pre-generated TTS. When a session ends, the token is released and reused. This model works for established offices with permanent hardware but doesn't cover:

1. **High-volume / disposable scenarios** — Offices that want a thermal-printed receipt per visit (like a bank or hospital). No pre-created inventory, no QR card management.
2. **Low-resource / ad-hoc scenarios** — Small offices, community outreach, or edge-mode Pi deployments where no printer or token cards are available. Clients are called by name.

### Solution

A new per-program setting `token_mode` with three values:

| Mode | How the client is identified | Token lifecycle |
|------|------------------------------|-----------------|
| `reusable` (default) | Physical QR card scanned at triage | Created once → used across many sessions → recycled |
| `consumable` | Receipt printed at triage (or handwritten fallback) | Created per-session → used once → marked `used` |
| `tokenless` | Client name / alias given at triage | Synthetic token created for FK integrity → never physically dispensed |

Both `consumable` and `tokenless` modes support the existing identity binding system (`disabled` / `optional` / `required`) exactly as described in `FLEXIQUEUE-XM2O-CONFIGURABLE-TOKEN-CLIENT-BINDING.md`.

---

## Part 2 — Current System Context

### 2.1 Existing Token Lifecycle

```
Admin batch-creates tokens                    Client presents physical card at triage
(TokenService::batchCreate)                   Staff/public scans QR or enters physical_id
        │                                              │
        ▼                                              ▼
   ┌──────────┐     SessionService::bind()      ┌──────────┐
   │  Token   │ ────────────────────────────►   │  Session  │
   │ available │     Sets token → in_use         │ waiting   │
   └──────────┘     Creates queue_sessions row   └──────────┘
        │                                              │
        │        SessionService::finishSession()       │
        ◄──────────────────────────────────────────────┘
   Token → available                          Session → completed/cancelled/no_show
   (ready for next client)
```

**Key data relationships:**
- `queue_sessions.token_id` (FK, required) → `tokens.id`
- `queue_sessions.alias` = `token.physical_id` (set at bind time)
- `DisplayBoardService` uses `session.alias` for all display output (now-serving, waiting lists, activity log)
- `StationActivity` event broadcasts `session.alias` for real-time display updates
- TTS announces `session.alias` (pre-generated audio keyed by token, or browser fallback using alias text)

### 2.2 Existing Files That Will Be Impacted

| File | Current Role | Impact |
|------|-------------|--------|
| `app/Support/ProgramSettings.php` | All program-level settings | Add `token_mode` + consumable settings |
| `app/Models/Token.php` | Reusable token model | Add `is_consumable` flag |
| `app/Models/Session.php` | Queue session model | Add `alias_source` column |
| `app/Services/SessionService.php` | Session lifecycle (bind/call/serve/transfer/complete/cancel) | Route bind by mode; change finishSession for consumable tokens |
| `app/Services/TokenService.php` | Batch create + lookup | Extended by new ConsumableTokenService |
| `app/Services/TokenPrintService.php` | QR generation for batch card printing | Not changed — new receipt service separate |
| `app/Http/Controllers/Api/SessionController.php` | Staff bind endpoint | Route by token_mode |
| `app/Http/Controllers/Api/PublicTriageController.php` | Public bind endpoint | Route by token_mode |
| `app/Http/Requests/BindSessionRequest.php` | Bind validation rules | Conditional rules by token_mode |
| `app/Http/Controllers/TriagePageController.php` | Staff triage page | Pass token_mode to frontend |
| `app/Services/IdentityBindingService.php` | Identity binding resolution | No change — works with all modes |
| `app/Services/DisplayBoardService.php` | Display board data | No change — uses session.alias which all modes populate |
| `app/Models/PrintSetting.php` | Reusable token card print template | Not changed — new model for consumable receipts |

### 2.3 Existing Identity Binding System (Unchanged)

The identity binding system from `FLEXIQUEUE-XM2O-CONFIGURABLE-TOKEN-CLIENT-BINDING.md` is **fully orthogonal** to token mode. It controls *who* the session is tied to; token mode controls *what* identifier the client carries.

Current binding modes (stored in `programs.settings.identity_binding_mode`):
- `disabled` — no client binding UI; session has no `client_id`
- `optional` — binding available but skippable
- `required` — triage blocked until a client is bound

**This system remains unchanged.** Both consumable and tokenless modes use `IdentityBindingService::resolve()` identically.

---

## Part 3 — Consumable Token Mode — Detailed Design

### 3.1 Concept

When `token_mode = consumable`, triage **creates a new, one-time token** for each session. The token is printed on a thermal printer as a receipt, or if the printer is unavailable, staff writes the number down and hands it to the client.

### 3.2 Token Auto-Numbering

**Sequence rule:** Daily counter per-program, resetting at midnight.

- Format: `{prefix}{zero-padded number}` (e.g., `A-001`, `Q-042`, `003`)
- Prefix: configurable per-program via `consumable_token_prefix` setting (default: `''`, meaning just the number)
- Padding: 3 digits minimum (configurable via `consumable_token_pad_digits`, default `3`, range 1–5)
- Maximum per day: 99999 (5-digit max; exceeding this throws a clear error)

**Sequence implementation — race-safe:**
```php
// Atomic get-and-increment using DB to prevent race conditions
// Uses a daily counter row in a new `consumable_token_sequences` table
DB::table('consumable_token_sequences')
    ->where('program_id', $programId)
    ->where('date', today()->toDateString())
    ->lockForUpdate()
    ->increment('last_number');
```

**Table: `consumable_token_sequences`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | PK | Auto-increment |
| `program_id` | FK → programs | Scoped per program |
| `date` | date | The day this sequence is for |
| `last_number` | int | The last number issued |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Unique index: `(program_id, date)` — one row per program per day. Row created on first bind of the day (upsert pattern).

### 3.3 Consumable Token Record

A consumable token is a normal `tokens` table row with additional flags:

| Column | Value (consumable) | Value (reusable) |
|--------|-------------------|------------------|
| `physical_id` | Auto-generated alias (e.g., `A-042`) | Admin-set (e.g., `A3`) |
| `qr_code_hash` | Auto-generated (SHA-256 of program_id + date + sequence + random) | Admin-set (immutable) |
| `pronounce_as` | `'word'` (default for consumable) | `'letters'` or `'word'` |
| `status` | Created as `in_use` immediately | Created as `available` |
| `is_consumable` | `true` | `false` |
| `tts_audio_path` | `null` (no pre-generation) | Pre-generated or null |
| `tts_status` | `null` | `pending` / `generated` / `failed` |
| `tts_pre_generate_enabled` | `false` | `true` (configurable) |

**Lifecycle difference from reusable:**
```
Reusable:    available → in_use → available → in_use → available → ...
Consumable:  [created at bind] → in_use → used (permanent — never reused)
```

### 3.4 Consumable Token Receipt — Print Output

When a consumable token session is created, the system generates a **print-ready receipt**:

```
┌──────────────────────────┐
│    [Program Name]         │
│                           │
│         A-042             │  ← Large, bold, centered
│                           │
│     ┌───────────┐         │
│     │  QR Code  │         │  ← Optional: encodes status URL
│     └───────────┘         │
│                           │
│   Track: Regular          │  ← Optional
│   Category: PWD           │  ← Optional (if not "Regular")
│   Date: 2026-03-12        │
│   Time: 14:32             │
│                           │
│   [Custom footer text]    │  ← Optional
└──────────────────────────┘
```

**Print delivery strategy (phased):**

| Phase | Method | When |
|-------|--------|------|
| **Phase 1 (initial)** | Browser print dialog | Always — render a print-optimized `<div>`, trigger `window.print()` or open a print-friendly popup |
| **Phase 2 (follow-up)** | ESC/POS thermal printer | USB/network thermal printer integration via server-side ESC/POS library or client-side WebUSB |

**Fallback when print is unavailable or disabled:**

The frontend shows a prominent modal/banner:

```
┌─────────────────────────────────────────────┐
│  ✋ Please write down and give to client:   │
│                                             │
│              A-042                          │  ← Very large text
│                                             │
│  Track: Regular · PWD                       │
│  [Copy to Clipboard]  [Dismiss]             │
└─────────────────────────────────────────────┘
```

This always appears when `consumable_print_enabled = false`, and also serves as a fallback if the browser print dialog fails or is cancelled.

### 3.5 Consumable Print Settings (per-program)

All stored in `programs.settings`:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `consumable_token_prefix` | string | `''` | Prefix before the number (e.g., `'A-'`, `'Q'`) |
| `consumable_token_pad_digits` | int (1–5) | `3` | Zero-pad width |
| `consumable_print_enabled` | bool | `true` | Whether to trigger browser print dialog after bind |
| `consumable_print_show_qr` | bool | `true` | Include QR code on receipt |
| `consumable_print_show_track` | bool | `true` | Include track name on receipt |
| `consumable_print_show_category` | bool | `true` | Include client category if not "Regular" |
| `consumable_print_footer` | string | `''` | Custom footer text on receipt |

### 3.6 Triage Flow — Consumable Mode

#### Staff triage (`Triage/Index.svelte`)

```
Current (reusable):               Consumable:
─────────────────                 ─────────────────
1. Scan token QR / enter ID  →   [Step removed — no token scan]
2. Token found, show status      
3. Select track + category       1. Select track + category
4. [Optional] Identity binding   2. [Optional] Identity binding
5. Confirm → bind                3. Confirm → auto-create token + bind
                                  4. Show print receipt / write-down fallback
```

**API call change:**
- Currently: `POST /api/sessions/bind` with `{ qr_hash, track_id, client_category, client_binding? }`
- Consumable: `POST /api/sessions/bind` with `{ track_id, client_category, client_binding? }` (no `qr_hash`)
- Backend detects `token_mode = consumable` from program settings, auto-creates token

#### Public triage (`Triage/PublicStart.svelte`)

Same flow but simplified for self-serve:
1. Select track + category
2. [Optional/required] Identity scan/binding
3. Confirm → receipt shown on screen with large number + QR
4. Client can print or screenshot

#### Response change

Bind response adds a `consumable_receipt` payload when in consumable mode:

```json
{
  "session": { "id": 1, "alias": "A-042", ... },
  "token": { "physical_id": "A-042", "status": "in_use" },
  "consumable_receipt": {
    "alias": "A-042",
    "qr_data_uri": "data:image/png;base64,...",
    "qr_hash": "abc123...",
    "track_name": "Regular",
    "client_category": "PWD",
    "program_name": "MSWDO Queue",
    "date": "2026-03-12",
    "time": "14:32",
    "footer": "Thank you for visiting."
  }
}
```

### 3.7 Session Finish — Consumable Token Release

In `SessionService::finishSession()`, the token release changes for consumable tokens:

```diff
 $token->update([
-    'status' => 'available',
+    'status' => $token->is_consumable ? 'used' : 'available',
     'current_session_id' => null,
 ]);
```

The `used` status is a new terminal status for consumable tokens. They are never reused.

### 3.8 Consumable Token Cleanup

Old consumable tokens accumulate over time. A scheduled artisan command prunes them:

```
php artisan flexiqueue:cleanup-consumable-tokens --older-than=30
```

- Deletes (soft-deletes) consumable tokens with status `used` older than N days (default 30)
- Runs as a scheduled job (configurable) or manually
- Logs count of cleaned tokens

---

## Part 4 — Tokenless Mode — Detailed Design

### 4.1 Concept

When `token_mode = tokenless`, no physical token is dispensed. The session is identified by a **client name or alias** entered during triage. The display board shows this name; TTS announces it.

### 4.2 Alias Source

The session's `alias` (what appears on the display board and is announced) comes from:

| Scenario | Alias source | Example |
|----------|-------------|---------|
| Identity binding `required` + client bound | `client.name` (auto-populated) | "Maria Santos" |
| Identity binding `optional` + client bound | `client.name` (auto-populated, overridable) | "Maria Santos" |
| Identity binding `optional` + client skipped | Staff-entered name/alias | "Maria" |
| Identity binding `disabled` | Staff-entered name/alias | "Juan" |

**Public triage (tokenless):** Client enters their name. If binding is required, they must scan an ID and the name comes from the matched client record.

### 4.3 Synthetic Token Record

To preserve the `queue_sessions.token_id` FK and all downstream systems (display board, transaction logs, events, TTS), tokenless sessions create a **synthetic token record**:

| Column | Value (synthetic) |
|--------|-------------------|
| `physical_id` | The alias (e.g., `"Maria Santos"`) |
| `qr_code_hash` | Auto-generated unique hash |
| `pronounce_as` | `'word'` |
| `status` | Created as `in_use` immediately |
| `is_consumable` | `true` (treated same as consumable for lifecycle) |
| `tts_audio_path` | `null` |

**Why not make `token_id` nullable?** Making `token_id` nullable on `queue_sessions` would require auditing and updating:
- `SessionService.php` — `finishSession()` accesses `$session->token` directly (line 836)
- `SessionController.php` — bind response returns `result['token']` (line 154)
- `DisplayBoardService.php` — indirectly via session relationships
- `StationActivity` event — passes `$session->token_id`
- `SessionResource.php` — may reference token
- All test files referencing token relationships
- The synthetic token approach is **zero-risk** — all existing code works unchanged.

### 4.4 Triage Flow — Tokenless Mode

#### Staff triage

```
Current (reusable):               Tokenless:
─────────────────                 ─────────────────
1. Scan token QR / enter ID  →   [Step removed — no token scan]
2. Token found, show status
3. Select track + category       1. Enter client name/alias [NEW STEP]
                                    └ If binding enabled + client bound:
                                      auto-fills from client.name
4. [Optional] Identity binding   2. Select track + category
5. Confirm → bind                3. [Optional] Identity binding
                                  4. Confirm → create synthetic token + bind
```

**Alias input rules:**
- Required field (cannot be empty)
- Max length: 100 characters
- If identity binding is `required`: alias field is read-only, auto-populated from resolved `client.name`
- If identity binding is `optional` and a client is bound: alias auto-populated but staff can override
- If identity binding is `disabled`: alias is free-text entry

#### Public triage

1. Enter your name (required field)
2. Select track + category
3. [Optional/required] ID scan
4. Confirm → display shows: "Your queue name is: **Maria Santos**. Listen for your name."

### 4.5 Duplicate Alias Handling

Since tokenless aliases are human names (not unique sequential numbers), duplicates are possible. Strategy:

- **Do NOT enforce uniqueness on alias.** Two "Maria" clients may genuinely be in the queue simultaneously.
- Display board already differentiates by station context ("`Maria` at Window 1" vs "`Maria` at Window 3").
- The per-session `id` and synthetic token `id` remain unique internally.
- **Optional safeguard:** If an active session with the same alias exists at the same station, show a confirmation prompt: "Another client named 'Maria' is currently in the queue. Proceed?" (Staff can add a distinguishing suffix like "Maria S." if needed.)

---

## Part 5 — Identity Binding Interaction Matrix

Full 9-combination matrix showing how `token_mode` and `identity_binding_mode` interact:

### 5.1 Reusable × Binding (Existing — No Changes)

| Binding Mode | Triage Flow | Session Alias Source |
|-------------|-------------|---------------------|
| `disabled` | Scan token → select track → confirm | `token.physical_id` |
| `optional` | Scan token → select track → [optional: bind client] → confirm | `token.physical_id` |
| `required` | Scan token → select track → bind client (required) → confirm | `token.physical_id` |

### 5.2 Consumable × Binding

| Binding Mode | Triage Flow | Session Alias Source |
|-------------|-------------|---------------------|
| `disabled` | Select track → confirm → print receipt | Auto-generated number (e.g., `A-042`) |
| `optional` | Select track → [optional: bind client] → confirm → print receipt | Auto-generated number; receipt can include client name |
| `required` | Select track → bind client (required) → confirm → print receipt | Auto-generated number; receipt includes client name |

**Print receipt enhancement when client is bound:** The receipt can optionally include the bound client's name (for staff reference), but the **queue alias remains the number** — this is important because numbers are unambiguous for TTS and display.

### 5.3 Tokenless × Binding

| Binding Mode | Triage Flow | Session Alias Source |
|-------------|-------------|---------------------|
| `disabled` | Enter name → select track → confirm | Staff-entered name |
| `optional` | Enter name → select track → [optional: bind client, name auto-fills] → confirm | Staff-entered name OR client.name |
| `required` | [Name auto-filled from binding] → select track → bind client (required) → confirm | `client.name` (auto, read-only) |

---

## Part 6 — Data Model Changes

### 6.1 New Column: `tokens.is_consumable`

```sql
-- Migration: add_is_consumable_to_tokens_table
ALTER TABLE tokens ADD COLUMN is_consumable BOOLEAN NOT NULL DEFAULT FALSE;
```

Both SQLite and MariaDB compatible. Index not needed (low-cardinality boolean; queries don't filter on this alone).

### 6.2 New Token Status: `used`

Current valid statuses: `available`, `in_use`, `deactivated`.
Add: `used` — terminal status for consumed tokens.

No migration needed (status is a string column, not an enum), but update any validation or documentation that lists valid statuses.

### 6.3 New Column: `queue_sessions.alias_source`

```sql
-- Migration: add_alias_source_to_queue_sessions_table
ALTER TABLE queue_sessions ADD COLUMN alias_source VARCHAR(20) NOT NULL DEFAULT 'token';
```

Valid values: `token`, `auto_number`, `client_name`, `manual_alias`

Purpose: analytics can distinguish how a session was identified. Not used in business logic.

### 6.4 New Table: `consumable_token_sequences`

```sql
CREATE TABLE consumable_token_sequences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,   -- SQLite
    -- id BIGINT UNSIGNED AUTO_INCREMENT,   -- MariaDB
    program_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    last_number INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE(program_id, date),
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
);
```

### 6.5 New Table: `consumable_print_settings` (Optional — Alternative: use `programs.settings` JSON)

**Recommendation:** Store in `programs.settings` JSON (consistent with all other program settings). No new table needed. Accessors added to `ProgramSettings.php`.

---

## Part 7 — Service Layer Changes

### 7.1 New Service: `ConsumableTokenService`

**Location:** `app/Services/ConsumableTokenService.php`

```php
class ConsumableTokenService
{
    /**
     * Get the next sequence number for today for a given program.
     * Race-safe: uses DB lock to prevent duplicate numbers.
     *
     * @return int The next number (1-based)
     */
    public function getNextSequenceNumber(int $programId): int;

    /**
     * Build the full alias string from a sequence number and program settings.
     * E.g., prefix "A-" + pad to 3 digits → "A-042"
     *
     * @return string The formatted alias
     */
    public function buildAlias(int $sequenceNumber, ProgramSettings $settings): string;

    /**
     * Create a consumable token record in the tokens table.
     * Sets is_consumable=true, status=in_use, generates unique qr_code_hash.
     *
     * @return Token The created token (already persisted)
     */
    public function createConsumableToken(string $alias, int $programId): Token;

    /**
     * Create a synthetic token for tokenless mode.
     * Same as createConsumableToken but alias = client name / manual alias.
     *
     * @return Token The created token (already persisted)
     */
    public function createSyntheticToken(string $alias): Token;

    /**
     * Generate the receipt data payload for a consumable token session.
     * Includes QR data URI, program name, track, date/time, footer.
     *
     * @return array The receipt payload for the API response
     */
    public function buildReceiptPayload(
        Token $token,
        Session $session,
        Program $program,
        ProgramSettings $settings
    ): array;

    /**
     * Cleanup: soft-delete consumed tokens older than N days.
     *
     * @return int Number of tokens deleted
     */
    public function cleanupOldTokens(int $olderThanDays = 30): int;
}
```

### 7.2 Modified Service: `SessionService`

**`bind()` method changes:**

The `bind()` method signature currently requires `string $qrHash` as first argument. Three options:

| Option | Approach | Risk |
|--------|----------|------|
| A. Overload | New methods `bindConsumable(...)`, `bindTokenless(...)` alongside existing `bind(...)` | Low risk but code duplication |
| B. Refactor | Make `$qrHash` nullable, add `?string $alias` parameter, detect mode from program | Medium risk — one method, more complex |
| **C. Wrapper** | New public method `bindByMode(...)` that reads token_mode and delegates to `bind()` or new helpers | **Recommended** — clean separation, existing `bind()` unchanged |

**Recommendation: Option C**

```php
public function bindByMode(
    int $trackId,
    ?string $clientCategory,
    ?int $staffUserId,
    ?array $clientBindingPayload = null,
    ?string $bindingSource = null,
    ?int $identityRegistrationId = null,
    // New parameters:
    ?string $qrHash = null,       // For reusable mode (existing)
    ?string $alias = null          // For tokenless mode
): array
{
    $program = Program::where('is_active', true)->first();
    $settings = $program->settings();
    
    return match ($settings->getTokenMode()) {
        'reusable' => $this->bind($qrHash, $trackId, ...),
        'consumable' => $this->bindConsumable($program, $trackId, ...),
        'tokenless' => $this->bindTokenless($program, $alias, $trackId, ...),
    };
}
```

**`finishSession()` method changes:**

```php
// Line 849-852 in current code:
$token->update([
    'status' => $token->is_consumable ? 'used' : 'available',
    'current_session_id' => null,
]);
```

### 7.3 No Changes Required

These services work as-is with all token modes because they operate on `session.alias` and `session.token_id` which all modes populate:

- `DisplayBoardService` — uses `session.alias` for all display output ✅
- `IdentityBindingService` — resolves client binding independently of token ✅
- `FlowEngine` — uses `session.track_id` ✅
- `StationSelectionService` — uses `process_id` + `program_id` ✅
- `StationQueueService` — uses `station_id` ✅
- `AnalyticsService` — uses `program_id` and session data ✅

---

## Part 8 — Controller & Request Changes

### 8.1 `BindSessionRequest` Changes

```php
// Make qr_hash conditional on token mode
public function rules(): array
{
    $tokenMode = $this->resolveTokenMode();

    return [
        'qr_hash' => $tokenMode === 'reusable'
            ? ['required_without:identity_registration_request', 'string', 'max:64']
            : ['prohibited'],             // Not allowed in consumable/tokenless
        'alias' => $tokenMode === 'tokenless'
            ? ['required', 'string', 'max:100']
            : ['prohibited'],             // Only for tokenless
        'track_id' => ['required', 'integer'],  // Always required (was required_with:qr_hash)
        // ... rest unchanged
    ];
}

private function resolveTokenMode(): string
{
    $program = Program::where('is_active', true)->first();
    return $program?->settings()->getTokenMode() ?? 'reusable';
}
```

### 8.2 `SessionController::bind()` Changes

```php
public function bind(BindSessionRequest $request): JsonResponse
{
    try {
        $result = $this->sessionService->bindByMode(
            (int) $request->validated('track_id'),
            $request->validated('client_category'),
            $request->user()->id,
            $request->validated('client_binding'),
            null,
            null,
            $request->validated('qr_hash'),     // null for consumable/tokenless
            $request->validated('alias')         // null for reusable/consumable
        );
    } catch (...) { /* existing error handling */ }

    $response = [
        'session' => [...],
        'token' => $result['token'],
    ];

    // Add consumable receipt if applicable
    if (isset($result['consumable_receipt'])) {
        $response['consumable_receipt'] = $result['consumable_receipt'];
    }

    return response()->json($response, 201);
}
```

### 8.3 `PublicTriageController::bind()` Changes

Same pattern as staff controller — detect mode, route accordingly. The public controller additionally gates on `allow_public_triage`.

### 8.4 `TriagePageController` Changes

Pass token mode to the frontend:

```php
$programPayload = [
    // ... existing fields ...
    'token_mode' => $programSettings->getTokenMode(),
    'consumable_token_prefix' => $programSettings->getConsumableTokenPrefix(),
    'consumable_print_enabled' => $programSettings->getConsumablePrintEnabled(),
    // etc.
];
```

---

## Part 9 — Frontend Changes

### 9.1 Staff Triage (`Triage/Index.svelte`)

**Mode detection:** Read `activeProgram.token_mode` from props.

**Conditional rendering:**

```svelte
{#if tokenMode === 'reusable'}
    <!-- Existing: Token scan / QR input -->
    <TokenScanner ... />
{:else if tokenMode === 'consumable'}
    <!-- No token scan — go straight to track/category/binding -->
    <TrackCategorySelector ... />
{:else if tokenMode === 'tokenless'}
    <!-- Name/alias input instead of token scan -->
    <AliasInput bind:value={alias} disabled={bindingRequired && boundClient} />
    <TrackCategorySelector ... />
{/if}
```

**Post-bind behavior (consumable):**

```svelte
{#if bindResult?.consumable_receipt}
    <ConsumableTokenReceipt
        receipt={bindResult.consumable_receipt}
        printEnabled={program.consumable_print_enabled}
        on:dismiss={resetTriage}
    />
{/if}
```

### 9.2 New Component: `ConsumableTokenReceipt.svelte`

A modal/overlay component that:
1. Renders a print-optimized receipt layout (hidden from normal view, visible via CSS `@media print`)
2. Shows the alias prominently on screen
3. If `printEnabled`: auto-triggers `window.print()` (can be cancelled)
4. Shows fallback "write down" instruction
5. Has a "Copy Number" button and "Dismiss" action

### 9.3 Public Triage (`Triage/PublicStart.svelte`)

Similar mode switching. For consumable mode, after bind:
- Show the receipt directly on screen (large number, optional QR)
- "Print" button for the client to print if they want
- Auto-dismiss after configurable timeout

For tokenless mode:
- Show confirmation: "You are registered as **Maria Santos**. Listen for your name."

### 9.4 Admin Settings Pages

Add to the Program Settings admin page:

```
Token Mode: [Reusable ▼] / [Consumable ▼] / [Tokenless ▼]

── (Visible only when Consumable) ──────────
  Prefix:                [A-    ]
  Number padding digits: [3     ]
  Auto-print receipt:    [✓]
  Show QR on receipt:    [✓]
  Show track on receipt: [✓]
  Show category:         [✓]
  Receipt footer text:   [Thank you for visiting.]
─────────────────────────────────────────────
```

**Warning when changing mode with active sessions:**
> ⚠️ There are N active sessions using reusable tokens. Changing the token mode now will only affect new sessions. Existing sessions will continue with their current tokens.

---

## Part 10 — TTS Strategy Per Mode

| Mode | Token TTS | Display Announcement |
|------|-----------|---------------------|
| **Reusable** | Pre-generated per token (`tts/tokens/{id}.mp3`) | Pre-generated audio + connector + station phrase |
| **Consumable** | **No pre-generation.** Browser fallback (Web Speech API) using alias text. On-demand ElevenLabs generation if TTS account configured. | Browser TTS speaks alias + connector + station phrase |
| **Tokenless** | **No pre-generation.** Browser fallback using alias (client name). On-demand generation if configured. | Browser TTS speaks alias (name) + connector + station phrase |

**Browser TTS fallback already exists** — the display board falls back to `speechSynthesis.speak()` when pre-generated audio is unavailable. Both consumable and tokenless modes use this path naturally.

**Optional enhancement:** On-demand TTS generation for consumable tokens (generate at bind time, cache by alias). This is a future optimization, not required for Phase 1.

---

## Part 11 — Edge Mode Compatibility

Per `CENTRAL-AND-EDGE-VISION.md`:

### 11.1 Consumable Tokens on Edge

| Aspect | Impact |
|--------|--------|
| Token creation | ✅ **Better than reusable** — no pre-created token inventory needed on Pi |
| Sequence counter | ✅ Local `consumable_token_sequences` table — works offline |
| Print receipt | ✅ Local — browser print dialog works offline |
| No sync needed | ✅ Consumable tokens are created locally and never need to be synced from central |
| Token cleanup | ✅ Local cleanup command works offline |

### 11.2 Tokenless on Edge

| Aspect | Impact |
|--------|--------|
| Session creation | ✅ Fully local — no external dependencies |
| Identity binding | ⚠️ Same constraints as reusable mode: binding `required` auto-downgrades to `optional` when offline |
| Client name entry | ✅ No external dependency |

### 11.3 Edge Mode Settings Extension

The `edge_settings` JSON per site (from CENTRAL-AND-EDGE-VISION Phase B) should carry `token_mode` as part of the program config. No special edge-mode handling needed — the token mode is part of `programs.settings` which gets packaged.

---

## Part 12 — Broadcasting & Real-time Impact

**No changes needed.** All broadcasting events use `session.alias` and `session.token_id`, which all three modes populate:

| Event | Data Used | Impact |
|-------|-----------|--------|
| `ClientArrived` | `session.alias`, station | ✅ Works |
| `StatusUpdate` | `session` | ✅ Works |
| `QueueLengthUpdated` | station_id | ✅ Works |
| `NowServing` | `session.alias`, `session.client_category` | ✅ Works |
| `StationActivity` | `session.alias`, `session.token.pronounce_as`, `session.token_id` | ✅ Works |

---

## Part 13 — Migration Safety & Mode Switching

### Changing Token Mode Mid-Program

When admin changes `token_mode` on an active program:

| From → To | What happens to existing sessions |
|-----------|----------------------------------|
| Reusable → Consumable | Existing reusable sessions continue as-is. New sessions use consumable tokens. Reusable tokens remain valid. |
| Reusable → Tokenless | Existing reusable sessions continue. New sessions use aliases. |
| Consumable → Reusable | New sessions require token scan. Any unused consumable tokens stay as `used`. |
| Consumable → Tokenless | New sessions use aliases. |
| Tokenless → Reusable | New sessions require token scan. |
| Tokenless → Consumable | New sessions use consumable tokens. |

**Rule:** Changing token mode **never** affects active sessions. Only new `bind` operations use the new mode. The UI should show a warning if active sessions exist.

---

## Part 14 — Implementation Phases

### Phase 1: Core Consumable Token Mode
1. `ProgramSettings` — add `getTokenMode()` + all consumable settings
2. Migration — add `tokens.is_consumable`, `queue_sessions.alias_source`, create `consumable_token_sequences`
3. `ConsumableTokenService` — sequence generation, token creation, receipt payload
4. `SessionService` — add `bindByMode()`, `bindConsumable()`, modify `finishSession()`
5. `BindSessionRequest` — conditional validation per mode
6. `SessionController` / `PublicTriageController` — route by mode
7. `TriagePageController` — pass token_mode to frontend
8. Frontend — mode switching in `Triage/Index.svelte`, `ConsumableTokenReceipt.svelte`
9. Admin settings UI — token mode selector + consumable settings

### Phase 2: Tokenless Mode
1. `SessionService` — add `bindTokenless()`
2. `ConsumableTokenService` — add `createSyntheticToken()`
3. `BindSessionRequest` — add `alias` field
4. Frontend — alias input in triage, public triage name entry
5. Duplicate alias confirmation logic

### Phase 3: Polish & Edge
1. `ConsumableTokenCleanup` artisan command
2. Admin UI panel for monitoring consumable token counts / daily sequence
3. Edge mode validation (test on Pi)
4. On-demand TTS for consumable/tokenless (optional)
5. ESC/POS thermal printer integration (optional separate scope)

---

## Part 15 — Risk Register

| Risk | Impact | Mitigation |
|------|--------|------------|
| Consumable token sequence race condition | Duplicate numbers in high-concurrency | DB-level `lockForUpdate()` on sequence row; unique constraint on (program_id, date, number) |
| Token table growth (consumable) | DB bloat over time | Scheduled cleanup command; soft-deletes for audit trail; hard-delete after configurable retention |
| Tokenless alias collisions on display | Two "Maria" on display board at same station | Don't enforce uniqueness; show disclaimer/prompt; staff can differentiate with suffix |
| Changing token_mode mid-program | Staff confusion | UI warning; existing sessions unaffected; only new binds use new mode |
| Thermal printer failure | Client has no receipt | Always show "write down" fallback with large visible number |
| Browser print dialog blocked | Receipt not printed | Detect print failure; always show on-screen fallback |
| `queue_sessions.token_id` becomes a FK to synthetic/consumable tokens | Downstream queries include "junk" tokens | `is_consumable` flag cleanly separates; admin token list can filter `WHERE is_consumable = false` |
| TTS quality for names (tokenless) | Browser TTS mispronounces names | Acceptable tradeoff; on-demand ElevenLabs generation as optional future enhancement |

---

## Part 16 — Test Plan Categories

### Unit Tests
- `ProgramSettingsTest` — token_mode getter, consumable settings, defaults
- `ConsumableTokenServiceTest` — sequence generation, alias building, daily reset, race safety
- Token model — `is_consumable` flag, `used` status

### Feature Tests
- Bind (consumable mode) — session created, consumable token created with `is_consumable = true`, receipt payload returned
- Bind (tokenless mode) — session created, synthetic token created, alias from input
- Bind (reusable mode) — remains unchanged (regression)
- FinishSession (consumable) — token set to `used`, not `available`
- FinishSession (reusable) — token set to `available` (regression)
- Identity binding × token mode — all 9 combinations
- Public triage per mode — gated correctly, correct payloads
- Validation: consumable bind rejects `qr_hash`; reusable bind rejects `alias`; tokenless bind requires `alias`

### Browser / UI Tests
- Staff triage renders correct flow per mode
- Consumable receipt modal appears and displays correct data
- Print dialog triggers when enabled
- Fallback "write down" appears when print disabled
- Tokenless alias input appears, auto-fills from binding when bound
- Admin settings: token mode selector, conditional consumable settings

---

*This plan is classified as a **future feature**. Do not schedule or implement without explicit prioritization.*
