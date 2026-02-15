# Track Overrides Refactor Plan

**Source:** Cursor plan `track_overrides_refactor_a69244dc`  
**Overview:** Refactor authorization, override, and permission-request flows: persist and list generated PIN/QR, simplify auth UI to PIN | QR | Request approval, switch override from station-based to track-based with custom one-off paths, add awaiting_approval session state (detached from queues), and implement reject-with-track-selection.

---

## Summary of Changes

1. **PIN/QR persistence and configurable TTL**: API to list recent generated authorizations; TTL configurable before generate (including no expiry); preset stays in Profile.
2. **Auth UI simplification**: Replace temp/preset distinction with PIN | QR | Request approval; icon toggle on one horizontal row; remove redundant "Scan supervisor's QR" when QR selected.
3. **Override by track**: Replace `target_station_id` with track selection (Track 1, Track 2, or Custom); Custom = one-off path defined at approve time.
4. **Session state during pending**: New status `awaiting_approval`; session detached from all queues (`current_station_id = null`).
5. **Reject flow**: Admin selects track (or custom) to reassign session to.
6. **Rename page**: "Authorize" → "Track Overrides".

---

## 1. Data Model Changes

### 1.1 Session status: add `awaiting_approval`

- `ALTER TABLE queue_sessions MODIFY status ENUM(..., 'awaiting_approval')`
- Sessions with pending permission requests: set `status = 'awaiting_approval'`, `current_station_id = null`

**Affected queries:** StationQueueService, DisplayBoardService, DashboardService, Session `scopeActive()`.

### 1.2 Session: add `override_steps` for one-off paths

- `queue_sessions.override_steps` JSON nullable — `[station_id1, station_id2, ...]` ordered path.

### 1.3 Permission requests: track-based

- Add `target_track_id` nullable (FK → service_tracks)
- Add `custom_steps` JSON nullable — one-off path for Custom track
- Drop `target_station_id` when API switches (separate migration in TOR-3)

### 1.4 Generated PIN/QR list

- `GET /api/auth/authorizations` returns list for current user (id, type, created_at, expires_at, used_at).

---

## 2. API Changes

- Override: `target_track_id` + optional `custom_steps` (replace `target_station_id`)
- Permission create/approve: track-based
- Permission reject: `{ reassign_track_id?, custom_steps? }`
- Generate PIN/QR: configurable TTL, nullable/0 for no expiry

---

## 3. FlowEngine / SessionService

- FlowEngine: `calculateNextStation()` uses `override_steps` when set
- SessionService: `override()` accepts track + optional custom steps; `reassignToTrack`, `reassignToCustomPath`; set `awaiting_approval` when creating permission request

---

## 4. UI Changes

- Station Override modal: Track picker instead of station dropdown; Custom option
- Station Force Complete modal: same auth simplification
- Track Overrides page: TTL dropdown, list of generated auths, approve with path, reject with reassign

---

## 5. Test Scenarios

- Session set to awaiting_approval when override request created; excluded from station queues
- Approve override to Track 1/2: session moves to first station of track
- Approve override with custom_steps: session gets override_steps, moves to first in path
- Reject with reassign_track_id: session moves to that track, status waiting
- FlowEngine uses override_steps when present
- Generated PIN/QR list returns recent; TTL configurable; no-expiry works
