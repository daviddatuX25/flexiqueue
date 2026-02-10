## Deployment, Operations, and Backups

**Purpose:** Provide a practical runbook for deploying FlexiQueue on‑site, monitoring it during events, and ensuring data safety.

Related docs: `02-architecture-overview.md`, `04-database-schema.md`

---

### 1. Hardware Requirements

From architecture Section 10.1.

#### 1.1 Portable Server

**Minimum (for small deployments):**

- CPU: Intel i3 / Ryzen 3 (4 GB RAM).
- Storage: 128 GB SSD.
- OS: Ubuntu 22.04 LTS or Windows 10.
- Wi‑Fi: Built‑in or USB adapter that supports AP/hotspot mode.

**Recommended:**

- CPU: Intel i5 / Ryzen 5 (8 GB RAM).
- Storage: 256 GB SSD.
- Battery: 4+ hours (plus power bank or generator backup).
- Network: Dual‑band Wi‑Fi (2.4 GHz for ESP32, 5 GHz optional for staff devices).

#### 1.2 Staff and Display Devices

- Staff smartphones:
  - Modern Android/iOS devices capable of running a PWA and using the camera.
- Optional tablets:
  - For kiosk / informant displays.

#### 1.3 ESP32 Devices (Post‑MVP)

MVP runs **without** ESP32 hardware. For future phases:

- **Option A (Full Station Setup)**
  - 1 × ESP32 DevKit (with buttons).
  - 1 × 7" LCD.
  - 1 × MAX98357A audio amplifier.
  - 1 × 5V 2A power supply.
- **Option B (Minimal)**
  - 1 × ESP32 + small OLED display.
  - Staff smartphone as primary interface.

These options are costed in the original architecture but implementation can be deferred.

#### 1.4 Network Equipment

- Optional Wi‑Fi router:
  - e.g., TP‑Link TL‑WR841N or equivalent.
- Optional small Ethernet switch:
  - For wired connections to server and some displays if desired.

---

### 2. Installation and Deployment Procedure

From architecture Section 10.2.

#### 2.1 Phase 1 – Pre‑Deployment (Office)

1. **Server Setup**
   - Install OS (Ubuntu Server 22.04 recommended for production; Windows acceptable for early demos).
   - Install dependencies:
     - PHP 8.2, Composer.
     - MariaDB.
     - Node.js (for frontend tooling).
   - Clone FlexiQueue repository.
   - Run:
     - `composer install`.
     - `npm install` (or your chosen JS package manager).
   - Generate Laravel app key:
     - `php artisan key:generate`.
   - Create `.env` file for local deployment (DB, app URL, Reverb config, etc.).

2. **Database Initialization**
   - Run migrations:
     - `php artisan migrate`.
   - Seed default data:
     - `php artisan db:seed`.
   - Create at least one admin user (via seeder, tinker, or a dedicated command).

3. **ESP32 Firmware (Post‑MVP)**
   - Flash station firmware to each device.
   - Configure Wi‑Fi credentials:
     - SSID: e.g., `MSWDO_FlexiQueue`.
   - Test connectivity and heartbeat reporting in the office.
   - Label each device physically (e.g., “Table1‑Display”, “Table1‑Button”).

4. **Token Preparation**
   - Generate unique QR codes (hashes stored in `tokens.qr_code_hash`).
   - Print labels (A1, A2, …, Z99) on durable cardstock.
   - Laminate cards for repeated use.
   - Seed `tokens` table with generated hashes and physical IDs.

#### 2.2 Phase 2 – On‑Site Deployment (Barangay)

**Day 1 Morning (Setup Timeline Example)**

- 08:00 – Arrive and identify power sources and backup options.
- 08:30 – Position server laptop and connect to stable power.
- 09:00 – Start Wi‑Fi hotspot or power on router.
- 09:15 – Start Laravel app and Reverb:
  - `php artisan serve` (or web server) and `php artisan reverb:start`.
- 09:30 – [Post‑MVP] Power on ESP32 devices and verify auto‑connection.
- 09:45 – Use admin dashboard to confirm:
  - All stations exist and are active.
  - Devices (if any) show as online.
- 10:00 – Assign staff to stations (login, station selection).
- 10:15 – Run a full test flow:
  - Scan a dummy token at triage.
  - Route through steps.
  - Complete and verify audit log entries.
- 10:30 – Declare system **READY FOR OPERATIONS**.

**Day 1 Operations**

- 10:30 – Start client intake.
- 12:00 – Lunch break (system can stay running).
- 14:00 – Resume operations.
- 17:00 – End of day serving.
- 17:15 – Export audit logs for the day.
- 17:30 – Perform database backup to removable media.
- 17:45 – Follow shutdown sequence (close app, stop services, power down devices).

#### 2.3 Phase 3 – Post‑Event

- Generate COA‑oriented reports and summaries.
- Unbind all tokens for the next event (reset aliases).
- Apply any firmware or software updates discovered as necessary.
- Pack and store hardware securely.

---

### 3. Monitoring and Health Checks

From architecture Section 10.3.

#### 3.1 Automated Health Checks

Implement a scheduled Laravel command, e.g., `php artisan flexiqueue:monitor`, that runs every 60 seconds and checks:

- Database responsiveness (< 100 ms for a simple query).
- Reverb (WebSocket server) is running and reachable.
- Disk space > 10% free.
- [Post‑MVP] ESP32 heartbeats:
  - `last_heartbeat` for all `hardware_units` < 90 seconds old.
- Active session count below configured thresholds (capacity).

Alerting:

- For severe issues, show desktop notification or an on‑screen alert on the admin dashboard.
- Log to a file such as `/var/log/flexiqueue-monitor.log` for later analysis.

#### 3.2 Manual Checks

Admin should periodically verify:

- System Health widget on the dashboard.
- Transaction logs for anomalies (e.g., too many overrides at one station).
- Queue lengths per station, rebalancing staff if needed.

---

### 4. Backup Strategy

From architecture Section 10.3.

#### 4.1 Real‑Time

- Transaction logs are append‑only and inherently resilient to logical corruption.
- Optionally mirror log files or use periodic SQL binlogs if infrastructure allows.

#### 4.2 Hourly Backups

- Run a cron job or scheduled task to:
  - Execute `mysqldump` to create `backup-{timestamp}.sql` snapshots.
  - Store backups under a location such as `/mnt/usb-backup/` or a dedicated backup directory.

#### 4.3 End‑of‑Day Backups

- At the end of each operating day:
  - Create a **full system backup**:
    - Database dump.
    - Key logs (transaction logs, monitor logs).
    - Configuration files (e.g., `.env`).
  - Copy backups to:
    - External hard drive.
    - Optionally a cloud storage location when internet is available (e.g., after returning to municipal office).

Backups should be:

- Labeled with program name and date.
- Stored securely and tested periodically for restorability.

---

### 5. Restoration and Disaster Recovery (Guidelines)

While not yet fully formalized in the architecture, a reasonable DR flow is:

1. Provision a replacement server (same or compatible OS).
2. Install required software stack (PHP, MariaDB, Node, etc.).
3. Restore most recent backup:
   - Import `backup-{timestamp}.sql`.
   - Restore `.env` and any config overrides.
4. Start Laravel app and Reverb.
5. Reconnect staff devices to the Wi‑Fi and perform a small smoke test before resuming operations.

---

For hardware details and deployment scenarios at the architecture level, see `02-architecture-overview.md`. For schema details that influence backup scope, see `04-database-schema.md`.

