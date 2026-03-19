# API Spec — Phase 1 (excerpt)

This file is a minimal stub focusing on the token print settings, token TTS settings, and related public TTS endpoints.

## 5.x Token print template settings

### `GET /api/admin/print-settings`

- **Auth**: `auth`, `role:admin`
- **Description**: Return the singleton print template settings used for token card printing.
- **Response** `200 OK`:

```json
{
  "print_settings": {
    "cards_per_page": 6,
    "paper": "a4",
    "orientation": "portrait",
    "show_hint": true,
    "show_cut_lines": true,
    "logo_url": "https://example.com/logo.png",
    "footer_text": "Office rules apply",
    "bg_image_url": "https://example.com/bg.png"
  }
}
```

### `PUT /api/admin/print-settings`

- **Auth**: `auth`, `role:admin`
- **Description**: Update the singleton print template settings.
- **Body (JSON)**:
  - `cards_per_page` — integer, 4–8
  - `paper` — `"a4"` or `"letter"`
  - `orientation` — `"portrait"` or `"landscape"`
  - `show_hint` — boolean
  - `show_cut_lines` — boolean
  - `logo_url` — nullable string (absolute URL or `/storage/...`)
  - `footer_text` — nullable string
  - `bg_image_url` — nullable string (absolute URL or `/storage/...`)

### `POST /api/admin/print-settings/image`

- **Auth**: `auth`, `role:admin`
- **Description**: Upload a real image file for the token print template and attach it to the singleton settings.
- **Request**:
  - Content-Type: `multipart/form-data`
  - Fields:
    - `image` — required file field, image (`jpeg,jpg,png,webp`, max 4 MB)
    - `type` — optional string, one of:
      - `"logo"` (default)
      - `"background"`
- **Behavior**:
  - Stores the uploaded file on the `public` disk under `print-settings/`.
  - Resolves a public URL via `Storage::disk('public')->url(...)`.
  - Updates `PrintSetting::instance()->logo_url` when `type=logo` (or omitted).
  - Updates `PrintSetting::instance()->bg_image_url` when `type=background`.
- **Response** `201 Created`:

```json
{
  "url": "/storage/print-settings/print_logo_xxx.png",
  "type": "logo"
}
```

or

```json
{
  "url": "/storage/print-settings/print_background_xxx.png",
  "type": "background"
}
```

## 5.y Token TTS settings (global voice + rate)

### `GET /api/admin/token-tts-settings`

- **Auth**: `auth`, `role:admin`
- **Description**: Return the singleton server-side TTS settings for token call phrases.
- **Response** `200 OK`:

```json
{
  "token_tts_settings": {
    "voice_id": "21m00Tcm4TlvDq8ikWAM",
    "rate": 0.84
  }
}
```

Notes:

- `voice_id` is the engine-specific voice ID (e.g. ElevenLabs voice id). `null`/empty means: use `config('tts.default_voice_id')`.
- `rate` is the playback speed (float, 0.5–2.0). When omitted in requests, it falls back to the saved rate or `config('tts.default_rate')`.

### `PUT /api/admin/token-tts-settings`

- **Auth**: `auth`, `role:admin`
- **Description**: Update the global token TTS voice and rate used for:
  - Pre-generated token audio (offline playback).
  - On-demand server TTS when pre-generated audio is missing.
- **Body (JSON)**:
  - `voice_id` — nullable string, max 200 (engine voice ID; `null` = config default).
  - `rate` — nullable number, 0.5–2.0.

- **Response** `200 OK`:

```json
{
  "token_tts_settings": {
    "voice_id": "custom-voice-id",
    "rate": 1.25
  },
  "requires_regeneration": true
}
```

Notes:

- `requires_regeneration` is `true` when `voice_id` or `rate` changed **and** there are tokens with `tts_pre_generate_enabled = true`. The admin UI should then offer a separate “Regenerate TTS audio” action.

### `POST /api/admin/tokens/regenerate-tts`

- **Auth**: `auth`, `role:admin`
- **Description**: Queue regeneration of pre-generated TTS audio for all tokens opted in via `tts_pre_generate_enabled = true`.
- **Behavior**:
  - When server TTS is disabled, returns `503 Service Unavailable` with `queued = 0`.
  - Otherwise:
    - Sets `tts_status = "generating"` for all matching tokens.
    - Dispatches a background job to regenerate audio with the current global voice/rate.
- **Response** `200 OK`:

```json
{
  "queued": 42
}
```

## 5.z Token TTS fields on admin token APIs

### `GET /api/admin/tokens`

- **Auth**: `auth`, `role:admin`
- **Description**: Returns the list of tokens, including TTS status fields.
- **Response** `200 OK` (per-token shape excerpt):

```json
{
  "tokens": [
    {
      "id": 1,
      "physical_id": "A1",
      "pronounce_as": "letters",
      "qr_code_hash": "…",
      "status": "available",
      "tts_status": "pre_generated",
      "has_tts_audio": true
    }
  ]
}
```

Notes:

- `tts_status` is `null` / `"generating"` / `"pre_generated"` / `"failed"`.
- `has_tts_audio` is `true` when `tts_audio_path` is non-null.

### `POST /api/admin/tokens/batch`

- **Auth**: `auth`, `role:admin`
- **Description**: Create a batch of tokens (unchanged from original spec) with optional pre-generation flag.
- **Body (JSON)** (excerpt, new field):
  - `generate_tts` — optional boolean. When `true` and server TTS is enabled:
    - New tokens are marked with `tts_pre_generate_enabled = true` and `tts_status = "generating"`.
    - A background job is dispatched to generate their audio.

- **Response** `201 Created` (per-token shape matches `GET /api/admin/tokens`).

## 5.zz Station holding area

Per station-holding-area plan: staff can move a serving session into the station's holding area and later resume it.

### `POST /api/sessions/{session}/hold`

- **Auth**: `auth`, `role:admin,supervisor,staff`. Policy: user can update the session (session at user's assigned station).
- **Description**: Move the session from serving into this station's holding area. Session must be in `serving` at the station; station's holding area must not be full.
- **Body (JSON)**:
  - `remarks` — optional string, max 500 (stored in transaction_log).
- **Response** `200 OK`:
  - `message`: `"Session moved to holding"`.
  - `session_id`: session id.
- **Errors**:
  - `422` with `error_code: "holding_full"` when the station's holding area is at capacity.
  - `422` / `409` with `error_code: "invalid_state"` when session is not serving or not at this station.
  - `403` when user is not allowed to act on this session.

### `POST /api/sessions/{session}/resume-from-hold`

- **Auth**: Same as hold. Policy: user can update the session (session held at user's assigned station).
- **Description**: Resume a session from this station's holding area back to serving. Session must be on hold at this station; station must have serving capacity.
- **Body (JSON)**:
  - `remarks` — optional string, max 500 (stored in transaction_log).
- **Response** `200 OK`:
  - `message`: `"Session resumed"`.
  - `session_id`: session id.
- **Errors**:
  - `422` with `error_code: "at_capacity"` when the station is at serving capacity (complete or transfer a client first).
  - `422` / `409` with `error_code: "invalid_state"` when session is not on hold at this station.
  - `403` when user is not allowed to act on this session.

Station queue response (`GET /api/stations/{station}/queue`) includes:
- `holding`: array of held sessions (session_id, alias, track, client_category, status, held_at, process_name, current_step_order, total_steps).
- `station.holding_capacity`, `station.holding_count`.

## 6. Program identity settings (triage)

Per identity-binding and identity-registration plans, programs expose two knobs for identity at triage. These are stored under `program.settings` and surfaced in Admin UI and triage flows.

### Fields on `Program.settings`

- `identity_binding_mode` — string enum, one of:
  - `"disabled"` – No ID or registration step at triage (staff or public).
  - `"optional"` – Identity tools are shown, but binding/registration never blocks starting a visit.
  - `"required"` – An identity step is required before starting a visit.
- `allow_unverified_entry` — boolean (public triage only)
  - When `true`, a public triage request that only has an identity registration (unverified) may still create a live session; the session is marked `unverified: true`.
  - When `false`, public triage only records the registration and does not create a session; staff must accept/verify before a session is created.

### Behavioral combinations

- **No identity at triage**
  - `identity_binding_mode = "disabled"`
  - `allow_unverified_entry = false` (ignored)
- **Identity optional**
  - `identity_binding_mode = "optional"`
  - `allow_unverified_entry` controls whether a registration-only public request can create an unverified session (`true`) or just a registration (`false`).
- **Identity or registration required**
  - `identity_binding_mode = "required"`
  - Public and staff triage must either bind an existing ID or submit a registration before starting a visit.
  - `allow_unverified_entry` has the same meaning as above for public triage when the visit is started via registration.

