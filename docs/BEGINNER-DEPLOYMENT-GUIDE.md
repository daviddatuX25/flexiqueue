# FlexiQueue: Beginner deployment guide

This guide walks you from zero to a fully deployed FlexiQueue app on an Orange Pi, using **SQLite** (to save RAM) and **ZeroTier** (so you can reach the Pi over the internet when needed). Follow the steps in order.

---

## 1. What you need

**On your PC (where you develop):**

- The FlexiQueue repo (cloned).
- Either **Docker** (for Laravel Sail) or **PHP 8.2+** and **Node.js** installed, so you can build the deploy tarball.

**On the Pi:**

- An **Orange Pi One** (or similar) with **Armbian 24.04 (Noble)** installed and updated.
- The Pi on your local network so you can SSH in (e.g. `ssh root@192.168.1.x`). Later you’ll use ZeroTier to reach it remotely.

**ZeroTier:**

- A **ZeroTier network** (create one at [my.zerotier.com](https://my.zerotier.com)).
- The Pi (and optionally your PC) **joined** to that network so the Pi gets a fixed ZeroTier IP (e.g. `10.22.25.107`). You’ll use this IP to deploy and maintain the app when you’re not on the same LAN.

**APP_URL and different networks (DHCP):** The Pi may connect to different WiFi or LANs (e.g. portable setup). The IP address will change on each network, so using a fixed IP in `APP_URL` is not ideal. Use **mDNS**: set a stable hostname on the Pi (e.g. `orangepione`) and install Avahi so the Pi is reachable as `http://orangepione.local` from any device on the same LAN. Then set `APP_URL=http://orangepione.local` in `.env` and you don’t have to look up the Pi’s IP on each network. The deploy tarball includes a template `.env.prod` with this URL; on first deploy it is copied to `.env` so you only need to tweak it if your hostname is different.

---

## 2. One-time: Prepare the Pi

Do these steps on the Pi. SSH in with the Pi’s local IP or, after ZeroTier is set up, with its ZeroTier IP.

### 2.1 Install PHP, SQLite, and Nginx

Run on the Pi:

```bash
sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl nginx
```

Check that SQLite is available:

```bash
php -m | grep pdo_sqlite
```

You should see `pdo_sqlite`. If not, install: `sudo apt install -y php8.3-sqlite3` and try again.

### 2.2 Install ZeroTier and join your network

Install ZeroTier and start it:

```bash
curl -s https://install.zerotier.com | sudo bash
sudo systemctl enable --now zerotier-one
```

Join your network (replace `<your-network-id>` with the 16-character ID from my.zerotier.com):

```bash
sudo zerotier-cli join <your-network-id>
```

In the ZeroTier web UI, **authorize** the Pi. Then check its ZeroTier IP:

```bash
sudo zerotier-cli listnetworks
```

Note the assigned IP (e.g. `10.22.25.107`). You’ll use this as `PI_HOST` when deploying from your PC.

### 2.3 Create the app directory and SQLite database

Run on the Pi:

```bash
sudo mkdir -p /var/www/flexiqueue
sudo chown -R www-data:www-data /var/www/flexiqueue

sudo mkdir -p /var/www/flexiqueue/database
sudo touch /var/www/flexiqueue/database/database.sqlite
sudo chown -R www-data:www-data /var/www/flexiqueue/database
```

You do **not** create `.env` by hand. The deploy tarball includes `.env.prod`. On first deploy, the deploy script copies `.env.prod` to `.env` if `.env` is missing. After that, optionally edit `.env` on the Pi to set `APP_URL` (see 2.4).

### 2.4 Set hostname and mDNS (recommended for portable Pi)

So the Pi works on any DHCP network without reconfiguring `APP_URL`, set a fixed hostname and use mDNS so you can open the app at `http://<hostname>.local` (e.g. `http://orangepione.local`).

On the Pi:

```bash
# Set hostname (e.g. orangepione; must match the name in .env.prod APP_URL)
sudo hostnamectl set-hostname orangepione

# Install Avahi for mDNS (.local resolution)
sudo apt install -y avahi-daemon
sudo systemctl enable --now avahi-daemon
```

From another device on the same LAN you can then use `http://orangepione.local` and `ssh root@orangepione.local`. If you use a different hostname, after first deploy edit `/var/www/flexiqueue/.env` on the Pi and set `APP_URL=http://<your-hostname>.local`, then run `sudo -u www-data php artisan config:cache`.

### 2.5 Install the Nginx site config

From your **PC** (in the repo root), copy the Nginx config to the Pi:

```bash
scp scripts/pi/nginx-flexiqueue.conf root@<pi-ip>:/tmp/flexiqueue
```

Use the Pi’s IP (local or ZeroTier). Then on the **Pi**:

```bash
sudo mv /tmp/flexiqueue /etc/nginx/sites-available/flexiqueue
sudo ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

If your Pi has PHP 8.2 instead of 8.3, edit the site config and change `php8.3-fpm.sock` to `php8.2-fpm.sock`. For full Nginx details, see [10-DEPLOYMENT.md](architecture/10-DEPLOYMENT.md) Step 4.

---

## 3. One-time: Prepare your PC

On your development machine, in the FlexiQueue repo root:

**If you use Sail (Docker):**

```bash
./vendor/bin/sail up -d
./scripts/build-deploy-tarball-sail.sh
```

**If you build on the host (no Sail):**

```bash
./scripts/build-deploy-tarball.sh
```

You should see a file `flexiqueue-deploy.tar.gz` in the repo root. That’s the bundle you’ll deploy to the Pi.

---

## 4. First deploy

From your **PC**, in the repo root, set the Pi’s IP and run the deploy script. Use the Pi’s ZeroTier IP if you’re deploying over ZeroTier (e.g. `10.22.25.107`):

```bash
PI_HOST=10.22.25.107 ./scripts/deploy-to-pi.sh --build
```

(Replace `10.22.25.107` with your Pi’s actual IP. Use `--build` so it builds the tarball and then deploys.)

The script will ask for the Pi’s root password (or use SSH keys if configured). It copies the tarball to the Pi, extracts it, and if `.env` does not exist it copies `.env.prod` to `.env` (so first deploy does not require creating `.env` by hand). Then it runs migrations and caches config.

Then on the **Pi**, run these **first-time setup** steps so the app doesn’t return 500 (missing APP_KEY or cache path). Do this after every first deploy; later deploys usually only need `config:cache`.

```bash
cd /var/www/flexiqueue

# Ensure storage and bootstrap/cache dirs exist and are writable by the web server
sudo mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# SQLite: make database dir and file writable by www-data (avoids "attempt to write a readonly database")
sudo chown -R www-data:www-data database
sudo chmod 775 database
test -f database/database.sqlite && sudo chmod 664 database/database.sqlite

# Generate APP_KEY (writes to .env), create public/storage link, then cache config
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan config:cache
```

If you already ran the deploy script (which creates storage dirs and runs storage:link), you can shorten to just `key:generate` and `config:cache`; if you still see a 500, run the full block above (including `config:clear`).

**Reverb (WebSocket)** is needed for real-time updates (e.g. live station display, queue changes). You can run it once to test:

```bash
sudo -u www-data php artisan reverb:start
```

**Important (WebSocket port):** On the Pi, browsers should normally connect to WebSockets via **Nginx on port 80/443** at path `/app` (example: `ws://orangepione.local/app/...`). Nginx then proxies `/app` to Reverb on `127.0.0.1:6001`. This avoids needing to open port `6001` to the LAN/WiFi and prevents “WebSocket closed before connection established” errors when `:6001` is blocked.

To have Reverb start automatically when the Pi boots and restart if it crashes, set up a systemd service. Use one of the two options below. **Option B (from your PC) always works**; use Option A only if the file is already on the Pi.

**Option A — copy from your PC** (recommended; works even if the deploy tarball didn’t include `scripts/pi/`):

From your **PC** (in the FlexiQueue repo root), copy the service file to the Pi. Replace `<pi-ip>` with the Pi’s IP (local or ZeroTier, e.g. `10.22.25.107`):

```bash
scp scripts/pi/flexiqueue-reverb.service root@<pi-ip>:/etc/systemd/system/
```

Then on the **Pi**:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now flexiqueue-reverb
```

**Option B — copy from the Pi** (only if the file is already there at `/var/www/flexiqueue/scripts/pi/flexiqueue-reverb.service`):

On the **Pi**:

```bash
sudo cp /var/www/flexiqueue/scripts/pi/flexiqueue-reverb.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now flexiqueue-reverb
```

If you get `No such file or directory` when copying from the Pi, the deploy tarball didn’t include `scripts/pi/`. Use Option A from your PC instead.

After either option, Reverb runs as a service: it starts at boot and restarts if it stops. Check status with:

```bash
sudo systemctl status flexiqueue-reverb
```

---

## 5. ZeroTier “only when idle” (optional but recommended)

ZeroTier uses about 20–40 MB RAM. On a 512 MB Pi, you can save that RAM during events by running ZeroTier **only when no program or queue session is active**. When the Pi is idle, ZeroTier starts so you can still SSH in or deploy.

Copy the script to the Pi (from your **PC**, repo root):

```bash
scp scripts/pi/zerotier-when-idle.sh root@<pi-ip>:/tmp/
```

On the **Pi**:

```bash
sudo mv /tmp/zerotier-when-idle.sh /usr/local/bin/zerotier-when-idle
sudo chmod +x /usr/local/bin/zerotier-when-idle
```

Add a cron job (as root) so it runs every 5 minutes:

```bash
sudo crontab -e
```

Add this line (save and exit):

```
*/5 * * * * /usr/local/bin/zerotier-when-idle
```

When a program or queue session is active, ZeroTier will stop; when the Pi is idle, ZeroTier will start again so you can reach it. Optionally set `RECLAIM_MEMORY_ON_IDLE=1` (e.g. in a small env file or cron) to also reload PHP-FPM and restart Reverb when switching to idle, to free more memory.

---

## 6. Verify

- Open the app in your browser: `http://orangepione.local` (if you set up mDNS in 2.4) or `http://<pi-ip>` (local or ZeroTier IP). You should see the FlexiQueue app.
- **If you get a 500 error:** run the first-time steps in section 4 (storage dirs, database permissions, `key:generate`, `config:clear`, `config:cache`). If the log says "attempt to write a readonly database", fix SQLite permissions: `sudo chown -R www-data:www-data /var/www/flexiqueue/database && sudo chmod 775 /var/www/flexiqueue/database && sudo chmod 664 /var/www/flexiqueue/database/database.sqlite`. Check `storage/logs/laravel.log` on the Pi for the exact error.
- On the Pi you can double-check:
  - PHP and SQLite: `php -v` and `php -m | grep pdo_sqlite`
  - Config: `grep -E '^DB_|^APP_' /var/www/flexiqueue/.env`
  - Database file: `ls -la /var/www/flexiqueue/database/database.sqlite`
  - Storage link (for avatars, diagram images): `ls -la /var/www/flexiqueue/public/storage` should show a symlink to `../storage/app/public`.
  - Nginx: `sudo systemctl status nginx`
  - Reverb: `sudo systemctl status flexiqueue-reverb` (if you set up the systemd service in section 4).

---

## 7. Maintenance

**Update the app (after you change code and rebuild):**

From your PC (repo root):

```bash
PI_HOST=10.22.25.107 ./scripts/deploy-to-pi.sh --build
```

Again, use your Pi’s IP. Omit `--build` if you already have a fresh `flexiqueue-deploy.tar.gz` and only want to push it.

**Alternative: update from a URL**

If the tarball is hosted somewhere (e.g. a file server or GitHub Releases), you can update from the Pi with the `update-from-url.sh` script. See [scripts/pi/README.md](../scripts/pi/README.md) for setup and usage.

**Back up the database**

The app data lives in one file. Copy it periodically (e.g. with scp or a cron job):

```bash
scp root@<pi-ip>:/var/www/flexiqueue/database/database.sqlite ./backups/
```

**Start completely fresh**

To wipe the app and reinstall:

1. On the Pi: `sudo rm -rf /var/www/flexiqueue`
2. Redo from **section 2.3** (create app directory and SQLite file), **2.4** (hostname and mDNS if desired), and **2.5** (Nginx). Then run **section 4** (first deploy); `.env` will be created from `.env.prod` in the tarball.

---

## 8. Where to read more

- **Full technical runbook** (all steps, MariaDB option, systemd Reverb): [docs/architecture/10-DEPLOYMENT.md](architecture/10-DEPLOYMENT.md)
- **Pi helper scripts** (update-from-url, Nginx, ZeroTier script): [scripts/pi/README.md](../scripts/pi/README.md)
