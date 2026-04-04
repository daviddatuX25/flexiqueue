# Admin/supervisor "first active program" fallback

**Source:** A.3 implementation; station/triage UX for admin with no assigned station.

**Status:** ✅ Superseded by A.4.5 — program selector in StatusFooter now replaces this; fallback remains in backend for backward compat.

---

## Current behavior

Station and Triage pages use "first active program" (by name) when the user is admin or supervisor and has **no** assigned station, so they can see a station list and use triage.

## Follow-up

Phase **A.4** (program selector in sidebar/URL) should replace this with an explicit program choice; the fallback is for backward compatibility until A.4 is done.

**Scheduled as A.4.5** in [central-edge-tasks.md](../../central-edge-tasks.md): remove first-active-program fallback when shared program selector is in place. See [staff-shared-program-selector](staff-shared-program-selector.md): one selection for both Station and Triage (session + optional `?program=id`).
