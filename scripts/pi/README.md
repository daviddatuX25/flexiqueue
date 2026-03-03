# Orange Pi setup helpers

**New? Start with [Beginner deployment guide](../../docs/BEGINNER-DEPLOYMENT-GUIDE.md).**

**Full deployment runbook** (first-time install, SQLite for prod to save RAM, Nginx, verify): see [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md). Use SQLite on Orange Pi One to avoid running MariaDB and save memory.

The deploy tarball **includes this folder** (`scripts/pi/`): Reverb systemd unit, zerotier-when-idle, nginx config, update-from-url, migrate-with-repair. After a full deploy they live at `/var/www/flexiqueue/scripts/pi/` on the Pi.

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
