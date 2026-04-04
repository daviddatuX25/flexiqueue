# Follow-Up Backlog

**Purpose of this folder:** Capture issues, edge cases, and product rules that come up during central-edge work but are **not** yet on the main [central-edge-tasks.md](../central-edge-tasks.md). Each specific follow-up lives in its **own .md file** in this folder.

**Reference:** [central-edge-v2-final.md](../specs/central-edge-v2-final.md)

---

## Double-check: staff multi-program and program choice

| What we want | Status |
|--------------|--------|
| Staff can be assigned to many programs (no block) | ✅ API allows; returns optional `warning` |
| No warning toast on **assignment page** when assigning to second program | ✅ Removed |
| On **activate** (program day): warn if staff in multiple active programs, **still proceed** | ✅ Backend adds `warning` to response; frontend shows toast; activation succeeds |
| Admin/supervisor with no station: **one program selector** for Station + Triage (session) | ✅ Done (session + `?program=`, selector on both pages) |
| **Staff** with multiple programs: same **program selector** on Station / Triage / Overrides | 📌 **Backlog:** [staff-program-choice-station-triage-overrides](staff-program-choice-station-triage-overrides.md) — not yet implemented; staff in multiple programs don't see dropdown |

---

## Site separation (multi-tenant data isolation)

**Deep study:** [../SITE-SEPARATION-STUDY.md](../SITE-SEPARATION-STUDY.md) — which entities have `site_id`, which are global, and where cross-site leakage exists.

**Migration planned:** Phase S in [central-edge-tasks.md](../central-edge-tasks.md); execution spec: [../specs/site-scoping-migration-spec.md](../specs/site-scoping-migration-spec.md).

| Entity | Risk | Follow-up |
|--------|------|-----------|
| **Tokens** | Global list; Site B sees all tokens | [token-per-site-or-pool](token-per-site-or-pool.md) |
| **Clients** | Global list; Site B sees all clients | Document in SITE-SEPARATION-STUDY; backlog: client site scoping |
| **Print settings** | Single global row; all sites share | Document in SITE-SEPARATION-STUDY; backlog: print_settings.site_id |

---

## Current follow-ups (open)

- [Staff assignment: multiple programs, warning on activate only](staff-assignment-one-program-per-staff.md)
- [Program day: staff one program at a time](program-day-staff-one-program-at-a-time.md)
- [Token per-site or shared pool](token-per-site-or-pool.md)

**Archived (done/superseded):** See [archive/](archive/README.md) — e.g. shared program selector (admin/supervisor), admin first-active fallback.

---

## How to use

1. **When you discover something** (manual testing, implementation, user feedback): add a new `.md` file here. Describe what was observed, the desired rule or fix, and optional checklist. Name the file clearly (e.g. `staff-assignment-one-program-per-staff.md`).

2. **Choose what to do next** for that follow-up:
   - **Delegate to the task list** — Add beads or sub-tasks to [central-edge-tasks.md](../central-edge-tasks.md) (e.g. under **🔧 A.S** or a new phase item) and work on it later. Optionally add a line at the top of the follow-up doc: "Scheduled as …" with the bead/task id.
   - **Do it now** — Implement the fix in this session; when done, move the follow-up to the archive (step 3).

3. **When a follow-up is finished** (whether you delegated and later completed it, or did it now): move its `.md` file into the **[archive/](archive/)** folder so this folder stays the list of open items only.
