---
name: Phase 4 ElevenLabs governance (hybrid)
overview: Reconciled March 2026 — core metering, rollups, budget guard, admin APIs, and read/edit UX are implemented in-repo. Remaining items are optional product enhancements (charts, per-language routing) or future multi-provider work.
todos:
  - id: metering-rollups
    content: "Meter successful synthesis → site_tts_usage_events + rollups"
    status: completed
  - id: budget-guard
    content: "TtsBudgetGuard before synthesis; block_on_limit when policy enforced"
    status: completed
  - id: site-policy-json
    content: "sites.settings.tts_budget merge on PUT + UpdateSiteRequest validation"
    status: completed
  - id: admin-budget-apis
    content: "GET /api/admin/tts/budget, /sites/{site}/tts-budget, /tts/budgets (superadmin)"
    status: completed
  - id: budget-read-ui
    content: "TtsBudgetCard on Audio & TTS; superadmin cross-site table on Settings"
    status: completed
  - id: budget-edit-ui
    content: "Site show — TTS generation budget form (PUT settings.tts_budget)"
    status: completed
  - id: docs-adr
    content: "docs/architecture/TTS.md + ADR 001 (one active TtsAccount per provider)"
    status: completed
  - id: tts-account-per-provider
    content: "TtsAccountService::setActive scoped by provider + getActiveForProvider"
    status: completed
  - id: optional-charts
    content: "(Optional) Time-series usage charts from rollups/events"
    status: pending
  - id: optional-per-lang-routing
    content: "(Optional) Per-language multi-driver routing — deferred per v1 non-goals"
    status: cancelled
isProject: false
---

# Phase 4 — ElevenLabs governance (hybrid) — reconciled

This checklist was reconciled against the shipped codebase during **TTS roadmap gap closure** (March 2026). Use `docs/architecture/TTS.md` and `docs/architecture/adr/001-tts-account-one-active-per-provider.md` as the source of truth for behavior.

## Shipped (verified)

| Area | Evidence |
|------|----------|
| Metering | `TtsGenerationMeter`, `TtsService` after generation |
| Rollups + guard | `TtsUsageRollupService`, `TtsBudgetGuard` |
| Site policy | `sites.settings.tts_budget`, `TtsBudgetPolicy`, `SiteController@update` merge |
| APIs | `TtsBudgetController` |
| Read UI | `TtsBudgetCard`, `Admin/Settings/Index` Integrations tab |
| Budget edit UI | `Admin/Sites/Show.svelte` |
| Account model | `TtsAccountService::setActive` per provider, `getActiveForProvider`, `getActiveMatchingDriver` |

## Deferred / optional

- **Charts** — not implemented; optional enhancement.
- **Per-language / multi-driver routing** — explicitly out of scope for v1 (`config('tts.driver')` only).
