## Central+Edge Specs — How to Use This Folder

This folder holds the **authoritative specs and execution plans** for Central+Edge work. The goal is to route every iteration through a written spec (not just a prompt) before any implementation happens.

- **Top-level spec**: `central-edge-v2-final.md` (master contract for behavior, phases A–H).
- **Robust planning version**: `central-edge-v2-robust.md` (deeper reasoning and alternatives; optional but useful when doing robust cross-checks).
- **Task-level execution plans**: one file per major bead/phase, under subfolders like `a2.2/`, `a2.3/`, `b/`, `f/`, etc.

This README is **only about specs and execution plans**: it tells the agent how to create, extend, or read them, not how to prompt or run sessions.

### 1. When to create or update a spec

During the iteration ritual (see `../ITERATION-RITUAL.md`), whenever brainstorming or deep logic checks uncover **new behavior, edge cases, or test scenarios** that are not already captured in:

- the chosen bead in `../central-edge-tasks.md`, or
- the current execution plan in `specs/` for that bead,

the agent must:

1. **Create or update a task-level execution plan** under this folder, and
2. Re-run the logic double-check against `central-edge-v2-final.md` using that updated spec.

Do **not** keep these details only in prompts or temporary notes; they must live in a `.md` spec file here.

### 2. Execution plan format (per A.2.2 pattern)

Each task-level execution plan should follow the A.2.2 structure:

- **Goal** — What this bead delivers, in one paragraph.
- **Reference** — Links into `central-edge-v2-final.md` and any relevant architecture docs.
- **Delegateable tasks** — For each sub-task:
  - **Scope** — What is and is not included.
  - **Steps** — Ordered implementation and test steps.
  - **Files** — Expected controllers, services, requests, Svelte pages, etc.

Use existing examples as templates:

- `a2.2/central-edge-A2.2-execution-plan.md`
- `a2.3/central-edge-A2.3-execution-plan.md`
- `f/central-edge-F-edge-mode-ui-execution-plan.md`

### 3. Mapping from beads to specs

When you pick a bead from `../central-edge-tasks.md`:

1. Start at `../README.md` to confirm where this work sits (task list vs backlog vs specs).
2. From the bead’s label (e.g. A.2.3, F, H), open the matching spec subfolder here (e.g. `a2.3/`, `f/`, `h/`).
3. If there is **no spec yet** for that bead:
   - Create a new execution plan file in the appropriate subfolder.
   - Follow the format above and immediately wire it into the iteration ritual (Step 1: “Read contracts”).

### 4. Iteration discipline for specs

On **every** iteration that touches Central+Edge:

- **Before implementation**:
  - Read `../README.md` to route to the right place (task list vs specs vs backlog).
  - Read the relevant execution plan under `specs/` and the master spec `central-edge-v2-final.md`.
  - If brainstorming (edge cases, risks, tests) reveals anything missing, update or create a spec file here.
- **After implementation**:
  - If the actual behavior deviates from the current spec, either:
    - Adjust the implementation back to match the spec, or
    - Update the spec here and, if needed, adjust beads in `../central-edge-tasks.md`.

Specs in this folder are the **single source of truth** for detailed behavior; prompts and transient iteration notes must always be synchronized back into these files.

