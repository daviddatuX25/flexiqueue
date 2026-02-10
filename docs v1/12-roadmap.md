## Roadmap and Future Capabilities

**Purpose:** Capture planned post‑MVP features, system limits, and success metrics so the current architecture stays compatible with long‑term goals without overloading the MVP.

Related docs: `01-project-brief.md`, `02-architecture-overview.md`, `06-api-and-realtime.md`, `08-edge-cases.md`

---

### 1. Capability Aggregation System (Post‑MVP IoT)

From architecture Section 6.

**Problem:** A station may have multiple heterogeneous devices (e.g., a display‑only ESP32, a button‑only ESP32, and a staff phone). The system needs to know what the station as a whole “can do.”

**Concept:**

- Each `HardwareUnit` has a `capabilities` JSON describing what it supports.
- For a given station, the system aggregates all capabilities of its devices (including phones) to produce a **combined capability profile**.

**High‑Level Algorithm:**

```text
FUNCTION getStationCapabilities(station_id):

  1. Query all hardware_units WHERE station_id = X.

  2. Merge capabilities JSON from all units.
     Example:
       Unit 1: {"has_display": true}
       Unit 2: {"has_buttons": true}
       Phone:  {"has_scanner": true, "has_microphone": true}

     Result:
       {
         "has_display": true,
         "has_buttons": true,
         "has_scanner": true,
         "has_microphone": true,
         "has_speaker": false
       }

  3. Evaluate requirements based on station.role_type:
     - Triage: require scanner, warn if no display.
     - Processing: require either display or buttons, warn if no speaker.

  4. Return:
     {
       "capabilities": { ... },
       "warnings": [ ... ],
       "ui_mode": "phone_primary" | "device_primary" | "hybrid"
     }
```

**UI Adaptation Examples:**

- If `has_buttons = true`:
  - Hide software “Next” button and rely on physical buttons.
- If `has_speaker = false`:
  - Show prompt “Manually call client: A1” instead of playing audio.
- If `has_microphone = true`:
  - Show “Hold to Talk” button for future audio relay features.

> MVP Note: Keep the `capabilities` JSON and basic station/device modeling in place now; implement aggregation and dynamic UI behavior in later phases.

---

### 2. Audio Streaming to Hardware Speakers

From architecture Section 7.2 (post‑MVP).

**Goal:** Allow staff devices to send voice or announcements to station‑mounted speakers.

**Pipeline Overview:**

1. **Staff Phone (Browser)**
   - Use WebAudio API (`getUserMedia`) to capture microphone.
   - Encode audio chunks (e.g., Opus).
   - Stream via binary WebSocket to the server.

2. **Server (Laravel + Audio Relay Service)**
   - Validate the sending staff and target hardware.
   - Forward encoded chunks to relevant devices via `device.{mac}` or dedicated audio channels.

3. **ESP32 Speaker**
   - Receive binary audio frames.
   - Decode and play audio through I2S amplifier (e.g., MAX98357A).

**Latency Target (~110 ms):**

- Capture: ~20 ms.
- Encoding: ~10 ms.
- Network (local Wi‑Fi): ~50 ms.
- Decoding: ~10 ms.
- Playback: ~20 ms.

> Design implication: current channel naming and authentication should leave room for audio commands and streams but does not need implementation in the MVP.

---

### 3. System Limits (Current Target Scale)

From architecture Section 12.1.

Approximate design limits for a single deployment:

- **Concurrent sessions:** ~200 active.
- **ESP32 devices:** ~20 concurrent (future phase).
- **Staff users:** ~10 concurrent.
- **Database footprint:** ~50 MB per event (around 10,000 transactions).
- **Network load:** ~2 Mbps peak for WebSocket + potential audio.

These limits inform:

- Query/index design (see `04-database-schema.md`).
- Health monitoring thresholds.
- Hardware sizing recommendations in `10-deployment.md`.

---

### 4. Phased Roadmap

From architecture Section 12.2.

#### 4.1 Phase 1 – MVP (Current)

- Web interfaces only (PWA for triage, stations, informant, admin).
- Single‑site deployment, local Wi‑Fi, offline‑first.
- Manual token distribution (pre‑printed QR cards).
- Core features:
  - Program/track/station configuration.
  - QR token binding and track‑aware routing.
  - Station queues, transfer, completion, no‑show.
  - Supervisor overrides and COA‑oriented audit logs.

#### 4.2 Phase 2 – 3–6 Months

- **Hybrid Android app** using Capacitor.js or similar:
  - Wrap PWA for better camera integration and kiosk locking.
- **Thermal printer integration:**
  - Automatically print tokens with aliases at triage.
- **SMS notifications:**
  - Notify clients of upcoming turn when cell signal is available.

#### 4.3 Phase 3 – 6–12 Months

- **Multi‑site sync:**
  - Replicate data between multiple MSWDO servers using USB or intermittent internet.
- **Advanced analytics:**
  - Wait time distributions, bottleneck detection, and staffing recommendations.
- **Voice announcements and TTS:**
  - Automatic read‑outs of “Now serving A1 at Table 2”.

#### 4.4 Phase 4 – 1+ Years

- **Cloud‑optional mode:**
  - Sync to a central system when internet is present, but still functional offline.
- **Mobile‑first redesign:**
  - Revisit UX from scratch for multi‑LGU usage.
- **Open API:**
  - Allow third‑party integrations with treasury, health, and other LGU systems.

---

### 5. Success Metrics

From architecture Sections 13.1 and 13.2.

#### 5.1 Technical KPIs

- System performance:
  - 99% uptime during operations.
  - \< 1 second average latency for queue updates to displays.
  - Zero data‑loss incidents across events.

- Hardware reliability:
  - \< 5% device failure rate.
  - \< 2 minutes average recovery time when failures occur.

- Usability:
  - **System Usability Scale (SUS)** score ≥ 68 (“Good”), targeting 80+ (“Excellent”).

#### 5.2 Operational KPIs

- Efficiency:
  - 50% reduction in manual queue management efforts.
  - 30% increase in client throughput during events.
  - 100% completeness of audit trails for COA.

- Cost:
  - \< ₱15,000 total hardware for a typical deployment.
  - ₱0 recurring cloud subscription costs.
  - \< 2 hours setup/teardown time per event.

These metrics should be used to:

- Evaluate MVP success during pilot deployments.
- Prioritize future backlog items that deliver concrete improvements rather than cosmetic changes.

---

### 6. Using This Roadmap

- **During MVP implementation:**
  - Avoid design decisions that would block planned Phase 2–4 features (e.g., overly rigid channel naming, hardcoded assumptions about having no hardware).
- **For future work planning:**
  - Use this document to create issues/epics for each phase.
  - Align enhancements with the success metrics rather than feature count alone.

