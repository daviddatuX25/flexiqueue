## Architecture Overview

**Purpose:** Describe how FlexiQueue is physically deployed and logically structured so developers can see the “whole system” before diving into any single layer.

Related docs: `01-project-brief.md`, `03-domain-model.md`, `10-deployment.md`

---

### 1. Physical Topology

FlexiQueue is designed for **remote barangay deployments** using a **portable server** and a local Wi‑Fi network.

Key physical components:

- **Portable Server**
  - Laptop or small PC running Laravel + MariaDB + WebSocket server.
  - Also acts as a Wi‑Fi access point (or connects to a router).
- **Staff Devices**
  - Smartphones or laptops used for:
    - Triage (scan & bind tokens, choose track).
    - Station operations (call next, serve, transfer, complete).
    - Admin dashboard access.
- **Informant Displays**
  - Tablet or phone in kiosk / “display mode”.
  - Shows “now serving” and queue summaries and allows client self‑status scans.
- **Optional (Post‑MVP) ESP32 Hardware**
  - Displays at stations (e.g., 7" LCD).
  - Buttons for “Next” / “Recall”.
  - Speakers for chimes / announcements.

The architecture supports three main deployment profiles:

- **Scenario A — Full Setup (30+ clients/hour)**
  - 1 server laptop.
  - 4–6 staff phones (triage + multiple stations).
  - 1–2 kiosk tablets or display phones.
- **Scenario B — Minimal (10–15 clients/hour)**
  - 1 server laptop.
  - 2 staff phones.
  - 1 phone in display mode for the waiting area.
- **Scenario C — Emergency / Field**
  - 1 server laptop.
  - Staff phones only; no kiosk, no ESP32.
  - Manual shouting of numbers as ultimate fallback.

> Implementation note: for MVP we target **Scenario B/C** using only existing phones and one laptop.

---

### 2. Network Architecture

The system runs on a **closed LAN** created by the portable server or an attached router.

Typical configuration:

- **Wi‑Fi Access Point**
  - SSID: `MSWDO_FlexiQueue`
  - Security: WPA2‑PSK
  - IP range: `192.168.100.0/24`
  - Server IP: `192.168.100.1`
- **Application Ports (on the server)**
  - HTTP(S): `80/443` – Laravel app (Inertia + Svelte).
  - WebSocket: `6001` – Laravel Reverb.
  - Database: `3306` – MariaDB (local only; not exposed).
  - [Post‑MVP] Audio relay: `8001` – binary WebSocket for streaming.
- **Clients**
  - Phones / tablets: DHCP leases inside `192.168.100.0/24`.
  - [Post‑MVP] ESP32 devices: either static or reserved DHCP addresses.

No external internet connection is required or assumed. The system is intentionally **not routable** from outside the site.

---

### 3. Logical Architecture (5‑Layer Model)

FlexiQueue uses a **5‑layer modular monolith**:

1. **Presentation Layer (Layer 5) – Client Interfaces**
   - Triage PWA (Svelte).
   - Station PWA (Svelte).
   - Informant Display (Svelte / static HTML).
   - Admin Dashboard (Svelte).
   - Communicates via HTTP + WebSockets to the backend.

2. **Application Services Layer (Layer 4) – Business Logic**
   - **Session Manager**
     - `bind()` – create new session for a token.
     - `transfer()` – move session to next/custom station.
     - `complete()` – finalize and unbind token.
   - **Flow Engine**
     - `calculateNext()` – determine standard next station by track step.
     - `validateStep()` – prevent invalid station scans / skipping.
     - `applyOverride()` – handle supervisor‑authorized deviations.
   - **Capability Manager** (post‑MVP hardware aggregation)
     - `aggregateCaps()` – merge ESP32/phone capabilities at a station.
     - `validateSetup()` – detect missing required capabilities.
   - **Audit Logger**
     - `logTransaction()` – append immutable log entries.
     - `generateReport()` – COA‑oriented exports.

3. **Communication Infrastructure (Layer 3) – Realtime + HTTP**
   - **WebSocket Server (Laravel Reverb)**
     - `station.{id}` – private channels for staff devices at a station.
     - `device.{mac}` – private channels for individual ESP32 units.
     - `global.queue` – broadcast for informant displays.
   - **REST API (Laravel routes)**
     - `/api/sessions/*` – bind, transfer, override, complete, no‑show.
     - `/api/stations/*` – per‑station queues and status.
     - `/api/devices/*` – hardware registration and control (post‑MVP).
   - **[Post‑MVP] Audio Stream Relay**
     - WebRTC / binary WebSocket pipeline from staff phone to ESP32 speakers.

4. **Domain Model (Layer 2) – Data Logic**
   - Core Eloquent models representing the business:
     - `Program`, `ServiceTrack`, `TrackStep`.
     - `Station`, `HardwareUnit`.
     - `Token`, `Session`, `TransactionLog`.
   - Encodes business rules such as:
     - Only one active program at a time.
     - Track steps must be contiguous (no gaps).
     - Tokens can have only one active session.
     - Transaction logs are append‑only.

5. **Data Persistence (Layer 1) – Storage**
   - **MariaDB** for all relational data.
   - Configuration via `.env` / config files.
   - [Optional] JSON or flat‑file config for deployment‑specific settings.

Each higher layer talks only to the layer directly beneath it, keeping presentation logic separate from business rules and storage details.

---

### 4. Deployment View (How Everything Fits Together)

High‑level deployment relationships:

- **Server Node**
  - Runs Laravel app, Reverb, MariaDB.
  - Hosts both HTTP and WebSocket endpoints.
  - Manages the Wi‑Fi access point (or connects to a router).
- **Triage Devices**
  - Connect via Wi‑Fi; access `/triage` UI.
  - Use camera to scan QR tokens and call `/api/sessions/bind`.
  - Subscribe to station/global channels for live feedback if needed.
- **Station Devices**
  - Connect via Wi‑Fi; access `/station/{id}` UI.
  - Show current session, queue list, and actions.
  - Publish events (transfer, complete, no‑show) and receive queue updates via WebSocket.
- **Informant Displays**
  - Connect via Wi‑Fi; access `/display` UI.
  - Subscribe to `global.queue` for “now serving” and counts.
  - Optionally use `/api/check-status/{qr_hash}` for per‑client lookup.
- **[Post‑MVP] ESP32 Devices**
  - Register with `/api/hardware-units`.
  - Subscribe to `device.{mac}` channels for commands (display text, play sound, flash LEDs).

From a developer’s perspective:

- **Frontend work** mostly lives in Layer 5 and the contracts in Layer 3.
- **Business rules** live in Layer 4 + Layer 2 (services + models).
- **Schema and migrations** live in Layer 1 + Layer 2.

---

### 5. Deployment Scenarios and Trade‑Offs

#### 5.1 Scenario A – Full Setup

- **Pros**
  - Best client experience: large displays, chimes, less shouting.
  - Easier monitoring of queues for both staff and beneficiaries.
- **Cons**
  - Highest hardware cost and most wiring/configuration.
  - More potential failure points (ESP32, screens, speakers).

#### 5.2 Scenario B – Minimal

- **Pros**
  - Uses almost exclusively existing hardware (phones + 1 laptop).
  - Easier to deploy and troubleshoot.
- **Cons**
  - Less visual presence in the waiting area.
  - Heavier cognitive load on staff to keep clients informed verbally.

#### 5.3 Scenario C – Emergency / Field

- **Pros**
  - Maximum resilience; can operate in disaster settings.
  - Very fast to set up and tear down.
- **Cons**
  - Minimal automation; closer to structured manual operations.

The MVP should run well in **Scenario B and C** with no dependency on Scenario A hardware.

---

### 6. Where to Go Next

- For entity definitions and business rules, see `03-domain-model.md`.
- For concrete tables, types, and indexes, see `04-database-schema.md`.
- For request/response contracts and channel naming, see `06-api-and-realtime.md`.

