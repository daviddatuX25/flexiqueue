# Workflow: Building from the Central–Edge Spec

**North star:** [`central-edge-v2-final.md`](./central-edge-v2-final.md)  
**Task list:** [`central-edge-tasks.md`](./central-edge-tasks.md)  
**Beads:** `bd ready` / `bd list` / `bd show <id>` (project state)  
**Routing:** [`README.md`](./README.md) (folder map: tasks ↔ specs ↔ backlog)  
**Iteration ritual:** [`ITERATION-RITUAL.md`](./ITERATION-RITUAL.md) (per-session planning discipline)  
**Specs guide:** [`specs/README.md`](./specs/README.md) (how execution plans are created/updated)

This doc is your **workflow** when building toward the Central + Edge plan: how to pick work, what to read, and when to use agency agents. When you mention “iteration workflow” and the `docs/plans/central-edge/` folder in a session prompt, the agent should load this file together with `README.md`, `ITERATION-RITUAL.md`, and `specs/README.md` and follow them as a linked set.

---

## 1. Phase order (do not skip)

Follow the spec’s dependency order. Do not start a phase until its gate is met.

| Gate | Then you can start |
|------|---------------------|
| **Pre-Work done** (test baseline, tag `pre-phase-a-stable`) | Phase A |
| **Phase A done** (multi-program, all success criteria) | Phase B and C in parallel |
| **Phase B + C done** | Phase D (Program Package API) |
| **Phase D done** | Phase E (Bridge) and F (Edge app) in parallel |
| **Phase E + F done** | Phase G (Sync API) |
| **Phase G done** | Phase H (Analytics) |

Critical path: **A → D → F → G**. B and C can run in parallel after A; E and F can run in parallel after D.

---

## 2. Session start (plan-first)

1. **Scope**
   - Run `bd ready` to see what’s unblocked.
   - Open [`central-edge-tasks.md`](./central-edge-tasks.md) and find the next unchecked item for the phase you’re in.
   - If the spec says “Future — do not begin until explicitly prioritized,” confirm with the team before starting.

2. **Pick one bead (or one task row)**
   - e.g. “Work on A.2.2 — Staff triage: resolve program from user’s station.”
   - Create/update a bead if you use beads: `bd create "A.2.2 Staff triage program resolution" --type=task` or `bd update <id> --status in_progress`.

3. **Contracts**
   - **Spec:** [`central-edge-v2-final.md`](./central-edge-v2-final.md) — phase section and “Success criteria” for that phase.
   - **API:** `docs/architecture/08-API-SPEC-PHASE1.md` if you touch endpoints.
   - **Data:** `docs/architecture/04-DATA-MODEL.md` for schema.
   - **Security:** `docs/architecture/05-SECURITY-CONTROLS.md` if you touch auth/sites/API keys.

4. **Plan**
   - Write a short plan: test scenarios (TDD) first, then implementation steps, then edge cases.
   - Confirm with yourself (or a teammate) before coding.

5. **Implement**
   - Test-first: write failing test, make it pass, refactor.
   - Run `./vendor/bin/sail artisan test` (or `php artisan test`) before considering the bead done.

---

## 3. When to use agency agents (Cursor rules)

The 142 Agency rules live in `.cursor/rules/`. Use them by **naming the role** in chat or by **@-mentioning** the rule. Examples:

| Situation | Example prompt |
|-----------|----------------|
| API or backend design (Phase D, E, G) | “Use the **Backend Architect** rules to design the package export endpoint.” or “@backend-architect …” |
| Security (sites, API keys, bridge auth) | “Use the **Security Engineer** rules to review this middleware.” or “@security-engineer …” |
| DB schema / migrations (B, C, D, G) | “Use the **Database Optimizer** rules for this migration.” or “@database-optimizer …” |
| Code review before merge | “Use the **Code Reviewer** rules on this PR.” or “@code-reviewer …” |
| Production readiness / quality gate | “Use the **Reality Checker** rules: is this ready for Phase A completion?” or “@reality-checker …” |
| Laravel / complex app logic | “Use the **Senior Developer** rules (Laravel/Livewire) for this service.” or “@senior-developer …” |
| API testing (sync, bridge) | “Use the **API Tester** rules to design tests for the sync upload.” or “@api-tester …” |
| Docs or runbooks | “Use the **Technical Writer** rules for this deployment section.” or “@technical-writer …” |

You don’t need to name an agent every time; use them when the task clearly fits a specialty (architecture, security, DB, testing, docs).

---

## 4. End of session

1. **Beads**
   - `bd update <id> --status done` for completed work.
   - Create beads for any new, agreed scope.

2. **Tasks**
   - Update [`central-edge-tasks.md`](./central-edge-tasks.md): check off completed items so the next session knows where to continue.

3. **Handoff**
   - Short summary: what’s done, what’s next, any blockers.
   - If you use git: push branch and note “Next up: <task id>”.

---

## 5. Quick reference

- **Spec (what to build):** [`central-edge-v2-final.md`](./central-edge-v2-final.md)
- **Checklist (what’s done/next):** [`central-edge-tasks.md`](./central-edge-tasks.md)
- **Beads (state):** `bd ready` / `bd list`
- **Agency agents:** in `.cursor/rules/*.mdc`; invoke by name or @-mention in Cursor chat.
- **Pre-Work:** Session lifecycle + display + triage tests, baseline coverage, tag `pre-phase-a-stable` — **before any Phase A code.**

Your workflow in one line: **Spec → Tasks/Beads → Contracts → Plan → TDD → Implement → Update tasks/beads and hand off.**
