# Manual checklist before proceeding to A.5 (Broadcasting)

Run through this after A.4 is done and before starting A.5. Use two **active** programs (e.g. "Alpha" and "Beta") and an **admin** user with **no assigned station** (so the footer program selector is available).

---

## 1. Admin pages

- [ ] **Dashboard** — Log in as admin. Open `/admin/dashboard`. Footer shows program chip; if 2+ programs, **chevron** opens dropdown with all programs. No console errors.
- [ ] **Programs list** — Open `/admin/programs`. Same: footer dropdown (chevron) shows when 2+ programs.
- [ ] **Program Show** — Open `/admin/programs/{id}` for one program. Footer shows that program; dropdown lists all; selecting another program **navigates to that program’s admin page** (URL and content change to the selected program).
- [ ] **Chip from admin → station** — From Program Show (e.g. Beta), click the **main chip** (Ongoing/Stand by + Connected). You go to **Station** and the **same program (Beta)** is in context (station list/triage data for Beta, not Alpha). No jump to “another” program.

---

## 2. Station (admin/supervisor, no station)

- [ ] **Station with program** — Log in as admin (no station). Open `/station` or `/station?program={id}`. Page shows one program’s stations/data. Footer shows that program; **chevron** opens dropdown.
- [ ] **Dropdown: switch program** — On Station, open dropdown, select the **other** program. Page **reloads and stays on Station** with the **new** program’s data (session updated). You do **not** land on an admin program page.
- [ ] **Chip from station → admin** — On Station with e.g. Alpha, click the **main chip**. You go to **Admin Program Show for Alpha** (`/admin/programs/{alphaId}`). Same program, not the other one.

---

## 3. Triage (admin/supervisor, no station)

- [ ] **Triage with program** — As admin, open `/triage` (or `/triage?program={id}`). Page shows one program’s triage. Footer dropdown works.
- [ ] **Dropdown: switch program** — On Triage, select the other program from the dropdown. Page **reloads and stays on Triage** with the new program’s context.
- [ ] **Chip** — Click chip → goes to that program’s admin page (same program).

---

## 4. Program Overrides

- [ ] As admin, open `/program-overrides`. If 2+ programs, footer dropdown is visible; selecting a program **reloads Program Overrides** with that program’s context (session set).

---

## 5. Display (unauthenticated)

- [ ] **No program** — Open `/display`. Program selector (or “pick a program”) shows; no crash.
- [ ] **With program** — Open `/display?program={id}` (valid active program). Board shows that program’s queue/activity. No console errors.

---

## 6. Staff with assigned station

- [ ] Log in as **staff** with an **assigned station** in one program. Open Station and Triage. Footer shows **that** program; **no program dropdown** (staff with station don’t get the selector). Chip still goes to station. No errors.

---

## 7. Backend / tests

- [ ] Run: `./vendor/bin/sail artisan test tests/Feature/HandleInertiaRequestsAdminProgramsTest.php tests/Feature/TriagePageControllerTest.php tests/Feature/Auth/RoleAccessTest.php` — all pass.
- [ ] Optional: full suite `./vendor/bin/sail artisan test` — no regressions.

---

## Sign-off

- [ ] All items above checked; no console errors or wrong program context during the flows.
- [ ] Ready to proceed to **A.5** (Broadcasting channel migration).
