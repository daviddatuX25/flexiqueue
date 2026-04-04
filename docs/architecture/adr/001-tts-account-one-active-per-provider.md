# ADR 001: One active `TtsAccount` per provider (not globally)

## Status

Accepted — implemented (March 2026).

## Context

`tts_accounts` stores provider credentials (ElevenLabs today; future engines add rows with matching `provider`). Historically, `TtsAccountService::setActive()` deactivated **all** other rows, enforcing **at most one active account globally**.

That blocks a valid deployment model: **multiple providers configured in parallel** (e.g. ElevenLabs + another engine) with **one active credential per provider**, while `config('tts.driver')` selects which engine runs at runtime.

## Decision

- **Activation rule:** When setting an account active, deactivate only other accounts with the **same `provider`** value as the account being activated.
- **Credentials resolution:** `TtsAccount::getActiveMatchingDriver()` (and related helpers) resolve the active account **for the configured `tts.driver`**, not “first active row anywhere.”
- **Provider-specific UIs:** e.g. ElevenLabs integration status uses the active account for `provider = elevenlabs`, independent of which driver is currently selected (so operators can see which ElevenLabs key is “live” for that provider).

## Consequences

- **Positive:** Multiple providers can each have one active account; switching `tts.driver` picks the matching row without deactivating others.
- **Migration:** Existing rows already have `provider` defaulting to `elevenlabs`; no data migration required for a single-provider fleet.
- **UI:** “Active” badges are per-account list; global “only one active in the whole table” is no longer true.

## Non-goals (unchanged)

- Per-language or multi-driver routing within one request (still **one global `tts.driver`** for v1 — see `docs/architecture/TTS.md`).

## References

- `App\Services\TtsAccountService`
- `App\Models\TtsAccount`
- `docs/architecture/TTS.md` — Multi-provider engine and budgeting
