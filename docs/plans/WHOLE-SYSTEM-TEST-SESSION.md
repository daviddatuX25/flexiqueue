# Whole-System Test Session Plan

**Purpose:** Run a single session that tests the entire FlexiQueue system end-to-end: backend (PHPUnit), frontend (Playwright E2E), and optional manual verification. Use this when you need confidence before a release, pilot, or handoff.

**Reference:** `QUALITY-GATES.md` (coverage targets, critical paths), `SETUP-BD-001.md` (environment setup).

---

## 1. Prerequisites (5–10 min)

### Environment

| Check | Command | Expected |
|-------|---------|----------|
| Sail up | `./vendor/bin/sail ps` | Containers running |
| DB ready | `./vendor/bin/sail artisan migrate:status` | Migrations up |
| Testing DB exists | `./vendor/bin/sail artisan migrate --database=testing` | No errors |
| Assets built | `ls public/build/manifest.json` | File exists |
| Playwright browsers | `./vendor/bin/sail npx playwright install` | Chromium installed |

### If Sail isn’t running

```bash
./vendor/bin/sail up -d
# Wait for MariaDB to be ready (≈30 s)
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm run build
```

### If using bare metal (no Sail)

- PHP 8.2+, Composer, Node 20+, MariaDB 10.6+
- `.env` and `.env.testing` (or `DB_DATABASE=testing`) configured
- Run `php artisan migrate` and `php artisan migrate --database=testing`
- Run `npm run build`

---

## 2. Test Execution Order

Run in this order; later steps assume earlier ones pass.

| Step | Layer | Command | Est. time |
|------|-------|---------|-----------|
| 1 | PHPUnit (Unit) | `./vendor/bin/sail artisan test tests/Unit` | 1–2 min |
| 2 | PHPUnit (Feature) | `./vendor/bin/sail artisan test tests/Feature` | 2–5 min |
| 3 | Full PHPUnit | `./vendor/bin/sail artisan test` | 3–7 min |
| 4 | Playwright E2E | `./vendor/bin/sail npx playwright test` | 2–5 min |
| 5 | Optional manual | Browser + seeded data | 10–15 min |

---

## 3. One-Command Full Run

For a single-session full system test, run:

```bash
# From project root, with Sail up

echo "=== 1. PHPUnit (Unit + Feature) ==="
./vendor/bin/sail artisan test

echo "=== 2. Playwright E2E (app must be serving) ==="
./vendor/bin/sail npx playwright test
```

**E2E requires the app to be serving.** If you use `sail up`, the web server is already running. For dev server (`npm run dev`), keep it running in another terminal, or rely on built assets (`npm run build`).

---

## 4. Scripted Full Session (Optional)

Create `scripts/full-system-test.sh`:

```bash
#!/usr/bin/env bash
set -e

cd "$(dirname "$0")/.."

echo "=== FlexiQueue full system test ==="
echo ""

echo "1. PHPUnit (backend)"
./vendor/bin/sail artisan test
echo ""

echo "2. Playwright E2E"
./vendor/bin/sail npx playwright test
echo ""

echo "Done. All tests passed."
```

Make it executable: `chmod +x scripts/full-system-test.sh`. Run:

```bash
./scripts/full-system-test.sh
```

---

## 5. What Each Layer Covers

| Layer | Scope | Key Areas |
|-------|--------|-----------|
| **Unit** | Services, models, support | FlowEngine, StationQueueService, PinService, StationSelectionService |
| **Feature** | HTTP + DB + middleware | Auth, bind/transfer/complete/cancel, admin CRUD, station queue, reports |
| **Playwright E2E** | Browser flows | Login, redirect by role, station UI, admin program steps, mobile layout |

---

## 6. Troubleshooting

### PHPUnit failures

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `SQLSTATE[42000]` / DB errors | Testing DB missing or schema mismatch | `./vendor/bin/sail artisan migrate:fresh --database=testing` |
| Factory or seeder errors | Missing or changed factories | Check `database/factories/` and `database/seeders/` |
| 403/401 in feature tests | Wrong user/role or middleware | Inspect `Auth::actingAs()` and role setup in the test |
| Reverb/broadcast errors | E2E sets `BROADCAST_CONNECTION=null` | Ensure `phpunit.xml` has `BROADCAST_CONNECTION=null` |

### Playwright failures

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `net::ERR_CONNECTION_REFUSED` | App not serving | Ensure Sail is up, or run `php artisan serve` + `npm run dev` |
| `require is not defined` | ESM/CommonJS mismatch | Use ESM in `playwright.config.js` (see developing-gotchas) |
| `headed` fails in Sail | No display in Docker | Run E2E from host: `npx playwright test --headed` |
| Flaky login/navigation | DB state or race | E2E uses `fullyParallel: false`; `global-setup` runs `migrate:fresh --seed` |

### Viewing E2E report

```bash
./vendor/bin/sail npx playwright show-report --host 0.0.0.0
# Open http://localhost:9323
```

---

## 7. Session Checklist

Use this as a pre-release or handoff checklist:

- [ ] Sail up and migrations run
- [ ] `./vendor/bin/sail artisan test` — all pass
- [ ] `./vendor/bin/sail npx playwright test` — all pass
- [ ] `./vendor/bin/sail artisan db:seed` — no errors
- [ ] (Optional) Manual smoke test: login as staff/admin, bind → transfer → complete
- [ ] (Optional) No console errors on core pages (Triage, Station, Display, Admin)

---

## 8. Time Estimates

| Scenario | Time |
|----------|------|
| Minimal (PHPUnit + E2E only) | ~5–12 min |
| Prereqs + full run | ~15–25 min |
| Full run + manual smoke + report review | ~30–45 min |

---

## 9. Quick Reference Commands

```bash
# Prerequisites
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate --database=testing
./vendor/bin/sail npm run build
./vendor/bin/sail npx playwright install

# Full test run
./vendor/bin/sail artisan test
./vendor/bin/sail npx playwright test

# E2E report
./vendor/bin/sail npx playwright show-report --host 0.0.0.0
```
