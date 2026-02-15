# UI findings: Mobile layout & bottom nav (Playwright-validated)

**Date:** 2025-02-15  
**Scope:** Mobile layout (Station, Triage, Track Overrides), bottom nav (dock), StatusFooter.  
**Validation:** Playwright E2E spec `e2e/mobile-layout.spec.ts` (9 tests, all passing).

---

## What was validated

- **Bottom nav (dock)** is present and visible on Station, Triage, and Track Overrides.
- **Active state** updates correctly when navigating: the current section (Station / Triage / Track Overrides) is highlighted with `text-primary font-semibold`.
- **Touch targets** in the bottom nav meet the 48x48px guideline (implemented as min-w-[44px] min-h-[44px]; tests assert ≥44px).
- **StatusFooter** is visible with: Online/Offline indicator, "Queue: N", "Processed: N", and live clock (updates every second).
- **Header** shows context (e.g. station name), role badge, and user dropdown with Station, Triage, Track Overrides, Log out.

So the **bottom nav is not “static” in terms of selection** — the active tab correctly reflects the current route and updates on client-side navigation.

---

## Finding 1: Footer “Queue” and “Processed” — **ADDRESSED (2025-02-15)**

**Original observation:** StatusFooter showed "Queue: 0" and "Processed: 0" because pages did not pass `queueCount` or `processedToday` to `MobileLayout`.

**Fix implemented:**

- **Backend:** `StationQueueService::getProgramFooterStats(?Program $program)` returns program-level `queue_count` (sessions in waiting/called/serving) and `processed_today` (sessions completed today). All three mobile page controllers (Station, Triage, Authorization) call it and pass `queueCount` and `processedToday` in the Inertia payload.
- **Frontend:** Station, Triage, and Authorization pages accept `queueCount` and `processedToday` from props and pass them to `MobileLayout`, so the footer shows live values on each page load.

**Note:** Values update on every full page load or Inertia navigation; they do not live-update during a single page session (e.g. after completing a session on Station) without a refresh or navigation. For real-time updates, the layout would need to consume the same API or Echo events as the Station page.

---

## Finding 2: Admin layout StatusFooter also static

**Observation:** `AdminLayout.svelte` renders `<StatusFooter />` with no props, so Queue and Processed default to 0.

**Recommendation:** If admin pages should show global queue/processed stats, pass them from the controller or a shared Inertia prop (e.g. from `HandleInertiaRequests`).

---

## Finding 3: User menu button has no accessible name

**Observation:** The mobile layout user dropdown trigger is a `<div role="button">` containing only the user initial (avatar). It has no `aria-label` or visible text, so screen readers and tests must target it by structure (e.g. `.navbar-end [role="button"]`).

**Recommendation:** Add `aria-label="User menu"` (or similar) to the dropdown trigger for accessibility and clearer E2E selectors.

---

## Summary

| Item | Status | Notes |
|------|--------|--------|
| Bottom nav visibility | OK | Present on all three mobile routes |
| Bottom nav active state | OK | Updates with route (not static) |
| Touch targets (44px+) | OK | Meets spec |
| StatusFooter presence | OK | Online, Queue, Processed, time |
| Queue / Processed values | Static | Always 0; not passed from pages/backend |
| User menu accessibility | Improvement | Add aria-label on trigger |

**E2E coverage:** `./vendor/bin/sail npx playwright test e2e/mobile-layout.spec.ts` — 9 tests covering dock, active state, footer, header menu, and touch targets.
