# Plan: Per-station TTS generation (connector + station pronunciation)

## Goal

Give **station directions** audio the same **product behavior** as **token** TTS: explicit generate/regenerate, visible **status** (pending / ready / failed), and admin UX that matches the Tokens page patterns—without changing the underlying two-segment playback model on displays.

## Prerequisites (must be true before starting this plan)
1. `AnnouncementBuilder` segment 1 fallback is language-driven (no hardcoded English): `default_languages.{lang}.segment1_no_pre_tail_fallback`.
2. `AnnouncementBuilder` segment 2 is connector-driven: when the program connector phrase is not configured for the active language, `buildSegment2` returns an empty string (`''`).
3. A builder-backed server endpoint exists for token pronunciation body only: `GET /api/admin/tts/preview-token-spoken-part` (used by Tokens “Play sample”).
4. `resources/js/lib/displayTts.js::buildDisplaySegment1Text` is scoped to offline/browser-only mode (documented in code).

## Current state (baseline)

- **Token call (segment 1):** Pre-generated per token when enabled; status on `Token`; regenerate API; admin shows TTS status and actions.
- **Station directions (segment 2):** Built at runtime from program **connector** + station **phrase/name**; optional background generation for station audio exists with **aggregate** status in `station.settings.tts.languages.*.status`; regenerate per station and program-wide already partially wired.
- **Gap:** UX and status presentation for stations are not aligned with Tokens (single “Generate TTS” mostly on failure, separate speech modal was redundant—now merged into **Edit station**).

## Target behavior

1. **Per language (en / fil / ilo):** Stored audio (or clear failure) for the **segment 2** string that `AnnouncementBuilder::buildSegment2` would produce for that station + program + site rules.
2. **Admin UI (Program → Stations):**
   - Same **mental model** as tokens: status chip or row state, **Generate** / **Regenerate** when appropriate, optional bulk “regenerate all” (already exists for connector changes).
   - **Edit station** modal is the single place for station wording + previews (done in sibling work: Play sample / Play full).
3. **Display runtime:** Prefer pre-generated station clip when present and settings allow; fall back to current synthesize/browser path when missing (mirror token segment 1 fallback strategy).

## Technical workstreams

### A. Data & storage

- Confirm or extend `station.settings.tts.languages[lang]` shape: `status`, `audio_path` (or equivalent), `failure_reason`, `updated_at` (if not already present).
- Ensure **idempotent** paths under `tts/stations/` (or existing convention) and safe streaming route if audio is served like token files.

### B. Backend jobs & services

- Job (or extend existing station TTS job) to compute `buildSegment2(...)` text per lang and call `TtsService` once per language (or batched), then write paths + status.
- **Triggers:** create station (when `auto_generate_station_tts`), update station TTS fields, update program connector (existing regenerate-all), manual **Regenerate** on station card.
- **Concurrency / failures:** Mark `failed` with reason; do not block station save on generation errors.

### C. API

- Keep or refine `POST /api/admin/stations/{id}/regenerate-tts` and `POST /api/admin/programs/{id}/regenerate-station-tts` to match new status fields.
- Optional: GET health endpoint alignment with `token-tts-health` for stations (Phase 1 nice-to-have).

### D. Realtime / Inertia

- Reuse or extend `station_tts_status_updated` (or equivalent) so the Stations tab updates without full page reload when jobs complete.
- `router.reload({ only: [...] })` or targeted prop refresh for station list.
- Display boundary (online/offline): when server is reachable, fetch builder-backed segment 2 text from the server (do not rebuild connector+station text in JS); when offline, fall back to a locally cached segment 2 string included in the initial display payload. Document that boundary next to the API call.

### E. Frontend (Svelte)

- **Station cards:** Status + actions aligned with token row patterns (ready / failed / not generated); enable **Generate** when server TTS configured and segment 2 enabled site-wide.
- **Edit station modal:** No second modal; previews already beside language blocks.
- **Copy:** Clarify that generation is for **directions audio**, not the token call.

### F. Tests

- PHPUnit: regenerate endpoint permissions, job dispatch, status transitions, merge of settings on partial update.
- Optional Playwright: stub or skip if no stable TTS in CI; prefer API/job tests for Phase 1.

## Dependencies & order

1. **Schema/settings audit** — confirm what is already stored vs missing.
2. **Job + status writes** — make generation reliable before polishing UI.
3. **Display consumption** — use generated file when `status === ready` and path valid.
4. **UI parity** — tokens-like status and buttons last so they reflect real states.

## Enforced execution order (developer instructions)
1. Complete modularity prerequisites first (segment 1/2 fallbacks, builder-backed token spoken-part endpoint, and offline-only JS builder). Station work depends on these being stable; otherwise station generation and display logic can drift across languages.
2. Only then execute the station plan in the bead order:
   1. Schema/settings audit
   2. Job + status writes
   3. Display consumption (online/offline boundary)
   4. UI parity
3. Do not start station UI parity work until job + status writes are solid and tested, matching the token TTS discipline.

## Suggested beads (split for `bd dep`)
1. **BD-S1: Schema + settings audit**
   - Confirm `station.settings.tts.languages[lang]` keys written today (especially `status` and `audio_path`).
   - Align/standardize fields (`status`, `audio_path`, `failure_reason`, `updated_at`).
   - Confirm storage/idempotency convention under `tts/stations/`.
2. **BD-S2: Connector-driven job + per-language status**
   - Generate segment 2 text using `AnnouncementBuilder::buildSegment2`.
   - If connector phrase is missing for FIL/ILO (builder returns `''`), mark that language `failed` (reason: connector missing) and do not leave stale `audio_path`.
   - Write `ready` + `audio_path` on success.
3. **BD-S3: Display consumption (online/offline boundary)**
   - Online: fetch builder-backed segment 2 text from server (no JS rebuilding of connector+station text).
   - Offline: use locally cached segment 2 string included in initial display payload.
   - Document this boundary next to the API call in `displayTts.js`.
4. **BD-S4: UI parity (Program → Stations)**
   - Mirror token UI patterns: per-language status, Generate/Regenerate actions, and bulk regenerate on connector change.
   - Ensure station preview actions don’t drift (previews should use builder-backed logic already used in sibling work).

## Out of scope (for this bead)

- Changing **AnnouncementBuilder** semantics or merging segment 1 + 2 into one file.
- Non–ElevenLabs providers (follow existing `TtsService` abstraction).

## Acceptance criteria

- [ ] After saving station wording (or explicit action), admin sees **clear status** per language when generation runs.
- [ ] **Failed** state shows **Regenerate** like tokens; **ready** does not spam errors.
- [ ] Display board prefers cached station audio when available; when generating via TTS, it fetches builder-backed segment 2 text online and uses a locally cached segment 2 string offline.
- [ ] Connector-missing language rule is respected: when the program connector phrase is not configured for the active language, segment 2 resolves to `''` (no hardcoded English fallback).
- [ ] Connector change still offers **regenerate all stations** and completes without manual per-station clicks.
- [ ] Docs: `TTS.md` + UX map updated to describe station generation alongside token generation.

---

*This plan is intentionally implementation-ready but should be broken into **separate beads** (e.g. job+storage, display path, UI polish) with `bd dep` between them.*
