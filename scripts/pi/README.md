# Orange Pi setup helpers

**New? Start with [Beginner deployment guide](../../docs/BEGINNER-DEPLOYMENT-GUIDE.md).**

**Full deployment runbook** (first-time install, SQLite for prod to save RAM, Nginx, verify): see [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md). Use SQLite on Orange Pi One to avoid running MariaDB and save memory.

The deploy tarball **includes this folder** (`scripts/pi/`): Reverb and queue worker systemd units, zerotier-when-idle, nginx config, update-from-url, migrate-with-repair. After a full deploy they live at `/var/www/flexiqueue/scripts/pi/` on the Pi.

Overview of all scripts (dev + Pi): [scripts/README.md](../README.md).

---

## Files in this folder

| File | Purpose |
|------|--------|
| `full-setup-pi.sh` | Run **once** on the Pi: install PHP, Nginx, SQLite, app dir, nginx site, Reverb + queue worker services. Optional `--hostname=...`. Then deploy from PC. |
| `apply-tarball.sh` | Run on the Pi after tarball is at e.g. `/tmp/flexiqueue-deploy.tar.gz`: extract, cache, migrate (incremental, fresh, or skip), install Reverb + queue worker systemd units if missing, then restart both. Used by deploy-to-pi.sh via SSH; can be run manually. |
| `migrate-with-repair.sh` | Run `php artisan migrate --force` on the Pi (idempotent migrations). |
| `update-from-url.sh` | On Pi: download tarball from URL and apply. |
| `install-flexiqueue-services.sh` | Install or update **only** Reverb + queue worker systemd units (no PHP/Nginx). Use when you already have the stack and just want FlexiQueue services. Run on Pi: `sudo ./scripts/pi/install-flexiqueue-services.sh`. |
| `flexiqueue-reverb.service` | systemd unit for Laravel Reverb (WebSocket). |
| `flexiqueue-queue.service` | systemd unit for Laravel queue worker (TTS generation and other queued jobs). |
| `zerotier-when-idle.sh` | Cron helper to start/stop ZeroTier when idle. |
| `nginx-flexiqueue.conf` | Nginx site config for FlexiQueue (HTTP). |
| `nginx-flexiqueue-ssl.conf` | Nginx site config with HTTPS (for camera on mobile). |
| `setup-ssl.sh` | Enable HTTPS on an **existing** Pi (self-signed cert, Nginx, APP_URL). Run on the Pi. |

---

## Local hostname
On your PC or any device accessing the Pi, add to /etc/hosts:
  192.168.x.x  flexiqueue.edge
Replace 192.168.x.x with the Pi's actual LAN IP.
If using dnsmasq or Pi-hole, add an A record for flexiqueue.edge instead.

---

## Full setup (first-time on Pi)

Prepare the Pi system once (PHP, Nginx, SQLite, app dir, nginx site, Reverb and queue worker). Then deploy the app from your PC.

1. Copy this folder to the Pi (or extract the deploy tarball into `/var/www/flexiqueue`).
2. On the Pi: `sudo ./scripts/pi/full-setup-pi.sh` (optionally `--hostname=flexiqueue.edge` for mDNS).
3. From your PC: `PI_HOST=flexiqueue.edge ./scripts/deploy-to-pi.sh --build` (or use the Pi IP).

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

# Restart queue worker (TTS generation, etc.) if installed
sudo systemctl restart flexiqueue-queue
```

### WebSocket (Reverb) networking model on the Pi

- **Reverb listens on**: `127.0.0.1:6001` (or `0.0.0.0:6001` as the service bind), managed by `flexiqueue-reverb.service`.
- **Browsers should connect to**: the **Nginx site** on port **80/443**, path `/app` (example: `ws://flexiqueue.edge/app/...`).
- **Nginx proxies**: `/app` → `http://127.0.0.1:6001`.

If a browser connects directly to `ws://flexiqueue.edge:6001/app/...` and port `6001` is blocked by firewall / network policy, you’ll see errors like “WebSocket is closed before the connection is established”. Using the Nginx proxy avoids this.

Full deployment and first-time setup: [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md).

### Troubleshooting: "Pusher error... Failed to connect to localhost port 6001" or wrong app id/key

If the backend uses old broadcast credentials (e.g. app id `747972` or key `fwa0z3...`) or Reverb isn’t running:

1. **Set Reverb vars in `.env`** (must match `.env.edge` and the frontend build):
   ```bash
   cd /var/www/flexiqueue
   sudo sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=reverb/' .env
   sudo sed -i 's/^REVERB_APP_ID=.*/REVERB_APP_ID=flexiqueue/' .env
   sudo sed -i 's/^REVERB_APP_KEY=.*/REVERB_APP_KEY=flexiqueue-prod-key/' .env
   sudo sed -i 's/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=flexiqueue-prod-secret/' .env
   ```
2. **Refresh config and restart Reverb:**
   ```bash
   sudo -u www-data php artisan config:clear
   sudo -u www-data php artisan config:cache
   sudo systemctl restart flexiqueue-reverb
   ```
3. **Confirm Reverb is running:** `sudo systemctl status flexiqueue-reverb` (should be active). If not: `sudo systemctl start flexiqueue-reverb`.

---

## Copy scripts to Pi without full deploy

Use this when you only want to update or add the Pi helper scripts (Reverb and queue worker services, zerotier-when-idle, nginx config) without running a full tarball deploy.

**From your PC** (repo root). Replace `<pi-ip>` with the Pi’s hostname or IP (e.g. `flexiqueue.edge` or `192.168.1.50`), and `root` with your Pi user if different:

```bash
# 1. Create scripts dir on Pi if needed, then copy scripts/pi into it
ssh root@<pi-ip> "mkdir -p /var/www/flexiqueue/scripts"
scp -r scripts/pi root@<pi-ip>:/var/www/flexiqueue/scripts/
```

**Then on the Pi**, either run the one-liner to install only Reverb + queue worker:

```bash
# 2a. Only FlexiQueue services (no nginx/PHP) — use when stack is already installed
cd /var/www/flexiqueue
sudo ./scripts/pi/install-flexiqueue-services.sh
```

Or install manually (same effect as `install-flexiqueue-services.sh`):

```bash
# 2b. Manual copy of service units
# Reverb: start at boot and restart on failure
sudo cp /var/www/flexiqueue/scripts/pi/flexiqueue-reverb.service /etc/systemd/system/
# Queue worker: required for TTS generation (token/station pre-generate)
sudo cp /var/www/flexiqueue/scripts/pi/flexiqueue-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now flexiqueue-reverb
sudo systemctl enable --now flexiqueue-queue

# Optional: allow TTS without queue worker (sync fallback). In .env set:
# TTS_ALLOW_SYNC_WHEN_QUEUE_UNAVAILABLE=1
# TTS_MAX_SYNC_TOKENS=20
# Large batches may cause slow HTTP requests; prefer running the queue worker.

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

The device camera requires a **secure context** (HTTPS or localhost). If you access the app over HTTP (e.g. `http://192.168.1.x` or `http://armbian.local`), the browser will deny camera access on mobile. The app will show a message: "Camera requires a secure connection (HTTPS). Open this page via https://… or use 'Scan from file'."

To make the camera work on phones:

- Use **HTTPS** when accessing from phones (see [HTTPS on the Pi](#https-on-the-pi-self-signed) below), or
- Use a hostname that resolves to localhost (e.g. `localhost` on the same device).

## HTTPS and camera access

HTTPS is configured automatically in the golden image.
Every flashed Pi boots with HTTPS already enabled for https://flexiqueue.edge

On client devices (phones, tablets, PCs):
- Open https://flexiqueue.edge in the browser
- Accept the self-signed certificate warning once
- Camera (QR scanning) will then work normally

No SSH, no terminal, no manual steps on the Pi needed.

---

### HTTPS on the Pi (self-signed)

To enable camera access on mobile, serve the app over HTTPS. A **self-signed certificate** is enough for LAN use; each device will show a one-time "unsafe" warning that the user can accept.

**Option A: Run the script (existing Pi)**

On a Pi that already has FlexiQueue and Nginx installed, run from the app root:

```bash
cd /var/www/flexiqueue
sudo ./scripts/pi/setup-ssl.sh --hostname=flexiqueue.edge
```

Replace `flexiqueue.edge` with the hostname your phones use to reach the Pi (e.g. `flexiqueue.edge` for mDNS, or your Pi's IP if you use that). The script will:

- Create `/etc/nginx/ssl/` and generate a self-signed cert (CN + SAN for the hostname)
- Install `nginx-flexiqueue-ssl.conf` as the Nginx site (HTTP redirects to HTTPS)
- Set `APP_URL=https://<hostname>` in `.env` and run `config:cache`
- Reload Nginx and restart Reverb if present

Then open the app on your phone at `https://flexiqueue.edge` (or your hostname), accept the certificate warning once, and the camera should work.

**Option B: Manual steps**

**1. Generate a self-signed certificate on the Pi**

```bash
sudo mkdir -p /etc/nginx/ssl
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /etc/nginx/ssl/flexiqueue.key \
  -out /etc/nginx/ssl/flexiqueue.crt \
  -subj "/CN=flexiqueue.edge" -addext "subjectAltName=DNS:flexiqueue.edge,IP:127.0.0.1"
```

Replace `flexiqueue.edge` with your Pi hostname or add more `DNS:`/`IP:` entries if you use other hostnames or IPs.

**2. Use the HTTPS Nginx config**

Copy `nginx-flexiqueue-ssl.conf` to the Pi and enable it the same way as the HTTP config (Option A or B under "Nginx site config" above), but use `nginx-flexiqueue-ssl.conf` instead of `nginx-flexiqueue.conf`. Ensure the SSL cert paths in the config match where you created the certs in step 1. Then:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

**3. Set Laravel to use HTTPS**

On the Pi, set `APP_URL` to `https://` so links and redirects use HTTPS:

```bash
cd /var/www/flexiqueue
sudo sed -i 's|^APP_URL=.*|APP_URL=https://flexiqueue.edge|' .env
sudo -u www-data php artisan config:cache
```

Replace `flexiqueue.edge` with your hostname. Restart Reverb if you use it: `sudo systemctl restart flexiqueue-reverb`.

**4. Open the app on your phone via HTTPS**

Use `https://flexiqueue.edge` (or your hostname). Accept the browser’s certificate warning once; then the camera should work.

**Let’s Encrypt (optional)**  
For a trusted cert with no warning, use Let’s Encrypt. You need a public hostname and port 80 (and optionally 443) reachable from the internet for validation. See your distro’s certbot docs; then use the same Nginx SSL pattern with the cert paths certbot provides.
