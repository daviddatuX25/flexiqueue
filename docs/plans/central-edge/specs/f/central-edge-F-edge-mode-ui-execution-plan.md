# Phase F — Edge Mode UI — Execution Plan

**Reference:** [central-edge-tasks.md](../../central-edge-tasks.md) (F.4 Edge mode UI), [central-edge-v2-final.md](../central-edge-v2-final.md) (Phase F §UI specification)  
**Goal:** Implement all Edge Mode UI deliverables so staff see accurate connectivity/sync state, triage messaging, admin read-only behavior, and offline client creation. No controller checks `APP_MODE` directly; all gates go through `EdgeModeService`.

**Status:** Draft — ready for implementation after F.1–F.3 (columns, EdgeModeService, Pi env config) are done.

---

## Prerequisites (from Phase F tasks)

- **F.1** — `queue_sessions.source`, `queue_sessions.binding_status` columns exist.
- **F.2** — `EdgeModeService` implemented with all feature gate methods.
- **F.3** — Pi env: `APP_MODE=edge`, `CENTRAL_URL`, `CENTRAL_API_KEY`, `SITE_ID`; config validation on boot.

---

## Shared data (Inertia)

**HandleInertiaRequests** (or a dedicated edge middleware) must share the following when `APP_MODE=edge` (or when `EdgeModeService::isEdgeMode()` is true):

| Prop | Type | Description |
|------|------|-------------|
| `edgeMode` | boolean | Whether the app is running in edge mode (Pi). |
| `edgeBanner` | object or null | Only when edgeMode is true. Keys: mode ('bridge' or 'offline'), lastSyncAt (ISO string or null), pendingCount (int), siteId (string). |
| `edgeSyncUrl` | string or null | When edge mode: URL for POST "Sync Now". Omit or null when not edge. |
| `canShowIdBindingPage` | boolean | From EdgeModeService::canShowIdBindingPage(). |
| `canEditPrograms` | boolean | From EdgeModeService::canEditPrograms(). |
| `canEditUsers` | boolean | From EdgeModeService::canEditUsers(). |
| `canEditTokens` | boolean | From EdgeModeService::canEditTokens(). |

When not in edge mode, `edgeMode` is false and `edgeBanner` can be null; layout and pages must not render edge-specific UI.

---

## Delegateable tasks

### F.4.1 — Edge banner (online/offline state)

**Scope:** One component shown at top of every page in edge mode; reflects bridge vs offline and sync info.

**Reference:** central-edge-v2-final.md Phase F — "Edge banner (top of every page in edge mode)":

- Green: Edge Mode · Connected to Central · Last sync: 14 minutes ago · [Sync Now]
- Orange: Edge Mode · Offline · Last sync: 2 hours ago · [Sync Now] · 47 records pending

**Steps:**

1. **Backend — shared data**  
   In HandleInertiaRequests::share() (or an EdgeMiddleware), when EdgeModeService::isEdgeMode():  
   - Set edgeMode => true, edgeBanner => [ mode => bridge|offline, lastSyncAt => Carbon|null, pendingCount => int, siteId => SITE_ID ].  
   - lastSyncAt: from a small edge_sync_metadata table or config/cache (last successful sync timestamp).  
   - pendingCount: count of records with synced_to_central_at IS NULL (sessions, transaction_logs, etc. per Phase G scope).  
   - Set edgeSyncUrl to the route URL for triggering sync (e.g. POST).

2. **Component — EdgeBanner.svelte**  
   - Location: resources/js/Components/EdgeBanner.svelte.  
   - Props (from $page.props): edgeMode, edgeBanner, edgeSyncUrl.  
   - When !edgeMode or !edgeBanner: render nothing.  
   - When edgeBanner.mode === 'bridge': green indicator, "Edge Mode · Connected to Central", format lastSyncAt as relative (e.g. "14 minutes ago"), button "Sync Now" → POST edgeSyncUrl (disable while request in flight).  
   - When edgeBanner.mode === 'offline': orange indicator, "Edge Mode · Offline", same last sync + "X records pending", "Sync Now".  
   - Use design tokens from docs/architecture/07-UI-UX-SPECS.md and Skeleton/flexiqueue theme; ensure 48px min touch targets for "Sync Now".  
   - role="status" and aria-live="polite" for state changes.

3. **Layout integration**  
   - Use EdgeBanner in AppShell.svelte and AdminLayout.svelte (and MobileLayout.svelte if triage uses it) only when edgeMode === true, above main content.  
   - Existing OfflineBanner.svelte remains for browser offline (navigator.onLine). Edge banner is separate (backend-driven bridge/offline + sync state).

4. **Tests**  
   - Feature test: with APP_MODE=edge and mocked EdgeModeService/ConnectivityMonitor, request a page and assert shared props include edgeMode, edgeBanner with expected keys.  
   - Optional: unit test for relative time formatting if extracted to a small util.

**Files:**

- app/Http/Middleware/HandleInertiaRequests.php (or new EdgeInertiaMiddleware).
- resources/js/Components/EdgeBanner.svelte (new).
- resources/js/Layouts/AppShell.svelte, AdminLayout.svelte, MobileLayout.svelte (conditional render of EdgeBanner).
- app/Services/EdgeModeService.php (or SyncMetadataService for lastSyncAt/pendingCount).
- tests/Feature/EdgeMode/EdgeBannerTest.php or within existing edge feature tests.

---

### F.4.2 — Sync status widget (pending count, last sync, Sync Now)

**Scope:** Widget that shows pending count, last sync time, and "Sync Now" action. May be the same as the edge banner strip, or a compact widget for sidebar/footer.

**Steps:**

1. **Reuse EdgeBanner or extract SyncStatusWidget**  
   - Option A: Edge banner (F.4.1) already shows "Last sync: X ago" and "Sync Now" and pending count when offline; no separate widget.  
   - Option B: Add a small SyncStatusWidget component for placement in Admin sidebar or footer.  
   - Recommendation: Implement F.4.1 first; if product wants a dedicated widget elsewhere, add SyncStatusWidget.svelte that receives same edgeBanner + edgeSyncUrl.

2. **Sync Now endpoint**  
   - Ensure a POST route exists for "Sync Now" (e.g. POST /api/edge/sync-now) that dispatches SyncToCentralJob and returns JSON { accepted: true, message: 'Sync started' }.  
   - Frontend: on "Sync Now" click, POST with CSRF; on success, refresh Inertia props or refetch edgeBanner.

3. **Accessibility**  
   - Widget/banner: aria-label for "Sync status" and "Sync Now" button; loading state (aria-busy, disabled button) while request in flight.

**Files:**

- Same as F.4.1 if Option A.  
- If Option B: resources/js/Components/SyncStatusWidget.svelte, and layout(s) where widget is placed.  
- Backend: route + controller/job for sync-now (may be part of Phase G).

---

### F.4.3 — Triage page: offline state messaging

**Scope:** When edge mode and offline, triage page shows explicit copy and correct CTAs (Search Local Registry, Create New Client, Skip Binding).

**Reference:** central-edge-v2-final.md Phase F — "Triage page — offline state":

- Info: Offline Mode  
- You can create clients and bind tokens. ID verification is unavailable while offline.  
- Bindings made now will be marked as unconfirmed and reviewed when synced to central.  
- [Search Local Registry] [Create New Client] [Skip Binding]

**Steps:**

1. **Controller**  
   - TriagePageController: pass edgeMode, connectivityMode (or canShowIdBindingPage), and optionally triageOfflineMessage. Derive from EdgeModeService and ConnectivityMonitor.

2. **Triage/Index.svelte**  
   - Accept props: edgeMode, canShowIdBindingPage.  
   - When edgeMode === true and canShowIdBindingPage === false:  
     - Render an info block at top: icon, heading "Offline Mode", body text per spec.  
     - Show primary actions: [Search Local Registry], [Create New Client], [Skip Binding].  
   - Ensure "Search Local Registry" triggers local client search only. "Create New Client" opens offline client form (F.4.6). "Skip Binding" continues bind flow without ID.

3. **Copy and a11y**  
   - Use role="status" or role="region" aria-label="Offline mode notice" for the message block.  
   - Buttons: 48px min touch target (touch-target-h from 07-UI-UX-SPECS).

**Files:**

- app/Http/Controllers/TriagePageController.php (add edge/triage props).
- resources/js/Pages/Triage/Index.svelte (conditional offline block + CTAs).
- Optional: resources/js/Components/TriageOfflineNotice.svelte.

---

### F.4.4 — Triage page: bridge state messaging

**Scope:** When edge mode and bridge (connected), triage shows "Connected" and full CTAs (Search All Clients, Create New Client, Verify ID).

**Reference:** central-edge-v2-final.md Phase F — "Triage page — bridge state":

- Green: Connected  
- Full client creation and ID verification available.  
- [Search All Clients] [Create New Client] [Verify ID]

**Steps:**

1. **Controller**  
   - Same as F.4.3: pass edgeMode, canShowIdBindingPage. When edgeMode and canShowIdBindingPage, frontend shows bridge state.

2. **Triage/Index.svelte**  
   - When edgeMode === true and canShowIdBindingPage === true:  
     - Render success-style block: "Connected", "Full client creation and ID verification available.", [Search All Clients], [Create New Client], [Verify ID].  
   - Wire "Search All Clients" to bridge/client search API; "Verify ID" to ID binding flow.

3. **Single source of truth**  
   - One conditional: canShowIdBindingPage false → offline messaging + local-only actions; true → bridge messaging + full actions.

**Files:**

- app/Http/Controllers/TriagePageController.php.  
- resources/js/Pages/Triage/Index.svelte (bridge block + CTAs).  
- Optional: TriageEdgeNotice.svelte with variant for offline vs bridge.

---

### F.4.5 — Admin read-only mode (hidden save/delete + notices)

**Scope:** On Pi (edge mode), admin program/user/token management pages do not show Save or Delete buttons; show an explanatory notice that settings are read-only on this device.

**Reference:** central-edge-v2-final.md Phase F — "Admin program edit — edge mode":  
"Program settings are read-only on this device. To modify program settings, log in to the central server."  
Save/Delete buttons: hidden (not disabled) in edge mode.

**Steps:**

1. **Shared props**  
   - Share canEditPrograms, canEditUsers, canEditTokens from HandleInertiaRequests when edge.

2. **AdminLayout.svelte**  
   - Receive canEditPrograms, canEditUsers, canEditTokens (or adminReadOnly when any false in edge mode).  
   - When admin read-only: render a persistent notice: "Program and staff settings are read-only on this device. To modify them, log in to the central server."  
   - Use Skeleton alert/preset.

3. **Program edit/show page**  
   - When canEditPrograms === false, do not render Save or Delete buttons; optionally show inline notice.

4. **User management pages**  
   - When canEditUsers === false, hide Save/Delete/Create; show same-style notice.

5. **Token management pages**  
   - When canEditTokens === false, hide Save/Delete; show notice.

6. **Backend enforcement**  
   - PUT/POST/DELETE for program, user, token update return 403 when EdgeModeService::canEdit* is false.

7. **Tests**  
   - Feature test: as edge-mode admin, GET program edit page and assert canEditPrograms false and no Save in payload/HTML.  
   - Feature test: POST/PUT to program update from edge returns 403.

**Files:**

- app/Http/Middleware/HandleInertiaRequests.php (share canEditPrograms, canEditUsers, canEditTokens when edge).
- resources/js/Layouts/AdminLayout.svelte (read-only notice).
- resources/js/Pages/Admin/Programs/Show.svelte (hide save/delete when canEditPrograms false).
- resources/js/Pages/Admin/Users/Index.svelte (hide when canEditUsers false).
- resources/js/Pages/Admin/Tokens/Index.svelte (hide when canEditTokens false).
- Controllers or middleware: 403 for program/user/token update/delete when edge and edit not allowed.
- tests/Feature/EdgeMode/AdminReadOnlyTest.php.

---

### F.4.6 — Offline client creation form (name + birth_year only)

**Scope:** When edge mode and offline, staff can create a new client with only name and birth_year; no ID binding page is shown; client is stored locally.

**Reference:** central-edge-v2-final.md Phase F (Decision 2), F.4.6 in task list.

**Steps:**

1. **Backend**  
   - When EdgeModeService::canShowIdBindingPage() === false, accept only name and birth_year; create client locally; do not redirect to ID binding page.  
   - Return created client (with UUID when applicable) so triage can bind the session to this client.

2. **Triage flow**  
   - "Create New Client" in triage (offline) opens a modal or inline form: Name (required), Birth year (required).  
   - Submit creates client via API that in edge-offline mode accepts only these fields and skips ID binding.  
   - On success, close form and use the returned client for binding.

3. **TriageClientBinder.svelte or Triage page**  
   - When canShowIdBindingPage === false, "Create New Client" must not open ID verification step; only name + birth_year form.  
   - After create, bind session to new client without showing ID binding page.

4. **Validation**  
   - Server: validate name (required, max length), birth_year (required, reasonable range).  
   - Client: same validation and clear error display.

5. **Tests**  
   - Feature test: as edge-offline, POST create client with name + birth_year only; assert 201 and client stored; assert no redirect to ID binding.  
   - Feature test: bind session to this new client and assert binding_status === 'unconfirmed'.

**Files:**

- app/Http/Controllers/Api/ClientController.php or triage-specific client create endpoint.  
- Form Request: StoreOfflineClientRequest (name required, birth_year required, integer, min/max).  
- resources/js/Pages/Triage/Index.svelte and/or TriageClientBinder.svelte: offline "Create New Client" form (name, birth_year), submit, then bind.  
- Optional: resources/js/Components/OfflineClientCreateForm.svelte.  
- tests/Feature/EdgeMode/OfflineClientCreationTest.php.

---

## File list (Phase F UI)

| File | Purpose |
|------|--------|
| app/Http/Middleware/HandleInertiaRequests.php | Share edgeMode, edgeBanner, edgeSyncUrl, canShowIdBindingPage, canEditPrograms, canEditUsers, canEditTokens. |
| resources/js/Components/EdgeBanner.svelte | Edge banner strip (bridge/offline + last sync + pending + Sync Now). |
| resources/js/Components/SyncStatusWidget.svelte | (Optional) Compact sync widget. |
| resources/js/Layouts/AppShell.svelte | Conditionally render EdgeBanner when edgeMode. |
| resources/js/Layouts/AdminLayout.svelte | Conditionally render EdgeBanner; read-only notice when admin read-only. |
| resources/js/Layouts/MobileLayout.svelte | Conditionally render EdgeBanner when edgeMode if triage uses it. |
| resources/js/Pages/Triage/Index.svelte | Offline notice + CTAs (F.4.3); bridge notice + CTAs (F.4.4); offline client form (F.4.6). |
| resources/js/Components/TriageOfflineNotice.svelte | (Optional) Extracted offline message block. |
| resources/js/Components/TriageEdgeNotice.svelte | (Optional) Single component for offline vs bridge. |
| resources/js/Components/OfflineClientCreateForm.svelte | (Optional) Name + birth_year form. |
| resources/js/Pages/Admin/Programs/Show.svelte | Hide save/delete when canEditPrograms false. |
| resources/js/Pages/Admin/Users/Index.svelte | Hide save/delete when canEditUsers false. |
| resources/js/Pages/Admin/Tokens/Index.svelte | Hide save/delete when canEditTokens false. |
| Backend: Edge sync metadata / pending count | Service or query for lastSyncAt and pendingCount (may be Phase G). |
| Backend: POST sync-now route + job | Trigger sync from UI (may be Phase G). |
| Backend: Client create (offline) | Accept name + birth_year only when canShowIdBindingPage false; 403 for program/user/token edit. |
| tests/Feature/EdgeMode/* | EdgeBanner, AdminReadOnly, OfflineClientCreation. |

---

## Design tokens and accessibility (UX Architect / UI Designer)

- **Foundation:** Use existing flexiqueue theme and resources/css/themes/flexiqueue.css.  
- **Touch targets:** Minimum 48×48px for Sync Now, Search Local Registry, Create New Client, Skip Binding (touch-target-h from 07-UI-UX-SPECS).  
- **States:** Banner and notices use Skeleton preset (success for Connected, warning for Offline/read-only); ensure contrast per WCAG AA.  
- **Live regions:** Edge banner and triage notices use role="status" or aria-live="polite".  
- **Theme:** Respect existing data-theme="flexiqueue" and ThemeToggle in layout.
