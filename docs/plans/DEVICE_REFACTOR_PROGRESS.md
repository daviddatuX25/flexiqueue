# Device refactor (kiosk / QR modularity) — progress

Companion to [DEVICE_REFACTOR_KIOSK_QR_MODULARITY_PLAN.md](./DEVICE_REFACTOR_KIOSK_QR_MODULARITY_PLAN.md).

## Phase 1 (Slices A–B) — closed without Playwright

- Staff footer QR → `qrScanResolve` → **StatusCheckerModal** (in queue) / **StaffTriageBindModal** (not in queue).
- Feature flag `FEATURE_STAFF_TRIAGE_PAGE` / `staff_triage_page_enabled` for legacy `/triage` page.
- **Redirect when triage page disabled:** `GET /triage` (and query string, e.g. `?program=`) redirects to `/station` with the same query so program selection is not lost.
- PHPUnit covers triage redirect behavior (`TriagePageControllerTest`).

**Deferred (explicit):** E2E/Playwright for the staff scan flow (plan §8.1 verification) — optional follow-up.

**Known follow-up:** Pending identity registrations are implemented on `Triage/Index` when the full triage page is enabled. When the triage page is disabled, staff rely on modals + Station; **surfacing the same pending-registration list** on Station or a dedicated route is not done here (plan §5.1).

## Phase 2 (Slice C) — display board scan removed

- `Display/Board.svelte` no longer includes `ScanModal`, HID barcode capture, or “CHECK YOUR STATUS” scan UI. The board remains queue + announcements + TTS + display settings / device unlock.
- Short copy explains that token status checks use **kiosk or staff** (footer QR).
- Backend still passes `display_scan_timeout_seconds` and `enable_display_*` where applicable (status page, program settings, admin); **admin** may still edit legacy display scan toggles until `kiosk_*` migration (Phase 4).

## Phase 3 (Slice D) — in progress

- **`DeviceLock::TYPE_KIOSK`** and canonical URLs `/site/{site}/kiosk` and `/site/{site}/kiosk/{program_slug}`.
- **`ProgramSettings`**: `kiosk_*` getters with **read fallback** from legacy `allow_public_triage` / public triage HID/camera / `display_scan_timeout_seconds` (see `ProgramSettingsTest::test_kiosk_getters_fallback_to_legacy_keys`).
- **Legacy `/public-triage` and `/public/triage/{program}`** respond with **301** to the matching `/kiosk/...` routes; `triageStartRedirect` → `/site/{site}/kiosk`.
- **Device chooser** posts `device_type=kiosk` and label **Self-service kiosk**.
- **EnforceDeviceLock**: triage locks may use **either** legacy `public-triage` path **or** `kiosk` path; new kiosk locks use `kiosk` only.

**Not in this slice:** idle video / YouTube, admin UI for `kiosk_*` keys (still use legacy toggles until Phase 4 admin migration).

## Next (Phase 4 remainder)

Canonical prioritized list: [DEVICE_REFACTOR_KIOSK_QR_MODULARITY_PLAN.md §14](./DEVICE_REFACTOR_KIOSK_QR_MODULARITY_PLAN.md#14-implementation-status--remaining-backlog-2026-03-21).

- **P0:** Admin “Kiosk” subsection + DB migration writing `kiosk_*`; **`PublicStart`** feature matrix (self-service vs status checker vs both off).
- **P1:** Idle attractor (`kiosk_idle_media_*`); pending identity registrations when triage page off; optional `KioskPage` split.
- **P2+:** Playwright staff scan; QR format catalog; station scan product decision; `staff_scan_*` rename.
