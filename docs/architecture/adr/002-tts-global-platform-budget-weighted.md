# ADR 002: Platform TTS budget (weighted global pool)

## Status

Accepted

## Context

Site-level `sites.settings.tts_budget` supports per-site caps. Operators running multiple sites on one ElevenLabs subscription sometimes need a **single** FlexiQueue-metered pool split across sites.

## Decision

- Store platform policy in `tts_platform_budgets` (singleton row) and optional `tts_site_budget_weights` (`site_id` → `weight` ≥ 1).
- When **global budgeting** is enabled and `char_limit` > 0:
  - Effective per-site limit = `floor(pool * weight_i / sum(weights))` with remainder units assigned in ascending `site_id` order (`TtsWeightedBudgetAllocator`).
  - Metering rollups use the **platform** period (`daily` | `monthly`) for all sites (`TtsGenerationMeter` + `TtsBudgetGuard`).
- When global budgeting is **off**, enforcement remains **per-site** `tts_budget` only; the super-admin dashboard still shows aggregate usage mix and vendor context.

## Consequences

- Super admins configure policy via `GET`/`PUT /api/admin/tts/platform-budget` and the **TTS Generation** tab on `Admin/Settings`.
- Site admins continue to edit per-site policy on **Admin → Sites → {site}** when global mode is off; when global mode is on, per-site caps are derived from the pool and weights.
