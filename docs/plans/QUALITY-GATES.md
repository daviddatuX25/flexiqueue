# FlexiQueue — Phase 1 Quality Gates

**Purpose:** Define testing standards, coverage targets, and quality checkpoints that must be met before any bead is marked as done.

This file is referenced by `agentic-workflow.mdc` (line 122).

---

## 1. Testing Pyramid

Phase 1 uses a pragmatic testing approach suited to the one-month capstone timeline:

```text
              ┌───────────┐
              │ Playwright │  ← E2E browser tests (UI flows, login, redirects)
              │    E2E     │
              ├───────────┤
              │  Feature   │  ← Full HTTP request cycle (controllers + services + DB)
              │  Tests     │
              ├───────────┤
              │   Unit     │  ← Service methods, model logic, flow engine
              │   Tests    │
              └───────────┘
```

**Priority order:** Feature tests > Unit tests > Playwright E2E. Phase 1 uses **PHPUnit** for backend tests and **Playwright** for browser verification.

Feature tests give the highest confidence-per-effort because they test the full stack (routing, middleware, validation, service, DB, response).

---

## 2. Coverage Targets

### 2.1 Minimum Coverage by Layer

| Layer | What to Test | Minimum Coverage |
|-------|-------------|-----------------|
| **Services** (SessionService, FlowEngine, PinService) | Unit tests for each public method | **100%** of public methods |
| **API Endpoints** (Controllers) | Feature tests for success + primary error paths | **100%** of endpoints, at least 2 cases each (success + error) |
| **Middleware** (EnsureRole, station access) | Feature tests with different roles | **100%** of role combinations per route group |
| **Models** (business rules, scopes) | Unit tests for scopes and custom methods | **80%** of custom model methods |
| **UI Pages** | Playwright E2E tests for critical flows (login, redirect, forms) | All 4 core pages covered by E2E; manual check at milestones optional |

### 2.2 Critical Paths (Must Have Feature Tests)

These flows are non-negotiable — they MUST have end-to-end feature tests:

| Critical Path | Minimum Test Cases |
|--------------|-------------------|
| **Bind flow** | Success, token_in_use (409), token_lost (409), no_active_program (400), invalid_track (422) |
| **Transfer flow** | Standard success, custom success, not_serving (409), flow_complete (no next station) |
| **Complete flow** | Success, required_steps_remaining (409), already_completed (409) |
| **Cancel flow** | From waiting, from serving, already_terminal (409) |
| **No-show flow** | Success, already_terminal (409) |
| **Override flow** | Success with valid PIN, invalid_pin (401), missing_reason (422) |
| **Force complete** | Success with PIN, invalid_pin (401) |
| **Auth** | Login success, login failure, logout, unauthorized access (403) |
| **RBAC** | Staff can't access admin routes, staff scoped to station, supervisor can override |
| **Transaction log** | Every action creates a log entry, logs are append-only (no update/delete) |

---

## 3. Testing Conventions

### 3.1 Test File Organization

```text
tests/
├── Feature/
│   ├── Auth/
│   │   ├── LoginTest.php
│   │   └── RoleAccessTest.php
│   ├── Session/
│   │   ├── BindSessionTest.php
│   │   ├── TransferSessionTest.php
│   │   ├── CompleteSessionTest.php
│   │   ├── CancelSessionTest.php
│   │   ├── NoShowSessionTest.php
│   │   └── OverrideSessionTest.php
│   ├── Station/
│   │   └── StationQueueTest.php
│   ├── Admin/
│   │   ├── ProgramCrudTest.php
│   │   ├── TrackCrudTest.php
│   │   ├── StationCrudTest.php
│   │   ├── TokenManagementTest.php
│   │   └── UserManagementTest.php
│   └── Reports/
│       └── AuditExportTest.php
├── Unit/
│   ├── Services/
│   │   ├── FlowEngineTest.php
│   │   ├── PinServiceTest.php
│   │   └── SessionServiceTest.php
│   └── Models/
│       ├── SessionTest.php
│       ├── TransactionLogTest.php
│       └── TokenTest.php
└── TestCase.php
```

### 3.2 Test Naming Convention

```php
// Pattern: test_{action}_{scenario}_{expected_outcome}
public function test_bind_with_available_token_creates_session(): void
public function test_bind_with_in_use_token_returns_409(): void
public function test_transfer_standard_moves_to_next_station(): void
public function test_staff_cannot_access_admin_programs_route(): void
```

### 3.3 Test Data Setup

Use Laravel factories and the demo seeder for test data:

```php
// In test setUp or individual test methods
$program = Program::factory()->active()->create();
$track = ServiceTrack::factory()->for($program)->default()->create();
$station = Station::factory()->for($program)->create();
TrackStep::factory()->for($track)->create(['station_id' => $station->id, 'step_order' => 1]);
$token = Token::factory()->available()->create();
$staff = User::factory()->staff()->create(['assigned_station_id' => $station->id]);
```

### 3.4 Transaction Log Assertions

Every feature test that mutates session state MUST assert that a transaction log was created:

```php
// Per 05-SECURITY-CONTROLS.md Section 6.2: Every action creates a log
$this->assertDatabaseHas('transaction_logs', [
    'session_id' => $session->id,
    'action_type' => 'transfer',
    'staff_user_id' => $staff->id,
    'previous_station_id' => $oldStation->id,
    'next_station_id' => $newStation->id,
]);
```

---

## 4. Quality Checkpoints

### 4.1 Per-Bead Gate (Before Marking Done)

Before running `bd close <id>`, verify:

- [ ] All new/modified code has corresponding PHPUnit tests (per TDD loop).
- [ ] `./vendor/bin/sail artisan test` (or `php artisan test`) passes with zero failures.
- [ ] No new linter warnings introduced.
- [ ] Transaction logs are written for every state change (if applicable).
- [ ] Role-based access enforced (if endpoint added/modified).

### 4.2 Feature Batch Gate (Before Moving to Next Feature Set)

After completing a batch of related beads (e.g., all station flow work):

- [ ] Run full PHPUnit suite: `./vendor/bin/sail artisan test`.
- [ ] Run Playwright E2E (if UI changed): `./vendor/bin/sail npx playwright test`.
- [ ] Seed demo data: `./vendor/bin/sail artisan db:seed`.
- [ ] Manually test the happy path in browser (optional).
- [ ] Verify WebSocket events fire correctly (if applicable).
- [ ] State: "Ready for code review — [feature set name]."

### 4.3 Phase 1 Completion Gate (Before Pilot Deployment)

- [ ] Full PHPUnit suite passes: `./vendor/bin/sail artisan test` (zero failures).
- [ ] Playwright E2E suite passes: `./vendor/bin/sail npx playwright test` (app running).
- [ ] Demo seeder creates working environment: `php artisan migrate:fresh --seed`.
- [ ] All 4 core pages render and function (Triage, Station, Display, Admin Dashboard).
- [ ] Admin can configure a full program (tracks, stations, steps, tokens, staff).
- [ ] Full client flow works: bind → transfer through stations → complete.
- [ ] Override flow works: PIN + reason → session re-routed.
- [ ] Informant display shows live updates via WebSocket.
- [ ] Audit log export (CSV) downloads correctly.
- [ ] PDF reports generate correctly (both templates).
- [ ] Offline banner appears when Wi-Fi is disabled.
- [ ] No console errors in browser on any page.
- [ ] Performance: common actions < 500ms on local Wi-Fi.

---

## 5. What NOT to Test (Phase 1)

To stay within the one-month timeline, explicitly skip:

- 100% line coverage — focus on critical paths, not getter/setter coverage.
- Performance/load testing — manual observation during pilot is sufficient.
- Accessibility (a11y) automated testing — follow design tokens (large touch targets, high contrast) but no automated WCAG suite.
- Mobile device matrix testing — test on Chrome mobile emulator + one physical Android phone.

These can be added in Phase 2 if the pilot reveals quality gaps.

---

## 6. Running Tests

**PHPUnit (backend):** Use Sail when available. Run:

```bash
# Run all PHPUnit tests
./vendor/bin/sail artisan test
# Or without Sail:
php artisan test

# Run specific test file
./vendor/bin/sail artisan test --filter=BindSessionTest

# Run with coverage report (requires Xdebug or PCOV)
./vendor/bin/sail artisan test --coverage --min=60

# Run only feature tests
./vendor/bin/sail artisan test tests/Feature

# Run only unit tests
./vendor/bin/sail artisan test tests/Unit
```

**Playwright E2E (browser):** Use Sail for npm and E2E (per environment rule). App must be running (Sail up and optionally `./vendor/bin/sail npm run dev` or built assets). Install npm deps (including Playwright) via Sail: `./vendor/bin/sail npm install`. Run:

```bash
./vendor/bin/sail npx playwright test
# Or: ./vendor/bin/sail npm run test:e2e
```

First-time: install browser binaries in the container: `./vendor/bin/sail npx playwright install`. See `playwright.config.js` and `e2e/` for E2E tests. Optional: set `PLAYWRIGHT_BASE_URL` and `PLAYWRIGHT_LARAVEL_BASE_URL` if the app is not at `http://localhost`.

**Minimum passing coverage for Phase 1 completion: 60% overall**, with 100% coverage on the critical paths listed in Section 2.2.
