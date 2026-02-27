# FlexiQueue Manual Testing Guide

**Purpose:** One-session guide to manually test the full system end-to-end. Covers happy paths and key edge cases. Use before a release, demo, or handoff.

**Time:** ~45–90 minutes for full coverage.

---

## Prerequisites

### Environment

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail npm run build
```

### Test Credentials (from seeder)

| Role | Email | Password | PIN |
|------|-------|----------|-----|
| Admin | admin@tagudinmswdo.gov.ph | password | 123456 |
| Staff (Window 1) | staff1@tagudinmswdo.gov.ph | password | 123456 |
| Staff (Window 2) | staff2@tagudinmswdo.gov.ph | password | 123456 |
| Staff (Window 3) | staff3@tagudinmswdo.gov.ph | password | 123456 |
| Staff (Window 4) | staff4@tagudinmswdo.gov.ph | password | 123456 |
| Supervisor | staff6@tagudinmswdo.gov.ph | password | 123456 |

**AICS** is the active program. 4 stations: Window 1 – Screening, Window 2 – Interview, Window 3 – Verification, Window 4 – Cash Release.

### Create Tokens (Required)

1. Login as **admin@tagudinmswdo.gov.ph**
2. Go to **Admin → Tokens**
3. Click **Create Batch**
4. Prefix: `A`, Start: `1`, Count: `10` → Create
5. Tokens A1–A10 will be available for Triage

---

## Part 1: Auth & RBAC

| # | Action | Expected |
|---|--------|----------|
| 1.1 | Open `/login`, enter admin credentials | Redirect to `/admin/dashboard` |
| 1.2 | Logout, login as staff1@tagudinmswdo.gov.ph | Redirect to `/station` (staff home) |
| 1.3 | Staff: try `/admin/dashboard` directly | 403 or redirect away |
| 1.4 | Invalid email/password | Error message, stay on login |
| 1.5 | Logout | Redirect to `/login` |

---

## Part 2: Full Client Flow (Happy Path)

### 2.1 Triage (Bind)

1. Login as **staff1** (or any staff with AICS access)
2. Go to **Triage** (bottom dock or nav)
3. **Scan or enter token**
   - Option A: Enter token ID `A1` and click **Look up**
   - Option B: Start camera, scan printed QR for A1
4. After scan: pick **Regular** (or other category), select track if multiple
5. Click **Confirm**
6. **Expected:** Success; client bound; triage returns to “Scan or enter token”

### 2.2 Station: Call Next

1. Go to **Station** (bottom dock)
2. If queue is empty: no client shown, **Call Next** disabled
3. After Triage bind: client A1 appears in queue
4. Click **Call Next** (or **Call Next Client**)
5. **Expected:** Client A1 moves to “Now serving”; alias shown; timer starts

### 2.3 Station: Transfer

1. With A1 being served at Window 1
2. Click **SEND TO [Window 2 – Interview]** (or equivalent primary action)
3. **Expected:** Session transfers to next station; Window 1 shows “No client active”; Window 2 gets A1 in queue
4. Login as **staff2** (or switch station if supervisor)
5. Window 2: **Call Next** → A1 becomes “Now serving”
6. Transfer to Window 3, repeat for Window 4

### 2.4 Station: Complete

1. At **Window 4 – Cash Release** (last step)
2. A1 should show **COMPLETE SESSION** (not “Send to…”)
3. Click **Complete Session**
4. **Expected:** Session completed; client leaves; Window 4 empty

### 2.5 Display Board

1. Open `/display` in another tab (public, no login)
2. **Expected:** “Now Serving” and “Currently Waiting” update as you move clients
3. During flow: A1 should appear in “Now Serving” when being served, then disappear when completed

### 2.6 Client Status Check

1. While A1 is in queue (any station)
2. On Display: click **TAP TO SCAN QR CODE**
3. Scan QR for token A1 (or enter qr_hash if known)
4. **Expected:** `/display/status/{qr_hash}` shows alias, progress steps, current station
5. Click **OK GOT IT** → back to display

---

## Part 3: Edge Cases & Alternate Flows

### 3.1 Double Scan (Token Already In Use)

1. Bind A2 at Triage (same as 2.1)
2. In another tab or device, go to Triage again
3. Scan or enter **A2** again
4. **Expected:** “Token is already in use” or double-scan modal with active session info
5. Option: **Force End** with supervisor PIN (123456) if needed

### 3.2 Cancel Session

1. Bind A3, call at Window 1
2. With A3 “Now serving”, click **Cancel Session**
3. Confirm
4. **Expected:** Session cancelled; A3 back to available; no longer in queue

### 3.3 No-Show

1. Bind A4, call at Window 1
2. With A4 “Now serving”, click **Mark No-Show**
3. **Expected:** Count increments (e.g. 1/3); after 3 no-shows, session may auto-cancel (if implemented)

### 3.4 Override (Send to Different Station)

1. Bind A5, call at Window 1
2. Click **Override** (or similar)
3. Select a **different station** (e.g. Window 4 – skip steps)
4. Enter **reason** and **supervisor PIN** (123456)
5. Confirm
6. **Expected:** Session jumps to selected station; bypasses normal flow

### 3.5 Requeue

1. Bind A6, call at Window 1
2. With A6 “Now serving”, click **Re-queue**
3. **Expected:** A6 goes back to end of queue; Window 1 shows “No client active”

### 3.6 Invalid Token

1. At Triage, enter token ID `ZZZ` (or non-existent)
2. **Expected:** Error “Token not found” or similar

### 3.7 Token Lost / Damaged

1. Admin → Tokens → mark A7 as **Lost** or **Damaged**
2. At Triage, try to bind A7
3. **Expected:** Error; token unavailable

---

## Part 4: Admin

### 4.1 Dashboard

1. Login as admin
2. Go to **Admin Dashboard**
3. **Expected:** Stats (active sessions, queue, stations online, completed today); station table; active program section

### 4.2 Programs

1. **Admin → Programs**
2. List programs (AICS active; others inactive)
3. Click AICS → Program detail (tracks, stations, steps)
4. Edit program (name, description) → Save
5. **Expected:** Changes persist; UI updates

### 4.3 Tracks & Steps

1. In AICS program, open **Tracks**
2. View Regular track, step order
3. Add/remove/reorder steps (if UI supports)
4. **Expected:** Steps reflect in station flow

### 4.4 Stations

1. **Admin → Programs → AICS → Stations**
2. List stations; edit name, capacity
3. Toggle active/inactive
4. **Expected:** Inactive station no longer in transfer options

### 4.5 Tokens

1. **Admin → Tokens**
2. Filter by status (available, in_use, lost, damaged)
3. Batch create more (e.g. B1–B10)
4. Mark one as Lost, another as Available
5. **Expected:** Status updates; Triage respects status

### 4.6 Users

1. **Admin → Users**
2. List staff; edit role, station assignment
3. Add new staff
4. **Expected:** New user can login; station assignment affects station page

### 4.7 Reports

1. **Admin → Reports**
2. Set date range, apply filters
3. View audit log
4. **Export CSV**
5. **Generate PDF** (if available)
6. **Expected:** Export downloads; data matches actions performed

---

## Part 5: Mobile & Layout

| # | Check | Expected |
|---|-------|----------|
| 5.1 | Resize browser to 375px (mobile) | Triage, Station usable; large touch targets |
| 5.2 | Status footer (network, availability) | Shows Connected/Offline; tap to cycle availability |
| 5.3 | Bottom dock (Station, Triage, Track Overrides) | Tabs visible; navigation works |

---

## Part 6: Optional / Nice-to-Have

| # | Check | Expected |
|---|-------|----------|
| 6.1 | Offline: disable Wi‑Fi briefly | Offline banner appears |
| 6.2 | Profile: change availability | Status updates in footer |
| 6.3 | Track Overrides (if enabled) | Supervisor can manage overrides |
| 6.4 | Browser console | No errors on core pages |

---

## Checklist Summary

Use this as a quick pass/fail checklist:

- [ ] Login (admin, staff, invalid)
- [ ] Staff cannot access admin
- [ ] Triage: bind (scan + manual entry)
- [ ] Station: Call Next, Transfer, Complete
- [ ] Display: live updates, status check
- [ ] Double scan handling
- [ ] Cancel, No-show, Override, Requeue
- [ ] Invalid / lost token
- [ ] Admin: Dashboard, Programs, Tracks, Stations, Tokens, Users, Reports
- [ ] Mobile layout
- [ ] Logout

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Blank page after login | Run `./vendor/bin/sail npm run build` |
| "Token not found" at Triage | Create tokens via Admin → Tokens → Create Batch |
| No active program | Activate AICS in Admin → Programs |
| Station shows wrong program | Ensure staff assigned to AICS station |
| Display not updating | Check Reverb/WebSocket; app must be serving |
