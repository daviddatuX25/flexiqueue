# Orange Pi setup helpers

**New? Start with [Beginner deployment guide](../../docs/BEGINNER-DEPLOYMENT-GUIDE.md).**

**Full deployment runbook** (first-time install, SQLite for prod to save RAM, Nginx, verify): see [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md). Use SQLite on Orange Pi One to avoid running MariaDB and save memory.

The deploy tarball **includes this folder** (`scripts/pi/`): Reverb systemd unit, zerotier-when-idle, nginx config, update-from-url, migrate-with-repair. After a full deploy they live at `/var/www/flexiqueue/scripts/pi/` on the Pi.

Overview of all scripts (dev + Pi): [scripts/README.md](../README.md).

---

## Files in this folder

| File | Purpose |
|------|--------|
| `full-setup-pi.sh` | Run **once** on the Pi: install PHP, Nginx, SQLite, app dir, nginx site, Reverb service. Optional `--hostname=...`. Then deploy from PC. |
| `apply-tarball.sh` | Run on the Pi after tarball is at e.g. `/tmp/flexiqueue-deploy.tar.gz`: extract, cache, migrate (incremental, fresh, or skip), restart Reverb. Used by deploy-to-pi.sh via SSH; can be run manually. |
| `migrate-with-repair.sh` | Run `php artisan migrate --force` on the Pi (idempotent migrations). |
| `update-from-url.sh` | On Pi: download tarball from URL and apply. |
| `flexiqueue-reverb.service` | systemd unit for Laravel Reverb (WebSocket). |
| `zerotier-when-idle.sh` | Cron helper to start/stop ZeroTier when idle. |
| `nginx-flexiqueue.conf` | Nginx site config for FlexiQueue. |

---

## Full setup (first-time on Pi)

Prepare the Pi system once (PHP, Nginx, SQLite, app dir, nginx site, Reverb). Then deploy the app from your PC.

1. Copy this folder to the Pi (or extract the deploy tarball into `/var/www/flexiqueue`).
2. On the Pi: `sudo ./scripts/pi/full-setup-pi.sh` (optionally `--hostname=orangepione` for mDNS).
3. From your PC: `PI_HOST=orangepione.local ./scripts/deploy-to-pi.sh --build` (or use the Pi IP).

---

## Apply tarball (update / rebuild)

After the tarball is on the Pi (e.g. scp by deploy-to-pi.sh to `/tmp/flexiqueue-deploy.tar.gz`), apply it with:

```bash
sudo ./scripts/pi/apply-tarball.sh /tmp/flexiqueue-deploy.tar.gz [--migrate=incremental|fresh|skip]
```

- **incremental** (default): `migrate --force` — keep existing data.
- **fresh**: `migrate:fresh --seed --force` — drop all tables and reseed.
- **skip**: do not run migrations.

`deploy-to-pi.sh` runs this script via SSH with the chosen migrate option (interactive prompt or `DEPLOY_MIGRATE` / `--migrate`).

---

## Common tasks on Pi

Run on the Pi (as root or with sudo as shown):

```bash
cd /var/www/flexiqueue

# Apply migrations (SQLite)
sudo -u www-data php artisan migrate --force

# Refresh caches
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# Restart Reverb (WebSocket) if installed
sudo systemctl restart flexiqueue-reverb
```

### WebSocket (Reverb) networking model on the Pi

- **Reverb listens on**: `127.0.0.1:6001` (or `0.0.0.0:6001` as the service bind), managed by `flexiqueue-reverb.service`.
- **Browsers should connect to**: the **Nginx site** on port **80/443**, path `/app` (example: `ws://orangepione.local/app/...`).
- **Nginx proxies**: `/app` → `http://127.0.0.1:6001`.

If a browser connects directly to `ws://orangepione.local:6001/app/...` and port `6001` is blocked by firewall / network policy, you’ll see errors like “WebSocket is closed before the connection is established”. Using the Nginx proxy avoids this.

Full deployment and first-time setup: [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md).

---

## Copy scripts to Pi without full deploy

Use this when you only want to update or add the Pi helper scripts (Reverb service, zerotier-when-idle, nginx config) without running a full tarball deploy.

**From your PC** (repo root). Replace `<pi-ip>` with the Pi’s IP (local or ZeroTier):

```bash
# Create scripts dir on Pi if needed, then copy scripts/pi into it
ssh root@<pi-ip> "mkdir -p /var/www/flexiqueue/scripts"
scp -r scripts/pi root@<pi-ip>:/var/www/flexiqueue/scripts/
```

**Then on the Pi**, install from the copied files:

```bash
# Reverb: start at boot and restart on failure
sudo cp /var/www/flexiqueue/scripts/pi/flexiqueue-reverb.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now flexiqueue-reverb

# ZeroTier when idle (optional): copy to /usr/local/bin and add cron
sudo cp /var/www/flexiqueue/scripts/pi/zerotier-when-idle.sh /usr/local/bin/zerotier-when-idle
sudo chmod +x /usr/local/bin/zerotier-when-idle
# Then: sudo crontab -e and add: */5 * * * * /usr/local/bin/zerotier-when-idle

# Nginx config (only if not already set up): copy to sites-available and enable
# sudo cp /var/www/flexiqueue/scripts/pi/nginx-flexiqueue.conf /etc/nginx/sites-available/flexiqueue
# sudo ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
# sudo nginx -t && sudo systemctl reload nginx
```

---

## Migrate with schema repair

**File:** `migrate-with-repair.sh` — runs `php artisan migrate --force`. Migrations are idempotent (create print_settings only if missing; add tokens.pronounce_as only if missing; ensure migration fixes orphan state). No runtime repair logic. Called automatically by deploy-to-pi.sh and update-from-url.sh.

---

## Remote update from URL

**File:** `update-from-url.sh` — run on the Pi to update the app by downloading the tarball from a URL (e.g. GitHub Releases, your file server). Use when you can't scp from your PC.

**Setup once on the Pi:**

```bash
# Copy script to Pi (from your laptop, from repo root)
scp scripts/pi/update-from-url.sh root@<pi-ip>:/tmp/

# On the Pi: install and make executable
sudo mv /tmp/update-from-url.sh /usr/local/bin/flexiqueue-update
sudo chmod +x /usr/local/bin/flexiqueue-update
```

**To update (on the Pi):** Put the tarball at a URL, then:

```bash
sudo flexiqueue-update "https://your-server.com/flexiqueue-deploy.tar.gz"
```

For full deployment runbook (including SQLite option and start-fresh steps), see [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md).

---

## ZeroTier only when idle

**File:** `zerotier-when-idle.sh` — run from cron on the Pi to start ZeroTier only when no program/queue session is active (saves ~20–40 MB RAM during events). Copy to `/usr/local/bin/zerotier-when-idle`, make executable, add cron e.g. `*/5 * * * * /usr/local/bin/zerotier-when-idle`. Set `RECLAIM_MEMORY_ON_IDLE=1` to reload PHP-FPM and restart Reverb when transitioning to idle.

---

## Nginx site config

**File:** `nginx-flexiqueue.conf` — use this on the Pi when you have PHP 8.3 (e.g. Armbian Noble). For PHP 8.2, edit the file and change `php8.3-fpm.sock` to `php8.2-fpm.sock`.

### Option A: Copy from your laptop (from repo root)

```bash
# Replace <pi-ip> with your Pi's IP or use armbian.local
scp scripts/pi/nginx-flexiqueue.conf root@<pi-ip>:/tmp/flexiqueue

ssh root@<pi-ip>
sudo mv /tmp/flexiqueue /etc/nginx/sites-available/flexiqueue
sudo ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### Option B: On the Pi (if you don't have the repo on your laptop)

Create the file on the Pi:

```bash
sudo nano /etc/nginx/sites-available/flexiqueue
```

Paste the contents of `nginx-flexiqueue.conf` (from this folder), save, then:

```bash
sudo ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### Camera (QR scanner) on mobile

The device camera requires a **secure context** (HTTPS or localhost). If you access the app over HTTP (e.g. `http://192.168.1.x` or `http://armbian.local`), the browser will deny camera access on mobile. For mobile QR scanning, either:

- Use **HTTPS** (e.g. Let's Encrypt) when accessing from phones
- Or use a hostname that resolves to localhost (e.g. `localhost` on the same device)
