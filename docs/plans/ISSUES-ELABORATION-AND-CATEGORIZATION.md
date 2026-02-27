# Issues Elaboration and Categorization

**Last updated:** 2025-02-24

**Categories:**

- **Bug** — Incorrect or broken behavior; fix has a clear root cause and regression path.
- **Logic refactor** — Change in flow, data, or API; may affect multiple layers.
- **Design issue** — UI/UX, copy, or layout change; no change to core business logic.

---

## Table of contents

1. [Modal close behavior](#1-modal-close-behavior)
2. [Global program settings (save and default)](#2-global-program-settings-save-and-default)
3. [Staff profile dropdown: hide redundant nav items](#3-staff-profile-dropdown-hide-redundant-nav-items)
4. [Custom override single-process: Complete vs Send to next station](#4-custom-override-single-process-complete-vs-send-to-next-station)
5. [Terminology: Send to next process; per-station multi-process](#5-terminology-send-to-next-process-per-station-multi-process)
6. [Admin live program: Open Triage → View Program; first page stations](#6-admin-live-program-open-triage--view-program-first-page-stations)
7. [Profile: nav area stable](#7-profile-nav-area-stable)
8. [Profile: better file-upload UI](#8-profile-better-file-upload-ui)
9. [Profile: avatar upload not persisting](#9-profile-avatar-upload-not-persisting)
10. [Display board: triage bind realtime](#10-display-board-triage-bind-realtime)
11. [Triage: deactivated token message and scan logging](#11-triage-deactivated-token-message-and-scan-logging)
12. [Triage: cancelled token stale state](#12-triage-cancelled-token-stale-state)
13. [Reorder Program Show nav tabs](#13-reorder-program-show-nav-tabs)
14. [Admin: supervisor-override warning in staff/station area](#14-admin-supervisor-override-warning-in-staffstation-area)
15. [Program settings: Strict priority first; ratio labels; info text; station selection details](#15-program-settings-strict-priority-first-ratio-labels-info-text-station-selection-details)
16. [Pre-session checkers before start](#16-pre-session-checkers-before-start)
17. [Deactivate station: process_ids and track warning](#17-deactivate-station-process_ids-and-track-warning)
18. [Staff/station assignment: many staff per station](#18-staffstation-assignment-many-staff-per-station)
19. [Process: edit and delete](#19-process-edit-and-delete)
20. [Time option mm:ss](#20-time-option-mmss)
21. [Track: Steps button label](#21-track-steps-button-label)
22. [Row actions: Print and row action](#22-row-actions-print-and-row-action)
23. [Logging: staff status and more cases](#23-logging-staff-status-and-more-cases)

---

## 1. Modal close behavior

**Category:** Bug

**Summary:** Modals close when the user clicks outside (backdrop) or when the mouse leaves; they should only close via an explicit Close/Cancel button (and optionally Escape).

**Current behavior:**

- **Modal.svelte** (`resources/js/Components/Modal.svelte`): The `<dialog>` has `onclick={(e) => e.target === dialogEl && handleClose()}`. Clicks on the backdrop (the dialog element itself) call `handleClose()`. Escape on the inner card and the ✕ button also close. Inner content uses `onclick={(e) => e.stopPropagation()}` so clicks inside the card do not close.
- **ConfirmModal.svelte**: Wraps Modal; inherits the same click-outside and Escape behavior. Cancel/Confirm buttons call their callbacks.
- **Raw `<dialog>` modals** in `resources/js/Pages/Station/Index.svelte` and `resources/js/Pages/TrackOverrides/Index.svelte` use `<form method="dialog" class="modal-backdrop">` with a button; clicking the backdrop submits the form and closes the dialog.

**Desired behavior:**

- Only explicit Close, Cancel, or Confirm (where applicable) close the modal. Optionally keep Escape for keyboard users.
- Clicking outside the modal (backdrop) or moving the mouse outside must **not** close the modal.

**Exact logic / implementation notes:**

- **Modal.svelte**: Remove the `onclick={(e) => e.target === dialogEl && handleClose()}` from the `<dialog>` element. Keep `onclose={handleClose}`, the ✕ button `onclick={handleClose}`, and optionally `onkeydown={(e) => e.key === 'Escape' && handleClose()}` on the inner card.
- **ConfirmModal.svelte**: No change needed if it only uses Modal; it will inherit the new behavior.
- **Station/Index.svelte** and **TrackOverrides/Index.svelte**: Remove or change the backdrop so that clicking it does not submit the form or close the dialog. Ensure only the explicit Cancel/Close buttons (and optionally Escape) close the dialog. If the backdrop is currently the `<form method="dialog">`, either remove the form’s submit-on-click behavior or use a non-submitting overlay and only close via buttons.

**Related:** None.

---

## 2. Global program settings (save and default)

**Category:** Logic refactor

**Summary:** Provide global program settings that can be saved and used as defaults (e.g. for new programs or when cloning).

**Current behavior:**

- Program-level settings live in `programs.settings` (JSON): e.g. `no_show_timer_seconds`, `require_permission_before_override`, `priority_first`, `balance_mode`, `station_selection_mode`, `alternate_ratio`. They are edited per program on the Program Show Settings tab and persisted via `PUT /api/admin/programs/{program}`. No global “default” store exists; each program has its own settings.

**Desired behavior:**

- A global (or template) settings object that can be saved (e.g. in a config table or a “default program settings” row). When creating a new program (or optionally when editing), the UI can prefill or offer “Apply default settings.” Existing programs are unchanged unless the user explicitly applies defaults.

**Exact logic / implementation notes:**

- **Backend:** Add a store for default program settings (e.g. new table `program_default_settings` with a single row or key-value, or reuse an existing config mechanism). Endpoints: e.g. `GET /api/admin/program-default-settings` and `PUT /api/admin/program-default-settings` (admin only). Validation same as `UpdateProgramRequest` for the `settings` subset.
- **Frontend:** In Admin, add a page or section (e.g. under Settings or Programs) to view/edit and save default program settings. In the Create Program flow (and optionally Edit), add “Use default settings” that copies from the saved defaults into the form.
- **Data:** Reuse the same keys as `programs.settings` so merging is straightforward. No change to existing program settings schema.

**Related:** Issue 15 (program settings labels and copy).

---

## 3. Staff profile dropdown: hide redundant nav items

**Category:** Design issue

**Summary:** In the staff profile icon dropdown, do not show Station, Triage, and Track Overrides because they are already in the main nav.

**Current behavior:**

- **MobileLayout.svelte** (`resources/js/Layouts/MobileLayout.svelte`, lines 50–59): The profile dropdown (trigger: avatar button, lines 46–49) lists: User name, Back (Dashboard/Admin), Profile, “Live Session” label, **Station**, **Triage**, **Track Overrides**, Log out. Station, Triage, and Track Overrides are redundant when the same layout already exposes them in the main navigation.

**Desired behavior:**

- Remove the Station, Triage, and Track Overrides links from the profile dropdown. Keep Profile, Back, Log out, and the user name. “Live Session” section label can be removed if no items remain under it, or kept for future items.

**Exact logic / implementation notes:**

- **File:** `resources/js/Layouts/MobileLayout.svelte`. Remove the list items (or anchors) for Station, Triage, and Track Overrides (and optionally the “Live Session” heading if it becomes empty). Preserve order and styling of remaining items.

**Related:** None.

---

## 4. Custom override single-process: Complete vs Send to next station

**Category:** Bug

**Summary:** When an admin creates a custom path with only one process for a track, the station UI shows “Send to next station” and clicking it hangs; it should show “Complete session” (or equivalent) and complete successfully.

**Current behavior:**

- **Backend:** For sessions with `override_steps` set (custom path), `FlowEngine::calculateNextFromOverrideSteps()` returns `null` when `current_step_order >= count(override_steps)`, so the backend correctly expects completion. `SessionService::complete()` and `getMaxRequiredStepOrder()` treat override steps correctly (e.g. single station → complete allowed).
- **Station queue payload:** `StationQueueService::formatServingSession()` sets `total_steps` to `$track->trackSteps()->count()` (track’s step count), ignoring `override_steps`. So for a single-station custom path, `total_steps` can be e.g. 4 while `current_step_order` is 1.
- **Frontend:** `resources/js/Pages/Station/Index.svelte` uses `isLastStep(s)` = `(s.current_step_order ?? 0) >= (s.total_steps ?? 1)`. With `total_steps = 4` and `current_step_order = 1`, `isLastStep` is false, so the UI shows “Send to next station” and calls `transfer()`. Transfer returns `action_required: 'complete'` and the frontend shows an error like “No next station… Complete the session instead,” and the session does not advance—hence the “hangs” experience.

**Desired behavior:**

- For custom-override sessions, the station UI should show “Complete session” (or equivalent) when the beneficiary is on the last step of the override path, and “Send to next station” otherwise. Clicking “Complete session” should complete the session successfully.

**Exact logic / implementation notes:**

- **Backend:** In `app/Services/StationQueueService.php`, in `formatServingSession()` (or equivalent), when the session has a non-empty `override_steps` array, set `total_steps` to `count($session->override_steps)`. Otherwise keep current logic (e.g. `$track->trackSteps()->count()` or 1). Ensure the same payload is used for both the “last step” button choice and any other step-based UI.
- **Frontend:** No change required if `total_steps` is corrected; `isLastStep(s)` will then be true for the last override step and the correct button will show. Optionally rename the button to “Complete session” or “Complete” for clarity (see Issue 5).
- **Regression:** Verify with a multi-step track (no override) and with a multi-step override path that the correct button appears at each step.

**Related:** Issue 5 (terminology “Send to next process”).

---

## 5. Terminology: Send to next process; per-station multi-process

**Category:** Logic refactor

**Summary:** Change “Send to next station” to “Send to next process” (or a clearer term) and research/design program setting for multiple processes per station (e.g. two processes at one station).

**Current behavior:**

- Station UI and backend use “station” in labels and in flow (e.g. “Send to next station”). Flow is step-based: each step is a process (optionally mapped to stations). Stations can have multiple processes; selection of which station is used for a process is done by `StationSelectionService` (e.g. fixed, shortest queue). There is no explicit “multiple processes on the same station” configuration in program settings; it emerges from track steps and station–process assignments.

**Desired behavior:**

- Replace “Send to next station” with “Send to next process” (or a term that reflects process-based flow) in the Station UI and any user-facing copy. Ensure completion/call/transfer wording is consistent.
- Research and, if needed, add a program setting or documentation for “process segregation” or “multiple processes per station” so admins understand how to configure two (or more) processes at one physical station (e.g. different step orders, same station_id in steps). This may be a UI/docs clarification rather than a new field.

**Exact logic / implementation notes:**

- **Frontend:** In `resources/js/Pages/Station/Index.svelte`, change the button label from “Send to next station” to “Send to next process” (or chosen term). Search the codebase for “next station” in user-facing strings and update consistently.
- **Backend:** If any API messages or logs are user-facing, update wording to “next process” where appropriate. Internal variable names can stay as-is.
- **Program settings:** In the Settings tab (and optionally in docs), add a short explanation or option such as “Multiple processes per station” that describes how to assign several processes to the same station (e.g. in track steps and station process list). If the current model already supports this, add info text only; otherwise consider a boolean or mode that affects display/validation.

**Related:** Issue 4 (single-process override completion), Issue 15 (settings labels).

---

## 6. Admin live program: Open Triage → View Program; first page stations

**Category:** Design issue

**Summary:** When a program is live, rename the “Open Triage” button to “View Program” and make the first view the stations page. On the Programs list, rename “View” to “Manage Program.”

**Current behavior:**

- **Program Show (live banner):** When `program.is_active && !program.is_paused`, a link is shown with label “Open Triage” and `href="/triage"` (`resources/js/Pages/Admin/Programs/Show.svelte`, ~1061–1068).
- **Dashboard (ActiveProgramCard):** Button “View Program” links to `/admin/programs/{id}` (Program Show) (`resources/js/Components/Dashboard/ActiveProgramCard.svelte`, ~26–31).
- **Programs Index:** Button “View” (title “View Dashboard”) links to `/admin/programs/{id}` (`resources/js/Pages/Admin/Programs/Index.svelte`, ~350–356).

**Desired behavior:**

- When viewing a live program from the dashboard or from the Program Show banner, the primary action should be “View Program” and the first page should be the **Stations** view (config flow start → finish). Since stations are a tab on Program Show, “View Program” can link to Program Show with the stations tab active (e.g. `?tab=stations`).
- The button currently labeled “Open Triage” on Program Show (live) should be renamed to “View Program” and link to the same Program Show with stations tab (or a dedicated stations route if one is added). Triage remains accessible via nav or a secondary link.
- On the Programs list, change the “View” button to “Manage Program” (and optionally adjust title to “Manage program”) and keep the same href (`/admin/programs/{id}`).

**Exact logic / implementation notes:**

- **Show.svelte:** In the “Program is Live” banner, change the link label from “Open Triage” to “View Program” and set `href` to `/admin/programs/${program.id}?tab=stations` (or the equivalent that opens the Stations tab). Ensure the Show page reads `tab` from the URL and sets `activeTab` accordingly on load.
- **ActiveProgramCard.svelte:** Change “View Program” link to `href="/admin/programs/{id}?tab=stations"` so the first view is stations. Label can stay “View Program.”
- **Programs Index:** Change button text from “View” to “Manage Program” and title from “View Dashboard” to “Manage program” (or similar).
- **Tab initialization:** In Show.svelte, on mount or when `program` is set, if the URL has `?tab=stations` (or similar), set `activeTab = 'stations'` so the user lands on the stations tab when following “View Program.”

**Related:** Issue 13 (tab order).

---

## 7. Profile: nav area stable

**Category:** Design issue

**Summary:** On the profile page (and optionally admin), keep the navigation area consistent (e.g. same layout as other pages) so the profile does not appear as a horizontal-only layout that changes the nav.

**Current behavior:**

- The Profile page uses **AppShell** only (`resources/js/Pages/Profile/Index.svelte`): a horizontal top bar (avatar, name, role, Profile link, Log out) and main content below. There is no sidebar or bottom nav like AdminLayout or MobileLayout, so the “nav area” looks different from Station/Triage/Admin pages.

**Desired behavior:**

- The navigation area (header/sidebar/bottom bar) should not change when entering the Profile page. Options: (A) Use the same layout as the role’s default (e.g. MobileLayout for staff, AdminLayout for admin) and render Profile as a page within it, or (B) Keep AppShell but make the header structure and nav items consistent with other pages (same items, same style) so the nav “area” does not feel like it switched to a different paradigm.

**Exact logic / implementation notes:**

- **Option A:** Change Profile to use `AdminLayout` for admin and `MobileLayout` for staff (or a shared layout that matches the rest of the app). Profile content stays in the main content area; sidebar/header come from the layout. Ensure Profile route and middleware allow both roles and the correct layout is chosen by role.
- **Option B:** Keep Profile on AppShell but align the header with the same links and visual structure as the other layouts (e.g. same nav items, same position of Profile/Log out). No structural change to which layout is used.
- Document the chosen option and any role-based layout logic.

**Related:** Issue 3 (dropdown items), Issue 8–9 (profile UI).

---

## 8. Profile: better file-upload UI

**Category:** Design issue

**Summary:** Replace or improve the native file input for profile photo upload so it is clearer and better looking (e.g. drag-and-drop, preview, or styled button).

**Current behavior:**

- In `resources/js/Pages/Profile/Index.svelte`, the avatar upload uses a standard `<input type="file" name="avatar" accept="image/jpeg,image/png,image/jpg">`. Styling is minimal; the control does not clearly read as “upload profile photo” and can look inconsistent with the rest of the UI.

**Desired behavior:**

- A clearer, more polished upload area: e.g. a dedicated “Upload photo” or “Change photo” button, optional drag-and-drop zone, and optional preview of the selected file before submit. Keep accessibility (label, focus, keyboard).

**Exact logic / implementation notes:**

- **File:** `resources/js/Pages/Profile/Index.svelte`. Add a wrapper (e.g. card or bordered area) that contains: (1) current avatar preview (if any), (2) a visible button or zone that triggers the file input (hidden or styled), (3) optional drag-and-drop (listen for drag/drop and set the same file on the input). Use the same `submitAvatar()` and endpoint; only the presentation and interaction change. Ensure the actual `<input type="file">` remains for form submission or is used programmatically in `submitAvatar()` (e.g. `FormData` with the chosen file). Follow `docs/architecture/07-UI-UX-SPECS.md` and touch targets (e.g. 48px).

**Related:** Issue 9 (avatar upload bug).

---

## 9. Profile: avatar upload not persisting

**Category:** Bug

**Summary:** After uploading a profile photo, the UI shows “Avatar updated” but the image does not change or persist.

**Current behavior:**

- **Flow:** User selects file → `submitAvatar()` in Profile/Index.svelte sends `POST /api/profile/avatar` with FormData. Backend (`app/Http/Controllers/Api/ProfileController.php`) validates, stores file under `storage/app/public/avatars/`, updates `user.avatar_path`, returns `{ avatar_url, message: 'Avatar updated.' }`. Frontend sets a success message and calls `router.reload()` so Inertia refetches; `auth.user` (with `avatar_url` from `User::$appends` and `getAvatarUrlAttribute()`) should update.
- **Likely cause:** The public storage URL is not reachable. `getAvatarUrlAttribute()` uses `Storage::disk('public')->url('avatars/'.$this->avatar_path)`. If `php artisan storage:link` was never run (or APP_URL is wrong), the URL 404s and the new image never loads; the old one may remain or show broken. The DB and success message are correct; the asset is missing at the URL.

**Desired behavior:**

- After a successful upload, the new avatar is visible (and persists on reload). No “Avatar updated” without a visible change.

**Exact logic / implementation notes:**

- **Backend:** Ensure `storage:link` is documented and run in setup (e.g. `docs/SETUP-BD-001.md`). In `ProfileController::updateAvatar()`, after storing, optionally return the full URL used for the avatar (same as `getAvatarUrlAttribute()`) so the frontend can set it immediately without waiting for reload. Consider validating that the stored file exists and is readable before returning success.
- **Frontend:** On success, besides `router.reload()`, optionally set the displayed avatar URL from the response (if the API returns it) so the UI updates even if reload is delayed or cached. Ensure the img `src` is bound to that URL or to `$page.props.auth.user.avatar_url` after reload.
- **Dev/deploy:** Add a note in setup or deploy docs: run `php artisan storage:link` and ensure `APP_URL` matches the app’s base URL so generated asset URLs are correct.

**Related:** Issue 8 (upload UI).

---

## 10. Display board: triage bind realtime

**Category:** Bug

**Summary:** The display board (`/display`) does not update in real time when a token is bound at triage; it should reflect new bindings immediately.

**Current behavior:**

- **Display board:** `resources/js/Pages/Display/Board.svelte` subscribes to channel `display.activity` and listens for `.station_activity` (event from `StationActivity`). Initial data (now_serving, waiting_by_station, etc.) comes from a single Inertia load; no polling.
- **Events:** `StationActivity` is broadcast only from `SessionService` on **call** and **serve**, not on **bind**. So when a beneficiary is bound at triage, no event is sent to the display channel; the board only updates when they are later called or served.

**Desired behavior:**

- When a token is bound at triage, the display board updates in real time (e.g. “Now serving” or “Waiting” counts/sections update, or a “Recent activity” line appears). No full page refresh required.

**Exact logic / implementation notes:**

- **Backend:** In `app/Services/SessionService.php`, in the `bind()` method (after successful bind and any queue/state updates), dispatch a broadcast to the display so the board can refresh. Options: (1) Broadcast the existing `StationActivity` event with a message like “{alias} registered at triage” and ensure the display subscribes to it; or (2) introduce a separate event (e.g. `TriageBind` or `DisplayBoardUpdate`) on the same `display.activity` channel with payload that includes updated counts or a minimal activity entry. The display board should then update its state (e.g. activity feed and/or now_serving/waiting) from the event.
- **Frontend:** In `resources/js/Pages/Display/Board.svelte`, ensure the handler for the new (or existing) event updates the relevant state (activity list, totals, or triggers a refetch of board data if the backend exposes a lightweight endpoint). If the backend sends full enough data in the event, update locally; otherwise call an API to refresh board data.
- **Consistency:** Ensure “Recent activity” and “Now serving” / “Waiting” stay in sync with bind, call, and serve.

**Related:** None.

---

## 11. Triage: deactivated token message and scan logging

**Category:** Bug + Logic refactor

**Summary:** When scanning a deactivated token, show “Token deactivated” (or similar) instead of “Token not found.” Log scan attempts and flag fabricated/not-found scans in logs.

**Current behavior:**

- **Manual lookup:** When the token is found but not available, the frontend shows “Token is already in use” for `in_use`, or “Token is marked as {status}” for others (e.g. deactivated). So manual lookup already distinguishes deactivated.
- **QR scan (handleQrScan with qr_hash):** The API returns 200 with token data including `status: 'deactivated'`. The frontend only branches on `ok && available` and `status === 'in_use'`; all other cases (including deactivated) fall through to `error = 'Token not found.'`. So deactivated tokens show “Token not found” on scan.
- **Logging:** `SessionController::tokenLookup()` does not write to any app log or table. Only successful bind creates a `TransactionLog`. Scan attempts (success or fail, not-found vs deactivated) are not recorded.

**Desired behavior:**

- For a scanned deactivated token, show a clear message such as “Token deactivated” (not “Token not found”).
- Log token lookup/scan attempts (e.g. success, not found, deactivated, in_use) and flag “not found” scans (e.g. in remarks or a dedicated field) so they can be reviewed as potentially fabricated or invalid.

**Exact logic / implementation notes:**

- **Frontend:** In `resources/js/Pages/Triage/Index.svelte`, in the QR scan branch where the API returns 200, add a condition for `t?.status === 'deactivated'` and set `error = 'Token deactivated.'` (or similar). Remove deactivated from the generic “Token not found” fallback.
- **Backend:** In `SessionController::tokenLookup()` (or a dedicated service), after resolving the token (or determining not found), write a log entry. Options: (1) New table `triage_scan_log` with columns such as `physical_id`/`qr_hash`, `result` (found/not_found/deactivated/in_use), `token_id` (nullable), `user_id`, `ip`, `created_at`; or (2) use a generic “audit” or “activity” log with a type like `triage_scan` and a `result` or `remarks` field. For “not found” results, set a flag (e.g. `fabricated_or_invalid: true` or `result: 'not_found'`) so reports can filter them.
- **API:** Optionally return a distinct code or `reason` for deactivated (e.g. `status: 'deactivated'`) so the frontend can show the right message without string matching.

**Related:** Issue 12 (stale state), Issue 23 (logging).

---

## 12. Triage: cancelled token stale state

**Category:** Bug

**Summary:** If a token was in use and then the session is cancelled (token becomes available again), the triage page can still show “Token is already in use” until the user refreshes or scans again.

**Current behavior:**

- Triage holds local state only (`scannedToken`, `error`, `scanHandled`, etc.); it does not subscribe to Echo or any push. When a lookup returns `in_use`, `error` is set to “Token is already in use.” If the session is later cancelled elsewhere, the token becomes `available`, but triage has no way to know; the same `error` remains until the user scans again, does another lookup, or refreshes.

**Desired behavior:**

- After a token is freed (e.g. session cancelled), triage should either (1) clear or update the error automatically when that token is freed, or (2) make it easy to “try again” (e.g. prominent “Scan again” or “Look up again”) so the user does not need to refresh the page. Ideally, if the user has the triage page open and the token is cancelled, the message updates (e.g. via realtime) or the next scan/lookup shows the correct state.

**Exact logic / implementation notes:**

- **Option A (realtime):** Subscribe triage to a channel or event that is broadcast when a session is cancelled (or when a token’s status changes to available). When the event matches the currently displayed token (e.g. same `physical_id` or `token_id`), clear `error` and optionally set a short message like “Token is now available. You can scan again.” Requires backend to broadcast token/session lifecycle events and frontend to subscribe and match.
- **Option B (UI only):** Add a clear “Scan again” or “Look up again” button that resets `error` and `scanHandled` (and optionally clears `scannedToken`) so the user can immediately scan or type again without refreshing. Document that the page does not auto-update when the token is freed elsewhere.
- Prefer Option B as a minimal fix; Option A can be a follow-up if realtime is desired.

**Related:** Issue 11 (triage messages), Issue 10 (realtime).

---

## 13. Reorder Program Show nav tabs

**Category:** Design issue

**Summary:** Reorder the Program Show tabs so they follow the configuration flow from start to finish: Overview → Processes → Stations → Staff → Track → Settings.

**Current behavior:**

- In `resources/js/Pages/Admin/Programs/Show.svelte`, the tab order (lines ~1147–1211) is: **Overview**, **Tracks**, **Processes**, **Stations**, **Staff**, **Settings**. Content blocks (lines ~1219–1439) follow the same order.

**Desired behavior:**

- Order: **Overview** → **Processes** → **Stations** → **Staff** → **Track** (singular) → **Settings**. This matches a logical flow: overview first, then processes, then stations that run them, then staff assignment, then track configuration, then settings.

**Exact logic / implementation notes:**

- **File:** `resources/js/Pages/Admin/Programs/Show.svelte`. Reorder the tab buttons in the tablist to: Overview, Processes, Stations, Staff, Tracks, Settings. Reorder the corresponding `{#if activeTab === 'overview'}`, `{:else if activeTab === 'processes'}`, … blocks to match. Ensure `activeTab` values and URL hash/query (if used) stay consistent. Optionally rename “Tracks” to “Track” in the label if the product uses singular there.

**Related:** Issue 6 (View Program → stations first), Issue 16 (pre-session checkers).

---

## 14. Admin: supervisor-override warning in staff/station area

**Category:** Design issue

**Summary:** When “Require supervisor PIN” (or similar) is enabled in program settings, show a warning in the staff/station assignment area if there are no supervisors assigned, and point the user to the setting to disable it if desired.

**Current behavior:**

- **Settings tab:** “Require Override PIN” / “Require supervisor PIN” is in the Settings tab (~1599–1622 in Show.svelte), stored as `require_permission_before_override` in program settings.
- **Staff tab:** “Station assignments” and “Supervisors” sections (~1767–1965). There is no warning when the override requirement is on but no supervisors exist.

**Desired behavior:**

- When `settingsRequireOverride` (or equivalent) is true and there are no supervisors (e.g. `staffSupervisors.length === 0`), show a warning in the Staff tab (e.g. at the top of the tab or immediately after the “Station assignments” heading): e.g. “Override requires a supervisor PIN but no supervisors are assigned. Assign supervisors below or disable this in Settings.” Make the warning dismissible or static; ensure it’s visible whenever the condition holds.

**Exact logic / implementation notes:**

- **File:** `resources/js/Pages/Admin/Programs/Show.svelte`. In the Staff tab content, add a conditional block: if `program.settings?.require_permission_before_override` (or the local state that mirrors it) and the list of supervisors for the program is empty, render an alert or message with the text above. Optionally add a link to the Settings tab (e.g. `onclick={() => activeTab = 'settings'}`). Use existing Skeleton alert/message styles.

**Related:** Issue 15 (settings copy), Issue 17 (station deactivate warning).

---

## 15. Program settings: Strict priority first; ratio labels; info text; station selection details

**Category:** Design issue

**Summary:** Rename “Priority first” to “Strict priority first” (including in the stations supervisor/override UI); add clear labels for priority vs regular in ratio inputs; add short info text for scenarios; add “more details” for station selection mode.

**Current behavior:**

- **Priority first:** Settings tab has heading “Priority First” and checkbox “Enable priority first routing” (~1498–1523). Stations Edit modal has a “Priority first override” dropdown (~2451–2477): “Use program default” | “Yes (priority lane first)” | “No (FIFO/alternate).”
- **Balance mode / ratio:** Balance mode select and, when alternate, ratio inputs (~1525–1592). Single label “Ratio Priority:Regular”; two number inputs without per-field labels.
- **Station selection:** Heading and description and select for station selection mode (~1600–1635). No expandable or inline “more details” about each mode.

**Desired behavior:**

- Use “Strict priority first” in headings and labels (Settings and Stations modal). In the Stations modal, include the term “strict priority first” in the checkbox or dropdown label where applicable.
- For the ratio inputs, add explicit labels: one for “Priority” and one for “Regular” (and keep “Ratio Priority:Regular” as section label if desired). Add a short info sentence under the ratio or under “Strict priority first” / “Balance mode” explaining when to use each (e.g. “Use strict priority when priority queue should always be served before regular.”).
- For station selection, add an optional “More details” control (e.g. a link or expandable) that shows a brief description of each selection mode (Fixed, Shortest Queue, Least Busy, Round Robin, Least Recently Served).

**Exact logic / implementation notes:**

- **Show.svelte Settings tab:** Replace “Priority First” with “Strict priority first” and “Enable priority first routing” with “Enable strict priority first routing.” Add a small info text block (e.g. `<p class="text-sm text-surface-500">…</p>`) under the checkbox. For ratio inputs, add `<label>` or visible text “Priority” and “Regular” next to the respective inputs; add one line of info text for the alternate-ratio scenario. For station selection, add a “More details” link or collapse that lists each mode with one sentence.
- **Show.svelte Stations tab (Edit Station modal):** In the “Priority first override” dropdown or label, include “strict priority first” (e.g. “Yes (strict priority first)”). Improve the UI of that control if needed (e.g. clearer options, spacing).
- **Backend:** No change to keys; only copy and UI. Ensure `priority_first` and related keys remain the same in API and Program model.

**Related:** Issue 2 (default settings), Issue 14 (supervisor warning).

---

## 16. Pre-session checkers before start

**Category:** Logic refactor

**Summary:** Before allowing a program session to start, check that the program has at least one station, at least one process with stations, at least one staff assigned to a station, and at least one track created; otherwise block or warn.

**Current behavior:**

- **ProgramService::activate()** (and the API that calls it) does not validate program configuration. The admin can click “Start session” (or equivalent) even when there are no stations, no processes, no staff assignments, or no tracks. The session starts and the queue/triage may be unusable or confusing.

**Desired behavior:**

- Before starting the session (or when the user clicks “Start session”), run checks: (1) program has at least one station; (2) at least one process exists and is assigned to at least one station; (3) at least one staff is assigned to a station (for this program); (4) at least one track exists. If any check fails, either block activation and show a clear message (e.g. “Add at least one station before starting.”) or show a warning modal listing missing items and allow “Start anyway” or “Cancel.”

**Exact logic / implementation notes:**

- **Backend:** In `ProgramService::activate()` (or in the controller before calling it), run the four checks using the program’s relations (stations, processes via stations, program_station_assignments or staff list, serviceTracks). Return a structured response (e.g. `{ ok: false, errors: ['no_stations', 'no_processes_with_stations', ...] }` or a single message). Alternatively, add a separate endpoint `GET /api/admin/programs/{id}/can-activate` that returns `{ can_activate: bool, missing: [...] }` and call it from the frontend before submitting activate.
- **Frontend:** When the user clicks “Start session” (Program Show or Programs Index), first call the checker (or submit activate and handle validation response). If checks fail, show an alert or modal listing what’s missing (e.g. “Stations: none configured. Processes: none with stations. …”) and either prevent activation or offer “Start anyway” that calls activate without re-checking. Use the same copy in both places (Show and Index) if both have an activate button.

**Related:** Issue 13 (tab order), Issue 17 (station deactivate).

---

## 17. Deactivate station: process_ids and track warning

**Category:** Bug + Design issue

**Summary:** Fix the “The process ids field is required” error when deactivating a station. When deactivating, show a warning if the station’s process(es) are part of a track so the admin is aware the track may not complete.

**Current behavior:**

- **Deactivate flow:** In Program Show Stations tab, toggling a station inactive calls `handleToggleStationActive()`, which sends `PUT /api/admin/stations/${s.id}` with only `name`, `capacity`, `client_capacity`, and `is_active`. It does not send `process_ids`.
- **Validation:** `UpdateStationRequest` requires `process_ids` (array, min:1). So the request fails with “The process ids field is required.”
- **Track impact:** There is no check or warning that the station’s processes are used in track steps; deactivating could leave tracks with no way to complete a step.

**Desired behavior:**

- Toggling a station to inactive should succeed without sending `process_ids` if the intent is only to deactivate. Either (1) send the current `process_ids` when toggling (so validation passes), or (2) make `process_ids` optional on update when only `is_active` is changing (backend rule change). Prefer (1) for minimal API change.
- When the user attempts to deactivate a station whose process(es) are used in any track step, show a warning (e.g. “This station’s processes are used in track steps. Deactivating may prevent some tracks from completing. Continue?”) and allow them to proceed or cancel.

**Exact logic / implementation notes:**

- **Frontend:** In `handleToggleStationActive()` in `resources/js/Pages/Admin/Programs/Show.svelte`, include the station’s current `process_ids` (from the list payload, e.g. `s.process_ids` or `s.processes?.map(p => p.id)`) in the PUT body so the request passes validation. If the payload does not include process_ids, ensure the Stations tab data loads them (e.g. from ProgramPageController or station list API).
- **Backend warning:** In `StationController::update()` (or a service), when `is_active` is being set to false, query whether any `TrackStep` (for the program’s tracks) has a `process_id` in the station’s process list. If yes, include a warning in the JSON response (e.g. `warning: 'This station\'s processes are used in track steps. Deactivating may prevent tracks from completing.'`) and still allow the update. Frontend can show the warning in a toast or inline message.
- **Optional:** Before deactivate, call an endpoint or include in station payload a flag `used_in_tracks: true/false`. If true, show the warning modal before sending the PUT.

**Related:** Issue 16 (pre-session checkers), Issue 19 (process edit/delete).

---

## 18. Staff/station assignment: many staff per station

**Category:** Logic refactor / Design issue

**Summary:** Ensure the staff/station assignment logic and UI clearly support assigning multiple staff to the same station.

**Current behavior:**

- **Data model:** `program_station_assignments` has (program_id, user_id, station_id) with unique on (program_id, user_id). So each user has one station per program, but multiple users can have the same station_id. The model already supports “many staff per station.”
- **UI:** Program Show Staff tab lists staff and their station assignment. The assignment flow (e.g. dropdown or modal) may be presented as “assign this staff to a station” without making it obvious that the same station can be selected for many staff. Display of “assigned staff” per station may be a list; ensure it shows multiple names when multiple staff are assigned.

**Desired behavior:**

- The UI should clearly allow selecting the same station for multiple staff. When viewing stations (e.g. in Staff tab or Stations tab), show the list of assigned staff per station (multiple names when applicable). No artificial “one staff per station” limit.

**Exact logic / implementation notes:**

- **Backend:** Confirm that `ProgramStaffController` (or equivalent) does not enforce a single staff per station. If any validation or sync removes previous assignees when assigning a new one, change it so that multiple assignments to the same station are allowed. Ensure list endpoints return `assigned_staff` (or equivalent) as an array for each station.
- **Frontend:** In the Staff tab, when assigning a staff to a station, the dropdown (or list) of stations should allow the same station to be chosen for different users. The “Station assignments” table (or similar) should show one row per staff with their station name, and stations should be able to appear in multiple rows. If there is a “Stations” view that shows “Assigned staff” per station, ensure it displays all assigned users (e.g. comma-separated or list). No code change if the backend and UI already support this; otherwise add the above.

**Related:** Issue 14 (supervisor warning), Issue 16 (staff assigned check).

---

## 19. Process: edit and delete

**Category:** Logic refactor

**Summary:** Add the ability to edit and delete a process in the admin UI.

**Current behavior:**

- **Processes tab** in Program Show (`resources/js/Pages/Admin/Programs/Show.svelte`, ~1690–1764): Only “Add Process” (create) and a list of processes as chips (name + optional description). No edit or delete actions. Processes are referenced when creating/editing stations (process checkboxes) and when adding track steps (process dropdown).

**Desired behavior:**

- Each process in the list should have an Edit and a Delete (or similar) action. Edit opens a form or inline edit for name and description. Delete removes the process after confirmation; if the process is in use (e.g. assigned to a station or used in a track step), show a warning or block deletion and explain.

**Exact logic / implementation notes:**

- **Backend:** Add or use endpoints: e.g. `PUT /api/admin/programs/{program}/processes/{process}` (or `PATCH`) and `DELETE /api/admin/programs/{program}/processes/{process}`. Update: validate name (and description); save. Delete: check if the process is assigned to any station (`station_process` or equivalent) or used in any TrackStep; if yes, return 422 with a message (e.g. “Process is in use by stations or track steps. Remove it from them first.”). If no, delete the process.
- **Frontend:** In the Processes tab, add an Edit button/icon and a Delete button per process. Edit can open a small modal or inline form with name and description, then call the update endpoint and refresh. Delete opens a confirm modal; on confirm, call the delete endpoint and refresh. Show backend error if delete is rejected.

**Related:** Issue 17 (station/process), Issue 16 (processes with stations).

---

## 20. Time option mm:ss

**Category:** Design issue

**Summary:** Allow the no-show timer (and any other time settings) to be entered or displayed as minutes:seconds (mm:ss) in addition to or instead of a single “seconds” number.

**Current behavior:**

- **No-show timer:** In Program Show Settings (~1476–1488), a single number input (seconds, min 5, max 120) with helper text “seconds (default: 10).” Backend stores `no_show_timer_seconds` as an integer; validation is integer 5–120.

**Desired behavior:**

- User can enter or see the timer as mm:ss (e.g. 1:30 for 90 seconds). Backend continues to store total seconds; extend max if needed (e.g. allow up to 600 seconds or 10 minutes) when mm:ss is used.

**Exact logic / implementation notes:**

- **Frontend:** In the no-show timer block, replace (or add alongside) the single number input with either: (1) two inputs (minutes and seconds) that combine to total seconds for submit, or (2) one text input that accepts “m:ss” or “mm:ss” and parses to seconds. Display existing value as mm:ss when loading (e.g. 90 → “1:30”). Validate total seconds in range (e.g. 5–600) before submit.
- **Backend:** Keep `no_show_timer_seconds` and validation; extend `max` in `UpdateProgramRequest` if needed (e.g. to 600). No new column.

**Related:** Issue 2 (default settings), Issue 15 (settings UI).

---

## 21. Track: Steps button label

**Category:** Design issue

**Summary:** Rename the “Steps” button on each track to “Create steps” when the track has no steps, and “Manage steps” when it already has steps.

**Current behavior:**

- In Program Show Tracks section (~1413–1419), each track card has a button with label “Steps” (and GitMerge icon). The label is the same whether the track has zero steps or existing steps.

**Desired behavior:**

- When the track has no steps: show “Create steps.” When the track has at least one step: show “Manage steps.”

**Exact logic / implementation notes:**

- **File:** `resources/js/Pages/Admin/Programs/Show.svelte`. Where the Steps button is rendered, use a conditional: if `track.track_steps?.length === 0` (or equivalent), label “Create steps”; else “Manage steps.” Ensure the track payload from the backend includes step count or `track_steps` so the condition is accurate.

**Related:** Issue 13 (tab order), Issue 19 (process edit/delete).

---

## 22. Row actions: Print and row action

**Category:** Design issue

**Summary:** Ensure Print is available when rows (tokens) are selected, and add or clarify “row action” (e.g. per-row actions or an additional bulk action).

**Current behavior:**

- **Admin Tokens Index** (`resources/js/Pages/Admin/Tokens/Index.svelte`): When one or more tokens are selected (`selectedIds`), a toolbar appears with: Print, Deactivate, Delete, Clear. Print is already present. There is no per-row action dropdown or icon that appears on each row (e.g. “Print this token,” “Deactivate this token”); actions are bulk-only in the toolbar.

**Desired behavior:**

- Print remains available in the selection toolbar (already the case). Add “row action” in one of two ways: (1) **Per-row actions:** each token row has an actions menu or icon that offers Print, Deactivate, Delete (and possibly View) for that single token; or (2) **Additional bulk action:** add another bulk action in the toolbar (e.g. “Export” or a custom action) alongside Print. Clarify with product which is intended; the plan assumes (1) per-row actions for consistency with “row action” wording.

**Exact logic / implementation notes:**

- **Per-row actions (if chosen):** In the token table, add a column or overflow menu per row with: Print (navigate to print view for that token or open print modal with single id), Deactivate (if available), Delete (if allowed). Use the same endpoints as bulk (e.g. single-token print URL or PATCH for status). Ensure disabled states when token is in_use (e.g. no delete, or show “In use”).
- **Bulk toolbar:** Keep Print, Deactivate, Delete, Clear as-is. If “row action” means a second bulk action, add the new button and handler.
- **Accessibility:** Ensure action buttons have labels and keyboard access.

**Related:** None.

---

## 23. Logging: staff status and more cases

**Category:** Logic refactor

**Summary:** Record staff status (availability) updates and other audit-worthy events in logs so they can be reviewed and reported.

**Current behavior:**

- **TransactionLog:** Used for session/queue actions (bind, call, check_in, transfer, override, complete, cancel, no_show, reorder, force_complete, identity_mismatch). Requires session/station context.
- **UserAvailabilityController:** Updates `availability_status` and `availability_updated_at` on the authenticated user. No log or audit record is written.
- **Other:** Program session start/stop in `program_audit_log`; no dedicated staff-lifecycle log.

**Desired behavior:**

- Log staff availability status changes (e.g. available, on_break, away, offline) with user id, previous value, new value, timestamp. Optionally log other staff-related events (e.g. login, role change, assignment change) in the same or a related log. Logs should be queryable for reports and support.

**Exact logic / implementation notes:**

- **Option A (new table):** Create `staff_activity_log` (or `user_activity_log`) with columns such as `user_id`, `action_type` (e.g. `availability_change`, `login`, `assignment_change`), `old_value`, `new_value`, `metadata` (JSON), `created_at`. In `UserAvailabilityController`, after updating the user, insert a row with action_type `availability_change` and old/new status. Optionally add similar inserts in login listener or assignment controller.
- **Option B (extend program_audit_log):** If program_audit_log is program-scoped only, it may not fit availability (which is global). Prefer a dedicated table for staff/user actions.
- **Frontend:** No change required for logging; backend-only.
- **Reports:** If an admin “Activity” or “Audit” report exists, add a filter or section for staff activity using the new log.

**Related:** Issue 11 (scan attempt logging).

---

## Implementation order (suggested)

- **Phase 1 – Bugs (fix first):** 1 (modal close), 4 (single-process complete), 9 (avatar upload), 10 (display realtime), 11 (deactivated message + scan log), 12 (triage stale state), 17 (deactivate station process_ids + warning).
- **Phase 2 – Logic refactor:** 2 (global defaults), 5 (terminology + process setting), 16 (pre-session checkers), 18 (many staff per station), 19 (process edit/delete), 23 (logging).
- **Phase 3 – Design and UX:** 3 (dropdown), 6 (View Program / Manage Program), 7 (profile nav), 8 (file upload UI), 13 (tab order), 14 (supervisor warning), 15 (settings labels and details), 20 (mm:ss), 21 (Steps button), 22 (row actions).

Dependencies: 4 and 5 are related (wording and completion logic). 6 and 13 go well together (tab order and first-view). 14 and 15 are both settings/staff UX. 16 and 17 affect program and station configuration.
