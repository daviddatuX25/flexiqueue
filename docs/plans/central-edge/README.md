# Central + Edge — Plan Documents (routing README)

All planning and specification documents for the Central + Edge (multi-program / edge mode) work live under this folder. **This README is the routing document**: it tells the agent where to look next (task list, specs, backlog), not how to prompt or run subagents.

When creating new MD files for central-edge work, **keep them under this folder** and follow the structure below:

- **Root (`docs/plans/central-edge/`)** — Workflow, iteration ritual, vision, and the **authoritative task list**.
- **`specs/`** — Top-level spec (`central-edge-v2-final.md`) and **task-level execution plans** (A.2.2–A.2.5, B–H). See `specs/README.md` for how to create/update these.
- **`follow-up-backlog/`** — Discovered items that are not yet scheduled (backlog only).
- **`code-review/`** — Task-level code reviews (e.g. `code-review/a2.2/`).

This README **does not describe prompts or iteration scripts**; those live in their own files and should be read only when explicitly tagged.

## What to read, in what order

When starting Central+Edge work, the agent should:

1. **Start here (`README.md`)** to understand the folder structure and where the source of truth lives.
2. **Go to the task list**: [central-edge-tasks.md](central-edge-tasks.md) to pick the next bead.
3. **Follow into specs**: from the chosen bead, open the corresponding execution plan under `specs/` (see `specs/README.md`) and the master spec `specs/central-edge-v2-final.md`.
4. **Consult backlog only when needed**: use [follow-up-backlog/README.md](follow-up-backlog/README.md) to pull in deferred discoveries and re-add them to `central-edge-tasks.md` when they are scheduled.

Use [WORKFLOW-CENTRAL-EDGE.md](WORKFLOW-CENTRAL-EDGE.md) and [ITERATION-RITUAL.md](ITERATION-RITUAL.md) for **how to run** a session, but treat this README as the exclusive map of **where to read next** (task list vs specs vs backlog).

## Key files

- **[central-edge-tasks.md](central-edge-tasks.md)** — Implementation task beads (Phase A–H, pre-work, cross-cutting).
- **[specs/central-edge-v2-final.md](specs/central-edge-v2-final.md)** — Final implementation plan (source of truth; top-level spec).
- **[specs/central-edge-v2-robust.md](specs/central-edge-v2-robust.md)** — Robust planning version; use for deeper cross-checks when needed.
- **[specs/README.md](specs/README.md)** — How execution plans are structured and when to create/update them.
- **[WORKFLOW-CENTRAL-EDGE.md](WORKFLOW-CENTRAL-EDGE.md)** — How to pick work, read contracts, and use agency rules.
- **[ITERATION-RITUAL.md](ITERATION-RITUAL.md)** — Session ritual: logic double-check and planning before coding.
- **[follow-up-backlog/README.md](follow-up-backlog/README.md)** — Discovered items not yet on the task list (e.g. staff-one-program rule); re-add to `central-edge-tasks.md` when scheduling.

Prompt helper files such as `NEXT-PROMPT.md` and `PROMPTS-FULL-LIST.md` are intentionally **not** referenced here to keep this README focused on routing between **task list**, **specs**, and **backlog** only.
