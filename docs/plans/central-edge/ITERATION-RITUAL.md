# Central+Edge — Iteration Ritual & Planning Discipline

This doc is **context for the agent/subagents** when you run a session prompt. It defines the ritual so **logic is double-checked** and **planning happens before code**. Tag `@ITERATION-RITUAL.md` (or this file) when you want the agent to have it in context.

**Doc organization:** All central-edge planning and ritual docs live under `docs/plans/central-edge/`. When creating new MD files for this work, keep them here: root for workflow/ritual/task list, `specs/` for execution plans, `code-review/` for reviews. See [README.md](README.md) for the full structure.

---

## 1. Session ritual (every subagent, every session)

Each subagent **must** do these steps in order. No implementation until Step 4 is done, and **planning-only outputs are not allowed** unless the user explicitly says to stop after planning.

| Step | Name | What the subagent does | Output |
|------|------|------------------------|--------|
| **0** | **Route via central-edge README** | Read `docs/plans/central-edge/README.md` to understand where this task lives (task list vs specs vs backlog) and which spec file(s) under `specs/` apply. Use this to decide whether new details belong in the task list, an execution plan, or the follow-up backlog. | (internal) |
| **1** | **Read contracts** | Read the task’s execution plan under `docs/plans/central-edge/specs/` (see `specs/README.md` for mapping), the referenced section of `central-edge-v2-final.md`, and any contract (e.g. `docs/architecture/08-API-SPEC-PHASE1.md`, `09-UI-ROUTES-PHASE1.md`, `04-DATA-MODEL.md`) that the task touches. | (internal) |
| **2** | **Logic double-check** | Apply the **specialized rules** assigned to the task (see below). If UX Architect or UI Designer applies: analyze current UI theme and existing builds (see §2b) and follow them. Check: Does the execution plan match the main spec? Any contradiction with current code (routes, controllers, services)? Are all request/response paths and error cases (403, 422, 404) covered? | Short **Logic confirmation** note: assumptions, contract alignment, “no conflict with X”. |
| **3** | **Edge-case & risk brainstorm** | List: What could break? What if program is missing/inactive? What if frontend sends no program_id? Backward compatibility? Security (unauthenticated public triage, admin-only data)? Tests we must have. **Whenever new behavior, edge cases, or tests are discovered, capture them by updating/creating a spec file under `specs/` per `specs/README.md`, not just in the prompt.** | **Edge cases & test list**: numbered list of scenarios and required tests, plus any spec updates in `specs/`. |
| **4** | **Execution plan confirmation** | Summarize: “We will do A, B, C. We will not change X, Y. Tests: T1, T2, T3. Risks: R1 (mitigated by …).” Confirm that the written spec in `specs/` now reflects this plan. Only then proceed to implementation. | **Go/no-go** + explicit scope boundaries, aligned with the updated spec. |
| **5** | **Implement (TDD then code)** | Unless the user has said “plan only”, continue in the **same session**: write failing PHPUnit/feature tests first for the scenarios from Step 3, then implement until tests pass, following the execution plan and stack conventions. | Code + tests. |
| **6** | **Handoff** | List files changed, test command (`./vendor/bin/sail artisan test` or `php artisan test`), and **manual test steps** for the user. | Handoff block. |
| **7** | **Update task list** | At the end of the session, tick off the completed task(s) in the central task list: [docs/plans/central-edge/central-edge-tasks.md](docs/plans/central-edge/central-edge-tasks.md). Mark the corresponding checkbox(es) for the bead(s) just finished (e.g. A.2.3, A.2.5, B.2). If any new follow-up work was discovered that does not belong in the current spec, add it to the follow-up backlog and wire it back into the task list when scheduled. | Task list and specs/backlog updated. |

If at Step 2 or 3 the subagent finds a spec conflict, inconsistency, or ambiguity, it **must** treat the **master spec** as the authority: [docs/plans/central-edge/specs/central-edge-v2-final.md](docs/plans/central-edge/specs/central-edge-v2-final.md). Resolve by referring to the relevant section there; if still unclear, say so and either propose a resolution or stop and ask.

---

## 2. Specialized rules (which agent to “think as”)

Map each task to the rules in `.cursor/rules/` that the subagent should apply during **logic double-check** and **implementation**. The prompt will name these so the subagent reads and uses them.

| Task / phase | Primary rules | Secondary (for edge-case / security) |
|--------------|----------------|---------------------------------------|
| **A.2.3** Public triage URL | Code Reviewer, UX Architect | Security Engineer (unauthenticated, program validation) |
| **A.2.4** Display board ?program= | UX Architect, Code Reviewer | — |
| **A.2.5** Admin program selector | UX Architect, Code Reviewer | — |
| **A.3** Refactor 21 locations | Code Reviewer, Senior Developer | Backend Architect (consistency) |
| **A.4** currentProgram prop | UX Architect, Code Reviewer | — |
| **A.5** Broadcasting channels | Backend Architect, Code Reviewer | API Tester (events) |
| **B** Sites, API keys | Database Optimizer, Security Engineer | Backend Architect |
| **C** Token–program | Database Optimizer, Code Reviewer | — |
| **D** Package API | Backend Architect, API Tester | Security Engineer (PII invariant) |
| **E, G** Bridge + Sync | Backend Architect, API Tester | Security Engineer (auth, rate limit) |
| **F** Edge UI | UX Architect, UI Designer | Code Reviewer |
| **H** Analytics | Backend Architect, Code Reviewer | — |

---

## 2b. UI tasks (when UX Architect or UI Designer applies)

When the task uses **UX Architect** or **UI Designer** rules, the subagent **must** analyze the **current UI theme and existing builds** before implementing any UI:

- **Where to look:** `resources/css/app.css`, `resources/css/themes/flexiqueue.css`, existing Svelte pages and layouts (`resources/js/Pages/`, `resources/js/Layouts/`), and if present `public/dev/components.html`, `docs/architecture/07-UI-UX-SPECS.md`, `SKELETON-COMPONENT-MAPPING.md`.
- **What to do:** Use the same Skeleton components, design tokens, and layout patterns already in the app. Do **not** introduce new visual patterns or components that clash with the FlexiQueue theme.
- **In Step 2/4 output:** Briefly note UI alignment (e.g. “using same card/selector pattern as AdminLayout; flexiqueue theme; 48px touch targets per spec”).

---

## 3. Contract references (where to read)

- **Main spec:** `docs/plans/central-edge/specs/central-edge-v2-final.md`
- **Task list:** `docs/plans/central-edge/central-edge-tasks.md`
- **API behavior:** `docs/architecture/08-API-SPEC-PHASE1.md` (if task touches API)
- **UI routes / pages:** `docs/architecture/09-UI-ROUTES-PHASE1.md` (if task touches routes or pages)
- **Data model:** `docs/architecture/04-DATA-MODEL.md` (if task touches schema or models)
- **Security:** `docs/architecture/05-SECURITY-CONTROLS.md` (if task touches auth, keys, or public endpoints)
- **Stack / conventions:** `.cursor/rules/stack-conventions.mdc`, `.cursor/rules/environment.mdc`

---

## 4. Parallel subagents

- **Same session, multiple subagents:** Each subagent runs the full ritual (Steps 1–6) on **its own task**. No shared files between tasks in one parallel batch when possible (the session prompt states which tasks run in parallel).
- **File overlap:** If two tasks touch the same file (e.g. different methods in the same controller), run them in **separate sessions** or have one subagent do both in sequence so merge conflicts and logic clashes are avoided.
- **Order:** For each subagent, **Phase 1 (Steps 1–4)** must be completed and output before **Phase 2 (Steps 5–6)**. The user can review the logic confirmation and test list before implementation proceeds.

---

## 5. Success criteria for “robustly planned”

Before any code is written for the iteration:

- [ ] Execution plan and main spec are aligned (no contradiction).
- [ ] Current code (routes, controllers, services) was checked; no surprise assumptions.
- [ ] Error and edge cases are listed (missing/inactive program, wrong program_id, backward compat).
- [ ] Test list is explicit (feature tests + manual test steps).
- [ ] Scope boundaries are clear (what we will not change).
- [ ] Relevant specialized rules were applied (correctness, security, contracts, UX).

After implementation:

- [ ] All new/updated tests pass.
- [ ] Handoff lists files changed, test command, and manual test steps for the user.
