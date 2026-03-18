# FlexiQueue scripts

Helper scripts for local development, building, and deploying to Orange Pi or Laragon/laptop. **Run all commands from the repo root.**

**Docs:** [Beginner deployment guide](../docs/BEGINNER-DEPLOYMENT-GUIDE.md) · [Deployment runbook](../docs/architecture/10-DEPLOYMENT.md)

---

## Manual release (GitHub Actions unavailable)

When GitHub Actions is temporarily unavailable, use these scripts to build and deploy from your machine. They do the same work as `.github/workflows/deploy.yml` (central FTP deploy and edge GitHub Release).

| Script | Purpose |
|--------|---------|
| `scripts/release-central.sh [version]` | Build (Sail/Docker) and deploy to Hestia hosting via FTP. Writes `storage/app/version.txt`, touches `bootstrap/cache/deploy_pending`, then mirrors the repo to the server (excluding `.env`, `storage/`, `node_modules/`, `.git/`). |
| `scripts/release-edge.sh [version]` | Build (Sail/Docker) and publish the edge tarball as a GitHub Release asset. Creates `flexiqueue-<version>-edge.tar.gz`, runs `gh release create` or `gh release upload --clobber`, then removes the local tarball. |

**Version:** Optional. Pass e.g. `v0.1.0` or omit to use the latest git tag (`git describe --tags --abbrev=0`). If no tag exists, the script exits with an error.

**Prerequisites:**

- **Central:** Docker, **lftp** (`sudo apt install lftp`). Credentials in **`.env.hosting`**: `FTP_HOST`, `FTP_USER` (or `FTP_USERNAME`), `FTP_PASSWORD`; and for the frontend build: `VITE_PUSHER_APP_KEY`, `VITE_PUSHER_APP_CLUSTER` (or `PUSHER_APP_KEY`, `PUSHER_APP_CLUSTER`).
- **Edge:** Docker, **gh CLI** ([install](https://cli.github.com)), then `gh auth login`. Reverb vars in **`.env.edge`**: `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT` (or `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`).

**Examples:**

```bash
./scripts/release-central.sh v0.1.0   # Deploy central to hosting
./scripts/release-edge.sh v0.1.0      # Build and publish edge tarball to GitHub Release
```

After a central deploy, ensure the server runs `deploy-update.php` (Hestia cron or Run PHP panel) so migrations and config cache run when `bootstrap/cache/deploy_pending` is present.

### Hestia cron (central)

Set these two cron jobs on the Hestia server:

- **Job 1 — Initial setup (runs once, self-disabling):**

  ```bash
  */2 * * * * [ ! -f /web/flexiqueue.click/public_html/bootstrap/cache/initial_setup_done ] && \
    php /web/flexiqueue.click/public_html/php-run-scripts/initial-setup.php
  ```

- **Job 2 — Deploy marker (runs on every deploy):**

  ```bash
  */2 * * * * [ -f /web/flexiqueue.click/public_html/bootstrap/cache/deploy_pending ] && \
    php /web/flexiqueue.click/public_html/php-run-scripts/deploy-update.php
  ```

`deploy-update.php` deletes the `deploy_pending` marker itself; no `rm` needed.  
`initial-setup.php` writes the `initial_setup_done` flag; the cron becomes a no-op after the first successful run.

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
| **scripts/** (root) | Build and deploy entry points: `build-deploy-tarball.sh`, `build-deploy-tarball-sail.sh`, `build-deploy-hosting.sh`, `deploy-to-pi.sh`, `deploy-to-laragon.sh`, `dev-start-sail.sh`, `dev-stop-sail.sh`, `dev-start-local.sh`, `dev-stop-local.sh` |
| **scripts/dev/** | Development: `setup.sh`, `full-setup-dev.sh`, `quick-check.sh`, `cache-clear.sh`, `common.sh` |
| **scripts/pi/** | Run on the Pi: `apply-tarball.sh`, `full-setup-pi.sh`, `update-from-url.sh`, nginx/Reverb configs |
| **scripts/laragon/** | Run on Laragon/laptop: `apply-tarball.sh` |
| **scripts/lib/** | Shared helpers (do not run directly): `git-worktree.sh` — **deprecated**; kept for scripts/legacy/ only |

---

## Quick reference

| Scenario | Type | Command |
|---------|------|---------|
| Dev first-time | Development | `./scripts/dev/setup.sh` then `./scripts/dev-start-sail.sh` or `./scripts/dev-start-local.sh` |
| Dev one-click (setup + start) | Development | `./scripts/dev/full-setup-dev.sh` |
| Build tarball (host) | Build | `./scripts/build-deploy-tarball.sh` |
| Build tarball (Sail) | Build | `./scripts/build-deploy-tarball-sail.sh` |
| Build hosting tarball | Build | `./scripts/build-deploy-hosting.sh` |
| Deploy to Pi (interactive) | Deploy Pi | `PI_HOST=... ./scripts/deploy-to-pi.sh --build` |
| Deploy to Pi (one-click) | Deploy Pi | `PI_HOST=... DEPLOY_MIGRATE=1 ./scripts/deploy-to-pi.sh --build` |
| Deploy to Laragon/laptop (optional build + deploy) | Deploy Laragon | `LARAGON_HOST=... ./scripts/deploy-to-laragon.sh --build` |
| Pi first-time system setup | On-Pi | On Pi: `sudo ./scripts/pi/full-setup-pi.sh [--hostname=flexiqueue.edge]` then from PC: `PI_HOST=... ./scripts/deploy-to-pi.sh --build` |
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

Build scripts run from the **current branch** (repo root). No prod branch or worktrees are used. Tags on **main** are the only release trigger for the release scripts (`release-central.sh`, `release-edge.sh`).

| Script | Purpose |
|--------|--------|
| `build-deploy-tarball.sh` | Build production tarball on host (Composer --no-dev, npm build). Output: `flexiqueue-deploy.tar.gz` in repo root. |
| `build-deploy-tarball-sail.sh` | Same, using Sail/Docker (repo root bind-mounted in container). Use when host has no PHP/Node. |
| `build-deploy-hosting.sh` | Build for **hosting** (PHP 8.2 max, MySQL, Pusher). Requires `.env.hosting` (copy from `.env.hosting.example`). Output: `flexiqueue-hosting.tar.gz`. Uses `VITE_BROADCASTER=pusher` so Echo connects to Pusher.com. |

**Reverb (WebSocket) keys for Pi/Laragon tarball:** The frontend needs `VITE_REVERB_APP_KEY` at build time. The tarball build scripts load `.env.prod` only (unset dev vars first) and pass Reverb vars into the build.

- **`VITE_REVERB_VIA_PROXY`**: Prod builds use `true` (default) so Echo connects same-origin; nginx proxies `/app` to Reverb. Dev uses `false` in `.env` so Echo connects directly to `localhost:6001`. Set in `.env.prod` to override.

---

## 3. Deploy from PC

### Deploy to Pi

| Script | Purpose |
|--------|--------|
| `deploy-to-pi.sh` | Optional build (from current branch), scp tarball to Pi, run `scripts/pi/apply-tarball.sh` with migrate option (interactive or `DEPLOY_MIGRATE=1|2|3` / `--migrate=incremental|fresh|skip`). Does not change your current branch. |

**Examples:** `PI_HOST=flexiqueue.edge ./scripts/deploy-to-pi.sh --build` · `PI_HOST=... DEPLOY_MIGRATE=1 ./scripts/deploy-to-pi.sh --build`

### Deploy to Laragon / laptop

| Script | Purpose |
|--------|--------|
| `deploy-to-laragon.sh` | Optional build (from current branch), scp to LARAGON_HOST, SSH and run `scripts/laragon/apply-tarball.sh`. Options: `--build`, `--migrate=incremental|fresh|skip`. |

**Example:** `LARAGON_HOST=laptop.local ./scripts/deploy-to-laragon.sh --build`

---

## 4. On-Pi (device setup and update)

After deploy, these live at `/var/www/flexiqueue/scripts/pi/` on the Pi.

| File | Purpose |
|------|--------|
| `full-setup-pi.sh` | Run **once** on the Pi: install PHP, Nginx, SQLite, create app dir and database, nginx site, Reverb systemd unit. Optional `--hostname=...`. Then deploy from PC. |
| `apply-tarball.sh` | Run on the Pi after tarball is present: extract, .env from .env.prod if missing, cache, migrate (incremental|fresh|skip), restart Reverb and queue worker. Used by deploy-to-pi.sh; can be run manually. |
| `update-from-url.sh` | On Pi: download tarball from URL and apply. Install as `flexiqueue-update` once. |
| `migrate-with-repair.sh` | `php artisan migrate --force`. |
| `flexiqueue-reverb.service` | systemd unit for Reverb (WebSocket). |
| `flexiqueue-queue.service` | systemd unit for queue worker (TTS generation and other queued jobs). |
| `nginx-flexiqueue.conf` | Nginx site config (proxies `/app` to Reverb). |
| `zerotier-when-idle.sh` | Cron helper to start/stop ZeroTier when idle. |

Details: [scripts/pi/README.md](pi/README.md).

---

## 5. Laptop / Laragon (production)

Same tarball as Pi. Use **deploy-to-laragon.sh** with `LARAGON_HOST=...`. Prepare the laptop once: app directory, SSH; nginx, Reverb, and queue worker if you need WebSockets and TTS/queued jobs.

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

See [scripts/pi/README.md — Common tasks on Pi](pi/README.md#common-tasks-on-pi) (migrate --force, config:cache, restart Reverb and queue worker). For full functionality (real-time updates and TTS pre-generation), both Reverb and the queue worker must run — use the systemd units installed by full-setup-pi.sh, or manually: `sudo -u www-data php artisan reverb:start` and `sudo -u www-data php artisan queue:work --tries=3` (in separate terminals if not using systemd).

---

## 7. Windows (WSL / Git Bash)

- **WSL2:** Clone the repo under WSL; run `bash scripts/dev/setup.sh`, `bash scripts/dev/full-setup-dev.sh`, etc.
- **Git Bash:** Ensure PHP, Composer, Node, npm are on PATH; same bash commands from repo root.

No .bat/.ps1 wrappers; all logic is in the bash scripts above.

---

## Cleaning up old prod/prod-hosting branches and worktrees

The build/deploy scripts no longer use a **prod** or **prod-hosting** branch or worktrees. After you have verified that the updated scripts work, you can remove the old branches and worktree manually:

```bash
# Delete local prod branch (if it exists)
git branch -d prod

# Delete remote prod branch (if it was pushed)
git push origin --delete prod

# Same for prod-hosting
git branch -d prod-hosting
git push origin --delete prod-hosting

# Remove the prod-hosting worktree (use the path your repo used, e.g. sibling directory)
git worktree remove /home/sarmi/projects/flexiqueue-prod-hosting --force
```

Do not delete branches until you have confirmed the new scripts behave as expected.

---

## Troubleshooting

| Issue | What to do |
|-------|------------|
| **"Not inside a git repository"** | Run the script from the FlexiQueue repo root (the directory that contains `composer.json` and `scripts/`). |
| **"composer not found" / "npm not found"** | Install PHP/Composer and Node/npm on the host, or use the Sail build: `./scripts/build-deploy-tarball-sail.sh` (requires Docker). |
| **"Docker is required for Sail build"** | Start Docker Desktop (or the Docker daemon). If you have PHP/Node on the host, use `./scripts/build-deploy-tarball.sh` instead. |
| **SSH / deploy fails** | Ensure `PI_HOST` or `LARAGON_HOST` is set and you can `ssh user@host`. Use SSH keys to avoid repeated password prompts. |
| **Apply-tarball fails on Pi** | Run with `sudo`. Ensure `/var/www/flexiqueue` exists (run `full-setup-pi.sh` once). Check that `scripts/pi/apply-tarball.sh` exists in the tarball. |
