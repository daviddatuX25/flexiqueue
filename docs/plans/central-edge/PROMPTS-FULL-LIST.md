# Central+Edge — Full List of Per-Iteration Prompts

Use these prompts in order.

**What you do per session:** (1) Pick the next session below. (2) Copy that session’s full prompt. (3) Paste into chat; for best context for the agent/subagents, optionally tag `@ITERATION-RITUAL.md` and/or `@docs/plans/central-edge/specs/central-edge-v2-final.md`. (4) Run the session. (5) After: run tests, do manual checks, tick off completed tasks in [central-edge-tasks.md](central-edge-tasks.md).

The session prompt tells the agent to follow the ritual in [ITERATION-RITUAL.md](ITERATION-RITUAL.md) (plan & confirm first, then TDD and implement). Rules from `.cursor/rules/` are applied by name.

**Current progress:** A.1, A.2.1, A.2.2 done. Start at **Session 1** below.

---

## Detail level and parallelism

### Making prompts detailed enough

- **Phase 1** in each prompt asks for: logic confirmation, edge cases, test list, go/no-go. Where a task has an **execution plan** with "Steps" and "Files", the prompt should tell the subagent to **read that plan** and to **output Phase 1 using those steps and files** as the checklist (so nothing is skipped).
- **Explicit file lists** are added below for sessions that had only "Implement per execution plan" — the subagent should touch exactly those files and no others outside scope.
- **Contracts** are named (e.g. 08-API-SPEC, 09-UI-ROUTES) so the subagent knows what to read when the task touches API or routes.

### Where more parallel subagents help (and where they don’t)

| Session | More parallelism? | Why |
|---------|-------------------|-----|
| **1** (A.2.3 + A.2.5) | Already 2 parallel | No shared files. |
| **2** (A.2.4) | No | Single coherent feature (controller + service + one Svelte page); one agent is simpler. |
| **3** (A.3) | **No** | Strict order: services (A.3.1) → controllers (A.3.2) → HandleInertiaRequests (A.3.3) → IdentityRegistration (A.3.4) → PublicTriage (A.3.5). Controllers depend on service signatures; splitting causes merge conflicts or wrong order. |
| **4** (A.4) | **Yes — 2 parallel** | Agent 1: A.4.1 (HandleInertiaRequests currentProgram/programs) + A.4.3 (admin pages → programs). Agent 2: A.4.2 (station/triage/display pages → currentProgram). No file overlap. Then A.4.4 (grep + remove program alias) in same session or quick follow-up. |
| **5** (A.5) | **Yes — 2 parallel** | Agent 1: backend (A.5.1, A.5.2 — broadcast on display.activity.{programId}, queue.{programId}). Agent 2: frontend (A.5.3 — Echo subscriptions in Display/Board, station page). Different files; channel names come from spec. |
| **B.1** | No | Must run first. |
| **B.2 + B.3** | **Yes — 2 parallel** | After B.1: B.2 (API key lifecycle, SiteController, middleware) and B.3 (edge_settings validator) touch different files. |
| **B.4, B.5** | No | B.4 depends on B.1; B.5 depends on B.1–B.3. |
| **C.1, C.2** | No | Sequential (C.2 needs pivot). |
| **C.3** | **Yes — 2 parallel** | Agent 1: API (assign, unassign, bulk-by-pattern endpoints). Agent 2: Admin UI (token assignment form, bulk pattern input). Same feature, no file overlap. |
| **D, E, G** | No | Each phase has strong internal dependencies (export→endpoint→import; bridge auth→endpoints→Pi client; sync upload→chunking→conflict). One agent per phase keeps reasoning in one place. |
| **F.1** | No | Columns first. |
| **F.2 + F.3** | **Yes — 2 parallel** | After F.1: F.2 (EdgeModeService) and F.3 (Pi env config) are different files. |
| **F.4** | No | Single UI feature. |

So: you can **finish work more quickly** in Sessions 4, 5, Phase B (B.2+B.3), Phase C (C.3), and Phase F (F.2+F.3) by using **2 parallel subagents** where the table says "Yes". Sessions 3, D, E, G stay single-agent to avoid ordering and merge issues.

---

## Ritual reminder (every prompt)

Each subagent **must**:

1. **Phase 1 — Before any code:** Read execution plan + `central-edge-v2-final.md` (relevant section) + any contract (08-API-SPEC, 09-UI-ROUTES, 04-DATA-MODEL, 05-SECURITY as needed). Apply the **named rules** (e.g. Code Reviewer, UX Architect). If **UX Architect** or **UI Designer** is in the rules: analyze current UI theme and existing builds (see "UI tasks" below) and follow them. Output: **(1) Logic confirmation** (spec vs code alignment, assumptions, “we will not change X”). **(2) Edge cases & test list** (what could break; required tests). **(3) Go/no-go** (scope boundaries, risks mitigated).
2. **Phase 2 — Implement:** Write failing PHPUnit/feature tests for the scenarios above, then implement until green. Follow execution plan and stack conventions. For UI: use existing theme and component patterns (flexiqueue, Skeleton); do not introduce clashing visuals.
3. **Phase 3 — Handoff:** List files changed, test command (`./vendor/bin/sail artisan test` or `php artisan test`), and **manual test steps** for the user.
4. **Session end — Update task list:** Tick off the completed task(s) in [docs/plans/central-edge/central-edge-tasks.md](docs/plans/central-edge/central-edge-tasks.md). Mark the checkbox(es) for the bead(s) just finished (e.g. A.2.3, A.2.5, B.2).

If Phase 1 finds a spec conflict, inconsistency, or ambiguity, treat the **master spec** as the authority: [docs/plans/central-edge/specs/central-edge-v2-final.md](docs/plans/central-edge/specs/central-edge-v2-final.md). Resolve by referring to the relevant section there; if still unclear, say so and propose a fix or ask before coding.

---

## UI tasks (when UX Architect or UI Designer is in the rules)

When a session uses **UX Architect** (`.cursor/rules/ux-architect.mdc`) or **UI Designer** (`.cursor/rules/ui-designer.mdc`), the subagent **must**:

- **Analyze the current UI theme and existing builds** before implementing any UI. Look at: `resources/css/app.css`, `resources/css/themes/flexiqueue.css`, existing Svelte pages and layouts in `resources/js/Pages/` and `resources/js/Layouts/`, and (if present) `public/dev/components.html` and `docs/architecture/07-UI-UX-SPECS.md` or `SKELETON-COMPONENT-MAPPING.md`. Use the same Skeleton components, design tokens, and layout patterns already in the app; **do not introduce new visual patterns or components that clash with the FlexiQueue theme.**
- In Phase 1 output, briefly note: "UI alignment: [e.g. using same card/selector pattern as AdminLayout; flexiqueue theme; 48px touch targets per spec]."

Sessions that include this: 1 (A.2.5), 2 (A.2.4), 4 (A.4), B.5, C.3 (Admin UI subagent), F.4.

---

# PHASE A — Multi-Program Foundation

---

## Session 1 — A.2.3 + A.2.5 (2 parallel subagents)

**Why parallel:** No shared files. A.2.3 = routes, DisplayController::publicTriage, PublicTriageController, PublicStart. A.2.5 = HandleInertiaRequests, AdminLayout, admin controllers.

Copy-paste:

```
Central+Edge Phase A. Run TWO subagents IN PARALLEL. Each subagent does the full ritual (Phase 1: plan & confirm, then Phase 2: TDD + implement, then Phase 3: handoff). No coding until Phase 1 is done and output.

**Subagent 1 — A.2.3 Public triage program from URL**
- Execution plan: docs/plans/central-edge/specs/a2.3/central-edge-A2.3-execution-plan.md
- Main spec: docs/plans/central-edge/specs/central-edge-v2-final.md (Phase A, $programId resolution table)
- Contracts: docs/architecture/09-UI-ROUTES-PHASE1.md if it defines public triage routes
- Rules to apply: Code Reviewer (.cursor/rules/code-reviewer.mdc), UX Architect (.cursor/rules/ux-architect.mdc), Security Engineer (.cursor/rules/security-engineer.mdc) for unauthenticated program validation
- Phase 1 output: Logic confirmation (spec vs current routes/controllers); edge cases (missing/inactive program, allow_public_triage false, program_id missing in API body); test list; go/no-go. Then Phase 2: TDD then implement. Phase 3: files changed, test command, manual test: "Open /public/triage/{id}, token lookup + bind, confirm session.program_id."
- Do NOT change: HandleInertiaRequests, DisplayController::board().

**Subagent 2 — A.2.5 Admin program selector**
- Execution plan: docs/plans/central-edge/specs/a2.5/central-edge-A2.5-execution-plan.md
- Main spec: docs/plans/central-edge/specs/central-edge-v2-final.md (Phase A, HandleInertiaRequests row)
- Rules to apply: UX Architect, Code Reviewer
- Phase 1 output: Logic confirmation (shared data for admin = programs only; currentProgram from controller on program-scoped pages); edge cases (admin with no programs, staff station still gets currentProgram); test list; go/no-go. Then Phase 2: TDD then implement. Phase 3: files changed, test command, manual test: "Admin login → sidebar program list → switch program → URL and content update."
- Do NOT change: PublicTriageController, DisplayController::board(), public triage routes.

Run both in parallel. When both done, summarize: files changed per agent, test command, and the two manual test steps.
```

---

## Session 2 — A.2.4 (1 subagent)

```
Central+Edge Phase A. Single subagent for A.2.4. Full ritual: Phase 1 (plan & confirm) then Phase 2 (TDD + implement) then Phase 3 (handoff).

- Execution plan: docs/plans/central-edge/specs/a2.4/central-edge-A2.4-execution-plan.md (read Task A and Task B fully)
- Main spec: docs/plans/central-edge/specs/central-edge-v2-final.md (Phase A, display board row)
- Rules: UX Architect, Code Reviewer
- Phase 1: (1) Logic confirmation: DisplayController::board() vs DisplayBoardService::getBoardData; current single-program behavior. (2) Edge cases: ?program= missing → show selector; invalid/inactive program id → program_name null, empty data, frontend "Program not found"; Echo channels still old until A.5—decide dual subscribe or note in code. (3) Test list: GET /display no query → 200 + programs, currentProgram null; GET /display?program=1 → board data + currentProgram; GET /display?program=999 → empty/not-found; DisplayBoardService::getBoardData(1) vs getBoardData(null). (4) Go/no-go; scope: only the files below.
- Phase 2: Implement per execution plan. Files to touch: app/Http/Controllers/DisplayController.php, app/Services/DisplayBoardService.php, resources/js/Pages/Display/Board.svelte; tests (DisplayBoardTest or DisplayControllerTest).
- Phase 3: Files changed, test command, manual test: "GET /display → program list; select program → board. GET /display?program=1 → board for program 1."
- Do NOT change: HandleInertiaRequests, public triage, admin sidebar.
```

---

## Session 3 — A.3 (1 subagent; refactor 21 locations)

```
Central+Edge Phase A. Single subagent for A.3 (refactor all 21 single-active-program locations). Full ritual first.

- Task list: docs/plans/central-edge/central-edge-tasks.md (A.3.1–A.3.6)
- Main spec: docs/plans/central-edge/specs/central-edge-v2-final.md (Phase A full section)
- Rules: Code Reviewer, Senior Developer (.cursor/rules/senior-developer.mdc), Backend Architect (.cursor/rules/backend-architect.mdc) for consistency
- Phase 1: (1) List all 21 locations from grep/exploration (SessionService, DisplayBoardService, StaffDashboardService; StaffDashboard, StationPage, Home, Triage controllers; HandleInertiaRequests; IdentityRegistrationController 6 methods; PublicTriageController 3). (2) Logic confirmation: each gets programId from request context per Phase A table—no where('is_active', true)->first(). (3) Edge cases: any controller that can be hit without program context (e.g. admin index) must not assume single program. (4) Test list: existing tests still pass; add tests for multi-program where missing. (5) Go/no-go and order: A.3.1 services → A.3.2 controllers → A.3.3 HandleInertiaRequests → A.3.4 IdentityRegistrationController → A.3.5 PublicTriageController → A.3.6 grep verification.
- Phase 2: Implement in that order; TDD where new behavior; run full test suite.
- Phase 3: Files changed, test command, manual test: "Two programs active; station in program A sees only A; display ?program=1 vs ?program=2; triage/staff sees correct program."
```

---

## Session 4 — A.4 (1 subagent, or 2 parallel — see below)

**Single-subagent version:**

```
Central+Edge Phase A. Single subagent for A.4 (frontend compatibility: currentProgram, deprecate program). Full ritual.

- Main spec: docs/plans/central-edge/specs/central-edge-v2-final.md (Phase A "Frontend compatibility plan for HandleInertiaRequests")
- Task list: A.4.1–A.4.4 in central-edge-tasks.md
- Rules: UX Architect, Code Reviewer
- Phase 1: (1) Logic confirmation: shared data and page controllers pass currentProgram (nullable); program kept as deprecated alias; admin gets programs array. (2) Edge cases: any page still using $page.props.program must keep working until A.4.4. (3) Test list: no prop-undefined in console; admin has programs; station/triage/display have currentProgram when in context. (4) Go/no-go. Order: A.4.1 → A.4.2 → A.4.3 → A.4.4.
- Phase 2: A.4.1 introduce currentProgram + deprecate program (HandleInertiaRequests + any shared-data source); A.4.2 update station/triage/display pages to currentProgram; A.4.3 admin pages to programs; A.4.4 remove program alias, grep zero references.
- Phase 3: Files changed, test command, manual test: "Station, triage, display, admin—no console errors; correct data on each."
```

**Optional 2-parallel version (faster):**

```
Central+Edge Phase A. Run TWO subagents IN PARALLEL for A.4. Full ritual each; no code until Phase 1 done.

**Subagent 1 — A.4.1 + A.4.3 (shared data + admin pages)**
- HandleInertiaRequests: introduce currentProgram (nullable), programs for admin; keep program as deprecated alias. Admin pages: use programs array; program-scoped pages get currentProgram from controller. Files: app/Http/Middleware/HandleInertiaRequests.php, resources/js/Layouts/AdminLayout.svelte, resources/js/Pages/Admin/* as needed. Do NOT change station/triage/display page components.
- Phase 1: Logic confirmation; edge cases (admin with zero programs); test list; go/no-go. Phase 2: Implement. Phase 3: Handoff.

**Subagent 2 — A.4.2 (station/triage/display → currentProgram)**
- Update station, triage, and display page components to use currentProgram from props (keep fallback to program for transition). Files: resources/js/Pages/** (station, triage, display only). Do NOT change HandleInertiaRequests or admin pages.
- Phase 1: Logic confirmation; edge cases (currentProgram null on some routes); test list; go/no-go. Phase 2: Implement. Phase 3: Handoff.

After both: one agent or you do A.4.4 (grep for $page.props.program / program alias; remove alias; verify zero references).
```

---

## Session 5 — A.5 (1 subagent, or 2 parallel — see below)

**Single-subagent version:**

```
Central+Edge Phase A. Single subagent for A.5 (broadcasting channel migration). Full ritual.

- Main spec: docs/plans/central-edge/specs/central-edge-v2-final.md (Phase A "Broadcasting channel migration" table)
- Task list: A.5.1–A.5.3 in central-edge-tasks.md
- Rules: Backend Architect, Code Reviewer, API Tester (.cursor/rules/api-tester.mdc) for event contracts
- Phase 1: (1) Logic confirmation: display.activity → display.activity.{programId}; global.queue → queue.{programId}; station.* and display.station.* unchanged. (2) Edge cases: every backend broadcast that today uses display.activity or global.queue must use program-scoped channel; frontend Echo in Display/Board and station page must subscribe to display.activity.{programId} and queue.{programId}; backward compat if any listener still on old channel. (3) Test list: backend emits on correct channel names; display board for program 1 does not receive program 2 events. (4) Go/no-go.
- Phase 2: Backend: find all broadcasts using display.activity / global.queue, switch to program-scoped. Frontend: Echo subscriptions in Display/Board.svelte and station page to new channel names. A.5.3 done in same pass.
- Phase 3: Files changed, test command, manual test: "Two programs; bind session in program 1; display?program=2 does not show it; display?program=1 shows it; events on correct channel."
```

**Optional 2-parallel version (faster):**

```
Central+Edge Phase A. Run TWO subagents IN PARALLEL for A.5. Full ritual each.

**Subagent 1 — A.5.1 + A.5.2 (backend broadcast channels)**
- Change all backend broadcasts from display.activity to display.activity.{programId} and from global.queue to queue.{programId}. Leave station.* and display.station.* unchanged. Files: grep for channel names in app/ (broadcasts, events, listeners). Do NOT change frontend.
- Phase 1: List every place that broadcasts to display.activity or global.queue; confirm programId available there; go/no-go. Phase 2: Implement. Phase 3: Handoff.

**Subagent 2 — A.5.3 (frontend Echo subscriptions)**
- Update Echo channel subscriptions in Display/Board.svelte and any station/display page that subscribes to display.activity or global.queue to use display.activity.{programId} and queue.{programId}. programId from currentProgram or equivalent. Do NOT change backend.
- Phase 1: List every frontend file that subscribes to those channels; confirm how programId is obtained; go/no-go. Phase 2: Implement. Phase 3: Handoff.
```

---

## Session 6 — A.6 (1 subagent; multi-program verification)

```
Central+Edge Phase A. Single subagent for A.6 (multi-program verification + pre-work tests still pass).

- Main spec: Phase A success criteria in central-edge-v2-final.md
- Rules: Code Reviewer, Reality Checker (.cursor/rules/reality-checker.mdc) if available
- Phase 1: Confirm test matrix: two programs active; 5 sessions each; display isolation; staff station isolation; public triage per program; all pre-work integration tests green.
- Phase 2: Add/run verification tests; fix any regressions.
- Phase 3: Handoff; manual test full flow for two programs.
```

---

## Session 7 — A.S (Stabilize)

```
Central+Edge Phase A. Stabilize bead: fix regressions, edge cases, frontend breakage found during A.2–A.6.

- Run full test suite; address failures. List any new tests or fixes. Manual smoke: station, triage (staff + public), display, admin.
```

---

# PRE-WORK (if not done before Phase A)

Do Pre-Work if you have not already tagged `pre-phase-a-stable` and have baseline tests.

---

## Session PW — Pre-Work test baseline (1 subagent)

```
Central+Edge Pre-Work. Single subagent. Full ritual.

- Task list: PW.1–PW.5 in central-edge-tasks.md
- Rules: API Tester, Code Reviewer
- Phase 1: Logic confirmation: PW.1 session lifecycle (bind→call→serve→transfer→complete + transaction_logs); PW.2 display board events (channels, events); PW.3 triage (staff, public, identity registration); PW.4 list 21 files and confirm each has test coverage; PW.5 tag pre-phase-a-stable. Edge cases: flaky tests, missing coverage. Go/no-go.
- Phase 2: Write/add tests; tag. Phase 3: Handoff.
```

---

# PHASE B — Multi-Tenant / Sites

---

## Session B.1 — B.1 Sites migration

```
Central+Edge Phase B. B.1 only: sites table, site_id on programs/users, default site seeder. Full ritual.

- Execution plan: docs/plans/central-edge/specs/b/central-edge-B-execution-plan.md (Task B.1 — read Steps 1–4 and Files)
- Main spec: Phase B schema in central-edge-v2-final.md
- Rules: Database Optimizer (.cursor/rules/database-optimizer.mdc), Security Engineer (.cursor/rules/security-engineer.mdc)
- Phase 1: (1) Logic confirmation: sites table columns (id, name, slug, api_key_hash, settings, edge_settings, timestamps); no api_key column; site_id nullable on programs and users; indexes on slug, api_key_hash, site_id. (2) Edge cases: SQLite vs MariaDB (JSON type); existing programs/users → assign to default site in seeder; default site has conservative edge_settings. (3) Test list: migration runs both drivers; after seeder all programs/users have non-null site_id. (4) Go/no-go.
- Phase 2: Files: database/migrations/xxxx_create_sites_table.php, xxxx_add_site_id_to_programs_and_users.php, database/seeders/DefaultSiteSeeder.php, tests. Phase 3: Handoff.
```

---

## Session B.2 — B.2 API key lifecycle (or run B.2 + B.3 in parallel)

```
Central+Edge Phase B. B.2 only: API key generate (40-char sk_live_), bcrypt hash only, show raw once, regenerate, auth middleware for sync/bridge. Full ritual.

- Execution plan: docs/plans/central-edge/specs/b/central-edge-B-execution-plan.md (Task B.2 — Steps and Files)
- Main spec: Phase B "API key lifecycle" in central-edge-v2-final.md
- Rules: Security Engineer, Backend Architect
- Phase 1: (1) Logic confirmation: generate 40-char sk_live_ prefix; store only Hash::make($rawKey) in api_key_hash; create-site response returns raw key once; GET site returns masked only; regenerate endpoint replaces hash and returns new raw once; middleware Bearer token = raw key, Hash::check against sites, bind site_id. (2) Edge cases: lost key → regenerate only; wrong key → 401; no key in request → 401. (3) Test list: create → raw in response once; GET → masked; invalid key → 401; valid → 200 and site-scoped. (4) Go/no-go.
- Phase 2: Files: app/Services/SiteApiKeyService.php (or similar), app/Http/Controllers/Api/Admin/SiteController.php, app/Http/Middleware/AuthenticateSiteByApiKey.php, routes, tests. Phase 3: Handoff.
```

---

## Session B.3 — B.3 edge_settings validation (can run parallel with B.2)

```
Central+Edge Phase B. B.3 only: edge_settings JSON schema validation, unknown key rejection, enums, defaults. Full ritual.

- Execution plan: docs/plans/central-edge/specs/b/central-edge-B-execution-plan.md (Task B.3)
- Main spec: Phase B "edge_settings schema" in central-edge-v2-final.md
- Rules: Backend Architect, Code Reviewer
- Phase 1: (1) Logic confirmation: schema keys (sync_clients, sync_client_scope, sync_tokens, sync_tts, bridge_enabled, offline_binding_mode_override, scheduled_sync_time, offline_allow_client_creation); unknown key → 422; invalid enum → 422; defaults for missing keys if desired. (2) Edge cases: empty object; partial object. (3) Test list: valid payload saves; unknown key 422; invalid enum 422. (4) Go/no-go.
- Phase 2: Validator (e.g. EdgeSettingsValidator or JSON Schema); apply on site create/update. Phase 3: Handoff.
```

---

## Session B.4 — B.4 Site scoping

```
Central+Edge Phase B. B.4 only: programs and users scoped by site_id; cross-site isolation. Full ritual.

- Execution plan: docs/plans/central-edge/specs/b/central-edge-B-execution-plan.md (Task B.4)
- Main spec: Phase B in central-edge-v2-final.md
- Rules: Database Optimizer, Security Engineer
- Phase 1: (1) Logic confirmation: program index/create/update scope by current user's site_id; user index/create/update scope by site_id; policy or middleware for show/edit so Site A cannot access Site B resources. (2) Edge cases: cross-site ID in URL → 403 or 404; super-admin if any (explicit). (3) Test list: two sites, programs/users each; admin A sees only A's. (4) Go/no-go.
- Phase 2: Scope queries in Program/User controllers; policies. Phase 3: Handoff.
```

---

## Session B.5 — B.5 Admin UI for sites

```
Central+Edge Phase B. B.5 only: CRUD sites, API key display/regenerate UI, edge settings form. Full ritual.

- Execution plan: docs/plans/central-edge/specs/b/central-edge-B-execution-plan.md (Task B.5)
- Rules: UX Architect, UI Designer (.cursor/rules/ui-designer.mdc)
- Phase 1: (1) Logic confirmation: create site → show raw key once in response/UI; list/show site → masked key only; regenerate button → new key once. (2) Edge cases: empty list; form validation for edge_settings. (3) Go/no-go.
- Phase 2: Admin pages for sites CRUD, key display/regenerate, edge_settings form. Phase 3: Handoff.
```

---

## Session B.S — Phase B Stabilize

```
Central+Edge Phase B. Stabilize: migration issues, auth edge cases. Run tests; manual smoke.
```

---

# PHASE C — Token–Program Association

---

## Session C.1 — C.1 program_token pivot

```
Central+Edge Phase C. C.1 only: program_token pivot migration (SQLite + MariaDB). Full ritual.

- Execution plan: docs/plans/central-edge/specs/c/central-edge-C-execution-plan.md (Task C.1 — Steps and Files)
- Main spec: Phase C schema in central-edge-v2-final.md
- Rules: Database Optimizer
- Phase 1: (1) Logic confirmation: table program_token (program_id, token_id, created_at); composite PK (program_id, token_id); FKs to programs(id) and tokens(id); index on token_id for reverse lookups. (2) Edge cases: both SQLite and MariaDB; reversible down(). (3) Test list: migration runs both drivers; insert/detach work. (4) Go/no-go.
- Phase 2: database/migrations/xxxx_create_program_token_table.php. Phase 3: Handoff.
```

---

## Session C.2 — C.2 Model relationships

```
Central+Edge Phase C. C.2 only: Program::tokens(), Token::programs(). Full ritual.

- Execution plan: docs/plans/central-edge/specs/c/central-edge-C-execution-plan.md (Task C.2)
- Rules: Code Reviewer
- Phase 1: (1) Logic confirmation: Program::tokens() belongsToMany Token via program_token; Token::programs() belongsToMany Program; pivot table name program_token. (2) Test list: attach/detach; token in multiple programs. (4) Go/no-go.
- Phase 2: app/Models/Program.php, app/Models/Token.php. Phase 3: Handoff.
```

---

## Session C.3 — C.3 Admin UI assign/unassign + bulk (1 subagent, or 2 parallel — see below)

**Single-subagent version:**

```
Central+Edge Phase C. C.3 only: assign/unassign tokens to program; bulk by pattern (e.g. A*). Full ritual.

- Execution plan: docs/plans/central-edge/specs/c/central-edge-C-execution-plan.md (Task C.3 — Steps and Files)
- Rules: Code Reviewer, Security Engineer (pattern injection: parameterized LIKE, no raw user input in SQL)
- Phase 1: (1) Logic confirmation: POST assign (program_id, token_id or token_ids), idempotent; DELETE unassign; POST bulk with pattern (e.g. physical_id LIKE) → syncWithoutDetaching. (2) Edge cases: pattern safe (escape %, _); idempotent assign; unassign token not in program → 200. (3) Test list: assign, unassign, bulk, idempotent. (4) Go/no-go.
- Phase 2: API (ProgramTokenController or under ProgramController) + Admin UI (token list, assign/unassign, bulk pattern input). Phase 3: Handoff.
```

**Optional 2-parallel version (faster):**

```
Central+Edge Phase C. Run TWO subagents IN PARALLEL for C.3. Full ritual each.

**Subagent 1 — C.3 API (assign, unassign, bulk by pattern)**
- Endpoints: assign token(s) to program, unassign, bulk assign by physical_id pattern. Use parameterized queries for pattern. Files: app/Http/Controllers/Api/Admin/ProgramTokenController.php (or equivalent), routes, tests. Do NOT change frontend.
- Phase 1: Logic confirmation; edge cases (pattern injection, idempotent); go/no-go. Phase 2: Implement. Phase 3: Handoff.

**Subagent 2 — C.3 Admin UI (token assignment form, bulk pattern input)**
- Admin program page: list tokens for program, assign/unassign buttons, bulk pattern field and submit. Calls API from Subagent 1. Files: resources/js/Pages/Admin/** (program show/edit). Do NOT change API.
- Phase 1: Confirm API contract (assign/unassign/bulk); go/no-go. Phase 2: Implement. Phase 3: Handoff.
```

---

## Session C.4 + C.S — C.4 Verification + Stabilize

```
Central+Edge Phase C. C.4 verification (token in multiple programs, no side effects) + C.S stabilize. Run tests; manual smoke.
```

---

# PHASE D — Program Package API

---

## Session D — Phase D (split or one)

Use execution plan `specs/d/central-edge-D-execution-plan.md`. Rules: Backend Architect, API Tester, Security Engineer (PII invariant). If one session: Phase 1 = confirm D.1–D.7 logic, edge cases, test matrix; Phase 2 = D.1 UUID → D.2 exporter → D.3 security test → D.4 endpoint → D.5 import → D.6/D.7. If split: D.1 → D.2+D.3 → D.4 → D.5 → D.6+D.7.

```
Central+Edge Phase D. Single subagent. Full ritual.

- Execution plan: docs/plans/central-edge/specs/d/central-edge-D-execution-plan.md
- Main spec: Phase D in central-edge-v2-final.md
- Rules: Backend Architect, API Tester, Security Engineer (no id_number_encrypted in package)
- Phase 1: Logic confirmation (UUID on 8 tables; export manifest + conditional clients/tokens; import transactional; security invariant CI). Edge cases (checksum fail, re-import with existing sessions, orphaned station). Test list from execution plan. Go/no-go.
- Phase 2: Implement D.1 through D.7 in order. Phase 3: Handoff; manual test export + import on empty and with existing sessions.
```

---

# PHASE E — Bridge Layer

---

## Session E — Phase E (one or two sessions)

Use execution plan `specs/e-g/` (E part). Rules: Backend Architect, API Tester, Security Engineer.

```
Central+Edge Phase E. Single subagent. Full ritual.

- Execution plan: docs/plans/central-edge/specs/e-g/central-edge-E-G-execution-plan.md (E.1–E.6)
- Main spec: Phase E in central-edge-v2-final.md
- Rules: Backend Architect, API Tester, Security Engineer
- Phase 1: Logic confirmation (ConnectivityMonitor sticky 3 fail / 2 success; connectivity_mode at bind; bridge endpoints auth + rate limit; BridgeService on Pi fallback). Edge cases (flap, timeout). Go/no-go.
- Phase 2: Implement E.1–E.6. Phase 3: Handoff.
```

---

# PHASE F — Edge Mode Application

---

## Session F.1 — F.1 New columns (source, binding_status)

```
Central+Edge Phase F. F.1 only: queue_sessions.source (default 'central'), queue_sessions.binding_status (default 'verified'). Full ritual. Rules: Database Optimizer, Code Reviewer.
- Main spec: Phase F "New columns" in central-edge-v2-final.md
- Phase 1: Logic confirmation (column types, defaults); edge cases (existing rows get default). Go/no-go.
- Phase 2: Migration. Phase 3: Handoff.
```

---

## Session F.2 + F.3 — F.2 EdgeModeService + F.3 Pi config (1 subagent, or 2 parallel)

**Single-subagent:** One agent does F.2 (EdgeModeService: isEdgeMode, canCreateClients, canShowIdBindingPage, getEffectiveBindingMode, getBindingStatus, etc.) then F.3 (APP_MODE=edge, CENTRAL_URL, CENTRAL_API_KEY, SITE_ID, config validation on boot). Full ritual. Rules: Backend Architect, Code Reviewer.

**Optional 2-parallel:** Subagent 1 = F.2 only (app/Services/EdgeModeService.php, feature gates per spec). Subagent 2 = F.3 only (config files, env validation, no APP_MODE in app/Http or app/Services except EdgeModeService). Different files.

---

## Session F.4 — Edge mode UI

```
Central+Edge Phase F. F.4 only: Edge banner, sync widget, triage offline/bridge messaging, admin read-only, offline client form. Full ritual.

- Execution plan: docs/plans/central-edge/specs/f/central-edge-F-edge-mode-ui-execution-plan.md (read delegateable tasks F.4.1–F.4.6 and Files)
- Main spec: Phase F "UI specification" in central-edge-v2-final.md
- Rules: UX Architect, UI Designer, Code Reviewer
- Phase 1: (1) Logic confirmation: EdgeBanner (online/offline, last sync, pending count, Sync Now); SyncStatusWidget; triage offline vs bridge messaging; admin read-only (hide save/delete, notices); offline client form (name + birth_year only). (2) Edge cases: offline vs bridge UI states; canShowIdBindingPage false offline. (3) Test list: banner shows correct state; triage shows correct message; admin no save when edge. (4) Go/no-go.
- Phase 2: Implement per execution plan. Phase 3: Handoff.
```

---

## Session F.5–F.6 + F.S — Verification + grep + Stabilize

```
Central+Edge Phase F. F.5: Full offline session test (bind→call→serve→transfer→complete, transaction_logs, display board, TTS). F.6: grep -r APP_MODE app/Http = 0, app/Services = 0 except EdgeModeService. F.S: Fix regressions, UI state issues. Full ritual; run tests and manual smoke.
```

---

# PHASE G — Sync API

---

## Session G — Phase G (one or two sessions)

Use execution plan `specs/e-g/` (G part). Rules: Backend Architect, API Tester, Security Engineer.

```
Central+Edge Phase G. Single subagent. Full ritual.

- Execution plan: docs/plans/central-edge/specs/e-g/central-edge-E-G-execution-plan.md (G.1–G.8)
- Main spec: Phase G in central-edge-v2-final.md
- Phase 1: Logic confirmation (upload endpoint, chunking 200, conflict resolution, sync_id_map, binding_review, SyncToCentralJob, triggers). Edge cases (partial failure, duplicate upload). Go/no-go.
- Phase 2: Implement. Phase 3: Handoff; manual test 5 offline sessions → sync → central.
```

---

# PHASE H — Analytics Views

---

## Session H — Phase H

```
Central+Edge Phase H. Single subagent. Full ritual.

- Execution plan: docs/plans/central-edge/specs/h/central-edge-H-analytics-views-execution-plan.md
- Main spec: Phase H in central-edge-v2-final.md
- Rules: Backend Architect, Code Reviewer
- Phase 1: Logic confirmation (source, siteId, bindingStatus filters; views per spec). Edge cases (no double-count, Pi only local). Go/no-go.
- Phase 2: AnalyticsService + views + filter UI. Phase 3: Handoff.
```

---

# Summary table

| Session | Task(s) | Subagents | Rules | Optional faster |
|---------|---------|-----------|--------|------------------|
| 1 | A.2.3, A.2.5 | 2 parallel | Code Reviewer, UX Architect, Security (A.2.3) | — |
| 2 | A.2.4 | 1 | UX Architect, Code Reviewer | — |
| 3 | A.3 | 1 | Code Reviewer, Senior Developer, Backend Architect | No (ordering) |
| 4 | A.4 | 1 or 2 | UX Architect, Code Reviewer | 2 parallel: A.4.1+A.4.3 \| A.4.2 |
| 5 | A.5 | 1 or 2 | Backend Architect, Code Reviewer, API Tester | 2 parallel: backend \| frontend |
| 6 | A.6 | 1 | Code Reviewer, Reality Checker | — |
| 7 | A.S | 1 | — | — |
| PW | Pre-Work | 1 | API Tester, Code Reviewer | — |
| B.1 | B.1 | 1 | Database Optimizer, Security | — |
| B.2, B.3 | B.2, B.3 | 1 each or 2 parallel | Security, Backend; Backend, Code Reviewer | 2 parallel after B.1 |
| B.4, B.5, B.S | B.4, B.5, B.S | 1 each | Database, Security; UX, UI | — |
| C.1, C.2 | C.1, C.2 | 1 each | Database Optimizer; Code Reviewer | — |
| C.3 | C.3 | 1 or 2 | Code Reviewer, Security | 2 parallel: API \| UI |
| C.4, C.S | C.4, C.S | 1 | — | — |
| D | Phase D | 1 | Backend Architect, API Tester, Security | No (deps) |
| E | Phase E | 1 | Backend Architect, API Tester, Security | No |
| F.1 | F.1 | 1 | Database, Code Reviewer | — |
| F.2, F.3 | F.2, F.3 | 1 or 2 | Backend, Code Reviewer | 2 parallel after F.1 |
| F.4, F.5–F.S | F.4, F.5–F.S | 1 each | UX, UI, Code Reviewer | — |
| G | Phase G | 1 | Backend Architect, API Tester, Security | No |
| H | Phase H | 1 | Backend Architect, Code Reviewer | — |

Use this list from top to bottom until all tasks are done. After each session: run tests, do the manual steps, then start the next prompt. Where "Optional faster" shows "2 parallel", use the **2-parallel prompt variant** in the session body to finish that session faster.
