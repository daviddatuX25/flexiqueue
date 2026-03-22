# API specification — Phase 1 (excerpt)

This file holds Phase 1 API notes that are not yet merged into a larger spec document.

## 3.1 `POST /api/sessions/bind`

Optional JSON field:

- **`priority_lane_override`** (boolean): Allowed only when **`client_category`** is an `Other: …` label (case-insensitive prefix `other:`). When `true` or `false`, the visit is classified for **queue ordering** using this flag while `client_category` remains the free-text label. Omitted when not using an Other category.
