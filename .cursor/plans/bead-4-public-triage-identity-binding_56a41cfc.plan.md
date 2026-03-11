---
name: bead-4-public-triage-identity-binding
overview: Add public triage identity binding step on PublicStart using existing scanner infrastructure, with explicit state machine, skip flow, rejection handling, and Playwright coverage.
todos:
  - id: public-binder-state-model
    content: Define and wire a public identity binding state machine in PublicStart.svelte with states idle, scanning, lookup_in_progress, match_found, not_found, skipped, completed.
    status: in_progress
  - id: public-binder-scanner-integration
    content: Reuse the existing public scanner component in an ID binding mode inside PublicStart.svelte, with appropriate copy and routing to /api/clients/lookup-by-id.
    status: pending
  - id: public-binder-rejection-and-timeout
    content: Implement NOT_FOUND rejection UX, skip button, and 45-second auto-reset timer while keeping the token free on all rejection paths.
    status: pending
  - id: public-binder-completion-flow
    content: Integrate binder results (bound vs skipped) with the rest of the public triage flow after completion.
    status: pending
  - id: public-binder-playwright-tests
    content: Add Playwright tests for rejection, skip, match found, auto-reset, and disabled binding mode cases using stable data-testid attributes.
    status: pending
isProject: false
---

# Bead 4 – Public triage identity binding

## Scope

- **In scope**
  - **Public triage binding step** in `[resources/js/Pages/Public/PublicStart.svelte](resources/js/Pages/Public/PublicStart.svelte)` when `identity_binding_mode` is not `disabled`.
  - **Scan-only identity binding** on public triage using the **existing scanner infrastructure** (HID barcode + QR) in a dedicated **ID binding mode**.
  - **State machine implementation** for the public binder:
    - `IDLE` → `SCANNING` → `LOOKUP_IN_PROGRESS` → `MATCH_FOUND` / `NOT_FOUND` → `SKIPPED` → `COMPLETED`.
  - **API integration** to `POST /api/clients/lookup-by-id` (Bead 2) for ID lookup/binding.
  - **Rejection UX** for unknown IDs:
    - Rejection copy exactly as locked.
    - Token remains free in all rejection cases.
    - Screen remains on rejection state until user acts or the **45-second auto-reset timer** expires.
  - **Skip flow in optional mode** with copy exactly as locked:
    - Button label: **“Continue without ID”**.
    - Helper text: **“You can still join the queue without linking your ID card.”**
  - **Auto-reset timer** on `NOT_FOUND` state:
    - 45 seconds of inactivity.
    - Countdown message: **“Resetting to the start in {secondsRemaining} seconds…”**.
  - **Playwright coverage** for public triage binding:
    - Rejection flow.
    - Skip flow.
    - Match found flow.
    - Auto-reset timer behavior.
- **Out of scope**
  - Any **name search**, **manual data entry**, or **client creation** on public triage (scan-only by design).
  - Changes to staff triage binder behavior beyond what is already implemented in Bead 3.
  - New backend endpoints or schema changes; relies on existing Bead 2 `/api/clients/lookup-by-id` contract.

## Files to touch

- **Existing frontend files**
  - `[resources/js/Pages/Public/PublicStart.svelte](resources/js/Pages/Public/PublicStart.svelte)` – add identity binding step, orchestrate state machine, and integrate into public triage flow.
  - `[resources/js/Components/TriageClientBinder.svelte](resources/js/Components/TriageClientBinder.svelte)` – reference patterns and possibly extract/reuse common pieces (scanner wiring, state helpers) without bringing over staff-only complexity.
  - Any **existing scanner component(s)** used by public triage (e.g. in `PublicStart` or shared components) – wire in ID binding mode while preserving token scan mode. Exact file(s) to be confirmed from current implementation, but likely under `resources/js/Components/`.
- **Existing backend files**
  - No new backend endpoints expected; reuse existing Bead 2 `/api/clients/lookup-by-id` implementation and types (controllers/requests/services/models) as-is.
- **New files (if needed)**
  - A small **TS helper module** under `resources/js` (e.g. `[resources/js/lib/publicBindingState.ts](resources/js/lib/publicBindingState.ts)`) *only if* the binder state machine logic would otherwise be too large or duplicated. If not necessary, keep logic inside `PublicStart.svelte`.

## Implementation steps

1. **Trace current public triage flow**
  1. Open `[resources/js/Pages/Public/PublicStart.svelte](resources/js/Pages/Public/PublicStart.svelte)` and identify:
    - Where the public triage flow starts and finishes.
    - Where the existing scanner is invoked for tokens (HID + QR).
    - How `identity_binding_mode` is provided in props and used today (if at all).
  2. Note any existing data structures or enums for identity binding mode from previous beads.
2. **Review staff triage binder patterns (read-only)**
  1. Skim `[resources/js/Components/TriageClientBinder.svelte](resources/js/Components/TriageClientBinder.svelte)` to understand:
    - How the staff state machine is modelled (enums, derived state, timers).
    - How scanner integration is wired (props, events, API calls).
  2. Decide what can be **conceptually reused** (patterns and naming) without importing staff-only complexity directly into public triage.
3. **Define public binding state model in code**
  1. Introduce a **public binding state enum/type** in `PublicStart.svelte` (or a small helper module) that encodes:
    - `IDLE`
    - `SCANNING`
    - `LOOKUP_IN_PROGRESS`
    - `MATCH_FOUND`
    - `NOT_FOUND`
    - `SKIPPED`
    - `COMPLETED`
  2. Document transitions in code comments referencing the locked state machine (high-level, not verbose).
  3. Add any supplementary flags needed, such as:
    - `notFoundResetSecondsRemaining` for the countdown.
    - A simple structure to report outcome to parent flow: `{ status: 'bound' | 'skipped', client: Client | null }`.
4. **Expose identity binding step in PublicStart**
  1. In `PublicStart.svelte`, conditionally insert the **identity binding step** when `identity_binding_mode !== 'disabled'`.
  2. Ensure the overall public triage flow respects this ordering:
    - Token acquisition / base triage steps.
    - Identity binding step (when enabled).
    - Subsequent steps (unchanged) once binder reaches `COMPLETED`.
  3. Define clear interfaces between the binder logic and the rest of the triage flow (e.g. a function called when binding completes with `bound` vs `skipped`).
5. **Wire scanner in ID binding mode**
  1. Identify the **existing scanner component** used on public triage (HID + QR).
  2. Add an **ID binding mode** to that component or its invocation:
    - Accept a `mode` or `context` prop: `'token' | 'identity'` (or similar).
    - In identity mode, scanned values are interpreted as **Client ID card values**, not tokens.
  3. Update the scanner invocation in `PublicStart.svelte` for the identity binding step:
    - Use the same scanner component with mode `'identity'`.
    - Change copy for this step to **“Scan your ID card”** (and equivalent microcopy as needed).
  4. Ensure hardware handling (HID, camera permission) remains centralized and unchanged.
6. **Implement state transitions for scan + lookup**
  1. From `IDLE`:
    - On “Scan ID card” action from the UI, transition to `SCANNING`.
    - On “Continue without ID”, transition to `SKIPPED`.
  2. From `SCANNING`:
    - On successful scan, capture the scanned value and transition to `LOOKUP_IN_PROGRESS`.
    - Optionally support a cancel/back action to return to `IDLE`.
  3. From `LOOKUP_IN_PROGRESS`:
    - Call `POST /api/clients/lookup-by-id` with the scanned ID value, using the Bead 2 contract.
    - On success with client match, transition to `MATCH_FOUND`.
    - On ID not found (or treated-as-not-found error), transition to `NOT_FOUND`.
7. **Handle MATCH_FOUND and binder completion**
  1. In `MATCH_FOUND` state:
    - Briefly show a confirmation (if desired) or directly progress.
    - Mark the binding outcome as `bound` with returned client.
  2. Immediately transition `MATCH_FOUND` → `COMPLETED`:
    - Notify the parent public triage flow that identity binding succeeded.
    - Ensure the flow advances to the next step (no additional user input required on this screen).
  3. Confirm that the **token state remains free until the binding is committed by the backend**, and there is no special marking on client-side for rejects.
8. **Implement NOT_FOUND UX with 45-second auto-reset**
  1. In `NOT_FOUND` state, render rejection screen with locked copy:
    - Title: **“We couldn’t find your details”**.
    - Body: **“This ID card isn’t linked to a record in our system. Please check you’re using the right card, or ask a staff member for help.”**
    - Static helper: **“If you don’t choose an option, this screen will reset automatically.”**
    - Dynamic countdown: **“Resetting to the start in {secondsRemaining} seconds…”**.
  2. Provide actions:
    - Primary: **“Try again”** → reset relevant local binding state and transition `NOT_FOUND` → `SCANNING`.
    - Secondary: **“Continue without ID”** → transition `NOT_FOUND` → `SKIPPED`.
  3. Implement a 45-second inactivity timer:
    - On entering `NOT_FOUND`, set `notFoundResetSecondsRemaining = 45` and start an interval that decrements once per second.
    - On any user action (“Try again” or “Continue without ID”), clear the timer.
    - When the countdown reaches 0 with no action:
      - Clear local binding attempt state.
      - Transition `NOT_FOUND` → `IDLE`.
      - Keep the token free / untouched in all cases.
9. **Implement SKIPPED state and completion**
  1. On **“Continue without ID”** from `IDLE` or `NOT_FOUND`:
    - Transition to `SKIPPED`.
    - Optionally show a brief, non-blocking toast/snackbar: **“Continuing without linking your ID.”**
  2. Immediately transition `SKIPPED` → `COMPLETED`:
    - Report outcome to parent flow as `skipped` with `client = null`.
    - Ensure downstream steps correctly handle “no identity bound” without error.
10. **Integrate binder result with public triage flow**
  1. In `PublicStart.svelte`, ensure that once binder reaches `COMPLETED`:
    - The rest of the public triage flow can rely on a clear, simple structure like:
      - `bindingResult.status` = `'bound' | 'skipped'`.
      - `bindingResult.client` set only when `status === 'bound'`.
    - There is no leak of intermediate states (e.g. `NOT_FOUND`) to later steps.
  2. Confirm that no path creates or searches for clients manually; all identity data comes from:
    - Token context.
    - `/api/clients/lookup-by-id` results.
11. **Accessibility and kiosk robustness pass**
  1. Ensure all new buttons have clear labels and hit areas suitable for kiosk use.
  2. Confirm focus behavior for keyboard/HID flows (focus is on the scanner or main button for easy re-scanning).
  3. Verify that the 45-second reset behaves well even if the kiosk is left idle repeatedly.

## Testing (Playwright)

- **General strategy**
  - Use **stable `data-testid` attributes** on key elements in `PublicStart.svelte` and any nested components:
    - `data-testid="public-id-binding-step"`
    - `data-testid="public-id-scan-button"`
    - `data-testid="public-id-skip-button"`
    - `data-testid="public-id-skip-helper"`
    - `data-testid="public-id-rejection-title"`
    - `data-testid="public-id-rejection-body"`
    - `data-testid="public-id-rejection-try-again"`
    - `data-testid="public-id-rejection-continue-without-id"`
    - `data-testid="public-id-rejection-countdown"`
    - `data-testid="public-id-binding-completed"` (or use an existing marker in the subsequent step showing that binding finished).
  - For scanner input, follow the existing `e2e/triage-binding.spec.ts` patterns to simulate HID/QR input events rather than real camera use.
- **Playwright test cases**
  1. **Rejection flow (unknown ID)**
    - Arrange:
      - Configure the test environment so that `/api/clients/lookup-by-id` returns **not found** for a given fake ID value.
      - Navigate Playwright to the public triage start page so that `identity_binding_mode` is active.
    - Act:
      - Click `[data-testid="public-id-scan-button"]` to enter `SCANNING`.
      - Simulate an ID scan (HID/QR) with the unknown ID.
    - Assert:
      - The rejection screen appears:
        - `[data-testid="public-id-rejection-title"]` has text **“We couldn’t find your details”**.
        - `[data-testid="public-id-rejection-body"]` has text **“This ID card isn’t linked to a record in our system. Please check you’re using the right card, or ask a staff member for help.”**
      - The countdown text is visible via `[data-testid="public-id-rejection-countdown"]` and includes **“Resetting to the start in”**.
      - Verify that the flow remains on this screen until the test either clicks a button or waits for the timer.
  2. **Skip flow (from IDLE or NOT_FOUND)**
    - Scenario A – skip from `IDLE`:
      - Arrange: as above, but do not scan yet.
      - Act: Click `[data-testid="public-id-skip-button"]`.
      - Assert:
        - The helper text `[data-testid="public-id-skip-helper"]` is visible and contains **“You can still join the queue without linking your ID card.”**.
        - The binder reaches `COMPLETED` and the public triage flow advances (assert via `[data-testid="public-id-binding-completed"]` or next step marker).
        - No rejection screen is shown.
    - Scenario B – skip from `NOT_FOUND`:
      - Arrange: go through rejection flow to reach `NOT_FOUND`.
      - Act: Click `[data-testid="public-id-rejection-continue-without-id"]` (the “Continue without ID” button on the rejection screen).
      - Assert:
        - Binder completes with status `skipped` (indirectly via UI: the flow advances to the next step with no bound identity).
        - The rejection screen disappears and auto-reset timer is cleared.
  3. **Match found flow (successful binding)**
    - Arrange:
      - Configure the environment so that `/api/clients/lookup-by-id` returns a **valid client** for a known ID.
      - Navigate to public triage binding step.
    - Act:
      - Click `[data-testid="public-id-scan-button"]`.
      - Simulate scan of the known ID.
    - Assert:
      - No rejection screen appears.
      - Binder reaches `COMPLETED` and the next step of public triage is shown.
      - If there is any UI confirmation that identity is linked (e.g. showing the client’s masked name), assert its presence.
  4. **Auto-reset timer behavior**
    - Arrange:
      - Reach `NOT_FOUND` state with an unknown ID as in the rejection test.
      - Optionally mock or speed up timers if your test harness supports it; otherwise, wait the real 45 seconds.
    - Act:
      - After reaching the rejection screen, **do not click** any buttons.
      - Wait slightly more than 45 seconds (or the mocked duration).
    - Assert:
      - The UI transitions back to the initial binding step:
        - `[data-testid="public-id-binding-step"]` is visible and in its `IDLE` state (e.g. showing the “Scan ID card” and “Continue without ID” controls).
      - The rejection elements (`public-id-rejection-`*) are no longer visible.
      - The token is still usable for a fresh binding attempt (verified indirectly by scanning again and being able to re-run the flow without an error about token state).
  5. **Non-regression for token-only flows** (sanity check)
    - If public triage can still be configured with `identity_binding_mode = disabled`, add or adapt a test to ensure:
      - The identity binding step does **not** appear.
      - The existing public triage token scanning and flow continue to work unchanged.

These steps and tests together will deliver a clear, scan-only public identity binding flow on `PublicStart.svelte`, with explicit states, correct rejection/skip behavior, and solid Playwright coverage around the new behaviors.
