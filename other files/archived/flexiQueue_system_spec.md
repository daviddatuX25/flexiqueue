# FlexiQueue: Detailed System Specifications

---

## 1. SYSTEM OVERVIEW

**System Name:** FlexiQueue  
**Version:** 1.0  
**Type:** Web-based Queue Management System with IoT Integration  
**Architecture:** Offline-first, Progressive Web Application (PWA)  
**Deployment Model:** On-premises (LGU Local Server)

---

## 2. FUNCTIONAL REQUIREMENTS

### 2.1 User Roles and Access Levels

#### 2.1.1 System Administrator
**Capabilities:**
- Create and manage programs
- Configure process types (master catalog)
- Assign process types to programs
- Create and configure tables
- Assign staff to roles and programs
- Configure table capabilities (which processes each table can handle)
- Generate stub batches
- View system-wide analytics and reports
- Manage user accounts and permissions
- Register physical devices (smartphones, tablets, IoT) into device inventory
- Assign/reassign devices to programs, tables, and roles
- View device status, health, and assignment history
- Monitor device uptime and connectivity
- Configure IoT device pairing
- Access comprehensive audit logs

#### 2.1.2 Program Administrator
**Capabilities:**
- Configure program-specific settings
- Define required processes for their program
- Set flow rules and dependencies
- Manage table assignments within their program
- Monitor program-specific queues
- Generate program reports
- View program audit logs

#### 2.1.3 Routing Staff (Queue Routing Device Operator)
**Capabilities:**
- Log into any available QRD device using credentials
- Device automatically assumes QRD role upon staff login
- Scan stubs using Queue Routing Device (QRD)
- Initiate stub routing to active programs and define initial flow
- Confirm stubs that are pre-registered
- Select or confirm process flow for new stubs
- View table capacities and availability
- Override flow rules (with reason logging)
- View client stub history
- Log out (device returns to unassigned state or remains paired to program)

#### 2.1.4 Processing Staff (Queue Processing Device Operator)
**Capabilities:**
- Log into any available QPD device at their assigned table
- Device automatically shows queue for their current table
- Scan stubs at their table using QPD
- View assigned processes for each stub
- Mark processes as IN_PROGRESS
- Mark processes as COMPLETED
- Redirect stubs to other tables (if needed, using flag/reroute buttons)
- Handle multiple clients concurrently (up to table limit)
- View queue for their table
- Operate QPD for calling clients (when IoT display attached)
- Log out (device can be used by another staff member)

#### 2.1.5 Client/Citizen
**Capabilities:**
- Receive stub at program entry
- Use Queue Informant Device to:
  - Scan stub and view current status
  - See assigned table
  - View pending processes
  - View completed processes
  - Check estimated position in queue

---

### 2.2 Core Modules

#### 2.2.1 Stub Management Module

**FR-SM-001: Stub Generation**
- System shall generate stubs in configurable batches
- Each stub shall have unique UUID
- Each stub shall have randomly generated alias (DSWD-suited format)
- System shall generate QR code encoding stub UUID
- Stubs shall exist independently of any program
- Batch size: 1 to 10,000 stubs per batch

**FR-SM-002: Stub Registration**
- Routing staff can scan unregistered stub
- System shall allow staff to select active program for registration
- System shall create STUB_PROGRAM record linking stub to program
- System shall auto-generate required STUB_PROCESS_STATUS records based on PROGRAM_PROCESS configuration
- Registration timestamp shall be recorded
- Registering staff ID shall be logged

**FR-SM-003: Stub Lifecycle**
- Status transitions: UNREGISTERED → REGISTERED → IN_PROGRESS → COMPLETED → EXPIRED
- Stubs registered to a program shall auto-expire when program end_date is reached
- Expired stubs can be reset and reused for future programs
- System shall maintain complete history even after expiration

**FR-SM-004: Stub Alias Generation (DSWD-Suited Format)**
- Format: [Beneficiary Category] [Service Type] [4-Digit Number]
- Beneficiary Category pool (minimum 20):
  - Senior, PWD, Youth, Family, Child, SoloParent, Indigenous, Emergency, Worker, Scholar
- Service Type pool (minimum 20):
  - Assistance, Support, Care, Services, Benefits, Program, Relief, Aid, Grant, Registration
- Number range: 0001-9999 (4-digit for better visibility and readability)
- Uniqueness enforced within active stubs only
- Example formats:
  - "Senior Assistance 0042"
  - "PWD Support 1157"
  - "Family Care 2089"
  - "Youth Benefits 0333"
  - "Child Relief 0501"
- Alternative simple format (optional): "ASS-0042" (abbreviation + number)
- Design goal: Easy pronunciation, memorable, context-aware, no cultural bias, suitable for non-technical staff
- System shall allow configuration of category and service type pools per DSWD office

---

#### 2.2.2 Program Management Module

**FR-PM-001: Program Creation**
- Admin can create new program with:
  - Program name (required)
  - Description (optional)
  - Start date (required)
  - End date (optional but recommended)
  - Active status (boolean)
- Programs can be activated/deactivated

**FR-PM-002: Process Type Assignment**
- Admin can assign process types from master catalog to program
- For each assigned process type, configure:
  - Required or optional (boolean)
  - Sequence order (integer, for suggested flow)
- Multiple process types can have same sequence order (parallel processes)

**FR-PM-003: Flow Rule Definition**
- Admin can create flow rules: FROM process → TO process
- Condition types:
  - AUTO: System automatically routes
  - MANUAL: Staff decides
  - CONDITIONAL: Based on criteria (future enhancement)
- Each flow rule has `can_override` flag
- Staff can override if flag is true, with mandatory reason logging

**FR-PM-004: Program Analytics**
- View total stubs registered
- View stubs by status (in progress, completed, expired)
- Average processing time per process type
- Table utilization rates
- Staff performance metrics

---

#### 2.2.3 Device Management Module

**FR-DM-001: Device Registration and Inventory**
System maintains central device registry where all physical devices are registered.

Device Types:
- **QPD** (Queue Processing Device): Smartphones, tablets for processing staff at tables
- **QRD** (Queue Routing Device): Smartphones, tablets for routing staff at intake
- **QID** (Queue Informant Device): Tablets, kiosks for clients to check status
- **IoT Display**: Passive displays (ESP32-based) paired to tables

Registration Process:
1. Admin registers device via admin panel or device self-registers
2. System generates unique device UUID
3. Physical attributes recorded (model, MAC address, location)
4. Device added to inventory with status: REGISTERED

Device Attributes:
- device_uuid (unique identifier, generated at registration)
- device_type (QPD, QRD, QID, IOT_DISPLAY)
- device_model (e.g., Samsung Galaxy Tab, iPhone 12, Kiosk-X1)
- mac_address (physical network identifier)
- ip_address (current network IP)
- physical_location (e.g., "Table 2", "Main Entrance", "Waiting Area")
- active (boolean, soft-delete capability)
- registered_at (timestamp of registration)
- last_seen (timestamp of last connection)

**FR-DM-002: Device Assignment System**
Devices are dynamically assigned to programs/tables/roles. Assignments are independent of device registration.

Assignment Model:
- Link: Device → Program → Table (optional) → Role
- Assignment states: ACTIVE, INACTIVE
- History preserved: assignment_id with assigned_at, unassigned_at timestamps
- Audit trail: assigned_by_staff_id logs who made the assignment

Assignment Examples:
- Tablet-001 → AICS Program → Table 2 → Processing Role (QPD)
- Phone-005 → 4Ps Program → No table → Routing Role (QRD)
- Kiosk-002 → Multi-program (all programs) → No table → Informant Role (QID)
- IoT Display-10 → Feeding Program → Table 4 → Display Role (IoT Display)

Key Features:
- **Program Flexibility**: When a program ends, device is reassigned to new program instantly
- **Location Agility**: Admin can move device between tables without re-registering
- **Instant Switching**: No downtime or re-pairing required for reassignments
- **Audit Trail**: Full history of device assignments and role changes

**FR-DM-003: Device Sessions (Staff-Operated Devices)**
For QPD/QRD: Staff login creates a session. Devices are not permanently assigned to individuals.

Session Workflow for Staff Devices (QPD/QRD):
1. Staff opens QPD/QRD app on any available device
2. Enters credentials (username/password)
3. System validates and checks device's active assignment
4. Creates DEVICE_SESSION record with:
   - device_id (which device they logged into)
   - staff_id (who logged in)
   - session_token (unique token for this session)
   - session_start (login timestamp)
   - last_activity (updated on each interaction)
5. Staff performs tasks (scan stubs, process clients)
6. Staff logs out → session_end recorded
7. Device is now available for next staff member
8. Session history preserved for auditing

Session Timeout:
- Auto-logout after 30 minutes of inactivity
- Session token expires after logout

Benefits:
- Multiple staff can use same device throughout day
- No device re-pairing needed between staff
- Clear audit trail of who did what on which device
- Flexible staff scheduling without device bottlenecks

**FR-DM-004: Device Pairing (IoT Devices)**
For non-staff devices (QID, IoT Display): Pairing instead of login.

Pairing Process:
1. IoT device broadcasts UUID over local WiFi
2. Admin sees device in "Available Devices" panel
3. Admin clicks "Assign" to device
4. Admin selects: Program, Table (if applicable), Role
5. System sends assignment config to device via MQTT
6. Device receives config, stores in flash memory
7. Device subscribes to appropriate MQTT topics
8. Device can now receive queue updates and display info
9. Device can be remotely reassigned without physical interaction

Re-pairing:
- Admin can reassign device to different program/table instantly
- No need to re-hold pair button
- Device maintains network connection and updates config automatically

**FR-DM-005: Device Health Monitoring**
Admin dashboard shows device status and health.

Monitored Metrics:
- **Connection Status**: ONLINE, OFFLINE, DEGRADED
- **Last Seen**: Timestamp of last contact with server
- **Battery Level** (for mobile devices)
- **Network Signal Strength** (RSSI)
- **Session Activity** (current logged-in user, session duration)
- **Assignment Status** (current program, table, role)

Alerts:
- Device offline > 5 minutes
- Battery critical (< 10%)
- Network signal weak (< -80 dBm)
- Unpaired IoT devices available

**FR-DM-006: Multi-Program Support**
Devices assigned to one program at a time, with instant reassignment capability.

Use Case Workflow:
```
Morning (8 AM):
  QPD-002 assigned to "Feeding Program 2025" at Table 1
  Staff login and process clients

Afternoon (1 PM):
  Program manager reassigns QPD-002 to "4Ps Program" at Table 3
  Assignment happens instantly, no downtime
  Next staff member logs in, sees 4Ps queue

QID/IoT Display (client-facing):
  Optional: Can show ALL programs simultaneously
  Clients scan stub to see which program/queue they're in
```

Device Behavior by Type:

**QPD (Processing):**
- Staff login required for operation
- Displays current program and table's queue
- Staff can only see/process stubs for their assigned program
- Auto-logout after 30 min inactivity

**QRD (Routing):**
- Staff login required for operation
- Can process new stubs across multiple active programs
- Staff selects program during intake
- Auto-logout after 30 min inactivity

**QID (Informant):**
- No login required (read-only)
- Can be assigned to single program OR all programs
- Clients scan stub and see status
- Auto-logout after 30 sec inactivity

**IoT Display:**
- Paired, not logged-in
- Receives queue updates via MQTT
- Shows queue and current client for assigned table
- Operates offline with cached data

---

#### 2.2.4 Table Management Module

**FR-TM-001: Table Configuration**
- Admin creates tables under specific program
- Table properties:
  - Table name/number (e.g., "Table 1", "Verification Counter")
  - Maximum client seats (1-20)
  - Active status
  - Program assignment (one program at a time)

**FR-TM-002: Table Process Capabilities**
- Admin assigns which process types each table can handle
- A table can handle multiple process types
- Staff at table can only work on processes their table supports
- System validates table capability before allowing stub assignment

**FR-TM-003: Staff Assignment for Tracking (Not Device Binding)**
- Admin assigns staff to tables for operational tracking
- One staff can be assigned to one table at a time
- Staff reassignment allowed with audit logging
- Table can have multiple staff assigned (shifts)
- **NOTE**: Staff assignment to table is for context, not device binding
- Staff login to QPD device shows their assigned table's queue
- Multiple staff can use same QPD device (sequential or via logout)

**FR-TM-004: Queue Device Assignment (Dynamic via DEVICE_ASSIGNMENT)**
- Devices are assigned to tables via DEVICE_ASSIGNMENT table
- Admin can assign multiple devices to one table
- Admin can reassign device from one table to another instantly
- Device assignment includes:
  - device_id (which device)
  - table_id (which table it serves)
  - program_id (which program's context)
  - assignment_role (QPD, QRD, QID role)
- Devices display stubs assigned to their table
- Devices can call next stub from waiting queue

---

#### 2.2.5 Process Flow Engine

**FR-PF-001: Process Status Tracking**
- Each stub has multiple STUB_PROCESS_STATUS records
- Status transitions: WAITING → IN_PROGRESS → COMPLETED
- Only one process can be IN_PROGRESS per table per stub
- Multiple processes can be IN_PROGRESS if at different tables (parallel processing)

**FR-PF-002: Flow Evaluation**
- When process marked COMPLETED, system evaluates flow rules
- If AUTO flow exists, suggest next process
- If MANUAL, staff selects next process
- If all required processes COMPLETED, stub marked COMPLETED

**FR-PF-003: Override Capability**
- Staff can override flow rules if `can_override = true`
- Override actions:
  - Skip process (mark as SKIPPED)
  - Change sequence
  - Add unplanned process
- All overrides require reason (text field)
- Overrides logged with timestamp and staff ID

**FR-PF-004: Parallel Processing Support**
- Stub can have multiple processes IN_PROGRESS simultaneously
- Constraint: Different tables only
- Example: Form A at Table 1, Interview at Table 2 (same stub, same time)
- System prevents double-booking at same table

---

#### 2.2.6 Queue Shaping Module

**FR-QS-001: Dynamic Queue Generation**
- Queues are database views, not stored tables
- Queue formula:
  ```sql
  SELECT stub_program_id, alias_name
  FROM STUB_PROCESS_STATUS sps
  JOIN STUB_PROGRAM sp ON sps.stub_program_id = sp.id
  JOIN STUB s ON sp.stub_id = s.stub_id
  WHERE sps.process_type_id = [TARGET_PROCESS]
  AND sps.status = 'WAITING'
  AND sps.assigned_table_id = [TABLE_ID]
  ORDER BY sp.registered_at ASC
  ```

**FR-QS-002: Queue Display Logic**
- Each table displays its own queue
- Shows next 5-10 stubs in waiting status
- Highlights currently being served stubs
- Updates in real-time via WebSocket

**FR-QS-003: Capacity-Based Routing**
- When routing staff assigns stub to table, system checks:
  - Table has available seats (not all occupied)
  - Table supports required process types
  - Table is active
- If table full, suggest alternative tables with availability

**FR-QS-004: Load Balancing (Optional Enhancement)**
- System can suggest least-busy table for a process type
- Metrics: current queue length, average processing time
- Routing staff can accept or override suggestion

---

#### 2.2.7 Device Interface Module (Smartphone-First + IoT Variants)

**FR-DI-001: Queue Processing Device (QPD) Smartphone Interface**
- Primary interface for processing staff at tables
- Upon stub scan (via QR/barcode):
  - Display stub alias prominently
  - Show stub program and current status
  - List assigned processes for the table
  - Display action buttons:
    - ✓ Mark IN_PROGRESS
    - ✓ Mark COMPLETED
    - 🚩 FLAG (mark for review/escalation)
    - ↻ REROUTE (send to different table with reason)
    - ⏸ HOLD (suspend processing temporarily)
  - Show current client seat status and queue length
- Optional IoT hardware integration (if QPD-R or QPD-S paired):
  - QPD-R (Receiver): Non-touchscreen display showing currently served stubs; can trigger audio announcements
  - QPD-S (Sender): Non-touchscreen display with physical buttons or microphone; staff uses to control without touching phone
  - Both synced with smartphone via MQTT; smartphone remains primary control

**FR-DI-002: Queue Routing Device (QRD) Smartphone Interface**
- Primary interface for routing staff at intake point
- Upon stub scan:
  - If unregistered:
    - Display program list (active programs only)
    - Allow staff to select program or use suggested default
    - Display auto-suggested flow based on program config
    - Allow manual flow selection or override
    - Confirm registration and initial routing
  - If pre-registered:
    - Confirm routing to predetermined program and flow
    - Allow override with mandatory reason logging
  - Confirmation screen shows table assignment and process sequence
- Optional IoT touchscreen variant (QRD-IoT):
  - Same interface as smartphone version
  - Standalone touchscreen with integrated camera
  - Pair button to register with Orange Pi server
  - Can operate independently at routing station without needing staff smartphone

**FR-DI-003: Queue Informant Device (QID) Client Interface**
- Self-service client-facing display (smartphone, tablet, or IoT kiosk)
- Upon stub scan:
  - Display stub alias prominently (large, easy to read)
  - Show current program name
  - Show assigned table number and current process
  - Display full process checklist with visual indicators:
    - ✅ Completed processes (green checkmark)
    - ⏳ Currently processing (yellow hourglass)
    - ⏸ Waiting processes (gray circle)
  - Show estimated position in queue ("You are #3 in queue")
  - Display helpful instructions in local language
  - Optional: Show estimated wait time
- No interactive buttons or actions available (read-only)
- Auto-logout after 30 seconds of inactivity
- Large touch targets for ease of use

---

#### 2.2.8 Audit & Reporting Module

**FR-AR-001: Comprehensive Audit Logging**
- All significant actions logged:
  - Stub registration
  - Process status changes
  - Table assignments
  - Flow overrides
  - Staff actions
  - Device connections (pairing, disconnection)
- Log fields:
  - Timestamp (exact date-time)
  - Actor (staff ID or device ID)
  - Action type
  - Target (stub ID, process ID, table ID)
  - Before state
  - After state
  - Reason (for overrides)
  - IP address (for web actions)

**FR-AR-002: Audit Log Access**
- System admin: Full access to all logs
- Program admin: Access to their program logs only
- Logs are immutable (no deletion, no editing)
- Logs retained for minimum 2 years for COA compliance

**FR-AR-003: Reporting**
- Daily summary reports:
  - Total stubs processed
  - Average processing time per process
  - Table utilization percentage
  - Staff performance (stubs handled)
- Program completion reports:
  - Total beneficiaries served
  - Process completion breakdown
  - Bottleneck identification
- Export formats: PDF, CSV, Excel

---

### 2.3 Non-Functional Requirements

#### 2.3.1 Performance Requirements

**NFR-P-001: Response Time**
- Web page load: < 2 seconds
- QR scan to display: < 1 second
- Queue updates via WebSocket: Real-time (< 100ms latency)
- Database queries: < 500ms for 95th percentile

**NFR-P-002: Concurrent Users**
- System shall support minimum 50 concurrent users
- LAN-based deployment, no internet bottleneck
- WebSocket connections: Minimum 100 concurrent connections

**NFR-P-003: Data Volume**
- Minimum 100,000 stubs storable
- Minimum 1,000 programs
- Minimum 50 tables
- Audit logs: Minimum 1 million records

**NFR-P-004: IoT Device Latency**
- MQTT message delivery: < 200ms
- Display update after event: < 500ms
- Audio announcement trigger: < 1 second

---

#### 2.3.2 Reliability Requirements

**NFR-R-001: Uptime**
- Target uptime: 99% during operating hours (8AM-5PM)
- Graceful degradation: If WebSocket fails, fall back to polling (every 5 seconds)

**NFR-R-002: Data Integrity**
- Database transactions for critical operations
- Foreign key constraints enforced
- Audit logs write-once (immutable)

**NFR-R-003: Offline Operation**
- System must function on LAN without internet
- Synchronization (if multi-site in future): Eventual consistency model
- Local data persistence: SQLite or PostgreSQL

**NFR-R-004: Fault Tolerance**
- IoT device disconnection: Queue device falls back to manual calling
- Power interruption recovery: Auto-resume from last known state
- Database backup: Daily automated backups

---

#### 2.3.3 Usability Requirements

**NFR-U-001: User Interface**
- Responsive design: Works on desktop, tablet, smartphone
- Minimum supported screen size: 5 inches (smartphone)
- Maximum clicks to complete action: 3 clicks
- Touch-friendly buttons: Minimum 44x44 pixels

**NFR-U-002: Language Support**
- Primary: English
- Secondary: Filipino (Taglish labels acceptable for Phase 1)
- Future: Cebuano, Ilocano (configurable)

**NFR-U-003: Accessibility**
- High contrast mode for visually impaired
- Large text option (minimum 16px base font)
- Audio announcements for Queue Device (screen reader compatible)

**NFR-U-004: Training Requirements**
- Admin training: Maximum 4 hours
- Staff training: Maximum 2 hours
- Citizen instructions: Visual guide posted at informant device (no training needed)

---

#### 2.3.4 Security Requirements

**NFR-S-001: Authentication**
- Admin and staff: Username/password authentication
- Password requirements:
  - Minimum 8 characters
  - At least one number
  - At least one special character
- Session timeout: 30 minutes of inactivity

**NFR-S-002: Authorization**
- Role-based access control (RBAC)
- Principle of least privilege
- Action-level permissions (e.g., can_override, can_create_program)

**NFR-S-003: Data Privacy**
- Stubs use aliases, no personal identifiable information (PII) in public displays
- Audit logs encrypted at rest
- HTTPS enforced for web interface (self-signed cert acceptable for LAN)

**NFR-S-004: Network Security**
- LAN-only deployment (no public internet exposure)
- Firewall rules: Only necessary ports open (80/443 for web, 1883 for MQTT)
- Device authentication: MAC address whitelisting for IoT devices

---

#### 2.3.5 Maintainability Requirements

**NFR-M-001: Code Documentation**
- Inline comments for complex logic
- API documentation using OpenAPI/Swagger
- README files for setup and deployment

**NFR-M-002: Database Schema**
- Normalized to 3NF (Third Normal Form)
- Migration scripts for version upgrades
- Schema documentation with ERD diagrams

**NFR-M-003: Modularity**
- Separate concerns: Backend API, Frontend UI, IoT Layer
- Configurable via environment variables (.env file)
- No hardcoded values (e.g., table limits, stub format)

**NFR-M-004: Update Mechanism**
- Version numbering: Semantic versioning (e.g., 1.0.0)
- Update process: Database migration + code deployment
- Rollback capability for failed updates

---

## 3. TECHNICAL SPECIFICATIONS

### 3.1 Technology Stack

#### 3.1.1 Backend
**Option A: Laravel (PHP Framework)**
- Version: Laravel 10.x or 11.x
- PHP Version: 8.1+
- Web Server: Apache or Nginx
- Features used:
  - Eloquent ORM for database
  - Laravel Queue for background jobs
  - Laravel Echo for WebSocket broadcasting
  - Laravel Sanctum for API authentication

**Option B: FastAPI (Python Framework)**
- Version: FastAPI 0.100+
- Python Version: 3.10+
- ASGI Server: Uvicorn
- Features used:
  - SQLAlchemy ORM
  - FastAPI WebSocket support
  - Pydantic for data validation
  - JWT authentication

**Recommendation:** Laravel (more familiar in Philippine dev community)

---

#### 3.1.2 Frontend
**Progressive Web App (PWA)**
- Framework: Vue.js 3 or Svelte
- UI Library: Tailwind CSS
- Icons: Lucide Icons or Heroicons
- QR Scanner: html5-qrcode library
- State Management: Pinia (Vue) or Svelte stores
- Build Tool: Vite

**Key Features:**
- Service Worker for offline caching
- Installable on smartphones/tablets
- Responsive design (mobile-first)
- Camera access for QR scanning

---

#### 3.1.3 Database
**Option A: PostgreSQL**
- Version: 14+
- Supports JSON fields for flexible metadata
- Robust transaction support
- Good for production deployments

**Option B: SQLite**
- Version: 3.40+
- Zero-configuration
- Single-file database
- Good for small to medium deployments

**Recommendation:** PostgreSQL for production, SQLite for development/testing

---

#### 3.1.4 Real-Time Communication

**WebSocket Server:**
- Laravel: Laravel Echo Server + Socket.io
- FastAPI: Native WebSocket support
- Protocol: WS (WebSocket) over LAN

**MQTT Broker (for IoT):**
- Mosquitto MQTT Broker
- Version: 2.0+
- Port: 1883 (non-TLS acceptable for LAN)
- Topics structure:
  ```
  flexiqueue/table/{table_id}/call
  flexiqueue/table/{table_id}/status
  flexiqueue/informant/update
  ```

---

#### 3.1.5 IoT Components

**Microcontroller:**
- ESP32 DevKit v1
- Features: WiFi, Bluetooth, GPIO pins
- Programming: Arduino IDE or PlatformIO
- Libraries:
  - PubSubClient (MQTT)
  - ArduinoJson (JSON parsing)
  - TFT_eSPI (for displays)

**Display Options:**
- Option 1: 16x2 LCD with I2C module (simple, cheap)
- Option 2: 2.8" TFT LCD (SPI, colorful)
- Option 3: LED Matrix Display (large, visible from distance)

**Audio:**
- DFPlayer Mini MP3 module + Speaker
- Pre-recorded audio files: "Now serving [alias] at Table [number]"
- Alternative: Text-to-Speech via web API called from server

**Barcode Scanner (for Informant Device):**
- USB Barcode Scanner (plug-and-play)
- Alternative: Camera-based using Raspberry Pi

---

### 3.2 Database Schema (Detailed)

#### 3.2.1 Tables and Fields

**STUB**
```sql
CREATE TABLE stub (
    stub_id VARCHAR(36) PRIMARY KEY,  -- UUID
    alias_name VARCHAR(50) UNIQUE NOT NULL,
    qr_code TEXT NOT NULL,  -- Base64 or URL to QR image
    printed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**PROGRAM**
```sql
CREATE TABLE program (
    program_id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    active BOOLEAN DEFAULT TRUE,
    start_date DATE NOT NULL,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**PROCESS_TYPE**
```sql
CREATE TABLE process_type (
    process_type_id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**PROGRAM_PROCESS**
```sql
CREATE TABLE program_process (
    program_process_id SERIAL PRIMARY KEY,
    program_id INTEGER REFERENCES program(program_id) ON DELETE CASCADE,
    process_type_id INTEGER REFERENCES process_type(process_type_id),
    required BOOLEAN DEFAULT TRUE,
    sequence_order INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(program_id, process_type_id)
);
```

**FLOW_RULE**
```sql
CREATE TABLE flow_rule (
    flow_rule_id SERIAL PRIMARY KEY,
    program_id INTEGER REFERENCES program(program_id) ON DELETE CASCADE,
    from_process_id INTEGER REFERENCES process_type(process_type_id),
    to_process_id INTEGER REFERENCES process_type(process_type_id),
    condition_type VARCHAR(20) DEFAULT 'MANUAL',  -- AUTO, MANUAL, CONDITIONAL
    can_override BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**TABLE**
```sql
CREATE TABLE service_table (
    table_id SERIAL PRIMARY KEY,
    program_id INTEGER REFERENCES program(program_id) ON DELETE SET NULL,
    table_name VARCHAR(100) NOT NULL,
    max_client_seats INTEGER DEFAULT 1,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**STAFF**
```sql
CREATE TABLE staff (
    staff_id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    staff_name VARCHAR(100) NOT NULL,
    role VARCHAR(50) DEFAULT 'PROCESSING_STAFF',  -- ADMIN, PROGRAM_ADMIN, ROUTING_STAFF, PROCESSING_STAFF
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**TABLE_PROCESS_TYPE**
```sql
CREATE TABLE table_process_type (
    table_process_type_id SERIAL PRIMARY KEY,
    table_id INTEGER REFERENCES service_table(table_id) ON DELETE CASCADE,
    process_type_id INTEGER REFERENCES process_type(process_type_id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(table_id, process_type_id)
);
```

**DEVICE**
```sql
CREATE TABLE device (
    device_id SERIAL PRIMARY KEY,
    device_uuid UUID UNIQUE NOT NULL DEFAULT gen_random_uuid(),
    device_type VARCHAR(50) NOT NULL,  -- QPD, QRD, QID, IOT_DISPLAY
    device_model VARCHAR(100),
    mac_address VARCHAR(17),
    ip_address INET,
    physical_location VARCHAR(200),
    active BOOLEAN DEFAULT TRUE,
    last_seen TIMESTAMP,
    registered_at TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW()
);
```

**DEVICE_ASSIGNMENT**
```sql
CREATE TABLE device_assignment (
    assignment_id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES device(device_id) ON DELETE CASCADE,
    program_id INTEGER REFERENCES program(program_id) ON DELETE CASCADE,
    table_id INTEGER REFERENCES service_table(table_id) ON DELETE SET NULL,  -- NULL for QRD/QID
    assignment_role VARCHAR(50) NOT NULL,  -- QPD, QRD, QID
    active BOOLEAN DEFAULT TRUE,
    assigned_at TIMESTAMP DEFAULT NOW(),
    unassigned_at TIMESTAMP,
    assigned_by_staff_id INTEGER REFERENCES staff(staff_id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(device_id, program_id)  -- One assignment per device per program
);
```

**DEVICE_SESSION**
```sql
CREATE TABLE device_session (
    session_id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES device(device_id) ON DELETE CASCADE,
    staff_id INTEGER REFERENCES staff(staff_id) ON DELETE CASCADE,
    session_start TIMESTAMP DEFAULT NOW(),
    session_end TIMESTAMP,
    session_token VARCHAR(255) UNIQUE NOT NULL,
    last_activity TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW()
);
CREATE INDEX idx_device_session_token ON device_session(session_token);
CREATE INDEX idx_device_session_staff ON device_session(staff_id);
```

**STUB_PROGRAM**
```sql
CREATE TABLE stub_program (
    stub_program_id SERIAL PRIMARY KEY,
    stub_id VARCHAR(36) REFERENCES stub(stub_id),
    program_id INTEGER REFERENCES program(program_id),
    registered_by_staff_id INTEGER REFERENCES staff(staff_id),
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'REGISTERED',  -- REGISTERED, IN_PROGRESS, COMPLETED, EXPIRED
    expires_at TIMESTAMP,
    UNIQUE(stub_id, program_id)
);
```

**STUB_PROCESS_STATUS**
```sql
CREATE TABLE stub_process_status (
    stub_process_status_id SERIAL PRIMARY KEY,
    stub_program_id INTEGER REFERENCES stub_program(stub_program_id) ON DELETE CASCADE,
    process_type_id INTEGER REFERENCES process_type(process_type_id),
    status VARCHAR(20) DEFAULT 'WAITING',  -- WAITING, IN_PROGRESS, COMPLETED, SKIPPED
    assigned_table_id INTEGER REFERENCES service_table(table_id) ON DELETE SET NULL,
    assigned_staff_id INTEGER REFERENCES staff(staff_id) ON DELETE SET NULL,
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    overridden BOOLEAN DEFAULT FALSE,
    override_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**AUDIT_LOG**
```sql
CREATE TABLE audit_log (
    log_id BIGSERIAL PRIMARY KEY,
    stub_id VARCHAR(36) REFERENCES stub(stub_id),
    actor_id INTEGER,  -- Could be staff_id or device_id
    actor_type VARCHAR(20),  -- STAFF, DEVICE, SYSTEM
    action VARCHAR(100) NOT NULL,  -- REGISTER_STUB, MARK_COMPLETE, OVERRIDE_FLOW, etc.
    from_state VARCHAR(50),
    to_state VARCHAR(50),
    reason TEXT,
    metadata JSONB,  -- Flexible field for additional data
    ip_address INET,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_audit_timestamp ON audit_log(timestamp);
CREATE INDEX idx_audit_stub ON audit_log(stub_id);
```

---

### 3.3 API Endpoints

#### 3.3.1 Authentication
```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
```

#### 3.3.2 Stub Management
```
POST   /api/stubs/generate              # Generate batch of stubs
GET    /api/stubs/{stub_id}             # Get stub details
POST   /api/stubs/register              # Register stub to program
GET    /api/stubs/{stub_id}/status      # Get stub status (for informant device)
GET    /api/stubs/{stub_id}/history     # Get stub audit trail
```

#### 3.3.3 Program Management
```
GET    /api/programs                    # List all programs
POST   /api/programs                    # Create program
GET    /api/programs/{id}               # Get program details
PUT    /api/programs/{id}               # Update program
DELETE /api/programs/{id}               # Delete program
POST   /api/programs/{id}/processes     # Assign process types
GET    /api/programs/{id}/flow-rules    # Get flow rules
POST   /api/programs/{id}/flow-rules    # Create flow rule
```

#### 3.3.4 Table Management
```
GET    /api/tables                      # List tables
POST   /api/tables                      # Create table
GET    /api/tables/{id}                 # Get table details
PUT    /api/tables/{id}                 # Update table
GET    /api/tables/{id}/queue           # Get current queue for table
POST   /api/tables/{id}/capabilities    # Assign process capabilities
```

#### 3.3.5 Process Flow
```
POST   /api/process/start               # Mark process as IN_PROGRESS
POST   /api/process/complete            # Mark process as COMPLETED
POST   /api/process/skip                # Skip process (with reason)
POST   /api/process/redirect            # Redirect stub to another table
```

#### 3.3.6 Queue Operations
```
GET    /api/queue/table/{table_id}      # Get queue for specific table
POST   /api/queue/call-next             # Call next stub from queue
GET    /api/queue/current/{table_id}    # Get currently served stubs
```

#### 3.3.7 Device Management
```
GET    /api/admin/devices               # List all devices
POST   /api/admin/devices               # Register new device
GET    /api/admin/devices/{uuid}        # Get device details
PUT    /api/admin/devices/{uuid}        # Update device info (location, model, etc.)
DELETE /api/admin/devices/{uuid}        # Deactivate device (soft-delete)

POST   /api/admin/devices/{uuid}/assign   # Assign device to program/table
POST   /api/admin/devices/{uuid}/unassign # Remove device assignment
GET    /api/admin/devices/{uuid}/assignments # Get device assignment history

POST   /api/devices/{uuid}/login        # Staff login to device (creates session)
POST   /api/devices/{uuid}/logout       # Staff logout (ends session)
GET    /api/devices/{uuid}/status       # Get device health/assignment
GET    /api/devices/{uuid}/session      # Get current session info
```

#### 3.3.8 Reports & Analytics
```
GET    /api/reports/daily               # Daily summary report
GET    /api/reports/program/{id}        # Program completion report
GET    /api/analytics/bottlenecks       # Identify process bottlenecks
GET    /api/analytics/staff-performance # Staff performance metrics
GET    /api/analytics/device-health     # Device uptime and session metrics
```

#### 3.3.9 WebSocket Events
```
SUBSCRIBE  /ws/queue/{table_id}         # Subscribe to table queue updates
SUBSCRIBE  /ws/display/{table_id}       # Subscribe to display updates
SUBSCRIBE  /ws/devices                  # Subscribe to device status changes
SUBSCRIBE  /ws/sessions/{staff_id}      # Subscribe to staff session events
```

---

### 3.4 IoT Integration Architecture

#### 3.4.1 MQTT Topic Structure
```
flexiqueue/table/{table_id}/call
  Payload: {"alias": "Green Owl 42", "table_name": "Table 1"}

flexiqueue/table/{table_id}/status
  Payload: {"queue_length": 5, "current_serving": ["Blue Cat 11", "Red Fox 08"]}

flexiqueue/display/audio
  Payload: {"text": "Now serving Green Owl 42 at Table 1", "audio_file": "call_001.mp3"}
```

#### 3.4.2 ESP32 Code Structure (Pseudocode)
```cpp
#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <TFT_eSPI.h>

WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);
TFT_eSPI tft = TFT_eSPI();

void setup() {
    // Connect to WiFi (LAN)
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    
    // Connect to MQTT broker
    mqttClient.setServer(MQTT_BROKER_IP, 1883);
    mqttClient.setCallback(messageCallback);
    mqttClient.subscribe("flexiqueue/table/1/call");
    
    // Initialize display
    tft.init();
    tft.setRotation(1);
}

void messageCallback(char* topic, byte* payload, unsigned int length) {
    // Parse JSON payload
    StaticJsonDocument<200> doc;
    deserializeJson(doc, payload, length);
    
    String alias = doc["alias"];
    String tableName = doc["table_name"];
    
    // Update display
    tft.fillScreen(TFT_BLACK);
    tft.setTextColor(TFT_WHITE, TFT_BLACK);
    tft.drawString("NOW SERVING:", 10, 50, 4);
    tft.setTextColor(TFT_GREEN, TFT_BLACK);
    tft.drawString(alias, 10, 100, 6);
    tft.setTextColor(TFT_YELLOW, TFT_BLACK);
    tft.drawString("at " + tableName, 10, 150, 4);
    
    // Trigger audio (if DFPlayer connected)
    playAudio();
}

void loop() {
    mqttClient.loop();
}
```

---

### 3.5 Deployment Architecture

#### 3.5.1 Network Topology (Orange Pi Central Server)

```
                    LGU LOCAL NETWORK (192.168.1.x / 10.0.x.x)
┌─────────────────────────────────────────────────────────────────┐
│                                                                 │
│  ┌──────────────┐                                              │
│  │   Router     │                                              │
│  │  (Gateway)   │                                              │
│  └──────┬───────┘                                              │
│         │                                                       │
│    ┌────┴────┬──────────┬──────────┬──────────┬──────────┐    │
│    │         │          │          │          │          │    │
│  ┌─▼──┐   ┌─▼──┐    ┌──▼───┐   ┌──▼───┐  ┌──▼───┐   ┌──▼───┐│
│  │App │   │MQTT│    │QPD-R │   │QRD   │  │Scan  │   │Info  ││
│  │Srv │   │Brkr│    │(ESP32│   │(Phone│  │Device│   │Device││
│  │Ora │   │    │    └──────┘   └──────┘  └──────┘   └──────┘│
│  │Pi) │   │    │                                               │
│  │    │   │    │    ┌──────────┐    ┌──────────┐              │
│  │    │   │    │    │  QPD-S   │    │ QRD-IoT  │              │
│  │    │   │    │    │ (ESP32)  │    │(ESP32-TS)│              │
│  │    │   │    │    └──────────┘    └──────────┘              │
│  └─┬──┘   └─┬──┘                                               │
│    │         │                                                 │
│  ┌─▼─────────▼──┐                                             │
│  │  PostgreSQL  │  (or SQLite)                                │
│  │   Database   │                                             │
│  └──────────────┘                                             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘

KEY:
- Orange Pi: Portable, fanless, central server (MQTT broker + app + DB)
- QPD: Queue Processing Device (smartphone-based, optional IoT variants QPD-R/S)
- QRD: Queue Routing Device (smartphone-based, optional IoT variant QRD-IoT)
- App Srv: Central application server (backend API + web frontend)
- MQTT Brkr: Mosquitto MQTT broker for IoT communication
- Database: PostgreSQL or SQLite for data persistence
```

**Components:**
- **Central Server (Orange Pi):** Portable, low-power central hub
  - Runs application backend (Laravel/FastAPI)
  - Hosts PWA frontend web interface
  - Runs PostgreSQL/SQLite database
  - Runs MQTT broker (Mosquitto)
- **QPD (Queue Processing Device):** Staff-operated, table-based
  - Smartphone (Android/iOS) with custom app
  - Optional: QPD-R and/or QPD-S (ESP32 IoT variants) for display/control
- **QRD (Queue Routing Device):** Staff-operated, intake-based
  - Smartphone (Android/iOS) with custom app
  - Optional: QRD-IoT (ESP32 touchscreen) for standalone routing station
- **QID (Queue Informant Device):** Client-facing status display
  - Smartphone, tablet, or IoT kiosk (read-only)
- **MQTT Broker:** Mosquitto for real-time IoT communication
- **Database:** PostgreSQL (production) or SQLite (development)

---

#### 3.5.2 Central Server Requirements (Orange Pi)

**Hardware Specifications:**

Orange Pi is the ideal choice for FlexiQueue's central server:
- **Model:** Orange Pi 5 or Orange Pi 5 Plus (recommended)
  - **CPU:** 8-core ARM (Cortex-A76 + Cortex-A55), 2.4 GHz
  - **RAM:** 8-16 GB (expandable with LPDDR5)
  - **Storage:** 128 GB eMMC (upgradeable via microSD/USB)
  - **Power:** 10-15W typical (fanless, silent operation)
  - **Network:** Gigabit Ethernet + WiFi 6 optional
  - **OS:** Supports Ubuntu 22.04 LTS, Debian, or custom OS

**Why Orange Pi for FlexiQueue:**
- ✅ Portable and lightweight (fits in briefcase for mobile operations)
- ✅ Low power consumption (suitable for LGUs with unstable power)
- ✅ Fanless (silent, reliable for long-term operation)
- ✅ Sufficient performance (handles 50-100 concurrent users)
- ✅ Cost-effective (₱3,500-5,000 USD equivalent)
- ✅ Easy to deploy and move between DSWD offices

**Minimum Requirements (Small LGU - Up to 50 concurrent users):**
- **CPU:** Quad-core ARM 1.8 GHz or better
- **RAM:** 4-8 GB LPDDR4/LPDDR5
- **Storage:** 64 GB eMMC or SSD
- **Network:** Gigabit Ethernet
- **Power:** 15W max with UPS (2000 mAh power bank sufficient for 2-4 hours offline)

**Recommended Requirements (Medium LGU - Up to 100 concurrent users):**
- **CPU:** 8-core ARM 2.4 GHz or better
- **RAM:** 8-16 GB LPDDR5
- **Storage:** 128-256 GB eMMC or SSD
- **Network:** Gigabit Ethernet + WiFi 6
- **Power:** 20W max with UPS (5000 mAh power bank for extended offline operation)

**Backup & Power:**
- **UPS:** 1000-2000 VA to provide 2-4 hours of backup power
- **Backup Power Alternatives:**
  - Solar panel (10-15W) with battery (10000 mAh) for off-grid areas
  - Power bank (20000 mAh) as emergency fallback
- **Cooling:** Passive (no fans needed, fanless design of Orange Pi)

---

#### 3.5.3 Client Device Requirements

**Queue Processing Device (QPD) - Smartphone Base:**
- **OS:** Android 8+ (preferred for DSWD) or iOS 12+
- **Camera:** 5MP or better (for QR/barcode scanning)
- **Screen:** 5-6 inches minimum
- **Processor:** Quad-core 1.5 GHz or better
- **RAM:** 2-4 GB
- **Storage:** 64-128 GB
- **Browser/App:** Custom FlexiQueue PWA or native app
- **Connectivity:** WiFi 5GHz (for stable LAN connection)

**QPD Optional IoT Variants:**
- **QPD-R (Receiver - ESP32-based):**
  - Microcontroller: ESP32 DevKit v1
  - Display: 2.8" TFT LCD (320x240) or 16x2 LCD
  - Speaker: 3W 8Ω speaker + DFPlayer Mini
  - Power: 5V 2A USB power adapter
  - Pair button: Momentary push button
  - Communication: WiFi + MQTT
  - Cost: ₱800-1,200
- **QPD-S (Sender - ESP32-based):**
  - Microcontroller: ESP32 DevKit v1
  - Input: 4-6 push buttons or touch pads
  - Display: 16x2 LCD (optional, for status feedback)
  - Power: 5V 2A USB power adapter
  - Pair button: Momentary push button
  - Communication: WiFi + MQTT
  - Cost: ₱600-900

**Queue Routing Device (QRD) - Smartphone Base:**
- **OS:** Android 8+ or iOS 12+
- **Camera:** 8MP or better (for barcode/QR scanning clarity)
- **Screen:** 5-6 inches
- **Processor:** Quad-core 1.5 GHz or better
- **RAM:** 2-4 GB
- **Storage:** 64-128 GB
- **Browser/App:** Custom FlexiQueue PWA or native app

**QRD Optional IoT Variant:**
- **QRD-IoT (Touchscreen - ESP32-based):**
  - Microcontroller: ESP32 with higher clock (240 MHz+)
  - Display: 7" or 10" touchscreen (1024x600 minimum)
  - Camera: OV5640 5MP or USB barcode scanner
  - Power: 12V 2A adapter
  - Pair button: Momentary push button
  - Communication: WiFi + MQTT
  - Cost: ₱4,000-6,000

**Queue Informant Device (QID) - Client Facing:**
- **Smartphone:** Android 8+ or iOS 12+, 5-6" screen (can be repurposed old phone)
- **Tablet:** Android/iOS, 10" screen (recommended for high-traffic areas)
- **IoT Kiosk Display:** 10-15" touchscreen with built-in camera (optional)
- **Key Requirement:** Large, clear display visible from 3-5 meters away

---

#### 3.5.4 Network Configuration

**WiFi Setup for Orange Pi:**
- **WiFi Standard:** 802.11a/b/g/n/ac (WiFi 5) recommended
- **Access Point:** TP-Link or Ubiquiti WiFi 5 AP (₱2,000-3,000)
- **Coverage:** Ensure LAN covers all staff and device locations
- **Bandwidth:** 5 GHz band preferred (less interference) for stable IoT connections
- **Channel:** Set to static channel (avoid auto-switching)

**LAN Configuration:**
- **Network Type:** Private local network (192.168.1.x or 10.0.x.x)
- **DHCP Server:** Enabled on Orange Pi for automatic device IP assignment
- **Firewall:** Block external access; allow only LAN devices
- **MQTT Port:** 1883 (MQTT, non-encrypted acceptable for LAN)
- **HTTP/HTTPS Port:** 80/443 for web access

**Network Diagram:**
```
Internet (optional backup)
         │
         ▼ (only for system updates, not required for operation)
    ┌─────────┐
    │ Router  │────────LAN Gateway (192.168.1.1)
    └─────────┘
         │
    ┌────┴──────────────────┐
    │                       │
  WiFi              Ethernet (preferred for Orange Pi)
    │                       │
    │                  ┌────▼────────┐
    ├─ Smartphones    │  Orange Pi   │
    ├─ Tablets        │  (5/5+)      │
    ├─ IoT Devices    │  - Backend   │
    └─ Laptops        │  - Database  │
                      │  - MQTT      │
                      └─────────────┘
```

---

#### 3.5.5 Software Installation Steps

**Step 1: Orange Pi Initial Setup**

```bash
# Flash OS to eMMC or microSD
# Download: Ubuntu 22.04 LTS for Orange Pi from official site
# Use Etcher or dd to flash to eMMC/card

# Boot Orange Pi and initial login
ssh orangepi@192.168.1.x (default password: orangepi)

# Update system packages
sudo apt update && sudo apt upgrade -y

# Install required dependencies
sudo apt install -y \
    curl git wget \
    nginx postgresql postgresql-contrib \
    php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-gd \
    nodejs npm \
    mosquitto mosquitto-clients \
    python3 python3-pip \
    ufw fail2ban

# Install Composer (PHP package manager)
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
```

**Step 2: PostgreSQL Database Setup**

```bash
# Create database and user
sudo -u postgres psql

CREATE DATABASE flexiqueue;
CREATE USER flexiqueue_user WITH ENCRYPTED PASSWORD 'strong_password_here';
ALTER ROLE flexiqueue_user SET client_encoding TO 'utf8';
ALTER ROLE flexiqueue_user SET default_transaction_isolation TO 'read committed';
ALTER ROLE flexiqueue_user SET default_transaction_deferrable TO on;
ALTER ROLE flexiqueue_user SET timezone TO 'UTC';
GRANT ALL PRIVILEGES ON DATABASE flexiqueue TO flexiqueue_user;
\q
```

**Step 3: MQTT Broker (Mosquitto) Setup**

```bash
# Configure Mosquitto
sudo nano /etc/mosquitto/conf.d/default.conf

# Add:
listener 1883
protocol mqtt
allow_anonymous true

# Restart Mosquitto
sudo systemctl restart mosquitto
sudo systemctl enable mosquitto

# Test MQTT broker
mosquitto_sub -h localhost -t "test/topic"  # In one terminal
mosquitto_pub -h localhost -t "test/topic" -m "Hello" # In another terminal
```

**Step 4: Application Deployment**

```bash
# Clone FlexiQueue repository
cd /var/www
sudo git clone https://github.com/your-org/flexiqueue.git
cd flexiqueue

# Install backend dependencies
composer install --optimize-autoloader --no-dev

# Install frontend dependencies
npm install
npm run build

# Configure environment
cp .env.example .env
sudo nano .env
```

**.env Configuration for Orange Pi:**
```
APP_NAME=FlexiQueue
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.100  # Orange Pi IP

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=flexiqueue
DB_USERNAME=flexiqueue_user
DB_PASSWORD=strong_password_here

MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=flexiqueue_server

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

SESSION_LIFETIME=30
QUEUE_CONNECTION=sync  # Use synchronous for small deployments
```

```bash
# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Create admin user (seed data)
php artisan db:seed --class=AdminSeeder

# Set proper permissions
sudo chown -R www-data:www-data /var/www/flexiqueue
sudo chmod -R 775 storage bootstrap/cache
```

**Step 5: Nginx Web Server Configuration**

```bash
# Create Nginx configuration for FlexiQueue
sudo nano /etc/nginx/sites-available/flexiqueue
```

**Content of /etc/nginx/sites-available/flexiqueue:**
```nginx
server {
    listen 80;
    server_name 192.168.1.100 _;  # Orange Pi local IP
    root /var/www/flexiqueue/public;

    index index.php index.html;
    
    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript;

    # Main routes
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP processing
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Hide sensitive files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # WebSocket (if using Laravel Echo)
    location /socket.io {
        proxy_pass http://localhost:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }

    # Cache static assets
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

```bash
# Enable site and restart Nginx
sudo ln -s /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default  # Remove default site
sudo nginx -t  # Test configuration
sudo systemctl restart nginx
sudo systemctl enable nginx
```

**Step 6: Firewall Configuration**

```bash
# Enable UFW firewall
sudo ufw enable

# Allow SSH (for remote administration)
sudo ufw allow 22/tcp

# Allow HTTP (port 80)
sudo ufw allow 80/tcp

# Allow HTTPS (port 443)
sudo ufw allow 443/tcp

# Allow MQTT (port 1883) - internal only
sudo ufw allow from 192.168.1.0/24 to any port 1883

# Verify
sudo ufw status
```

**Step 7: System Optimization for Orange Pi**

```bash
# Set CPU governor to performance (optional)
echo "performance" | sudo tee /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor

# Enable swap (useful for low RAM situations)
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Reduce unnecessary services to save resources
sudo systemctl disable bluetooth.service  # If not needed
sudo systemctl disable avahi-daemon.service  # If not needed
```

---

#### 3.5.6 IoT Device (ESP32) Setup

**For QPD-R and QPD-S (Display/Speaker):**

```cpp
#include <WiFi.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <TFT_eSPI.h>  // Or LiquidCrystal_I2C for 16x2 LCD

// Configuration
const char* ssid = "FLEXIQUEUE_LAN";        // Your LAN SSID
const char* password = "your_password";     // Your LAN password
const char* mqtt_server = "192.168.1.100";  // Orange Pi IP
const int mqtt_port = 1883;

WiFiClient espClient;
PubSubClient client(espClient);
TFT_eSPI tft = TFT_eSPI();

// Pair button pin
const int PAIR_BUTTON = 0;  // GPIO0 (BOOT button on most ESP32 boards)
const int PAIR_LED = 2;     // GPIO2 (built-in LED)

// Device identifiers
String device_uuid;
String assigned_table_id = "";

void setup() {
    Serial.begin(115200);
    
    // Initialize LED and button
    pinMode(PAIR_LED, OUTPUT);
    pinMode(PAIR_BUTTON, INPUT_PULLUP);
    
    // Initialize display
    tft.init();
    tft.setRotation(1);
    tft.fillScreen(TFT_BLACK);
    tft.setTextColor(TFT_WHITE);
    tft.drawString("FlexiQueue QPD", 10, 10, 2);
    tft.drawString("Connecting...", 10, 40, 2);
    
    // Generate device UUID from MAC address
    uint8_t mac[6];
    WiFi.macAddress(mac);
    device_uuid = String(mac[3], HEX) + String(mac[4], HEX) + String(mac[5], HEX);
    device_uuid.toUpperCase();
    
    // Connect to WiFi
    connectToWiFi();
    
    // Setup MQTT
    client.setServer(mqtt_server, mqtt_port);
    client.setCallback(callback);
}

void connectToWiFi() {
    WiFi.mode(WIFI_STA);
    WiFi.begin(ssid, password);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("\nWiFi connected");
        tft.fillScreen(TFT_BLACK);
        tft.drawString("WiFi OK", 10, 10, 2);
        tft.drawString(WiFi.localIP().toString(), 10, 40, 2);
    }
}

void reconnectMQTT() {
    while (!client.connected()) {
        Serial.print("Attempting MQTT connection...");
        
        if (client.connect(device_uuid.c_str())) {
            Serial.println("connected");
            digitalWrite(PAIR_LED, HIGH);  // LED on when connected
            
            // Subscribe to topics
            client.subscribe("flexiqueue/device/pairing");
            client.subscribe("flexiqueue/table/" + assigned_table_id + "/call");
            client.subscribe("flexiqueue/device/" + device_uuid + "/assignment");
        } else {
            Serial.print("failed, rc=");
            Serial.print(client.state());
            Serial.println(" try again in 5 seconds");
            delay(5000);
        }
    }
}

void callback(char* topic, byte* payload, unsigned int length) {
    StaticJsonDocument<256> doc;
    deserializeJson(doc, payload);
    
    String t = String(topic);
    
    if (t.endsWith("/assignment")) {
        // Device received table assignment
        assigned_table_id = doc["table_id"].as<String>();
        digitalWrite(PAIR_LED, HIGH);  // Blink to confirm pairing
        displayPairingSuccess(assigned_table_id);
    } 
    else if (t.endsWith("/call")) {
        // New stub to display and announce
        String alias = doc["alias"].as<String>();
        String table_name = doc["table_name"].as<String>();
        displayStub(alias, table_name);
        playAudio();  // Trigger DFPlayer if connected
    }
}

void displayPairingSuccess(String table_id) {
    tft.fillScreen(TFT_GREEN);
    tft.setTextColor(TFT_BLACK);
    tft.drawString("PAIRING SUCCESS", 10, 50, 3);
    tft.drawString("Table: " + table_id, 10, 100, 2);
    delay(3000);
}

void displayStub(String alias, String table_name) {
    tft.fillScreen(TFT_BLUE);
    tft.setTextColor(TFT_WHITE);
    tft.drawString("NOW SERVING:", 10, 20, 3);
    tft.setTextColor(TFT_YELLOW);
    tft.drawString(alias, 10, 70, 6);  // Large font for alias
    tft.setTextColor(TFT_WHITE);
    tft.drawString("at " + table_name, 10, 130, 2);
}

void playAudio() {
    // If DFPlayer Mini is connected via Serial1
    // Send commands to play pre-recorded audio files
    // Format: [Start Byte][Version][Length][CMD][Feedback][Parameter1][Parameter2][Checksum][End Byte]
    // Example: Play track 001
    byte payload[] = {0x7E, 0xFF, 0x06, 0x03, 0x00, 0x00, 0x01, 0xFE, 0xEF};
    Serial1.write(payload, sizeof(payload));
}

void checkPairingButton() {
    static unsigned long lastPress = 0;
    static bool buttonPressed = false;
    
    if (digitalRead(PAIR_BUTTON) == LOW && !buttonPressed) {
        lastPress = millis();
        buttonPressed = true;
    }
    
    if (digitalRead(PAIR_BUTTON) == HIGH && buttonPressed) {
        unsigned long holdTime = millis() - lastPress;
        if (holdTime > 3000) {  // 3-second hold
            initiatePairing();
        }
        buttonPressed = false;
    }
}

void initiatePairing() {
    Serial.println("Entering pairing mode...");
    digitalWrite(PAIR_LED, LOW);  // LED off during pairing
    
    tft.fillScreen(TFT_ORANGE);
    tft.setTextColor(TFT_BLACK);
    tft.drawString("PAIRING MODE", 10, 50, 3);
    tft.drawString("ID: " + device_uuid, 10, 100, 2);
    
    // Publish availability to server
    StaticJsonDocument<128> doc;
    doc["device_uuid"] = device_uuid;
    doc["device_type"] = "QPD-R";  // or "QPD-S" for sender variant
    doc["status"] = "available_for_pairing";
    
    char buffer[128];
    serializeJson(doc, buffer);
    client.publish("flexiqueue/device/pairing", buffer);
    
    // Wait for assignment (30-second timeout)
    unsigned long pairingStartTime = millis();
    while (millis() - pairingStartTime < 30000) {
        client.loop();
        if (assigned_table_id != "") {
            break;  // Assignment received
        }
        delay(100);
    }
}

void loop() {
    // Check WiFi connection
    if (WiFi.status() != WL_CONNECTED) {
        connectToWiFi();
    }
    
    // Check MQTT connection
    if (!client.connected()) {
        reconnectMQTT();
    }
    
    // Handle MQTT messages
    client.loop();
    
    // Check pairing button
    checkPairingButton();
    
    delay(100);
}
```

**For QRD-IoT (Touchscreen):**
- Requires touch library (XPT2046_Touchscreen)
- Camera integration via OV5640 or USB scanner
- Similar MQTT pairing mechanism

---

### 3.6 Backup & Disaster Recovery

**Automated Daily Backup:**

```bash
# Create backup script
sudo nano /usr/local/bin/flexiqueue-backup.sh

#!/bin/bash
BACKUP_DIR="/backup/flexiqueue"
DATE=$(date +%Y-%m-%d_%H-%M-%S)
DB_NAME="flexiqueue"
DB_USER="flexiqueue_user"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup PostgreSQL database
pg_dump -U $DB_USER $DB_NAME | gzip > $BACKUP_DIR/db_$DATE.sql.gz

# Backup application files
tar -czf $BACKUP_DIR/app_$DATE.tar.gz /var/www/flexiqueue

# Keep only last 30 days of backups
find $BACKUP_DIR -name "*.gz" -mtime +30 -delete

echo "Backup completed: $DATE"
```

```bash
# Set up cron job for daily backup at 2 AM
sudo crontab -e

# Add line:
0 2 * * * /usr/local/bin/flexiqueue-backup.sh >> /var/log/flexiqueue-backup.log 2>&1
```

**Recovery Procedure:**
```bash
# Restore database from backup
gunzip < /backup/flexiqueue/db_2025-01-24.sql.gz | psql -U flexiqueue_user flexiqueue

# Restore application files
tar -xzf /backup/flexiqueue/app_2025-01-24.tar.gz -C /
```

---

## 3.6 Deployment Timeline

### Week 1-2: Orange Pi Setup
- Flash OS and initial configuration
- Install PostgreSQL, MQTT, PHP, Node.js
- Deploy FlexiQueue application
- Test basic connectivity

### Week 3-4: Application Testing
- Test API endpoints
- Test MQTT communication
- Generate and verify stubs
- Conduct load testing

### Week 5: Device Setup & Pairing
- Configure ESP32 devices (QPD-R, QPD-S, QRD-IoT)
- Test device pairing mechanism
- Verify display updates
- Test audio announcements

### Week 6: Integration Testing
- End-to-end workflow testing
- Prepare training materials
- Plan rollout schedule

### Week 7: Pilot Deployment
- Deploy at MSWDO office
- Conduct staff training
- Monitor system performance
- Collect initial feedback

---
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# Install MQTT Broker
sudo apt install -y mosquitto mosquitto-clients
sudo systemctl enable mosquitto
```

#### Step 2: Database Setup

```bash
# Create database and user
sudo -u postgres psql

CREATE DATABASE flexiqueue;
CREATE USER flexiqueue_user WITH ENCRYPTED PASSWORD 'secure_password_here';
GRANT ALL PRIVILEGES ON DATABASE flexiqueue TO flexiqueue_user;
\q
```

#### Step 3: Application Deployment

```bash
# Clone repository (or upload files)
cd /var/www
sudo git clone https://github.com/your-org/flexiqueue.git
cd flexiqueue

# Install backend dependencies
composer install --optimize-autoloader --no-dev

# Install frontend dependencies
npm install
npm run build

# Configure environment
cp .env.example .env
nano .env
```

**.env Configuration:**
```
APP_NAME=FlexiQueue
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.100

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=flexiqueue
DB_USERNAME=flexiqueue_user
DB_PASSWORD=secure_password_here

MQTT_HOST=127.0.0.1
MQTT_PORT=1883
MQTT_CLIENT_ID=flexiqueue_server

SESSION_LIFETIME=30
```

```bash
# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Create admin user
php artisan db:seed --class=AdminSeeder

# Set permissions
sudo chown -R www-data:www-data /var/www/flexiqueue
sudo chmod -R 775 storage bootstrap/cache
```

#### Step 4: Web Server Configuration (Nginx)

```nginx
# /etc/nginx/sites-available/flexiqueue
server {
    listen 80;
    server_name 192.168.1.100;
    root /var/www/flexiqueue/public;

    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
# Enable site
sudo ln -s /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx
```

#### Step 5: WebSocket Server Setup

```bash
# Install Laravel Echo Server
npm install -g laravel-echo-server

# Initialize
laravel-echo-server init

# Start as service
sudo nano /etc/systemd/system/echo-server.service
```

**echo-server.service:**
```ini
[Unit]
Description=Laravel Echo Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/flexiqueue
ExecStart=/usr/bin/laravel-echo-server start
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable echo-server
sudo systemctl start echo-server
```

---

## 4. IMPLEMENTATION PHASES

### Phase 1: Core System Development (Months 1-2)

**Week 1-2: Database & Backend Foundation**
- Design and implement database schema
- Create migration files
- Develop authentication system
- Build basic CRUD APIs for:
  - Programs
  - Process Types
  - Tables
  - Staff

**Week 3-4: Stub Management**
- Implement stub generation algorithm
- Create QR code generation
- Build stub registration API
- Develop stub-program linking logic

**Week 5-6: Process Flow Engine**
- Implement STUB_PROCESS_STATUS tracking
- Build flow rule evaluation logic
- Create override mechanism
- Develop queue generation views

**Week 7-8: Frontend Foundation**
- Set up Vue.js/Svelte project
- Build admin dashboard:
  - Program management interface
  - Table configuration interface
  - Staff management
- Create responsive layouts

**Deliverable:** Working backend API + basic admin interface

---

### Phase 2: Device Interfaces (Month 3)

**Week 9-10: Scanner Device Interface**
- Implement QR code scanning (html5-qrcode)
- Build stub information display
- Create action menus based on roles
- Develop process completion interface

**Week 11-12: Queue Device Interface**
- Build queue display component
- Implement "Call Next" functionality
- Create WebSocket connection for real-time updates
- Design staff concurrent client management

**Week 13-14: Queue Informant Device**
- Build self-service kiosk interface
- Implement read-only stub status display
- Create process checklist visualization
- Add auto-timeout feature

**Deliverable:** Fully functional web-based device interfaces

---

### Phase 3: IoT Integration (Month 4)

**Week 15-16: MQTT Infrastructure**
- Set up Mosquitto broker
- Design topic structure
- Implement backend MQTT publisher
- Test message delivery

**Week 17-18: ESP32 Display Development**
- Write Arduino code for ESP32
- Integrate with LCD/TFT display
- Implement MQTT subscriber
- Test display updates

**Week 19-20: Audio Calling System**
- Integrate DFPlayer Mini (or alternative)
- Record/generate audio announcements
- Implement audio trigger logic
- Test audio-visual synchronization

**Deliverable:** Working IoT queue display with audio calling

---

### Phase 4: Testing & Deployment (Month 5)

**Week 21-22: System Integration Testing**
- End-to-end workflow testing
- Load testing (simulate 50+ concurrent users)
- IoT device reliability testing
- WebSocket stress testing

**Week 23: MSWDO Pilot Deployment**
- Install server at MSWDO office
- Configure network and devices
- Generate initial stub batch
- Create first program (e.g., AICS distribution)

**Week 24: Training & Documentation**
- Conduct admin training (4 hours)
- Conduct staff training (2 hours)
- Prepare user manuals
- Create video tutorials

**Week 25: Monitoring & Bug Fixes**
- Monitor system during live operations
- Collect feedback from staff and citizens
- Fix critical bugs
- Optimize performance

**Deliverable:** Production-ready system deployed at MSWDO

---

## 5. TESTING STRATEGY

### 5.1 Unit Testing
- Test individual API endpoints
- Test database models and relationships
- Test stub alias generation algorithm
- Test flow rule evaluation logic
- **Target Coverage:** 70%+ code coverage

### 5.2 Integration Testing
- Test complete workflows:
  - Stub registration → process assignment → completion
  - Admin creates program → staff serves clients → completion
- Test WebSocket message delivery
- Test MQTT publish-subscribe
- Test database transactions and rollbacks

### 5.3 User Acceptance Testing (UAT)
- Recruit 5-10 MSWDO staff for testing
- Simulate real AICS distribution scenario
- Collect feedback via questionnaire:
  - Ease of use (1-5 scale)
  - System reliability (1-5 scale)
  - Feature completeness (qualitative)
- Iterate based on feedback

### 5.4 Load Testing
- Use Apache JMeter or Locust
- Simulate scenarios:
  - 50 concurrent API requests
  - 100 WebSocket connections
  - 20 queue devices updating simultaneously
- **Success Criteria:**
  - < 2s response time for 95% of requests
  - No errors under normal load

### 5.5 IoT Device Testing
- Disconnect/reconnect WiFi (test auto-reconnect)
- Power cycle ESP32 (test state recovery)
- MQTT broker restart (test reconnection logic)
- Display refresh rate testing (no flickering)

---

## 6. MAINTENANCE PLAN

### 6.1 Daily Tasks
- Check system uptime (via monitoring dashboard)
- Review error logs for anomalies
- Verify database backup completion

### 6.2 Weekly Tasks
- Review audit logs for suspicious activity
- Check disk space usage
- Test database backup restore procedure

### 6.3 Monthly Tasks
- Update system dependencies (security patches)
- Review and archive old audit logs
- Generate monthly usage reports for LGU

### 6.4 Quarterly Tasks
- Full system backup and offsite storage
- Performance optimization review
- User satisfaction survey

---

## 7. COST ESTIMATION

### 7.1 Development Costs (One-time)
| Item | Cost (PHP) |
|------|------------|
| Developer (5 months) | ₱0 (thesis project) |
| Testing & QA | ₱0 (self-testing) |
| **Total Development** | **₱0** |

### 7.2 Hardware Costs (One-time)
| Item | Quantity | Unit Cost | Total |
|------|----------|-----------|-------|
| Server (refurbished PC) | 1 | ₱15,000 | ₱15,000 |
| UPS (1000VA) | 1 | ₱3,500 | ₱3,500 |
| Network Switch (8-port) | 1 | ₱1,500 | ₱1,500 |
| Router/Access Point | 1 | ₱2,000 | ₱2,000 |
| ESP32 DevKit | 5 | ₱400 | ₱2,000 |
| 2.8" TFT LCD | 5 | ₱800 | ₱4,000 |
| DFPlayer Mini + Speaker | 5 | ₱300 | ₱1,500 |
| Tablet (Informant Device) | 2 | ₱8,000 | ₱16,000 |
| Stub Printer (label/ticket) | 1 | ₱5,000 | ₱5,000 |
| Cables & Accessories | - | - | ₱2,000 |
| **Total Hardware** | | | **₱52,500** |

### 7.3 Annual Operating Costs
| Item | Cost (PHP/year) |
|------|-----------------|
| Electricity (~100W 24/7) | ₱5,000 |
| Internet (optional backup) | ₱0 (LAN only) |
| Maintenance & Support | ₱10,000 |
| **Total Annual** | **₱15,000** |

**Total Initial Investment:** ₱52,500  
**5-Year Total Cost of Ownership:** ₱127,500  

**Cost Comparison:**
- Commercial queue system: ₱200,000 - ₱500,000 (one-time) + ₱50,000/year licensing
- FlexiQueue 5-year TCO: ₱127,500 (open source, no licensing)
- **Savings:** ~₱322,500 over 5 years

---

## 8. RISK MANAGEMENT

### Risk 1: Hardware Failure
**Mitigation:**
- Daily automated backups
- UPS for power protection
- Spare ESP32 devices
- Documented recovery procedures

### Risk 2: Staff Resistance to New System
**Mitigation:**
- Involve staff in design phase (feedback sessions)
- Hands-on training with practice scenarios
- Quick reference cards at each station
- Dedicated support during first week

### Risk 3: Network Instability
**Mitigation:**
- Offline-first architecture (works without internet)
- LAN-only deployment (no dependency on ISP)
- Graceful degradation (fall back to manual if WebSocket fails)

### Risk 4: Scope Creep During Development
**Mitigation:**
- Clear requirements document (this spec)
- Phased development with defined deliverables
- Defer non-critical features to post-thesis enhancements
- Regular check-ins with thesis adviser

### Risk 5: Data Loss or Corruption
**Mitigation:**
- Database transactions for critical operations
- Foreign key constraints
- Daily backups with 30-day retention
- Audit logs for data reconstruction

---

## 9. SUCCESS METRICS

### 9.1 Technical Metrics
- **System Uptime:** > 99% during operating hours
- **Response Time:** < 2s for 95% of requests
- **Error Rate:** < 1% of transactions
- **Queue Update Latency:** < 500ms (WebSocket)

### 9.2 User Satisfaction Metrics
- **Staff Ease of Use:** > 4.0/5.0 average rating
- **Client Satisfaction:** > 80% find informant device helpful
- **Training Time:** < 2 hours for staff proficiency

### 9.3 Operational Metrics
- **Processing Time Reduction:** 20% faster than manual system
- **Queue Confusion Incidents:** < 5 per day
- **Override Frequency:** < 10% of transactions (indicates good flow design)

### 9.4 Business Value Metrics
- **Cost Savings:** Quantified against commercial alternatives
- **Scalability:** Successfully configure new program in < 1 hour
- **Adoption:** Used by minimum 2 MSWDO programs within 6 months

---

## 10. FUTURE ENHANCEMENTS (Post-Thesis)

### Phase 2 Enhancements
1. **Mobile App (Native):** iOS and Android apps for better performance
2. **Biometric Integration:** Fingerprint for stub registration (prevent fraud)
3. **SMS Notifications:** Send queue position updates to client phones
4. **Analytics Dashboard:** Real-time charts, bottleneck identification, predictive wait times
5. **Multi-Site Support:** Synchronize stubs across multiple LGU offices

### Phase 3 Enhancements
1. **AI-Powered Flow Optimization:** Machine learning to suggest better flow rules
2. **Citizen Portal:** Web/app for clients to register and track remotely
3. **Digital Stub (QR on Phone):** Eliminate paper stubs for tech-savvy clients
4. **Integration with LGU Systems:** Connect to Civil Registry, Treasurer, etc.
5. **Voice-Activated Informant:** For visually impaired clients

---

## 11. CONCLUSION

FlexiQueue represents a practical, cost-effective solution to queue management challenges in Philippine LGUs. By combining modern web technologies with affordable IoT components, the system delivers enterprise-level functionality at a fraction of commercial alternatives' cost.

The table-based architecture, stub-driven tracking, and configurable flow engine make it truly universal—adaptable to any government service without code changes. The phased implementation ensures manageable development while the offline-first design guarantees reliability in resource-constrained environments.

**Key Differentiators:**
1. ✅ Program-agnostic (not hardcoded to specific services)
2. ✅ Privacy-protecting (random aliases, no PII exposure)
3. ✅ Scalable (smartphones to IoT displays)
4. ✅ Offline-capable (LAN-only operation)
5. ✅ Audit-ready (comprehensive logging)
6. ✅ Cost-effective (open source, no licensing fees)

This system is ready for thesis defense, pilot deployment, and eventual adoption across multiple LGU offices.

---

**Document Version:** 1.0  
**Last Updated:** January 2026  
**Author:** David Datu N. Sarmiento
