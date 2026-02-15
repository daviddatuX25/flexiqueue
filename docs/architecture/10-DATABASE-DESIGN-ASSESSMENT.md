# FlexiQueue — Database Design & ACID Assessment

**Related:** [04-DATA-MODEL.md](04-DATA-MODEL.md) (schema), [06-ERROR-HANDLING.md](06-ERROR-HANDLING.md) (transaction patterns)

---

## Verdict: Suitable for Phase 1, No Blocking Changes

The schema meets ACID expectations and core design principles. Use as-is for Phase 1; optional enhancements can be adopted incrementally.

---

## 1. ACID Compliance

| Principle | Status | Notes |
|-----------|--------|-------|
| **Atomicity** | OK | MariaDB transactions apply. Multi-step ops (bind, transfer, complete, etc.) must run inside `DB::transaction()` — see 06-ERROR-HANDLING.md. |
| **Consistency** | OK | FK constraints enforce referential integrity. Business rules (one active program, unique active alias, token-session invariant) are application-enforced per 04-DATA-MODEL. |
| **Isolation** | OK | InnoDB (REPEATABLE READ) is sufficient for Phase 1 single-site. |
| **Durability** | OK | MariaDB default (fsync, WAL). |

---

## 2. Design Principles — Strengths

- **Normalization:** Effectively 3NF. `alias` in `queue_sessions` is derived from token but stored for display/query; acceptable denormalization.
- **Referential integrity:** FKs with appropriate CASCADE/RESTRICT (see 04-DATA-MODEL Relationship Summary).
- **Audit trail:** Append-only `transaction_logs` — no UPDATE/DELETE.
- **Indexing:** Indexes match main query patterns (`idx_queue_sessions_active`, `idx_tokens_hash`, `idx_logs_session`, etc.).
- **ON DELETE semantics:** CASCADE for config (programs → tracks, stations); RESTRICT for core domain (sessions, transaction_logs).

---

## 3. Application-Enforced Constraints

These are not enforced in the DB but in the application layer (per spec):

| Constraint | Reason | Where Enforced |
|------------|--------|----------------|
| One active program | No DB-native “single true” flag | Program activation service |
| Unique active alias | MariaDB lacks partial unique indexes | Bind flow |
| Token-session invariant | `status='in_use'` ↔ `current_session_id IS NOT NULL` | Bind/unbind logic |
| Contiguous `step_order` | Logical constraint | Track-step API |

---

## 4. Optional Enhancements (Solve Along the Way)

Track progress as these are addressed:

- [ ] **DB transactions for multi-step ops** — Ensure bind, transfer, complete, override, cancel, no-show all use `DB::transaction()`. Add to SessionService/FlowEngine.
- [ ] **CHECK constraint for token-session invariant** — MariaDB 10.6 supports CHECK. Add migration:
  ```sql
  CHECK (
    (status = 'in_use' AND current_session_id IS NOT NULL) OR
    (status != 'in_use' AND current_session_id IS NULL)
  )
  ```
- [ ] **`action_type` / status lookup tables** — Replace ENUMs with lookup tables for easier evolution (Phase 2+).
- [ ] **Hash chain on transaction_logs** — Add `previous_log_hash` for tamper detection (Phase 2 per PHASES-OVERVIEW).

---

## 5. No Required Schema Changes for Phase 1

- FKs are correctly defined.
- Indexes cover expected workloads.
- Audit requirements are met by append-only logs.
- Application-enforced rules are documented and feasible.

---

*Last assessed: 2026-02-14. Update this doc when enhancements are implemented.*
