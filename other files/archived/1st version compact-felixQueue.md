create a more compact version of this yet detailed as you can

FlexiQueue: Compact System Specifications

1\. Overview

Name: FlexiQueue



Type: Web-based Queue Management System with IoT



Architecture: Offline-first PWA



Deployment: On-premises LGU server



2\. Functional Requirements

2.1 Roles \& Capabilities

Admin: Manage programs, tables, staff, devices (register/assign/reassign), analytics



Program Admin: Configure programs, processes, flow rules



Routing Staff: Login to any QRD device, scan/register stubs, assign to tables



Processing Staff: Login to any QPD device at table, handle stubs, mark processes complete, logout (device reusable)



Client: Scan stub at QID informant kiosk to view status/queue (no login needed)



2.2 Core Modules

Stub Management: Unique UUID + alias (Category Service Number), QR code, lifecycle: UNREGISTERED→REGISTERED→IN\_PROGRESS→COMPLETED→EXPIRED



Program Management: Create programs, assign process types, define flow rules (AUTO/MANUAL)



Table Management: Configure tables with max capacity, assign process capabilities



Device Management: Central registry of all physical devices (phones, tablets, IoT), dynamic assignment to programs/tables, staff login sessions (not permanent binding)



Device Types: QPD (processing staff), QRD (routing staff), QID (client informant), IoT Display (passive queue display)



Process Flow Engine: Track WAITING→IN\_PROGRESS→COMPLETED, support parallel processing, allow overrides with logging



Queue Module: Dynamic SQL-based queue per table, capacity-based routing



Audit \& Reporting: Immutable logs, role-based access, daily/program reports, device session tracking



3\. Technical Specs

3.1 Tech Stack

Backend: Laravel (PHP) or FastAPI (Python)



Frontend: Vue.js/Svelte PWA with Tailwind



Database: PostgreSQL (production), SQLite (dev)



Real-time: WebSocket (Laravel Echo/Socket.io) + MQTT (Mosquitto) for IoT



IoT: ESP32 + LCD/TFT + DFPlayer Mini for audio



3.2 Key Tables

stub (stub\_id, alias\_name, qr\_code)



program (program\_id, name, active, dates)



process\_type (name, description)



service\_table (table\_id, program\_id, max\_capacity)



staff (staff\_id, username, password\_hash, role) – **No table\_id binding**



device (device\_id, device\_uuid, device\_type, mac\_address, location)



device\_assignment (device→program→table, role, assigned\_by, timestamps)



device\_session (device→staff login, session\_token, timestamps)



stub\_program (links stub + program + status)



stub\_process\_status (tracks process per stub)



audit\_log (comprehensive action logging)



3.3 API Highlights

POST /api/stubs/register – Register stub to program



POST /api/process/complete – Mark process done



GET /api/tables/{id}/queue – Get queue for table



POST /api/queue/call-next – Call next stub



GET /api/reports/daily – Daily summary



**Device Management APIs:**



POST /api/admin/devices – Register device



POST /api/admin/devices/{uuid}/assign – Assign device to program/table



POST /api/devices/{uuid}/login – Staff login (create session)



POST /api/devices/{uuid}/logout – Staff logout



GET /api/admin/devices/{uuid}/assignments – View assignment history



4\. Deployment

4.1 Architecture

LAN-only (192.168.1.x)



Central Server: Orange Pi (portable, fanless, 8GB RAM, 128GB SSD)



Components: App server, PostgreSQL, MQTT broker



Client Devices: Staff phones/tablets (Android 8+/iOS 12+), IoT devices (ESP32), Client kiosks (tablets)



Device Model: Central registry → Dynamic assignment per program → Staff login sessions (reusable devices)



4.2 Hardware Requirements

Server: Orange Pi 5+ (8GB, 128GB, ₱5,000) or equivalent



Staff devices: 2-3 Android tablets (₱15,000-25,000)



IoT displays: 2-3 ESP32 + TFT displays (₱3,000-5,000)



Informant kiosk: 10" tablet (₱12,000) or IoT kiosk (₱15,000)



Network: WiFi 5 AP, Ethernet cables (₱5,000)



4.3 Installation & Device Setup

Install OS (Ubuntu 22.04), PHP/Python, PostgreSQL, Node.js, Mosquitto



Create DB and admin user



Deploy Laravel/FastAPI app, run migrations



Configure Nginx, WebSocket server



Register devices via admin panel (one-time per device)



Assign devices to programs/tables (can change anytime)



Staff login via app on assigned device (creates session)



Set up ESP32 displays with MQTT (pair button or admin assign)



5. Key Architectural Shifts

**Old Model (Flawed):**
- Device permanently tied to one table
- Staff assigned to one table
- Program ends → Device unusable until reconfigured
- Staff rotation = manual device reassignment

**New Model (Flexible):**
- Devices = reusable inventory assets
- Devices assigned to programs dynamically
- Staff login sessions (not permanent binding)
- Program change = instant device reassignment, no downtime
- Multiple staff can use same device (sequential logins)
- Device paired once, assigned many times

**Benefits:**
- Supports multiple concurrent programs at same location
- Staff flexibility (no device-to-person binding)
- Reduced hardware costs (fewer devices needed)
- Instant program rotation without re-pairing
- Full audit trail of assignments and sessions

6. Implementation Phases (5 Months)

Months 1-2: Core system (DB, APIs, stub mgmt, flow engine, admin UI, device registry)



Month 3: Device interfaces (QPD, QRD, QID apps) + device management dashboard



Month 4: IoT integration (MQTT, ESP32 displays, audio), device session tracking



Month 5: Testing, pilot deployment at MSWDO, staff training



6\. Costs

Hardware: ~₱55,000 (Orange Pi ₱5K, tablets ₱20K, IoT displays ₱5K, kiosk ₱15K, network ₱5K)



Dev: ~₱150,000-200,000 (2-3 devs × 5 months)



Annual ops: ~₱15,000 (electricity, maintenance)



5-year TCO: ~₱235,000-285,000 (vs ₱450,000+ for commercial systems)



7\. Success Metrics

Uptime >99%, response <2s, user satisfaction >4/5



Reduce processing time by 20%, <5 queue confusion incidents/day



8\. Future Enhancements

Mobile apps, SMS notifications, biometric integration



Multi-site sync, AI flow optimization, citizen portal



Key Advantages:



Program-agnostic, privacy-focused (aliases, no PII)



Offline-capable, audit-ready, scalable, device-flexible



Open source, no licensing fees



Suitable for thesis defense and LGU pilot deployment



Device-efficient (reusable inventory model), supports multi-program rotation



Document: v1.1 (Device Management), Jan 2026

Author: David Datu N. Sarmiento

