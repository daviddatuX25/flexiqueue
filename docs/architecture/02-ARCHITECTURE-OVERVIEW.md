# FlexiQueue — Phase 1 Architecture Overview

**Purpose:** Describe how FlexiQueue is physically deployed and logically structured for Phase 1 (phone-first, no ESP32/IoT).

---

## 1. Physical Topology (Phase 1)

```text
REMOTE BARANGAY SITE
┌─────────────────────────────────────────────────────────┐
│                    WAITING AREA                         │
│                                                         │
│  ┌──────────────┐                                      │
│  │  Kiosk /     │  ← Tablet or phone in "Display Mode" │
│  │  Informant   │     Shows: Now Serving + QR status    │
│  │  Display     │     Route: /display                   │
│  └──────┬───────┘                                      │
│         │                                               │
│         │         Local WiFi (MSWDO_FlexiQueue)        │
│         │         ─────────────────────────────         │
│         │                    │                           │
├─────────┼────────────────────┼───────────────────────────┤
│  ENTRANCE / TRIAGE           │     SERVICE STATIONS      │
│                              │                           │
│  ┌──────────────┐           │   ┌──────────────┐       │
│  │ Triage Phone │           │   │ Station Phone │       │
│  │ (QR Scanner) │◄──────────┼──►│ (Table 1)    │       │
│  │ Route: /triage│          │   │ Route: /station       │
│  └──────────────┘           │   └──────────────┘       │
│                              │                           │
│                              │   ┌──────────────┐       │
│  ┌──────────────────────┐   │   │ Station Phone │       │
│  │  PORTABLE SERVER     │   │   │ (Table 2)    │       │
│  │  (Laptop)            │◄──┘   └──────────────┘       │
│  │                      │                               │
│  │  Laravel App  :80    │       ┌──────────────┐       │
│  │  Reverb WS    :6001  │       │ Admin Laptop │       │
│  │  MariaDB      :3306  │       │ Route: /admin │       │
│  │  (internal only)     │       └──────────────┘       │
│  └──────────────────────┘                               │
└─────────────────────────────────────────────────────────┘
```

**Phase 1 has NO:**
- ESP32 devices (no displays, buttons, or speakers at stations).
- Audio relay service (no port 8001).
- `device.{mac}` WebSocket channels.

---

## 2. Network Configuration

| Setting | Value |
|---------|-------|
| **SSID** | `MSWDO_FlexiQueue` |
| **Security** | WPA2-PSK |
| **IP Range** | `192.168.100.0/24` |
| **Server IP** | `192.168.100.1` |
| **HTTP** | Port `80` (or `443` with self-signed cert) |
| **WebSocket (Reverb)** | Port `6001` |
| **Database (MariaDB)** | Port `3306` (localhost only, not exposed to LAN) |

**Clients:** All phones/tablets get DHCP leases in `192.168.100.0/24` range.

**DNS:** Not required. Staff access the app via the server's IP address directly (e.g., `http://192.168.100.1`).

---

## 3. Deployment Scenarios (Phase 1)

### Scenario A — Full Setup (30+ clients/hour)
- 1 laptop server (Wi-Fi hotspot + app host).
- 4–6 staff phones (1 triage + multiple station operators).
- 1–2 tablets/phones for informant display.

### Scenario B — Minimal Setup (10–15 clients/hour)
- 1 laptop server.
- 2 staff phones (1 triage, 1 multi-station).
- 1 phone in "Display Mode" for the waiting area.

### Scenario C — Emergency / Field
- 1 laptop server.
- Staff phones only (no kiosk).
- Manual verbal calling as backup.

**Phase 1 MVP targets Scenario B/C** — minimal hardware, maximum resilience.

---

## 4. Logical Architecture (5-Layer Model)

```text
┌──────────────────────────────────────────────────────────┐
│  LAYER 5: PRESENTATION (Svelte + Inertia.js)             │
│                                                          │
│  Triage PWA   Station PWA   Display PWA   Admin Dashboard│
│  (/triage)    (/station)    (/display)    (/admin/*)     │
└─────────────────────────┬────────────────────────────────┘
                          │ HTTP (Inertia) + WebSocket (Echo)
┌─────────────────────────▼────────────────────────────────┐
│  LAYER 4: APPLICATION SERVICES (Laravel Services)        │
│                                                          │
│  ┌─────────────────┐  ┌──────────────────┐              │
│  │ SessionService   │  │ FlowEngine       │              │
│  │ - bind()        │  │ - calculateNext() │              │
│  │ - transfer()    │  │ - validateStep()  │              │
│  │ - complete()    │  │ - getExpectedStep()│             │
│  │ - cancel()      │  └──────────────────┘              │
│  │ - markNoShow()  │                                     │
│  │ - forceComplete()│  ┌──────────────────┐             │
│  └─────────────────┘  │ AuditLogger       │              │
│                        │ - logTransaction()│              │
│  ┌─────────────────┐  │ - generateCsv()   │              │
│  │ PinService       │  │ - generatePdf()   │              │
│  │ - validate()    │  └──────────────────┘              │
│  └─────────────────┘                                     │
└─────────────────────────┬────────────────────────────────┘
                          │ Events / Broadcasting
┌─────────────────────────▼────────────────────────────────┐
│  LAYER 3: COMMUNICATION (Laravel Reverb + HTTP API)      │
│                                                          │
│  WebSocket Channels:                                     │
│    - station.{id}    (private, per-station staff)        │
│    - global.queue    (public, informant displays)        │
│                                                          │
│  REST API:                                               │
│    - /api/sessions/*      (staff operations)             │
│    - /api/stations/*      (queue data)                   │
│    - /api/admin/*         (configuration)                │
│    - /api/check-status/*  (public)                       │
│    - /api/dashboard/*     (admin stats)                  │
│    - /api/auth/*          (PIN verification)             │
└─────────────────────────┬────────────────────────────────┘
                          │ Eloquent ORM
┌─────────────────────────▼────────────────────────────────┐
│  LAYER 2: DOMAIN MODEL (Eloquent Models + Business Rules)│
│                                                          │
│  Program, ServiceTrack, TrackStep, Station               │
│  Token, Session, TransactionLog, User                    │
│                                                          │
│  Business rules encoded as:                              │
│    - Model scopes (e.g., Session::active())              │
│    - Model events (e.g., creating/updating hooks)        │
│    - Validation in Form Requests                         │
│    - TransactionLog append-only (no update/delete)       │
└─────────────────────────┬────────────────────────────────┘
                          │ SQL (MariaDB driver)
┌─────────────────────────▼────────────────────────────────┐
│  LAYER 1: DATA PERSISTENCE                               │
│                                                          │
│  MariaDB 10.6+                                           │
│  8 tables (04-DATA-MODEL; 10-DATABASE-DESIGN-ASSESSMENT)  │
│  + Laravel session table (for auth sessions)             │
│  + Laravel cache table (optional)                        │
│  + Laravel jobs table (optional, for queued events)      │
└──────────────────────────────────────────────────────────┘
```

**Each layer talks only to the layer directly below it.** Presentation never queries the DB directly; it goes through Services (Layer 4) via Controllers.

---

## 5. Key Service Classes

These are the primary service classes the execution agent will create:

| Service | Layer | Responsibility | Reference |
|---------|-------|---------------|-----------|
| `SessionService` | 4 | All session lifecycle: bind, transfer, complete, cancel, no-show, force-complete | `08-API-SPEC-PHASE1.md` Sections 3.1–3.8 |
| `FlowEngine` | 4 | Route calculation: next station, step validation, sequence checking | `03-FLOW-ENGINE.md` |
| `PinService` | 4 | Supervisor PIN validation and rate limiting | `05-SECURITY-CONTROLS.md` Section 4 |
| `AuditLogger` | 4 | Transaction log creation, CSV export, PDF generation | `05-SECURITY-CONTROLS.md` Section 6, `08-API-SPEC` Section 5.8 |
| `TokenService` | 4 | Token CRUD, batch generation, QR hash creation | `08-API-SPEC` Section 5.5 |
| `ProgramService` | 4 | Program activation/deactivation, validation | `08-API-SPEC` Section 5.1 |

---

## 6. Directory Structure (Target)

```text
flexiqueue/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/              # Login, Logout
│   │   │   ├── Admin/             # Programs, Tracks, Stations, Tokens, Users, Reports
│   │   │   ├── Api/               # Session operations, station queue, dashboard stats
│   │   │   └── Display/           # Public informant routes
│   │   ├── Middleware/
│   │   │   └── EnsureRole.php     # Role-based access
│   │   └── Requests/              # Form validation (per endpoint)
│   ├── Models/                    # Eloquent models (8 models)
│   ├── Services/                  # Business logic (SessionService, FlowEngine, etc.)
│   ├── Events/                    # Broadcasting events (ClientArrived, StatusUpdate, etc.)
│   └── Policies/                  # Authorization policies (SessionPolicy, StationPolicy)
├── database/
│   ├── migrations/                # 8 table migrations + Laravel defaults
│   └── seeders/                   # Demo seeder (program, tracks, stations, tokens)
├── resources/
│   └── js/
│       ├── app.js                 # Inertia + Echo initialization
│       ├── Layouts/               # AppShell, AdminLayout, MobileLayout, DisplayLayout
│       ├── Pages/                 # Svelte page components (mirroring routes)
│       │   ├── Auth/
│       │   ├── Triage/
│       │   ├── Station/
│       │   ├── Display/
│       │   └── Admin/
│       ├── Components/            # Shared components (QrScanner, Modal, Toast, etc.)
│       └── stores/                # Svelte stores (connection, toast, queue, etc.)
├── routes/
│   ├── web.php                    # Inertia page routes
│   └── channels.php               # WebSocket channel authorization
├── docs/                          # Architecture contracts (THIS folder)
│   ├── architecture/
│   └── plans/
└── .env                           # Local deployment config
```

---

## 7. Request Lifecycle (Typical Flow)

**Example: Staff presses "Transfer to next station" on Station page**

```text
1. Svelte (Station/Index.svelte)
   → User clicks "SEND TO CASHIER" button
   → Calls Inertia.post('/api/sessions/101/transfer', { mode: 'standard' })

2. Laravel Router (routes/web.php)
   → Matches POST /api/sessions/{id}/transfer
   → Middleware: auth, role:admin,supervisor,staff
   → Dispatches to SessionController@transfer

3. SessionController
   → Validates request via TransferRequest form request
   → Calls SessionService::transfer(session, mode)

4. SessionService::transfer()
   → Calls FlowEngine::calculateNextStation(session)
   → FlowEngine returns station_id = 4 (Cashier)
   → Updates Session: status=waiting, current_station_id=4, current_step_order=3
   → Calls AuditLogger::logTransaction(session, 'transfer', ...)
   → Dispatches ClientArrived event to station.4
   → Dispatches QueueUpdated event to station.2 (old station)
   → Dispatches NowServing + QueueLength to global.queue

5. Response
   → Returns Inertia redirect or JSON with updated session

6. WebSocket (parallel)
   → station.4 channel: Station 4 page auto-updates queue (new client in waiting)
   → station.2 channel: Station 2 page auto-updates (client removed)
   → global.queue: Informant display updates Now Serving board
```

---

## 8. What Is NOT in Phase 1 Architecture

| Component | Status |
|-----------|--------|
| `device.{mac}` WebSocket channel | Not implemented |
| Audio relay service (port 8001) | Not implemented |
| Capability aggregation (`getStationCapabilities`) | Not implemented |
| `hardware_units` table / model | Not created |
| `device_events` table / model | Not created |
| IndexedDB offline action queue | Not implemented (basic banner only) |
| Multi-site sync | Not implemented |

The architecture is designed so these can be added in Phase 2+ without restructuring existing code. Channel naming and service boundaries are kept compatible.
