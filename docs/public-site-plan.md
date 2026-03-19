Let me re-read everything fresh before writing.Good. I have the full picture now — routes, both middleware classes, DeviceLock, Site model, all controllers. Let me write the clean restarted plan.

---

# FlexiQueue — Homepage Stats, Monitor Your Queue & Public Display
# Complete Plan — v3 (Clean Restart)

---

## Part 0 — The Two Worlds (Architectural Boundary)

Everything in this system belongs to one of two completely separate worlds. This boundary never blurs.

| World | How you get in | Scope |
|---|---|---|
| **Admin panel** | Login with credentials | `/admin/*`, `/login`, staff routes |
| **Public side** | Site key entry | Everything else — display boards, triage, client phones, site landing |

The plan below only touches the **public side** and the **homepage**. The admin panel changes (landing page customization in Sites/Show) are the only admin-side additions, and they only configure what the public side displays.

---

## Part 1 — The Two Cookies (What They Do, What Changes)

There are currently two cookie systems. Both stay. One gets modified.

### Cookie A — `subscribed_site_slug` (Site Subscription)

**Current behavior:** Written when any device visits `/site/{slug}/...`. Stores one slug. Used by `RedirectByPublicSubscription` to bounce `/display` visits back to the known site.

**Problem:** Stores only one site. Also, the site is currently reachable by anyone who guesses the slug — no key required.

**New behavior:** Replace the single-slug cookie with a **known sites list** — a JSON array of site slugs the device has been granted access to via a valid site key. A site only enters this list after the key is validated. Never by guessing the URL.

**New cookie name:** `known_sites` (replaces `subscribed_site_slug` and `subscribed_program_slug` — both retired).

**Cookie value shape:** `["tagudin", "candon", "vigan"]` — JSON array of slugs, 1-year expiry.

**How a site gets added:** User enters the site key on the key entry screen → backend validates → site slug appended to the array → cookie rewritten.

**How it's used:**
- 0 known sites → show key entry screen
- 1 known site → go straight to that site's landing
- 2+ known sites → show a site picker (only lists known sites — not all sites in the system)
- "Add another site" option always available on the picker

### Cookie B — `device_lock`

**No change.** This cookie (managed by `DeviceLock`) handles device role lockout (display/triage/station). It is set after admin QR scan and device type selection. It is cleared by admin unlock. Behavior is exactly the same as today.

---

## Part 2 — Site Key System (New)

### 2.1 What is a Site Key

A site key is a short, human-typeable code (not the slug, not the API key — those are different things) that an admin gives to staff or posts at a venue so devices can be registered to a site. Think of it like a Wi-Fi password for discovering the site.

**Stored in:** `site.settings['public_access_key']` — inside the existing `settings` JSON column on the `Site` model. No new DB column needed.

**Format:** Recommend 6–8 alphanumeric uppercase characters (e.g. `TAGUDIN8`, `MSWDO-04`). Admin sets this manually in the Sites/Show page. It is not auto-generated (unlike the API key).

**This is completely separate from `api_key_hash`** which is for edge device (Orange Pi) sync authentication. Do not conflate them.

### 2.2 Backend — Site Key Validation Endpoint

**Route:** `POST /api/public/site-key` (no auth, throttled)

**Request body:** `{ "key": "TAGUDIN8" }`

**Logic:**
1. Search `Site` records for one where `settings->public_access_key` matches the submitted key (case-insensitive).
2. If found and site is active → return `{ "slug": "tagudin", "name": "Tagudin MSWDO" }` with 200.
3. If not found → return 404. Do not reveal whether the key exists or the site exists.

**Throttle:** 10 attempts per minute per IP. After 10 failures, lock out for 1 minute. This prevents brute-force guessing.

**Controller:** New `PublicSiteKeyController` in `app/Http/Controllers/Api/`.

**Note:** The backend does not write the cookie — that is a frontend responsibility after a successful response. The backend only validates.

### 2.3 Frontend — Site Key Entry Screen

**When shown:** Any time a device reaches the homepage "Monitor your queue" flow and has zero known sites in its cookie.

**Also accessible from:** The site picker via an "Enter another site key" button, for devices that want to add a second or third site.

**UI:** Simple single-field form. Input for the site key, submit button. On success: add slug to `known_sites` cookie, navigate to that site's landing page. On failure: show "Invalid key" error.

**This screen is not a separate page/route.** It is a modal or inline panel that appears over the homepage or the site picker. It does not need its own URL.

### 2.4 Admin — Setting the Site Key

In `Admin/Sites/Show.svelte`, add a "Public Access Key" field to the site settings. Admin types a key, saves it. This is saved via the existing `PUT /api/admin/sites/{site}` endpoint as `settings.public_access_key`.

Validation rules to add to `UpdateSiteRequest`: `settings.public_access_key` — nullable, string, max 20 characters, alphanumeric and hyphens only.

Show a warning in the UI if no key is set: "No public access key set — public devices cannot discover this site."

---

## Part 3 — Homepage Changes

### 3.1 Global Stats Block

**Remove:** "In Queue" block (`queueCount`), "Processed Today" block (`processedToday`), `showPeopleServed` / `hasActiveProgram` gate.

**Add:** Two numbers — **total people ever served** (all sites, all time) and **total program hours** (all sites, all time).

**New endpoint:** `GET /api/home-stats` — public, no auth, throttled at 60/min.

**New service:** `HomeStatsService::getGlobalStats()` — returns `served_count` (COUNT of `queue_sessions` where `status = 'completed'` AND `completed_at IS NOT NULL`) and `session_hours` (SUM of `TIMESTAMPDIFF(SECOND, started_at, completed_at) / 3600` for same filter, `started_at IS NOT NULL` also required). No site filter. Global across all records. 30-second cache under fixed key `home_stats_global`.

**Frontend:** Fetch on mount, re-fetch every 30s, clear interval on destroy. Show `—` while pending. Silent failure (retain last values).

**HomeController cleanup:** Remove `queueCount`, `processedToday`, `StationQueueService` dependency. Add `default_site_slug` prop (`Site::where('is_default', true)->value('slug')`, nullable).

### 3.2 "Monitor Your Queue" CTA

**Label change:** "Launch Queue Display" → **"Monitor your queue"** + small **(Beta)** badge.

**Destination resolution** (shared logic, used by both this CTA and the "Ready to check in?" section):

| Condition | Destination |
|---|---|
| Has 1 known site in cookie | `/site/{that_slug}` |
| Has 2+ known sites | Open site picker modal |
| Has 0 known sites | Open site key entry screen |

**Remove from "Ready to check in?" section:** The "Start Triage" CTA. Triage is a staff-initiated flow. It does not belong in a public-facing block.

**Update "Queue Display" card:** Same destination resolution as above.

### 3.3 StatusFooter

`setInterval(fetchToday, 10000)` → `setInterval(fetchToday, 30000)`. One line. Admin routes only, already the case.

---

## Part 4 — Site Picker (New UI Component)

**What it is:** A modal/overlay that appears when a device has 2+ known sites. Not a separate page. Not a route.

**Contents:**
- List of known sites (names resolved from the known_sites slug array — frontend can fetch site names from a lightweight public endpoint, or store the name alongside the slug in the cookie value).
- Each site links to `/site/{slug}`.
- "Enter another site key" button at the bottom → opens site key entry screen.

**Cookie shape revision:** To avoid a round-trip to resolve site names, store slug + name pairs in the cookie:
```json
[{"slug":"tagudin","name":"Tagudin MSWDO"},{"slug":"candon","name":"Candon City MSWDO"}]
```
The name is written when the site key is validated (the validation response returns both slug and name).

---

## Part 5 — Public Display Route (Read-Only Board)

### 5.1 Route

**URL:** `GET /site/{site:slug}/program/{program:slug}/view`

**Named route:** `site.program.public-view`

**Register in:** `routes/web.php` inside the existing `prefix('site')->middleware('set.public.subscription.cookie')` group.

**Middleware:** Standard web only. No device auth. No device lock.

**Access gate:** The `set.public.subscription.cookie` middleware runs on this route, but since the site key system now controls site discovery, also add a check: if the requesting device does not have this site's slug in its `known_sites` cookie, redirect to the homepage (where they will be prompted for a site key). This keeps the URL from being useful to someone who just guesses it.

### 5.2 Controller

Add `publicDisplay(Request $request, Site $site, string $program_slug)` to `DisplayController`.

**Logic:**
1. Check `known_sites` cookie contains `$site->slug`. If not → redirect to `/` (not 404 — the site's existence should not be confirmed or denied to unauthorized devices).
2. Resolve `$program` by slug scoped to `$site`. If not found or not active → 404.
3. Call `$this->displayBoardService->getBoardData($program->id, $site->id)` with explicit params.
4. Return `Inertia::render('Display/Board', [...$data, 'publicView' => true, 'site_slug' => $site->slug, 'program_slug' => $program->slug])`.
5. Do not set any device lock cookie. Do not call `requireDeviceAuthOrRedirect`.

### 5.3 `DisplayBoardService::getBoardData` Param Fix

Confirm the method accepts explicit `$programId` — does not internally resolve from a single active program. Fix if needed (this is one of the 21 coupling locations from the audit).

### 5.4 `Display/Board.svelte` — `publicView` Prop

Add `publicView: boolean` prop, default `false`. Backward compatible — all existing routes unaffected.

**When `publicView = true`:**
- Hide: device settings controls, HID scanner settings, device lock controls, admin override buttons, device mode indicator.
- Show: theme toggle moves to the **header** (not inside a settings panel).
- Keep: real-time WebSocket board updates (public broadcast channels, no auth required — verified in channel map).
- Show subtle "View only" label somewhere unobtrusive.

### 5.5 EnforceDeviceLock Middleware

Add `site.program.public-view` named route to the allowed-without-lock exclusion. Use named route reference, not a string pattern.

---

## Part 6 — Site Landing Page

### 6.1 Two-Action Layout Per Program

**File:** `resources/js/Pages/Site/Landing.svelte`

Each program card gets two buttons:

| Button | Label | Destination | Style |
|---|---|---|---|
| View board | "Monitor your queue" | `/site/{site.slug}/program/{program.slug}/view` | Primary |
| Use as device | "Use this device" | `/site/{site.slug}/program/{program.slug}` | Secondary/outline |

"Use this device" leads to device auth → device choose → lock. It is visually subordinate — it is an admin-assisted action, not a public one.

### 6.2 Landing Page Content

**Data model:** All landing content stored in `site.settings` JSON column. No new DB column.

**Keys added to `site.settings`:**

| Key | Type | Description |
|---|---|---|
| `public_access_key` | string | Site key for device discovery (Part 2) |
| `landing_hero_title` | string | Override for page title. Defaults to site name. |
| `landing_hero_description` | string | Short description shown under the title. |
| `landing_hero_image_path` | string | Storage path to uploaded hero image. |
| `landing_sections` | array | Ordered content sections (type, title, body). |
| `landing_show_stats` | boolean | Show site-scoped served count on landing. |

**`DisplayController@siteLanding` additions:** Pass a `landing` object alongside `site` and `programs`:
```php
'landing' => [
    'hero_title'       => $site->settings['landing_hero_title'] ?? $site->name,
    'hero_description' => $site->settings['landing_hero_description'] ?? null,
    'hero_image_url'   => isset($site->settings['landing_hero_image_path'])
                            ? Storage::url($site->settings['landing_hero_image_path'])
                            : null,
    'sections'         => $site->settings['landing_sections'] ?? [],
    'show_stats'       => $site->settings['landing_show_stats'] ?? false,
]
```

Pass the resolved public URL, never the raw storage path.

### 6.3 Site-Scoped Stats on Landing

If `landing.show_stats` is true, the landing page fetches `GET /api/public/site-stats/{site:slug}` on mount. This is **separate from** `/api/home-stats` which is global. This endpoint returns `served_count` and `session_hours` scoped to the given site's programs only.

Add a `getSiteStats(Site $site): array` method to `HomeStatsService`. Reuse the same query pattern, filtered by `site_id`.

---

## Part 7 — Landing Page Admin UI (Admin/Sites/Show)

### 7.1 New "Landing Page" Section

Insert between "Public site URL" section and "API key" section in `Admin/Sites/Show.svelte`.

**Subsections:**

**Public Access:**
- Text input: "Site key" (`settings.public_access_key`) with warning if empty.

**Hero:**
- Text input: "Page title" (`landing_hero_title`, placeholder = site name).
- Textarea: "Description" (`landing_hero_description`, max 500 chars).
- Image upload control: shows thumbnail if image set, file input to replace, delete button to clear.
- Toggle: "Show served stats on public landing" (`landing_show_stats`).

**Content sections:**
- Ordered list of sections, each with title + truncated body preview.
- Inline edit, remove (with confirm), up/down reorder per section.
- "Add section" button.

**Save:** One save button for all text fields. Image upload/delete are separate controls with their own immediate actions.

### 7.2 Image Upload Endpoint

**Route:** `POST /api/admin/sites/{site}/hero-image`
**Route:** `DELETE /api/admin/sites/{site}/hero-image`

**Controller:** New `SiteHeroImageController`.

**Storage:** `public/site-assets/{site->id}/hero.{ext}`. Overwrite on re-upload.

**Accepted:** `jpeg`, `png`, `webp`. Max 2MB.

**`landing_hero_image_path` is never accepted through `PUT /api/admin/sites/{site}`** — only through the upload endpoint. Prevents path injection.

### 7.3 Settings Save

Extend `UpdateSiteRequest` to accept `settings.*` keys:
- `settings.public_access_key` — nullable, string, max 20, alphanumeric + hyphens
- `settings.landing_hero_title` — nullable, string, max 120
- `settings.landing_hero_description` — nullable, string, max 500
- `settings.landing_sections` — nullable, array, each item validated for `type`, `title`, `body`
- `settings.landing_show_stats` — nullable, boolean
- `settings.landing_hero_image_path` — **blocked** (not in accepted fields)

`SiteSettingsController` is currently a stub returning `{ message: 'OK' }`. **Deprecate it** — consolidate all settings saving into `SiteController@update`. Document this decision with a comment.

---

## Part 8 — Middleware Updates

### 8.1 Retire `SetPublicSubscriptionCookie` and `RedirectByPublicSubscription`

These two middleware manage the old single-slug cookie system. They are replaced by the new `known_sites` cookie managed on the frontend after site key validation. The middleware should be removed from `bootstrap/app.php` and `routes/web.php` registration, and the files archived or deleted.

### 8.2 New: `RequireSiteAccess` Middleware (optional, lightweight)

For routes under `/site/{slug}/...`, optionally add a middleware that checks whether the requesting device has `$site->slug` in its `known_sites` cookie. If not, redirect to `/` rather than serving a 404 (which would confirm the site exists).

This replaces the in-controller check described in Part 5.2 and is cleaner as a middleware. Apply it to the entire `prefix('site')` route group.

---

## Part 9 — Complete Files Matrix

| # | File | Change Type | Reason |
|---|---|---|---|
| 1 | `resources/js/Layouts/StatusFooter.svelte` | Edit | 10s → 30s interval |
| 2 | `app/Services/HomeStatsService.php` | Create | Global + site-scoped stats |
| 3 | `app/Http/Controllers/Api/HomeStatsController.php` | Create | GET /api/home-stats |
| 4 | `app/Http/Controllers/Api/PublicSiteStatsController.php` | Create | GET /api/public/site-stats/{site} |
| 5 | `app/Http/Controllers/Api/PublicSiteKeyController.php` | Create | POST /api/public/site-key |
| 6 | `routes/api.php` | Edit | Register home-stats, site-stats, site-key routes |
| 7 | `app/Http/Controllers/HomeController.php` | Edit | Remove old props, add default_site_slug |
| 8 | `resources/js/Pages/Home.svelte` | Edit | New stat block, CTA changes, site picker/key entry logic |
| 9 | `app/Services/DisplayBoardService.php` | Edit | Confirm/fix explicit $programId param |
| 10 | `app/Http/Controllers/DisplayController.php` | Edit | Add publicDisplay(); update siteLanding() to pass landing props |
| 11 | `routes/web.php` | Edit | Add /view route; add RequireSiteAccess middleware to site group; retire old subscription middleware |
| 12 | `resources/js/Pages/Display/Board.svelte` | Edit | publicView prop; theme toggle to header; hide device controls |
| 13 | `app/Http/Middleware/EnforceDeviceLock.php` | Edit | Exclude site.program.public-view named route |
| 14 | `app/Http/Middleware/RequireSiteAccess.php` | Create | Gate /site/* routes by known_sites cookie |
| 15 | `app/Http/Middleware/SetPublicSubscriptionCookie.php` | Delete/archive | Replaced by frontend known_sites logic |
| 16 | `app/Http/Middleware/RedirectByPublicSubscription.php` | Delete/archive | Replaced by frontend known_sites logic |
| 17 | `resources/js/Pages/Site/Landing.svelte` | Edit | Two-action cards, hero block, sections, optional stats |
| 18 | `app/Http/Requests/UpdateSiteRequest.php` | Edit | Add landing_* and public_access_key validation |
| 19 | `app/Http/Controllers/Api/Admin/SiteController.php` | Edit | Ensure settings keys persisted correctly |
| 20 | `app/Http/Controllers/Api/Admin/SiteSettingsController.php` | Deprecate | Consolidate into SiteController@update |
| 21 | `app/Http/Controllers/Api/Admin/SiteHeroImageController.php` | Create | Image upload + delete |
| 22 | `routes/api.php` | Edit | Register hero image routes |
| 23 | `resources/js/Pages/Admin/Sites/Show.svelte` | Edit | Landing page section, site key field, image upload UI |
| 24 | Database migration (if needed) | Create | Index on queue_sessions.status |

---

## Part 10 — Implementation Order

| Step | Task | Depends On |
|---|---|---|
| 1 | StatusFooter 30s | Nothing |
| 2 | HomeStatsService + /api/home-stats | Nothing |
| 3 | Home.svelte stat block + 30s poll | Step 2 |
| 4 | HomeController cleanup + default_site_slug prop | Step 3 confirmed |
| 5 | PublicSiteKeyController + /api/public/site-key route | Nothing |
| 6 | Home.svelte — site key entry screen + known_sites cookie logic | Step 5 |
| 7 | Home.svelte — site picker modal | Step 6 |
| 8 | Home.svelte — CTA destination resolution using known_sites | Steps 6, 7 |
| 9 | RequireSiteAccess middleware | Step 6 (cookie shape must be decided) |
| 10 | Retire SetPublicSubscriptionCookie + RedirectByPublicSubscription | Steps 6, 9 confirmed |
| 11 | DisplayBoardService param fix | Nothing |
| 12 | DisplayController@publicDisplay + /view route | Steps 9, 11 |
| 13 | Board.svelte publicView prop + theme toggle to header | Step 12 |
| 14 | EnforceDeviceLock exclusion | Step 12 |
| 15 | UpdateSiteRequest + SiteController settings persistence | Nothing |
| 16 | SiteHeroImageController + routes | Step 15 |
| 17 | Admin/Sites/Show.svelte — landing page section + site key field | Steps 15, 16 |
| 18 | DisplayController@siteLanding — pass landing props | Step 15 |
| 19 | Site/Landing.svelte — full redesign (hero, sections, two-action cards) | Steps 12, 18 |
| 20 | PublicSiteStatsController + /api/public/site-stats route | Step 2 (reuse service) |
| 21 | Site/Landing.svelte — optional stats row | Step 20 |

---

## Part 11 — Deferred Items

| # | Item | Note |
|---|---|---|
| 1 | Program-level key (URL restriction per-program) | Site key covers site access. Per-program key is future work. Add TODO comment on /view route. |
| 2 | Section types beyond `text` | Only `text` (title + body) in v1. Future: `image`, `links`. |
| 3 | Admin landing page preview | Admin opens the public site URL manually. No in-admin iframe preview now. |
| 4 | Central-edge settings tab in Sites/Show | Existing edge settings section stays. Future: dedicated "Central & Edge" subsection. Placeholder comment only. |
| 5 | Site key rotation/expiry | Keys are static for now. Future: expiry date, one-time-use keys. |