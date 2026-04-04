# Display & Device Links — Implementation Plan
**Feature:** Display and device links from staff pages  
**Scope:** 4 changes across 3 backend files + 2 frontend files

---

## Overview of What Gets Built

| # | What | Where | Complexity |
|---|------|-------|------------|
| 1 | Redirect `/display` → site display | `DisplayController.php` | Trivial — 3 lines |
| 2 | Pass `site_slug`, `program_slug`, `allow_public_triage` | `TriagePageController.php` | Low — extend existing payload |
| 3 | Pass `site_slug`, `program_slug` | `StationPageController.php` | Low — extend existing payload |
| 4 | "Open public triage" link | `Triage/Index.svelte` | Low — add to existing heading row |
| 5 | "Display board" + "Station display" links | `Station/Index.svelte` | Low — add to existing toolbar row |
| 6 | Test: `/display` redirect | `DisplayBoardTest.php` | Trivial |

---

## Change 1 — DisplayController: Redirect `/display` when site exists

**File:** `app/Http/Controllers/DisplayController.php`

**Current code (confirmed):**
```php
public function showScanQrMessage(): Response
{
    return Inertia::render('Display/ScanQrMessage');
}
```

**New code:**
```php
public function showScanQrMessage(): \Illuminate\Http\RedirectResponse|Response
{
    $site = SiteResolver::defaultIfExists();

    if ($site !== null) {
        return redirect()->route('display.site', ['site' => $site->slug]);
    }

    return Inertia::render('Display/ScanQrMessage');
}
```

**What to verify:** `SiteResolver` is already imported in this file (confirmed — it's in the use statements). `display.site` is the route name for `/site/{site:slug}/display` (confirmed at line 23002 of code map). No other changes needed in this file.

---

## Change 2 — TriagePageController: Add site slug, program slug, allow_public_triage to payload

**File:** `app/Http/Controllers/TriagePageController.php`

**Context:** The controller already resolves `$siteId` via `$user->site_id ?? SiteResolver::default()->id` (confirmed). The `$program` object is already loaded. `getAllowPublicTriage()` exists on `ProgramSettings` (confirmed at line 25774 of code map).

**What's needed:** Load the `Site` model from `$siteId`, grab the `slug`; grab `$program->slug`; call `$programSettings->getAllowPublicTriage()`.

**Add after the `$programSettings = $program->settings();` line:**
```php
// Resolve site model for slug (already have $siteId from above)
$site = \App\Models\Site::find($siteId);
```

**Extend the `return Inertia::render(...)` call — add three keys to the existing array:**
```php
return Inertia::render('Triage/Index', [
    'currentProgram'                 => $programPayload,
    'program'                        => $programPayload,
    'activeProgram'                  => $programPayload,
    'canSwitchProgram'               => $canSwitchProgram,
    'programs'                       => $programsForSelector,
    'queueCount'                     => $footerStats['queue_count'],
    'processedToday'                 => $footerStats['processed_today'],
    'display_scan_timeout_seconds'   => $displayScanTimeoutSeconds,
    'staff_triage_allow_hid_barcode' => $user->staff_triage_allow_hid_barcode ?? true,
    'staff_triage_allow_camera_scanner' => $user->staff_triage_allow_camera_scanner ?? true,
    'pending_identity_registrations' => $pendingRegistrations,
    // NEW ↓
    'site_slug'                      => $site?->slug,
    'program_slug'                   => $program->slug,
    'allow_public_triage'            => $programSettings->getAllowPublicTriage(),
]);
```

**Note:** `$program->slug` — the `Program` model has a `slug` column (confirmed by its use throughout routing, e.g. `DevicesUrl` uses `$program->slug`). No eager load needed — it's a scalar column.

---

## Change 3 — StationPageController: Add site slug and program slug to payload

**File:** `app/Http/Controllers/StationPageController.php`

**Context:** The controller already has `$siteId` (confirmed). The `$program` variable is set before the render call. The current payload does NOT include `site_slug` or `program_slug` (confirmed — only `id`, `name`, `is_active`, `is_paused` are in `$currentProgramPayload`).

**Add after the `$siteId = ...` line (near the top of `__invoke`):**
```php
$site = \App\Models\Site::find($siteId);
```

**Extend the `return Inertia::render('Station/Index', [...])` call:**
```php
return Inertia::render('Station/Index', [
    'station'          => $resolvedStation ? ['id' => $resolvedStation->id, 'name' => $resolvedStation->name] : null,
    'currentProgram'   => $currentProgramPayload,
    'activeProgram'    => $currentProgramPayload,
    'stations'         => $stationsList,
    'tracks'           => $tracksList,
    'canSwitchStation' => $canSwitchStation,
    'canSwitchProgram' => $canSwitchProgram,
    'programs'         => $programsForSelector,
    'queueCount'       => $footerStats['queue_count'],
    'processedToday'   => $footerStats['processed_today'],
    'display_scan_timeout_seconds' => $displayScanTimeoutSeconds,
    // NEW ↓
    'site_slug'        => $site?->slug,
    'program_slug'     => $program?->slug,
]);
```

**Note:** `$program` can be `null` (when `resolveProgramForStaffWithoutStation` returns null), so use `$program?->slug`. `$site` comes from `Site::find($siteId)` which can return null if the ID is somehow invalid, so use `$site?->slug`. Both are nullable strings — the frontend handles that gracefully.

---

## Change 4 — Triage/Index.svelte: "Open public triage" link

**File:** `resources/js/Pages/Triage/Index.svelte`

### 4a. Extend props

Current props destructuring ends at `pending_identity_registrations`. Add three new optional props:

```ts
let {
    currentProgram = null,
    program = null,
    activeProgram = null,
    canSwitchProgram = false,
    programs = [],
    queueCount = 0,
    processedToday = 0,
    display_scan_timeout_seconds = 20,
    staff_triage_allow_hid_barcode = true,
    staff_triage_allow_camera_scanner = true,
    pending_identity_registrations = [],
    // NEW ↓
    site_slug = null,
    program_slug = null,
    allow_public_triage = false,
}: {
    // ... existing types ...
    site_slug?: string | null;
    program_slug?: string | null;
    allow_public_triage?: boolean;
} = $props();
```

### 4b. Add derived URL

Add this below the `effectiveProgram` derived:

```ts
const publicTriageUrl = $derived(
    allow_public_triage && site_slug && program_slug
        ? `/site/${site_slug}/public-triage/${program_slug}`
        : null
);
```

### 4c. Add the link in the heading row

**Current heading row (confirmed at line ~63849):**
```html
<div class="flex items-center justify-between gap-2">
    <h1 class="text-xl md:text-2xl font-semibold text-surface-950">Triage</h1>
    <button
        type="button"
        class="btn btn-icon preset-tonal touch-target"
        aria-label="Triage settings (HID and scanner)"
        title="Settings"
        onclick={openTriageSettingsModal}
    >
        <Settings class="w-5 h-5" />
    </button>
</div>
```

**New heading row — add the link between the h1 and the Settings button:**
```html
<div class="flex items-center justify-between gap-2">
    <h1 class="text-xl md:text-2xl font-semibold text-surface-950">Triage</h1>
    <div class="flex items-center gap-2">
        {#if publicTriageUrl}
            <a
                href={publicTriageUrl}
                target="_blank"
                rel="noopener noreferrer"
                class="btn btn-sm preset-tonal gap-1.5 touch-target-h"
                title="Open client self-service triage in new tab"
            >
                <ArrowUpRight class="w-4 h-4" />
                <span class="hidden sm:inline text-sm">Public triage</span>
            </a>
        {/if}
        <button
            type="button"
            class="btn btn-icon preset-tonal touch-target"
            aria-label="Triage settings (HID and scanner)"
            title="Settings"
            onclick={openTriageSettingsModal}
        >
            <Settings class="w-5 h-5" />
        </button>
    </div>
</div>
```

**Import:** `ArrowUpRight` is already imported in `Station/Index.svelte` but NOT in `Triage/Index.svelte`. Add it to the existing lucide import line:

```ts
// Current:
import { Camera, Plus, Search, Settings } from 'lucide-svelte';

// New:
import { ArrowUpRight, Camera, Plus, Search, Settings } from 'lucide-svelte';
```

---

## Change 5 — Station/Index.svelte: "Display board" and "Station display" links

**File:** `resources/js/Pages/Station/Index.svelte`

### 5a. Extend props

Add two optional props to the existing destructuring:

```ts
let {
    station = null,
    stations = [],
    tracks = [],
    canSwitchStation = false,
    canSwitchProgram = false,
    programs = [],
    currentProgram = null,
    program = null,
    queueCount = 0,
    processedToday = 0,
    display_scan_timeout_seconds = 20,
    // NEW ↓
    site_slug = null,
    program_slug = null,
}: {
    // ... existing types ...
    site_slug?: string | null;
    program_slug?: string | null;
} = $props();
```

### 5b. Add derived URLs

Add below `effectiveCurrentProgram`:

```ts
const displayBoardUrl = $derived(
    site_slug ? `/site/${site_slug}/display` : null
);
const stationDisplayUrl = $derived(
    site_slug && station?.id
        ? `/site/${site_slug}/display/station/${station.id}`
        : null
);
```

### 5c. Add the links to the toolbar row

**Current toolbar row (confirmed at line ~67797):**
```html
<!-- Toolbar: capacity, priority, display audio (single row, wraps on small) -->
<div class="flex flex-wrap items-center justify-between gap-3 py-2 px-3 rounded-container bg-surface-50/80 border border-surface-200 elevation-card">
    <div class="flex items-center gap-3 touch-target-h">
        ...Serving X/Y + Priority first toggle...
    </div>
    <button ...Scan token...>...</button>
    <button ...Display audio...>...</button>
```

**Add two link buttons after the "Display audio" button, still inside the toolbar `<div>`:**
```html
    {#if displayBoardUrl}
        <a
            href={displayBoardUrl}
            target="_blank"
            rel="noopener noreferrer"
            class="btn preset-tonal btn-sm gap-2 touch-target md:min-w-auto px-3"
            title="Open display board in new tab"
            aria-label="Display board"
        >
            <Monitor class="w-5 h-5 text-surface-600 shrink-0" />
            <span class="hidden md:inline text-sm">Display board</span>
        </a>
    {/if}
    {#if stationDisplayUrl}
        <a
            href={stationDisplayUrl}
            target="_blank"
            rel="noopener noreferrer"
            class="btn preset-tonal btn-sm gap-2 touch-target md:min-w-auto px-3"
            title="Open this station's display in new tab"
            aria-label="Station display"
        >
            <ArrowUpRight class="w-5 h-5 text-surface-600 shrink-0" />
            <span class="hidden md:inline text-sm">Station display</span>
        </a>
    {/if}
```

**Import check:** `Monitor` is already imported (confirmed at line 66330). `ArrowUpRight` is also already imported (confirmed at line 66331). No new imports needed.

---

## Change 6 — Test: `/display` redirects to site display

**File:** `tests/Feature/DisplayBoardTest.php`

Add these two test methods to the existing `DisplayBoardTest` class:

```php
/** GET /display with a default site configured returns 302 to /site/{slug}/display */
public function test_display_redirects_to_site_display_when_default_site_exists(): void
{
    $site = $this->defaultSite(); // already created in setUp()

    $response = $this->get('/display');

    $response->assertRedirect('/site/' . $site->slug . '/display');
}

/** GET /display with no site returns ScanQrMessage (200) */
public function test_display_shows_scan_qr_message_when_no_site_exists(): void
{
    // Wipe all sites for this test
    \App\Models\Site::query()->delete();
    \Illuminate\Support\Facades\Cache::forget('default_site');

    $response = $this->get('/display');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page->component('Display/ScanQrMessage'));
}
```

**Note on the second test:** After the test, `setUp` will recreate `defaultSite()` for subsequent tests, so order doesn't matter. The `Cache::forget('default_site')` is needed because `SiteResolver::defaultIfExists()` caches the result for 60s — without clearing it, the cache from a prior test's site creation would make the "no site" scenario return the cached site. Confirm the `SiteResolver::CACHE_KEY` value is `'default_site'` (confirmed at line 25940 of code map).

---

## Execution Checklist for Developer

Work in this order — each step can be tested independently.

**Backend first (no frontend needed to verify backend works):**

- [ ] `DisplayController.php` — add 3-line redirect in `showScanQrMessage()`
- [ ] `TriagePageController.php` — add `Site::find($siteId)`, extend render payload with 3 new keys
- [ ] `StationPageController.php` — add `Site::find($siteId)`, extend render payload with 2 new keys
- [ ] Run `php artisan test --filter DisplayBoardTest` to verify existing tests still pass

**Then frontend:**

- [ ] `Triage/Index.svelte` — add props, derived URL, `ArrowUpRight` import, link in heading row
- [ ] `Station/Index.svelte` — add props, derived URLs, two links in toolbar row

**Then test:**

- [ ] `DisplayBoardTest.php` — add two new test methods
- [ ] Run `php artisan test --filter DisplayBoardTest` — all tests green
- [ ] Manual smoke test: open Triage with `allow_public_triage=true` program → link appears, opens correct URL in new tab
- [ ] Manual smoke test: open Station with station selected → both display links appear, open correct URLs in new tab
- [ ] Manual smoke test: visit `/display` in browser → redirects to `/site/{slug}/display`

---

## Notes / Gotchas

**`$program?->slug` in StationPageController:** The `$program` variable is set from `resolveProgramForStaffWithoutStation()` which can return `null`. Use the null-safe operator. The Inertia payload will send `null` for `program_slug` when there's no program — the frontend handles this gracefully since the derived URL will also be `null`.

**`allow_public_triage` on TriagePageController only:** The plan correctly scopes this to Triage. Station page doesn't need it — Station only links to the display board and station display, not to public triage.

**Both links are `target="_blank"`:** Correct per the plan spec — staff keep their current Triage/Station tab open. The `rel="noopener noreferrer"` is standard security practice for external/new-tab links.

**Display board URL with program query param:** The plan spec mentions optionally appending `?program={program_id}` to the display board URL for deep-linking. This is optional — the display board already has a program selector, so it works without the param. It can be added as a derived enhancement: `${displayBoardUrl}?program=${effectiveCurrentProgram?.id}` — but only add this if the dev wants it; it's not required for the feature to work.

**No auth changes:** Display and public triage routes remain public/device-auth as-is. These links just open them in a new tab — no auth flow changes.