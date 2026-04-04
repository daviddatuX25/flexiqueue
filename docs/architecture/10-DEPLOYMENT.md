# FlexiQueue deployment (Orange Pi One)

For a step-by-step beginner path (including ZeroTier and maintenance), see [Beginner deployment guide](../BEGINNER-DEPLOYMENT-GUIDE.md).

Deploy FlexiQueue to Orange Pi One (e.g. Armbian 24.04 Noble, 512 MB RAM). This guide covers a **fresh install** and uses **SQLite** on the Pi to save RAM (no MariaDB/MySQL daemon).

---

## Why SQLite for production on Orange Pi One

- **Saves RAM**: No separate database process (~50–100 MB saved vs MariaDB).
- **Simpler**: No DB server to install, secure, or back up separately.
- **Sufficient** for single-Pi, single-site event queue usage (sessions, cache, queue, and app data in one file).
- **Alternative**: Use MariaDB if you need multi-node or heavy concurrent writes; then install `mariadb-server`, create DB, and set `DB_CONNECTION=mysql` in `.env`.

---

## Overview

1. **Start fresh** (optional): remove existing `/var/www/flexiqueue` on the Pi.
2. **On the Pi**: install PHP 8.3, extensions (including SQLite), Nginx, create app dir and `database/database.sqlite`; `.env` is created from `.env.prod` in the tarball on first deploy.
3. **On your PC**: build the deploy tarball and run the deploy script (or copy tarball + run commands manually).
4. **On the Pi**: run migrations, cache config, Reverb and queue worker (both installed by full-setup-pi.sh).

---

## Step 1: Start fresh on the Pi (optional)

If you want to wipe the app and reinstall:

```bash
# SSH into the Pi
ssh root@<pi-ip>

# Remove app directory (all app files and any existing DB)
sudo rm -rf /var/www/flexiqueue
```

---

## Step 2: Prepare the Pi (PHP, SQLite, Nginx)

Run on the Pi (Armbian/Ubuntu):

```bash
# Update and install PHP 8.3 + FPM + SQLite and other extensions
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl

# Verify SQLite is enabled
php -m | grep pdo_sqlite
# Should show: pdo_sqlite

# Install Nginx if not already present
sudo apt install -y nginx
```

---

## Step 3: Create app directory (and `.env` from tarball)

Still on the Pi:

```bash
# Create app directory and set ownership
sudo mkdir -p /var/www/flexiqueue
sudo chown -R www-data:www-data /var/www/flexiqueue

# Create database directory and SQLite file (writable by www-data)
sudo mkdir -p /var/www/flexiqueue/database
sudo touch /var/www/flexiqueue/database/database.sqlite
sudo chown -R www-data:www-data /var/www/flexiqueue/database
```

**`.env`:** The deploy tarball includes `.env.prod` (production template with SQLite). When you run the deploy script, if `.env` is missing it is created by copying `.env.prod` to `.env`, so you do not need to create `.env` manually on first deploy. If you deploy by hand (extract only), run: `cp .env.prod .env` and `chown www-data:www-data .env`.

**APP_URL and different networks (DHCP):** If the Pi connects to different WiFi/LANs, its IP will change. Set a stable hostname (e.g. `orangepione`) and use **mDNS** (Avahi) so the Pi is reachable as `http://orangepione.local`; then set `APP_URL=http://orangepione.local` in `.env`. You can then open the app and SSH as `root@orangepione.local` without looking up the IP on each network. See [Beginner deployment guide](../BEGINNER-DEPLOYMENT-GUIDE.md) section 2.4 for hostname and Avahi setup. After first deploy, generate the key: `sudo -u www-data php artisan key:generate` and `config:cache`.

---

## Step 4: Nginx site config on the Pi

Copy the Nginx config from your PC (from repo root):

```bash
scp scripts/pi/nginx-flexiqueue.conf root@<pi-ip>:/tmp/flexiqueue
```

On the Pi:

```bash
sudo mv /tmp/flexiqueue /etc/nginx/sites-available/flexiqueue
sudo ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

For PHP 8.2, edit the site config and change `php8.3-fpm.sock` to `php8.2-fpm.sock`.

---

## Step 5: Build and deploy from your PC

On your development machine (from repo root):

**Build the tarball** (Sail or host):

```bash
# With Sail (recommended)
./vendor/bin/sail up -d
./scripts/build-deploy-tarball-sail.sh

# Or without Sail
./scripts/build-deploy-tarball.sh
```

**Deploy to the Pi** (creates `flexiqueue-deploy.tar.gz` if you used `--build`):

```bash
export PI_HOST=10.22.25.107   # or your Pi's IP/hostname
./scripts/deploy-to-pi.sh --build
# Or, if tarball already built:
./scripts/deploy-to-pi.sh
```

The deploy script will:

- Copy `flexiqueue-deploy.tar.gz` to the Pi
- Extract into `/var/www/flexiqueue`, chown to `www-data`
- Create `storage` dirs and set `database/` (and `database.sqlite`) writable by `www-data` (avoids "readonly database" with SQLite)
- Run `php artisan migrate --force`, `config:cache`, `route:cache`, `storage:link`
- Restart `flexiqueue-reverb` and `flexiqueue-queue` if the units exist

**If `.env` is still missing** after deploy (e.g. you extracted the tarball by hand), on the Pi run: `cd /var/www/flexiqueue && cp .env.prod .env && chown www-data:www-data .env`, then either re-run the deploy script (without `--build`) or on the Pi run the first-time steps below.

---

## Step 6: First-time on the Pi (key, storage, cache)

After the first deploy (or if you see 500 “No application encryption key” or “valid cache path”), on the Pi run:

```bash
cd /var/www/flexiqueue

# Ensure storage and bootstrap/cache exist and are writable (avoids "valid cache path" / view errors)
sudo mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# SQLite: make database dir and file writable by www-data (avoids "attempt to write a readonly database")
sudo chown -R www-data:www-data database
sudo chmod 775 database
test -f database/database.sqlite && sudo chmod 664 database/database.sqlite

# Generate APP_KEY, storage link, clear then cache config
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan config:cache
```

If you only need to refresh config after a later deploy: `sudo -u www-data php artisan config:cache`.

---

## Step 7: Reverb and queue worker

**Reverb** (WebSocket for real-time updates):

```bash
# Run once to test
sudo -u www-data php artisan reverb:start

# Or install as a systemd service — full-setup-pi.sh does this; unit: scripts/pi/flexiqueue-reverb.service
# Then: sudo systemctl enable --now flexiqueue-reverb
```

**WebSocket proxying on the Pi (recommended):**

- Reverb runs on `127.0.0.1:6001` (or binds `0.0.0.0:6001`), but browsers should connect to **Nginx** on port **80/443** at `/app` (example: `ws://orangepione.local/app/...`).
- Nginx config (`scripts/pi/nginx-flexiqueue.conf`) already proxies `/app` → `http://127.0.0.1:6001` with the required `Upgrade` headers.

This avoids requiring port `6001` to be reachable from client devices and prevents “WebSocket closed before connection established” when `:6001` is blocked.

**Queue worker** (required for TTS pre-generation and other queued jobs):

- Token and station TTS generation (GenerateTokenTtsJob, GenerateStationTtsJob) run in the queue. Without a running worker, TTS pre-generate will not run and the admin UI may show 503 or stuck "generating" status.
- **On the Pi:** full-setup-pi.sh installs `flexiqueue-queue.service`; apply-tarball restarts it after deploy. To run manually: `sudo -u www-data php artisan queue:work --tries=3`. To install the unit by hand: copy `scripts/pi/flexiqueue-queue.service` to `/etc/systemd/system/`, then `sudo systemctl daemon-reload && sudo systemctl enable --now flexiqueue-queue`.
- **On Laragon/laptop:** run `php artisan queue:work --tries=3` in a separate terminal, or use the same systemd unit on WSL.

---

## Verification

On the Pi:

```bash
# PHP and SQLite
php -v
php -m | grep pdo_sqlite

# DB and app
grep -E '^DB_|^APP_' /var/www/flexiqueue/.env
ls -la /var/www/flexiqueue/database/database.sqlite

# Test DB connection (no migrate, just connect)
cd /var/www/flexiqueue && sudo -u www-data php artisan tinker --execute="DB::connection()->getPdo(); echo 'DB OK';"

# Run migrations
cd /var/www/flexiqueue && sudo -u www-data php artisan migrate --force
```

Then open `http://<pi-ip>` in a browser.

---

## Using MariaDB on the Pi instead of SQLite

If you prefer MariaDB (e.g. for replication or tooling):

1. Install: `sudo apt install -y mariadb-server php8.3-mysql`
2. Create DB and user, then in `/var/www/flexiqueue/.env`:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flexiqueue
DB_USERNAME=flexiqueue
DB_PASSWORD=your_password
```

3. Run migrate and cache as above.

---

## Quick reference: clean reinstall

1. On Pi: `sudo rm -rf /var/www/flexiqueue`
2. On Pi: install PHP 8.3 + `php8.3-sqlite3`, Nginx; create `/var/www/flexiqueue`, `database/database.sqlite`; `.env` comes from `.env.prod` in tarball on first deploy.
3. On PC: build tarball, then `PI_HOST=<pi-ip> ./scripts/deploy-to-pi.sh --build`
4. On Pi: `sudo -u www-data php artisan key:generate` (if needed), `config:cache`. If you used full-setup-pi.sh, Reverb and queue worker are already enabled; otherwise start them (see Step 7).
