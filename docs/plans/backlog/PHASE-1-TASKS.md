# Phase 1 — Task and bead approach

**We do not keep a pre-generated list of Phase 1 tasks.** Concepts and scope can change; a long fixed backlog becomes outdated and noisy.

## How we work

1. **Direction comes from the plan docs**  
   Use the architecture and plan markdown files as the source of truth:
   - `docs/architecture/` — API spec, data model, security, UI routes, flow engine
   - `docs/plans/PHASES-OVERVIEW.md` — what is in/out for Phase 1
   - `docs/plans/REFACTOR-TRACKS-AND-STATIONS.md` and any other plan docs we add

2. **Tasks are added when they are clear**  
   When you scan the project and the docs, only add a task (e.g. as a bead) for work that is **clearly in front** and **strictly sure** given current progress. If something is speculative or might change with concept shifts, don’t add it yet.

3. **Beads = long-term memory for what’s confirmed**  
   Beads are for things we’re committed to and that reflect **current** progress and decisions. Because concepts change, we don’t treat beads as a full roadmap; we use them for concrete next steps and completed work we want to remember.

4. **Finding “next work”**  
   - Run `bd ready` to see existing beads.
   - Scan the codebase and the plan docs to see what’s missing or blocked.
   - Add a bead only when there is a clear, agreed next piece of work.
   - Prefer creating a small number of well-scoped beads over a large pre-generated list.

## What was removed

The previous version of this file contained a long pre-generated list of tasks (BD-001 through BD-052, dependency graph, sprint plan). That list has been removed so we can rely on the plan docs and add tasks only when they are clear and stable. The script `scripts/import-phase1-beads.sh` is no longer used for that list.

## References

- **Scope and phase:** `docs/plans/PHASES-OVERVIEW.md`
- **API and data:** `docs/architecture/08-API-SPEC-PHASE1.md`, `04-DATA-MODEL.md`
- **UI and flows:** `docs/architecture/09-UI-ROUTES-PHASE1.md`, `03-FLOW-ENGINE.md`
- **Quality:** `docs/plans/QUALITY-GATES.md`
