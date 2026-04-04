# FlexiQueue — Final Plan: CI/CD + Edge Mode (v4)
> All decisions locked. Grounded in code map 2244 + clarification answers.

---

## Part A — CI/CD

---

### A1. North Star

- One repo, one `main` branch, deploy by tags (`vX.Y.Z`) only.
- Tags trigger two CI jobs: **Central FTP deploy** and **Edge GitHub Release tarball**.
- `prod` branch as release truth is retired.
- Env differences live in `.env` and runtime config, never in branches.

---

### A2. Server & Path Constants

| Thing | Value |
|---|---|
| FTP root (Hestia, already set) | `/home/avelinht/web/flexiqueue.click/public_html/` |
| Docroot | `/home/avelinht/web/flexiqueue.click/public_html/public` |
| php-run-scripts (absolute, for cron) | `/home/avelinht/web/flexiqueue.click/public_html/php-run-scripts/` |
| Edge app dir (Pi) | `/var/www/flexiqueue` |
| Central broadcaster | Pusher |
| Edge broadcaster (both modes) | Reverb (local, nginx-proxied) |

> FTP root already points at app root — `server-dir` in the CI action is `/`.

---

### A3. Release Strategy

```
dev  →  main  →  tag vX.Y.Z  →  CI triggers both jobs
```

- Feature work on `dev`. Merge to `main` when ready.
- Tag triggers deploy: `git tag v1.3.0 && git push origin v1.3.0`
- Rollback = push an older tag. Same FTP + tarball workflow.

---

### A4. Central Deploy Job (GitHub Actions → FTP)

**Steps on tag:**

1. Checkout tagged commit.
2. PHP 8.2 + `composer install --no-dev --optimize-autoloader`.
3. Node 20 + `npm ci && npm run build` with Pusher secrets injected.
4. Write tag name to `storage/app/version.txt`.
5. `touch bootstrap/cache/deploy_pending` (cron pickup).
6. FTP sync to `/` — incremental. Never overwrite `.env` or `storage/**`.

**Must be included:**
```
vendor/
public/build/
public/.htaccess
php-run-scripts/
bootstrap/cache/deploy_pending
storage/app/version.txt
```

**Must never be overwritten:**
```
.env
storage/**
```

---

### A5. php-run-scripts Lifecycle

#### Stage 1 — First deploy ever (one time, Hestia panel)

```
php /home/avelinht/web/flexiqueue.click/public_html/php-run-scripts/initial-setup.php
```

**Extend `run_initial_setup()` in `helpers.php` to include:**
- `key:generate` if `APP_KEY` missing
- `storage:link`
- `migrate --force`
- `db:seed --class=SuperAdminSeeder`
- `config:cache`, `route:cache`

**Set in `.env` on server before running:**
```
SUPER_ADMIN_EMAIL=your@email.com
SUPER_ADMIN_PASSWORD=strong-password
```

#### Stage 2 — Every tag deploy (automated cron marker)

**Hestia cron (set once):**
```bash
*/2 * * * * [ -f /home/avelinht/web/flexiqueue.click/public_html/bootstrap/cache/deploy_pending ] && \
  php /home/avelinht/web/flexiqueue.click/public_html/php-run-scripts/deploy-update.php && \
  rm /home/avelinht/web/flexiqueue.click/public_html/bootstrap/cache/deploy_pending
```

`run_deploy_update()` already handles: `migrate --force`, `config:cache`, `route:cache`. No changes needed.

#### Stage 3 — Ongoing maintenance (superadmin UI)

Superadmin-only secured route inside the app:

| Action | Implementation |
|---|---|
| `config:cache`, `route:cache`, `view:clear` | `Artisan::call()` |
| App version | Read `storage/app/version.txt` |
| Migration status | `Artisan::call('migrate:status')` |
| Force reseed | `ALLOW_FORCE_RESEED=true` guard + multi-step confirmation |

Security: superadmin gate, CSRF, rate-limited, full audit log.

---

### A6. Edge Deploy Job (GitHub Release Tarball)

**Steps on tag:**

1. PHP 8.3 + `composer install --no-dev --optimize-autoloader`.
2. `npm ci && npm run build` with Reverb secrets + `VITE_REVERB_VIA_PROXY=true`.
3. Build `flexiqueue-vX.Y.Z-edge.tar.gz` — excludes `php-run-scripts`, `storage`, `tests`, `.env`, `.github`.
4. Publish as GitHub Release asset.

**OTA update on Pi:**
```bash
sudo flexiqueue-update "https://github.com/ORG/flexiqueue/releases/download/vX.Y.Z/flexiqueue-vX.Y.Z-edge.tar.gz"
```

---

### A7. Script Cleanup

**Delete outright:**

| File | Reason |
|---|---|
| `scripts/deploy-via-prod-to-pi.sh` | Tags replace prod-branch as release truth |
| `scripts/deploy-via-prod.sh` | Already DEPRECATED internally |
| `scripts/pi/zerotier-when-idle.sh` | Dropped by decision |

**Move to `scripts/legacy/`:**

| File | Reason |
|---|---|
| `scripts/deploy-to-pi-edge.sh` | Keep until edge onboarding ships (B-13) |

**Migration deletion fix (blocker for CI) — simpler than expected:**

The scripts delete `2025_02_15_000013_create_print_settings_table.php` but that exact filename no longer exists in the repo. The current file is `2025_02_15_000013_000002_create_print_settings_table.php` which already has `if (Schema::hasTable('print_settings')) { return; }` — it is idempotent. The scripts are deleting a ghost.

**Fix:** Remove the deletion lines from all three scripts. Nothing else needed.
- `scripts/pi/apply-tarball.sh`
- `scripts/laragon/apply-tarball.sh`
- `scripts/pi/update-from-url.sh`

**APP_KEY OTA gap (fix alongside):**

`update-from-url.sh` does not preserve `APP_KEY` before overwriting `.env`. Port the APP_KEY preservation block from `apply-tarball.sh` into `update-from-url.sh`.

---

### A8. GitHub Actions Workflow

Create `.github/workflows/deploy.yml`:

```yaml
on:
  push:
    tags: ['v*.*.*']

jobs:

  deploy-central:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2' }
      - run: composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: npm ci
      - run: npm run build
        env:
          VITE_BROADCASTER: pusher
          VITE_PUSHER_APP_KEY: ${{ secrets.VITE_PUSHER_APP_KEY }}
          VITE_PUSHER_APP_CLUSTER: ${{ secrets.VITE_PUSHER_APP_CLUSTER }}
      - run: echo "${{ github.ref_name }}" > storage/app/version.txt
      - run: touch bootstrap/cache/deploy_pending
      - uses: SamKirkland/FTP-Deploy-Action@v4
        with:
          server: ${{ secrets.FTP_HOST }}
          username: ${{ secrets.FTP_USER }}
          password: ${{ secrets.FTP_PASSWORD }}
          server-dir: /
          exclude: |
            **/.env
            **/storage/**

  deploy-edge-release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: npm ci
      - run: npm run build
        env:
          VITE_REVERB_APP_KEY: ${{ secrets.VITE_REVERB_APP_KEY }}
          VITE_REVERB_HOST: ${{ secrets.VITE_REVERB_HOST }}
          VITE_REVERB_PORT: ${{ secrets.VITE_REVERB_PORT }}
          VITE_REVERB_SCHEME: https
          VITE_REVERB_VIA_PROXY: "true"
      - name: Build edge tarball
        run: |
          tar -czf flexiqueue-${{ github.ref_name }}-edge.tar.gz \
            --exclude='.git' --exclude='node_modules' --exclude='.env' \
            --exclude='storage' --exclude='tests' --exclude='e2e' \
            --exclude='.github' --exclude='php-run-scripts' \
            .
      - name: Publish GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          files: flexiqueue-${{ github.ref_name }}-edge.tar.gz
```

**GitHub Secrets required:**

| Secret | Job |
|---|---|
| `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD` | Central deploy |
| `VITE_PUSHER_APP_KEY`, `VITE_PUSHER_APP_CLUSTER` | Central build |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` | Edge build |
| `GITHUB_TOKEN` | Auto-provided |

---

### A9. CI/CD Delivery Order

| Phase | Work | Prerequisite |
|---|---|---|
| **A-1** | Remove ghost migration deletion lines from 3 scripts + APP_KEY OTA fix | — |
| **A-2** | Add `deploy.yml` + GitHub Secrets | A-1 |
| **A-3** | Extend `run_initial_setup()` to call SuperAdminSeeder | A-2 |
| **A-4** | Set Hestia cron for marker-based auto-deploy | A-2 live |
| **A-5** | Script cleanup PR | A-2 green |
| **A-6** | Central Maintenance UI (superadmin panel) | A-3 |

---

## Part B — Edge Mode

---

### B1. What Already Exists

| Thing | Status |
|---|---|
| `APP_MODE=edge/central` | Working, enforced via `EdgeModeService` |
| `EdgeModeService` | Has `isEdge()`, `isCentral()`, `isOnline()` (stub — must implement), `bridgeModeEnabled()` |
| `EDGE_BRIDGE_MODE` → `config('app.edge_bridge_mode')` | Wired in `config/app.php` |
| `CENTRAL_URL` + `CENTRAL_API_KEY` → config | Wired — `CENTRAL_API_KEY` will be replaced by `device_token` after B-11 |
| `edge:import-package` artisan command | Exists. Requires `CENTRAL_URL`, `CENTRAL_API_KEY`, `--program=ID` |
| `EdgePackageImportService` | Exists. Handles program sync from central |
| `EdgeImportController` + `ImportProgramPackageJob` | Exists. HTTP-triggered async import |
| `edge_settings` JSON on `sites` | Exists. Holds `bridge_enabled`, `sync_tokens`, `sync_clients`, `sync_tts`, `scheduled_sync_time` |
| `sites.api_key_hash` | Exists. Not used for Pi pairing in v2 — pairing uses short-lived tokens instead |
| `supervisor` | Not a role — per-program permission via `program_supervisors` table |
| `print_settings` migration | `_000002` variant already idempotent (`hasTable` guard). Ghost deletion in deploy scripts is the only issue — fixed in A-1 |

---

### B2. Two Modes

Both modes use **local SQLite + Reverb**. Central (hosting) uses Pusher and is a completely separate deployment target.

| Mode | DB | Real-time | Central sync | Use case |
|---|---|---|---|---|
| **Sync on the go** | Local SQLite | Reverb (local) | Real-time push on every queue/session event — both local and central stay in sync continuously | Reliable internet, central always current |
| **Sync after session** | Local SQLite | Reverb (local) | Manual only — staff taps "Sync" after session; all data pushed at once | Unreliable/no internet, offline-capable |

`EDGE_BRIDGE_MODE=true` → Sync on the go. `EDGE_BRIDGE_MODE=false` → Sync after session.
`EdgeModeService::bridgeModeEnabled()` already maps to this. No new config keys needed.

---

### B3. Program Locking and Session State

**One program per edge session. Always.**

- Edge device is locked to one assigned program for the entire session.
- Admin on the edge device sees only that program — no picker while session is active.
- Program cannot change while a session is running.

**Reassignment is always manual (central admin action).** There is no automatic program switching.

**Central admin attempting to reassign while a session is active:**
- Central shows a warning: "This device has an active session. End or dump the session before reassigning."
- Reassignment is blocked until the edge device has no active session.
- Admin must either wait for the session to end naturally, or trigger a "dump session" from central which forces the edge to push all remaining queue data to central and close the session.

**After session ends (either normally or via dump):**
- Edge returns to the waiting splash.
- Central admin can now assign a new program.
- No credentials are re-entered — device is already paired.

**Sync on the go — seamless reassignment:**
- Because data is continuously synced, session end is already reflected on central immediately.
- Reassignment can happen right after session ends with no manual sync step.

---

### B4. What Sync After Session Pushes to Central

When staff taps "Sync" after a session, all of the following go to central:

- Session summary (tokens served, timestamps, program info)
- Full queue log
- Client records created or updated during the session
- TTS audio generated during the session

This is a full push of everything produced locally. Central merges it into its own DB.

---

### B5. Edge Device States (Lifecycle)

```
[Flash golden image]
       ↓
[First boot — no identity]
       ↓
[Setup Wizard] ← runs exactly once
  - Central URL
  - Pairing code (from central Edge Devices page)
  - Mode selection (sync on the go / sync after session)
       ↓
[Waiting for program assignment] ← splash, no wizard
  "Paired to [Site Name]. Awaiting program assignment."
  Polls / listens for central to assign a program.
       ↓
[Program syncing]
  edge:import-package runs for assigned program.
  Shows progress.
       ↓
[Session active — locked to program]
  Normal edge UI. One program. Admin sees only this program.
  Sync on the go: every event pushed to central in real time.
  Sync after session: local only, manual sync button available.
       ↓
[Session ended or dumped]
       ↓
[Waiting for program assignment] ← back to splash, no re-setup
```

**The setup wizard runs exactly once.** Returning to the waiting splash never re-triggers the wizard. Credentials, pairing, and mode are already stored in `edge_device_state`.

---

### B6. edge_device_state Table (Single Row, id=1)

| Column | Purpose |
|---|---|
| `paired_at` | null = not configured; timestamp = wizard complete |
| `central_url` | Stored during wizard |
| `site_id` | Received from central on pairing |
| `site_name` | Cached for display on splash |
| `device_token` | Issued by central on `POST /edge/pair`; used for all central API calls |
| `sync_mode` | `bridge` (sync on the go) or `sync` (sync after session) |
| `supervisor_admin_access` | bool — pushed from central assignment page |
| `active_program_id` | null = waiting; set when central assigns; cleared when session ends |
| `active_program_name` | Cached for display |
| `session_active` | bool — true when a session is running |
| `last_synced_at` | Timestamp of last successful sync to central |

---

### B7. Boot Middleware

On every request, check in order:

1. `paired_at` is null → redirect to `/edge/setup` (wizard)
2. `active_program_id` is null → redirect to `/edge/waiting` (waiting splash)
3. Otherwise → normal edge UI

---

### B8. Setup Wizard (Runs Once)

**Steps:**

1. Welcome + connectivity check (ping `CENTRAL_URL`).
2. Central URL field — editable, defaults to `https://flexiqueue.click`.
3. Pairing code entry — short code from central Edge Devices page (e.g. `ABCD-1234`).
   - Calls `POST /edge/pair` on central with `{ pairing_code, central_url }`.
   - Central returns: `device_token`, `site_id`, `site_name`, `device_id`.
   - Stored in `edge_device_state`. No API key ever stored on the Pi.
4. Mode selection — "Sync on the go" or "Sync after session." Brief description of each shown.
5. Confirm → `EdgeDeviceSetupService`:
   - Writes `edge_device_state` row.
   - Updates `.env` programmatically: `CENTRAL_URL`, `EDGE_BRIDGE_MODE`.
   - Runs `config:clear`, `config:cache`.
   - Sets `paired_at`.
6. Redirect to `/edge/waiting`.

---

### B9. Waiting Splash (`/edge/waiting`)

- Displays: "Paired to [site_name]. Awaiting program assignment from your administrator."
- Polls `GET /edge/assignment` on central (or listens via WebSocket if online).
- When assignment arrives: auto-triggers `edge:import-package --program=ID` → shows progress → redirects to edge UI.
- No user action required.

---

### B10. Supervisor Admin Access

Toggle is set per device on the central assignment page by site admin.

- **OFF (default):** Only `admin` role users can access the edge admin panel.
- **ON:** Users who are supervisors of the currently assigned program (`program_supervisors` table) get full admin access on this edge device — same capabilities as admin role. No partial access; it is full or nothing.

`supervisor_admin_access` is stored in `edge_device_state` and pushed to the device on each assignment sync. Edge middleware/gate reads it when checking admin panel access.

---

### B11. Central Assignment Page

**Access:** Site admin of that site only.

**Location:** Dedicated "Edge Devices" section within Site settings (not a separate top-level page).

**Per device row:**

| Field | Notes |
|---|---|
| Device name | Editable |
| Last seen | From heartbeat |
| Mode | Sync on the go / Sync after session |
| Status | Active session / Waiting / Offline |
| Assigned program | Dropdown of active programs for this site |
| Supervisor admin access | Toggle (full access or admin-only) |
| Actions | Assign, Dump session + reassign, Revoke device |

**Pairing code generation:**
- "Add device" button → generates a short-lived pairing code (10 min TTL, single-use).
- Shows code + QR for easy entry on Pi wizard.
- On successful pairing, device appears in the list.

**Reassignment guard:**
- If `session_active = true` on the device, the assign/reassign control shows: "Active session in progress. Dump session to reassign."
- "Dump session" button → sends signal to edge to push all queue data to central and close the session → then allows reassignment.

**`max_edge_devices` per site:**
- Central enforces: "Add device" is disabled if site is already at its device limit.
- Superadmin sets the limit per site.

---

### B12. Central API Endpoints (New)

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /edge/pair` | Pairing code (one-time) | Exchange code for `device_token` + site binding |
| `GET /edge/assignment` | Device token | Poll for current program assignment |
| `POST /edge/session/dump` | Device token | Push all session data + close session |
| `POST /edge/heartbeat` | Device token | Update `last_seen_at` |
| `POST /edge/sync` | Device token | Sync after session — full data push |
| `POST /edge/event` | Device token | Sync on the go — single event push |

---

### B13. Golden Image Build Script

A script (`scripts/pi/build-golden-image.sh`) that runs on a developer machine to produce a flashable `.img`:

**What the script does:**
1. Starts from a base Armbian/Debian image (via `qemu` or direct on a Pi).
2. Installs: PHP 8.3, PHP-FPM, nginx, SQLite3, required PHP extensions.
3. Clones or extracts the latest edge tarball into `/var/www/flexiqueue`.
4. Generates `APP_KEY` (`php artisan key:generate`).
5. Sets `APP_MODE=edge`, `DB_CONNECTION=sqlite` in `.env`.
6. Runs `php artisan migrate --force` (schema ready, no data).
7. Copies and enables systemd units: `flexiqueue-reverb.service`, `flexiqueue-queue.service`.
8. Configures nginx for flexiqueue.
9. Does NOT set `CENTRAL_URL`, `CENTRAL_API_KEY`, `EDGE_BRIDGE_MODE` — wizard sets these.
10. Writes a first-boot marker so the app knows `edge_device_state` is empty.
11. Shrinks and compresses the image for distribution.

**Output:** `flexiqueue-golden-vX.Y.Z.img.gz` — flash with Balena Etcher or `dd`.

---

### B14. isOnline() Implementation

`EdgeModeService::isOnline()` is currently a stub returning `false`. This is the single most critical prerequisite for Sync on the go mode.

**Implementation:**
- HTTP GET to `{CENTRAL_URL}/api/ping` with a short timeout (2–3s).
- Cache the result for 30 seconds to avoid hammering on every request.
- Returns `true` if response is 200, `false` on timeout/error.
- Used by: Sync on the go event push, waiting splash poll, heartbeat.

---

### B15. Edge Mode Delivery Order

| Phase | Work | Prerequisite |
|---|---|---|
| **B-1** | Implement `isOnline()` in `EdgeModeService` | A-2 |
| **B-2** | `edge_device_state` migration + model | B-1 |
| **B-3** | Boot middleware (wizard / waiting / normal routing) | B-2 |
| **B-4** | `POST /edge/pair` on central + pairing token schema | B-3 |
| **B-5** | Wizard UI + `EdgeDeviceSetupService` | B-4 |
| **B-6** | Waiting splash + `GET /edge/assignment` poll | B-5 |
| **B-7** | Central assignment page (program dropdown, supervisor toggle, dump guard) | B-6 |
| **B-8** | `supervisor_admin_access` gate on edge admin panel | B-7 |
| **B-9** | Sync on the go: `POST /edge/event` on every queue/session event | B-7 |
| **B-10** | Sync after session: "Sync" button + `POST /edge/sync` full push | B-7 |
| **B-11** | Session dump: `POST /edge/session/dump` + reassignment guard on central | B-7 |
| **B-12** | Heartbeat: `POST /edge/heartbeat` background ping | B-6 |
| **B-13** | Golden image build script | B-5 (can parallel) |
| **B-14** | Retire `scripts/deploy-to-pi-edge.sh` | B-13 |

---

### B16. What Retires When

| Retired | When | Replaced by |
|---|---|---|
| `scripts/deploy-to-pi-edge.sh` | After B-14 | Golden image + wizard |
| `CENTRAL_API_KEY` in edge `.env` | After B-5 | `device_token` in `edge_device_state` |
| Manual `--program=ID` at deploy | After B-7 | Central assignment page |
| Fixed program binding at setup | After B-7 | Dynamic central assignment |

---

## Subagent prompts (copy-paste)

Final prompts with handoff instructions. Use the spawn order at the end to run subagents.

---

**Subagent A-1**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is A-1: Fix two script issues before CI/CD can ship.

Fix 1 — Remove ghost migration deletion lines.
The following three scripts each contain a line that deletes a migration file that no longer exists in the repo. Find and remove those lines from all three scripts:
- scripts/pi/apply-tarball.sh
- scripts/laragon/apply-tarball.sh
- scripts/pi/update-from-url.sh

The line to remove in each looks like:
  sudo rm -f "$APP_DIR/database/migrations/2025_02_15_000013_create_print_settings_table.php"
or similar. Remove only that line. Do not change anything else in those scripts.

Fix 2 — Port APP_KEY preservation to update-from-url.sh.
scripts/pi/apply-tarball.sh has a block that reads the existing APP_KEY from .env before overwriting it, then restores it after. scripts/pi/update-from-url.sh does not have this. Port that exact preservation logic into update-from-url.sh in the same position (before the tar extract, restore after).

When done, reply with:
## Handoff
**Subagent:** A-1
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** A-2 can now be spawned
**Notes:** [anything unexpected found]
```

---

**Subagent A-2**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is A-2: Create the GitHub Actions deploy workflow.

Create .github/workflows/deploy.yml with two jobs triggered on tags matching v*.*.* :

Job 1 — deploy-central:
- runs-on: ubuntu-latest
- PHP 8.2 via shivammathur/setup-php@v2
- composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
- Node 20 via actions/setup-node@v4
- npm ci then npm run build with these env vars:
    VITE_BROADCASTER: pusher
    VITE_PUSHER_APP_KEY: ${{ secrets.VITE_PUSHER_APP_KEY }}
    VITE_PUSHER_APP_CLUSTER: ${{ secrets.VITE_PUSHER_APP_CLUSTER }}
- run: echo "${{ github.ref_name }}" > storage/app/version.txt
- run: touch bootstrap/cache/deploy_pending
- FTP sync using SamKirkland/FTP-Deploy-Action@v4:
    server: ${{ secrets.FTP_HOST }}
    username: ${{ secrets.FTP_USER }}
    password: ${{ secrets.FTP_PASSWORD }}
    server-dir: /
    exclude: **/.env and **/storage/**

Job 2 — deploy-edge-release:
- runs-on: ubuntu-latest
- PHP 8.3 via shivammathur/setup-php@v2
- composer install --no-dev --optimize-autoloader --prefer-dist --no-interaction
- Node 20 via actions/setup-node@v4
- npm ci then npm run build with these env vars:
    VITE_REVERB_APP_KEY: ${{ secrets.VITE_REVERB_APP_KEY }}
    VITE_REVERB_HOST: ${{ secrets.VITE_REVERB_HOST }}
    VITE_REVERB_PORT: ${{ secrets.VITE_REVERB_PORT }}
    VITE_REVERB_SCHEME: https
    VITE_REVERB_VIA_PROXY: "true"
- Build tarball:
    tar -czf flexiqueue-${{ github.ref_name }}-edge.tar.gz \
      --exclude='.git' --exclude='node_modules' --exclude='.env' \
      --exclude='storage' --exclude='tests' --exclude='e2e' \
      --exclude='.github' --exclude='php-run-scripts' \
      .
- Publish release using softprops/action-gh-release@v2 with the tarball as the file

Do not create any other files. Only deploy.yml.

When done, reply with:
## Handoff
**Subagent:** A-2
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** yes — David must add these GitHub Secrets if not already done: FTP_HOST, FTP_USER, FTP_PASSWORD, VITE_PUSHER_APP_KEY, VITE_PUSHER_APP_CLUSTER, VITE_REVERB_APP_KEY, VITE_REVERB_HOST, VITE_REVERB_PORT. Then push a tag to test.
**Ready to unblock:** A-4 (after David sets Hestia cron), B-1 (after first successful deploy)
**Notes:** [anything unexpected found]
```

---

**Subagent A-3**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is A-3: Extend run_initial_setup() in php-run-scripts/helpers.php to seed the SuperAdminSeeder after migrations.

Find the run_initial_setup() function in php-run-scripts/helpers.php. After the migrate --force step and before config:cache, add a call to:
  php artisan db:seed --class=SuperAdminSeeder --force

Add this comment above that line:
  // Creates superadmin if not exists. Reads SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD from .env.
  // Safe to re-run (updateOrCreate). Set these in .env on the server before first deploy.

Do not change anything else in helpers.php or any other file.

When done, reply with:
## Handoff
**Subagent:** A-3
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** yes — before running initial-setup.php for the first time, David must set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env on the Hestia server, then run: php /home/avelinht/web/flexiqueue.click/public_html/php-run-scripts/initial-setup.php via the Hestia Run PHP panel.
**Ready to unblock:** A-6 (Central Maintenance UI)
**Notes:** [anything unexpected found]
```

---

**Subagent A-5**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is A-5: Script cleanup PR.

Delete these files entirely:
- scripts/deploy-via-prod-to-pi.sh
- scripts/deploy-via-prod.sh
- scripts/pi/zerotier-when-idle.sh

Create the directory scripts/legacy/ and move this file into it:
- scripts/deploy-to-pi-edge.sh → scripts/legacy/deploy-to-pi-edge.sh

Create scripts/legacy/README.md with this content:
  # Legacy Scripts
  These scripts are kept temporarily and will be removed once edge onboarding ships.
  Do not use these for new deployments.

  - deploy-to-pi-edge.sh: Replaced by the in-app edge wizard + golden image workflow.

Do not modify any other files.

When done, reply with:
## Handoff
**Subagent:** A-5
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** nothing blocked by this — cleanup only
**Notes:** [anything unexpected found]
```

---

**Subagent B-2**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is B-2: Create the edge_device_state migration and model.

Create a new migration: database/migrations/YYYY_MM_DD_000001_create_edge_device_state_table.php
Use today's date for YYYY_MM_DD.

The table is named edge_device_state with these columns:
- id (primary, always 1 — single row table)
- paired_at (timestamp, nullable)
- central_url (string 500, nullable)
- site_id (unsignedBigInteger, nullable)
- site_name (string 255, nullable)
- device_token (text, nullable)
- sync_mode (enum: bridge, sync — default sync)
- supervisor_admin_access (boolean, default false)
- active_program_id (unsignedBigInteger, nullable)
- active_program_name (string 255, nullable)
- session_active (boolean, default false)
- last_synced_at (timestamp, nullable)
- timestamps()

The migration up() must begin with: if (Schema::hasTable('edge_device_state')) { return; }

Create app/Models/EdgeDeviceState.php:
- $table = 'edge_device_state'
- All columns fillable
- Casts: paired_at and last_synced_at as datetime; supervisor_admin_access and session_active as boolean; device_token as encrypted
- Static helper: EdgeDeviceState::current() returns the row with id=1, or creates it with all defaults if missing

Do not run the migration or seed any data.

When done, reply with:
## Handoff
**Subagent:** B-2
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** B-3 can now be spawned (needs the model), B-5 and B-6 can be spawned after B-3 merges
**Notes:** [anything unexpected found]
```

---

**Subagent B-3**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is B-3: Create the edge boot middleware.

Create app/Http/Middleware/EdgeBootGuard.php with this logic:
1. If not edge mode (use EdgeModeService::isEdge()) — pass through immediately
2. Load EdgeDeviceState::current()
3. If paired_at is null → redirect to /edge/setup (unless request is already on /edge/setup)
4. If paired_at is set but active_program_id is null → redirect to /edge/waiting (unless already on /edge/waiting or /edge/setup)
5. Otherwise → pass through

Register this middleware in bootstrap/app.php so it runs on every web request.

Create two placeholder routes (in routes/web.php or a new routes/edge.php if that pattern exists in the codebase):
- GET /edge/setup → Inertia render of Edge/Setup with a TODO comment
- GET /edge/waiting → Inertia render of Edge/Waiting with a TODO comment

Create two minimal Svelte stub pages:
- resources/js/Pages/Edge/Setup.svelte — just an h1 "Edge Setup — Coming Soon"
- resources/js/Pages/Edge/Waiting.svelte — just an h1 "Edge Waiting — Coming Soon"

Both stubs must use the same layout pattern as other pages in the codebase. Do not build the real UI — that is B-5 and B-6.

When done, reply with:
## Handoff
**Subagent:** B-3
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** B-5 and B-6 can now be spawned
**Notes:** [anything unexpected found]
```

---

**Subagent B-5**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is B-5: Build the edge setup wizard UI and EdgeDeviceSetupService.

Create app/Services/EdgeDeviceSetupService.php with method complete(array $data):
- Accepts: central_url, device_token, site_id, site_name, sync_mode (bridge|sync)
- Updates EdgeDeviceState::current() with these values and sets paired_at = now()
- Writes CENTRAL_URL and EDGE_BRIDGE_MODE to .env programmatically (file_get_contents / str_replace, no package)
- Runs Artisan::call('config:clear') then Artisan::call('config:cache')

Create app/Http/Controllers/Edge/SetupController.php:
- show(): returns Inertia page Edge/Setup
- store(Request $request): validates input, calls a stubbed pair() returning fake data (device_token: "stub-token", site_id: 1, site_name: "Test Site") — real POST /edge/pair comes in B-4. Calls EdgeDeviceSetupService::complete(). Redirects to /edge/waiting.

Replace the stub at resources/js/Pages/Edge/Setup.svelte with a real 4-step wizard (Svelte 5 syntax):
- Step 1: Central URL input (default https://flexiqueue.click) + "Check connection" button (stubbed — just shows a green checkmark for now)
- Step 2: Pairing code input (format hint: e.g. ABCD-1234)
- Step 3: Mode selection — two cards: "Sync on the go" (continuous sync, needs internet) and "Sync after session" (offline-capable, manual sync after session ends)
- Step 4: Confirm summary + "Set up device" button

Use the existing UI component patterns in the codebase. Design for a Pi touchscreen — large tap targets, minimal clutter.

When done, reply with:
## Handoff
**Subagent:** B-5
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** A-5 (cleanup safe now), B-4 (real pairing endpoint — needs A-2 green first)
**Notes:** [anything unexpected found]
```

---

**Subagent B-6**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is B-6: Build the waiting splash page.

Create app/Http/Controllers/Edge/WaitingController.php:
- show(): returns Inertia page Edge/Waiting with EdgeDeviceState::current() data (site_name, sync_mode)
- poll(): GET /edge/waiting/poll — if active_program_id is not null returns { assigned: true, program_name: "..." }, else returns { assigned: false }

Replace the stub at resources/js/Pages/Edge/Waiting.svelte with a real page (Svelte 5):
- Shows: "Paired to [site_name]. Awaiting program assignment from your administrator."
- Small badge showing sync mode (Sync on the go / Sync after session)
- Subtle animated waiting indicator
- Polls /edge/waiting/poll every 5 seconds
- On { assigned: true } → window.location = '/' (redirect to normal edge UI)

Add the poll route: GET /edge/waiting/poll

When done, reply with:
## Handoff
**Subagent:** B-6
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** B-7 (central assignment page — needs A-2 green first)
**Notes:** [anything unexpected found]
```

---

**Subagent B-13**
```
You are working on the FlexiQueue repository (github: flexiqueue).

Your task is B-13: Write the golden image build script.

Create scripts/pi/build-golden-image.sh

This script runs on a developer Linux machine to produce a flashable .img.gz for Orange Pi One (Armbian/Debian base).

Arguments:
  $1 — path to base Armbian .img file
  $2 — path to edge tarball (flexiqueue-vX.Y.Z-edge.tar.gz)
  $3 — version string (e.g. v1.0.0, defaults to "dev")

Add a --help flag that prints usage and exits.

The script must:
1. Print clear section headers for each step
2. Check prerequisites: qemu-img, kpartx or losetup, chroot — print helpful error if missing
3. Mount the base image via loop device
4. chroot into it and:
   - apt install: php8.3 php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl nginx sqlite3 curl unzip
   - mkdir -p /var/www/flexiqueue
5. Extract the edge tarball into /var/www/flexiqueue
6. Inside chroot at /var/www/flexiqueue:
   - php artisan key:generate --force
   - Set APP_MODE=edge and DB_CONNECTION=sqlite in .env (sed or echo)
   - php artisan migrate --force
   - cp scripts/pi/flexiqueue-reverb.service /etc/systemd/system/
   - cp scripts/pi/flexiqueue-queue.service /etc/systemd/system/
   - systemctl enable flexiqueue-reverb flexiqueue-queue
   - cp scripts/pi/nginx-flexiqueue.conf /etc/nginx/sites-available/flexiqueue
   - ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/flexiqueue
7. Do NOT set CENTRAL_URL, CENTRAL_API_KEY, or EDGE_BRIDGE_MODE
8. Unmount cleanly
9. Compress: output flexiqueue-golden-$3.img.gz
10. Print flash instructions: balena etcher or dd if=flexiqueue-golden-$3.img.gz | gunzip | dd of=/dev/sdX

Add set -e at the top and a trap for cleanup on error.

When done, reply with:
## Handoff
**Subagent:** B-13
**Status:** Done / Blocked
**Files changed:** [list]
**Needs human action:** no
**Ready to unblock:** nothing blocked — standalone script
**Notes:** [anything unexpected found]
```

---

### Spawn order

- **Right now, simultaneously:** A-1, A-2, A-3 (CI track); B-2, B-3, B-13 (Edge track, independent).
- **After B-2 + B-3 handoffs confirm Done:** B-5, B-6.
- **After A-2 handoff confirms Done + Hestia cron set (A-4):** B-1, then B-4, B-7, B-8, B-9, B-10, B-11, B-12 in order.
- **After B-5 handoff confirms Done:** A-5.

---

## Known Gaps (All Documented)

| Gap | Severity | Resolution |
|---|---|---|
| Ghost migration deletion in 3 deploy scripts | Blocker for CI — **but trivial fix** (just remove the lines; file no longer exists with that name) | Phase A-1 |
| `update-from-url.sh` does not preserve `APP_KEY` | Medium | Phase A-1 |
| `EdgeModeService::isOnline()` stub | Blocks Sync on the go | Phase B-1 |
| WebSocket state resync on reconnect | Low for CI/CD | Tracked separately |
| `lib/git-worktree.sh` tied to `prod` branch | Low | Verify callers after A-5, archive if unused |