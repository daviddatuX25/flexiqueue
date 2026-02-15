# FlexiQueue — Phase 1 UI Routes & Component Hierarchy

**Framework:** Svelte 5 + Inertia.js (via Laravel)
**Styling:** TailwindCSS 4 + DaisyUI 5 (see `07-UI-UX-SPECS.md` for design system, theme, and component mapping)
**Layout:** Mobile-first (375px primary), desktop for admin (1440px)

---

## 1. Route Map

### Public Routes (No Auth)

| Route | Page Component | Purpose |
|-------|---------------|---------|
| `GET /login` | `Auth/Login.svelte` | Staff login form |
| `GET /display` | `Display/Board.svelte` | Informant "Now Serving" board |
| `GET /display/status/{qr_hash}` | `Display/Status.svelte` | Client QR status check result |

### Authenticated Routes (All Staff)

| Route | Page Component | Purpose | Target Device |
|-------|---------------|---------|--------------|
| `GET /dashboard` | Redirect | Role-based: admin → admin.dashboard, staff → station. Staff home / future staff dashboard URL. | — |
| `GET /triage` | `Triage/Index.svelte` | QR scan + category select + bind | Mobile (375px) |
| `GET /station` | `Station/Index.svelte` | Station operations (auto-detects assigned station) | Mobile (375px) |
| `GET /station/{id}` | `Station/Index.svelte` | Station operations (explicit station, supervisor/admin) | Mobile (375px) |

### Admin Routes (Admin Role Only)

| Route | Page Component | Purpose | Target Device |
|-------|---------------|---------|--------------|
| `GET /admin/dashboard` | `Admin/Dashboard.svelte` | System health + live overview | Desktop (1440px) |
| `GET /admin/programs` | `Admin/Programs/Index.svelte` | Program list + CRUD | Desktop |
| `GET /admin/programs/{id}` | `Admin/Programs/Show.svelte` | Program detail (tracks, stations, steps) | Desktop |
| `GET /admin/programs/{id}/tracks` | `Admin/Tracks/Index.svelte` | Track management per program | Desktop |
| `GET /admin/programs/{id}/stations` | `Admin/Stations/Index.svelte` | Station management per program | Desktop |
| `GET /admin/tokens` | `Admin/Tokens/Index.svelte` | Token inventory + batch create | Desktop |
| `GET /admin/users` | `Admin/Users/Index.svelte` | Staff accounts + role management | Desktop |
| `GET /admin/reports` | `Admin/Reports/Index.svelte` | Audit log viewer + export | Desktop |

---

## 2. Layout Components

### 2.1 `Layouts/AppShell.svelte` (Authenticated Base)

Wraps all authenticated pages. Provides:
- Header bar (role-aware navigation).
- Offline detection banner.
- Toast notification area.
- Footer with connection status.

**Props:**
- `user` — current authenticated user object.
- `activeProgram` — currently active program (or null).

**Slots:**
- Default slot for page content.

---

### 2.2 `Layouts/AdminLayout.svelte` (Admin Pages)

Extends `AppShell`. Adds:
- Left sidebar (240px) with admin navigation.
- Main content area (fluid).

**Sidebar Menu Items:**
- Dashboard (`/admin/dashboard`)
- Programs (`/admin/programs`)
- Tokens (`/admin/tokens`)
- Users (`/admin/users`)
- Reports (`/admin/reports`)

---

### 2.3 `Layouts/MobileLayout.svelte` (Live Session: Triage, Station, Track Overrides)

Used for Station, Triage, Track Overrides, and Profile. Optimized for:
- Full-width content (no sidebar).
- Large touch targets.
- Fixed header + scrollable content + fixed footer.

**Header:** Top-left back link (arrow + "Admin panel" for admin → `/admin/dashboard`, or "Dashboard" for staff → `/dashboard`) + page title (e.g. station name, "Triage", "Track Overrides") + user dropdown. Back link is always visible for consistent return to admin or staff home.

**User dropdown:** User name, then "Admin panel" / "Dashboard" (same as back link), then **Live Session** section (Station, Triage, Track Overrides), then Log out.

**Bottom dock:** Label "Live Session" above three tabs: Station | Triage | Track Overrides. Connection status + queue stats + clock below.

---

### 2.4 `Layouts/DisplayLayout.svelte` (Informant)

No auth required. Minimal chrome:
- Blue header with program name + date.
- Full-screen content.
- Auto-refreshing via WebSocket.
- No navigation (kiosk mode).

---

## 3. Page-by-Page Component Hierarchy

### 3.1 Login Page — `Auth/Login.svelte`

```
Auth/Login.svelte
├── Logo + branding
├── LoginForm
│   ├── EmailInput
│   ├── PasswordInput
│   ├── ErrorMessage (validation / auth failure)
│   └── SubmitButton ("Sign In")
└── Footer (version info)
```

**State:**
- `email`, `password` (form fields).
- `errors` (from server validation, via Inertia).
- `processing` (loading state during submission).

**Data loaded:** None (public page).

---

### 3.2 Triage Page — `Triage/Index.svelte`

Single **"Get token"** (scan or enter) section: camera and manual entry live in one block with no mode toggle. User can start the camera to scan a QR code, or ignore the camera and type a token ID and tap Look up; both paths feed the same `scannedToken` and the category/track/confirm flow.

```
Triage/Index.svelte [MobileLayout]
├── GetTokenSection ("Scan or enter token ID")
│   ├── StartCameraButton / StopCameraButton (toggles QrScanner visibility)
│   ├── [IF camera open] QrScanner
│   │   └── CameraViewfinder (300x300, centered) + ScanFeedback (success/error)
│   ├── Divider ("or enter token ID")
│   ├── TokenIdInput (placeholder e.g. "A1") + LookUpButton (inline, always visible)
│   └── LookupErrorAlert (shown on failed lookup)
│
├── CategorySelector (visible after scan)
│   ├── ScannedTokenDisplay ("TOKEN SCANNED: A1")
│   ├── CategoryButton ("Regular")
│   ├── CategoryButton ("PWD / Senior / Pregnant")
│   ├── CategoryButton ("Incomplete Documents")
│   └── TrackDropdown (if > 3 tracks, fallback to dropdown)
│
├── ConfirmBar
│   ├── CancelButton (gray)
│   └── ConfirmButton (green, disabled until category selected)
│
├── DoubleScanModal (shown if token is in_use)
│   ├── ActiveSessionInfo (alias, station, status, started_at)
│   ├── ViewDetailsButton
│   └── ForceEndButton → SupervisorPinModal
│
├── SupervisorPinModal (reusable)
│   ├── PinInput (6 digits, numeric keypad)
│   ├── ReasonTextarea (if required)
│   ├── CancelButton
│   └── ConfirmButton
│
└── StatusFooter (online/offline, queue count, processed today, clock)
```

**State:**
- `scanState`: `'scanning'` | `'scanned'` | `'confirming'` | `'error'`
- `scannedToken`: `{ physical_id, qr_hash }` | null
- `selectedCategory`: string | null
- `selectedTrackId`: number | null
- `doubleScanSession`: session object | null (when token is in_use)
- `isSubmitting`: boolean

**Data loaded (via Inertia props):**
- `activeProgram` — with tracks (id, name, color_code, is_default).
- `stats` — `{ queueCount, processedToday }`.

**WebSocket subscriptions:** None (triage doesn't need live updates; it's scan-and-go).

---

### 3.3 Station Page — `Station/Index.svelte`

```
Station/Index.svelte [MobileLayout]
├── StationHeader
│   ├── StationName ("Table 2 — Interview")
│   └── StaffMenu (settings, switch station for supervisors)
│
├── [IF now_serving]:
│   ├── CurrentClientCard
│   │   ├── NowServingLabel
│   │   ├── AliasDisplay ("A1", 72px)
│   │   ├── CategoryBadge ("PWD / Senior", gold)
│   │   ├── TimerDisplay (started_at, live duration)
│   │   ├── ProgressBar ("Step 2 of 3: Interview")
│   │   └── IdentityVerification (for priority categories)
│   │       ├── VerifyPrompt ("Verify ID for priority client")
│   │       ├── VerifiedButton
│   │       └── MismatchButton → MismatchModal
│   │
│   ├── PrimaryActionButton
│   │   └── TransferButton ("SEND TO CASHIER — Table 4")
│   │       (or "COMPLETE SESSION" if last step)
│   │
│   ├── SecondaryActionsRow
│   │   ├── RequeueButton ("Re-queue")
│   │   ├── OverrideButton ("Override") → OverrideModal
│   │   └── CancelButton ("Cancel Session")
│   │
│   └── NoShowButton ("Mark No-Show (1/3)")
│
├── [IF NOT now_serving]:
│   ├── EmptyStateCard
│   │   ├── NoClientMessage ("NO CLIENT ACTIVE")
│   │   └── CallNextButton ("CALL NEXT CLIENT — B3 is ready")
│   │       (disabled if queue is empty)
│   └── EmptyQueueMessage (if queue is empty too)
│
├── QueuePreview
│   ├── QueueTitle ("QUEUE — Next 5")
│   ├── QueueItem[] (alias, track, category badge, wait time)
│   └── ViewAllButton → QueueFullModal
│
├── OverrideModal
│   ├── Title ("OVERRIDE STANDARD FLOW")
│   ├── StationRadioList (all active stations)
│   ├── ReasonTextarea (required)
│   ├── SupervisorPinInput
│   ├── CancelButton
│   └── ConfirmButton (disabled until all fields filled)
│
├── InvalidSequenceScreen (full-screen red overlay)
│   ├── WarningIcon + "INVALID SEQUENCE"
│   ├── CurrentProgress ("Step 2/4 — Interview")
│   ├── ExpectedStation ("Table 2")
│   ├── ThisStation ("Table 4 — Cashier")
│   ├── MissingSteps[] (list of required steps not yet done)
│   ├── SendBackButton
│   └── SupervisorOverrideButton → OverrideModal
│
├── MismatchModal
│   ├── Title ("Identity Mismatch")
│   ├── IssueTextarea ("Describe the issue")
│   ├── SendBackToTriageButton
│   └── CancelSessionButton
│
└── StatusFooter
```

**State:**
- `station`: full station object.
- `nowServing`: current session being served | null.
- `queue`: array of waiting sessions.
- `noShowAttempts`: number (for current session).
- `showOverrideModal`: boolean.
- `showInvalidSequence`: boolean + violation data.
- `isProcessing`: boolean (for action buttons).

**Data loaded (via Inertia props):**
- `station` — station details (id, name).
- `queue` — from `GET /api/stations/{id}/queue`.
- `allStations` — for override modal (list of stations with names).
- `user` — current staff user.

**WebSocket subscriptions:**
- `station.{id}` — listen for `ClientArrived`, `StatusUpdate`, `QueueUpdated`, `OverrideAlert`.
- On any event → re-fetch queue data or apply optimistic update.

---

### 3.4 Informant Display — `Display/Board.svelte`

```
Display/Board.svelte [DisplayLayout]
├── DisplayHeader
│   ├── AppLogo ("FlexiQueue")
│   ├── ProgramName ("Cash Assistance Distribution")
│   └── DateDisplay ("February 10, 2026")
│
├── ScanSection (tap to activate camera)
│   ├── ScanPrompt ("CHECK YOUR STATUS")
│   ├── QrIcon (large, centered)
│   └── ScanButton ("TAP TO SCAN QR CODE")
│       → Activates QR scanner overlay
│       → On scan: navigate to /display/status/{qr_hash}
│
├── NowServingSection
│   ├── SectionTitle ("NOW SERVING")
│   └── NowServingGrid (2x2 cards)
│       └── NowServingCard[] (alias → station name, track label)
│
├── WaitingSummary
│   ├── SectionTitle ("CURRENTLY WAITING")
│   ├── StationWaitRow[] ("Table 1: A8, B5, C2 — 3 clients")
│   └── TotalDisplay ("Total in queue: 8")
│
└── AnnouncementBanner (if active system announcement)
    └── MessageText + priority styling
```

**State:**
- `nowServing`: array of `{ alias, station_name, track }`.
- `waitingByStation`: array of `{ station_name, aliases[], count }`.
- `announcement`: string | null.
- `showScanner`: boolean.

**Data loaded:** Initial data via Inertia props (fetched from dashboard stats endpoint or dedicated display endpoint).

**WebSocket subscriptions:**
- `global.queue` — listen for `NowServing`, `QueueLength`, `SystemAnnouncement`, `SessionCompleted`.
- Auto-update all sections in real-time. No manual refresh needed.

---

### 3.5 Informant Status View — `Display/Status.svelte`

```
Display/Status.svelte [DisplayLayout]
├── StatusHeader ("YOUR STATUS")
├── AliasDisplay ("A1", 72px, bold)
├── CategoryBadge ("Priority", gold)
├── ProgressSteps
│   ├── StepRow ("Triage — Complete", gray, checkmark)
│   ├── StepRow ("Interview — IN PROGRESS", blue, arrow, highlighted)
│   └── StepRow ("Cashier — Waiting", light gray, circle)
├── LocationText ("Currently at: Table 2")
├── WaitEstimate ("Estimated wait: ~5 minutes")
├── DismissButton ("OK, GOT IT") → navigates back to /display
└── AutoDismissTimer (30 seconds → auto-navigate back)
```

**Data loaded:** Via Inertia props from `GET /api/check-status/{qr_hash}` response.

---

### 3.6 Admin Dashboard — `Admin/Dashboard.svelte`

```
Admin/Dashboard.svelte [AdminLayout]
├── DashboardHeader ("FlexiQueue Admin Dashboard")
│
├── HealthCardsRow (4 cards)
│   ├── StatCard ("Active Sessions", count, blue icon)
│   ├── StatCard ("Queue Waiting", count, orange icon)
│   ├── StatCard ("Stations Online", count, green icon)
│   └── StatCard ("Completed Today", count, gray icon)
│
├── ActiveProgramSection
│   ├── SectionTitle ("ACTIVE PROGRAM: Cash Assistance")
│   ├── TrackRow[] ("Regular: 15 clients", "Priority: 8 clients", ...)
│   └── NoProgramMessage (if no active program)
│
├── StationStatusTable
│   ├── TableHeader (Station | Staff | Queue | Current Client | Status)
│   └── StationRow[] (live data per station)
│
├── QuickActions
│   ├── ManageProgramButton → /admin/programs
│   ├── ManageStaffButton → /admin/users
│   └── ViewReportsButton → /admin/reports
│
└── SystemAnnouncementForm
    ├── MessageInput
    ├── PrioritySelect (normal, high)
    └── BroadcastButton
```

**State:**
- `stats` — from `GET /api/dashboard/stats`.
- `stations` — from `GET /api/stations` (with queue counts).
- Auto-refreshes via WebSocket or polling (every 10 seconds).

**WebSocket subscriptions:**
- `global.queue` — update stats in real-time.

---

### 3.7 Admin Programs — `Admin/Programs/Index.svelte`

```
Admin/Programs/Index.svelte [AdminLayout]
├── PageHeader ("Programs" + "+ Create Program" button)
├── ProgramList
│   └── ProgramCard[]
│       ├── ProgramName + status badge (Active / Inactive)
│       ├── TrackCount, StationCount, SessionCount
│       ├── EditButton → ProgramFormModal
│       ├── ActivateButton (if inactive)
│       ├── DeactivateButton (if active, confirm dialog)
│       └── DeleteButton (if no sessions, confirm dialog)
└── ProgramFormModal (create/edit)
    ├── NameInput
    ├── DescriptionTextarea
    ├── SaveButton
    └── CancelButton
```

---

### 3.8 Admin Program Detail — `Admin/Programs/Show.svelte`

```
Admin/Programs/Show.svelte [AdminLayout]
├── ProgramHeader (name, status, created date)
├── TabNavigation (Tracks | Stations | Overview)
│
├── [Tab: Tracks]
│   ├── TrackList
│   │   └── TrackCard[]
│   │       ├── TrackName + color indicator + default badge
│   │       ├── StepList (ordered: Step 1: Triage → Step 2: Interview → ...)
│   │       ├── EditTrackButton
│   │       ├── ManageStepsButton → StepManagerModal
│   │       └── DeleteTrackButton
│   └── AddTrackButton
│
├── [Tab: Stations]
│   ├── StationList
│   │   └── StationCard[]
│   │       ├── StationName
│   │       ├── Capacity, Active status
│   │       ├── AssignedStaff[]
│   │       ├── EditButton
│   │       └── ToggleActiveButton
│   └── AddStationButton
│
├── StepManagerModal
│   ├── DraggableStepList (reorder by drag or up/down buttons)
│   ├── AddStepRow (select station, required toggle, est. minutes)
│   └── SaveOrderButton
│
└── [Tab: Overview]
    ├── ProgramStats (total sessions, active, completed)
    └── TrackFlowDiagram (visual: step1 → step2 → step3 per track)
```

---

### 3.9 Admin Tokens — `Admin/Tokens/Index.svelte`

```
Admin/Tokens/Index.svelte [AdminLayout]
├── PageHeader ("Token Management" + "Create Batch" button)
├── FilterBar (status dropdown, search by physical_id)
├── TokenTable
│   ├── TableHeader (Physical ID | QR Hash (truncated) | Status | Current Session | Actions)
│   └── TokenRow[]
│       ├── StatusBadge (available=green, in_use=blue, lost=red, damaged=orange)
│       └── ActionDropdown (Mark Lost, Mark Damaged, Mark Available)
├── Pagination
└── BatchCreateModal
    ├── PrefixInput ("A")
    ├── StartNumberInput (1)
    ├── CountInput (50)
    ├── PreviewList ("A1, A2, ... A50")
    └── CreateButton
```

---

### 3.10 Admin Users — `Admin/Users/Index.svelte`

```
Admin/Users/Index.svelte [AdminLayout]
├── PageHeader ("Staff Management" + "+ Add Staff" button)
├── FilterBar (role dropdown, active toggle, search)
├── UserTable
│   ├── TableHeader (Name | Email | Role | Station | Status | Actions)
│   └── UserRow[]
│       ├── RoleBadge (admin=purple, supervisor=blue, staff=gray)
│       ├── StationAssignment (name or "Unassigned")
│       ├── ActiveToggle
│       └── EditButton → UserFormModal
├── Pagination
└── UserFormModal (create/edit)
    ├── NameInput
    ├── EmailInput
    ├── PasswordInput (required on create, optional on edit)
    ├── RoleSelect
    ├── OverridePinInput (shown for supervisor/admin roles)
    ├── StationSelect (assign to station)
    ├── ActiveToggle
    └── SaveButton
```

---

### 3.11 Admin Reports — `Admin/Reports/Index.svelte`

```
Admin/Reports/Index.svelte [AdminLayout]
├── PageHeader ("Reports & Audit")
├── FilterPanel
│   ├── ProgramSelect
│   ├── DateRangePicker (from, to)
│   ├── ActionTypeFilter (multi-select checkboxes)
│   ├── StationFilter
│   └── ApplyButton
│
├── AuditLogTable (paginated)
│   ├── TableHeader (Time | Session | Action | Station | Staff | Remarks)
│   └── LogRow[] (color-coded by action_type)
│
├── ExportBar
│   ├── DownloadCsvButton
│   ├── GenerateDailySummaryPdfButton
│   └── GenerateSessionDetailPdfButton
│
└── Pagination
```

---

## 4. Shared / Reusable Components

> **DaisyUI component mapping:** See `07-UI-UX-SPECS.md` Section 6 for the full mapping of each component to DaisyUI classes.

| Component | Used By | DaisyUI Base | Purpose |
|-----------|---------|-------------|---------|
| `QrScanner.svelte` | Triage, Display | Custom (no equivalent) | Camera-based QR code reader |
| `SupervisorPinModal.svelte` | Triage, Station | `modal` + `fieldset` + `input` | PIN entry + reason for overrides |
| `StatusBadge.svelte` | All pages | `badge` | Colored badge (waiting=info, serving=success, etc.) |
| `CategoryBadge.svelte` | Station, Display | `badge` | Priority (`badge-accent`), Regular, Incomplete (`badge-warning`) |
| `ProgressBar.svelte` | Station, Display | `progress` | Step X of Y with filled bar |
| `ProgressSteps.svelte` | Display/Status | `steps` | Vertical step list with status icons |
| `StatCard.svelte` | Dashboard | `stat` | Number + label + icon card |
| `DataTable.svelte` | Admin pages | `table` | Sortable, paginated table (`table-zebra`) |
| `Modal.svelte` | All pages | `modal` | Generic `<dialog>` wrapper with `modal-box` |
| `ConfirmDialog.svelte` | Admin pages | `modal` | "Are you sure?" with `modal-action` buttons |
| `Toast.svelte` | All pages | `toast` + `alert` | Success/error/info notification stack |
| `OfflineBanner.svelte` | AppShell | `alert` | "Offline — connection lost" (`alert-warning`) |
| `LoadingSkeleton.svelte` | All pages | `skeleton` | Animated placeholder for loading states |
| `EmptyState.svelte` | Station, admin lists | Custom (simple) | Centered message + icon for empty data |

---

## 5. State Management Strategy

### 5.1 Inertia.js Props (Primary)

All page data is loaded via Inertia.js server-side rendering:
- Controller passes data as props → Svelte component receives as `$props`.
- Form submissions use `useForm()` from `@inertiajs/svelte`.
- Page transitions preserve scroll position where appropriate.

### 5.2 Svelte Stores (Client-Side)

For real-time state that updates independently of page loads:

| Store | Scope | Purpose |
|-------|-------|---------|
| `connectionStore` | Global | `{ online: boolean, wsConnected: boolean }` |
| `toastStore` | Global | Queue of toast notifications |
| `queueStore` | Station page | Live queue data updated via WebSocket |
| `nowServingStore` | Display page | Live "now serving" list via WebSocket |
| `dashboardStore` | Admin dashboard | Live stats via WebSocket |

### 5.3 WebSocket Integration Pattern

```svelte
<!-- Station/Index.svelte -->
<script>
  import { onMount, onDestroy } from 'svelte';
  import Echo from 'laravel-echo';

  let { station, queue: initialQueue } = $props();
  let queue = $state(initialQueue);

  onMount(() => {
    window.Echo.private(`station.${station.id}`)
      .listen('ClientArrived', (e) => { /* add to queue */ })
      .listen('StatusUpdate', (e) => { /* update queue item */ })
      .listen('QueueUpdated', (e) => { /* replace queue */ });
  });

  onDestroy(() => {
    window.Echo.leave(`station.${station.id}`);
  });
</script>
```

---

## 6. Responsive Breakpoints

| Breakpoint | Target | Pages |
|------------|--------|-------|
| 375px | Mobile (primary) | Triage, Station |
| 768px | Tablet (kiosk) | Informant Display |
| 1024px | Tablet landscape | Admin (usable) |
| 1440px | Desktop (primary) | Admin Dashboard, Reports |

**Mobile-first rule:** All components start at 375px and scale up. Admin pages have a minimum usable width of 768px with horizontal scroll below that.

---

## 7. Design Tokens Reference

**Full design system, DaisyUI theme, and component mapping:** See `07-UI-UX-SPECS.md`.

Quick reference:

```
Component Library: DaisyUI 5 (65 semantic components)
Theme: "flexiqueue" custom theme (07-UI-UX-SPECS.md Section 2)

Colors (mapped to DaisyUI semantic variables):
  --color-primary:  #2563EB (blue)     → btn-primary, badge-primary, etc.
  --color-success:  #16A34A (green)    → btn-success, alert-success, etc.
  --color-warning:  #EA580C (orange)   → btn-warning, badge-warning, etc.
  --color-error:    #DC2626 (red)      → btn-error, alert-error, etc.
  --color-accent:   #F59E0B (gold)     → badge-accent (priority clients)
  --color-neutral:  #6B7280            → btn-ghost, secondary text
  --color-base-100: #FFFFFF            → page backgrounds

Typography:
  Font: Inter, sans-serif
  Alias display: 72px bold (text-7xl font-bold)
  H1: 36px (text-4xl)
  H2: 24px (text-2xl)
  Body: 16px (text-base)

Button Sizes (DaisyUI btn + Tailwind height override):
  Primary action: btn btn-primary h-20 text-lg  (80px)
  Secondary action: btn btn-ghost h-12           (48px)
  Standard: btn btn-primary                      (default ~44px)

Touch targets:
  Minimum: 44x44px (WCAG)
  Recommended: 80px height for primary actions on mobile
```
