This is a professional **System Architecture Document (SAD)** for FlexiQueue.

As your System Architect, I have designed this to balance your immediate MVP needs (Web-based, cost-effective) with your future roadmap (Hybrid Android, IoT Audio Streaming). The core philosophy here is **"Modular Monolith with Event-Driven IoT Extensions."**

---

# **System Architecture: FlexiQueue**

**Type:** Local-First, Offline-Capable, Event-Driven Distributed System
**Primary Stack:** Laravel (Backend), Svelte (Frontend), WebSockets (Real-time), ESP32 (Hardware)

## **1. Architectural Style & Topology**

We are using a **Star Topology** deployed on a **Local Area Network (LAN)**.

* **The Hub:** A portable server (Laptop/Mini-PC) hosting the Application and Database.
* **The Spokes:** Client devices (Staff Phones, Laptops, IoT Devices, Kiosks) connecting via a Local Wi-Fi Hotspot.
* **Communication:**
* **HTTP/REST:** For standard data (saving forms, updating profiles).
* **WebSockets (Full Duplex):** For real-time state changes (Queue updates, Hardware triggers).
* **UDP/Audio Stream:** For the "Phone-to-Speaker" voice feature.



---

## **2. Detailed Layered Architecture**

### **Layer 1: Presentation (The "Web-First" MVP)**

*Rationale: Using Progressive Web Apps (PWA) allows you to deploy to any device with a browser immediately without building native Android APKs yet.*

1. **Triage Interface (The Binder):**
* *Role:* Receptionists.
* *Key Feature:* Rapid QR Scanning (via Camera API). Binds a "Hollow Token" to a "New Session."


2. **Station Interface (The Command Center):**
* *Role:* Desk Staff.
* *Key Feature:* Dynamic "Action Panel" based on the current client. Contains the **Softphone Module** (Mic button).


3. **Informant Interface (The Passive Display):**
* *Role:* Clients / Waiting Area.
* *Key Feature:* High-contrast visibility. Polls for status changes or listens to WebSocket channel `public-updates`.


4. **Admin Dashboard:**
* *Role:* MSWDO Head / IT.
* *Key Feature:* Visual Flow Builder and Device Capability Manager.



### **Layer 2: Application Core (Laravel)**

*The brain of the operation. It manages the logic, not just the data.*

1. **Session Manager (Service):**
* Handles the lifecycle of a client.
* *Logic:* `bind(token_id, program_id)` -> `transfer(session_id, station_id)` -> `complete(session_id)` -> `unbind(token_id)`.


2. **Flow Engine (Service):**
* Determines the "Next Step."
* *Logic:* Checks `Stations` table. If `Staff_Override` exists, prioritize that. If not, return `Default_Next_Station`.


3. **Capability Aggregator (Service):**
* *Critical Logic:* When a Station loads, this service queries the `hardware_units` table.
* *Output:* Returns a configuration object to the Frontend (e.g., `{ "can_broadcast_voice": true, "has_physical_next_button": false }`).


4. **Audit Logger (Middleware):**
* Intercepts every state-changing controller action and writes to an immutable `transaction_logs` table for COA compliance.



### **Layer 3: The IoT & Real-Time Bridge**

*The nervous system connecting software to hardware.*

1. **WebSocket Server (Laravel Reverb / Pusher):**
* **Channel `station.{id}`:** Private channel for the Staff at Station X.
* **Channel `device.{mac}`:** Private channel for specific ESP32 hardware.
* **Channel `global.queue`:** Public channel for the "Informant" screens.


2. **Audio Stream Relay (The "Phone-to-Speaker" Feature):**
* *Architecture:* To minimize latency, we do not save audio to the DB.
* *Flow:* Staff Browser (Web Audio API) captures Mic -> Encodes (Opus/PCM) -> Stream to WebSocket -> Server Broadcasts to `device.{mac}` -> ESP32 (I2S DAC) plays audio.



### **Layer 4: Data Persistence**

1. **Primary DB (MySQL/MariaDB):** Relational data (Programs, Sessions, Hardware).
2. **Config Store (JSON/SQLite):** Local configuration for the Server (IP addresses, Wi-Fi credentials).

---

## **3. The "Phone-as-Microphone" Subsystem Design** (!!!NOT PRIORITY)

You requested a specific feature where standard phones talk to Queue Device speakers. Here is the architecture for that:

* **Source:** Staff Smartphone (Web Interface).
* User holds "Talk" button on screen.
* Browser requests Microphone Permission.
* Audio is captured in chunks (BLOBs).


* **Transport:** WebSocket (Binary Binary Message).
* Payload: `{ "target_device": "esp32_04", "audio_data": <binary_chunk> }`


* **Destination:** ESP32 Queue Device.
* Equipped with **MAX98357A** (I2S Amplifier).
* Listens to WebSocket.
* Buffers incoming audio chunks -> Decodes -> Plays on Speaker.


* **Fallback:** If ESP32 is offline, the Server plays the audio through its own speakers (PA System mode).

---

## **4. Data Flow: The "Life of a Token"**

1. **Initialization (The "Hollow" State):**
* Token `QR_XYZ` exists in DB. `Status: Available`.
* Physical Card sits in a bucket at the entrance.


2. **Binding (The Entry):**
* **Client** walks in.
* **Triage Staff** scans `QR_XYZ` using Phone Camera.
* **System** creates `Session #500` linked to `QR_XYZ`.
* **System** sets `Session #500` status to `Waiting` at `Station: Triage`.


3. **Routing (The Process):**
* **Triage Staff** selects "Cash Assistance" on screen.
* **Flow Engine** calculates: Next stop is `Table 1 (Verification)`.
* **System** updates `Session #500` -> `Current_Station: 1`.


4. **Servicing (The Station):**
* **Staff at Table 1** sees "Incoming: Session #500" on their Web Dashboard.
* **Staff** presses "Call Next" on screen (or physical button).
* **IoT System** triggers `ESP32_Table1` to flash lights and play sound.
* **Informant Screen** updates: "Number A1 -> Table 1".


5. **Completion (The Unbind):**
* Process finishes. Staff clicks "Complete".
* **Session #500** is archived.
* **Token QR_XYZ** is updated to `Status: Available`.
* Client drops card in exit box.



---

## **5. Hardware-Software Deployment Diagram**

```text
[ SITE: Remote Barangay Hall ]

       (Physical Space)                      (Network Space)
    +---------------------+              +----------------------+
    |  Entrance / Triage  |              |    Local WiFi        |
    |  [Phone Scanner]    | <----------> |    (SSID: MSWDO)     |
    +---------------------+              +-----------+----------+
                                                     |
    +---------------------+                          |
    |    Waiting Area     |                          |
    | [Informant Screen]  | <------------------------+
    +---------------------+                          |
                                                     |
    +---------------------+              +-----------v----------+
    |   Service Tables    |              |   Portable Server    |
    |                     |              | (Laptop/RaspberryPi) |
    | [Table 1: Verify]   |              |                      |
    |   - Staff Phone     | <----------> |  [Laravel App]       |
    |   - ESP32 (Display) | <----------> |  [MySQL DB]          |
    |                     |              |  [WebSocket Server]  |
    | [Table 2: Payout]   |              +----------------------+
    |   - Staff Laptop    | <---------->
    |   - ESP32 (Speaker) | <---------->
    +---------------------+

```

## **6. Technology Stack Recommendations**

| Component | Technology | Why? |
| --- | --- | --- |
| **Backend Framework** | **Laravel (PHP)** | You know it. It handles Queues, Events, and Database interaction perfectly. |
| **Frontend Framework** | **Svelte (via Inertia.js)** | You are learning it. It is lightweight, fast, and great for low-end devices. |
| **Real-time Engine** | **Laravel Reverb** | Native WebSocket server for Laravel. No external costs (Pusher) needed. Offline friendly. |
| **Database** | **MariaDB** | Standard, reliable, free. |
| **IoT Firmware** | **C++ (Arduino framework)** | Standard for ESP32. |
| **Local Server OS** | **Ubuntu Server / Windows** | Flexible deployment. |

### **Next Step**

This architecture gives you the "Blueprint" to start coding. Since you are building the MVP first:

**Would you like the Database Migration (Schema) code for the `hardware_units` and `sessions` tables to start building the backend?**
