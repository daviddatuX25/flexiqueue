Let me read the current program model and settings before writing anything.Good. I have everything I need. Now let me write the complete concrete plan.

---

# Public Access & Program Page Plan
## Complete Concrete Plan — v1

---

## Part 0 — What Already Exists (Baseline)

| Component | Relevant State |
|---|---|
| `Program` model | Has `settings` (JSON/array cast), `description` (text column), `slug`, `is_active`, `is_paused`. No `is_published` flag yet. |
| `ProgramSettings` | Full class with typed getters. New settings keys must be added here as getters. |
| `ProgramPageController@show` | Already passes full `settings` payload to `Admin/Programs/Show`. Needs new keys added. |
| `Admin/Programs/Show.svelte` | Has tabs: Overview, Processes, Stations, Staff, Tokens, Tracks, Diagram, Settings. New "Public Page" tab goes here. |
| `Site` model | Has `settings` (JSON/array cast) with `public_access_key` already planned. |
| `DisplayController@siteLanding` | Currently lists all active programs. Must filter by `is_published` once that flag exists. |
| `DisplayController@publicDisplay` | New method (from v3 plan) — needs `known_programs` cookie check for private programs. |
| Home.svelte | Old version — all changes from v3 plan still pending. |
| Admin/Sites/Show.svelte | Old version — Landing Page section still pending. |

---

## Part 1 — Scope of This Plan

**In scope:**
- Two-layer key system fully implemented (site key + program key)
- `is_published` flag on programs (replaces filtering by `is_active` alone on site landing)
- Program public page (description, banner image, announcement text)
- QR code generation for site entry and program entry
- Opaque short-URL resolver for QR codes
- `known_programs` temporary cookie for private program access
- Program key entry modal (mirrors site key entry)
- All gaps from v3 plan confirmed missing in code audit

**This plan absorbs and replaces v3 — treat this as the single source of truth.**

---

## Part 2 — Data Model Changes

### 2.1 Program — New Settings Keys

All new program settings live inside the existing `program.settings` JSON column. No new DB columns except one flag.

**New DB column:** `is_published` (boolean, default `true`) on the `programs` table. This is a first-class column (not in settings) because it's queried in scopes, not just read from JSON.

Migration: `add_is_published_to_programs_table` — `$table->boolean('is_published')->default(true)->after('is_paused')`.

Add to `Program::$fillable` and `Program::$casts`.

**New `program.settings` keys:**

| Key | Type | Default | Description |
|---|---|---|---|
| `public_access_key` | string\|null | null | Program key. Null = program is public within site. Set = program is private. |
| `public_access_expiry_hours` | int | 24 | How long the temporary program-access cookie lasts after key entry. |
| `page_description` | string\|null | null | Short public-facing description shown on program page. |
| `page_announcement` | string\|null | null | Ephemeral notice (e.g. "Open until 4PM today"). Shown prominently on program page. |
| `page_banner_image_path` | string\|null | null | Storage path to banner image. Never accepted through general settings PUT. |

**New `ProgramSettings` getters to add:**

- `getPublicAccessKey(): ?string`
- `getPublicAccessExpiryHours(): int` (default 24, min 1, max 168)
- `getPageDescription(): ?string`
- `getPageAnnouncement(): ?string`
- `isPrivate(): bool` — returns `$this->getPublicAccessKey() !== null`

### 2.2 Site — Confirm Existing Settings Keys

`public_access_key` is already planned in `site.settings`. Confirm `UpdateSiteRequest` validates it. No new site model changes needed.

### 2.3 New: `program_access_tokens` Table

For private program access, we need a server-side record of issued temporary tokens — so they can be revoked and validated properly (not just trusted from a cookie alone).

**Migration:** `create_program_access_tokens_table`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `program_id` | bigint FK | `programs.id`, cascade delete |
| `token_hash` | string | SHA-256 hash of the raw token |
| `expires_at` | timestamp | Based on `public_access_expiry_hours` at time of issue |
| `created_at` | timestamp | |

**No `updated_at`** — these are immutable once created.

**Model:** `ProgramAccessToken` — simple model, `program()` belongs-to relation.

**Cleanup:** Scheduled job or middleware pruning of expired records (soft: just ignore expired on lookup; hard: prune weekly).

### 2.4 New: `site_short_links` Table

For opaque QR code URLs. Both site and program QR codes resolve through this table.

**Migration:** `create_site_short_links_table`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `code` | string(12) | Unique opaque code (random alphanumeric). Indexed. |
| `type` | enum | `site_entry`, `program_public`, `program_private` |
| `site_id` | bigint FK nullable | |
| `program_id` | bigint FK nullable | |
| `created_at` | timestamp | |

**No expiry on the short link itself** — the link always works. What expires is the program-access cookie granted after key validation. The link just points at the flow.

**Model:** `SiteShortLink` — with `site()` and `program()` relations.

---

## Part 3 — New Settings in ProgramSettings

Add to `app/Support/ProgramSettings.php`:

```
getPublicAccessKey(): ?string
getPublicAccessExpiryHours(): int  (default 24, clamp 1–168)
getPageDescription(): ?string
getPageAnnouncement(): ?string
isPrivate(): bool  (true when public_access_key is not null)
```

---

## Part 4 — Site Landing Page Logic Change

### 4.1 `DisplayController@siteLanding`

**Current:** Shows all `is_active = true` programs.

**New logic:**
- Show programs where `is_active = true` AND `is_published = true` AND `public_access_key IS NULL` (i.e. public within site) — these appear on the landing for anyone with the site key.
- Private programs (`public_access_key` set) do **not** appear on the site landing regardless of `is_published`. They are only reachable via direct program key entry or QR.
- Pass `is_published` and `is_private` flags in the program payload so `Landing.svelte` can render accordingly in future.

### 4.2 `RequireSiteAccess` Middleware

Already planned in v3. Confirm it checks `known_sites` cookie for the site slug. If not present, redirect to `/` (not 404).

---

## Part 5 — Program Key System

### 5.1 Backend — Program Key Validation Endpoint

**Route:** `POST /api/public/program-key` (no auth, throttled 10/min per IP)

**Request body:** `{ "site_slug": "tagudin", "key": "AICS-PRIV" }`

**Logic:**
1. Resolve site by slug. If not found → 404 (don't reveal existence).
2. Confirm device has `known_sites` cookie containing this site slug. If not → 403 ("Site access required first"). The site gate must be passed before the program gate.
3. Find program in that site where `settings->public_access_key` matches the submitted key (case-insensitive). If not found → 404.
4. Confirm program `is_active = true`. If not → 404.
5. Generate a raw random token (32 bytes, hex). Hash it (SHA-256). Store in `program_access_tokens` with `program_id` and `expires_at` = now + `public_access_expiry_hours`.
6. Return `{ "program_slug": "...", "site_slug": "...", "program_name": "...", "token": "<raw_token>", "expires_at": "<iso8601>" }`.

**Controller:** New `PublicProgramKeyController` in `app/Http/Controllers/Api/`.

### 5.2 Frontend — `known_programs` Cookie

**Shape:** JSON array of program access objects:
```json
[
  {
    "site_slug": "tagudin",
    "program_slug": "aics-private",
    "program_name": "AICS Pre-screening",
    "token": "<raw_token>",
    "expires_at": "2026-03-16T08:00:00Z"
  }
]
```

**Written by:** Frontend after successful `POST /api/public/program-key` response.

**Expiry:** Individual entry expiry is tracked in `expires_at`. The cookie itself has a long expiry (1 year) — expired entries are pruned client-side on read.

**Cookie name:** `known_programs`

**Used by:** `RequireProgramAccess` middleware (Part 5.3) and the program key entry modal logic.

### 5.3 New: `RequireProgramAccess` Middleware

Applied only to the public display route (`/site/{site}/program/{program}/view`) and the program landing page (Part 8).

**Logic:**
1. Load program from route binding.
2. If `program->settings()->isPrivate()` is false → pass through (public program, no key needed beyond site key).
3. If private → read `known_programs` cookie, find entry matching `site_slug` + `program_slug`.
4. If no matching entry → redirect to `/` (not 404 — don't reveal the program exists).
5. If matching entry found → validate `token` against `program_access_tokens` table (hash the raw token, look up by hash + program_id, check `expires_at > now()`).
6. If token valid → pass through.
7. If token expired or invalid → clear the entry from cookie, redirect to `/` with a flash that access has expired.

**Apply to:** The entire `/site/{site}/program/{slug}/...` route group for public-facing routes (view, program landing page). Not applied to the device-auth routes — those have their own gating.

---

## Part 6 — QR Code System

### 6.1 Short Link Generation

**New API endpoints (admin only):**

`POST /api/admin/sites/{site}/generate-qr` — generates a `site_entry` short link for the site.

`POST /api/admin/programs/{program}/generate-qr?type=public` — generates a `program_public` short link.

`POST /api/admin/programs/{program}/generate-qr?type=private` — generates a `program_private` short link (only valid when program has a key set).

**Controller:** New `ShortLinkController` in `app/Http/Controllers/Api/Admin/`.

**Logic:** Check if a short link of that type already exists for the site/program. If yes, return the existing one (idempotent). If no, generate a new 8-character random alphanumeric code (retry on collision), store in `site_short_links`, return the full short URL.

**Short URL format:** `GET /go/{code}` — opaque, no slug visible.

### 6.2 Short Link Resolver

**Route:** `GET /go/{code}` — no auth, no middleware except standard web.

**Controller:** New `ShortLinkResolverController`.

**Logic by type:**

| Type | Behavior |
|---|---|
| `site_entry` | Redirect to `/` with a `?site_key_for={site_slug}` hint, so the frontend auto-opens the key entry modal pre-filled (or directly adds if site key is embedded — see 6.3). |
| `program_public` | If device has site in `known_sites` → redirect to `/site/{slug}/program/{prog_slug}/view`. If not → redirect to `/` with `?after_site={site_slug}&then_program={prog_slug}` so after key entry it resumes to the program. |
| `program_private` | Same as program_public but after site access, also triggers program key entry modal. |

**The slug never appears in the `/go/{code}` URL itself.** The resolved destination URL will contain the slug (e.g. `/site/tagudin/...`) but the QR code only ever shows `/go/aB3xK9`.

### 6.3 QR Code Rendering

QR codes are rendered **client-side** in the admin UI using a lightweight JS QR library (e.g. `qrcode` npm package — already common in Laravel/Vite stacks). No server-side image generation needed.

**Where QR codes are shown:**
- `Admin/Sites/Show.svelte` → "Public Access" section → QR for site entry
- `Admin/Programs/Show.svelte` → new "Public Page" tab → QR for public program (if published) + QR for private program (if key set)

**QR display:** Show the QR image, the short URL below it, and a "Download QR" button (triggers canvas-to-PNG download). Also show a "Copy link" button for the short URL.

---

## Part 7 — Program Public Page (New Route)

### 7.1 Route

**URL:** `GET /site/{site:slug}/program/{program:slug}/info`

**Named route:** `site.program.info`

**Middleware:** `require.site.access`, `require.program.access` (Part 5.3). Standard web only — no device auth, no lock.

**Purpose:** A read-only public-facing page for the program. Clients can see program info before deciding to enter. Links from here to the public display (`/view`) and device setup.

### 7.2 Controller

Add `programInfo(Request $request, Site $site, string $program_slug)` to `DisplayController`.

**Props passed:**
```php
[
  'site'        => ['id', 'name', 'slug'],
  'program'     => ['id', 'name', 'slug', 'description', 'is_active', 'is_paused'],
  'page'        => [
    'description'        => settings()->getPageDescription(),
    'announcement'       => settings()->getPageAnnouncement(),
    'banner_image_url'   => Storage::url($settings['page_banner_image_path']) or null,
  ],
  'is_private'  => $program->settings()->isPrivate(),
]
```

### 7.3 New Page: `Site/ProgramInfo.svelte`

**Layout:** `DisplayLayout` (same as site landing — public-facing).

**Contents:**
- Banner image (if set) as a header image.
- Program name as page title.
- Announcement block (if set) — prominent, styled differently to signal it's time-sensitive.
- Description text (if set).
- Program status badge (Active / Paused).
- Two CTAs at the bottom (same as site landing cards):
  - "Monitor your queue" → `/site/{slug}/program/{slug}/view`
  - "Use this device" → `/site/{slug}/program/{slug}`

**Graceful:** If no description, no announcement, no banner — still renders cleanly with just the name and CTAs.

---

## Part 8 — Admin: Program "Public Page" Tab

### 8.1 New Tab in `Admin/Programs/Show.svelte`

Add `"public-page"` to `VALID_TABS` and `TABS` arrays. Insert between "Overview" and "Processes" in the tab order — it's a commonly used tab once programs go live.

**Tab label:** "Public Page"

### 8.2 Tab Contents

**Section 1 — Visibility**

- Toggle: "Published" (`is_published`) — when off, program is hidden from site landing. A warning shows: "This program will not appear on the site landing page."
- Toggle: "Private" (derived from whether `public_access_key` is set or null) — when enabled, reveals the key input field.
- Input: "Program key" (only shown when Private is on) — text input, max 20 chars, alphanumeric + hyphens.
- Select: "Access expiry" — options: 8 hours, 24 hours, 48 hours, 72 hours, 1 week. Maps to `public_access_expiry_hours`.
- Warning when Private is on: "Clients must enter this key to access the program. It will not appear on the site landing."

**Section 2 — Page Content**

- Textarea: "Description" (`page_description`) — max 500 chars. Shows on public program info page.
- Textarea: "Announcement" (`page_announcement`) — max 200 chars. Shown prominently. Intended for short-lived notices.
- Image upload: "Banner image" — thumbnail preview, Replace button, Remove button. Same pattern as site hero image.

**Section 3 — QR Codes**

- "Public QR" card — shows QR for `/go/{code}` pointing to program public view. Download + Copy link buttons. Only shown when `is_published = true` and program is not private.
- "Private QR" card — shows QR for `/go/{code}` pointing to program private entry. Download + Copy link. Only shown when program has a key set.
- "Generate QR" button when no QR exists yet for that type. Calls `POST /api/admin/programs/{program}/generate-qr`.

**Save button:** Saves `is_published`, `public_access_key`, `public_access_expiry_hours`, `page_description`, `page_announcement` via `PUT /api/admin/programs/{program}` with these keys in `settings` (plus `is_published` as a top-level field).

Banner image handled separately via its own upload endpoint (same pattern as site hero image).

### 8.3 Backend — Program Update Endpoint

Extend `PUT /api/admin/programs/{program}` (`AdminProgramController@update`) to accept:
- `is_published` (boolean)
- `settings.public_access_key` (nullable string, max 20, alphanumeric + hyphens)
- `settings.public_access_expiry_hours` (integer, 1–168)
- `settings.page_description` (nullable string, max 500)
- `settings.page_announcement` (nullable string, max 200)
- `settings.page_banner_image_path` — **blocked** (upload endpoint only)

### 8.4 Program Banner Image Endpoint

**Route:** `POST /api/admin/programs/{program}/banner-image`
**Route:** `DELETE /api/admin/programs/{program}/banner-image`

**Controller:** New `ProgramBannerImageController`.

**Storage:** `public/program-assets/{program->id}/banner.{ext}`.

**Accepted:** `jpeg`, `png`, `webp`. Max 2MB.

---

## Part 9 — Admin: Site "Public Access" Section Update

### 9.1 `Admin/Sites/Show.svelte` — Confirm Full Landing Section

The code audit confirmed the entire Landing Page section is **missing** from the current file. The following must be built fresh (not modified — it doesn't exist):

**New "Landing Page" section** (insert between "Public site URL" and "API key"):

**Public Access subsection:**
- Text input: "Site key" (`settings.public_access_key`) — warning if empty: "No site key set — public devices cannot discover this site."
- QR code display for site entry (`site_entry` type short link). Generate button if none exists. Download + Copy.

**Hero subsection:**
- Text input: "Page title" (`landing_hero_title`)
- Textarea: "Description" (`landing_hero_description`)
- Image upload: "Hero image" (thumbnail, Replace, Remove)
- Toggle: "Show served stats" (`landing_show_stats`)

**Content sections subsection:**
- Ordered list, each with title + body preview
- Add, edit inline, remove (with confirm), up/down reorder

**One save button** for all text fields. Image handled separately.

### 9.2 `SitesPageController@show`

Extend the `site` payload to include landing settings:
```php
'landing' => [
    'hero_title'       => $site->settings['landing_hero_title'] ?? $site->name,
    'hero_description' => $site->settings['landing_hero_description'] ?? null,
    'hero_image_url'   => isset($site->settings['landing_hero_image_path'])
                            ? Storage::disk('public')->url($site->settings['landing_hero_image_path'])
                            : null,
    'sections'         => $site->settings['landing_sections'] ?? [],
    'show_stats'       => $site->settings['landing_show_stats'] ?? false,
    'public_access_key'=> $site->settings['public_access_key'] ?? null,
],
```

---

## Part 10 — Homepage (Confirming All v3 Gaps)

All of these were confirmed **missing** from the current `Home.svelte`. Must be built:

- Remove `queueCount`, `processedToday`, `hasActiveProgram`, `showPeopleServed`, `showTriageCta`
- Add `servedCount` + `sessionHours` fetched from `GET /api/home-stats` on mount, 30s poll
- Primary CTA: "Monitor your queue (Beta)" — not "Launch Queue Display"
- CTA destination resolution using `known_sites` cookie
- Site key entry modal (0 known sites)
- Site picker modal (2+ known sites)
- Remove "Start Triage" from "Ready to check in?" section

**`HomeController`** must pass `default_site_slug` and stop passing `queueCount` / `processedToday`.

---

## Part 11 — Complete Files Matrix

| # | File | Type | Reason |
|---|---|---|---|
| 1 | `database/migrations/..._add_is_published_to_programs.php` | Create | `is_published` boolean column |
| 2 | `database/migrations/..._create_program_access_tokens.php` | Create | Temporary program access token storage |
| 3 | `database/migrations/..._create_site_short_links.php` | Create | Opaque QR code resolver table |
| 4 | `app/Models/Program.php` | Edit | Add `is_published` to fillable/casts; add `scopePublished` |
| 5 | `app/Models/ProgramAccessToken.php` | Create | New model |
| 6 | `app/Models/SiteShortLink.php` | Create | New model |
| 7 | `app/Support/ProgramSettings.php` | Edit | Add 5 new getters |
| 8 | `app/Http/Controllers/Api/PublicProgramKeyController.php` | Create | POST /api/public/program-key |
| 9 | `app/Http/Controllers/Api/PublicSiteKeyController.php` | Create (v3 gap) | POST /api/public/site-key |
| 10 | `app/Http/Controllers/Api/HomeStatsController.php` | Create (v3 gap) | GET /api/home-stats |
| 11 | `app/Http/Controllers/Api/PublicSiteStatsController.php` | Create (v3 gap) | GET /api/public/site-stats/{slug} |
| 12 | `app/Http/Controllers/Api/Admin/ShortLinkController.php` | Create | QR generation for site + program |
| 13 | `app/Http/Controllers/Api/Admin/ProgramBannerImageController.php` | Create | Banner upload/delete |
| 14 | `app/Http/Controllers/Api/Admin/SiteHeroImageController.php` | Create (v3 gap) | Hero image upload/delete |
| 15 | `app/Http/Controllers/ShortLinkResolverController.php` | Create | GET /go/{code} |
| 16 | `app/Http/Middleware/RequireSiteAccess.php` | Create (v3 gap) | Gate /site/* by known_sites cookie |
| 17 | `app/Http/Middleware/RequireProgramAccess.php` | Create | Gate private programs |
| 18 | `app/Services/HomeStatsService.php` | Create (v3 gap) | Global + site-scoped stats |
| 19 | `app/Http/Controllers/HomeController.php` | Edit (v3 gap) | Remove old props, add default_site_slug |
| 20 | `app/Http/Controllers/DisplayController.php` | Edit | Add publicDisplay(), programInfo(), update siteLanding() |
| 21 | `app/Http/Controllers/Admin/ProgramPageController.php` | Edit | Add is_published + new settings keys to payload |
| 22 | `app/Http/Controllers/Admin/SitesPageController.php` | Edit (v3 gap) | Add landing props to site payload |
| 23 | `app/Http/Requests/UpdateSiteRequest.php` | Edit (v3 gap) | Add landing_* + public_access_key validation |
| 24 | `routes/web.php` | Edit | Add /go/{code}, /program/{slug}/info, update site group middleware |
| 25 | `routes/api.php` | Edit | Add all new public + admin API routes |
| 26 | `resources/js/Pages/Home.svelte` | Edit (v3 gap) | Full homepage overhaul |
| 27 | `resources/js/Pages/Site/Landing.svelte` | Edit (v3 gap) | Two-action cards, hero, sections, stats |
| 28 | `resources/js/Pages/Site/ProgramInfo.svelte` | Create | New public program info page |
| 29 | `resources/js/Pages/Admin/Sites/Show.svelte` | Edit (v3 gap) | Full Landing Page section + site key + QR |
| 30 | `resources/js/Pages/Admin/Programs/Show.svelte` | Edit | Add "Public Page" tab |
| 31 | `resources/js/Layouts/AdminLayout.svelte` | Edit | Site-scoped admin nav entry for their own site |
| 32 | `app/Http/Middleware/SetPublicSubscriptionCookie.php` | Delete | Replaced by known_sites system |
| 33 | `app/Http/Middleware/RedirectByPublicSubscription.php` | Delete | Replaced by known_sites system |
| 34 | `app/Services/DisplayBoardService.php` | Edit (v3 gap) | Confirm explicit $programId param |
| 35 | `resources/js/Pages/Display/Board.svelte` | Edit (v3 gap) | publicView prop, theme toggle to header |
| 36 | `app/Http/Middleware/EnforceDeviceLock.php` | Edit (v3 gap) | Exclude public view + program info routes |

---

## Part 12 — Implementation Order

| Step | Task | Depends On |
|---|---|---|
| 1 | Migrations (is_published, program_access_tokens, site_short_links) | Nothing |
| 2 | Model updates (Program fillable/casts, new models) | Step 1 |
| 3 | ProgramSettings — 5 new getters | Nothing |
| 4 | HomeStatsService + /api/home-stats + /api/public/site-stats | Nothing |
| 5 | HomeController cleanup + default_site_slug | Step 4 |
| 6 | Home.svelte full overhaul | Steps 4, 5 |
| 7 | PublicSiteKeyController + /api/public/site-key | Nothing |
| 8 | RequireSiteAccess middleware | Step 7 |
| 9 | PublicProgramKeyController + /api/public/program-key | Steps 2, 8 |
| 10 | RequireProgramAccess middleware | Step 9 |
| 11 | ShortLinkResolverController + /go/{code} route | Step 2 |
| 12 | ShortLinkController (admin QR generation) | Step 11 |
| 13 | DisplayBoardService param fix | Nothing |
| 14 | DisplayController — publicDisplay(), programInfo(), siteLanding() update | Steps 3, 10, 13 |
| 15 | Board.svelte publicView prop + theme toggle | Step 14 |
| 16 | EnforceDeviceLock exclusions | Step 14 |
| 17 | Site/ProgramInfo.svelte | Step 14 |
| 18 | Site/Landing.svelte full redesign | Steps 8, 14 |
| 19 | UpdateSiteRequest + SiteController settings | Nothing |
| 20 | SiteHeroImageController + ProgramBannerImageController | Step 19 |
| 21 | SitesPageController@show — landing props | Step 19 |
| 22 | Admin/Sites/Show.svelte — full Landing Page section + site key + QR | Steps 12, 20, 21 |
| 23 | ProgramPageController — add is_published + new settings keys | Steps 2, 3 |
| 24 | Admin/Programs/Show.svelte — Public Page tab | Steps 12, 20, 23 |
| 25 | AdminLayout — site-scoped admin nav entry | Nothing |
| 26 | Delete old subscription middleware files | Step 8 confirmed working |

---
Here are the additions and the redone Part 13.

---

## Addition A — QR Auto-Unlock (Embedded Key in Short Link)

### A.1 Schema Change to `site_short_links`

Add one column to the migration:

| Column | Type | Notes |
|---|---|---|
| `embedded_key` | string nullable | Stores the raw program key at time of QR generation. Only populated for `program_private` type when admin chooses "Scannable" QR. Encrypted at rest using Laravel's `encrypted` cast. |

**Why store it here and not derive it:** At scan time the resolver needs the key to perform silent validation. The program key in `program.settings` could change after the QR was generated — if it does, the old QR should stop working. Storing the key in the short link means a key rotation automatically breaks old scannable QRs, which is correct security behavior.

**`SiteShortLink` model:** Add `embedded_key` to fillable, cast as `encrypted`.

### A.2 Two QR Variants for Private Programs

When an admin generates a QR for a private program, they choose one of two types:

| Variant | Label in UI | Behavior on scan | Security posture |
|---|---|---|---|
| **Key-entry QR** | "QR with key prompt" | Scan → site check → program key modal (user types key) | Key not in QR image |
| **Scannable QR** | "Scannable key QR" | Scan → silent auto-unlock → lands on program directly | Key embedded — share carefully |

Both generate a `/go/{code}` URL. The difference is whether `embedded_key` is populated in `site_short_links` and whether the resolver performs silent validation or redirects to key entry.

**In the admin UI (Programs/Show → Public Page tab):** Show both options side by side under "Private QR Codes." Each has its own Generate button, QR display, Download, and Copy link. A warning label under Scannable QR: "⚠ Anyone who scans this gets immediate access. Treat it like a physical key."

### A.3 Resolver Logic Change for `program_private` with `embedded_key`

Update `ShortLinkResolverController` to handle this case:

**When `type = program_private` and `embedded_key` is NOT null (scannable QR):**

1. Resolve site and program from the short link record.
2. Check device `known_sites` cookie. If site not present → store intended destination in session, redirect to site key entry flow, resume after.
3. Validate `embedded_key` against `program->settings()->getPublicAccessKey()` (case-insensitive). If mismatch (key was rotated since QR generation) → redirect to `/` with a "This QR code is no longer valid" flash. Do not expose why.
4. If valid → generate a `ProgramAccessToken` (same logic as `PublicProgramKeyController`), write `known_programs` cookie entry, redirect straight to `/site/{slug}/program/{slug}/view`.
5. User never sees the key. URL never contains the key.

**When `type = program_private` and `embedded_key` IS null (key-entry QR):**

Existing behavior — redirect to site entry flow, then program key modal.

### A.4 `ShortLinkController` — Generate QR Endpoint Change

`POST /api/admin/programs/{program}/generate-qr` — add `type` field to request body:

```
type: "public" | "private_prompt" | "private_scannable"
```

- `public` → creates `program_public` short link, no embedded_key.
- `private_prompt` → creates `program_private` short link, embedded_key null.
- `private_scannable` → creates `program_private` short link, embedded_key = current `public_access_key` value. Fails with 422 if program has no key set.

**Important:** When admin changes the program key (`PUT /api/admin/programs/{program}` with new `settings.public_access_key`), the backend must **delete all existing `program_private` short links** for that program (both variants). This forces QR regeneration with the new key, making old scannable QRs dead immediately. Add this as a side effect in `AdminProgramController@update`.

---

## Addition B — Program Access Token Revocation UI

### B.1 Token Stats Endpoint

**Route:** `GET /api/admin/programs/{program}/access-tokens` (admin auth)

**Response:**
```json
{
  "active_count": 4,
  "tokens": [
    {
      "id": 12,
      "token_ref": "a3f9",
      "issued_at": "2026-03-15T08:00:00Z",
      "expires_at": "2026-03-16T08:00:00Z"
    }
  ]
}
```

`token_ref` = last 4 characters of the token hash. Not enough to reconstruct the token. Just enough for the admin to cross-reference with a specific person if needed.

**Controller:** Add `accessTokens()` method to a new `ProgramAccessTokenController` or inline in `AdminProgramController`.

### B.2 Revoke All Endpoint

**Route:** `DELETE /api/admin/programs/{program}/access-tokens` (admin auth)

**Logic:** Hard-delete all `ProgramAccessToken` records for the program. Immediately invalidates all active sessions — any device with a `known_programs` cookie entry for this program will be denied on next request (middleware checks against the DB).

**Audit log:** Write to `AdminActionLog` — `program_access_tokens_revoked`, with count of deleted tokens.

### B.3 Revoke Individual Token

**Route:** `DELETE /api/admin/programs/{program}/access-tokens/{token_id}` (admin auth)

**Logic:** Delete the single `ProgramAccessToken` record by ID. Validates the token belongs to the program (not just any token ID).

### B.4 Auto-Revocation Triggers

The following actions must automatically revoke all tokens for the affected program (add as side effects in their respective controller methods):

| Trigger | Where to add side effect |
|---|---|
| Program key changed (`settings.public_access_key` updated) | `AdminProgramController@update` |
| Program key cleared (set to null) | `AdminProgramController@update` |
| Program deactivated | `AdminProgramController@deactivate` |
| Program deleted | `Program::booted()` deleting hook (cascade or explicit) |

For key change and key clear: also delete all `program_private` short links for that program (as noted in A.4).

### B.5 Admin UI — Programs/Show → Public Page Tab

Add a "Active Access Tokens" subsection at the bottom of the Public Page tab. Only shown when program is private (has a key set).

**Contents:**
- Count badge: "X active tokens"
- Table of tokens: `token_ref`, `issued_at`, `expires_at`, individual Revoke button per row.
- "Revoke all" button — prominent, with a confirm modal: "This will immediately cut off access for all devices that have unlocked this program. They will need to re-enter the key."
- Auto-refresh: re-fetch token list every 30s while tab is open (same pattern as other polling in the app).
- Empty state: "No active tokens. Devices will need to enter the program key to gain access."

### B.6 `RequireProgramAccess` Middleware — DB Check Confirmed

Already specified in Part 10 of the main plan but worth restating here: the middleware does not trust the cookie alone. It hashes the raw token from the cookie and looks it up in `program_access_tokens` by hash + program_id + `expires_at > now()`. If the record was revoked (deleted), the lookup fails and access is denied even if the cookie is still present and unexpired. The cookie is just a carrier — the DB record is the authority.

---

## Part 13 — Deferred Items (Redone)

| # | Item | Note |
|---|---|---|
| 1 | Short link scan analytics | Add `scan_count` + `last_scanned_at` to `site_short_links` later. Useful for knowing if a distributed QR is actually being used. |
| 2 | Section types beyond `text` on site landing | Only `text` (title + body) in v1. Future: `image`, `links`, `divider`. |
| 3 | Central-edge settings subsection in Sites/Show | Existing edge settings section stays as-is. Future: dedicated "Central & Edge" subsection. Placeholder comment only for now. |
| 4 | Site key rotation and expiry | Site keys are static strings for now. Future: expiry date, one-time-use site keys, key rotation with grace period. |
| 5 | Per-device token identity | Currently a token is anonymous — no device fingerprint attached. Future: bind token to a device identifier so the same QR scan from two different devices issues two distinguishable tokens, enabling per-device revocation. |
| 6 | Scannable QR with time-limited validity | Currently scannable QRs are valid until the program key changes. Future: add an `expires_at` to the short link itself so a scannable QR can self-expire after N hours regardless of whether the key rotated. |
| 7 | Program landing page preview in admin | Admin can open the public program info URL manually to preview. No in-admin iframe preview now. |
| 8 | Bulk token expiry extension | Admin can currently only revoke. Future: extend expiry for all active tokens (e.g. session running long, need another hour). |
| 9 | Site picker page as a standalone route | Currently the site picker is a modal on the homepage. Future: a proper `/sites` page for devices that want to manage multiple known sites. |