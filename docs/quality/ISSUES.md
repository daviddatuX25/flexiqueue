# FlexiQueue Issue Tracker

Add new issues here as you discover them during DISCOVERY.md sweeps.
Address open issues in priority order.

## Legend
- 🔴 Critical — security, data integrity, or cross-cutting logic duplicated
- 🟡 Medium — misplaced logic, duplication, or suboptimal patterns
- 🟢 Low — cleanup or minor hardening

## Status
- `open` — not started
- `in-progress` — being worked on now
- `done` — completed and verified

---

## Summary Table

| # | Severity | Layer | File | Issue | Status |
|---|----------|-------|------|-------|--------|
| 1 | 🔴 | Backend | SessionController | Auth block copy-pasted ×3 | done |
| 2 | 🔴 | Backend | SessionController | `DB::table()` raw insert in controller | done |
| 3 | 🔴 | Backend | SessionController | `Schema::hasTable()` runtime check in controller | done |
| 4 | 🔴 | Backend | SessionController | `Token::where()` Eloquent query in controller | done |
| 5 | 🟡 | Backend | SessionController + PermissionRequestService | `formatSession()` duplicated | done |
| 6 | 🟡 | Backend | SessionController | Domain logic `callRequiresOverrideAuth()` in controller | done |
| 7 | 🟡 | Backend | Program model | 15+ settings getters — fat settings bag | done |
| 8 | 🟡 | Backend | PrintSetting + TokenTtsSetting | Singleton `instance()` on Eloquent models | done |
| 9 | 🟡 | Backend | User model | DB queries in model method | done |
| 10 | 🟡 | Backend | TtsAccount model | Cross-row update inside model | done |
| 11 | 🟡 | Backend | Station model | File I/O in `booted()` | done |
| 12 | 🟡 | Backend | Token model | File I/O in `booted()` | done |
| 13 | 🟡 | Backend | PermissionRequestService | 3-branch `approve()` without private method extraction | done |
| 14 | 🟡 | Backend | AnalyticsService | `Schema::hasColumn()` runtime check | done |
| 15 | 🟡 | Backend | DisplayBoardService | Connector phrase extraction duplicated | done |
| 16 | 🟡 | Backend | TtsService | Raw HTTP call duplicates ElevenLabsClient | done |
| 17 | 🟡 | Backend | StationSelectionService | `leastBusy()` was identical to `shortestQueue()` | done |
| 18 | 🟢 | Backend | SessionService | Old `override()` still exists alongside `overrideByTrack()` | open |
| 19 | 🟢 | Backend | TransactionLog model | Append-only enforcement was PHP-only (no DB trigger) | done |
| 20 | 🟢 | Backend | TokenService | N+1 inserts in `batchCreate()` loop | done |

---

## Issue Details

### #18 — `SessionService::override()` still exists alongside `overrideByTrack()`

**File:** `app/Services/SessionService.php`
**Layer:** Backend
**Severity:** 🟢 Low
**Status:** open

**Background:** `overrideByTrack()` is the current implementation. The old `override(int $targetStationId, ...)` method was kept pending staging/production verification that no callers remain.

**Action:**
1. Search for remaining callers: `grep -rn "->override(" app/`
2. If no callers found, delete `override()` from `SessionService`.
3. Run the test suite: `php artisan test`
4. Commit: `git commit -m "refactor: remove deprecated SessionService::override()"`

---

## How to add a new issue

1. Run the DISCOVERY.md checklist for the file you're working in.
2. For each violation found, add a row to the summary table (next available `#`, assign severity and layer, set status `open`).
3. Add a detail section at the bottom of this file following the format above.
