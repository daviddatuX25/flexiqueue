# Complete Issue List + Concrete Actions

This document captures identified code quality and architecture issues, ordered by severity. Each issue includes a concrete action plan that can be executed one at a time.

**Legend**
- 🔴 **Critical** — Violates conventions, creates technical debt, or increases maintenance risk
- 🟡 **Medium** — Duplication, misplaced logic, or suboptimal patterns
- 🟢 **Low** — Cleanup, minor improvements, or edge-case hardening

---

## Summary Table

| # | Severity | File | Issue | Action |
|---|----------|------|-------|--------|
| 1 | 🔴 | SessionController | Auth block ×3 | Extract SupervisorAuthService |
| 2 | 🔴 | SessionController | DB::table() in controller | Move to TriageScanLogService |
| 3 | 🔴 | SessionController | Schema::hasTable() in controller | Remove; trust migrations |
| 4 | 🔴 | SessionController | Token::where() in controller | Move to TokenService::lookupByPhysicalOrHash() |
| 5 | 🟡 | SessionController + PermissionRequestService | formatSession() duplicated | Create SessionResource |
| 6 | 🟡 | SessionController | Domain logic in controller | Move callRequiresOverrideAuth() to SessionService |
| 7 | 🟡 | Program model | 15+ settings getters | Extract ProgramSettings value object |
| 8 | 🟡 | PrintSetting + TokenTtsSetting | Singleton on Eloquent | Move to repositories |
| 9 | 🟡 | User model | DB queries in model method | Move to StaffAssignmentService |
| 10 | 🟡 | TtsAccount model | Cross-row update in model | Move to TtsAccountService |
| 11 | 🟡 | Station model | File I/O in booted() | Move to CleanupStationTtsFiles listener |
| 12 | 🟡 | Token model | File I/O in booted() | Move to CleanupTokenTtsFiles listener |
| 13 | 🟡 | PermissionRequestService | 3-branch approve | Extract private methods per path |
| 14 | 🟡 | AnalyticsService | Schema::hasColumn() at runtime | Remove; column always exists |
| 15 | 🟡 | DisplayBoardService | Connector phrase extraction duplicated | Use existing getConnectorPhraseForLang() |
| 16 | 🟡 | TtsService | Raw HTTP call duplicates ElevenLabsClient | Add generateSpeech() to client |
| 17 | 🟡 | StationSelectionService | leastBusy() is identical to shortestQueue() | Implement or remove |
| 18 | 🟢 | SessionService | Old override() alongside overrideByTrack() | Migrate callers then delete |
| 19 | 🟢 | TransactionLog model | Append-only PHP-only | Add DB trigger migration |
| 20 | 🟢 | TokenService | N+1 inserts in batch create loop | Replace with bulk DB::table()->insert() |

---

## 🔴 Critical Issues

### Issue 1 — Supervisor auth block copy-pasted ×3

**File:** `app/Http/Controllers/Api/SessionController.php` — methods `call()`, `forceComplete()`, `override()`

**Action:**
1. Create `app/Services/SupervisorAuthService.php`
2. Move the full `resolveAuthType()` + match block + rate limiter + authorizer check into one method: `SupervisorAuthService::verify(array $validated, int $staffUserId): array|false`
3. Have it return the verified result or throw a typed exception (e.g. `SupervisorAuthException`)
4. Replace all three duplicated blocks in the controller with a single call to this service
5. Delete `resolveAuthType()` from the controller

---

### Issue 2 — DB::table() raw insert inside a controller

**File:** `app/Http/Controllers/Api/SessionController.php` — `logTriageScan()`

**Action:**
1. Create `app/Services/TriageScanLogService.php` with a single `log(Request $request, ?int $tokenId, string $result, ?string $physicalId, ?string $qrHash): void` method
2. Move the entire `DB::table('triage_scan_log')->insert(...)` block there
3. Inject `TriageScanLogService` into `SessionController` and call it instead

---

### Issue 3 — Schema::hasTable() runtime check in a controller

**File:** `app/Http/Controllers/Api/SessionController.php` — `logTriageScan()`

**Action:**
1. Remove the `Schema::hasTable()` guard entirely
2. Ensure the `triage_scan_log` migration always runs before this feature is active (it already exists in migrations)
3. If you truly need a safety guard, put a try/catch around the insert in `TriageScanLogService` and log the exception — don't do schema checks at runtime

---

### Issue 4 — Direct Token::where() Eloquent query in a controller

**File:** `app/Http/Controllers/Api/SessionController.php` — `tokenLookup()`

**Action:**
1. Add a method to `TokenService`: `lookupByPhysicalOrHash(?string $physicalId, ?string $qrHash): ?Token`
2. Move the two `Token::where(...)` calls there
3. In the controller, call `$this->tokenService->lookupByPhysicalOrHash(...)` and handle the null result

---

## 🟡 Medium Issues

### Issue 5 — formatSession() duplicated in two classes

**Files:** `SessionController.php` and `PermissionRequestService.php`

**Action:**
1. Create `app/Http/Resources/SessionResource.php` using Laravel's API Resources (`php artisan make:resource SessionResource`)
2. Define the array shape once there
3. In both the controller and the service, replace `$this->formatSession($session)` with `SessionResource::make($session)->resolve()`
4. Delete both private `formatSession()` methods

---

### Issue 6 — Domain logic callRequiresOverrideAuth() in a controller

**File:** `app/Http/Controllers/Api/SessionController.php`

**Action:**
1. Move `callRequiresOverrideAuth(Session $session): bool` into `SessionService`
2. The controller calls it as `$this->sessionService->callRequiresOverrideAuth($session)`
3. This keeps all session policy decisions in the service layer where they belong

---

### Issue 7 — Program model is a fat settings bag (15+ getters)

**File:** `app/Models/Program.php`

**Action:**
1. Create `app/Support/ProgramSettings.php` as a plain PHP class wrapping the settings array
2. Move all `get*()` methods into it (e.g. `ProgramSettings::fromArray(array $settings)->getPriorityFirst()`)
3. In `Program`, add a single accessor: `public function settings(): ProgramSettings { return ProgramSettings::fromArray($this->attributes['settings'] ?? []); }`
4. Update all callers from `$program->getPriorityFirst()` to `$program->settings()->getPriorityFirst()`

---

### Issue 8 — Singleton factory pattern on Eloquent models

**Files:** `app/Models/PrintSetting.php` (`instance()`), `app/Models/TokenTtsSetting.php` (`instance()`)

**Action:**
1. Create `app/Repositories/PrintSettingRepository.php` with `getInstance(): PrintSetting`
2. Create `app/Repositories/TokenTtsSettingRepository.php` with `getInstance(): TokenTtsSetting`
3. Move the `first() ?? create(...)` logic into each repository
4. Inject the repositories where `PrintSetting::instance()` and `TokenTtsSetting::instance()` are currently called
5. Remove the `instance()` static methods from the models

---

### Issue 9 — User model running active DB queries in a method

**File:** `app/Models/User.php` — `assignedStationForProgram()`

**Action:**
1. Create `app/Services/StaffAssignmentService.php` with `getStationForUser(User $user, int $programId): ?Station`
2. Move the `ProgramStationAssignment::query()` and `Station::find()` logic there
3. Anywhere `$user->assignedStationForProgram($programId)` is called, inject and use `StaffAssignmentService` instead
4. Remove the method from `User`

---

### Issue 10 — TtsAccount::activate() updates sibling rows from inside the model

**File:** `app/Models/TtsAccount.php`

**Action:**
1. Create `app/Services/TtsAccountService.php` with `setActive(TtsAccount $account): void`
2. Move `static::where('id', '!=', $this->id)->update(...)` and `$this->update(...)` there
3. Update callers (likely `ElevenLabsIntegrationController`) to use `TtsAccountService::setActive()` instead
4. Remove `activate()` from the model

---

### Issues 11 & 12 — Models deleting files in booted() observers

**Files:** `app/Models/Station.php`, `app/Models/Token.php`

**Action:**
1. Create `app/Listeners/CleanupStationTtsFiles.php` and `app/Listeners/CleanupTokenTtsFiles.php`
2. Register them to the `StationDeleted` and `TokenDeleted` Eloquent events in `AppServiceProvider` or via `$dispatchesEvents` on the model
3. Move the `Storage::deleteDirectory()` and `Storage::delete()` calls into those listeners
4. Remove the `booted()` observers from both models

---

### Issue 13 — PermissionRequestService::approve() has 3-branch if/elseif routing

**File:** `app/Services/PermissionRequestService.php` — `approve()`

**Action:**
1. Extract the three branches into private methods: `approveViaCustomPath()`, `approveViaTrack()`, `approveViaLegacyStation()`
2. Keep the main `approve()` as a dispatcher that just decides which private method to call
3. Long-term: consider making each a dedicated command/handler class if this grows further

---

### Issue 14 — AnalyticsService has a Schema::hasColumn() runtime check

**File:** `app/Services/AnalyticsService.php` — `getTokenTtsHealth()`

**Action:**
1. Remove `Schema::hasColumn(...)` — `tts_status` has been in migrations since 2026_03_05; the column is always there
2. Replace the guarded block with a direct query, same as `by_status`
3. If you ever need cross-version safety, use a dedicated feature flag in config, not schema introspection

---

### Issue 15 — DisplayBoardService connector phrase extraction duplicated

**File:** `app/Services/DisplayBoardService.php`

**Action:**
1. The 8-line nested `isset()` block for extracting the connector phrase appears verbatim in both `getBoardData()` and `getStationBoardData()`
2. Extract it into private function `resolveConnectorPhrase(array $programSettings, string $lang): ?string`
3. Call it from both methods — already partially done with `getConnectorPhraseForLang()` but `getBoardData()` isn't using it; it's doing the raw extraction inline instead
4. Replace the inline block in `getBoardData()` with `$this->getConnectorPhraseForLang($program, $activeLanguage)`

---

### Issue 16 — TtsService::generateElevenLabs() duplicates HTTP call that ElevenLabsClient wraps

**Files:** `app/Services/TtsService.php`, `app/Services/ElevenLabsClient.php`

**Action:**
1. `TtsService` makes a raw `Http::withHeaders(...)` POST to ElevenLabs directly — but you already have `ElevenLabsClient` for this
2. Add a `generateSpeech(string $text, string $voiceId, string $modelId): ?string` method to `ElevenLabsClient`
3. In `TtsService::generateElevenLabs()`, construct an `ElevenLabsClient` and call `generateSpeech()` instead of doing the raw HTTP call
4. One place to maintain ElevenLabs HTTP logic, not two

---

### Issue 17 — StationSelectionService::leastBusy() is an alias that does nothing different

**File:** `app/Services/StationSelectionService.php`

**Action:**
1. `leastBusy()` now implements a weighted load-based strategy distinct from `shortestQueue()`:
   - `shortest_queue` selects the station with the fewest total `waiting`+`called`+`serving` sessions.
   - `least_busy` computes a load score per station where `serving` sessions are weighted higher than `called`/`waiting` (per `StationSelectionService::leastBusy()`).
2. The selection mode is configured via `Program::getStationSelectionMode()` and admin program settings as `station_selection_mode`.
3. See `StationSelectionService` docblock for the exact weighting and behavior; future changes should keep `least_busy` semantically distinct from `shortest_queue` or update this entry accordingly.

---

## 🟢 Low Issues

### Issue 18 — SessionService::override() (old) still exists alongside overrideByTrack()

**File:** `app/Services/SessionService.php`

**Action:**
1. Search all callers — only `PermissionRequestService::approve()` still calls the old `override()` (for `target_station_id` legacy path)
2. Update `PermissionRequestService` to use `overrideByTrack()` with the station wrapped into a custom path (`[$targetStationId]`) — **done** per `flexiqueue-sn8t` with `PermissionRequestServiceOverrideTest` coverage.
3. Mark `override()` as `@deprecated` and add a `// TODO: remove after PermissionRequestService migration` comment — **done**; no remaining production callers.
4. Delete `override()` once step 2 is confirmed working — **pending** follow-up bead after staging/production verification.

---

### Issue 19 — TransactionLog append-only enforcement is PHP-only

**File:** `app/Models/TransactionLog.php`

**Action:**
1. Database-level hardening is now implemented:
   - SQLite: `2026_03_10_000100_add_transaction_logs_triggers_sqlite.php` adds `BEFORE UPDATE` and `BEFORE DELETE` triggers that abort with an error.
   - MySQL/MariaDB: `2026_03_10_000110_add_transaction_logs_triggers_mysql.php` adds `BEFORE UPDATE` and `BEFORE DELETE` triggers that `SIGNAL SQLSTATE '45000'`.
2. These triggers complement the model-level guards in `TransactionLog` and ensure the append-only guarantee holds even when raw SQL bypasses Eloquent.
3. Future schema changes to `transaction_logs` must preserve these triggers or replace them with equivalent protections.

---

### Issue 20 — TokenService::batchCreate() saves tokens one-by-one in a loop

**File:** `app/Services/TokenService.php`

**Action:**
1. Replace the for loop with a single `Token::insert([...])` bulk insert
2. Since `qr_code_hash` is guarded, you'll need to use `DB::table('tokens')->insert([...])` or temporarily unguard for the insert
3. Wrap it in a `DB::transaction()` to ensure atomicity
4. This is a meaningful performance fix — creating 100 tokens currently fires 100 separate INSERT queries

---

## Usage

Address issues one at a time in priority order. For each issue:

1. Create or update the relevant tests first (TDD where applicable)
2. Implement the action steps
3. Run the test suite: `./vendor/bin/sail artisan test`
4. Remove deprecated code only after callers are migrated and verified

When you touch an area, also scan for similar patterns so we can beadify new findings:

- **Supervisor auth / PIN & QR**:
  - Search for direct `PinService` usage in controllers: `rg "PinService" app/Http/Controllers`.
  - Search for repeated `RateLimiter` keys for PIN failures: `rg "pin_auth_fail:"`.
  - Search for scattered supervisor/authorization checks instead of services: `rg "isSupervisorForProgram" app/Http/Controllers`.
- **General refactors**:
  - Prefer services and value objects over fat controllers/models; when you see complex conditionals or repeated logic, consider adding a new entry here and a bead with severity:
    - 🔴 Critical — security, data integrity, or cross-cutting logic duplicated.
    - 🟡 Medium — duplication, misplaced logic, or suboptimal patterns.
    - 🟢 Low — cleanup or minor hardening.

Any new instance you find should become:

- A new row in this document (include file, short issue name, and concrete action).
- A Bead (`bd create`) with a matching title and severity-aligned priority.
