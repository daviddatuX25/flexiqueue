# Plan: ElevenLabs Dynamic Accounts & Voice Management

**Status**: Draft  
**Last updated**: 2026-03-07  
**Goal**: Allow admins to switch ElevenLabs API accounts on the fly and manage (add, list) voices via the Settings Integrations UI, without editing `.env`.

---

## Current State

- **Config**: `config/tts.php` + `.env` (`ELEVENLABS_API_KEY`, `TTS_DEFAULT_VOICE_ID`, `tts.voices` hardcoded)
- **TtsService**: Reads `config('tts.elevenlabs.api_key')` and `config('tts.voices')` only
- **Settings UI**: Integrations tab shows read-only status (connected/model/voices count); “Coming soon” placeholders for voices and API account
- **Voice source**: Static list in `config/tts.php`; ElevenLabs API `/v1/voices` is not used

---

## Scope

1. **Account switching**: Store multiple ElevenLabs accounts (label, API key, model) in DB; one “active” account used for TTS generation.
2. **Voice management**: Fetch voices from ElevenLabs API per account; optionally merge with config/DB “allowed voices” list.
3. **Fallback**: If no DB accounts, use existing `.env` / config (backward compatible).

---

## Design

### 1. Data Model

#### `tts_accounts` (new table)

| Column        | Type         | Notes                                                |
|---------------|--------------|------------------------------------------------------|
| id            | bigint PK    |                                                      |
| label         | string       | Display name (e.g. "Production", "Free tier")        |
| api_key       | text         | Encrypted (`Crypt::encryptString()`)                 |
| model_id      | string       | Default: `eleven_multilingual_v2`                    |
| is_active     | boolean      | Exactly one must be `true` when any exist            |
| created_at    | timestamp    |                                                      |
| updated_at    | timestamp    |                                                      |

**Constraints**:

- At most one row with `is_active = true`.
- `label` unique (optional; or allow duplicates for multiple “Production” instances).
- Index on `is_active` for fast “active account” lookup.

#### Voices

**Option A – API-only (recommended for Phase 1)**  
- No new table.
- `GET /api/admin/integrations/elevenlabs/voices` calls ElevenLabs `GET /v1/voices` with the active account’s API key.
- UI uses this list for dropdowns; `TokenTtsSetting.default_languages` and token-level voice IDs stay as-is.
- Keeps config `tts.voices` as optional fallback when API fails or no account.

**Option B – Cache voices in DB**  
- `tts_voices` table: `tts_account_id`, `voice_id`, `name`, `labels` (JSON), `synced_at`.
- Admin-triggered sync stores API response; TtsService / UI read from DB.
- More control and offline behavior; adds sync logic.

**Recommendation**: Start with **Option A** (API-only). Add Option B later if you need offline/restricted voice sets.

---

### 2. TtsService Resolution

TtsService must resolve credentials and model in this order:

1. **Active DB account**: `TtsAccount::active()?->api_key`, `model_id`.
2. **Config fallback**: `config('tts.elevenlabs.api_key')`, `config('tts.elevenlabs.model_id')`.

Same for voices:

1. **API fetch** (when active account exists): call `GET /v1/voices` with active account’s key.
2. **Config fallback**: `config('tts.voices')`.

**Refactor**: Introduce `ElevenLabsClient` or extend `TtsService` with `getResolvedApiKey()`, `getResolvedModelId()`, `getVoicesFromApi()` so all TTS generation and voice listing use a single resolution path.

---

### 3. API Endpoints

Auth for all: `auth`, `role:admin`. Base path: `/api/admin/integrations/elevenlabs`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/` | Status (existing). Extend with `accounts`, `active_account_id`, `voices_count`. |
| GET | `/voices` | Fetch voices from active account (ElevenLabs API proxy). |
| GET | `/accounts` | List accounts (no `api_key` in response). |
| POST | `/accounts` | Create account: `label`, `api_key`, `model_id`. Validate key via `/v1/user` or `/v1/voices` before saving. |
| PUT | `/accounts/{id}` | Update account; optionally set `is_active`. |
| POST | `/accounts/{id}/activate` | Set this account as active (deactivate others). |
| DELETE | `/accounts/{id}` | Remove account. If it was active, activate another or fall back to config. |

**Validation**:

- `api_key`: non-empty, validated with a cheap ElevenLabs call (e.g. `GET /v1/user` or `GET /v1/voices` with limit 1).
- `label`: required, max 255.
- `model_id`: optional; default `eleven_multilingual_v2`.

---

### 4. Security

- **Storage**: Encrypt `api_key` with `Crypt::encryptString()` before saving; decrypt in TtsService / API layer only when needed.
- **Responses**: Never return raw `api_key`; at most a masked hint (e.g. `sk_xxx...xxx`).
- **Rate limiting**: Consider throttling POST/PUT/DELETE on accounts (e.g. 10/min) to avoid abuse.
- **Audit**: Optionally log account create/update/delete (who, when).

---

### 5. ElevenLabs API Usage

- **List voices**: `GET https://api.elevenlabs.io/v1/voices`  
  - Header: `xi-api-key: <api_key>`  
  - Response: `{ voices: [{ voice_id, name, labels, ... }] }`

- **Validate key**: `GET https://api.elevenlabs.io/v1/user` or `GET https://api.elevenlabs.io/v1/voices` (limit 1).  
  - 401/403 → invalid key.

---

### 6. UI Changes (Settings > Integrations)

1. **Account list**  
   - Show accounts with label, model, active badge.  
   - Actions: Edit, Activate, Delete.  
   - “Add account” button.

2. **Add/Edit account form**  
   - Fields: Label, API key, Model ID (optional).  
   - Validate API key on submit; show success/error.

3. **Voices**  
   - “Refresh voices” fetches from active account.  
   - List voices (id, name) or use in dropdown for default voice selection.  
   - Link to Tokens page for per-language defaults (existing).

4. **Fallback behavior**  
   - When no DB accounts: show “Using .env configuration” and hide account management.  
   - Optional: “Add first account” CTA to migrate from .env.

---

## Implementation Phases

### Phase 1: DB model + resolution

1. Migration: `tts_accounts` table.
2. Model: `TtsAccount` (encrypted `api_key`, `active()` scope).
3. TtsService: `getResolvedApiKey()`, `getResolvedModelId()`; wire `getPath()` and `generateElevenLabs()` to use them.
4. Feature tests: resolution order (DB active → config).

### Phase 2: Account CRUD API

1. Form requests: `StoreTtsAccountRequest`, `UpdateTtsAccountRequest`.
2. Controller: `ElevenLabsIntegrationController` – index (accounts), store, update, destroy, activate.
3. ElevenLabs API validation helper (validate key).
4. Feature tests: admin CRUD, staff forbidden, encrypted storage.

### Phase 3: Voices API

1. `ElevenLabsIntegrationController::voices()` – proxy to ElevenLabs `/v1/voices` with resolved key.
2. Optional: cache response for 5–15 min to avoid hammering API.
3. Feature tests: voices returned when active account; 401/403 when invalid.

### Phase 4: Settings UI

1. Account list + add/edit/activate/delete.
2. Voices list + refresh.
3. Extend existing Integrations tab; remove “Coming soon” placeholders.

---

## Migration Strategy

- **Existing installs**: Keep `.env` as fallback. No breaking change.
- **New installs**: Can start with DB accounts only (no .env key).
- **Optional migration script**: Read `ELEVENLABS_API_KEY` from env and create first `TtsAccount` row for convenience.

---

## Edge Cases

| Case | Behavior |
|------|----------|
| No accounts, no .env key | TTS disabled; status “not_configured”. |
| Active account deleted | Activate next account; if none, fall back to config. |
| API key invalid (quota/revoked) | TTS generation fails; show error in UI. Optionally mark account “needs review” on repeated failures. |
| Voices API fails | Use config `tts.voices` if present; else show “Unable to load voices”. |
| Two accounts, switch active | Immediate; next TTS request uses new account. |

---

## Open Questions

1. **Voice restrictions**: Do we want an “allowed voices” whitelist per account (stored in DB), or always allow any voice from the account’s API list?
2. **Account limits**: Max number of accounts (e.g. 5) to avoid bloat?
3. **Sync frequency**: For Option B (cached voices), how often to refresh—manual only, or cron?

---

## Files to Touch

| File | Changes |
|------|---------|
| `database/migrations/*_create_tts_accounts_table.php` | New migration |
| `app/Models/TtsAccount.php` | New model |
| `app/Services/TtsService.php` | Resolution logic, optional `ElevenLabsClient` |
| `app/Http/Controllers/Api/Admin/ElevenLabsIntegrationController.php` | Extend: accounts CRUD, voices |
| `app/Http/Requests/StoreTtsAccountRequest.php` | New |
| `app/Http/Requests/UpdateTtsAccountRequest.php` | New |
| `routes/web.php` | New routes under `/api/admin/integrations/elevenlabs` |
| `resources/js/Pages/Admin/Settings/Index.svelte` | Account + voice UI |
| `tests/Feature/Api/Admin/ElevenLabsIntegrationTest.php` | Extended tests |
