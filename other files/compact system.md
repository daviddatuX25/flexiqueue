Below is a **compact, mockup-presentation-ready “knowledge pack”** of FlexiQueue, **optimized for: staff phones + 1 portable server + optional kiosk (tablet + 2D scanner)**. (No ESP32 add-ons for now.)

---

## FlexiQueue in One Sentence

**FlexiQueue is an offline-first queue system for MSWDO barangay operations that runs on a local Wi-Fi hotspot, letting staff control client flow using phones—while keeping an audit trail for every action.**

---

## Core Goal

Enable **organized, track-based queueing** (Regular / Priority / Incomplete Docs) in remote areas **without internet**, using **existing staff phones** and a **portable server**.

---

## Minimal Hardware Setup (Preferred)

**Portable Server (Laptop/RPi)**

* Hosts the app + database + real-time updates
* Creates local Wi-Fi hotspot (or uses router)

**Staff Phones (Main Operators)**

* Triage phone: scan token, bind client, choose track
* Station phones: call next, serve, transfer/override/complete

**Optional Kiosk (Still Included)**

* **Tablet display + 2D scanner** for self-check (or assisted-check) status
* Used in waiting area to reduce “tanong nang tanong” load

---

## How It Works (Very Simple Flow)

1. **Client gets a reusable QR token card** (A1, A2, …)
2. **Triage staff scans** → selects category/track → session starts
3. Client waits; **Station staff calls next** from their phone
4. Staff **transfers** client to next step, or **overrides** if needed
5. When done, staff **completes** the session → token becomes reusable
6. Every step creates a **transaction log** for auditing

---

## Main Roles (For Mockups)

* **Admin** – configure program, tracks, stations; view reports
* **Supervisor** – approves overrides / force actions (PIN)
* **Staff** – operates assigned station (call, serve, transfer, complete)
* **Public/Kiosk** – read-only status lookup (no login)

---

## 4 Core Screens (That You’ll Mockup)

1. **Triage (Phone)**
   Scan QR → select category/track → confirm bind

2. **Station (Phone)**
   Call next → show current client → transfer/override/no-show/complete

3. **Informant / Kiosk (Tablet)**
   Client checks status + “Now Serving” board

4. **Admin Dashboard (Laptop/Desktop)**
   Live counts + station status + devices + audit export

---

## System Architecture (Compact View)

### Physical Topology

* **Portable server** = Wi-Fi hub + app host
* **Phones** connect via local Wi-Fi
* **Kiosk** connects via same Wi-Fi

### App Building Blocks

* **Frontend (PWA)**: Triage / Station / Kiosk / Admin
* **Backend**: Laravel app (business rules + API)
* **Realtime**: WebSocket broadcast (live queue updates)
* **Database**: MariaDB (sessions + logs)

---

## Key Data Concepts (Easy to Explain)

* **Program** = today’s event (e.g., “Cash Assistance Distribution”)
* **Track** = lane/path (Regular / Priority / Incomplete)
* **Station** = service point (Verification, Interview, Cashier…)
* **Token** = reusable QR card (physical ID like “A1”)
* **Session** = active journey of a token through steps
* **Transaction Log** = immutable record of every action (audit-ready)

---

## Core Rules (Why This Is “Audit-Ready”)

* No “silent changes”: **every status/station change is logged**
* Logs are **append-only** (no update/delete)
* Overrides require **supervisor + reason**
* Token reuse is controlled: **one active session per token**

---

## What Makes It “Offline-First”

* Runs entirely inside **local Wi-Fi**
* Phones use browser/PWA (no Play Store needed)
* If Wi-Fi drops briefly:

  * staff can keep operating manually
  * system resumes once network returns

---

## Kiosk Use (Simple Version)

**Purpose:** reduce crowd questions + let clients self-check
**Features:**

* Scan token → show current step, station, estimated wait
* Show “Now Serving” board + per-station queue counts

> If kiosk is too heavy, it can be replaced by **one staff phone in “Display Mode.”**

---

## What You Can Say During Presentation (Script-y)

* “We prioritize **phones first** so MSWDO can deploy with minimal cost.”
* “The server creates its own Wi-Fi—**no internet needed**.”
* “The kiosk is optional; it’s mainly for **status checking + now serving**.”
* “Every action is logged, so after the event we can export an **audit report**.”

---

## MVP Scope (Phone-First)

✅ Program + Tracks + Stations setup (Admin)
✅ QR token bind (Triage)
✅ Queue per station + call next (Station)
✅ Transfer + complete + no-show
✅ Override with supervisor PIN + reason
✅ Informant board (kiosk or display mode)
✅ Audit export

---