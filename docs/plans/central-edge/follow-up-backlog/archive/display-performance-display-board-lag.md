## Display Page Performance — Multi-Program Display Board Lag (Archived)

> **Status:** Archived — initial instrumentation completed. Future performance tuning will be scheduled as a separate bead/session when prioritized.

### Summary

- Added lightweight timing instrumentation around `DisplayBoardService::getBoardData()`:
  - Logs `display_board.getBoardData` with `program_id`, `duration_ms`, `total_in_queue`, and `station_count` when `APP_DEBUG=true`.
  - Verified via automated tests and manual `/display` hits that the logging is working without changing behavior.
- Verified that:
  - Multi-program correctness is intact (A.6 tests).
  - Display and station queue feature tests still pass after instrumentation.
- Early measurements (small queues, few stations) show backend render times in the tens of milliseconds.

### Notes for Future Performance Work

- When we want to do a deeper performance pass, we should:
  - Reproduce a realistic heavy load (many sessions, multiple active programs).
  - Capture `display_board.getBoardData` logs under that load.
  - Pair those logs with DB/query profiling (e.g., Telescope, slow query log) to identify specific bottlenecks.
  - Potentially add similar instrumentation around other hot endpoints (station queue, dashboard stats) as needed.

