## FlexiQueue Documentation

FlexiQueue is a **local‑first, offline‑capable queue management system** for Municipal Social Welfare and Development Offices (MSWDO), designed for portable deployment in remote barangays using a laptop/portable server and staff phones.

This `docs/` folder shards the original, long-form architecture and thesis context into focused markdown files. Each file answers one main developer question so you can open only what you need.

### Reading Order (for New Contributors)

1. `01-project-brief.md` – **Why this exists, for whom, and MVP boundaries**
2. `02-architecture-overview.md` – **High-level system shape and deployment topology**
3. `03-domain-model.md` – **Core entities, rules, and relationships**
4. `04-database-schema.md` – **Concrete SQL tables and constraints**
5. `05-flow-engine.md` – **State machine and routing logic**
6. `06-api-and-realtime.md` – **HTTP API and WebSocket contracts**
7. `07-ui-ux-specs.md` – **Screens and design system for all clients**
8. `08-edge-cases.md` – **Operational edge cases and recovery patterns**
9. `09-security.md` – **Roles, permissions, and audit integrity**
10. `10-deployment.md` – **Hardware, installation, and operations**
11. `11-tech-decisions.md` – **Technology choices and alternatives**
12. `12-roadmap.md` – **Post‑MVP capabilities and scaling plans**

### File Index

| File | Purpose |
|------|---------|
| `01-project-brief.md` | Project background, problem context (MSWDO Tagudin), guiding principles, non‑functional requirements, MVP scope and limitations, objectives, evaluation target (SUS), and glossary. |
| `02-architecture-overview.md` | Physical topology, network layout, deployment scenarios, and the 5‑layer logical architecture. |
| `03-domain-model.md` | Conceptual entity model (Program, ServiceTrack, TrackStep, Station, HardwareUnit, Token, Session, TransactionLog, Users, DeviceEvents) with business rules. |
| `04-database-schema.md` | SQL‑level schema for all tables, JSON columns, indexes, and key constraints, plus an example data flow. |
| `05-flow-engine.md` | Flow engine design, session lifecycle state machine, multi‑track routing, and how standard paths and overrides interact. |
| `06-api-and-realtime.md` | REST endpoints grouped by audience (public/staff/admin) and WebSocket channels with message payload shapes; includes post‑MVP audio streaming notes. |
| `07-ui-ux-specs.md` | Design tokens (colors, typography, spacing) and detailed specs for Triage, Station, Informant Display, and Admin Dashboard screens. |
| `08-edge-cases.md` | Ghost client, double scan, WiFi blackout, rogue hardware, process skipper, token swap fraud, plus a summary edge‑case matrix. |
| `09-security.md` | Threat model, role‑based access control, data protection approach, and audit log integrity guarantees. |
| `10-deployment.md` | Hardware requirements, on‑site deployment procedure, monitoring and health checks, and backup strategy. |
| `11-tech-decisions.md` | Rationale for Laravel, Svelte, MariaDB, Laravel Reverb, ESP32, and rejected alternatives. |
| `12-roadmap.md` | Capability aggregation system, IoT/audio extensions, system limits, long‑term roadmap, and success metrics. |

### Quick Reference

- **Entities and rules:** `03-domain-model.md`
- **Database tables and columns:** `04-database-schema.md`
- **Session flow and state transitions:** `05-flow-engine.md`
- **HTTP endpoints:** `06-api-and-realtime.md`
- **WebSocket channels and events:** `06-api-and-realtime.md`
- **Screen layouts and components:** `07-ui-ux-specs.md`
- **Edge‑case handling logic:** `08-edge-cases.md`
- **Deployment runbook:** `10-deployment.md`

