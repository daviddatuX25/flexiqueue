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
| 21 | 🔴 | Backend | UserAvailabilityController | Schema::hasTable() + DB::table() raw insert in controller | open |
| 22 | 🔴 | Backend | StationController | Token::where() Eloquent query in controller | done |
| 23 | 🔴 | Backend | ProgramPackageController | DB::table() raw query in controller | open |
| 24 | 🔴 | Backend | PublicTriageController | Multiple Token::where() Eloquent queries in controller | done |
| 25 | 🔴 | Backend | StepController | DB::table() raw join query in controller | open |
| 26 | 🔴 | Backend | ClientController | `Client::where()` Eloquent query in controller | open |
| 27 | 🔴 | Backend | IdentityRegistrationController | `Client::where()` / `Client::findOrFail()` in controller | open |
| 28 | 🟡 | Backend | PublicDisplaySettingsAuthService | `Request` object as method parameter | open |
| 29 | 🟡 | Backend | TriageScanLogService | `Request` object as method parameter | open |
| 30 | 🟡 | Backend | Program model | `static::where()` cross-row query in `booted()` | open |
| 31 | 🟡 | Backend | User model | `Storage::disk()` in model accessor | open |
| 32 | 🟡 | Frontend | Admin/Clients pages | Raw `fetch()` for client CRUD mutations | open |
| 33 | 🟡 | Frontend | Admin/Sites pages | Raw `fetch()` for site mutations and image uploads | open |
| 34 | 🟡 | Frontend | Admin/Settings/TtsGenerationTab | Raw `fetch()` for TTS settings mutations | open |
| 35 | 🟡 | Frontend | Profile/Index | Raw `fetch()` for profile mutations (password, PIN, avatar) | open |
| 36 | 🟡 | Frontend | Admin/Programs/Show, Tokens/Index, Station/Index | Raw `fetch()` for program/token/permission mutations | open |

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

### #21 — `Schema::hasTable()` + `DB::table()` raw insert in `UserAvailabilityController`

**File:** `app/Http/Controllers/Api/UserAvailabilityController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** open

**Background:** Database schema checks and raw inserts belong in services, not controllers. This violates separation of concerns.

**Action:**
1. Extract `Schema::hasTable('staff_activity_log')` guard and `DB::table('staff_activity_log')->insert(...)` into a `StaffActivityLogService`.
2. Replace controller code with: `$this->staffActivityLogService->logActivity(...)`.
3. Run the test suite: `php artisan test`
4. Commit: `git commit -m "refactor: extract staff activity logging to StaffActivityLogService"`

---

### #22 — `Token::where()` Eloquent query in `StationController`

**File:** `app/Http/Controllers/Api/StationController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** done

**Background:** Eloquent queries belong in services or repositories. The `Token::where('qr_code_hash', ...)` lookup should use an existing service.

**Action:**
1. Move `Token::where('qr_code_hash', $qrHash)->first()` to `TokenService::lookupByPhysicalOrHash()` (already exists).
2. Replace controller code with: `$token = $this->tokenService->lookupByPhysicalOrHash($qrHash)`.
3. Run the test suite: `php artisan test`
4. Commit: `git commit -m "refactor: move Token lookup to TokenService in StationController"`

---

### #23 — `DB::table()` raw query in `ProgramPackageController`

**File:** `app/Http/Controllers/Api/Admin/ProgramPackageController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** open

**Background:** Raw database queries belong in services, not controllers. The `DB::table('program_token')` query should be abstracted.

**Action:**
1. Move `DB::table('program_token')->where(...)` query to `ProgramPackageService` or `TokenService`.
2. Replace controller code with a service method call.
3. Run the test suite: `php artisan test`
4. Commit: `git commit -m "refactor: move program_token query to service layer"`

---

### #24 — Multiple `Token::where()` Eloquent queries in `PublicTriageController`

**File:** `app/Http/Controllers/Api/PublicTriageController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** done

**Background:** Multiple `Token::where()` lookups are scattered throughout this controller. All should use `TokenService` for consistency.

**Action:**
1. Identify all `Token::where(...)` calls (found: lines 108, 114, 163, 169, 274, 419).
2. Consolidate lookups using `TokenService::lookupByPhysicalOrHash()`, `lookupById()`, etc.
3. Replace controller code with service method calls.
4. Run the test suite: `php artisan test`
5. Commit: `git commit -m "refactor: move all Token lookups to TokenService in PublicTriageController"`

---

### #25 — `DB::table()` raw join query in `StepController`

**File:** `app/Http/Controllers/Api/Admin/StepController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** open

**Background:** Raw database joins belong in services or repositories. The `DB::table('track_steps')->join(...)` query should be abstracted.

**Action:**
1. Move `DB::table('track_steps')->join(...)` query to `StepService` or `TrackService`.
2. Replace controller code with a service method call.
3. Run the test suite: `php artisan test`
4. Commit: `git commit -m "refactor: move track_steps join query to service layer"`

---

### #26 — `Client::where()` Eloquent query in controller

**File:** `app/Http/Controllers/Api/ClientController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** open

**Action:**
1. Move `Client::where('mobile_hash', $hash)->where('id', '!=', $client->id)->first()` (line 263) to `ClientService` or `ClientRepository`.
2. Inject the service into the controller and call it instead.

---

### #27 — `Client::where()` / `Client::findOrFail()` in controller

**File:** `app/Http/Controllers/Api/IdentityRegistrationController.php`
**Layer:** Backend
**Severity:** 🔴 Critical
**Status:** open

**Action:**
1. Move `Client::where('mobile_hash', ...)` (line 272) and `Client::findOrFail($clientId)` (line 372) to a `ClientService`.
2. Inject and call the service in the controller.

---

### #28 — `Request` object as method parameter in service

**File:** `app/Services/PublicDisplaySettingsAuthService.php`
**Layer:** Backend
**Severity:** 🟡 Medium
**Status:** open

**Background:** `verify()` (line 39) and `throttleKey()` (line 168) accept `Request $request`. Services should not depend on the HTTP layer.

**Action:**
1. Extract the values the service needs from `Request` in the controller before calling the service (e.g. `$ip = $request->ip()`, `$key = $request->input('key')`).
2. Update `verify()` and `throttleKey()` to accept those primitive values instead of the full `Request` object.
3. Update the controller call site accordingly.

---

### #29 — `Request` object as method parameter in service

**File:** `app/Services/TriageScanLogService.php`
**Layer:** Backend
**Severity:** 🟡 Medium
**Status:** open

**Background:** `log(Request $request, ...)` (line 20) accepts the full HTTP request. Services should not depend on the HTTP layer.

**Action:**
1. Extract needed values from `Request` in the controller (e.g. `$ip = $request->ip()`, `$userAgent = $request->userAgent()`).
2. Update `log()` signature to accept those values as primitive parameters.
3. Update the controller call site.

---

### #30 — `static::where()` cross-row query in `Program::booted()`

**File:** `app/Models/Program.php`
**Layer:** Backend
**Severity:** 🟡 Medium
**Status:** open

**Background:** Line 256 runs `static::where('site_id', $siteId)->where('slug', $program->slug)->exists()` inside a `booted()` lifecycle hook to generate a unique slug.

**Action:**
1. Extract the slug uniqueness logic into `ProgramService::generateUniqueSlug(string $baseSlug, int $siteId): string`.
2. Call it from wherever a new Program is created instead of relying on the model's boot cycle.
3. Remove the slug-generation logic from `booted()`.

---

### #31 — `Storage::disk()` inside a model accessor

**File:** `app/Models/User.php`
**Layer:** Backend
**Severity:** 🟡 Medium
**Status:** open

**Background:** Line 322 calls `Storage::disk('public')->url('avatars/'.$this->avatar_path)` inside an accessor. File I/O does not belong in model accessors.

**Action:**
1. Remove the `Storage::` call from the accessor.
2. Either: return just `$this->avatar_path` from the accessor and resolve the URL at the presentation layer, OR create a `UserAvatarService::getUrl(User $user): string` and call it from controllers/resources that need the URL.

---

### #32 — Raw `fetch()` for client CRUD mutations

**Files:** `resources/js/Pages/Admin/Clients/Index.svelte`, `resources/js/Pages/Admin/Clients/Show.svelte`
**Layer:** Frontend
**Severity:** 🟡 Medium
**Status:** open

**Background:** DELETE, POST, and PUT calls to `/api/admin/clients/*` and `/api/clients/*` are made with raw `fetch()`. These should use Inertia's `useForm()` or `router.visit()` so flash messages, validation errors, and page state are handled consistently.

**Action:**
1. For delete actions: replace `fetch(url, { method: 'DELETE' })` with `router.delete(url)`.
2. For create/update form submissions: replace manual `fetch` + JSON body with `useForm()` and `form.post()` / `form.put()`.

---

### #33 — Raw `fetch()` for site mutations and image uploads

**Files:** `resources/js/Pages/Admin/Sites/Index.svelte`, `resources/js/Pages/Admin/Sites/Create.svelte`, `resources/js/Pages/Admin/Sites/Show.svelte`
**Layer:** Frontend
**Severity:** 🟡 Medium
**Status:** open

**Background:** PATCH, POST, PUT, and DELETE calls for site management (set default, create/update site, hero image upload/delete) use raw `fetch()`.

**Action:**
1. Replace `fetch(url, { method: 'PATCH/POST/PUT/DELETE' })` with `router.patch()`, `router.post()`, `useForm().post()`, etc.
2. For file uploads: use `useForm()` which handles multipart forms and progress natively.

---

### #34 — Raw `fetch()` for TTS settings mutations

**File:** `resources/js/Pages/Admin/Settings/TtsGenerationTab.svelte`
**Layer:** Frontend
**Severity:** 🟡 Medium
**Status:** open

**Background:** Multiple PUT, POST, and DELETE calls to ElevenLabs integration and TTS budget endpoints use raw `fetch()` (lines 153, 191, 226, 256, 284, 363, 412, 442).

**Action:**
1. Replace each raw `fetch` mutation with `useForm()` where form data is involved, or `router.post/put/delete()` for action-style calls.

---

### #35 — Raw `fetch()` for profile mutations

**File:** `resources/js/Pages/Profile/Index.svelte`
**Layer:** Frontend
**Severity:** 🟡 Medium
**Status:** open

**Background:** PUT, POST, DELETE calls for password change, PIN override, QR regeneration, avatar upload, and Google OAuth unlinking (lines 79, 98, 156, 199, 238, 278) use raw `fetch()`.

**Action:**
1. Replace each mutation with `useForm()` so validation errors surface consistently.
2. For destructive actions (unlink Google, delete QR): use `router.delete()`.

---

### #36 — Raw `fetch()` for program/token/permission mutations

**Files:** `resources/js/Pages/Admin/Programs/Show.svelte`, `resources/js/Pages/Admin/Tokens/Index.svelte`, `resources/js/Pages/Station/Index.svelte`
**Layer:** Frontend
**Severity:** 🟡 Medium
**Status:** open

**Background:** Program banner image upload, station TTS regeneration, token management operations, and permission request creation/cancellation all use raw `fetch()` instead of Inertia helpers.

**Action:**
1. Replace file upload fetches with `useForm()` (handles multipart natively).
2. Replace action-style fetches (regenerate TTS, cancel permission request) with `router.post()` / `router.delete()`.

---

## How to add a new issue

1. Run the DISCOVERY.md checklist for the file you're working in.
2. For each violation found, add a row to the summary table (next available `#`, assign severity and layer, set status `open`).
3. Add a detail section at the bottom of this file following the format above.
