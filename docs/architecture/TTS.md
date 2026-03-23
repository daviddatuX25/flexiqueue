# TTS architecture (Phase 1)

Single mental model: **token call** (site + token) then optional **station directions** (program + station), one string builder on the server. In code and APIs you may still see **segment 1 / segment 2** names; in admin copy we prefer plain language below.

## User-facing vocabulary

| Concept | Meaning |
|--------|---------|
| **Token call** | Pre-phrase + spoken token (letters / whole ID / or per-token `token_phrase` in **custom** mode) + optional token bridge tail |
| **Station directions** | Program connecting phrase + station wording (`station_phrase`) or station name |

## Technical segments (builder)

| Segment | Content | Owner |
|--------|---------|--------|
| **1** (token call) | `pre_phrase` + spoken token + optional `token_bridge_tail` | Site `token_tts_settings` + per-token overrides |
| **2** (station directions) | Program `connector_phrase` + `station_phrase` or station name | Program + station |

Do not merge the site **token bridge tail** with the program **connector**; they are different concerns.

## Storage (`token_tts_settings`)

| Field | Location |
|-------|-----------|
| Default voice, rate, `token_phrase` | `default_languages.{en,fil,ilo}` (edited in **Configuration → Audio & TTS**; `PUT` merges per language so partial updates do not wipe other keys) |
| `token_bridge_tail` | Same JSON (edited in **Configuration → Audio & TTS** when **station directions** are off — optional words after the spoken token, still within segment 1) |
| `closing_without_segment2` | Same JSON (optional second browser utterance after segment 1 when directions are off; retained for compatibility, not exposed in admin UI) |
| `pre_phrase` | Same JSON (edited on **Tokens** — **Token prephrase (site-wide)**) |
| Product toggles | `playback` JSON column |

### `playback` shape

```json
{
  "prefer_generated_audio": true,
  "allow_custom_pronunciation": true,
  "segment_2_enabled": true
}
```

- **`prefer_generated_audio: false`** — Informant displays use **browser speech** for announcements and do **not** fetch `/api/public/tts/token/{id}` for the call flow.
- **`segment_2_enabled: false`** — No station-direction audio after the token call; optional `closing_without_segment2[lang]` may be spoken after the token call if non-empty after trim.
- **`allow_custom_pronunciation: false`** — Admin UIs hide custom token/station wording fields. **Runtime:** `AnnouncementBuilder::mergeLangConfig` drops `token_phrase`; `resolveStationTtsPhrase` returns null so only the station **name** is used in directions. Program **connector** phrases are not “custom wording” in this sense and remain used when station directions are enabled.

Keys and provider credentials stay in `config` / `.env`; **product** toggles live in the DB.

## Admin Inertia (shared)

On admin routes, `HandleInertiaRequests` shares:

- `tts_allow_custom_pronunciation`
- `tts_segment_2_enabled`

So Program and Tokens pages can gate UI without an extra API round-trip.

## Server builder

`App\Services\Tts\AnnouncementBuilder` assembles:

- `buildSegment1(Token, TokenTtsSetting, lang, ?mergedConfig)` — token call text
- `buildSegment2(Station, Program, lang, TokenTtsSetting)` — station directions text (`TokenTtsSetting` required for `allow_custom_pronunciation` on station wording)
- `buildSegment2FromParts(...)` — admin preview with explicit parts
- `buildClosingWhenSegment2Disabled(TokenTtsSetting, lang)`
- `tokenSpokenByLangForBroadcast(Token, TokenTtsSetting)` for `StationActivity`

Low-level phonetics remain in `App\Support\TtsPhrase`.

## API

- `GET/PUT /api/admin/token-tts-settings` — includes `playback` and extended `languages` keys. **`PUT` merges** each of `en` / `fil` / `ilo` with existing `default_languages` so Configuration and Tokens pages can send partial `languages` payloads without clearing fields managed elsewhere.
- `GET /api/admin/tts/sample-phrase` — token call samples (builder-backed).
- `GET /api/admin/tts/preview-text?segment=1|2&...` — builder-backed text for admin previews (`segment=2` accepts optional `connector_phrase`, `station_name`, `station_phrase` for placeholders).

Admin **Play sample** vs **Play full**: **sample** plays one segment (token call *or* station directions, depending on context). **Play full** chains segment 1 + segment 2 (or segment 1 + optional `closing_without_segment2`) in order, using the same strings `AnnouncementBuilder` would use, via `playAdminFullAnnouncementPreview` in `resources/js/lib/ttsPreview.js` (sequential `playAdminTtsPreview`, not the display board’s `playFullAnnouncement` queue).

## Multi-provider engine (server generation)

- **Contract** — Synthesis goes through `App\Services\Tts\Contracts\TtsEngine`. Implementations live under `App\Services\Tts\Engines\` (e.g. `ElevenLabsEngine`, `NullTtsEngine`). `App\Services\TtsService` delegates `getPath` / `getVoicesList` / `isEnabled` to the resolved engine; `config('tts.driver')` selects the engine via `AppServiceProvider` bindings.
- **Credentials** — `App\Services\Tts\ElevenLabsCredentials` resolves API key and model from `TtsAccount` **only when** the active account’s `provider` matches `tts.driver`, then falls back to `config('tts.elevenlabs.*')`. Avoids using an ElevenLabs key when the driver is another engine.
- **Active accounts** — At most **one active `TtsAccount` per `provider`** (not one global active row). See `docs/architecture/adr/001-tts-account-one-active-per-provider.md`.
- **Cache keys** — On-disk phrase cache under `tts.cache_path` is keyed by `hash(engine_cache_segment + text + voice + rate)` so different providers/models do not overwrite each other.
- **Revision assets** — `App\Services\Tts\TtsAssetIdentity::build()` accepts optional `providerKey` and `engineModelKey`; jobs pass `TtsService::getProviderKey()` and `getAssetIdentityModelKey()`. Omitted args preserve the legacy canonical form (backward compatible).
- **Adding a provider** — Implement `TtsEngine`, register in `AppServiceProvider` for a new `tts.driver` value, add rows to `tts_accounts` with matching `provider`, extend admin integration if needed. Out of scope until a second engine is required.

### v1 non-goals (routing)

- **Single global driver** — Phase 1 uses a **single** `config('tts.driver')` for all synthesis. **Per-language or per-token multi-driver routing** is explicitly **deferred** (no parallel engines per language in one request).

## Site TTS generation budget

Per-site policy is stored in **`sites.settings.tts_budget`** (JSON, merged on `PUT` with `settings.tts_budget` so partial updates do not wipe other keys).

- **Policy shape** — `App\Services\Tts\TtsBudgetPolicy`: `enabled`, `mode` (`chars` only in Phase 1), `period` (`daily` \| `monthly`), `limit`, `warning_threshold_pct`, `block_on_limit`. Enforcement is **enabled** when `enabled` is true **and** `limit > 0`.
- **Metering** — On successful synthesis (cache miss path), `App\Services\Tts\TtsGenerationMeter` records usage into `site_tts_usage_events`; rollups feed `site_tts_usage_rollups`.
- **Guard** — Before synthesis, `App\Services\Tts\TtsBudgetGuard` blocks when policy is enforced and the site is over limit (when `block_on_limit` is true).
- **Admin APIs** — `GET /api/admin/tts/budget` (current user’s site), `GET /api/admin/sites/{site}/tts-budget` (superadmin or that site’s admin), `GET /api/admin/tts/budgets` (superadmin, all sites). Updates go through **`PUT /api/admin/sites/{site}`** with `settings.tts_budget` (validated in `UpdateSiteRequest`).
- **Platform global budget (super_admin)** — `GET`/`PUT /api/admin/tts/platform-budget` loads/saves `tts_platform_budgets` + `tts_site_budget_weights`. When enabled, **weighted** allocation and a single platform period drive rollups and `TtsBudgetGuard` (see `docs/architecture/adr/002-tts-global-platform-budget-weighted.md`).
- **Superadmin Configuration UI** — **`Admin/Settings`** for `super_admin`: **TTS Generation**, **Program defaults** (platform `program_default_settings` row — same template site admins edit), and **Default print settings** (`print_settings.site_id` null — platform template copied when a new site is created). Super admins do **not** see Storage, site **Print settings**, or **Audio & TTS** (`token-tts-settings`). **Site admins** use **Configuration → Audio & TTS** (`TtsBudgetCard`) and **Admin → Sites → {site}** for per-site TTS generation budget when global mode is off.

**APIs (super_admin Configuration):**

| Purpose | Method | Route |
|--------|--------|--------|
| Platform program defaults | `GET` / `PUT` | `/api/admin/program-default-settings` (shared with site `admin`) |
| Platform print template | `GET` / `PUT` / `POST` …`/image` | `/api/admin/print-platform-default-settings` (super_admin only) |
| TTS Generation (budget, integrations) | existing | `/api/admin/tts/platform-budget`, `/api/admin/integrations/elevenlabs`, … |

### Preview and metering

- **Jobs** — Token/station generation jobs pass `site_id` and `source=job` into `TtsService` so metering attributes usage to the correct site.
- **Admin preview** — Authenticated admin/super_admin preview requests to **`GET /api/public/tts?...`** (stream) are required; site-scoped users meter against their own `site_id`. **Successful on-demand synthesis counts toward the same site TTS budget** as job-generated audio (product decision: preview is not exempt from metering when the user is site-scoped).

## ElevenLabs usage policy

- **Generation only** — ElevenLabs is used exclusively for pre-generation (jobs: `GenerateTokenTtsJob`, `GenerateStationTtsJob`). Stored MP3s are written to disk; retrieval is via `/api/public/tts/token/{id}` and `/api/public/tts/station/{id}/{lang}`.
- **Display playback** — Uses pre-generated MP3s when available. When pre-generated audio is missing (404, not yet generated, etc.), the display uses **browser Web Speech Synthesis** only. It does **not** call `GET /api/public/tts?text=...` (on-demand ElevenLabs) for fallback.
- **Admin preview** — `playAdminTtsPreview` may still use `/api/public/tts?text=...` for low-volume voice preview; on failure it falls back to Web Speech.

## Display (Inertia props)

From `DisplayBoardService::getDisplayTtsPlaybackProps()` merged into board payloads:

- `prefer_generated_audio`, `segment_2_enabled`, `allow_custom_pronunciation`
- `tts_default_pre_phrase`, `tts_token_bridge_tail`, `tts_closing_without_segment2` (for the program’s active TTS language)

Frontend: `resources/js/lib/displayTts.js` implements explicit branches (no reliance on 503 for “browser only”).

## Admin UI entry points

- **Configuration → Audio & TTS** (`TokenTtsSettingsTab.svelte`) — global voice/speed, playback toggles, per-language voice/rate, optional default `token_phrase` when allowed; when station directions are off, per-language **token bridge tail** only; **Play station directions** preview when directions are on.
- **Tokens** — collapsible **Token prephrase (site-wide)** (`pre_phrase`, save + samples); **Edit token**: **Letters** — letter-by-letter phonetics plus digit runs; **Word** — each contiguous letter run as one spoken chunk, then digit runs (e.g. `AAB3` → “AAB” then “3”); **Custom** (after create, when `tts_allow_custom_pronunciation`) — per-language voice, rate, pre-phrase, and exact wording spoken as typed (ID not appended). Batch create offers **Letters** or **Word** only. Saving as **Letters** or **Word** clears per-token voice/rate/pre-phrase/token wording so site defaults apply.
- **Program → Stations** — **Connecting phrase TTS** (program-wide connecting phrase) and per-station **station directions** audio; hidden when `tts_segment_2_enabled` is false site-wide.
