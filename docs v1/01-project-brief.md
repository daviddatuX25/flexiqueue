## FlexiQueue Project Brief

**Role:** Full‑stack developer  
**Audience:** Developers and technical stakeholders onboarding to FlexiQueue  
**Goal:** Capture the *why*, *for whom*, and *MVP boundaries* of the system in a concise, implementation‑oriented brief.

---

### 1. Problem Context

Municipal Social Welfare and Development Offices (MSWDOs) in the Philippines still rely heavily on **manual queue management**: paper tickets, verbal calling, and ad‑hoc coordination between staff. This leads to:

- **Congested waiting areas** and long, opaque queues.
- **Perceived favoritism** and inequity among beneficiaries.
- **High staff burnout** caused by constant firefighting and lack of tools.
- **Weak auditability**, making COA‑compliant reporting difficult.

The **Mandanas‑Garcia ruling** and devolution have expanded LGU responsibilities and budgets without proportionate improvements in digital infrastructure. Many LGUs—including Tagudin, Ilocos Sur—still run on handwritten forms, Excel sheets, and siloed record systems, with **unstable internet connectivity** and **resource constraints** (limited devices, low incomes, disaster‑prone geography).

There is a clear research and operational gap: existing LGU systems (e.g., iSupport, iServe) focus on **record management**, not **real‑time queue orchestration** during high‑volume program implementations.

---

### 2. Solution Overview

**FlexiQueue** is a **universal, offline‑first, table‑based queue management system** for multi‑service government operations, initially targeted at the **MSWDO of Tagudin, Ilocos Sur**.

Core characteristics:

- **Local‑first architecture** running on a **portable server** (laptop or small PC) with a self‑contained Wi‑Fi network.
- **Phone‑first UX**: staff use existing smartphones; optional kiosk tablets; ESP32/IoT is *post‑MVP*.
- **Token‑based queueing** using reusable QR cards (e.g., A1, B5) to protect privacy while preserving full auditability.
- **Program‑ and track‑aware flow engine**: supports multiple assistance programs and per‑demographic tracks (regular, priority, incomplete docs).
- **Commission on Audit (COA)‑ready audit trail** through immutable transaction logs.

---

### 3. Driving Principles

From the architecture’s Section 1.1:

| Principle | Implementation |
|----------|----------------|
| **Offline‑First** | Entire system runs on a local server and LAN, with no cloud dependency. |
| **Portable** | Laptop or Raspberry Pi acts as the hub, deployable to barangay halls, gyms, or open fields. |
| **Hardware‑Agnostic** | Adapts to whatever devices are available (phones, tablets; ESP32 later). |
| **Audit‑Ready** | Immutable transaction logs and COA‑compliant reporting. |
| **Staff‑Empowered** | Human judgment and overrides are first‑class, not afterthoughts. |

---

### 4. Key Non‑Functional Requirements

From architecture Section 1.3:

- **Performance**
  - UI response time \< 500 ms for common actions.
  - 100+ active client sessions supported on local Wi‑Fi.
  - Average DB query time \< 100 ms.
- **Reliability**
  - 99% uptime during 8‑hour events.
  - Graceful degradation when Wi‑Fi drops; automatic resync when back online.
  - Auto‑save / idempotent operations to prevent data loss.
- **Security & Privacy**
  - Local network only; no external attack surface.
  - Role‑based access control (Admin / Supervisor / Staff / Public).
  - No PII stored in sessions; tokens are anonymous aliases (e.g., A1).

These NFRs should inform all performance, UX, and infrastructure decisions.

---

### 5. MVP Scope (One‑Month Capstone)

The one‑month MVP focuses on a **phone‑first, ESP32‑free** implementation:

**In Scope**

- Program, track, and station configuration via an admin UI.
- Track‑based flow definition (e.g., regular vs. priority vs. incomplete docs).
- QR token management and binding to sessions at triage.
- Per‑station queues with:
  - Call next / serve / transfer to next station.
  - Mark complete / cancel / no‑show.
- Supervisor overrides (with PIN + mandatory reason).
- Informant display mode (phone/tablet kiosk or wall‑mounted screen) showing:
  - Now serving per station.
  - Client self‑status via QR scan.
- COA‑oriented audit log export after events.

**Out of Scope (Post‑MVP)**

- ESP32‑based displays, buttons, and speakers.
- Real‑time audio streaming from phones to hardware speakers.
- Integration with external systems (e.g., DSWD Listahanan, treasury).
- Predictive analytics and advanced reporting.
- Native Android/iOS apps for beneficiaries.

Anything in the architecture tagged as hardware, audio streaming, multi‑site sync, or long‑term analytics belongs in the **roadmap**, not in MVP implementation.

---

### 6. Objectives (Developer Translation)

From Chapter 1 objectives, restated for implementation:

1. **Document and reflect real operations:** The system must encode actual MSWDO Tagudin processes, bottlenecks, and program‑specific requirements—not an idealized model.
2. **Deliver a portable, offline‑first, table‑based queue system:** Runs on budget‑friendly hardware, using staff phones and local Wi‑Fi only.
3. **Achieve acceptable usability:** Target a **System Usability Scale (SUS)** score ≥ 68 (“Good”) across admin, staff, and IT expert users.

These objectives should drive backlog prioritization and acceptance criteria.

---

### 7. Scope and Limitations (What the System Does *Not* Do)

Key constraints distilled from Chapter 1:

- **No direct integration** with national DSWD databases (e.g., Listahanan 3.0); all eligibility checks remain manual.
- **LAN‑only access:** only devices connected to the local hotspot can use the system; there is no internet or VPN requirement.
- **No financial processing:** cash disbursement and reconciliation remain manual; the system only manages queues and audit logs.
- **No biometrics:** identity verification is done via physical IDs and staff checking; no fingerprint or facial recognition.
- **Single‑site deployment:** architecture supports future multi‑site sync, but MVP targets **one MSWDO site** only.
- **No cross‑system integration:** initial version does not integrate with treasury, health, or other LGU systems.

When adding features, check they do not implicitly break these constraints without explicit scope negotiation.

---

### 8. Methodology and Timeline (For Dev Planning)

The capstone uses **Rapid Application Development (RAD)**:

- **Requirements Planning (≈ 1 week)**  
  Interviews, observations, and documentation of current queue workflows and pain points.

- **User Design (≈ 2 weeks, overlapping)**  
  Rapid prototyping of admin, staff, and informant UIs; iterative feedback from MSWDO staff and adviser.

- **Construction (≈ 2 weeks)**  
  Laravel backend, Svelte frontends, MariaDB schema, WebSocket integration, plus unit and integration tests.

- **Cutover (≈ 2 weeks)**  
  Pilot deployment during an actual assistance event, SUS usability testing, training, and documentation.

This methodology justifies **iterative delivery**: it is acceptable (and expected) to refine screens, flows, and schema as real feedback arrives, as long as high‑level architecture remains consistent.

---

### 9. Success Metrics

From architecture Sections 13.1 and 13.2:

- **Technical KPIs**
  - 99% uptime during operations.
  - \< 1 second average latency between backend updates and client displays.
  - Zero data‑loss incidents during events.
- **Operational KPIs**
  - 50% reduction in manual queue management workload.
  - 30% improvement in client throughput.
  - 100% completeness of audit trails.
- **Usability KPI**
  - SUS score ≥ 68 (targeting 80+ as “Excellent”).

These metrics provide a yardstick for “done” beyond just feature checklists.

---

### 10. Glossary (Working Definitions)

- **Program** – A specific government assistance event (e.g., Social Pension Payout).
- **Service Track** – A configured pathway for a demographic group (e.g., Regular, Priority, Incomplete Docs).
- **Station** – A logical service point mapped to a table or desk (e.g., Triage, Verification, Cashier).
- **Token** – A reusable physical QR card (e.g., A1, B5) carried by a client.
- **Session** – A client’s journey through the system, bound to a token.
- **Bind** – Linking a token to a newly created session.
- **Unbind** – Releasing the token after completion or cancellation.
- **Override** – A supervisor‑approved deviation from the standard track (e.g., skipping or reordering stations).
- **Capability** – What a hardware unit can do (display, buttons, speaker, scanner, etc.), relevant to post‑MVP IoT extensions.

For more detailed architectural views, continue with `02-architecture-overview.md`.

