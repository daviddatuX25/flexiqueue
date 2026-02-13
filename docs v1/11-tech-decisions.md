## Technology Decisions

**Purpose:** Document why specific technologies were chosen for FlexiQueue, and which alternatives were considered and rejected.

Related docs: `01-project-brief.md`, `02-architecture-overview.md`

---

### 1. Backend – Laravel (PHP)

From architecture Section 9.1.

**Why Laravel?**

- **Familiarity:** You already know Laravel, reducing ramp‑up time.
- **Batteries included:** Built‑in auth, queues, events, Eloquent ORM, validation, and middleware.
- **Local‑first friendly:** No mandatory cloud dependencies; runs comfortably on a single laptop.
- **Ecosystem:** Laravel Reverb (WebSockets), Horizon (job monitoring), and robust package ecosystem.

**Alternatives Considered**

- **Node.js**
  - Pros:
    - Rich ecosystem for realtime apps (Socket.io).
  - Cons:
    - Less familiar for this project.
    - Callback/async complexity adds overhead, especially under time pressure.
  - Decision:
    - Rejected in favor of leveraging existing Laravel expertise.

- **Django (Python)**
  - Pros:
    - Mature framework with good ORM and admin tools.
  - Cons:
    - More friction deploying Python stack on Windows laptops under tight timelines.
  - Decision:
    - Rejected due to operational complexity in the target environment.

---

### 2. Frontend – Svelte (via Inertia.js)

From architecture Section 9.2.

**Why Svelte?**

- **Learning goal:** You explicitly want to learn Svelte.
- **Performance:** Compiler‑based approach; no virtual DOM overhead.
- **Bundle size:** Smaller than many alternatives—important for weak Wi‑Fi.
- **Laravel integration:** Inertia.js provides a smooth bridge between Laravel routes and Svelte components, avoiding a separate SPA boilerplate.

**Alternatives Considered**

- **React**
  - Pros:
    - Huge ecosystem and community.
  - Cons:
    - Heavier runtime and mental model (hooks, JSX).
    - Overkill for a focused, offline‑first queue system.
- **Vue**
  - Pros:
    - Excellent for SPAs, similar to Svelte’s goals.
  - Cons:
    - Larger bundles than Svelte; not aligned with the learning goal.
- **Plain Blade / HTML**
  - Pros:
    - Simpler stack.
  - Cons:
    - Too primitive for highly interactive, realtime UIs across many devices.

Decision: **Svelte + Inertia** provides a good balance between developer experience, performance, and the project’s educational goals.

---

### 3. Realtime – Laravel Reverb

From architecture Section 9.3.

**Why Laravel Reverb?**

- **Native to Laravel 12+:**
  - Designed to integrate directly with Laravel’s broadcasting/events system.
- **Offline / local operation:**
  - Does not require external cloud services.
- **Cost:**
  - Free to run locally, unlike hosted services like Pusher.
- **True WebSocket support:**
  - Full‑duplex connections suitable for queue updates and future audio streaming.

**Alternatives Considered**

- **Pusher**
  - Pros:
    - Easy hosted WebSocket solution.
  - Cons:
    - Requires stable internet and monthly subscription; incompatible with offline‑first requirement.
- **Socket.io (Node‑based)**
  - Pros:
    - Mature, widely used for realtime apps.
  - Cons:
    - Would require introducing Node.js backend into a Laravel‑centric stack.

Decision: **Reverb** keeps the stack cohesive and fully local.

---

### 4. Database – MariaDB

From architecture Section 9.4.

**Why MariaDB?**

- **Relational and ACID‑compliant:**
  - Necessary for reliable audit logs and multi‑table relationships.
- **Maturity and familiarity:**
  - Well‑known in the PHP/Laravel ecosystem.
- **Portability:**
  - Runs easily on commodity laptops (Windows/Linux).

**Alternatives Considered**

- **MongoDB**
  - Pros:
    - Flexible document model.
  - Cons:
    - Weak fit for transactional audit data and complex joins (tracks → steps → stations).
- **SQLite**
  - Pros:
    - Simple, file‑based DB.
  - Cons:
    - Concurrency issues with 10–30 devices writing concurrently.

Decision: **MariaDB** is the right balance of reliability, complexity, and deployment practicality.

---

### 5. IoT Hardware – ESP32 (Arduino Framework)

From architecture Section 9.5.

**Why ESP32?**

- **Built‑in Wi‑Fi:**
  - No need for extra modules.
- **I2S Audio Support:**
  - Direct audio output to amplifiers (e.g., MAX98357A).
- **GPIO Richness:**
  - Buttons, LEDs, and sensors can be integrated per station.
- **Cost:**
  - Low unit cost is critical for LGU budgets (~₱200–₱400 per board).
- **Ecosystem:**
  - Large Arduino community and examples, easier to get started.

**Alternatives Considered**

- **Raspberry Pi Zero**
  - Pros:
    - Full Linux environment; very flexible.
  - Cons:
    - Higher cost and overpowered for simple station displays/buttons.
- **Arduino Uno (without Wi‑Fi)**
  - Pros:
    - Very common microcontroller.
  - Cons:
    - Requires separate Wi‑Fi shield; adds cost and complexity.

Decision: **ESP32** fits the price, capability, and ecosystem needs for future hardware extensions.

---

### 6. Architecture Style – Modular Monolith

Implicit in the architecture and methodology.

**Why a Modular Monolith?**

- Single deployment unit:
  - Simpler to deploy on a single laptop server in remote environments.
- Clear internal boundaries:
  - Layered architecture (presentation, services, communications, domain, persistence).
- Evolvable:
  - You can later extract services if multi‑site or cloud sync becomes a priority.

**Alternatives Considered**

- **Microservices**
  - Pros:
    - Scalability and independent deployments.
  - Cons:
    - Operational overhead is unjustified for a single‑site MSWDO deployment.
    - Harder to manage with limited infrastructure and staff.

Decision: A **modular monolith** is the simplest approach that still allows good separation of concerns.

---

### 7. Alignment with Project Constraints

All of these decisions are grounded in the constraints laid out in `01-project-brief.md`:

- Offline‑first and local‑only operations.
- Limited and sometimes unreliable infrastructure.
- Budget and hardware constraints in LGU environments.
- Tight one‑month capstone timeline and existing skills.

When revisiting any of these choices, weigh:

- Does the alternative remain compatible with offline‑first, local‑only operation?
- Does it reduce or increase operational overhead for LGU staff?
- Does it meaningfully improve maintainability or extensibility given current scope?

