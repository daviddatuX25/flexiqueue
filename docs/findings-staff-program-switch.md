# Findings: Staff multi-program selection / can’t switch program

## How it’s supposed to work

1. **Who can switch**
   - **Admin/supervisor with no assigned station:** always get the program selector (all active programs on “the” site).
   - **Staff with 2+ active program assignments:** get the selector restricted to those programs.

2. **Where it appears**
   - Station (`/station`), Triage (`/triage`), Program Overrides (`/track-overrides`).
   - Footer chip: program name + chevron; chevron opens a drop-up with program list.  
   - Visibility: `showProgramSwitch = programs.length > 1 && ((isLiveSessionRoute && canSwitchProgram) || (isAdminRoute && isAdminOrSupervisor))`.

3. **How switching works**
   - User picks a program in the footer → `switchProgram(programId)` → `router.visit('/station?program=2')` (or `/triage?program=2`, `/track-overrides?program=2`).
   - Controller sees `?program=id`, validates the program (active + **site-scoped**), puts `staff_selected_program_id` in session, redirects to the base URL (e.g. `/station`).
   - Next load uses session to resolve current program; same session is used for Station, Triage, and Overrides.

4. **Backend logic**
   - **Station:** `StationPageController`
   - **Triage:** `TriagePageController`
   - **Overrides:** `ProgramOverridesPageController`
   - All three:
     - Compute `$staffHasMultiProgramAssignments` (distinct active programs in `program_station_assignments` > 1).
     - Compute `$canSwitchProgram = $isAdminOrSupervisorWithoutStation || $staffHasMultiProgramAssignments`.
     - Build `programs` for the selector with `Program::query()->forSite(SiteResolver::default()->id)->where('is_active', true)->...`, and for staff with multi-assignments also `->whereIn('id', $assignedProgramIds)`.
     - When handling `?program=id`, validate with `Program::query()->forSite(SiteResolver::default()->id)->where('id', $programId)->where('is_active', true)->first()`.

So both the **program list** and the **?program=** validation are tied to **the default site** (`SiteResolver::default()->id`), not the **staff user’s site**.

---

## Why you can’t switch or don’t see the selector

### 1. **Site scoping (most likely)**

If staff have a `site_id` and it’s different from the default site, or if “default site” doesn’t match where their programs live:

- **Selector list:** Built with `forSite(SiteResolver::default()->id)`. Only programs on the default site are included. If the staff’s programs are on another site, the list has 0 or 1 program → `programs.length > 1` is false → **no dropdown**.
- **Switching:** `?program=id` is validated with `forSite(SiteResolver::default()->id)`. If the chosen program is on the staff’s site but not the default site, the lookup returns `null` → session is **not** updated → redirect happens but **context doesn’t change**.

So: **using the default site for staff breaks multi-program selection when staff (or their programs) are not on the default site.**

**Fix:** For staff, use the **user’s site** when resolving programs for the selector and when validating `?program=`. Only fall back to the default site when the user has no site (e.g. legacy or super-admin). Same for session program resolution in `StationPageController::resolveProgramForStaffWithoutStation` when the user has a site.

### 2. **Dropdown needs at least 2 programs**

The footer shows the program switch only when `programs.length > 1`. So:

- If the backend sends 0 or 1 program (e.g. because of the site filter above), the dropdown never appears.
- If `canSwitchProgram` is true but the list is wrong, the only way to get the UI is to fix the backend list (e.g. site scoping as above).

### 3. **Count of “multiple programs”**

`$staffHasMultiProgramAssignments` uses:

```php
$user->programStationAssignments()
    ->whereHas('program', fn ($q) => $q->where('is_active', true))
    ->distinct('program_id')
    ->count('program_id') > 1;
```

On some DB drivers, `distinct('program_id')->count('program_id')` may not be a strict “count distinct program_id”. If you ever see staff with two programs but `canSwitchProgram` false (or the opposite), consider a more explicit count, e.g.:

- `->select('program_id')->groupBy('program_id')->get()->count() > 1`, or  
- `->toBase()->distinct()->count('program_id')` (if it generates `COUNT(DISTINCT program_id)`).

This is a secondary concern compared to site scoping.

### 4. **HandleInertiaRequests shared `currentProgram`**

For station/triage, shared `currentProgram` is resolved from route station or `user->assignedStation->program` or (for admin/supervisor only) session. So for **staff**, shared data does **not** use the session program. The footer, however, uses `$page.props?.activeProgram ?? $page.props?.currentProgram`, which come from the **page** (Station/Triage/Overrides controllers), so the footer still shows the correct program. So this is not the cause of “can’t switch”; it’s consistent as long as the controller passes `currentProgram`/`activeProgram`.

---

## Recommended change

- **Use the staff user’s site when building the program selector and when validating `?program=`:**
  - In `StationPageController`, `TriagePageController`, and `ProgramOverridesPageController`:
    - For the programs list and for `?program=` validation, use  
      `$siteId = $user->site_id ?? SiteResolver::default()->id`  
      (and use `SiteResolver::defaultIfExists()` if you need to avoid throwing when no site exists).
    - Use this `$siteId` in `Program::query()->forSite($siteId)->...` for both the selector and the `?program=` check.
  - In `StationPageController::resolveProgramForStaffWithoutStation`, when resolving by session or “first active”, scope programs by the same rule: prefer `$request->user()->site_id`, then default site.

After this, staff on a non-default site still get a correct program list and their program switch will persist in session.
