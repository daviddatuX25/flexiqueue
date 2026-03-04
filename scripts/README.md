# FlexiQueue scripts

Helper scripts for local development, building, and deploying to Orange Pi or Laragon/laptop. **Run all commands from the repo root.**

**Docs:** [Beginner deployment guide](../docs/BEGINNER-DEPLOYMENT-GUIDE.md) · [Deployment runbook](../docs/architecture/10-DEPLOYMENT.md)

---

## Requirements

| Context | What you need |
|--------|----------------|
| Any script | Git, Bash; run from FlexiQueue repo root |
| **Build (host)** | PHP 8.2+, Composer, Node 20+, npm |
| **Build (Sail)** | Docker; `compose.yaml` in repo (Laravel Sail) |
| **Deploy to Pi/Laragon** | SSH access to target; tarball (or use `--build`) |

---

## Script layout

| Location | Purpose |
|----------|---------|
| **scripts/** (root) | Build and deploy entry points: `build-deploy-tarball.sh`, `build-deploy-tarball-sail.sh`, `deploy-to-pi.sh`, `deploy-to-laragon.sh`, `deploy-via-prod-to-pi.sh`, `dev-start-sail.sh`, `dev-stop-sail.sh`, `dev-start-local.sh`, `dev-stop-local.sh` |
| **scripts/dev/** | Development: `setup.sh`, `full-setup-dev.sh`, `quick-check.sh`, `cache-clear.sh`, `common.sh` |
| **scripts/pi/** | Run on the Pi: `apply-tarball.sh`, `full-setup-pi.sh`, `update-from-url.sh`, nginx/Reverb configs |
| **scripts/laragon/** | Run on Laragon/laptop: `apply-tarball.sh` |
| **scripts/lib/** | Shared helpers (do not run directly): `git-worktree.sh` — prod branch and worktree handling for build/deploy scripts |

---

## Quick reference

| Scenario | Type | Command |
|---------|------|---------|
| Dev first-time | Development | `./scripts/dev/setup.sh` then `./scripts/dev-start-sail.sh` or `./scripts/dev-start-local.sh` |
| Dev one-click (setup + start) | Development | `./scripts/dev/full-setup-dev.sh` |
| Build tarball (host) | Build | `./scripts/build-deploy-tarball.sh` |
| Build tarball (Sail) | Build | `./scripts/build-deploy-tarball-sail.sh` |
| Deploy to Pi (interactive) | Deploy Pi | `PI_HOST=... ./scripts/deploy-to-pi.sh --build` |
| Deploy to Pi (one-click) | Deploy Pi | `PI_HOST=... DEPLOY_MIGRATE=1 ./scripts/deploy-to-pi.sh --build` |
| Deploy to Pi from prod (merge + build + deploy) | Deploy Pi | `PI_HOST=... ./scripts/deploy-via-prod-to-pi.sh --build` |
| Deploy to Laragon/laptop (merge + build + deploy) | Deploy Laragon | `LARAGON_HOST=... ./scripts/deploy-to-laragon.sh --build` |
| Pi first-time system setup | On-Pi | On Pi: `sudo ./scripts/pi/full-setup-pi.sh [--hostname=orangepione]` then from PC: `PI_HOST=... ./scripts/deploy-to-pi.sh --build` |
| Pi one-click update (from URL) | On-Pi | On Pi: `sudo flexiqueue-update "https://...tarball.tar.gz"` (after installing script once) |

---

## 1. Development (this computer)

| Script | Purpose |
|--------|--------|
| `scripts/dev/setup.sh` | First-time or reset: composer install, npm install, optional key:generate, storage:link. Uses Sail if available. |
| `scripts/dev/full-setup-dev.sh` | One-click: run setup then start dev stack (Vite + Reverb + queue). Sail or local. |
| `scripts/dev/quick-check.sh` | PHP syntax check + PHPUnit. |
| `scripts/dev/cache-clear.sh` | config:clear, route:clear, view:clear, cache:clear. |

**Daily start/stop:** `dev-start-sail.sh`, `dev-stop-sail.sh`, `dev-start-local.sh`, `dev-stop-local.sh` — start/stop Sail or bare-metal Vite + Reverb + queue.

**WebSockets (Reverb):** Dev uses direct `ws://<host>:6001/app/...`. Production (Pi/Laragon) uses same-origin proxy; nginx proxies `/app` to Reverb. See [scripts/pi/README.md](pi/README.md#websocket-reverb-networking-model-on-the-pi).

---

## 2. Build

| Script | Purpose |
|--------|--------|
| `build-deploy-tarball.sh` | Build production tarball on host from a **temporary prod worktree** (Composer --no-dev, npm build). Output: `flexiqueue-deploy.tar.gz` in repo root. Main repo and dev environment are untouched. |
| `build-deploy-tarball-sail.sh` | Same, using Docker (worktree mounted in container). Use when host has no PHP/Node. |

**Prod worktree and prompts:**

- Builds always use the **prod** branch. If the prod branch does not exist, you are prompted: **"Create prod branch from current? [y/N]"**. Answer **y** to create it and continue.
- If prod is already checked out in another worktree, you are prompted: **"Prod is already checked out elsewhere. Use that worktree for build? [y/N]"**. Answer **y** to reuse it (no second worktree).
- Build-only scripts use a **temporary** worktree (unique path, removed on exit). Deploy scripts use a **fixed** worktree path; if that path exists but is invalid, you are prompted to remove it.

**After a build-only run:** If your current branch is not prod, you will see a message suggesting you switch to prod (`git checkout prod`) and run `deploy-to-pi.sh` or `deploy-to-laragon.sh`, or use `deploy-via-prod-to-pi.sh` / `deploy-to-laragon.sh` from your current branch (they merge into prod and deploy). When the build is run from inside a deploy script, this message is not shown.

---

## 3. Deploy from PC

### Deploy to Pi

| Script | Purpose |
|--------|--------|
| `deploy-to-pi.sh` | Optional build, scp tarball to Pi, run `scripts/pi/apply-tarball.sh` with migrate option (interactive or `DEPLOY_MIGRATE=1|2|3` / `--migrate=incremental|fresh|skip`). **Does not change** your current branch. |
| `deploy-via-prod-to-pi.sh` | **Prod-as-staging:** Merge current branch into prod and push, build from prod worktree, run deploy-to-pi.sh. After a **successful** deploy, the main worktree is switched to **prod**. Option `--no-merge` to skip merge. |

**Examples:** `PI_HOST=orangepione.local ./scripts/deploy-to-pi.sh --build` · `PI_HOST=... DEPLOY_MIGRATE=1 ./scripts/deploy-to-pi.sh --build` · `PI_HOST=... ./scripts/deploy-via-prod-to-pi.sh --build`

### Deploy to Laragon / laptop

| Script | Purpose |
|--------|--------|
| `deploy-to-laragon.sh` | **Prod-as-staging:** Merge current into prod and push, build from prod worktree, scp to LARAGON_HOST, SSH and run `scripts/laragon/apply-tarball.sh`. After a **successful** deploy, the main worktree is switched to **prod**. Option `--no-merge`, `--migrate=...`. |

**Example:** `LARAGON_HOST=laptop.local ./scripts/deploy-to-laragon.sh --build`

**Branch behavior:** Only **deploy-to-laragon.sh** and **deploy-via-prod-to-pi.sh** switch you to the prod branch after deploy. **deploy-to-pi.sh** (when run directly) does not change your branch.

**Deprecated:** `deploy-via-prod.sh` — forwards to deploy-via-prod-to-pi.sh. Use `deploy-via-prod-to-pi.sh` or `deploy-to-laragon.sh` directly.

---

## 4. On-Pi (device setup and update)

After deploy, these live at `/var/www/flexiqueue/scripts/pi/` on the Pi.

| File | Purpose |
|------|--------|
| `full-setup-pi.sh` | Run **once** on the Pi: install PHP, Nginx, SQLite, create app dir and database, nginx site, Reverb systemd unit. Optional `--hostname=...`. Then deploy from PC. |
| `apply-tarball.sh` | Run on the Pi after tarball is present: extract, .env from .env.prod if missing, cache, migrate (incremental|fresh|skip), restart Reverb. Used by deploy-to-pi.sh; can be run manually. |
| `update-from-url.sh` | On Pi: download tarball from URL and apply. Install as `flexiqueue-update` once. |
| `migrate-with-repair.sh` | `php artisan migrate --force`. |
| `flexiqueue-reverb.service` | systemd unit for Reverb. |
| `nginx-flexiqueue.conf` | Nginx site config (proxies `/app` to Reverb). |
| `zerotier-when-idle.sh` | Cron helper to start/stop ZeroTier when idle. |

Details: [scripts/pi/README.md](pi/README.md).

---

## 5. Laptop / Laragon (production)

Same tarball as Pi. Use **deploy-to-laragon.sh** with `LARAGON_HOST=...`. Prepare the laptop once: app directory, SSH; nginx + Reverb if you need WebSockets. After build you see: **"Merged to prod and pushed. Tarball built from prod."**

See [scripts/laragon/README.md](laragon/README.md) for preparing the target and [docs/architecture/10-DEPLOYMENT.md](../docs/architecture/10-DEPLOYMENT.md) for nginx/Reverb.

---

## 6. Individual commands (no script per command)

### Dev machine — Sail (recommended)

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
./vendor/bin/sail npm run dev
./vendor/bin/sail npm run build
./vendor/bin/sail npx playwright test
```

### Dev machine — bare metal

```bash
php artisan test
php artisan migrate
npm run dev
npm run build
npx playwright test
```

### On the Pi

See [scripts/pi/README.md — Common tasks on Pi](pi/README.md#common-tasks-on-pi) (migrate --force, config:cache, restart Reverb).

---

## 7. Windows (WSL / Git Bash)

- **WSL2:** Clone the repo under WSL; run `bash scripts/dev/setup.sh`, `bash scripts/dev/full-setup-dev.sh`, etc.
- **Git Bash:** Ensure PHP, Composer, Node, npm are on PATH; same bash commands from repo root.

No .bat/.ps1 wrappers; all logic is in the bash scripts above.

---

## Troubleshooting

| Issue | What to do |
|-------|------------|
| **"Prod branch is required"** | Create it: `git branch prod` (or answer **y** when prompted "Create prod branch from current?"). |
| **"Not inside a git repository"** | Run the script from the FlexiQueue repo root (the directory that contains `composer.json` and `scripts/`). |
| **"composer not found" / "npm not found"** | Install PHP/Composer and Node/npm on the host, or use the Sail build: `./scripts/build-deploy-tarball-sail.sh` (requires Docker). |
| **"Docker is required for Sail build"** | Start Docker Desktop (or the Docker daemon). If you have PHP/Node on the host, use `./scripts/build-deploy-tarball.sh` instead. |
| **"Prod is already checked out elsewhere"** | Answer **y** to use the existing prod worktree, or run the build from that worktree. Deploy scripts (e.g. `deploy-to-laragon.sh`) use a fixed worktree path; build-only scripts use a temporary one. |
| **SSH / deploy fails** | Ensure `PI_HOST` or `LARAGON_HOST` is set and you can `ssh user@host`. Use SSH keys to avoid repeated password prompts. |
| **Apply-tarball fails on Pi** | Run with `sudo`. Ensure `/var/www/flexiqueue` exists (run `full-setup-pi.sh` once). Check that `scripts/pi/apply-tarball.sh` exists in the tarball. |
