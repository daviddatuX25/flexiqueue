# Display devices + TTS — logic check and code review

**Plan:** Two display device types + TTS audio (no ESP32 API).  
**Review:** Logic checks, edge cases, and goal verification.

---

## 1. Goals (from plan)

| Goal | Verification |
|------|----------------|
| Station-specific display: one page per station, calling + queue, TTS "Calling {alias}", mute/volume on page, real-time | **Met.** Route `GET /display/station/{station}`, `DisplayBoardService::getStationBoardData`, `StationBoard.svelte` with Echo on `display.station.{id}`, TTS on `.station_activity` when `action_type === 'call'`, mute/volume UI and localStorage, refresh on events. |
| General display: TTS "Calling {alias}, please proceed to {station_name}", mute/volume in admin only, real-time | **Met.** `Board.svelte` speaks on `.station_activity` when `action_type === 'call'`; admin Settings has Mute + Volume; `DisplaySettingsUpdated` broadcast; Board listens `.display_settings`. |
| No ESP32 API | **Met.** No device API added; displays are Inertia pages only. |

---

## 2. Edge cases (plan §5)

| Case | Handling in code |
|------|------------------|
| Station id invalid | 404 via Laravel implicit binding (no Station model). |
| Station inactive | `DisplayController::stationBoard`: `! $station->is_active` → `abort(404)`. |
| Station from non-active program / no active program | Same method: `! $program \|\| $station->program_id !== $program->id` → `abort(404)`. |
| Station has no sessions | `getStationBoardData` returns empty `now_serving`, `waiting`, `station_activity`; UI shows "No activity at this station." |
| speechSynthesis missing | StationBoard: `!window.speechSynthesis` → skip speak. Board: same in `speakCallAnnouncement`. |
| Rapid calls | Browser queues utterances; no cancel. Acceptable per plan. |
| General display muted or volume 0 | `speakCallAnnouncement` returns early if `displayAudioMuted`. Volume clamped 0–1. |
| New program / settings absent | `Program::getDisplayAudioMuted()` / `getDisplayAudioVolume()` use `?? false` and `?? 1`. `getBoardData()` when no program returns `display_audio_muted: false`, `display_audio_volume: 1.0`. |
| display_audio_volume validation | `UpdateProgramRequest`: `min:0`, `max:1`; test rejects 1.5 with 422. |
| Display page closed when event fires | Echo unsubscribed on unmount; no-op. |

---

## 3. Logic checks

**Station display**

- **Station match for TTS:** `handleStationActivity` only speaks when `action_type === 'call'` and `Number(e.station_id) === Number(station_id)` so backend int and JSON number/string are both handled.
- **Data scope:** `getStationBoardData` filters sessions by `program_id` and `current_station_id`; activity by `getStationActivity([$station->id], 15)`. Correct for single station.
- **Real-time:** Subscribes to `display.station.{station_id}`; `.station_activity`, `.now_serving`, `.queue_length` trigger refresh and/or TTS. Events broadcast to that channel from `StationActivity`, `NowServing`, `QueueLengthUpdated`.

**General display**

- **Display settings source:** Initial load from `getBoardData()` (program settings). Updates from `.display_settings` and from `refreshBoardData()` (display audio props included in `only` so reloads don’t lose or overwrite them incorrectly).
- **TTS respects mute/volume:** `speakCallAnnouncement` reads `displayAudioMuted` and `displayAudioVolume` at call time; state is updated by props and `.display_settings`.

**Admin**

- **When we broadcast:** `ProgramController@update` dispatches `DisplaySettingsUpdated` only when the **request** contained `display_audio_muted` or `display_audio_volume` (so we don’t broadcast on unrelated updates). Uses `$program->fresh()->getDisplayAudioMuted/Volume()` so payload is the saved value.

---

## 4. Changes made during review

1. **StationBoard:** Station match for TTS made robust to type coercion: `Number(e?.station_id) === Number(station_id)` so Echo payload (string or number) and Inertia prop always match.
2. **Board:** `refreshBoardData()` now includes `display_audio_muted` and `display_audio_volume` in the `only` list so partial reloads still get latest server-side values if a broadcast was missed.

---

## 5. Conclusion

- Plan goals are met: station display, general display TTS, admin-only mute/volume for general display, real-time for both, no ESP32 API.
- Documented edge cases are handled in code.
- Two small robustness improvements were applied (station_id comparison, display audio in reload).
