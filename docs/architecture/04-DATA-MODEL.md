# Data model and database posture

This document gives a high–level view of FlexiQueue’s data model and, critically, how we use the database engine today versus where we expect to be in the future.

It is **not** an exhaustive schema reference; when in doubt, read the migrations under `database/migrations/` and the Eloquent models under `app/Models/`.

---

## Database engines: SQLite today, MariaDB tomorrow

- **Production-on-Pi (current)**  
  - For Orange Pi One deployments (see `docs/architecture/10-DEPLOYMENT.md`), we run on **SQLite** (single `database/database.sqlite` file).  
  - Reasons:
    - Very low RAM footprint (no separate MariaDB/MySQL daemon).
    - Simple to back up and move (copy one file).
    - Sufficient for single-site, single-Pi event queues.

- **Server deployments (future / larger sites)**  
  - The long‑term target for full server deployments is **MariaDB 10.6+** (or MySQL‑compatible) as pinned in [`stack-conventions.mdc`](../../.cursor/rules/stack-conventions.mdc).  
  - When we eventually move a site from SQLite to MariaDB:
    - We must be able to run the **same migrations** against MariaDB and end up with an equivalent schema.
    - Application code must not rely on SQLite‑only behaviour (loose typing, disabled foreign keys, etc.).

- **Portability requirement (applies to all new work)**  
  - All schema and queries MUST remain **portable between SQLite and MariaDB** unless a migration or doc explicitly says otherwise.  
  - When adding migrations that branch on the driver (for example, `transaction_logs` uses different SQL for SQLite vs MariaDB), keep **both branches correct and tested**.
  - Prefer Laravel’s schema builder and Eloquent over raw SQL so the framework can handle most cross‑driver differences for us.

In short: **SQLite is part of production today**, not a toy; but we treat MariaDB as the long‑term “full server” target and keep the codebase ready for that migration.

---

## Core entities (overview)

The main tables are:

- **Programs** (`programs`) – top‑level configuration for a queue deployment: name, diagram, settings JSON (including triage/public settings, display options, etc.).  
- **Tokens** (`tokens`) – physical queue tokens (e.g. “A1”, “B12”) and their QR hashes and status (`available`, `in_use`, `deactivated`, …).
- **Service tracks** (`service_tracks`) – logical flows (Regular, PWD, Incomplete docs).  
- **Track steps** (`track_steps`) – ordered steps inside a track; each step links to one or more stations.
- **Stations** (`stations`) – triage or service desks; capacity and assignment of staff.
- **Sessions** (`queue_sessions`) – a client’s journey through the flow: which token, which program, current station, status, timestamps.
- **Transaction logs** (`transaction_logs`) – immutable audit trail for session events (bind, call, transfer, complete, cancel, etc.).
- **Program audit log** (`program_audit_log`) – immutable audit trail for program‑level events (session start/stop).
- **Staff activity log** (`staff_activity_log`) – availability and other staff‑level changes (used by the audit log page).

These tables are designed to be append‑only or mostly append‑heavy, which works well on SQLite and MariaDB. All tables use integer primary keys and explicit foreign keys; when adding new relationships, always follow that pattern.

---

## SQLite–specific considerations

When touching schema or queries, keep in mind:

- **Migrations that ALTER tables**  
  - SQLite has limited `ALTER TABLE` support. For non‑trivial changes we sometimes:
    - Create a `*_new` table,
    - Copy data over with a raw `INSERT … SELECT …`,
    - Drop the old table and rename the new one.  
  - If you add such logic, ensure both SQLite **and** MariaDB branches exist and are tested.

- **Timestamps and NOT NULL**  
  - Several audit tables (`transaction_logs`, `program_audit_log`, `staff_activity_log`) have `created_at` defined as `NOT NULL`.  
  - When using `$timestamps = false` on the model, we must either:
    - Give the column a proper DB default (`useCurrent()` in the migration), or
    - Set `created_at` in the model’s `creating` hook (as we do for `TransactionLog` and `ProgramAuditLog`) so SQLite’s NOT NULL constraint is always satisfied.

- **Foreign keys**  
  - We rely on foreign keys for referential integrity even on SQLite (see `config/database.php`, `DB_FOREIGN_KEYS`). Do not disable them in migrations or tests unless a spec explicitly requires it.

- **Adding new enum values**  
  - When a migration adds new values to an enum column (e.g. `transaction_logs.action_type`), it must update **both** MySQL/MariaDB and SQLite. Use `ALTER TABLE … MODIFY COLUMN … ENUM(…)` only for MySQL/MariaDB (guarded by `DB::getDriverName()`). For SQLite, Laravel’s `enum()` creates a CHECK constraint; either add a SQLite branch that recreates the table with `action_type VARCHAR(255)` (or an extended CHECK), or add a follow-up migration that fixes SQLite (see `2025_02_15_000003_add_transaction_log_call_and_reorder` and `2026_03_02_000001_fix_sqlite_transaction_logs_action_type` for the pattern).

- **Making columns nullable (or other ALTER)**  
  - For nullable or other structural changes that use `ALTER … MODIFY` on MySQL/MariaDB only, add a SQLite branch (table recreate) or a follow-up SQLite-only migration so both engines stay in sync (see `2026_03_02_000002_fix_sqlite_queue_sessions_track_steps_tokens` and `2026_02_28_100000_make_transaction_logs_staff_user_id_nullable` for the pattern).

If a change works on MariaDB locally but fails on the Pi with SQLite (or vice‑versa), treat that as a **portability bug** and fix it in the schema or query, not by special‑casing production.

