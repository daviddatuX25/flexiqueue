# FlexiQueue — Edge Mode: Complete Implementation Plan

**Status:** Locked for implementation. No open questions remain.  
**Features covered:** Edge Pairing + Edge Mode Foundation + Session Sync  
**Prerequisite:** Neither feature begins until the pairing feature passes its hardware acceptance gate.  
**Deviation policy:** Any deviation from this document requires a written decision note before the code is written, not after.

---

# FEATURE 1 — Edge Pairing

**Scope:** The mechanism by which any edge device establishes its identity with central, receives a long-lived site token, and stores that credential so all subsequent sync communication is authenticated. Covers QR handshake, token issuance, credential persistence, revocation, re-pair lifecycle, golden image contract, and artisan CLI.

---

## F1-Part 1 — Locked Decisions

| # | Decision | Answer |
|---|----------|--------|
| D1 | Reachability model | Edge calls central. Central never calls edge back. `pi_url` in QR is display-only — shown to admin, never used in server logic or automated HTTP. |
| D2 | Trust boundary for QR payload | Central trusts `nonce` for DB storage. `expires_at` from QR is ignored for enforcement — central computes its own expiry at scan time. `pi_url` is untrusted display. `fq_pair` validated for format only. |
| D3 | Single active token per site | One `site_token_hash` per `sites` row. Re-pair atomically replaces the hash. No rotation without revoke+reset+re-pair. |
| D4 | Re-pair semantics | Old token invalidated the moment confirm succeeds and new hash is written. Old device gets `401 site_revoked` on next call. |
| D5 | Time authority | Central clock only. QR `expires_at` ignored for enforcement. Central sets `pairing_requests.expires_at = now() + 15 minutes` at scan time. |
| D6 | Re-bind same site to different device | Same Site row can be re-bound. Revoke → reset → new device pairs. No new Site record needed. |
| D7 | Revoke → reset cooldown | None. Immediate reset allowed. Audit log provides the trail. |
| D8 | `/pair/status` mechanism | Edge polls its own local `/pair/status`. Edge backend checks local cache. Admin's browser calls `/pair/complete` on the edge device directly after central confirms. Edge never polls central. |
| D9 | Shared service class | `PairingService` used by both `PairSetupController` and `flexiqueue:pair` artisan command. No duplicated logic. |
| D10 | Confirm succeeded, edge never writes | Token re-deliverable from `pairing_requests.token_payload_encrypted` for 1 hour. After 1 hour: admin revokes, resets, re-pairs. |
| D11 | Transport | HTTPS required on central in production. LAN HTTP acceptable for edge-local pairing endpoints — no secrets transit those routes. Token travels central → admin browser (HTTPS) → edge device (LAN). |
| D12 | Who can pair/revoke/reset | `admin` role only. `SitePolicy::adminOnly()` on all pairing actions. |
| D13 | Prune cadence | Hourly. `pairing_requests` rows expire in 15 minutes — daily would leave hours of stale rows. |
| D14 | Golden image state | Ships with `APP_MODE=edge`, `CENTRAL_SITE_TOKEN=` empty, no `edge_identity` rows. Always boots to unpaired state. |

---

## F1-Part 2 — Data Model

### Central — `sites`

```php
Schema::create('sites', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->enum('pairing_status', ['awaiting_pair', 'paired', 'revoked'])
          ->default('awaiting_pair');
    $table->string('site_token_hash')->nullable()->unique();
    $table->timestamp('paired_at')->nullable();
    $table->timestamp('last_seen_at')->nullable();
    $table->string('pi_url')->nullable();        // display-only
    $table->json('settings')->nullable();        // edge_settings knobs (future)
    $table->timestamps();
    $table->softDeletes();
    $table->index('pairing_status');
});
```

**Site deletion rule:** A site with `pairing_status = 'paired'` cannot be soft-deleted. The delete action returns `409 site_has_active_pairing`. Admin must revoke first, then delete. This prevents orphaned `edge_locked` programs with no resolvable site owner.

### Central — `pairing_requests`

```php
Schema::create('pairing_requests', function (Blueprint $table) {
    $table->id();
    $table->string('nonce', 64)->unique();
    $table->string('pi_url');
    $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
    $table->enum('status', ['pending', 'confirmed', 'expired'])->default('pending');
    $table->text('token_payload_encrypted')->nullable(); // cleared after payload_expires_at
    $table->timestamp('payload_expires_at')->nullable(); // now + 1hr at confirm time
    $table->timestamp('expires_at');                     // now + 15min at scan time
    $table->timestamps();
    $table->index('expires_at');
    $table->index(['nonce', 'status']);
});
```

### Edge device — `edge_identity`

```php
Schema::create('edge_identity', function (Blueprint $table) {
    $table->id();
    $table->string('central_url');
    $table->unsignedBigInteger('site_id_on_central');
    $table->string('site_slug');
    $table->string('site_name');
    $table->timestamp('paired_at');
    $table->timestamp('last_sync_at')->nullable();
    $table->timestamp('last_sync_succeeded_at')->nullable();
    $table->text('last_sync_error')->nullable();
    $table->string('last_sync_run_id', 36)->nullable();
    $table->timestamp('last_package_imported_at')->nullable();
    $table->timestamps();
});
```

Always exactly one row. Written via `EdgeIdentity::updateOrCreate(['id' => 1], [...data])`. Never any other insert pattern.

`CENTRAL_SITE_TOKEN` lives in `.env` only — never in any database table.

---

## F1-Part 3 — QR Payload

```json
{
  "fq_pair": 1,
  "nonce": "<64-char hex>",
  "pi_url": "http://192.168.1.42",
  "expires_at": "2026-03-19T10:15:00Z"
}
```

`fq_pair` is the schema version. Central rejects anything other than `1` with `422 unsupported_qr_version`. Future versions are supported in a transition window via version-switch in `PairingController@scan`. Nonce logged as first 8 chars only. Raw token never logged anywhere.

---

## F1-Part 4 — API Error Matrix

### `POST /api/admin/pairing/scan`

| Condition | HTTP | `error` |
|-----------|------|---------|
| `fq_pair` ≠ 1 | 422 | `unsupported_qr_version` |
| Malformed / missing fields | 422 | `invalid_qr_payload` |
| Nonce pending (already scanned) | 409 | `nonce_already_scanned` |
| Nonce confirmed | 409 | `nonce_already_used` |
| Nonce expired | 410 | `nonce_expired` |
| Not admin | 403 | `forbidden` |

### `POST /api/admin/pairing/confirm`

| Condition | HTTP | `error` |
|-----------|------|---------|
| Nonce not found | 404 | `nonce_not_found` |
| Nonce expired | 410 | `nonce_expired` |
| Nonce already confirmed (double-tap) | 200 | *(re-deliver payload)* |
| Site not found | 404 | `site_not_found` |
| Site not `awaiting_pair` | 409 | `site_not_available` |
| Race — second confirm on same nonce | 409 | `pairing_already_confirmed` |

Race protection: `lockForUpdate()` on `pairing_requests` row inside a DB transaction. First wins; second sees `confirmed` and returns `409`.

### Pi `GET /pair/status`

| Condition | HTTP | Body |
|-----------|------|------|
| Nonce unknown | 404 | `{status: 'unknown'}` |
| Pending | 200 | `{status: 'pending'}` |
| Confirmed | 200 | `{status: 'confirmed', payload: {...}}` |
| Expired | 200 | `{status: 'expired'}` |

### Pi `POST /pair/complete`

| Condition | HTTP | Body |
|-----------|------|------|
| Success | 200 | `{paired: true}` |
| `.env` write fails | 500 | `{error: 'env_write_failed'}` |
| DB insert fails after `.env` write | 500 | `{error: 'identity_write_failed'}` — `.env` rolled back |
| Already paired (idempotent retry) | 200 | `{paired: true, already_paired: true}` |

### Central `AuthenticateEdgeSite` middleware

| Condition | HTTP | `error` |
|-----------|------|---------|
| No `Authorization` header | 401 | `missing_token` |
| Token hash not found | 401 | `invalid_token` |
| Site `pairing_status = revoked` | 401 | `site_revoked` |
| Site soft-deleted | 401 | `site_not_found` |

---

## F1-Part 5 — Pairing Flow

**Step 1 — Edge enters unpaired state**

Triggers: `APP_MODE=edge` set + `CENTRAL_SITE_TOKEN` absent/empty, or `flexiqueue:pair --reset` run, or revocation detected.

`EnsurePiIsPaired` middleware intercepts all requests except `pair/*` and `api/health`. Web requests redirect to `/pair/setup`. API requests return `503 edge_unpaired`.

**Step 2 — Edge displays QR**

`GET /pair/setup` generates fresh nonce via `random_bytes(32)`, stores in local cache with 15-minute TTL, renders QR using local QR library (no external API calls). Page polls `/pair/status` every 3 seconds. QR auto-regenerates at 14 minutes.

**Step 3 — Admin scans on central**

Central's scanner UI decodes QR JSON, validates `fq_pair === 1`, POSTs to `/api/admin/pairing/scan`. Central creates `pairing_requests` row with its own `expires_at = now() + 15min`. Returns confirmation screen data.

**Step 4 — Admin confirms, selects site**

Dropdown shows only `pairing_status = awaiting_pair` sites. Admin confirms. `PairingController@confirm` runs in a DB transaction: generates 64-byte random token, hashes it (SHA-256), stores hash on `sites` row, stores encrypted raw token in `pairing_requests.token_payload_encrypted` (with `payload_expires_at = now() + 1hr`), marks `sites.pairing_status = paired`. Returns raw token payload once.

**Step 5 — Edge receives token**

Poll detects `confirmed`. Svelte page calls `POST /pair/complete` with payload. Backend:
1. Takes `.env` backup in memory.
2. Writes to `.env.tmp`: `CENTRAL_SITE_TOKEN`, `CENTRAL_URL`, `CENTRAL_SITE_ID`, `CENTRAL_SITE_SLUG`.
3. Renames `.env.tmp` → `.env` (atomic POSIX rename).
4. Runs `php artisan config:clear`.
5. `EdgeIdentity::updateOrCreate(['id' => 1], [...])`.
6. If step 5 fails: restore `.env` from memory backup, delete `.env.tmp`, return `500`.
7. Redirect to `/`.

**Step 6 — Confirmation**

Edge shows success screen with site name and central URL for 3 seconds, then redirects to app. Central Sites list shows `● Paired` with timestamp and pi_url.

---

## F1-Part 6 — Revocation and Re-pair

**Admin revokes:**
`POST /api/admin/sites/{site}/revoke-pairing` → clears `site_token_hash`, sets `pairing_status = revoked`, audit log `site.token_revoked`.

**Edge detects revocation:**
Any edge HTTP call to central returning `401 site_revoked` triggers `HandleSiteRevocation` job (dispatched synchronously — must complete before returning):
1. Atomic `.env` write: clear `CENTRAL_SITE_TOKEN`, `CENTRAL_SITE_ID`, `CENTRAL_SITE_SLUG`. Preserve `CENTRAL_URL` and `APP_MODE`.
2. `php artisan config:clear`.
3. Truncate `edge_identity`.
4. Audit log `site.revocation.detected`.
5. Session data, queue data, transaction logs untouched.

Next web request hits `EnsurePiIsPaired` → redirect to `/pair/setup`.

**Admin resets to awaiting:**
`POST /api/admin/sites/{site}/reset-pairing` — valid only when `pairing_status = revoked`. Sets back to `awaiting_pair`. Audit log `site.pairing.reset`.

---

## F1-Part 7 — Middleware and Routes

### `EnsurePiIsPaired`

```php
public function handle(Request $request, Closure $next): Response
{
    if (config('app.mode') !== 'edge') {
        return $next($request);
    }
    if ($request->is('pair/*', 'health', 'api/health')) {
        return $next($request);
    }
    if (empty(config('services.central.site_token'))) {
        return $request->expectsJson()
            ? response()->json(['error' => 'edge_unpaired'], 503)
            : redirect('/pair/setup');
    }
    return $next($request);
}
```

Registered first in global middleware stack after `TrustProxies` and `HandleCors`.

### Rate limits

```php
RateLimiter::for('pairing-scan',    fn() => Limit::perMinute(10)->by('ip'));
RateLimiter::for('pairing-confirm', fn() => Limit::perMinute(5)->by('ip'));
RateLimiter::for('pair-poll',       fn() => Limit::perMinute(30)->by('ip'));
```

### Routes — edge device (registered only when `APP_MODE=edge`)

| Method | URI | Controller |
|--------|-----|------------|
| GET | `/pair/setup` | `PairSetupController@show` |
| GET | `/pair/status` | `PairSetupController@status` |
| POST | `/pair/complete` | `PairSetupController@complete` |

### Routes — central

| Method | URI | Controller |
|--------|-----|------------|
| GET | `/admin/sites` | `SiteController@index` |
| POST | `/admin/sites` | `SiteController@store` |
| GET | `/admin/sites/pair` | `SiteController@pairScanner` |
| POST | `/api/admin/pairing/scan` | `PairingController@scan` |
| POST | `/api/admin/pairing/confirm` | `PairingController@confirm` |
| POST | `/api/admin/sites/{site}/revoke-pairing` | `SiteController@revoke` |
| POST | `/api/admin/sites/{site}/reset-pairing` | `SiteController@resetPairing` |

---

## F1-Part 8 — Artisan Commands

### `flexiqueue:pair`

Shares `PairingService` with web controller. Generates nonce, renders ASCII QR to stdout, polls local cache every 3 seconds, writes credentials on confirmation.

Flags:
- `--reset` — wipes `CENTRAL_SITE_TOKEN`, truncates `edge_identity`, exits without starting QR flow.
- `--central-url=` — overrides `CENTRAL_URL` for this session.

### `flexiqueue:pairing-requests:prune`

Deletes rows where `expires_at < now()` OR (`status = confirmed` AND `payload_expires_at < now()`). Scheduled hourly.

---

## F1-Part 9 — Config

```php
// config/app.php
'mode' => env('APP_MODE', 'central'), // 'central' | 'edge'

// config/services.php
'central' => [
    'url'        => env('CENTRAL_URL'),
    'site_token' => env('CENTRAL_SITE_TOKEN'),
    'site_id'    => env('CENTRAL_SITE_ID'),
    'site_slug'  => env('CENTRAL_SITE_SLUG'),
],
```

`.env` on edge after pairing:
```dotenv
APP_MODE=edge
CENTRAL_URL=https://flexiqueue.example.com
CENTRAL_SITE_TOKEN=<128-char hex>
CENTRAL_SITE_ID=3
CENTRAL_SITE_SLUG=mswdo-tagudin
GOLDEN_VERSION=20260319
```

---

## F1-Part 10 — Golden Image

**Contains:** Armbian, PHP, Nginx, Supervisor, Laravel app at pinned release tag, all migrations run on clean SQLite DB, `APP_MODE=edge` set, `CENTRAL_SITE_TOKEN=` empty, no `edge_identity` rows, TTS and QR libraries installed and functional offline.

**Wiped before snapshot:** `storage/logs/*`, `storage/framework/cache/*`, `bootstrap/cache/*`, pairing artifacts, `database.sqlite` replaced with fresh migrated empty DB, `.env` sanitized to `APP_MODE=edge` + `CENTRAL_URL=` placeholder only.

**Acceptance gate — all must pass before publishing:**
1. Fresh boot → `/pair/setup` loads without error.
2. QR renders offline (no external calls).
3. Full pair flow completes on a clean clone.
4. Revocation drops device back to `/pair/setup`.
5. Re-pair after revocation succeeds.
6. `flexiqueue:pair` CLI flow completes.

---

## F1-Part 11 — Audit Events

| Event | `action_type` |
|-------|--------------|
| Site created | `site.created` |
| QR scanned | `site.pairing.scan_received` |
| Pairing confirmed | `site.pairing.confirmed` |
| Scan expired | `site.pairing.scan_expired` |
| Token revoked | `site.token_revoked` |
| Reset to awaiting | `site.pairing.reset` |
| Edge completed write | `site.pairing.pi_completed` |
| Edge detected revocation | `site.revocation.detected` |

---

## F1-Part 12 — Implementation Sequence

1. `config/app.php` mode key + `config/services.php` central block.
2. `EnsurePiIsPaired` middleware. Test: `APP_MODE=edge` → any route → redirect to `/pair/setup`.
3. `sites` migration + `Site` model + `SitePolicy`. Basic CRUD only. No pairing logic yet.
4. `pairing_requests` migration + `PairingRequest` model.
5. `edge_identity` migration + `EdgeIdentity` model. `updateOrCreate(['id' => 1], ...)` pattern enforced from day one.
6. `PairingService` — nonce generation, QR payload assembly, local cache read/write. Unit-testable in isolation.
7. Edge `/pair/setup` Svelte page — static QR display via local library. Verify offline. No polling yet.
8. Edge `/pair/status` endpoint — local cache read, returns `{status: 'pending'}`. Wire polling in Svelte.
9. Central `/admin/sites/pair` scanner UI — camera decode, POST to scan endpoint.
10. `PairingController@scan` — validate, create `pairing_requests` row, rate limiter.
11. Central confirmation screen — site dropdown (awaiting_pair only), confirm button.
12. `PairingController@confirm` — `lockForUpdate` transaction, token generation, hash storage, encrypted payload, audit log, rate limiter.
13. Edge poll detects confirmed → `/pair/complete` → atomic `.env` write → `EdgeIdentity::updateOrCreate` → config:clear → redirect.
14. **Hardware verification gate: steps 1–13 must pass end-to-end on real hardware before proceeding.**
15. `AuthenticateEdgeSite` middleware — token hash lookup, `last_seen_at` update, `site_revoked` detection.
16. Revocation: `SiteController@revoke` + `HandleSiteRevocation` job + `reset-pairing` endpoint.
17. Site deletion guard: block delete when `pairing_status = paired`.
18. `flexiqueue:pair` artisan command.
19. `flexiqueue:pairing-requests:prune` + hourly scheduler.
20. Audit log writes at all lifecycle events.
21. Golden image build script — wipe checklist + `GOLDEN_VERSION` injection.
22. Feature tests: happy path, all error matrix cases, race conditions, revocation lifecycle, site deletion guard.

---

## F1-Part 13 — Recovery Runbook

**Power lost mid-pair:** Nonce in memory — gone. Edge boots to `/pair/setup`, generates new nonce. Admin scans again. Old `pairing_requests` row prunes hourly.

**Token suspected leaked:** Admin revokes immediately. Edge self-wipes on next call to central. If offline, edge stays functional until next call. Admin resets and re-pairs.

**Central DB restored from backup:** Admin manually revokes all site tokens after any DB restore. Documented operational requirement.

**SD card cloned:** Both devices authenticate as same site. Admin revokes — both drop to unpaired. Intended device re-pairs.

---

---

# FEATURE 2 — Edge Mode Foundation & Session Sync

**Scope:** Everything between a paired device and a working sync loop. Covers mode behavioral gating, UUID infrastructure, program package download/import, program lock on central, session sync upload (upload direction only), sync conflict capture, sync triggers, and sync state UI. Two-way live sync is out of scope for this feature.

---

## F2-Part 1 — Locked Decisions

| # | Decision | Answer |
|---|----------|--------|
| D1 | Hardware diversity | Edge mode is env-defined. Any hardware running `APP_MODE=edge` is an edge device. Orange Pi, laptop, desktop, mini-PC — all identical behavior. |
| D2 | DB drivers | SQLite or MySQL only. No MariaDB. |
| D3 | Bridge layer | Removed. Sync auto mode handles all data flow. No real-time proxying. |
| D4 | Upload order enforcement | Enforced by `SyncUploadService` on the edge device. Fixed order: `new_clients` → `new_id_document_hashes` → `identity_registrations` → `queue_sessions` → `transaction_logs` → `permission_requests` → `program_audit_log` → `staff_activity_log`. No retry queue needed — ordered upload eliminates foreign UUID resolution failures. |
| D5 | Program lock unlock trigger | Folded into final chunk acceptance. When central accepts the last chunk of a `sync_run_id` (determined by `chunk_index == total_chunks_for_type - 1` on the last entity type in the fixed upload order), central atomically unlocks the program. No separate `complete-run` endpoint. |
| D6 | Program lock on re-download by same site | Allowed. Re-downloading the package by the same site that locked the program refreshes `edge_locked_at` but does not change `edge_locked_by_site_id`. Not treated as a new lock event. |
| D7 | Config edits on locked programs | Allowed on central. Admin sees a warning banner: "This program is running on {site_name}. Changes take effect on next package re-download." Changes do not affect the running edge device until it re-downloads. |
| D8 | Site deletion when programs are locked | Blocked. `SiteController@destroy` returns `409 site_has_locked_programs` if any program has `edge_locked_by_site_id = $site->id`. Admin must force-unlock all programs first, then delete. |
| D9 | Sync chunk idempotency mechanism | Dedicated `sync_chunks_received` table. Cleaner than overloading `sync_id_map`. Pruned after 7 days. |
| D10 | Pending count mechanism | Computed on demand, cached 20 seconds. `last_sync_succeeded_at` as watermark — anything created after that timestamp is pending. No per-row `synced` flag. |
| D11 | "Sync succeeded" definition | The entire `sync_run_id` is marked succeeded only when all chunks are accepted AND the program lock is released. Partial runs do not advance the watermark. |
| D12 | TTS files missing at package generation time | Include only tokens with `tts_status = generated`. Manifest lists which token IDs have packaged TTS. Edge falls back to browser TTS (Web Speech API) for any token not in the manifest. Package generation never aborts due to missing TTS. |
| D13 | `edge_sync_runs` table | Required, not optional. Sync status UI depends on per-run history. Local to edge device — not synced to central. |
| D14 | `sync_chunks_received` | Pruned after 7 days via scheduled command. |
| D15 | `fq_package_version` constant | Defined as `const SUPPORTED_PACKAGE_VERSION = 1` in `PackageImporter` class. Importer hard-aborts on unsupported version before any DB writes. |
| D16 | 21-location single-program refactor | Does not block this feature. Single-program edge assumption used. Bounded adapter `EdgeModeContext::activeProgram()` encapsulates the `Program::where('is_active', true)->first()` call. Future refactor touches one place. Tracked as a separate dependent bead. |
| D17 | `sync_conflicts` table | In scope. Required before client dedup logic is implemented. |
| D18 | WebSocket bridge (central watching edge live) | Explicitly not built. Central shows last-synced DB state for edge-locked programs with a "Live data unavailable" banner. No Reverb subscription from central to edge. |

---

## F2-Part 2 — Behavior Changes in Edge Mode

| Behavior | Central | Edge |
|----------|---------|------|
| TTS generation | Enabled if key present | Always disabled |
| Program CRUD | Full | `403 edge_readonly` on all writes |
| Token creation | Full | `403 edge_readonly` |
| User management | Full | `403 edge_readonly` on writes |
| Client creation | Full | Disabled offline; enabled when central is reachable |
| Identity registration | Full | Disabled offline; enabled when central is reachable |
| Identity binding `required` | Enforced | Auto-downgraded to `optional` when offline |
| Session lifecycle | Full | Full — primary operation |
| Session write on edge-locked program | `423 program_edge_locked` | N/A — edge owns it |
| Reverb | Local or remote | Always `127.0.0.1:6001` |
| Analytics | Full multi-program | Local sessions only |
| Sync UI | Hidden | Shown in sidebar |
| Program import | N/A | Available via artisan + admin UI |

### What does not change

Queue engine, display board, staff auth, override flows, permission requests, `transaction_logs` append-only enforcement.

---

## F2-Part 3 — `EdgeModeContext` Service

```php
// app/Services/EdgeModeContext.php

class EdgeModeContext
{
    public function isEdge(): bool
    {
        return config('app.mode') === 'edge';
    }

    public function isCentral(): bool
    {
        return !$this->isEdge();
    }

    public function isOnline(): bool
    {
        // Only meaningful in edge mode. Always false in central mode.
        if ($this->isCentral()) return false;

        return Cache::remember('edge.central_online', 30, function () {
            try {
                $response = Http::timeout(3)->get(
                    config('services.central.url') . '/api/health'
                );
                return $response->ok();
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function canCreateClients(): bool
    {
        return $this->isEdge() && $this->isOnline();
    }

    public function canRegisterIdentity(): bool
    {
        return $this->isEdge() && $this->isOnline();
    }

    public function effectiveBindingMode(string $configured): string
    {
        if ($configured === 'required' && $this->isEdge() && !$this->isOnline()) {
            return 'optional';
        }
        return $configured;
    }

    public function activeProgram(): ?Program
    {
        // Bounded adapter — encapsulates the 21-location single-program assumption.
        // Future multi-program refactor touches only this method.
        return Program::where('is_active', true)->first();
    }
}
```

Central's `/api/health` endpoint must exist, return `200` with no auth, and return no data. Add to implementation sequence.

### Behavioral gates

| Gate | Location | Behavior |
|------|----------|----------|
| TTS generation | `TtsService::isEnabled()` | Return `false` in edge mode always |
| Program CRUD | `ProgramController` writes | `403 edge_readonly` |
| Token creation | `TokenController` create/store | `403 edge_readonly` |
| User writes | `UserController` writes | `403 edge_readonly` |
| Client creation | Triage / `ClientController` | Gate via `canCreateClients()` |
| Identity registration | `IdentityRegistrationController` | Gate via `canRegisterIdentity()` |
| Binding mode | Triage session init | `effectiveBindingMode()` |
| Analytics | `AnalyticsController` | Scope to local sessions, no cross-site |

---

## F2-Part 4 — UUID Infrastructure

### Tables requiring `uuid CHAR(36)` column

- `queue_sessions`
- `transaction_logs`
- `clients`
- `client_id_documents`
- `identity_registrations`
- `permission_requests`
- `program_audit_log`
- `staff_activity_log`

### 3-phase migration per table

**Phase 1:** Add nullable.
```php
$table->uuid('uuid')->nullable()->after('id');
```

**Before Phase 2:** Add to model `booted()`:
```php
static::creating(function ($model) {
    $model->uuid ??= (string) Str::uuid();
});
```

**Phase 2:** Backfill in chunks of 500.
```php
YourModel::whereNull('uuid')->chunkById(500, function ($rows) {
    foreach ($rows as $row) {
        $row->update(['uuid' => (string) Str::uuid()]);
    }
});
```

**Phase 3:** Enforce.
```php
$table->uuid('uuid')->nullable(false)->unique()->change();
```

SQLite may require table rebuild for Phase 3. Use `DB::statement()` create-temp → copy → drop → rename pattern if `->change()` fails. Test both SQLite and MySQL for each phase.

**Post-migration integrity check — required before proceeding:**
```php
assert(YourModel::whereNull('uuid')->count() === 0);
assert(YourModel::distinct('uuid')->count('uuid') === YourModel::count());
```

### Central `sync_id_map`

```php
Schema::create('sync_id_map', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
    $table->string('entity_type', 64);
    $table->char('edge_uuid', 36);
    $table->unsignedBigInteger('central_id');
    $table->timestamps();
    $table->unique(['site_id', 'entity_type', 'edge_uuid']);
    $table->index(['entity_type', 'edge_uuid']);
    $table->index('site_id');
});
```

### Central `sync_chunks_received`

```php
Schema::create('sync_chunks_received', function (Blueprint $table) {
    $table->id();
    $table->char('sync_run_id', 36);
    $table->unsignedInteger('chunk_index');
    $table->string('entity_type', 64);
    $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
    $table->timestamp('received_at');
    $table->unique(['sync_run_id', 'chunk_index', 'entity_type']);
    $table->index('received_at'); // prune performance
});
```

Pruned after 7 days via scheduled command.

---

## F2-Part 5 — `source` Tagging

```php
// Migration on queue_sessions
$table->string('source', 64)->default('central')->after('program_id');
// Values: 'central' | 'edge:{site_slug}'
```

```php
// SessionService::bind() — after session creation
$session->source = app(EdgeModeContext::class)->isEdge()
    ? 'edge:' . config('services.central.site_slug')
    : 'central';
$session->save();
```

---

## F2-Part 6 — Program Package (Central → Edge)

### Package format

```
flexiqueue-package-{program_slug}-{timestamp}.tar.gz
├── manifest.json
│     fq_package_version: 1
│     app_version: "..."
│     program_uuid: "..."
│     generated_at: "..."
│     size_bytes: N
│     tts_token_ids: [1, 4, 7, ...]   ← only tokens with generated TTS
│     checksums: { "program.json": "sha256...", ... }
├── program.json
├── stations.json          ← includes station_process pivot
├── service_tracks.json    ← includes track_steps
├── processes.json
├── users.json             ← bcrypt-hashed; assigned staff only
├── tokens.json
├── clients.json           ← name + birth_year ONLY
├── id_document_hashes.json  ← id_type + id_number_hash + client_id
├── print_settings.json
├── program_diagram.json
├── temporary_authorizations.json
└── tts/
    ├── tokens/    ← only tokens listed in manifest.tts_token_ids
    └── stations/
```

Never in package: `id_number_encrypted`, raw IDs, `identity_registrations` with encrypted data, `tts_accounts`, `site_token_hash`.

### Package generation endpoint

```
GET /api/edge/programs/{program}/package
Authorization: Bearer {site_token}
```

Authenticated via `AuthenticateEdgeSite`. Site token identifies requesting site for client scoping.

On successful stream completion: set `program.edge_locked = true`, `program.edge_locked_by_site_id = $site->id`, `program.edge_locked_at = now()`.

Re-download by the same site: allowed. Refreshes `edge_locked_at`. Does not change `edge_locked_by_site_id`. No new lock event in audit log — only a `edge.package_downloaded` event.

Package size limit: default 100 MB (`EDGE_PACKAGE_MAX_BYTES`). If exceeded, return `422 package_too_large` before streaming.

Query params (defaults from `sites.settings`):

| Param | Default | Values |
|-------|---------|--------|
| `sync_clients` | `true` | `true` / `false` |
| `client_scope` | `site_scoped` | `site_scoped` / `program_history` / `all` |
| `sync_tokens` | `true` | `true` / `false` |
| `sync_tts` | `true` | `true` / `false` |

### Program lock columns on `programs`

```php
$table->boolean('edge_locked')->default(false);
$table->foreignId('edge_locked_by_site_id')
      ->nullable()->constrained('sites')->nullOnDelete();
$table->timestamp('edge_locked_at')->nullable();
```

**While locked on central:**
- Session writes blocked: `423 program_edge_locked`.
- Config edits (stations, tracks, processes) allowed. Warning banner shown.
- Display board shows last-synced DB state. No Reverb. Banner: `"Live data unavailable — running on {site_name}. Last synced: {last_sync_at}."`
- Central UI program list: `● Running at {site_name}`.

**Unlock conditions:**
1. Final chunk of sync run accepted — unlock atomically in the same DB transaction as final chunk import.
2. Admin force-unlock: `POST /api/admin/programs/{program}/force-unlock` — admin role only. Audit log `program.edge_lock_force_released`.
3. Site token revoked: `SiteController@revoke` clears `edge_locked` on all programs locked by that site.
4. Site deleted (force-blocked unless programs are unlocked first — see D8).

### Package import (edge device)

**Via artisan:** `php artisan flexiqueue:import-package /path/to/package.tar.gz`

**Via admin UI:** Admin → Edge → "Download & Import Package"

**Import procedure — strict order, no deviations:**

1. **Pre-flight:** Query `queue_sessions where status IN ('waiting','called','serving')`. If count > 0: abort, return `"Import blocked: {N} active sessions."` Nothing touched.

2. **Extract** to temp dir. Validate all checksums from manifest. Any failure: delete temp dir, abort with specific filename.

3. **Version check:** `manifest.fq_package_version` must equal `PackageImporter::SUPPORTED_PACKAGE_VERSION` (currently `1`). Hard abort if not. Log app_version mismatch as warning only.

4. **DB transaction:**
   - Upsert program (by `uuid`).
   - Upsert stations (by `uuid`); re-link `station_process` pivot.
   - Upsert service tracks (by `uuid`); upsert track steps.
   - Upsert processes (by `uuid`).
   - Upsert users (by `email`).
   - Upsert tokens (by `qr_code_hash`).
   - Upsert clients (by `uuid`).
   - Upsert id_document_hashes (by `id_number_hash`).
   - Replace print_settings (single row, id=1).
   - Replace program_diagram (by program uuid).
   - Replace temporary_authorizations (by `value_hash`).
   - Set program `is_active = true`.
   - Upsert pattern: `updateOrCreate(['uuid' => $row['uuid']], [...fields])` for all UUID-keyed entities. Pre-UUID-migration fallback: use natural keys (`qr_code_hash`, `email`, `id_number_hash`).

5. **TTS copy** (outside DB transaction): copy `tts/tokens/*` and `tts/stations/*` to `storage/app/tts/`. Overwrite existing.

6. **Finalize:** `EdgeIdentity::updateOrCreate(['id' => 1], ['last_package_imported_at' => now()])`. Delete temp dir. Audit log `edge.package_imported`.

On DB transaction failure: rollback, do not copy TTS, do not delete temp dir (leave for diagnosis), report error.

---

## F2-Part 7 — Sync Conflicts

### `sync_conflicts` table (central only)

```php
Schema::create('sync_conflicts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->constrained('sites');
    $table->string('entity_type', 64);
    $table->char('edge_uuid', 36);
    $table->string('conflict_type', 64);
    // 'client_name_mismatch' — same ID hash, different name/birth_year
    // 'duplicate_session'    — defensive; should not occur
    // 'hash_collision'       — theoretical; two clients share same ID hash
    $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
    $table->json('details');
    // { edge_data: {}, central_data: {}, auto_action: 'linked'|'created'|null }
    $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamps();
    $table->index(['site_id', 'status']);
    $table->index(['entity_type', 'edge_uuid']);
});
```

### Conflict UI

Admin → Sites → {site} → Sync Conflicts. Table: entity type, conflict type, date, edge vs central data, status. Actions: Mark Resolved, Mark Ignored. Filter by status.

---

## F2-Part 8 — Sync Upload (Edge → Central)

### What gets uploaded

| Entity | Notes |
|--------|-------|
| `new_clients` | Created on edge; central dedup-checks |
| `new_id_document_hashes` | Hashes only |
| `identity_registrations` | Central re-encrypts on import |
| `queue_sessions` | Edge authoritative |
| `transaction_logs` | Append-only; edge authoritative |
| `permission_requests` | Edge authoritative |
| `program_audit_log` | Append-only |
| `staff_activity_log` | Append-only |

**Not uploaded (central authoritative):** tokens, programs, stations, tracks, processes, users.

**Upload order (enforced by `SyncUploadService`):**
1. `new_clients`
2. `new_id_document_hashes`
3. `identity_registrations`
4. `queue_sessions`
5. `transaction_logs`
6. `permission_requests`
7. `program_audit_log`
8. `staff_activity_log`

This order ensures all foreign UUID dependencies exist on central before dependent records arrive.

### Upload endpoint

```
POST /api/edge/sync/upload
Authorization: Bearer {site_token}
Content-Type: application/json
```

### Chunk request

```json
{
  "sync_run_id": "<uuid>",
  "chunk_index": 0,
  "entity_type": "queue_sessions",
  "checksum": "<sha256 of records array>",
  "total_chunks_for_type": 3,
  "is_final_chunk_of_run": false,
  "program_uuid": "...",
  "synced_at": "2026-03-19T17:00:00Z",
  "records": [ ... ]
}
```

`is_final_chunk_of_run: true` is set only on the last chunk of `staff_activity_log` (last entity in fixed order, last batch). Central uses this flag to trigger program unlock atomically.

### Batch sizes

| Entity | Batch size |
|--------|-----------|
| `queue_sessions` | 200 |
| `transaction_logs` | 1000 |
| `program_audit_log` | 500 |
| `staff_activity_log` | 500 |
| `permission_requests` | 200 |
| `new_clients` | 200 |
| `new_id_document_hashes` | 500 |
| `identity_registrations` | 200 |

### Chunk response

```json
{
  "accepted": true,
  "sync_run_id": "...",
  "chunk_index": 0,
  "entity_type": "queue_sessions",
  "imported": 198,
  "skipped": 2,
  "conflicts": 1,
  "program_unlocked": false
}
```

`program_unlocked: true` only on the response to the final chunk.

### Central import per entity type

**Idempotency check (all entities):**
```php
$existing = SyncChunksReceived::where('sync_run_id', $data['sync_run_id'])
    ->where('chunk_index', $data['chunk_index'])
    ->where('entity_type', $data['entity_type'])
    ->exists();
if ($existing) {
    return response()->json(['accepted' => true, 'skipped' => count($data['records'])]);
}
```

Then register chunk:
```php
SyncChunksReceived::create([...]);
```

**`queue_sessions`:**
1. Check `sync_id_map` — if UUID mapped, skip.
2. Resolve `token_uuid`, `client_uuid`, `track_uuid` via `sync_id_map` to central IDs. (Upload order guarantees clients are already imported.)
3. Create session. Insert `sync_id_map`.

**`transaction_logs`:**
1. Check `sync_id_map` — skip if mapped.
2. Resolve `session_uuid`, `station_uuid`, `staff_user_uuid`. (Sessions already imported.)
3. Insert. Append-only — no update path. Insert `sync_id_map`.

**`new_clients`:**
1. Check `sync_id_map` by UUID — skip if mapped.
2. Check `id_number_hash` dedup against `client_id_documents`:
   - Hash match, name/birth_year identical → link to existing client, insert `sync_id_map`. No conflict.
   - Hash match, name/birth_year differs → link to existing client (do not overwrite), insert `sync_id_map`, insert `sync_conflicts` row (`client_name_mismatch`).
   - No hash match → create new client, insert `sync_id_map`.
   - No hash provided (created without ID binding) → create new client, insert `sync_id_map`.

**All other entities:** check `sync_id_map` UUID, skip if present, insert if not.

**Final chunk processing:**
When `is_final_chunk_of_run = true` and all records accepted:
```php
DB::transaction(function () use ($site, $programUuid) {
    // Mark run succeeded
    EdgeIdentity on central: update last_sync_at (via sites.last_seen_at already updated by auth middleware)

    // Unlock program
    Program::where('uuid', $programUuid)
            ->where('edge_locked_by_site_id', $site->id)
            ->update([
                'edge_locked' => false,
                'edge_locked_by_site_id' => null,
                'edge_locked_at' => null,
            ]);
});
```

---

## F2-Part 9 — Sync Triggers

All three active simultaneously. All dispatch `SyncUploadJob`. Job is idempotent via `Cache::lock('edge_sync_running', 300)` — duplicate dispatches within 5 minutes are no-ops.

### Trigger 1 — Queue idle

```php
// TriggerPostSessionSync job — dispatched on every session terminal status
public function handle(): void
{
    $active = QueueSession::whereIn('status', ['waiting', 'called', 'serving'])->count();
    if ($active > 0) return;
    SyncUploadJob::dispatch();
}
```

### Trigger 2 — Manual

Admin sidebar "Sync Now" → dispatches `SyncUploadJob` → UI polls `/api/edge/sync/status` every 2 seconds while running → shows result on completion.

### Trigger 3 — Scheduled

`EDGE_SYNC_SCHEDULE` env var. Default: `0 17 * * *`. Set to `false` to disable.

---

## F2-Part 10 — Sync State Tracking

### Edge device — local `edge_sync_runs` table

```php
Schema::create('edge_sync_runs', function (Blueprint $table) {
    $table->id();
    $table->char('run_id', 36)->unique();
    $table->timestamp('started_at');
    $table->timestamp('completed_at')->nullable();
    $table->enum('status', ['running', 'succeeded', 'failed'])->default('running');
    $table->unsignedInteger('records_uploaded')->default(0);
    $table->unsignedInteger('conflicts_count')->default(0);
    $table->text('error')->nullable();
    $table->timestamps();
});
```

Local only — not synced to central.

### Pending counts

```php
// EdgeSyncStateService::getPendingCounts()
public function getPendingCounts(): array
{
    return Cache::remember('edge.pending_counts', 20, function () {
        $lastSync = EdgeIdentity::value('last_sync_succeeded_at');
        return [
            'sessions' => QueueSession::when($lastSync, fn($q) =>
                $q->where('created_at', '>', $lastSync))->count(),
            'logs' => TransactionLog::when($lastSync, fn($q) =>
                $q->where('created_at', '>', $lastSync))->count(),
        ];
    });
}
```

`last_sync_succeeded_at` advances only when the final chunk is accepted and program unlock succeeds (D11). Partial runs do not advance the watermark.

---

## F2-Part 11 — Sync Status UI

```
┌─────────────────────────────┐
│ 🟢/🟠/🔴 Edge Mode          │
│ MSWDO Tagudin               │
│ central.example.com         │
│                             │
│ Last sync: 23 min ago       │
│ Pending: 12 sessions        │
│          47 logs            │
│                             │
│ [ Sync Now ]  [ Details ]   │
└─────────────────────────────┘
```

Color: green < 1hr, orange 1–8hr, red > 8hr or last run failed.

Details page:
- Last 10 sync runs (run ID, started, completed, records, conflicts, status).
- Open conflicts count → link to conflict list.
- Package import history (imported at, program slug, version).
- Central URL, site slug, paired at, golden version.

---

## F2-Part 12 — Conflict Authority Rules

- Identity/auth conflicts: central authoritative, edge defers.
- Client dedup conflicts: captured in `sync_conflicts`, never silently overwritten, admin resolves.
- Concurrent sync races: `sync_id_map` unique constraint — first insert wins, second catches constraint violation, skips that record, continues.
- `CENTRAL_SITE_TOKEN`: never in any log, trace, or response body.
- `sync_run_id`: may be logged in full.
- Nonce: logged as first 8 chars only.
- Idempotency: every chunk retryable via `sync_run_id + chunk_index`. Duplicate chunks are no-ops.
- Audit events required: `edge.package_imported`, `edge.sync_started`, `edge.sync_chunk_received`, `edge.sync_completed`, `edge.sync_failed`, `edge.conflict_detected`, `edge.conflict_resolved`, `program.edge_locked`, `program.edge_lock_force_released`.

---

## F2-Part 13 — Out of Scope

- Two-way live sync (online client lookup/create). Upload only in this feature.
- Package delta sync. Full re-download only.
- Multi-program on edge. Single program only.
- Central → edge config push.
- Analytics UI aggregation on central.
- Remote wipe.
- Resumable package download.
- Queue-for-idle import.

---

## F2-Part 14 — Implementation Sequence

**Foundation:**

1. Central `/api/health` endpoint — public, no auth, returns `200`.
2. `EdgeModeContext` service — all methods implemented, unit-tested.
3. UUID columns — 3-phase migration on all 8 tables. Test SQLite + MySQL. Integrity check per table. Full stop until all pass.
4. `source` column on `queue_sessions`. Wire `SessionService::bind()`. Unit test.
5. `sync_id_map` table on central.
6. `sync_chunks_received` table on central.
7. `sync_conflicts` table on central. `SyncConflict` model.
8. `edge_sync_runs` table on edge device.
9. Edge behavioral gates — all `403 edge_readonly` responses. Integration test: `APP_MODE=edge` → blocked routes → assert `403`.

**Program lock:**

10. `edge_locked`, `edge_locked_by_site_id`, `edge_locked_at` columns on `programs`.
11. Site deletion guard: block when `pairing_status = paired` (F1) and block when programs locked (F2).
12. Central program display: detect `edge_locked`, suppress Reverb, show "not live" banner and site name.
13. Admin `force-unlock` endpoint + audit log.

**Package:**

14. Package generation endpoint on central — config-only (no TTS). Lock written on stream completion.
15. Manifest checksum generation + size budget enforcement (`EDGE_PACKAGE_MAX_BYTES`).
16. `flexiqueue:import-package` artisan command — full procedure: pre-flight, extract, version check, DB transaction, finalize. Config-only first, no TTS.
17. Edge admin UI — "Download & Import Package" button.
18. TTS inclusion in package generation + TTS copy in import.
19. **Golden image validation gate:** Pairing + package import verified on real hardware. Do not proceed to sync upload steps until this passes.

**Sync Upload:**

20. `SyncUploadService` on edge — assembles chunks per entity type in fixed order, manages `sync_run_id`, `is_final_chunk_of_run` flag, watermark logic.
21. `EdgeSyncStateService::getPendingCounts()` with cache.
22. Sync upload endpoint on central — chunk receipt, checksum validation, `sync_chunks_received` idempotency check.
23. `new_clients` import + hash dedup + `sync_conflicts` creation.
24. `queue_sessions` import — UUID resolution via `sync_id_map`, `source` tagging.
25. `transaction_logs` import.
26. All remaining entity imports (`permission_requests`, `program_audit_log`, `staff_activity_log`, `identity_registrations`).
27. Final chunk: program unlock in same DB transaction.
28. Manual sync trigger — "Sync Now" + `SyncUploadJob` + cache lock.
29. Queue-idle auto-sync — `TriggerPostSessionSync` job.
30. Scheduled sync — `EDGE_SYNC_SCHEDULE` + Laravel scheduler.
31. Sync status sidebar widget + `/api/edge/sync/status` polling endpoint.
32. Sync run history + conflict UI on central (Admin → Sites → {site} → Sync Conflicts).
33. `sync_chunks_received` prune command (7-day retention) + scheduler registration.

**Tests:**

34. Package round-trip: generate → download → import → verify local DB state.
35. Program lock: download → assert locked; force-unlock → assert unlocked; site revoke → assert unlocked.
36. Re-download by same site: assert lock refreshed, not duplicated.
37. Import blocked on active sessions.
38. Import version mismatch: hard abort on major, warn on minor.
39. All `403 edge_readonly` gates.
40. Sync upload happy path: sessions on edge → upload → verify on central.
41. Sync idempotency: same chunks uploaded twice → assert no duplicates.
42. Client dedup — identical: auto-link, no conflict.
43. Client dedup — name mismatch: auto-link + `sync_conflicts` row created.
44. Final chunk: program unlocked atomically.
45. `TriggerPostSessionSync`: active sessions → no sync; idle → sync dispatched.
46. Scheduled sync: fires at configured cron time.
47. Sync status widget: pending counts accurate after sync, color thresholds correct.