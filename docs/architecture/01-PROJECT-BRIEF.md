# FlexiQueue — Phase 1 Project Brief

**Purpose:** Orient the execution agent on what FlexiQueue is, who it's for, why it exists, what constraints bind Phase 1, and the tech stack being used.

---

## 1. What Is FlexiQueue?

FlexiQueue is an **offline-first, phone-first queue management system** for government social welfare operations in the Philippines.

**In one sentence:** Staff scan reusable QR token cards to track clients through multi-station service flows — with every action audit-logged for COA compliance — running entirely on a local Wi-Fi network with no internet.

---

## 2. Who Is It For?

**Primary Client:** MSWDO (Municipal Social Welfare & Development Office) of Tagudin, Ilocos Sur.

**Users:**

| User | Device | What They Do |
|------|--------|-------------|
| **Admin** | Laptop browser | Configure programs, tracks, stations, tokens, staff. View reports. |
| **Supervisor** | Phone or laptop | Approve overrides, reassign staff, monitor operations. |
| **Staff** | Smartphone (PWA) | Operate assigned station: call next, serve, transfer, complete. |
| **Public / Client** | Kiosk tablet or own phone | Read-only: check queue status, see "Now Serving" board. |

**Non-technical users.** Staff are MSWDO employees with minimal tech training. The UI must be self-explanatory with large touch targets.

---

## 3. Why Does It Exist?

### The Problem
- MSWDOs rely on **manual queue management**: paper tickets, verbal calling, ad-hoc coordination.
- **Congested waiting areas**, perceived favoritism, staff burnout.
- **Weak auditability** — COA (Commission on Audit) compliance is difficult.
- **No internet** at remote barangay deployment sites.
- Existing LGU systems (iSupport, iServe) focus on record management, not **real-time queue orchestration**.

### The Solution
- Portable server creates its own Wi-Fi network.
- Staff use their existing phones as station terminals.
- Reusable QR token cards (A1, A2...) replace paper tickets — no PII stored.
- Track-based routing (Regular / Priority / Incomplete Docs) with supervisor overrides.
- Immutable transaction logs for full COA audit trail.

---

## 4. Phase 1 Constraints

| Constraint | Value |
|-----------|-------|
| **Timeline** | ~1 month (capstone project, RAD methodology) |
| **Deployment** | Single site (MSWDO Tagudin, Ilocos Sur) |
| **Network** | Closed local Wi-Fi LAN, no internet |
| **Hardware** | 1 laptop server + existing staff phones + optional kiosk tablet |
| **Active Programs** | One at a time (simultaneous programs deferred to Phase 2) |
| **IoT / ESP32** | Fully excluded from Phase 1 — no `hardware_units`, no `device_events` |
| **Audio Streaming** | Excluded — staff call clients verbally |
| **Offline Resilience** | Basic: offline banner + PWA shell caching. Full IndexedDB replay is Phase 2 |

---

## 5. Tech Stack

| Layer | Technology | Version | Notes |
|-------|-----------|---------|-------|
| **Backend** | Laravel | 11.x | Modular monolith, session-based auth |
| **Frontend** | Svelte | 5.x | Via Inertia.js (server-driven SPA) |
| **Bridge** | Inertia.js | Latest | Connects Laravel routes to Svelte pages |
| **Styling** | TailwindCSS | 4.x | Utility-first, mobile-first |
| **Database** | MariaDB | 10.6+ | ACID-compliant, relational |
| **Real-time** | Laravel Reverb | Latest | Local WebSocket server on port 6001 |
| **Build** | Vite | Latest | Frontend bundling |
| **Runtime** | PHP 8.2+ | — | With Composer |
| **JS Runtime** | Node.js 20+ | — | For frontend tooling only |

### Why These Choices (Summary)

- **Laravel**: familiar to developer, batteries-included (auth, ORM, events, broadcasting).
- **Svelte**: learning goal + compiler-based performance + small bundles for weak Wi-Fi.
- **Inertia.js**: avoids separate SPA boilerplate, server-driven routing.
- **MariaDB**: relational integrity for audit logs, proven with Laravel.
- **Reverb**: native Laravel WebSockets, runs locally, no cloud dependency.

For detailed rationale, see `docs v1/11-tech-decisions.md`.

---

## 6. Non-Functional Requirements

| Category | Requirement |
|----------|------------|
| **Performance** | UI actions < 500ms response time on local Wi-Fi |
| **Capacity** | 100+ concurrent active sessions |
| **DB Queries** | < 100ms average |
| **Uptime** | 99% during 8-hour event operations |
| **Offline** | Core functions survive brief Wi-Fi drops; auto-recover |
| **Security** | RBAC (Admin/Supervisor/Staff/Public), no PII stored, append-only audit logs |
| **Usability** | SUS score >= 68 ("Good"), staff onboarding < 3 minutes |

---

## 7. Glossary

| Term | Definition |
|------|-----------|
| **Program** | A government assistance event (e.g., "Social Pension Payout"). Only one active at a time. |
| **Service Track** | A demographic-specific pathway through a program (e.g., Regular, Priority, Incomplete Docs). |
| **Track Step** | An ordered position in a track, mapping to a station (Step 1 → Triage, Step 2 → Interview, ...). |
| **Station** | A logical service point / desk (e.g., "Verification Desk", "Cashier"). Roles: triage, processing, release. |
| **Token** | A reusable physical QR card (e.g., "A1", "B15") carried by a client. Identified by SHA-256 hash. |
| **Session** | A client's journey through the system, bound to one token. States: waiting, serving, completed, cancelled, no_show. |
| **Bind** | Linking a token to a newly created session at triage. |
| **Unbind** | Releasing a token after session completion, cancellation, or no-show. Token becomes `available`. |
| **Override** | A supervisor-approved deviation from the standard track flow. Requires PIN + reason. Fully logged. |
| **Transaction Log** | An immutable audit record of every state change. Append-only — no updates or deletes allowed. |
| **Alias** | The display name for a session, derived from the token's physical ID (e.g., "A1"). Unique among active sessions. |
| **COA** | Commission on Audit — Philippine government body requiring auditable records of public fund distribution. |
| **MSWDO** | Municipal Social Welfare and Development Office — the target client organization. |
| **PWA** | Progressive Web App — the frontend runs as an installable web app on staff phones. |

---

## 8. Document Map

The execution agent should consult these docs in this order:

| Doc | Purpose | When to Read |
|-----|---------|-------------|
| `01-PROJECT-BRIEF.md` | This file — context and orientation | Start of any session |
| `02-ARCHITECTURE-OVERVIEW.md` | System topology, layers, ports | Setting up project or infrastructure |
| `03-FLOW-ENGINE.md` | State machine, routing logic, edge cases | Implementing session operations |
| `04-DATA-MODEL.md` | Database schema, relationships, constraints | Writing migrations, models, queries |
| `05-SECURITY-CONTROLS.md` | Auth, RBAC, PIN system, audit integrity | Implementing auth, middleware, policies |
| `06-ERROR-HANDLING.md` | Error patterns, response format, exception classes | Writing any controller or service |
| `08-API-SPEC-PHASE1.md` | Endpoint contracts (request/response/logic) | Implementing any API endpoint |
| `09-UI-ROUTES-PHASE1.md` | Routes, component trees, state management | Building any Svelte page |
| `PHASES-OVERVIEW.md` | Scope freeze, what's in/out, success metrics | Checking if a feature is Phase 1 |
| `QUALITY-GATES.md` | Testing standards, coverage targets | Before marking any bead as done |
| `PHASE-1-TASKS.md` | The bead backlog with dependencies | Picking next work item |
