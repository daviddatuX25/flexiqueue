# Phase D + F — Final Implementation Plan

---

## Markers Reference

Each task has a unique marker in format `[DF-XX]`. The phased plan at the end references these markers in execution order.

---

## Section 1 — Config & Foundation

---

### [DF-01] Add `mode` key to `config/app.php`

**File:** `config/app.php`

After the existing `'env' => env('APP_ENV', 'production'),` line, add:

```
'mode' => env('APP_MODE', 'central'),
```

This is the single source of truth for whether this instance is a central server or an edge Pi. Default is `central` so existing deployments are unaffected.

---

### [DF-02] Create `EdgeModeService`

**File:** `app/Services/EdgeModeService.php`

New service class. Namespace `App\Services`. No constructor dependencies.

**Methods to implement:**

`isEdge(): bool`
Returns `config('app.mode') === 'edge'`.

`isCentral(): bool`
Returns `!$this->isEdge()`.

`isOnline(): bool`
Returns `false`. This is a stub — Phase E (bridge layer) will replace this with real connectivity detection. Do not add any network checks here now.

`isOffline(): bool`
Returns `$this->isEdge() && !$this->isOnline()`.

`canCreateClients(): bool`
Returns `!$this->isOffline()`. On central: always true. On edge offline: false.

`canRegisterIdentity(): bool`
Returns `!$this->isOffline()`. Identity registration requires central DB access.

`getEffectiveBindingMode(string $programMode): string`
If `$this->isOffline()` and `$programMode === 'required'`, return `'optional'`. Otherwise return `$programMode` unchanged. This prevents triage from being completely blocked on the Pi when a program requires identity binding but no central server is reachable.

`isAdminReadOnly(): bool`
Returns `$this->isEdge()`. On an edge Pi, the admin panel is always read-only regardless of connectivity. Program configuration changes must be made on central, then re-synced.

**Enforcement rule — document in the class docblock:**
No file in `app/Http/` or `app/Services/` (except this file) may call `config('app.mode')` or read `APP_MODE` directly. All edge mode checks must go through this service. This makes Phase E connectivity detection a single-file change.

---

### [DF-03] Register `EdgeModeService` as singleton

**File:** `app/Providers/AppServiceProvider.php`

In the `register()` method, add:

```
$this->app->singleton(\App\Services\EdgeModeService::class);
```

This ensures a single instance is used across all controllers and services in a given request.

---

### [DF-04] Share `edge_mode` in `HandleInertiaRequests`

**File:** `app/Http/Middleware/HandleInertiaRequests.php`

In the `share()` method, add the following key to the `$base` array alongside the existing `device_locked`, `flash`, and `auth` keys:

```
'edge_mode' => [
    'is_edge'         => app(\App\Services\EdgeModeService::class)->isEdge(),
    'is_online'       => app(\App\Services\EdgeModeService::class)->isOnline(),
    'is_offline'      => app(\App\Services\EdgeModeService::class)->isOffline(),
    'admin_read_only' => app(\App\Services\EdgeModeService::class)->isAdminReadOnly(),
],
```

Use inline `app()` resolution — consistent with how `TtsService` and `DeviceLock` are already used in this file. Do not add constructor injection to this middleware.

This makes `$page.props.edge_mode` available in every Inertia page and component without any additional prop passing.

---

## Section 2 — Package Exporter (Central Side)

---

### [DF-05] Create `ProgramPackageExporter` service

**File:** `app/Services/ProgramPackageExporter.php`

Namespace `App\Services`. Constructor injects nothing — use static DB queries inline.

**Single public method:** `export(Program $program, Site $site): array`

The method reads edge settings from the site to determine what to include:

```
$edgeSettings = $site->edge_settings ?? [];
$syncTokens  = (bool) ($edgeSettings['sync_tokens']  ?? false);
$syncClients = (bool) ($edgeSettings['sync_clients'] ?? false);
$syncTts     = (bool) ($edgeSettings['sync_tts']     ?? false);
```

**Build each section as follows:**

**`program` section:**
`$program->toArray()` — the full programs table row for this program. Safe: no sensitive data in the programs table.

**`tracks` section:**
`ServiceTrack::where('program_id', $program->id)->get(['id', 'program_id', 'name', 'description', 'is_default', 'color_code', 'created_at', 'updated_at'])->toArray()`

Store the track IDs in a local variable `$trackIds` for use in the steps query.

**`processes` section:**
`Process::where('program_id', $program->id)->get(['id', 'program_id', 'name', 'description', 'expected_time_seconds', 'created_at', 'updated_at'])->toArray()`

**`stations` section:**
`Station::where('program_id', $program->id)->get()->toArray()`
Include all columns — the `settings` JSON column contains TTS configuration that the Pi needs for audio playback.

Store station IDs in `$stationIds`.

**`steps` section:**
`TrackStep::whereIn('track_id', $trackIds)->get(['id', 'track_id', 'station_id', 'process_id', 'step_order', 'is_required', 'estimated_minutes', 'created_at', 'updated_at'])->toArray()`

**`station_process` section:**
`DB::table('station_process')->whereIn('station_id', $stationIds)->get(['station_id', 'process_id'])->toArray()`

**`users` section:**
```
User::where('site_id', $site->id)->get([
    'id', 'site_id', 'name', 'email', 'password', 'role',
    'override_pin', 'override_qr_token',
    'assigned_station_id', 'is_active', 'availability_status',
    'staff_triage_allow_hid_barcode', 'staff_triage_allow_camera_scanner',
    'created_at', 'updated_at'
])->toArray()
```

The `password` field contains the bcrypt hash — safe to include. The Pi needs it for staff login to work offline. `override_pin` and `override_qr_token` are app-level hashed values — the Pi needs them for supervisor PIN/QR authorization flows to work offline.

**`tokens` section (only if `$syncTokens`):**
```
Token::forSite($site->id)
    ->whereHas('programs', fn($q) => $q->where('programs.id', $program->id))
    ->get([
        'id', 'site_id', 'physical_id', 'pronounce_as', 'qr_code_hash',
        'status', 'tts_audio_path', 'tts_status', 'tts_settings',
        'is_global', 'created_at', 'updated_at'
    ])->toArray()
```

If `$syncTokens` is false, set this section to an empty array `[]`.

**`program_token` section (only if `$syncTokens`):**
`DB::table('program_token')->where('program_id', $program->id)->get(['program_id', 'token_id', 'created_at'])->toArray()`

If `$syncTokens` is false, set to `[]`.

**`clients` section (only if `$syncClients`):**
```
Client::forSite($site->id)->get([
    'id', 'site_id',
    'first_name', 'middle_name', 'last_name', 'birth_date',
    'address_line_1', 'address_line_2', 'city', 'state',
    'postal_code', 'country',
    'identity_hash',
    'created_at', 'updated_at'
])->toArray()
```

**Never include `mobile_encrypted` or `mobile_hash` in the client export under any circumstances.** These fields must not be synced to the Pi. This exclusion is hard-coded and not configurable.

If `$syncClients` is false, set to `[]`.

**`tts_files` section (only if `$syncTts`):**

Collect all TTS audio file paths from:
- `$sections['tokens']` — each token's `tts_audio_path` field, plus all `tts_settings.languages.*.audio_path` values
- `$sections['stations']` — each station's `settings.tts.languages.*.audio_path` values

Filter to non-null, non-empty strings. Deduplicate. Verify each file exists on `Storage::disk('local')` before including — skip missing files silently.

If `$syncTts` is false, set to `[]`.

**`manifest` section — always included:**
```
[
    'program_id'    => $program->id,
    'site_id'       => $site->id,
    'exported_at'   => now()->toIso8601String(),
    'sync_tokens'   => $syncTokens,
    'sync_clients'  => $syncClients,
    'sync_tts'      => $syncTts,
    'checksums'     => [
        'program'         => hash('sha256', json_encode($sections['program'])),
        'tracks'          => hash('sha256', json_encode($sections['tracks'])),
        'steps'           => hash('sha256', json_encode($sections['steps'])),
        'processes'       => hash('sha256', json_encode($sections['processes'])),
        'stations'        => hash('sha256', json_encode($sections['stations'])),
        'station_process' => hash('sha256', json_encode($sections['station_process'])),
        'users'           => hash('sha256', json_encode($sections['users'])),
        'tokens'          => hash('sha256', json_encode($sections['tokens'])),
        'clients'         => hash('sha256', json_encode($sections['clients'])),
    ],
]
```

**Return the assembled array:**
```
return [
    'manifest'        => $manifest,
    'program'         => $sections['program'],
    'tracks'          => $sections['tracks'],
    'steps'           => $sections['steps'],
    'processes'       => $sections['processes'],
    'stations'        => $sections['stations'],
    'station_process' => $sections['station_process'],
    'users'           => $sections['users'],
    'tokens'          => $sections['tokens'],
    'program_token'   => $sections['program_token'],
    'clients'         => $sections['clients'],
    'tts_files'       => $sections['tts_files'],
]
```

---

### [DF-06] Create `ProgramPackageController`

**File:** `app/Http/Controllers/Api/Admin/ProgramPackageController.php`

Three methods:

**`show(Request $request, Program $program): JsonResponse`**

Auth: admin session (called from within the existing `role:admin` route group).

Logic:
1. Resolve `$siteId = $request->user()->site_id`. If null, abort 403.
2. Load `$site = Site::find($siteId)`. If null, abort 403.
3. Verify `$program->site_id === $siteId`. If not, abort 404.
4. Instantiate `ProgramPackageExporter` and call `export($program, $site)`.
5. Log: `AdminActionLog::log($request->user()->id, 'program_package_exported', 'Program', $program->id, ['site_id' => $siteId])`.
6. Return `response()->json($package)`.

**`showForSite(Request $request, Program $program): JsonResponse`**

Auth: site API key (called from the `site.api_key` middleware group). This is a separate method — not shared with `show()` — to keep the two auth paths explicit.

Logic:
1. Resolve `$site = $request->attributes->get('site')`. If null, abort 401.
2. Verify `$program->site_id === $site->id`. If not, abort 404.
3. Call `ProgramPackageExporter::export($program, $site)`.
4. No `AdminActionLog` — the Pi is not a user.
5. Return `response()->json($package)`.

**`streamTtsFile(Request $request, Program $program, string $filename): StreamedResponse`**

Auth: site API key.

Logic:
1. Resolve and verify site as in `showForSite`.
2. Validate filename format. Accept only two patterns:
   - `tts/tokens/{numeric_id}/{any}.mp3`
   - `tts/stations/{numeric_id}/{any}.mp3`
   Reject anything else with 403. Use `preg_match('/^tts\/(tokens|stations)\/(\d+)\/.+\.mp3$/', $filename, $matches)`.
3. Extract entity type (`tokens` or `stations`) and ID from the regex matches.
4. Verify ownership:
   - If `tokens`: check `DB::table('program_token')->where('program_id', $program->id)->where('token_id', $entityId)->exists()`. If false, abort 403.
   - If `stations`: check `Station::where('id', $entityId)->where('program_id', $program->id)->exists()`. If false, abort 403.
5. Check `Storage::disk('local')->exists($filename)`. If false, abort 404.
6. Return `Storage::disk('local')->download($filename)`.

---

### [DF-07] Register package export routes in `routes/web.php`

Two additions:

**Inside the existing `role:admin` middleware group** (the one with programs, tokens, etc.), add:
```
Route::get('/programs/{program}/package', [ProgramPackageController::class, 'show']);
```

**New standalone group** (outside all existing auth groups, near the bottom of the file before or after the existing `site.api_key` test route):
```
Route::middleware('site.api_key')->prefix('api/admin')->group(function () {
    Route::get('/programs/{program}/package', [ProgramPackageController::class, 'showForSite'])
         ->name('api.admin.programs.package.site');
    Route::get('/programs/{program}/tts-files/{filename}', [ProgramPackageController::class, 'streamTtsFile'])
         ->where('filename', '.+');
});
```

The `->where('filename', '.+')` is required because the filename contains forward slashes (e.g. `tts/tokens/1/en.mp3`). Without this, Laravel will stop route matching at the first slash.

Add the `ProgramPackageController` use statement to the top of `web.php`.

---

## Section 3 — Package Importer (Pi Side)

---

### [DF-08] Create `EdgeImportPackage` artisan command

**File:** `app/Console/Commands/EdgeImportPackage.php`

Namespace `App\Console\Commands`. Implements standard `Command` class.

**Signature:** `edge:import-package {--program= : The program ID to import} {--url= : Override the central URL (optional)}`

**Description:** `Import a program package from the central server into this edge Pi.`

**`handle()` method — full logic in order:**

Step 1 — Resolve configuration:
```
$centralUrl = $this->option('url') ?: env('CENTRAL_URL');
$apiKey     = env('CENTRAL_API_KEY');
$programId  = $this->option('program');
```
If any of these are null or empty, call `$this->error('...')` with a descriptive message and return `Command::FAILURE`.

Step 2 — Check and write lock file:
```
$lockPath = storage_path('app/edge_import_running.lock');
```
If the lock file exists, output error "Another import is already running." and return `Command::FAILURE`.
Write the lock file: `file_put_contents($lockPath, now()->toIso8601String())`.
Wrap everything from Step 3 onward in a `try/finally` block that deletes the lock file in `finally`.

Step 3 — Fetch package from central:
```
$response = \Illuminate\Support\Facades\Http::withHeaders([
    'Authorization' => 'Bearer ' . $apiKey,
    'Accept'        => 'application/json',
])->timeout(60)->get("{$centralUrl}/api/admin/programs/{$programId}/package");
```
If `$response->failed()`, output error with status code and return `Command::FAILURE`.
Decode: `$package = $response->json()`.

Step 4 — Validate manifest checksums:
```
$manifest = $package['manifest'];
$checksums = $manifest['checksums'];
foreach ($checksums as $section => $expectedHash) {
    $actual = hash('sha256', json_encode($package[$section] ?? []));
    if ($actual !== $expectedHash) {
        $this->error("Checksum mismatch for section: {$section}. Aborting.");
        return Command::FAILURE;
    }
}
$this->info('Checksums verified.');
```

Step 5 — Import inside DB transaction:

Wrap the following in `DB::transaction(function() use ($package) { ... })`.

Import in this exact order (dependency order):

a) Programs:
`DB::table('programs')->upsert([$package['program']], ['id'], array_keys($package['program']));`

b) Service tracks:
`DB::table('service_tracks')->upsert($package['tracks'], ['id'], ['name', 'description', 'is_default', 'color_code', 'updated_at']);`
Skip if empty.

c) Processes:
`DB::table('processes')->upsert($package['processes'], ['id'], ['name', 'description', 'expected_time_seconds', 'updated_at']);`
Skip if empty.

d) Stations:
`DB::table('stations')->upsert($package['stations'], ['id'], ['name', 'capacity', 'client_capacity', 'holding_capacity', 'settings', 'is_active', 'updated_at']);`
Skip if empty.

e) Track steps:
`DB::table('track_steps')->upsert($package['steps'], ['id'], ['station_id', 'process_id', 'step_order', 'is_required', 'estimated_minutes', 'updated_at']);`
Skip if empty.

f) Station-process pivot:
`DB::table('station_process')->upsert($package['station_process'], ['station_id', 'process_id'], []);`
Skip if empty.

g) Users — **CRITICAL: use raw DB, not Eloquent**:
```
DB::table('users')->upsert(
    $package['users'],
    ['id'],
    ['name', 'email', 'password', 'role', 'override_pin', 'override_qr_token',
     'assigned_station_id', 'is_active', 'availability_status',
     'staff_triage_allow_hid_barcode', 'staff_triage_allow_camera_scanner', 'updated_at']
);
```
**Why raw DB:** The `User` model casts `password` as `'hashed'`. Using `User::upsert()` would pass the already-hashed bcrypt string through the cast again, double-hashing it and making all staff logins fail. `DB::table()` bypasses all Eloquent casts.

h) Tokens (if `$manifest['sync_tokens']` and tokens not empty):
`DB::table('tokens')->upsert($package['tokens'], ['id'], ['physical_id', 'pronounce_as', 'status', 'tts_audio_path', 'tts_status', 'tts_settings', 'updated_at']);`

i) Program-token pivot (if `$manifest['sync_tokens']` and not empty):
`DB::table('program_token')->upsert($package['program_token'], ['program_id', 'token_id'], ['created_at']);`

j) Clients (if `$manifest['sync_clients']` and clients not empty):
`DB::table('clients')->upsert($package['clients'], ['id'], ['first_name', 'middle_name', 'last_name', 'birth_date', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'identity_hash', 'updated_at']);`

After the transaction: `$this->info('Database import complete.');`

Step 6 — Download TTS files (if `$manifest['sync_tts']`):
```
foreach ($package['tts_files'] as $filePath) {
    $encoded = implode('/', array_map('rawurlencode', explode('/', $filePath)));
    $fileResponse = Http::withHeaders([
        'Authorization' => 'Bearer ' . $apiKey,
    ])->timeout(30)->get("{$centralUrl}/api/admin/programs/{$programId}/tts-files/{$encoded}");
    
    if ($fileResponse->ok()) {
        Storage::disk('local')->put($filePath, $fileResponse->body());
        $this->line("Downloaded: {$filePath}");
    } else {
        $this->warn("Failed to download: {$filePath} (skipping — browser TTS fallback will cover this)");
    }
}
```
TTS download failures are non-fatal warnings. The Pi has browser speech synthesis as fallback.

Step 7 — Write status file:
```
Storage::disk('local')->put('edge_package_imported.json', json_encode([
    'program_id'    => $manifest['program_id'],
    'site_id'       => $manifest['site_id'],
    'imported_at'   => now()->toIso8601String(),
    'manifest_hash' => hash('sha256', json_encode($manifest)),
    'sync_tokens'   => $manifest['sync_tokens'],
    'sync_clients'  => $manifest['sync_clients'],
    'sync_tts'      => $manifest['sync_tts'],
    'status'        => 'complete',
]));
```

Step 8 — Output success: `$this->info('Edge import complete.')` and return `Command::SUCCESS`.

The `finally` block (wrapping Steps 3–8) deletes the lock file: `@unlink($lockPath)`.

---

### [DF-09] Create `ImportProgramPackageJob`

**File:** `app/Jobs/ImportProgramPackageJob.php`

Follow the exact same trait pattern as `GenerateStationTtsJob`:
```
use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
```

**Properties:**
```
public int $timeout = 300;

public function __construct(
    public int    $programId,
    public string $centralUrl,
    public string $apiKey
) {}
```

**`handle()` method:**

Write lock file `storage/app/edge_import_running.lock`.

Write running status to `edge_package_imported.json`:
```
{ "status": "running", "started_at": "<now iso>" }
```

Wrap the import logic in try/catch/finally:
- `try`: run identical import logic to the artisan command (Steps 3–7 from [DF-08]) but using `$this->programId`, `$this->centralUrl`, `$this->apiKey` instead of command options.
- `catch(\Throwable $e)`: write `{ "status": "failed", "error": "<message>", "failed_at": "<now>" }` to the status file. Log::error the exception.
- `finally`: delete the lock file.

Do not re-throw the exception — let the job complete without going to the failed jobs queue. The status file communicates failure to the UI.

---

### [DF-10] Create `EdgeImportController`

**File:** `app/Http/Controllers/Api/Admin/EdgeImportController.php`

**`trigger(Request $request): JsonResponse`**

1. Guard: `if (app(EdgeModeService::class)->isCentral()) return response()->json(['message' => 'This endpoint is only available in edge mode.'], 403);`
2. Check lock file: `if (Storage::disk('local')->exists('../edge_import_running.lock') || file_exists(storage_path('app/edge_import_running.lock'))) return response()->json(['status' => 'already_running'], 409);`
3. Resolve inputs:
   ```
   $programId  = $request->input('program_id');
   $centralUrl = env('CENTRAL_URL');
   $apiKey     = env('CENTRAL_API_KEY');
   ```
   If any null, return 422 with descriptive message.
4. Dispatch: `ImportProgramPackageJob::dispatch($programId, $centralUrl, $apiKey);`
5. Return `response()->json(['status' => 'queued'])`.

**`status(): JsonResponse`**

1. Guard: edge mode only (same 403 as trigger).
2. Check lock file — if exists, return `{ "status": "running" }` merged with whatever is in the status file.
3. Check if `edge_package_imported.json` exists:
   - If not: return `{ "status": "never_synced" }`.
   - If yes: decode and return the JSON contents directly.

---

### [DF-11] Register edge import routes in `routes/web.php`

Inside the existing `role:admin` middleware group (same group as programs, tokens, etc.), add:
```
Route::post('/edge/import', [EdgeImportController::class, 'trigger']);
Route::get('/edge/import/status', [EdgeImportController::class, 'status']);
```

Add `EdgeImportController` use statement at the top of `web.php`.

---

## Section 4 — Apply Edge Mode Guards to Existing Code

---

### [DF-12] Guard `ClientController::store()` and `updateMobile()`

**File:** `app/Http/Controllers/Api/ClientController.php`

At the very top of `store()`, before `$siteId = $this->resolveSiteId($request)`:
```
if (app(\App\Services\EdgeModeService::class)->isOffline()) {
    return response()->json([
        'message' => 'Client creation is not available in offline mode. Clients must be synced from the central server.'
    ], 403);
}
```

At the very top of `updateMobile()`, before the site resolution:
```
if (app(\App\Services\EdgeModeService::class)->isOffline()) {
    return response()->json([
        'message' => 'Mobile number updates are not available in offline mode.'
    ], 403);
}
```

`search()` and `searchByPhone()` are NOT guarded — client lookup works on local synced data.

---

### [DF-13] Apply effective binding mode in `TriagePageController`

**File:** `app/Http/Controllers/TriagePageController.php`

In the `$programPayload` array, change:
```
// Before:
'identity_binding_mode' => $programSettings->getIdentityBindingMode(),

// After:
'identity_binding_mode' => app(\App\Services\EdgeModeService::class)
    ->getEffectiveBindingMode($programSettings->getIdentityBindingMode()),
```

No other changes to this file.

---

### [DF-14] Apply effective binding mode in `PublicTriageController`

**File:** `app/Http/Controllers/Api/PublicTriageController.php`

Find every location where `getIdentityBindingMode()` result is passed to the frontend (in the Inertia response or JSON response). Wrap each with `getEffectiveBindingMode()` the same way as [DF-13].

Do NOT add an offline guard that blocks public triage entirely. Token binding (token → session → track) works fully in offline mode using local data. Only identity verification/registration is blocked.

---

### [DF-15] Guard write methods in `IdentityRegistrationController`

**File:** `app/Http/Controllers/Api/IdentityRegistrationController.php`

Add offline guard at the top of three methods: `direct()`, `accept()`, and `confirmBind()`.

```
if (app(\App\Services\EdgeModeService::class)->isOffline()) {
    return response()->json([
        'message' => 'Identity registration requires central server connectivity and is not available offline.'
    ], 403);
}
```

Do NOT add the guard to `index()`, `possibleMatches()`, or `revealPhone()`. These are read operations that work on local synced data.

---

## Section 5 — Edge Mode UI

---

### [DF-16] Create `EdgeModeBanner` component

**File:** `resources/js/Components/EdgeModeBanner.svelte`

**Script section:**

```
import { usePage, router } from '@inertiajs/svelte';
import { onMount } from 'svelte';
import { Wifi, WifiOff, RefreshCw } from 'lucide-svelte';

const page = usePage();
const edgeMode = $derived($page.props?.edge_mode ?? null);

let importStatus = $state(null);
let importing = $state(false);
let pollInterval = $state(null);

function getCsrfToken() {
    return ($page.props?.csrf_token) ?? 
        document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function fetchStatus() {
    const res = await fetch('/api/admin/edge/import/status', { credentials: 'same-origin' });
    if (res.ok) importStatus = await res.json();
}

onMount(() => {
    fetchStatus();
});

async function triggerSync() {
    if (importing) return;
    const programId = $page.props?.currentProgram?.id;
    if (!programId) {
        alert('No active program selected. Go to a program page to sync.');
        return;
    }
    importing = true;
    const res = await fetch('/api/admin/edge/import', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ program_id: programId }),
    });
    if (res.ok || res.status === 409) {
        pollInterval = setInterval(async () => {
            await fetchStatus();
            if (importStatus?.status !== 'running') {
                clearInterval(pollInterval);
                pollInterval = null;
                importing = false;
                router.reload();
            }
        }, 3000);
    } else {
        importing = false;
    }
}
```

**Template section:**

Show only when `edgeMode?.is_edge` is true. Two visual states:

Offline state (amber):
- Background: amber/orange — `bg-amber-50 border-b border-amber-200`
- Left icon: `WifiOff` in amber color
- Text: `Edge mode — offline` in bold, `Admin is read-only` in smaller text
- Right side: last synced time if `importStatus?.imported_at` exists (formatted as a readable date), plus a "Sync Now" button that calls `triggerSync()`
- When `importing` is true: show a spinner inside the "Sync Now" button and disable it

Online state (green, for Phase E):
- Only shown when `edgeMode?.is_online` is true
- Background: `bg-green-50 border-b border-green-200`
- Icon: `Wifi` in green
- Text: `Edge mode — bridge active`

The banner is a full-width, low-height strip (`py-2 px-4 flex items-center justify-between`). It does not take up significant vertical space.

---

### [DF-17] Add `EdgeModeBanner` to `AdminLayout`

**File:** `resources/js/Layouts/AdminLayout.svelte`

Import `EdgeModeBanner` at the top of the script block alongside other imports.

Inside the `<main>` element, before `{@render children()}`:

```svelte
<main class="fq-main-scroll flex-1 min-h-0 overflow-y-scroll py-6 px-4 sm:px-6 lg:px-8 pb-24 max-w-7xl mx-auto w-full">
    {#if $pageStore.props?.edge_mode?.is_edge}
        <EdgeModeBanner />
    {/if}
    {#if children}
        {@render children()}
    {/if}
</main>
```

Do NOT place at the document root level. `OfflineBanner` is already at root and handles network-offline state. `EdgeModeBanner` is a content-area banner specifically for edge mode admin context. They serve different purposes and must not conflict.

---

### [DF-18] Update `Admin/Programs/Show.svelte` for edge mode

**File:** `resources/js/Pages/Admin/Programs/Show.svelte`

Three changes:

**Change 1 — Edge sync card in Overview tab:**

Read edge mode from shared props: `const edgeMode = $derived($page.props?.edge_mode ?? null);`

At the top of the Overview tab panel content, before the existing stats/info sections, add:

```svelte
{#if edgeMode?.is_edge}
    <div class="rounded-container bg-amber-50 border border-amber-200 p-4 flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
            <p class="text-sm font-semibold text-amber-900">Edge Package</p>
            <p class="text-xs text-amber-700 mt-0.5">
                This program was synced from the central server.
                Re-sync to get the latest configuration.
            </p>
        </div>
        <button
            type="button"
            class="btn preset-tonal btn-sm touch-target-h"
            onclick={triggerEdgeSync}
            disabled={edgeSyncing}
        >
            {edgeSyncing ? 'Syncing…' : 'Re-sync from central'}
        </button>
    </div>
{/if}
```

Add state: `let edgeSyncing = $state(false);`

Add function:
```
async function triggerEdgeSync() {
    if (!program?.id || edgeSyncing) return;
    edgeSyncing = true;
    const res = await api('POST', '/api/admin/edge/import', { program_id: program.id });
    if (!res.ok && res.status !== 409) {
        toaster.error({ title: 'Failed to trigger sync.' });
        edgeSyncing = false;
    }
    // Don't reset edgeSyncing — EdgeModeBanner handles progress display
}
```

**Change 2 — Read-only guard on Settings tab:**

In the Settings tab, wrap ALL save/update buttons with:
```svelte
{#if !edgeMode?.admin_read_only}
    <!-- save button -->
{:else}
    <p class="text-sm text-amber-700">Settings must be changed on the central server and re-synced.</p>
{/if}
```

**Change 3 — Read-only guard on create buttons:**

Apply `disabled={!!edgeMode?.admin_read_only}` to:
- "Add track" button
- "Add station" button  
- "Add process" button
- "Assign staff" button

Also add a `title` attribute when disabled: `"Changes must be made on the central server and re-synced to this device."` so users know why the button is disabled.

Do not hide these buttons entirely — keep them visible but disabled. This communicates clearly that the feature exists but is not available in this context, rather than looking like a missing feature.

---

## Section 6 — Tests

---

### [DF-19] Write `EdgeModeServiceTest`

**File:** `tests/Feature/EdgeModeServiceTest.php`

Test cases:

When `APP_MODE` is not set (default `central`):
- `isEdge()` returns `false`
- `isCentral()` returns `true`
- `isOnline()` returns `false`
- `isOffline()` returns `false`
- `isAdminReadOnly()` returns `false`
- `canCreateClients()` returns `true`
- `canRegisterIdentity()` returns `true`

When `APP_MODE=edge`:
- `isEdge()` returns `true`
- `isCentral()` returns `false`
- `isOnline()` returns `false`
- `isOffline()` returns `true`
- `isAdminReadOnly()` returns `true`
- `canCreateClients()` returns `false`
- `canRegisterIdentity()` returns `false`

Binding mode downgrade:
- `getEffectiveBindingMode('required')` returns `'optional'` when `APP_MODE=edge`
- `getEffectiveBindingMode('disabled')` returns `'disabled'` when `APP_MODE=edge`
- `getEffectiveBindingMode('required')` returns `'required'` when `APP_MODE=central`
- `getEffectiveBindingMode('disabled')` returns `'disabled'` when `APP_MODE=central`

Set `APP_MODE` in tests using `config(['app.mode' => 'edge'])` — do not set env directly.

---

### [DF-20] Write `ProgramPackageExportTest`

**File:** `tests/Feature/Api/Admin/ProgramPackageExportTest.php`

Test cases:

- Admin can export package for their site's program — response 200, JSON structure contains all required top-level keys: `manifest`, `program`, `tracks`, `steps`, `processes`, `stations`, `station_process`, `users`, `tokens`, `program_token`, `clients`, `tts_files`
- Manifest contains correct SHA-256 checksums for each section (recompute and verify in test)
- Client records in the response never contain `mobile_encrypted` or `mobile_hash` keys
- Returns 404 when program belongs to a different site than the admin's site
- Site API key authentication works for the package export route (use `Authorization: Bearer` header with the site's API key)
- TTS file stream returns 200 and correct `Content-Type: audio/mpeg` for a valid token TTS file that belongs to the program
- TTS file stream returns 403 when the token does not belong to the program
- TTS file stream returns 403 for a path that contains directory traversal patterns (e.g. `../etc/passwd`)

---

### [DF-21] Write `EdgeImportControllerTest`

**File:** `tests/Feature/Api/Admin/EdgeImportControllerTest.php`

Test cases:

- `POST /api/admin/edge/import` returns 403 when `APP_MODE=central`
- `POST /api/admin/edge/import` returns 403 when unauthenticated
- `GET /api/admin/edge/import/status` returns 403 when `APP_MODE=central`
- `POST /api/admin/edge/import` returns 409 when lock file already exists (create the lock file before the test, clean up after)
- `POST /api/admin/edge/import` with valid `program_id` when `APP_MODE=edge` returns `{ "status": "queued" }` with 200
- `GET /api/admin/edge/import/status` returns `{ "status": "never_synced" }` when no status file exists
- `GET /api/admin/edge/import/status` returns correct data when status file exists (seed a fake `edge_package_imported.json` and verify the response matches)
- `GET /api/admin/edge/import/status` returns `{ "status": "running" }` when lock file exists

Clean up all test artifacts (lock file, status file) in `tearDown()`.

---

### [DF-22] Write `ClientCreationOfflineTest`

**File:** `tests/Feature/ClientCreationOfflineTest.php`

Test cases:

- `POST /api/clients` returns 403 with `message` key when `APP_MODE=edge`
- `POST /api/clients` returns 201 when `APP_MODE=central` (normal creation flow unaffected)
- `PUT /api/clients/{id}/mobile` returns 403 when `APP_MODE=edge`
- `GET /api/clients/search` returns 200 when `APP_MODE=edge` (search is not blocked)

---

### [DF-23] Write `EdgeBindingModeTest`

**File:** `tests/Feature/EdgeBindingModeTest.php`

Test cases:

- Staff triage page (`GET /triage`) passes `identity_binding_mode = 'optional'` in the program payload when the program has `identity_binding_mode = 'required'` in settings and `APP_MODE=edge`
- Staff triage page passes `identity_binding_mode = 'required'` unchanged when `APP_MODE=central`
- Staff triage page passes `identity_binding_mode = 'disabled'` unchanged in both modes
- `POST /api/identity-registrations/direct` returns 403 when `APP_MODE=edge`
- `POST /api/identity-registrations/{id}/accept` returns 403 when `APP_MODE=edge`
- `POST /api/identity-registrations/{id}/confirm-bind` returns 403 when `APP_MODE=edge`
- `GET /api/identity-registrations` returns 200 when `APP_MODE=edge` (read is not blocked)

---

## Section 7 — Files Summary

| # | Marker | File | Action |
|---|---|---|---|
| 1 | DF-01 | `config/app.php` | MODIFY |
| 2 | DF-02 | `app/Services/EdgeModeService.php` | NEW |
| 3 | DF-03 | `app/Providers/AppServiceProvider.php` | MODIFY |
| 4 | DF-04 | `app/Http/Middleware/HandleInertiaRequests.php` | MODIFY |
| 5 | DF-05 | `app/Services/ProgramPackageExporter.php` | NEW |
| 6 | DF-06 | `app/Http/Controllers/Api/Admin/ProgramPackageController.php` | NEW |
| 7 | DF-07 | `routes/web.php` | MODIFY |
| 8 | DF-08 | `app/Console/Commands/EdgeImportPackage.php` | NEW |
| 9 | DF-09 | `app/Jobs/ImportProgramPackageJob.php` | NEW |
| 10 | DF-10 | `app/Http/Controllers/Api/Admin/EdgeImportController.php` | NEW |
| 11 | DF-11 | `routes/web.php` | MODIFY (edge import routes) |
| 12 | DF-12 | `app/Http/Controllers/Api/ClientController.php` | MODIFY |
| 13 | DF-13 | `app/Http/Controllers/TriagePageController.php` | MODIFY |
| 14 | DF-14 | `app/Http/Controllers/Api/PublicTriageController.php` | MODIFY |
| 15 | DF-15 | `app/Http/Controllers/Api/IdentityRegistrationController.php` | MODIFY |
| 16 | DF-16 | `resources/js/Components/EdgeModeBanner.svelte` | NEW |
| 17 | DF-17 | `resources/js/Layouts/AdminLayout.svelte` | MODIFY |
| 18 | DF-18 | `resources/js/Pages/Admin/Programs/Show.svelte` | MODIFY |
| 19 | DF-19 | `tests/Feature/EdgeModeServiceTest.php` | NEW |
| 20 | DF-20 | `tests/Feature/Api/Admin/ProgramPackageExportTest.php` | NEW |
| 21 | DF-21 | `tests/Feature/Api/Admin/EdgeImportControllerTest.php` | NEW |
| 22 | DF-22 | `tests/Feature/ClientCreationOfflineTest.php` | NEW |
| 23 | DF-23 | `tests/Feature/EdgeBindingModeTest.php` | NEW |

---

## Section 8 — Phased Execution Plan

---

### Phase 1 — Foundation (do first, everything depends on this)

**Goal:** `APP_MODE` is readable, `EdgeModeService` exists and is injectable, all Inertia pages receive `edge_mode` in shared props.

Tasks in order:
1. [DF-01] — Add `mode` key to config
2. [DF-02] — Create `EdgeModeService`
3. [DF-03] — Register singleton in AppServiceProvider
4. [DF-04] — Share `edge_mode` in HandleInertiaRequests

**Verify before continuing:** Boot the app. Check that a page load includes `edge_mode` in the Inertia shared props (check browser dev tools → Network → any Inertia request → response JSON). Confirm `is_edge: false` when `APP_MODE` is not set.

---

### Phase 2 — Package Exporter (central side, no Pi needed yet)

**Goal:** Central server can export a complete program package as JSON. Accessible via admin browser and via site API key.

Tasks in order:
5. [DF-05] — Create `ProgramPackageExporter` service
6. [DF-06] — Create `ProgramPackageController`
7. [DF-07] — Register routes in web.php

**Verify before continuing:** As admin, hit `GET /api/admin/programs/{id}/package` in the browser or Postman. Confirm response contains all required sections. Confirm client data does not include `mobile_encrypted` or `mobile_hash`. Test with the site API key using Bearer auth on the same endpoint.

---

### Phase 3 — Package Importer (Pi side)

**Goal:** Pi can download and import a package from central. Artisan command works. HTTP trigger and status API work.

Tasks in order:
8. [DF-08] — Create `EdgeImportPackage` artisan command
9. [DF-09] — Create `ImportProgramPackageJob`
10. [DF-10] — Create `EdgeImportController`
11. [DF-11] — Register edge import routes

**Verify before continuing:** On the Pi (with `APP_MODE=edge` in `.env` and `CENTRAL_URL`, `CENTRAL_API_KEY`, `SITE_ID` set), run `php artisan edge:import-package --program=1`. Confirm all tables are populated. Confirm `storage/app/edge_package_imported.json` is written. Confirm `GET /api/admin/edge/import/status` returns the status file contents.

---

### Phase 4 — Edge Mode Guards (existing code)

**Goal:** Client creation, identity registration, and binding mode behave correctly in offline mode.

Tasks in order (these are independent, can be done in any order within this phase):
12. [DF-12] — Guard `ClientController::store()` and `updateMobile()`
13. [DF-13] — Apply effective binding mode in `TriagePageController`
14. [DF-14] — Apply effective binding mode in `PublicTriageController`
15. [DF-15] — Guard write methods in `IdentityRegistrationController`

**Verify before continuing:** With `APP_MODE=edge`, attempt to create a client via the API — confirm 403. Open the triage page for a program with `identity_binding_mode = required` — confirm it renders with `optional` instead. Attempt to call `POST /api/identity-registrations/direct` — confirm 403.

---

### Phase 5 — Edge Mode UI

**Goal:** Admin panel shows edge mode banner and read-only state visually. Programs/Show has a sync card.

Tasks in order:
16. [DF-16] — Create `EdgeModeBanner` component
17. [DF-17] — Add banner to `AdminLayout`
18. [DF-18] — Update `Programs/Show` for edge mode

**Verify before continuing:** With `APP_MODE=edge`, open the admin dashboard. Confirm amber banner is visible inside the main content area. Confirm it shows "offline" state and a "Sync Now" button. Click "Sync Now" — confirm it calls the import API. Open a program — confirm Settings tab save buttons are disabled/hidden and the edge sync card appears in Overview.

---

### Phase 6 — Tests

**Goal:** Automated test coverage for all new behavior. Run after all implementation is complete.

Tasks in order:
19. [DF-19] — `EdgeModeServiceTest`
20. [DF-20] — `ProgramPackageExportTest`
21. [DF-21] — `EdgeImportControllerTest`
22. [DF-22] — `ClientCreationOfflineTest`
23. [DF-23] — `EdgeBindingModeTest`

**Final verify:** Run `php artisan test`. All existing tests must still pass. All five new test files must pass. Fix any failures before considering the phase complete.

---

### End-to-End Verification Checklist

After all six phases are complete, run this sequence manually with two instances:

1. On central: Create a program with tracks, stations, processes, staff users, and tokens. Assign tokens to the program.
2. On central: Hit `GET /api/admin/programs/{id}/package` — verify complete JSON response with all sections.
3. On Pi (`.env` has `APP_MODE=edge`, `CENTRAL_URL`, `CENTRAL_API_KEY`): Run `php artisan edge:import-package --program=1`. Confirm success output.
4. On Pi: Open admin dashboard. Confirm amber edge mode banner is visible.
5. On Pi: Confirm admin pages are read-only — Settings save buttons disabled, create buttons disabled.
6. On Pi: Log in as a staff user from the imported users. Confirm login works (password not double-hashed).
7. On Pi: Open triage. Bind a token to a track. Confirm session is created.
8. On Pi: Open station page. Call next, serve, transfer, complete a session end-to-end.
9. On Pi: Confirm display board updates in real time via local Reverb.
10. On Pi: Attempt to create a new client — confirm 403 error message is shown.
11. On Pi: Click "Sync Now" in the edge mode banner — confirm import is triggered and status updates.