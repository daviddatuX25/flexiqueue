# FlexiQueue: Complete System Architecture Document

**Role:** Senior System Architect  
**Project:** FlexiQueue - Offline Queue Management System for Remote Government Operations  
**Client:** MSWDO (Municipal Social Welfare & Development Office)  
**Version:** 1.0 - Architecture Blueprint

---

## EXECUTIVE SUMMARY

FlexiQueue is a **local-first, offline-capable, phone-first queue management system** designed for portable deployment in remote Philippine barangays. The architecture prioritizes **using existing staff phones**, **staff-driven flow control**, and **audit compliance** while operating without cloud dependency, with an optional kiosk for status display.

**Core Innovation (overall system):** Decoupling physical hardware from logical workflow through capability-based aggregation and reusable token binding.  
**MVP Focus (1-month build):** A portable server running Laravel + MariaDB, PWA frontends for triage/station/kiosk/admin over local Wi-Fi, and reusable QR token cards—**excluding ESP32/IoT and audio streaming features, which are deferred to future phases.**

---

# 1. ARCHITECTURAL VISION & CONSTRAINTS

## 1.1 Driving Principles

| Principle | Implementation |
|-----------|----------------|
| **Offline-First** | Local server, no internet dependency |
| **Portable** | Laptop/RaspberryPi as hub |
| **Hardware-Agnostic** | System adapts to available devices |
| **Audit-Ready** | Immutable transaction logs (COA compliance) |
| **Staff-Empowered** | Human override beats automation |

## 1.2 Operational Constraints

- **Deployment Environment:** Remote barangay halls, gymnasiums, open fields
- **Network:** Self-contained WiFi hotspot (10-30 concurrent devices)
- **Power:** Generator/Solar backup required
- **Personnel:** Non-technical MSWDO staff (minimal training)
- **Cost:** Maximize use of existing phones, minimize dedicated hardware

## 1.3 Non-Functional Requirements

```
Performance:
  - Response Time: <500ms for UI actions
  - Concurrent Sessions: 100+ active clients
  - Database Queries: <100ms average

Reliability:
  - Uptime Target: 99% during 8-hour operations
  - Graceful Degradation: Core functions survive WiFi drops
  - Data Loss Prevention: Auto-save every 30 seconds

Security:
  - Local Network Only: No external exposure
  - Role-Based Access: Admin / Supervisor / Staff tiers
  - Audit Trail: Every state change logged with timestamp + user
```

## 1.4 One-Month MVP Scope (Phone-First)

To match the one-month capstone timeline, the initial implementation deliberately focuses on a **phone-first, ESP32-free** feature set:

- Program + tracks + stations setup (Admin, laptop or phone browser)
- QR token bind from reusable cards (Triage phone)
- Per-station queues with call-next / serve controls (Station phones)
- Transfer, complete, and no-show flows across stations
- Supervisor override with PIN + reason capture
- Informant board via kiosk tablet or staff phone in "Display Mode"
- Audit log export after events

Features involving dedicated IoT hardware (ESP32 displays/buttons/speakers), live audio streaming, and advanced analytics remain **out-of-scope for the MVP** and are documented later in this architecture as **future enhancements**.

---

# 2. SYSTEM TOPOLOGY & DEPLOYMENT MODEL

## 2.1 Physical Architecture

```
REMOTE BARANGAY SITE
┌─────────────────────────────────────────────────────────┐
│                    WAITING AREA                         │
│  ┌──────────────┐                ┌──────────────┐      │
│  │  Informant   │                │  Informant   │      │
│  │   Kiosk      │                │   Screen     │      │
│  │ (Touch/Scan) │                │  (Display)   │      │
│  └──────┬───────┘                └──────┬───────┘      │
│         │         Local WiFi            │              │
│         └────────(MSWDO_NET)────────────┘              │
│                       │                                 │
├───────────────────────┼─────────────────────────────────┤
│  ENTRANCE/TRIAGE      │      SERVICE TABLES             │
│  ┌──────────────┐     │                                 │
│  │ Reception    │     │   ┌──────────────────────┐     │
│  │ Phone/Tablet │◄────┼───┤ Portable Server      │     │
│  │ (QR Scanner) │     │   │ (Laptop/RaspberryPi) │     │
│  └──────────────┘     │   │                      │     │
│                       │   │ ┌──────────────────┐ │     │
│  SERVICE STATIONS     │   │ │ Laravel App      │ │     │
│  ┌──────────────┐     │   │ │ MySQL Database   │ │     │
│  │ Table 1      │     │   │ │ WebSocket Server │ │     │
│  │ Staff Phone  │◄────┼───┤ │ Audio Relay      │ │     │
│  │ ESP32 Display│◄────┼───┤ └──────────────────┘ │     │
│  └──────────────┘     │   │                      │     │
│                       │   │ ┌──────────────────┐ │     │
│  ┌──────────────┐     │   │ │ Admin Dashboard  │ │     │
│  │ Table 2      │     │   │ │ (Browser Access) │ │     │
│  │ Staff Laptop │◄────┼───┤ └──────────────────┘ │     │
│  │ ESP32 Speaker│◄────┘   └──────────────────────┘     │
│  │ ESP32 Button │                                       │
│  └──────────────┘                                       │
└─────────────────────────────────────────────────────────┘
```

> **MVP Note:** The diagram above represents the **phone-first baseline**: portable server + staff phones + optional kiosk tablet. ESP32 devices and other IoT peripherals are **not implemented in the one-month MVP** and are treated as future hardware extensions.

## 2.2 Network Architecture

```
┌────────────────────────────────────────────┐
│         PORTABLE SERVER (Hub)              │
│                                            │
│  ┌──────────────────────────────────────┐ │
│  │  WiFi Access Point (hostapd)         │ │
│  │  SSID: MSWDO_FlexiQueue              │ │
│  │  Security: WPA2-PSK                  │ │
│  │  IP Range: 192.168.100.1/24          │ │
│  └──────────────────────────────────────┘ │
│                                            │
│  ┌──────────────────────────────────────┐ │
│  │  Application Stack                   │ │
│  │  - Laravel (Port 80/443)             │ │
│  │  - WebSocket (Port 6001)             │ │
│  │  - MySQL (Port 3306 - Internal)      │ │
│  │  - Audio Relay (Port 8001)           │ │
│  └──────────────────────────────────────┘ │
└────────────────────────────────────────────┘
                    │
        ┌───────────┼───────────┐
        │           │           │
    [Phones]   [ESP32s]    [Kiosks]
   (DHCP)      (Static)    (DHCP)
```

## 2.3 Deployment Scenarios

### Scenario A: Full Setup (30+ clients/hour)
- 1 Laptop/Portable Server (Wi-Fi hotspot + app host)
- 4-6 Staff Phones (triage + multiple stations)
- 1-2 Informant Kiosks (tablet + 2D scanner or "Display Mode" phone)

### Scenario B: Minimal Setup (10-15 clients/hour)
- 1 Laptop/Portable Server
- 2 Staff Phones (one for triage, one for processing)
- 1 Staff Phone in "Display Mode" as informant screen

### Scenario C: Emergency/Field Setup
- 1 Laptop/Portable Server
- Staff phones only (no kiosk, no ESP32)
- Manual calling (shouting numbers) as backup if devices fail

---

# 3. LOGICAL ARCHITECTURE (5-LAYER MODEL)

```
┌──────────────────────────────────────────────────────┐
│  LAYER 5: PRESENTATION (Client Interfaces)           │
├──────────────────────────────────────────────────────┤
│  - Triage PWA (Svelte)                               │
│  - Station PWA (Svelte)                              │
│  - Informant Display (Svelte/Static HTML)            │
│  - Admin Dashboard (Svelte)                          │
└───────────────────────┬──────────────────────────────┘
                        │ HTTP/WebSocket
┌───────────────────────▼──────────────────────────────┐
│  LAYER 4: APPLICATION SERVICES (Business Logic)      │
├──────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐          │
│  │ Session Manager │  │ Flow Engine      │          │
│  │ - bind()        │  │ - calculateNext()│          │
│  │ - transfer()    │  │ - validateStep() │          │
│  │ - complete()    │  │ - applyOverride()│          │
│  └─────────────────┘  └──────────────────┘          │
│                                                       │
│  ┌─────────────────┐  ┌──────────────────┐          │
│  │ Capability Mgr  │  │ Audit Logger     │          │
│  │ - aggregateCaps()│  │ - logTransaction()│         │
│  │ - validateSetup()│  │ - generateReport()│         │
│  └─────────────────┘  └──────────────────┘          │
└───────────────────────┬──────────────────────────────┘
                        │ Events/Queries
┌───────────────────────▼──────────────────────────────┐
│  LAYER 3: COMMUNICATION INFRASTRUCTURE               │
├──────────────────────────────────────────────────────┤
│  ┌──────────────────────────────────────────┐       │
│  │ WebSocket Server (Laravel Reverb)        │       │
│  │  - station.{id} (private)                │       │
│  │  - device.{mac} (private)                │       │
│  │  - global.queue (public broadcast)       │       │
│  └──────────────────────────────────────────┘       │
│                                                       │
│  ┌──────────────────────────────────────────┐       │
│  │ Audio Stream Relay (WebRTC/Binary WS)    │       │
│  │  - Mic Capture (WebAudio API)            │       │
│  │  - Stream Encoding (Opus)                │       │
│  │  - ESP32 Decoder (I2S Output)            │       │
│  └──────────────────────────────────────────┘       │
│                                                       │
│  ┌──────────────────────────────────────────┐       │
│  │ REST API (Laravel Routes)                │       │
│  │  - /api/sessions/*                       │       │
│  │  - /api/stations/*                       │       │
│  │  - /api/devices/*                        │       │
│  └──────────────────────────────────────────┘       │
└───────────────────────┬──────────────────────────────┘
                        │ ORM/Query
┌───────────────────────▼──────────────────────────────┐
│  LAYER 2: DOMAIN MODEL (Data Logic)                  │
├──────────────────────────────────────────────────────┤
│  Eloquent Models with Business Rules:                │
│  - Program, ServiceTrack, TrackStep                  │
│  - Station, HardwareUnit                             │
│  - Token, Session, TransactionLog                    │
└───────────────────────┬──────────────────────────────┘
                        │ SQL
┌───────────────────────▼──────────────────────────────┐
│  LAYER 1: DATA PERSISTENCE                           │
├──────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐          │
│  │ MySQL Database  │  │ Config Store     │          │
│  │ (Relational)    │  │ (JSON/ENV)       │          │
│  └─────────────────┘  └──────────────────┘          │
└──────────────────────────────────────────────────────┘
```

---

# 4. DOMAIN MODEL (Entity Design)

## 4.1 Core Entities Hierarchy

```
PROGRAM (The Event)
  │
  ├─── SERVICE_TRACK (Demographic Lane)
  │     │
  │     └─── TRACK_STEP (Ordered Sequence)
  │           └─── references STATION
  │
  └─── STATION (Service Point)
        │
        └─── HARDWARE_UNIT (Physical Device)
              └─── has CAPABILITIES (JSON)

TOKEN (Physical QR Card)
  │
  └─── SESSION (Active Client Journey)
        │
        ├─── belongs to TRACK
        ├─── currently at STATION
        │
        └─── TRANSACTION_LOG (Audit Trail)
              └─── performed by STAFF
```

## 4.2 Detailed Entity Specifications

### 4.2.1 PROGRAM
```yaml
Purpose: Defines the government assistance event
Attributes:
  - id: bigint (PK)
  - name: varchar(100) # "Social Pension Distribution"
  - description: text
  - is_active: boolean # Only one active per deployment
  - created_at: timestamp
  - created_by: bigint (FK → users)
  
Business Rules:
  - Only ONE program can be active at a time
  - Must have at least ONE service track
  - Cannot delete if active sessions exist
  
Relationships:
  - Has Many: service_tracks
  - Has Many: stations (through the program)
  - Has Many: sessions
```

### 4.2.2 SERVICE_TRACK
```yaml
Purpose: Defines demographic-specific pathways
Attributes:
  - id: bigint (PK)
  - program_id: bigint (FK)
  - name: varchar(50) # "Priority Lane (PWD/Senior)"
  - description: text
  - is_default: boolean # One track per program is default
  - color_code: varchar(7) # UI differentiation
  
Business Rules:
  - Each program must have ONE default track
  - Track name must be unique within program
  - Cannot delete if referenced in active sessions
  
Relationships:
  - Belongs To: program
  - Has Many: track_steps (ordered)
  - Has Many: sessions
```

### 4.2.3 TRACK_STEP
```yaml
Purpose: Ordered sequence of stations for a track
Attributes:
  - id: bigint (PK)
  - track_id: bigint (FK)
  - station_id: bigint (FK)
  - step_order: int # 1, 2, 3...
  - is_required: boolean
  - estimated_duration_minutes: int # For queue estimation
  
Business Rules:
  - step_order must be unique within track
  - Cannot have gaps in sequence (1,2,4 invalid)
  - Station can appear in multiple tracks
  
Relationships:
  - Belongs To: service_track
  - References: station
```

### 4.2.4 STATION
```yaml
Purpose: Logical service point (formerly "Table")
Attributes:
  - id: bigint (PK)
  - program_id: bigint (FK)
  - name: varchar(50) # "Verification Desk"
  - role_type: enum('triage', 'processing', 'release')
  - capacity: int # How many staff can operate here
  - is_active: boolean
  
Business Rules:
  - Name must be unique within program
  - Must have at least ONE hardware unit OR staff phone
  - Cannot delete if referenced in track_steps
  
Relationships:
  - Belongs To: program
  - Has Many: hardware_units
  - Has Many: sessions (current_station_id)
  - Referenced By: track_steps
```

### 4.2.5 HARDWARE_UNIT
```yaml
Purpose: Physical device (ESP32 or phone)
Attributes:
  - id: bigint (PK)
  - station_id: bigint (FK, nullable) # NULL = unassigned
  - mac_address: varchar(17) UNIQUE
  - device_type: enum('esp32_combo', 'esp32_display', 
                      'esp32_button', 'esp32_speaker',
                      'mobile_phone')
  - capabilities: json # {"has_display": true, "has_buttons": true}
  - status: enum('online', 'offline', 'disabled')
  - last_heartbeat: timestamp
  
Business Rules:
  - mac_address is globally unique
  - Can only be assigned to ONE station at a time
  - Status auto-updates based on heartbeat (>60s = offline)
  
Relationships:
  - Belongs To: station (nullable)
  - Sends: device_events (logs)
  
Capability Schema:
  {
    "has_display": boolean,
    "display_orientation": "landscape|portrait",
    "has_buttons": boolean,
    "button_count": int,
    "has_speaker": boolean,
    "has_microphone": boolean,
    "has_scanner": boolean
  }
```

### 4.2.6 TOKEN
```yaml
Purpose: Physical reusable QR card
Attributes:
  - id: bigint (PK)
  - qr_code_hash: varchar(64) UNIQUE # SHA-256 of QR data
  - physical_id: varchar(10) # "A1", "B15" (printed on card)
  - status: enum('available', 'in_use', 'lost', 'damaged')
  - current_session_id: bigint (FK, nullable)
  
Business Rules:
  - qr_code_hash is immutable after creation
  - Can only have ONE active session
  - Status changes trigger audit log
  
Relationships:
  - Has One: session (current, nullable)
  - Has Many: sessions (historical)
```

### 4.2.7 SESSION
```yaml
Purpose: Client's journey through the system
Attributes:
  - id: bigint (PK)
  - token_id: bigint (FK)
  - program_id: bigint (FK)
  - track_id: bigint (FK)
  - alias: varchar(10) # "A1" for display (from token.physical_id)
  - client_category: varchar(50) # "PWD", "Senior", "Pregnant"
  - current_station_id: bigint (FK, nullable)
  - current_step_order: int # Where in the track sequence
  - status: enum('waiting', 'serving', 'completed', 
                 'cancelled', 'no_show')
  - started_at: timestamp
  - completed_at: timestamp (nullable)
  - no_show_attempts: int DEFAULT 0
  
Business Rules:
  - Alias must be unique among active sessions
  - current_step_order must match track_steps.step_order
  - Status transitions are logged
  - Cannot complete if current_step < max(track_steps.step_order)
  
Relationships:
  - Belongs To: token
  - Belongs To: program
  - Belongs To: service_track
  - Currently At: station (nullable)
  - Has Many: transaction_logs
```

### 4.2.8 TRANSACTION_LOG
```yaml
Purpose: Immutable audit trail (COA compliance)
Attributes:
  - id: bigint (PK)
  - session_id: bigint (FK)
  - station_id: bigint (FK)
  - staff_user_id: bigint (FK)
  - action_type: enum('bind', 'check_in', 'transfer', 
                      'override', 'complete', 'cancel', 
                      'no_show')
  - previous_station_id: bigint (FK, nullable)
  - next_station_id: bigint (FK, nullable)
  - remarks: text (nullable) # Required for overrides
  - metadata: json # Additional context
  - timestamp: timestamp DEFAULT CURRENT_TIMESTAMP
  
Business Rules:
  - IMMUTABLE (no updates/deletes allowed)
  - action_type 'override' requires remarks
  - Auto-generated by middleware, not manual
  
Relationships:
  - Belongs To: session
  - References: station (current/previous/next)
  - References: users (staff)
  
Metadata Schema Examples:
  {
    "override_reason": "Missing documents - return to verification",
    "hardware_triggered": true,
    "device_mac": "AA:BB:CC:DD:EE:FF"
  }
```

## 4.3 Supporting Entities

### USERS (Staff)
```yaml
Attributes:
  - id, name, email, password_hash
  - role: enum('admin', 'supervisor', 'staff')
  - assigned_station_id: bigint (FK, nullable)
  - is_active: boolean
```

### DEVICE_EVENT (Hardware Logs)
```yaml
Attributes:
  - id, hardware_unit_id, event_type
  - payload: json
  - timestamp
```

---

# 5. FLOW ENGINE ARCHITECTURE

## 5.1 Flow Calculation Logic

```
FUNCTION calculateNextStation(session_id):
  
  1. LOAD session with track, current_step
  
  2. CHECK for STAFF OVERRIDE
     IF transaction_logs.last_action == 'override':
       RETURN override.next_station_id
  
  3. CALCULATE STANDARD PATH
     current_step = session.current_step_order
     next_step = track_steps WHERE step_order = current_step + 1
     
     IF next_step EXISTS:
       RETURN next_step.station_id
     ELSE:
       RETURN NULL (flow complete)
  
  4. VALIDATE REQUIREMENTS
     IF next_step.is_required:
       CHECK station.is_active
       IF NOT active:
         TRIGGER staff_alert("Station offline - manual routing needed")
         RETURN NULL
```

## 5.2 State Transition Diagram

```
┌─────────────┐
│  AVAILABLE  │ (Token in bucket)
│   (Token)   │
└──────┬──────┘
       │ scan_at_triage()
       ▼
┌─────────────┐
│   WAITING   │ (Session created, assigned to track)
│  (Session)  │
└──────┬──────┘
       │ call_next()
       ▼
┌─────────────┐
│   SERVING   │ (Staff processing client)
│  (Session)  │
└──────┬──────┘
       │
       ├─ transfer() ──→ WAITING (at next station)
       │
       ├─ override() ──→ WAITING (at custom station)
       │
       ├─ complete() ──→ COMPLETED
       │
       └─ mark_no_show() ──→ NO_SHOW
              │
              ▼
       ┌─────────────┐
       │  AVAILABLE  │ (Token unbound, back to bucket)
       │   (Token)   │
       └─────────────┘
```

## 5.3 Multi-Track Routing Example

```
PROGRAM: "Cash Assistance Distribution"

TRACK A (Regular):
  Step 1: Triage (Screening)
  Step 2: Documentation Review
  Step 3: Interview
  Step 4: Cashier

TRACK B (PWD/Senior Priority):
  Step 1: Triage (Express)
  Step 2: Interview
  Step 3: Cashier
  
TRACK C (Incomplete Docs):
  Step 1: Triage
  Step 2: Legal Assistance
  Step 3: Documentation Review
  Step 4: Interview
  Step 5: Cashier

SCENARIO:
  Client arrives → Triage scans token
  Staff selects: "PWD" → System binds to TRACK B
  
  At Step 1 (Triage):
    Standard Next: Interview (Step 2 of Track B)
    
  Staff Override Option:
    "Actually needs legal help" → Manually route to Legal
    System creates override log
    Next scan at Legal desk continues from there
```

---

# 6. CAPABILITY AGGREGATION SYSTEM

> **Phase Note:** This section describes the **future hardware-extensible design** (e.g., ESP32 displays, speakers, buttons). It is **not part of the one-month MVP implementation**, but is retained here as the roadmap for adding smart hardware once the phone-first core is stable.

## 6.1 The Problem
A station might have:
- 1 ESP32 with display only
- 1 ESP32 with buttons only
- 1 Staff phone

**Question:** What can this station DO?

## 6.2 The Solution: Dynamic Capability Calculation

```
FUNCTION getStationCapabilities(station_id):
  
  1. QUERY all hardware_units WHERE station_id = X
  
  2. MERGE capabilities JSON from all units
     Example:
       Unit 1: {"has_display": true}
       Unit 2: {"has_buttons": true}
       Phone: {"has_scanner": true, "has_microphone": true}
       
     RESULT: {
       "has_display": true,
       "has_buttons": true,
       "has_scanner": true,
       "has_microphone": true,
       "has_speaker": false  # Missing
     }
  
  3. EVALUATE station requirements
     IF role_type == 'triage':
       REQUIRE: has_scanner
       WARN_IF_MISSING: has_display
     
     IF role_type == 'processing':
       REQUIRE: has_display OR has_buttons
       WARN_IF_MISSING: has_speaker (for calling)
  
  4. RETURN configuration object to frontend
     {
       "capabilities": {...},
       "warnings": ["No speaker - cannot call clients audibly"],
       "ui_mode": "phone_primary" | "device_primary" | "hybrid"
     }
```

## 6.3 UI Adaptation Example

```
STATION INTERFACE LOADS:

IF capabilities.has_buttons == true:
  HIDE software "Next" button (use physical button)
ELSE:
  SHOW software "Next" button (large, prominent)

IF capabilities.has_speaker == false:
  SHOW "Manually call client: A1" (staff shouts number)
ELSE:
  AUTO-PLAY chime + announce "A1 to Table 2"

IF capabilities.has_microphone == true:
  SHOW "Hold to Talk" button (phone-to-ESP32 speaker)
ELSE:
  HIDE voice feature
```

---

# 7. COMMUNICATION INFRASTRUCTURE

## 7.1 WebSocket Channel Design

```
CHANNEL: station.{id}
Purpose: Private channel for staff at specific station
Subscribers: Staff devices assigned to this station
Messages:
  - client_arrived: {session_id, alias, category}
  - status_update: {session_id, new_status}
  - override_alert: {from_station, session_id, reason}

CHANNEL: device.{mac}
Purpose: Direct hardware control
Subscribers: Single ESP32 unit
Messages:
  - display_update: {text, color, animation}
  - trigger_sound: {sound_id, volume}
  - flash_lights: {pattern, duration}

CHANNEL: global.queue
Purpose: Public broadcast for informant screens
Subscribers: All waiting area displays
Messages:
  - now_serving: [{alias: "A1", station: "Table 2"}, ...]
  - queue_length: {station_id, count}
  - system_announcement: {message, priority}
```

## 7.2 Audio Streaming Architecture

> **Phase Note:** Real-time audio streaming to hardware speakers is an **advanced feature planned for post-MVP phases**. The one-month MVP relies on staff verbally calling clients or using on-screen prompts only.

```
┌──────────────────┐
│  Staff Phone     │
│  (Browser)       │
│                  │
│  WebAudio API    │
│  - getUserMedia()│
│  - createAnalyser│
└────────┬─────────┘
         │ Binary WebSocket
         │ (Opus encoded chunks)
         ▼
┌──────────────────┐
│  Laravel Server  │
│                  │
│  Audio Relay     │
│  - Validates MAC │
│  - Broadcasts to │
│    target device │
└────────┬─────────┘
         │ Binary WebSocket
         │ (Raw PCM or Opus)
         ▼
┌──────────────────┐
│  ESP32 Speaker   │
│                  │
│  I2S Driver      │
│  - MAX98357A DAC │
│  - Buffer mgmt   │
└──────────────────┘

LATENCY BUDGET:
  Capture: ~20ms
  Encode: ~10ms
  Network: ~50ms (local WiFi)
  Decode: ~10ms
  Playback: ~20ms
  ─────────────────
  Total: ~110ms (acceptable for announcements)
```

## 7.3 API Endpoint Structure

```
PUBLIC ENDPOINTS (No Auth):
  GET  /api/check-status/{qr_hash}  # For informant kiosks
  
STAFF ENDPOINTS (Session Auth):
  POST /api/sessions/bind           # Triage: Create session
  POST /api/sessions/{id}/transfer  # Move to next station
  POST /api/sessions/{id}/override  # Custom routing
  POST /api/sessions/{id}/complete  # Finish journey
  POST /api/sessions/{id}/no-show   # Mark absent
  GET  /api/stations/{id}/queue     # Current queue at station
  
ADMIN ENDPOINTS (Admin Role):
  POST /api/programs                # Create program
  POST /api/service-tracks          # Define track
  POST /api/track-steps             # Set sequence
  POST /api/hardware-units          # Register device
  PUT  /api/hardware-units/{id}/assign  # Assign to station
  GET  /api/reports/audit           # COA compliance export
```

---

# 8. EDGE CASE HANDLING (Architect's Perspective)

## 8.1 The "Ghost Client" Problem

**Scenario:** Client leaves without notification. Staff keeps calling.

**Technical Solution:**

```
SESSION TABLE:
  - no_show_attempts: int DEFAULT 0
  
STAFF UI LOGIC:
  ON "Call Next" button press:
    INCREMENT session.no_show_attempts
    
    IF no_show_attempts == 1:
      DISPLAY: "Calling A1... (Attempt 1/3)"
      PLAY: Regular chime
    
    IF no_show_attempts == 2:
      DISPLAY: "Calling A1 AGAIN... (Attempt 2/3)"
      PLAY: Urgent chime
      
    IF no_show_attempts >= 3:
      SHOW MODAL: "Client Not Responding"
        [Mark No-Show] [Keep Waiting]
        
      IF "Mark No-Show":
        UPDATE session SET status = 'no_show'
        LOG transaction (action: 'no_show')
        UNBIND token (status = 'available')
        REMOVE from queue
        BROADCAST to global.queue (update display)
```

**Business Impact:**
- Prevents queue stagnation
- Creates accountability trail
- Allows token reuse immediately

---

## 8.2 The "Double Scan" Error

**Scenario:** Staff accidentally scans an active token again.

**Technical Solution:**

```
TRIAGE SCAN HANDLER:

  ON QR scan event:
    1. QUERY tokens WHERE qr_code_hash = {scanned}
    
    2. CHECK status:
       
       IF status == 'available':
         → PROCEED with bind flow
       
       IF status == 'in_use':
         → LOOKUP current session
         → SHOW ALERT MODAL:
            "⚠️ Token Already Active
             Alias: A1
             Station: Table 2 (Interview)
             Status: Serving
             Started: 10:35 AM
             
             [View Details] [Force End Session]"
         
       IF status == 'lost' OR 'damaged':
         → SHOW ERROR:
            "❌ Token Marked as {status}
             Please use a different token"
         → PREVENT binding

FORCE END LOGIC (Supervisor only):
  REQUIRE supervisor PIN
  LOG transaction (action: 'force_complete', reason: required)
  UNBIND token
  ALLOW re-binding
```

**Why This Matters:**
- Prevents data corruption (duplicate A1)
- Provides recovery path for edge cases
- Maintains audit trail

---

## 8.3 The "WiFi Blackout" Scenario

**Scenario:** Router loses power for 2 minutes. Clients are waiting.

**Technical Solution:**

```
FRONTEND (PWA with Service Worker):

  1. DETECT OFFLINE:
     window.addEventListener('offline', () => {
       showBanner("⚠️ Offline Mode - Actions will sync")
     })
  
  2. QUEUE ACTIONS IN IndexedDB:
     async function transfer(session_id, next_station) {
       try {
         await api.post('/sessions/transfer', {...})
       } catch (NetworkError) {
         // Store for retry
         await db.pendingActions.add({
           action: 'transfer',
           session_id,
           next_station,
           timestamp: Date.now()
         })
       }
     }
  
  3. AUTO-RETRY ON RECONNECT:
     window.addEventListener('online', async () => {
       const pending = await db.pendingActions.getAll()
       for (const action of pending) {
         try {
           await executeAction(action)
           await db.pendingActions.delete(action.id)
         } catch (error) {
           console.error('Sync failed:', action)
         }
       }
     })

BACKEND (Idempotency):
  - Use unique request IDs to prevent duplicate processing
  - Accept slightly stale timestamps (within 5 min window)
```

**Graceful Degradation:**
- ESP32 displays freeze at last known state
- Staff can manually shout numbers
- When WiFi returns, system syncs automatically

---

## 8.4 The "Rogue Hardware" Issue

**Scenario:** ESP32 button is stuck/malfunctioning, spamming "Next" signals.

**Technical Solution:**

```
BACKEND RATE LIMITER (Middleware):

  private $callAttempts = []; // In-memory cache
  
  public function handleDeviceEvent(Request $request) {
    $mac = $request->input('device_mac');
    $action = $request->input('action');
    
    IF $action == 'call_next':
      $key = "device:{$mac}:call_next";
      $attempts = Cache::get($key, 0);
      
      IF $attempts >= 3:
        // Device is flooding
        Log::alert("Rogue device detected", ['mac' => $mac]);
        
        // Auto-disable
        HardwareUnit::where('mac_address', $mac)
          ->update(['status' => 'disabled']);
        
        // Notify admin
        broadcast(new DeviceAlert($mac, 'flooding'));
        
        return response()->json(['error' => 'Rate limit'], 429);
      
      Cache::put($key, $attempts + 1, now()->addSeconds(10));
  }

ADMIN DASHBOARD:
  Real-time alert: "⚠️ Device AA:BB:CC disabled (flooding)"
  
  [View Logs] [Re-enable] [Replace Hardware]
```

**Recovery Path:**
- Admin physically inspects device
- Can re-enable after fixing
- Staff uses phone as fallback

---

## 8.5 The "Process Skipper" Violation

**Scenario:** Client walks directly to Cashier, skipping Interview.

**Technical Solution:**

```
STATION INTERFACE (Scan Handler):

  ON token scan:
    session = getSession(token_id)
    current_step = session.current_step_order
    
    my_station = getCurrentStation()
    expected_step = getExpectedStep(session.track_id, current_step + 1)
    
    IF my_station.id != expected_step.station_id:
      // VIOLATION DETECTED
      
      SHOW RED SCREEN:
        "🚫 INVALID SEQUENCE
         
         Client: A1 (PWD)
         Current Step: 2/4 (Interview)
         Expected Station: Table 2
         You Are: Table 4 (Cashier)
         
         This client has not completed:
         ✗ Interview (Required)
         
         [Send Back to Table 2] [Supervisor Override]"
      
      IF "Supervisor Override" clicked:
        REQUIRE PIN
        SHOW TEXT AREA: "Reason for override (required)"
        
        ON SUBMIT:
          LOG transaction (
            action: 'override',
            reason: {entered_text},
            staff_id: supervisor_id
          )
          ALLOW processing
    ELSE:
      // Valid sequence
      PROCEED normal
```

**Audit Benefit:**
- Every violation logged
- Supervisor accountability
- Post-event review capability

---

## 8.6 The "Token Swap" Fraud

**Scenario:** Two clients swap tokens to game priority.

**Technical Solution:**

```
STAFF INTERFACE (Identity Verification):

  WHEN serving session:
    DISPLAY PROMINENT:
      ┌─────────────────────────┐
      │ 👤 VERIFY IDENTITY      │
      ├─────────────────────────┤
      │ Alias: A1               │
      │ Category: ⭐ PWD/SENIOR │
      │                         │
      │ ⚠️ Priority clients:   │
      │ Request valid ID        │
      └─────────────────────────┘
      
      [✓ ID Verified] [❌ Mismatch]
  
  IF "Mismatch" clicked:
    SHOW FORM:
      "Describe issue:"
      [ Text area ]
      
      [Send Back to Triage] [Cancel Session]
    
    LOG transaction (
      action: 'identity_mismatch',
      remarks: {staff_notes}
    )
```

**Process Control:**
- Visual reminder for staff
- Deters fraud attempts
- Creates investigation trail

---

## 8.7 Summary: Edge Case Matrix

| Edge Case | Detection Method | Automated Response | Manual Override |
|-----------|------------------|-------------------|-----------------|
| Ghost Client | No-show counter | Auto-mark after 3 attempts | Staff can keep waiting |
| Double Scan | Token status check | Block with alert | Supervisor force-end |
| WiFi Blackout | Browser offline event | Queue in IndexedDB | Manual calling fallback |
| Rogue Hardware | Rate limiting | Auto-disable device | Admin re-enable |
| Process Skip | Step validation | Block with red screen | Supervisor PIN + reason |
| Token Swap | Visual ID prompt | Alert staff | Send to triage/cancel |

---

# 9. TECHNOLOGY STACK RATIONALE

## 9.1 Backend: Laravel (PHP)

**Decision Factors:**
- **Familiar:** You already know it
- **Batteries Included:** Auth, queues, events, ORM built-in
- **Local-First Ready:** No cloud dependencies
- **Mature Ecosystem:** Reverb (WebSockets), Horizon (job monitoring)

**Alternatives Considered:**
- Node.js (rejected: less familiar, callback complexity)
- Django (rejected: Python deployment challenges on Windows laptops)

---

## 9.2 Frontend: Svelte (via Inertia.js)

**Decision Factors:**
- **Learning Goal:** You want to learn Svelte
- **Performance:** Compiled, no virtual DOM overhead
- **Bundle Size:** Critical for slow WiFi connections
- **Laravel Integration:** Inertia.js provides seamless bridge

**Why Not:**
- React (too heavy, JSX overhead)
- Vue (similar to Svelte but larger)
- Plain HTML (too primitive for dynamic UI)

---

## 9.3 Real-Time: Laravel Reverb

**Decision Factors:**
- **Native:** Built for Laravel 12+
- **Offline:** Runs on local server
- **Cost:** Free (vs Pusher $49/month)
- **Full Duplex:** True WebSocket support

**Alternative:**
- Pusher (rejected: requires internet)
- Socket.io (rejected: Node.js dependency)

---

## 9.4 Database: MariaDB

**Decision Factors:**
- **Relational:** Complex joins needed (tracks → steps → stations)
- **ACID Compliance:** Critical for COA audit
- **Proven:** Industry standard
- **Portable:** Runs on any laptop

**Why Not:**
- MongoDB (rejected: audit trails need relational integrity)
- SQLite (rejected: concurrency issues with 30+ devices)

---

## 9.5 IoT: ESP32 (Arduino Framework)

**Decision Factors:**
- **WiFi Native:** Built-in, no external module
- **I2S Support:** Direct audio output
- **GPIO:** Buttons, LEDs, sensors
- **Cost:** ₱200-400 per unit
- **Community:** Massive Arduino ecosystem

**Alternative:**
- Raspberry Pi Zero (rejected: overkill, higher cost)
- Arduino Uno (rejected: no WiFi)

---

# 10. DEPLOYMENT & OPERATIONS

## 10.1 Hardware Requirements

### Portable Server
```
Minimum:
  - Laptop: Intel i3 / Ryzen 3 (4GB RAM)
  - Storage: 128GB SSD
  - OS: Ubuntu 22.04 LTS or Windows 10
  - WiFi: Built-in or USB adapter (support AP mode)

Recommended:
  - Laptop: Intel i5 / Ryzen 5 (8GB RAM)
  - Storage: 256GB SSD
  - Battery: 4+ hours (with power bank backup)
  - Network: Dual-band WiFi (2.4GHz for ESP32, 5GHz option)
```

### ESP32 Devices
```
MVP Focus (no ESP32 required):
  - Reuse existing staff smartphones for all station interactions
  - Optional tablet with 2D scanner for kiosk/informant display

Future Extension (post-MVP, optional):
  Per Station Setup Options:

  Option A (Full):
    - 1x ESP32-DevKit (with buttons) → ₱400
    - 1x 7" LCD Display → ₱2,500
    - 1x MAX98357A Speaker Amp → ₱150
    - 1x Power Supply (5V 2A) → ₱200
    Total: ~₱3,250

  Option B (Minimal):
    - 1x ESP32 + OLED (0.96") → ₱500
    - Staff Phone (reused) → ₱0
    Total: ~₱500
```

### Network Equipment
```
- WiFi Router (optional): TP-Link TL-WR841N → ₱800
  (Alternative: Use laptop's built-in hotspot)
- Network Switch (for wired stability): ₱600
```

---

## 10.2 Installation Procedure

```
PHASE 1: PRE-DEPLOYMENT (Office)

1. SERVER SETUP:
   - Install Ubuntu Server 22.04
   - Install: PHP 8.2, Composer, MariaDB, Node.js
   - Clone FlexiQueue repository
   - Run: composer install && npm install
   - Generate app key: php artisan key:generate
   - Create .env file (local settings)

2. DATABASE INITIALIZATION:
   - Run migrations: php artisan migrate
   - Seed default data: php artisan db:seed
   - Create admin user

3. ESP32 FIRMWARE:
   - Flash each device with station firmware
   - Configure WiFi credentials (SSID: MSWDO_FlexiQueue)
   - Test connectivity in office
   - Label devices (Table1-Display, Table1-Button, etc.)

4. TOKEN PREPARATION:
   - Generate QR codes (SHA-256 hashes)
   - Print on cardstock (A1, A2, ..., Z99)
   - Laminate for durability
   - Seed token table with hashes

PHASE 2: ON-SITE DEPLOYMENT (Barangay)

Day 1 Morning (Setup):
  08:00 - Arrive, identify power outlets
  08:30 - Position server laptop, connect to power
  09:00 - Start WiFi hotspot
  09:15 - Boot Laravel: php artisan serve & php artisan reverb:start
  09:30 - Power on ESP32 devices (auto-connect)
  09:45 - Verify all devices online (admin dashboard)
  10:00 - Assign staff to stations (web login)
  10:15 - Test flow: scan dummy token, process, complete
  10:30 - READY FOR OPERATIONS

Day 1 Operations:
  10:30 - Open gates, begin client intake
  12:00 - Lunch break (system stays online)
  14:00 - Resume operations
  17:00 - End of day
  17:15 - Export audit logs
  17:30 - Backup database (USB drive)
  17:45 - Shutdown sequence

PHASE 3: POST-EVENT

  - Generate COA report
  - Unbind all tokens (reset for next event)
  - Update firmware if needed
  - Pack equipment
```

---

## 10.3 Monitoring & Maintenance

### Health Checks
```
AUTOMATED (Every 60 seconds):

System Monitor (Laravel Command):
  php artisan flexiqueue:monitor
  
  Checks:
    ✓ Database responsive (<100ms)
    ✓ Reverb server running
    ✓ Disk space >10%
    ✓ All ESP32 heartbeats <90s old
    ✓ Active sessions < capacity
  
  Alerts:
    - Desktop notification if critical
    - Log to /var/log/flexiqueue-monitor.log

MANUAL (Hourly check by Admin):
  - Dashboard: "System Health" widget
  - Review transaction log for anomalies
  - Check queue lengths (rebalance staff if needed)
```

### Backup Strategy
```
REAL-TIME:
  - Transaction logs: Append-only (no backup needed)
  
HOURLY:
  - Database snapshot: mysqldump > backup-{timestamp}.sql
  - Store in: /mnt/usb-backup/
  
END-OF-DAY:
  - Full system backup (DB + logs + config)
  - Copy to: External HDD + Cloud upload (when internet available)
```

---

# 11. SECURITY ARCHITECTURE

## 11.1 Threat Model

```
THREATS (Local Network):
  ✗ External Internet Attacks: N/A (offline system)
  ✓ Unauthorized Device Access: Mitigated by WPA2
  ✓ Staff Privilege Abuse: Mitigated by audit logs
  ✓ Physical Theft: Mitigated by backups
  ✓ Data Tampering: Mitigated by immutable logs
```

## 11.2 Access Control

```
ROLE HIERARCHY:

Admin:
  - Configure programs/tracks
  - Assign hardware
  - View all audit logs
  - Override any action

Supervisor:
  - Approve overrides
  - Reassign staff
  - View station-specific logs

Staff:
  - Operate assigned station only
  - Basic queue actions (call, transfer)
  - Cannot delete/modify logs

Public (Informant):
  - Read-only status checks
  - No authentication required
```

## 11.3 Data Protection

```
ENCRYPTION:
  - In Transit: HTTPS (self-signed cert)
  - At Rest: NOT encrypted (offline, physical security)
  
AUDIT LOG INTEGRITY:
  - Append-only table (no UPDATE/DELETE permissions)
  - Each log has hash of previous log (blockchain-lite)
  - Periodic checksum verification
  
PRIVACY:
  - No PII stored (tokens are anonymous)
  - Session logs use alias only (A1, not "Juan Dela Cruz")
  - Compliant with Data Privacy Act
```

---

# 12. SCALABILITY & FUTURE ROADMAP

## 12.1 Current System Limits

```
Concurrent Sessions: 200 active
ESP32 Devices: 20 concurrent
Staff Users: 10 concurrent
Database Size: ~50MB per event (10,000 transactions)
Network Load: ~2Mbps peak (WebSocket + audio)
```

## 12.2 Growth Path

```
PHASE 1 (MVP - Current):
  - Web interfaces only
  - Single-site deployment
  - Manual token distribution

PHASE 2 (3-6 months):
  - Hybrid Android app (Capacitor.js)
  - Thermal printer integration (auto-print tokens)
  - SMS notifications (when cell signal available)

PHASE 3 (6-12 months):
  - Multi-site sync (USB drive replication)
  - Advanced analytics (wait time optimization)
  - Voice announcements (TTS integration)

PHASE 4 (1+ years):
  - Cloud-optional mode (sync when internet available)
  - Mobile-first redesign
  - API for third-party integrations
```

---

# 13. SUCCESS METRICS

## 13.1 Technical KPIs

```
System Performance:
  - 99% uptime during operations
  - <1s average queue update latency
  - Zero data loss incidents

Hardware Reliability:
  - <5% device failure rate
  - <2min average recovery time

User Experience:
  - <3min average onboarding time (new staff)
  - <10s token scan-to-display time
```

## 13.2 Operational KPIs

```
Efficiency:
  - 50% reduction in manual queue management time
  - 30% improvement in client throughput
  - 100% audit trail completeness

Cost:
  - <₱15,000 total hardware investment
  - ₱0 recurring costs (no cloud subscriptions)
  - <2 hours setup/teardown time
```

---

# 14. DATABASE SCHEMA

## CORE TABLES

### programs
```sql
id                BIGINT PRIMARY KEY
name              VARCHAR(100)
description       TEXT
is_active         BOOLEAN DEFAULT FALSE
created_at        TIMESTAMP
created_by        BIGINT FK→users
```

### service_tracks
```sql
id                BIGINT PRIMARY KEY
program_id        BIGINT FK→programs
name              VARCHAR(50)
description       TEXT
is_default        BOOLEAN DEFAULT FALSE
color_code        VARCHAR(7)
```

### track_steps
```sql
id                BIGINT PRIMARY KEY
track_id          BIGINT FK→service_tracks
station_id        BIGINT FK→stations
step_order        INT
is_required       BOOLEAN DEFAULT TRUE
estimated_minutes INT
```

### stations
```sql
id                BIGINT PRIMARY KEY
program_id        BIGINT FK→programs
name              VARCHAR(50)
role_type         ENUM('triage','processing','release')
capacity          INT
is_active         BOOLEAN DEFAULT TRUE
```

### hardware_units
```sql
id                BIGINT PRIMARY KEY
station_id        BIGINT FK→stations (NULLABLE)
mac_address       VARCHAR(17) UNIQUE
device_type       ENUM('esp32_combo','esp32_display','esp32_button','esp32_speaker','mobile_phone')
capabilities      JSON
status            ENUM('online','offline','disabled')
last_heartbeat    TIMESTAMP
```

**capabilities JSON format:**
```json
{
  "has_display": true,
  "display_orientation": "landscape",
  "has_buttons": true,
  "button_count": 4,
  "has_speaker": false,
  "has_microphone": false,
  "has_scanner": true
}
```

### tokens
```sql
id                BIGINT PRIMARY KEY
qr_code_hash      VARCHAR(64) UNIQUE
physical_id       VARCHAR(10)
status            ENUM('available','in_use','lost','damaged')
current_session_id BIGINT FK→sessions (NULLABLE)
```

### sessions
```sql
id                BIGINT PRIMARY KEY
token_id          BIGINT FK→tokens
program_id        BIGINT FK→programs
track_id          BIGINT FK→service_tracks
alias             VARCHAR(10)
client_category   VARCHAR(50)
current_station_id BIGINT FK→stations (NULLABLE)
current_step_order INT
status            ENUM('waiting','serving','completed','cancelled','no_show')
started_at        TIMESTAMP
completed_at      TIMESTAMP (NULLABLE)
no_show_attempts  INT DEFAULT 0
```

### transaction_logs
```sql
id                BIGINT PRIMARY KEY
session_id        BIGINT FK→sessions
station_id        BIGINT FK→stations
staff_user_id     BIGINT FK→users
action_type       ENUM('bind','check_in','transfer','override','complete','cancel','no_show')
previous_station_id BIGINT FK→stations (NULLABLE)
next_station_id   BIGINT FK→stations (NULLABLE)
remarks           TEXT (NULLABLE)
metadata          JSON
timestamp         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

**metadata JSON format:**
```json
{
  "override_reason": "Missing documents",
  "hardware_triggered": true,
  "device_mac": "AA:BB:CC:DD:EE:FF"
}
```

## SUPPORTING TABLES

### users (staff)
```sql
id                BIGINT PRIMARY KEY
name              VARCHAR(100)
email             VARCHAR(100) UNIQUE
password_hash     VARCHAR(255)
role              ENUM('admin','supervisor','staff')
assigned_station_id BIGINT FK→stations (NULLABLE)
is_active         BOOLEAN DEFAULT TRUE
created_at        TIMESTAMP
```

### device_events (hardware logs)
```sql
id                BIGINT PRIMARY KEY
hardware_unit_id  BIGINT FK→hardware_units
event_type        VARCHAR(50)
payload           JSON
timestamp         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

## RELATIONSHIPS SUMMARY

```
program (1) ──→ (M) service_tracks
program (1) ──→ (M) stations
program (1) ──→ (M) sessions

service_track (1) ──→ (M) track_steps
service_track (1) ──→ (M) sessions

track_steps (M) ──→ (1) station

station (1) ──→ (M) hardware_units
station (1) ──→ (M) sessions (current)

token (1) ──→ (1) session (current, nullable)
token (1) ──→ (M) sessions (historical)

session (1) ──→ (M) transaction_logs

users (1) ──→ (M) transaction_logs
users (1) ──→ (1) station (assigned, nullable)

hardware_unit (1) ──→ (M) device_events
```

---

## KEY CONSTRAINTS

**Business Rules:**
- Only ONE program `is_active=true` at a time
- Only ONE track per program `is_default=true`
- `track_steps.step_order` unique within track (no gaps)
- `tokens.status='in_use'` requires `current_session_id NOT NULL`
- `sessions.alias` unique among active sessions
- `transaction_logs` is append-only (no UPDATE/DELETE)

**Indexes:**
```sql
INDEX idx_sessions_active ON sessions(status, current_station_id)
INDEX idx_tokens_hash ON tokens(qr_code_hash)
INDEX idx_hardware_mac ON hardware_units(mac_address)
INDEX idx_logs_session ON transaction_logs(session_id, timestamp)
UNIQUE INDEX idx_alias_active ON sessions(alias) WHERE status IN ('waiting','serving')
```

---

## SAMPLE DATA FLOW

```sql
-- 1. Create program
INSERT INTO programs (name, is_active) 
VALUES ('Cash Assistance', TRUE);

-- 2. Create tracks
INSERT INTO service_tracks (program_id, name, is_default) 
VALUES (1, 'Regular', TRUE), (1, 'Priority', FALSE);

-- 3. Define steps
INSERT INTO track_steps (track_id, station_id, step_order) 
VALUES (1, 1, 1), (1, 2, 2), (1, 3, 3); -- Regular: Triage→Interview→Cashier

-- 4. Bind token
UPDATE tokens SET status='in_use', current_session_id=101 WHERE id=1;
INSERT INTO sessions (token_id, track_id, alias, status) 
VALUES (1, 1, 'A1', 'waiting');

-- 5. Transfer
UPDATE sessions SET current_station_id=2, current_step_order=2 WHERE id=101;
INSERT INTO transaction_logs (session_id, action_type, next_station_id) 
VALUES (101, 'transfer', 2);

-- 6. Complete
UPDATE sessions SET status='completed', completed_at=NOW() WHERE id=101;
UPDATE tokens SET status='available', current_session_id=NULL WHERE id=1;
```

---

**Total Tables:** 10  
**Pivot Tables:** track_steps (links tracks to stations)  
**Audit Table:** transaction_logs (immutable)

---

# 15. UI/UX DESIGN SPECIFICATION

**Framework:** Svelte + TailwindCSS  
**Target:** AI Agent to generate mockup code  
**Output:** 4 interactive web pages

---

## DESIGN SYSTEM

### Colors
```
Primary: #2563EB (blue)
Success: #16A34A (green)
Warning: #EA580C (orange)
Error: #DC2626 (red)
Priority: #F59E0B (gold)
Gray: #6B7280
White: #FFFFFF
```

### Typography
```
Font: Inter, sans-serif
Sizes: 72px (alias), 36px (h1), 24px (h2), 16px (body)
```

### Spacing
```
Use 4px grid system
Button height: 80px (primary), 48px (secondary)
Card padding: 24px
```

---

## SCREEN 1: TRIAGE INTERFACE

**Purpose:** Reception staff scans QR, selects category, binds client

**Layout (Mobile 375px):**
```
Header (blue background, white text):
  - Logo "⚡ FlexiQueue | Triage"
  - Program name: "Cash Assistance"
  - Staff name + Logout button

Camera Section (480px height):
  - Large text: "📷 SCAN QR CODE"
  - Camera viewfinder (centered square 300x300)
  - Below: Two small buttons "Manual Entry" | "Recent"

Category Selection (appears after scan):
  - Text: "TOKEN SCANNED: A1" (large, 36px)
  - Text: "Select Client Category:" (24px)
  
  Three large buttons (full width, 120px each, 16px gap):
  1. "👤 REGULAR" (white bg, gray border)
     Subtext: "Standard Processing"
  
  2. "⭐ PWD / SENIOR / PREGNANT" (gold bg #F59E0B)
     Subtext: "Express Lane"
  
  3. "⚠️ INCOMPLETE DOCUMENTS" (orange bg #EA580C)
     Subtext: "Legal Assistance Required"

Bottom:
  - Cancel button (gray) | Confirm button (green, disabled until selection)

Footer (gray bar):
  - "🟢 Online | Queue: 12 | Processed: 45 | 15:34"
```

**States to show:**
- Default (camera ready)
- After scan (category buttons visible)

---

## SCREEN 2: STATION INTERFACE

**Purpose:** Staff processes clients, calls next, routes flow

**Layout (Mobile 375px):**
```
Header:
  - "Table 2 - Interview" (h1)
  - Staff name + Menu icon

Current Client Card (white, shadow, rounded):
  - "NOW SERVING" (gray text)
  - "A1" (72px, bold, centered)
  - "⭐ PWD / Senior" (gold badge)
  - "🕐 Started: 10:35 AM"
  - "⏱ Duration: 3m 45s"
  - Progress bar: "Step 2 of 3: Interview" (67% filled blue bar)

Primary Action (green button, 80px height):
  - "✓ SEND TO CASHIER"
  - Subtext: "(Table 4 - Next Step)"

Secondary Actions (row of 3 buttons, 48px each):
  - "↻ Re-queue" | "⚠️ Override" | "🎤 Talk"

Bottom Button (gray):
  - "❌ Mark No-Show (0/3)"

Queue Preview (list):
  - "QUEUE (Next 5):"
  - 5 rows: "1. B3  Regular  🕐 2m"
  - "View All" button

Footer: same as Triage
```

**Empty State (no client):**
```
Replace client card with:
  - "NO CLIENT ACTIVE" (centered gray text)
  - Large blue button (100px height): "🔔 CALL NEXT CLIENT"
  - Subtext: "(B3 is ready)"
```

**Override Modal (popup):**
```
White card, centered, 90% width, rounded corners:
  - Title: "⚠️ OVERRIDE STANDARD FLOW"
  - Radio buttons (4 options):
    ○ Table 1 - Verification
    ○ Table 3 - Legal Assistance
    ○ Table 4 - Cashier
    ○ Table 5 - Manager
  - Text area: "Reason (required):" with placeholder
  - Cancel (gray) | Confirm (blue) buttons
```

---

## SCREEN 3: INFORMANT DISPLAY

**Purpose:** Clients check status, see who's being served

**Layout (Portrait kiosk 768x1024):**
```
Header (blue):
  - "⚡ FlexiQueue"
  - "Cash Assistance Distribution"
  - Date

Scan Section (400px height):
  - "🔍 CHECK YOUR STATUS"
  - Large touch area with QR icon
  - Text: "TAP TO SCAN QR CODE"

After Scan (replace scan section):
  - "YOUR STATUS:" (h2)
  - "A1" (72px, bold)
  - "⭐ Priority" (gold badge)
  - Progress steps (3 rows):
    "✓ Triage - Complete" (gray)
    "→ Interview - IN PROGRESS" (blue, bold)
    "○ Cashier - Waiting" (light gray)
  - "📍 Currently at: Table 2"
  - "⏱ Wait time: ~5 minutes"
  - "✓ OK, GOT IT" button (green)

Always Visible - Now Serving (4 cards in 2x2 grid):
  - "🔔 NOW SERVING"
  - Each card (white, shadow):
    "A1 → Table 2"
    "Interview"

Waiting Area (list):
  - "⏳ CURRENTLY WAITING:"
  - "Table 1: A8, B5, C2 (3 clients)"
  - Repeat for 4 tables
  - "Total in queue: 8"
```

---

## SCREEN 4: ADMIN DASHBOARD

**Purpose:** Monitor system, configure programs

**Layout (Desktop 1440px):**
```
Left Sidebar (240px, gray bg):
  - Logo at top
  - Menu items:
    📊 Dashboard
    📋 Programs
    🖥️ Devices
    📈 Reports
    ⚙️ Settings

Main Content:
  - Header: "⚡ FlexiQueue Admin Dashboard"
  - User + Logout (top right)

System Health (4 cards in row):
  Card format (white, shadow, centered):
  - Number (48px): "42"
  - Label: "Active Sessions"
  - (Repeat for: Devices Online, Queue Waiting, Uptime)

Active Program Section:
  - Title: "ACTIVE PROGRAM: Cash Assistance"
  - 3 rows:
    "Track A (Regular): 15 clients"
    "Track B (Priority): 8 clients ⭐"
    "Track C (Incomplete): 4 clients ⚠️"

Station Status Table:
  - Columns: Station | Staff | Queue | Devices
  - 4 rows with data
  - Example: "Table 1 (Verify) | Juan Cruz | 3 | 🟢🟢"

Bottom buttons:
  - "+ Add Station" | "⚠️ Configure Flow" | "📊 Reports"
```

**Device Manager Modal:**
```
White overlay, centered card:
  - Title: "🖥️ HARDWARE DEVICES" + "+ Register New"
  - Table (5 columns):
    Device | MAC Address | Station | Status | Actions
  - 5 rows with data
  - Example: "ESP32-Display-1 | AA:BB:CC:DD:EE:01 | Table 1 | 🟢 Online"
  
  Capabilities box (bottom):
  - "CAPABILITIES DETECTED (Table 1):"
  - ✓ Display (Portrait, 7")
  - ✓ Physical Buttons
  - ✗ Speaker (warning message)
```

---

## COMPONENT SPECIFICATIONS

### Button Styles
```css
Primary: bg-blue-600 text-white py-4 px-6 rounded-lg text-lg font-semibold
Secondary: bg-gray-200 text-gray-900 py-3 px-6 rounded-lg
Success: bg-green-600 text-white py-4 px-6 rounded-lg
Danger: bg-red-600 text-white py-3 px-6 rounded-lg
```

### Card Styles
```css
Elevated: bg-white shadow-lg rounded-xl p-6
Status badge: px-3 py-1 rounded-full text-sm font-medium
```

### Input Styles
```css
Text/Select: border border-gray-300 rounded-lg px-4 py-3 w-full
Textarea: border border-gray-300 rounded-lg px-4 py-3 min-h-24
Radio: w-5 h-5 text-blue-600
```

---

## INTERACTION NOTES

1. **All buttons:** Hover shows darker shade, active shows pressed state
2. **Modals:** Dark overlay (bg-black/50), centered card with close X
3. **Loading:** Show skeleton screens (gray animated bars)
4. **Responsive:** Mobile-first, stack columns on <768px
5. **Icons:** Use emoji or simple SVG icons

---

## DELIVERABLE REQUEST

Generate 4 Svelte pages using this spec:
- `Triage.svelte`
- `Station.svelte` 
- `Informant.svelte`
- `Admin.svelte`

Use TailwindCSS classes as specified. Make buttons clickable with basic state changes (e.g., category selection highlights button, modal opens/closes). No backend needed - use placeholder data.

**Focus on:** Large touch targets, high contrast, clean spacing, professional appearance.

---

# APPENDIX A: GLOSSARY

| Term | Definition |
|------|------------|
| **Program** | A government assistance event (e.g., pension distribution) |
| **Service Track** | A demographic-specific pathway through the program |
| **Station** | A logical service point (e.g., verification desk) |
| **Token** | A physical QR card that clients carry |
| **Session** | A client's journey bound to a token |
| **Bind** | Linking a token to a new session |
| **Unbind** | Releasing a token after session completion |
| **Override** | Staff manually changing the standard flow |
| **Capability** | What a hardware unit can do (display, buttons, etc.) |

---

# APPENDIX B: SYSTEM ARCHITECT'S SIGN-OFF

```
This architecture document defines a production-ready system that:

✓ Solves the stated problem (flexible queue management)
✓ Meets all constraints (offline, portable, audit-ready)
✓ Handles identified edge cases
✓ Scales within reasonable limits
✓ Uses appropriate, proven technologies

Next Steps:
  1. Stakeholder review (MSWDO, advisors)
  2. API contract definition
  3. Sprint planning for MVP

Architect: [System Architect Role]
Date: [Current Date]
Version: 1.0
```

---

**END OF SYSTEM ARCHITECTURE DOCUMENT**

---

Would you like me to **expand any specific section** (e.g., detailed API contracts, database migration scripts, or ESP32 firmware architecture)? Or shall we move to **visual diagrams** (sequence diagrams, component diagrams)?