# FlexiQueue Deployment — Orange Pi 3 Zero (1 GB)

**Purpose:** Compact runbook from Orange Pi setup through first deploy and updates. Target: Orange Pi 3 Zero, 1 GB RAM; native install (no Docker on the Pi). **Build is always GitHub Actions**; the Pi receives the full ready artifact (one tarball) or, in fallback, code only via git pull.

Related: [02-ARCHITECTURE-OVERVIEW.md](02-ARCHITECTURE-OVERVIEW.md), [05-SECURITY-CONTROLS.md](05-SECURITY-CONTROLS.md).

---

## 0. SD card preparation and OS installation

Do this on your **host machine** (Linux, WSL2, or macOS) before the first boot. The SD card will be wiped; use a spare card or ensure you have no data to keep.

### 0.1 Identify the SD card device

**Critical:** Use the correct device. Writing to the wrong device (e.g. your main disk) will destroy data.

```bash
# List block devices (SD card is usually the smallest, e.g. /dev/sdb or /dev/mmcblk0)
lsblk

# After inserting the card, see what was added
dmesg | tail -20
```

- **Linux:** Often `/dev/sdX` (e.g. `/dev/sdb`) or `/dev/mmcblk0` if using a built-in reader.
- **WSL2:** The SD card may appear as a drive in Windows; use **Windows** to flash (e.g. [Raspberry Pi Imager](https://www.raspberrypi.com/software/), [balenaEtcher](https://etcher.balena.io/), or [rufus](https://rufus.ie/)). WSL2 often does not expose removable USB/SD to Linux. If the card is visible in WSL2, it will show up in `lsblk`.
- **macOS:** Usually `/dev/diskN` (e.g. `/dev/disk2`). Use `diskutil list` to confirm.

Use `DISK` in the steps below; replace with your actual device (e.g. `DISK=/dev/sdb`). **Do not use a partition** (e.g. `/dev/sdb1`); use the whole disk (`/dev/sdb`).

### 0.2 Unmount any partitions

```bash
# Replace DISK with your device (e.g. /dev/sdb or /dev/mmcblk0)
DISK=/dev/sdb

# Unmount all partitions on that device (Linux)
sudo umount ${DISK}?* 2>/dev/null || true
sudo umount ${DISK}*  2>/dev/null || true
# For mmcblk0:
sudo umount ${DISK}p* 2>/dev/null || true
```

### 0.3 Wipe the card (clean partition table)

This removes existing partitions and leaves the card empty so the next step writes a single bootable image.

```bash
# Zero the first 32 MiB (removes partition table and boot loaders)
sudo dd if=/dev/zero of=${DISK} bs=1M count=32 status=progress conv=fsync

# Create a fresh GPT (or MBR) table — optional; the image flash in 0.4 will overwrite this
sudo wipefs -a ${DISK}
```

### 0.4 Download the OS image and flash

1. **Download** an image for **Orange Pi 3 Zero** (1 GB):
   - [Armbian](https://www.armbian.com/orange-pi-3-zero/) — pick Ubuntu 22.04 or Debian 12.
   - Or [Orange Pi official images](https://github.com/orangepi-xunlong/orangepi-build/releases) if available for Orange Pi 3 Zero.

2. **Flash** the downloaded `.img` file to the SD card (replace `IMAGE` with the path to the file):

   ```bash
   IMAGE=/path/to/Armbian_*.img

   # Raw write (same size as image; block size 4M is often faster)
   sudo dd if=${IMAGE} of=${DISK} bs=4M status=progress conv=fsync
   ```

   Alternatively use a GUI flasher (Raspberry Pi Imager, balenaEtcher) and select the same image and the SD card device.

3. **Sync and eject:**

   ```bash
   sync
   sudo eject ${DISK}
   ```

Insert the SD card into the Orange Pi, connect power and network, then continue with **Section 2** (SSH, swap, static IP).

---

## 1. Scope and constraints

- **Target:** Orange Pi 3 Zero, 1 GB RAM. Native install only — no Docker on the Pi.
- **Build:** **GitHub Actions only.** Composer and `npm run build` run in CI; no laptop or “pi-prod” build. The Pi receives the **full ready artifact** (one tarball: code + vendor + public/build) or, in fallback, code only via `git pull`.
- **Discovery:** mDNS (Avahi) so the Pi is reachable as `orangepi.local` on any LAN without looking up the IP. **Portable (planned):** headless WiFi via USB QR scanner (SSID/password payload); app services start only after network is up (Section 2.1).
- **Expand:** Hardware table (alternative boards, 2 GB+ Pi), power, WiFi AP as gateway.

---

## 2. Orange Pi setup (what to do next)

1. **OS:** Flash an Armbian or official Orange Pi image for **Orange Pi 3 Zero** (e.g. Ubuntu 22.04 or Debian 12 if available). Boot and confirm the device is on the network.
2. **SSH:** Ensure SSH is enabled; from your machine: `ssh <user>@<pi-ip>` (default user is often `orangepi` or `root`).
3. **Swap (required for 1 GB):**

```bash
sudo fallocate -l 1G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

4. **Static IP (optional):** Set a fixed IP (e.g. `192.168.100.1`) so `APP_URL` and deploy targets stay stable. *Expand: DHCP server / WiFi AP if the Pi is the LAN gateway.*

5. **mDNS (discovery):** Use hostname instead of IP so the Pi is reachable on any LAN without searching for its DHCP address. Enable Avahi and use `orangepi.local` or `armbian.local`:

   ```bash
   sudo apt install -y avahi-daemon
   sudo systemctl enable --now avahi-daemon
   ```

   From your laptop/phone (on the same network): `ssh root@orangepi.local` or `ssh root@armbian.local`. Works when the client and Pi support mDNS; if not, use static IP or DHCP reservation (see Section 2.2 for portable use).

*Expand: Full WiFi AP setup, UFW firewall, SSH key-only auth.*

### 2.1 Portable / Ethernet-less operation (planned)

**Goal:** Use the Pi on any LAN without a screen or keyboard; avoid IP hunting when moving between networks.

- **Discovery:** Rely on **mDNS** (step 5 above) so you can always `ssh root@orangepi.local` from a device on the same network, regardless of DHCP-assigned IP.
- **Headless WiFi provisioning:** On first boot (or when joining a new network), plug in a **USB QR scanner**. The scanner acts as a keyboard (HID); you scan a QR code whose payload encodes **SSID and password**. A small service on the Pi:
  - Listens for input from the scanner (e.g. on a dedicated device or via parsing keyboard input).
  - Expects a standard WiFi QR format (e.g. `WIFI:T:WPA;S:<ssid>;P:<password>;;`).
  - Writes the credentials into the system (e.g. `wpa_supplicant` or NetworkManager) and triggers (re)connect.
- **Wait for network before starting servers:** Application services (Nginx, PHP-FPM, MariaDB, Reverb, queue workers) must **not** start until the Pi has connectivity (Ethernet or WiFi). Implementation options:
  - A systemd **path** or **oneshot** that blocks until `network-online.target` or a custom check (e.g. ping gateway or DNS).
  - FlexiQueue stack services (Nginx, PHP-FPM, Reverb, etc.) depend on that target or a `flexiqueue-ready.service` that runs after network is up (and optionally after QR provisioning has run once).
- **Flow:** Boot → (optional: wait for USB QR scan → configure WiFi → connect) → wait for network → start FlexiQueue stack.

*Expand: QR provisioning daemon, WiFi QR payload spec, systemd units for wait-for-network and service ordering.*

---

## 3. Stack on the Pi (native)

- **No Node, no Composer** on the Pi. Install only the runtime stack.

### 3.1 Install packages

Use **PHP 8.2** or **PHP 8.3** depending on what your image provides (Laravel 12 supports both). Armbian 25.x Noble ships PHP 8.3.

**If your Pi has PHP 8.2:**
```bash
sudo apt update && sudo apt install -y \
  nginx php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath mariadb-server git
```

**If your Pi has PHP 8.3 (e.g. Armbian Noble):**
```bash
sudo apt update && sudo apt install -y \
  nginx php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath mariadb-server git
```

Check with: `apt-cache search php | grep -E 'php[0-9]\.[0-9]-fpm'`. In Nginx and PHP-FPM config below, use the matching socket and paths (e.g. `php8.3-fpm.sock` and `/etc/php/8.3/fpm/`).

### 3.2 MariaDB

```bash
sudo mysql -e "
  CREATE DATABASE flexiqueue;
  CREATE USER 'flexiqueue_user'@'localhost' IDENTIFIED BY 'your_strong_password';
  GRANT ALL ON flexiqueue.* TO 'flexiqueue_user'@'localhost';
  FLUSH PRIVILEGES;
"
```

Use a strong password; store it for `.env` (Step 4).

*Expand: PHP-FPM pool tuning (pm.max_children), MariaDB `innodb_buffer_pool_size` (e.g. 64M–128M for 1 GB), Nginx worker tuning.*

### 3.3 Nginx site

Create `/etc/nginx/sites-available/flexiqueue`:

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/flexiqueue/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;  # or php8.3-fpm.sock if using PHP 8.3
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # WebSocket: Laravel Reverb (optional; else clients use port 6001)
    location /app {
        proxy_pass http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

Enable and reload:

```bash
sudo ln -s /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

---

## 4. App on the Pi (first-time deploy)

### 4.1 Clone and env

```bash
sudo mkdir -p /var/www
sudo git clone https://github.com/your-org/flexiqueue.git /var/www/flexiqueue
cd /var/www/flexiqueue
sudo cp .env.example .env
```

Edit `.env`: set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=http://<pi-ip>` (e.g. `http://192.168.100.1`), `DB_*` to match MariaDB (user `flexiqueue_user`, database `flexiqueue`, password from Step 3.2), `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=database`, and Reverb:

- `BROADCAST_CONNECTION=reverb`
- `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (generate or keep secret)
- `REVERB_HOST=<pi-ip>`, `REVERB_PORT=6001`, `REVERB_SCHEME=http`, `REVERB_SERVER_HOST=0.0.0.0`, `REVERB_SERVER_PORT=6001`
- `VITE_REVERB_*` same as above so built assets point at the Pi

Align with project [.env.example](../../.env.example).

### 4.2 App key (on the Pi only)

```bash
cd /var/www/flexiqueue
php artisan key:generate
```

Do not copy `.env` from another machine for production; generate the key on the Pi.

### 4.3 Full artifact (no build on Pi, no build on laptop)

Do **not** run `composer install` or `npm run build` on the Pi. Get the **full deploy tarball** from GitHub Actions (Section 6): download the artifact from a successful run (Actions tab → run → Artifacts → **flexiqueue-deploy**), then push it to the Pi and extract. For first time:

- **Option 1 (recommended):** After the workflow runs, from **any** machine (any laptop) download **flexiqueue-deploy** (contains `flexiqueue-deploy.tar.gz`). Then:
  ```bash
  scp flexiqueue-deploy.tar.gz <pi-user>@<pi-ip>:/tmp/
  ssh <pi-user>@<pi-ip>
  cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz
  sudo chown -R www-data:www-data /var/www/flexiqueue
  ```
- **Option 2:** If the Pi is reachable from GitHub (e.g. ZeroTier), set `ENABLE_PI_DEPLOY=1` and Pi secrets so the workflow deploys for you (Section 6.2 A).

The tarball includes code, `vendor/`, and `public/build/`; it does **not** overwrite `.env` or `storage` (they stay on the Pi).

### 4.4 Migrate, seed, permissions

```bash
cd /var/www/flexiqueue
php artisan migrate --force
php artisan db:seed
sudo chown -R www-data:www-data /var/www/flexiqueue
sudo chmod -R 775 storage bootstrap/cache
```

Optional: `php artisan storage:link`. Ensure `APP_URL` matches the app’s base URL.

### 4.5 Reverb

Run Reverb on the Pi so real-time updates work:

```bash
php artisan reverb:start
```

Keep it running in a screen/tmux session, or run as a systemd service. *Expand: systemd unit for `flexiqueue-reverb`, restart policy.*

---

## 5. Deploying the artifact to the Pi (no tunnel)

Use this when the Pi is **not** reachable from GitHub (typical demo: Pi on LAN only). **No building on the laptop** — the laptop (or any machine) only downloads the pre-built artifact and pushes it to the Pi.

1. **Trigger a build:** Push to `dev` or `master` (or run the workflow manually) (Actions → “Build for Orange Pi” → Run workflow).
2. **Download the artifact:** From the completed run, open Artifacts and download **flexiqueue-deploy** (or use `gh run download` if you have the GitHub CLI). You get `flexiqueue-deploy.tar.gz`.
3. **Push to the Pi:** From the same machine (any laptop):
   ```bash
   scp flexiqueue-deploy.tar.gz <pi-user>@<pi-ip>:/tmp/
   ```
4. **On the Pi:** SSH in and extract, then migrate and cache:
   ```bash
   ssh <pi-user>@<pi-ip>
   cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz
   sudo chown -R www-data:www-data /var/www/flexiqueue
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   # If Reverb runs as a service: sudo systemctl restart flexiqueue-reverb
   ```

*Expand: Script (e.g. `scripts/deploy-to-pi.sh`), idempotency, rollback.*

---

## 6. GitHub Actions

The workflow is the **only** build source. It produces one **full deploy tarball** (code + vendor + public/build). See [.github/workflows/deploy-orange-pi.yml](../../.github/workflows/deploy-orange-pi.yml).

### 6.0 Branch strategy (dev / prod)

- **`dev`** — Development branch. Day-to-day work and feature branches merge here. Pushing to `dev` triggers the **build** job only (artifact is produced; you can download it and deploy to a test Pi manually). The **deploy** job does **not** run on `dev`.
- **`master`** — Production branch. Code that is released to the Orange Pi. Pushing to `master` triggers the **build** job; the **deploy** job runs only if `ENABLE_PI_DEPLOY=1` and Pi secrets are set (see 6.2 A).

**Workflow:** Develop on `dev` → test with artifact from `dev` runs if needed → merge `dev` into `master` when ready for production → push to `master` (build + optional auto-deploy to Pi).

To create and push `dev` from current `master`: `git checkout -b dev && git push -u origin dev`. Then set the default branch in GitHub repo settings as needed (e.g. `dev` for PRs, `master` for production).

### 6.1 Build

- **Trigger:** Push to `dev`, push to `master`, or manual `workflow_dispatch`.
- **Steps:** Checkout, PHP 8.2, Node, `composer install --no-dev`, `npm ci && npm run build`, then create `flexiqueue-deploy.tar.gz` (excludes `.git`, `node_modules`, `.env`, `storage`). Upload as artifact **flexiqueue-deploy**.

### 6.2 Deploy options

**Recommended when the Pi is on your LAN (no ZeroTier):** Leave `ENABLE_PI_DEPLOY` **unset**. Use **Actions to build** → **you download the artifact** from the run (Actions → completed run → Artifacts → **flexiqueue-deploy**) → **from your laptop:** `scp flexiqueue-deploy.tar.gz root@<pi-ip>:/tmp/` → **SSH to the Pi** and run extract + migrate (Section 5). No tunnel, no deploy secrets, no ZeroTier required.

- **A) Pi reachable from GitHub (e.g. ZeroTier tunnel):** Optional. Only if you want GitHub to push to the Pi automatically. Set **variable** `ENABLE_PI_DEPLOY=1` and **secrets** `PI_HOST`, `PI_USER`, `PI_SSH_KEY`. Deploy runs **only on `master`**. Without a tunnel, this job never runs. *Expand: ZeroTier/similar, SSH key on Pi, firewall.*
- **B) Pi on LAN only (typical):** Do **not** set `ENABLE_PI_DEPLOY`. The workflow only builds and uploads the artifact. You download the artifact, push the tarball to the Pi from your machine, then SSH and extract + migrate (Section 5). **This is the normal flow when you don’t use ZeroTier.**
- **C) Fallback — no artifact at hand:** SSH to the Pi and run `git pull` only. That updates **code only**; `vendor/` and `public/build/` are unchanged unless you update them separately (e.g. push an artifact later).

*Expand: Secrets best practices, deploy on tag vs branch.*

---

## 7. Pi update workflow (after deploy or git pull)

**Normal path (artifact):** You already pushed the tarball and ran extract + migrate + cache + restart (Section 5 or workflow deploy job). Nothing else on the Pi unless you want to clear caches or restart Reverb again.

**If you used git pull (fallback):** SSH to the Pi, then:

```bash
cd /var/www/flexiqueue
git pull
# If composer.lock or package.json changed, you must get new vendor/build onto the Pi (e.g. push a new artifact, or run composer/npm on the Pi).
php artisan migrate --force
php artisan config:cache
php artisan route:cache
# Restart Reverb if it runs as a service: sudo systemctl restart flexiqueue-reverb
```

*Expand: Zero-downtime, health checks, maintenance mode.*

---

## 8. Checklist and what to do next

### First-time

1. Orange Pi: OS, SSH, swap, optional static IP, **mDNS** (Avahi) for discovery (`ssh root@orangepi.local`).
2. (Optional / planned) Portable: headless WiFi via USB QR scanner (SSID/password payload); services start only after network is up (Section 2.1).
3. Stack: Nginx, PHP 8.2- or 8.3-FPM (per image), MariaDB; create DB and user; Nginx site.
4. App: clone repo, `.env` (with Reverb), `php artisan key:generate` on the Pi.
5. Get the **full deploy tarball** from GitHub Actions (download artifact or use deploy job if tunnel is set). Push to Pi and extract; migrate, seed, permissions, optional `storage:link`.
6. Start Reverb; test in browser at `http://<pi-ip>` or `http://orangepi.local`.

### Updates

1. **GitHub Actions** produces the full artifact on push to `dev` or `master` (or manual run). Deploy job runs only on `master` when `ENABLE_PI_DEPLOY=1`.
2. **With tunnel:** If `ENABLE_PI_DEPLOY=1` and Pi secrets are set, the deploy job pushes the tarball to the Pi and runs extract + migrate + cache.
3. **No tunnel:** From any machine, download the **flexiqueue-deploy** artifact, push the tarball to the Pi, SSH and run extract + migrate + cache + restart Reverb (Section 5).
4. **Fallback:** If you can’t use the artifact, SSH to the Pi and run `git pull` (code only); then update vendor/build by other means if needed.

### Full runbook (redo from first SSH)

Use this when redoing the whole setup (e.g. fresh Armbian flash or clean reinstall). Run steps **on the Pi** unless marked "From laptop". Replace `<pi-ip>` with the Pi’s IP or use `armbian.local` / `orangepi.local` if mDNS is set up; replace `your_strong_password` and your repo URL.

| Step | Where | Action |
|------|--------|--------|
| 1 | Laptop | `ssh root@<pi-ip>` (or `ssh root@armbian.local`) |
| 2 | Pi | **Swap:** `sudo fallocate -l 1G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile && echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab` |
| 3 | Pi | **mDNS (optional):** `sudo apt install -y avahi-daemon && sudo systemctl enable --now avahi-daemon` |
| 4 | Pi | **Stack:** If PHP 8.2: `sudo apt install -y nginx php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath mariadb-server git`. If PHP 8.3 (e.g. Armbian Noble): use `php8.3-fpm` and `php8.3-*` in place of 8.2. Then use matching socket/paths in Nginx and pool config (e.g. `php8.3-fpm.sock`, `/etc/php/8.3/fpm/`). |
| 5 | Pi | **MariaDB DB/user:** `sudo mysql -e "CREATE DATABASE flexiqueue; CREATE USER 'flexiqueue_user'@'localhost' IDENTIFIED BY 'your_strong_password'; GRANT ALL ON flexiqueue.* TO 'flexiqueue_user'@'localhost'; FLUSH PRIVILEGES;"` |
| 6 | Pi | **MariaDB 512MB (optional):** `echo '[mysqld]\ninnodb_buffer_pool_size = 32M' | sudo tee /etc/mysql/mariadb.conf.d/99-flexiqueue.cnf && sudo systemctl restart mariadb` |
| 7 | Pi | **Nginx site:** Create `/etc/nginx/sites-available/flexiqueue` with the server block from Section 3.3 (root `/var/www/flexiqueue/public`, fastcgi to `php8.2-fpm.sock` or `php8.3-fpm.sock` to match installed PHP, `/app` proxy to 6001). Then: `sudo ln -s /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default && sudo nginx -t && sudo systemctl reload nginx` |
| 8 | Pi | **PHP-FPM 512MB (optional):** In `/etc/php/8.2/fpm/pool.d/www.conf` or `/etc/php/8.3/fpm/pool.d/www.conf` set `pm = ondemand`, `pm.max_children = 2`, `pm.max_requests = 500`; then `sudo systemctl restart php8.2-fpm` or `php8.3-fpm` |
| 9 | Pi | **Swappiness (optional):** `echo 'vm.swappiness = 10' | sudo tee /etc/sysctl.d/99-lowram.conf && sudo sysctl -p /etc/sysctl.d/99-lowram.conf` |
| 10 | Pi | **Clone + env:** `sudo mkdir -p /var/www && sudo git clone https://github.com/YOUR_ORG/flexiqueue.git /var/www/flexiqueue && cd /var/www/flexiqueue && sudo cp .env.example .env` then edit `.env`: APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, QUEUE_CONNECTION=sync, SESSION_DRIVER=database, BROADCAST_CONNECTION=reverb, REVERB_* and VITE_REVERB_* (Section 4.1) |
| 11 | Pi | **App key:** `cd /var/www/flexiqueue && php artisan key:generate` |
| 12 | Laptop | Build artifact (Actions → Build for Orange Pi), download **flexiqueue-deploy**, then `scp flexiqueue-deploy.tar.gz root@<pi-ip>:/tmp/` (or extract and scp the inner .tar.gz if needed) |
| 13 | Pi | **Extract artifact:** `cd /var/www/flexiqueue && sudo tar -xzf /tmp/flexiqueue-deploy.tar.gz && sudo chown -R www-data:www-data /var/www/flexiqueue` |
| 14 | Pi | **Migrate + seed + perms:** `cd /var/www/flexiqueue && php artisan migrate --force && php artisan db:seed && sudo chown -R www-data:www-data /var/www/flexiqueue && sudo chmod -R 775 storage bootstrap/cache` |
| 15 | Pi | **Start Reverb:** `php artisan reverb:start` (foreground) or run in screen/tmux / systemd |
| 16 | Laptop | **Test:** Open `http://<pi-ip>` or `http://armbian.local` in browser |

### Low-RAM (512 MB) tuning and OOM avoidance

For Orange Pi One (512 MB) or similar low-RAM boards, use the following to avoid OOM and hanging. Expected load: ~5 staff + 1 informant + 5 display/station devices (~11 connections); up to 100 queue clients as data only.

**Findings (research summary):**

1. **PHP-FPM**
   - **pm.max_children:** On 512 MB, reserve ~100–150 MB for OS/Nginx/MariaDB/Reverb; leave ~100–150 MB for PHP. With ~60 MB per Laravel worker, use **pm.max_children = 2** (or 1 for maximum safety).
   - **pm = ondemand:** Spawn workers only on request; avoids idle workers using RAM. Prefer over `dynamic` on 512 MB.
   - **pm.max_requests:** Set to **500–1000** (not 0). Recycles workers periodically so PHP memory leaks and fragmentation don’t grow unbounded; reduces slow degradation and 502s over time.

2. **MariaDB**
   - **innodb_buffer_pool_size = 32M** (or 64M if you have headroom). Default 128M is too large; MariaDB can use 400MB+ untuned. Also minimize other buffers (e.g. `innodb_log_buffer_size`, `thread_cache_size`) in a custom `my.cnf` or drop-in.

3. **Reverb**
   - Reverb has reported **memory growth and high CPU** over long runs (e.g. 24h). Run it under **systemd** with **Restart=always** so crashes recover. For 512 MB, add a **periodic restart** (e.g. systemd timer or cron every 6–12h) to clear accumulated memory until upstream fixes improve.
   - Ensure **Telescope/Pulse ingest** is disabled on the Pi (no Laravel Telescope/Pulse in production on 512 MB); Reverb config has `telescope_ingest_interval` / `pulse_ingest_interval` — if those packages aren’t used, ingestion is effectively off.

4. **Swap and kernel**
   - **Swap:** 1 GB (or more) in `/etc/fstab`. Gives the kernel room before OOM; use it as a buffer, not as the only fix.
   - **vm.swappiness:** Set to **10–20** (e.g. in `/etc/sysctl.d/99-lowram.conf`). Reduces aggressive swap I/O so the system stays responsive under memory pressure instead of hanging on swap thrash.

5. **Cleanup and recycling**
   - **Logs:** Rotate/trim Laravel logs (e.g. `log_max_files` in `config/logging.php` or system `logrotate`) so `storage/logs/` doesn’t grow and fill the SD card; full disk can cause freezes and odd failures.
   - **PHP-FPM pm.max_requests** (above) recycles workers; no extra “cleaning” of PHP processes needed beyond that.
   - **Sessions:** With `SESSION_DRIVER=database`, expired sessions are just rows; optionally run `php artisan session:table` and a scheduled prune if you add a cleanup command, so the sessions table doesn’t grow without bound.

6. **What to avoid**
   - Don’t run **queue workers** on the Pi for this load; use **QUEUE_CONNECTION=sync** (already in the doc).
   - Don’t rely on **swap alone** as a permanent fix; combine with the limits above.
   - Don’t leave **pm.max_requests = 0**; worker recycling is important for stability.

**Suggested Pi config snippets (apply on the Pi):**

- **PHP-FPM pool** (e.g. `/etc/php/8.2/fpm/pool.d/www.conf` or `/etc/php/8.3/fpm/pool.d/www.conf`):
  ```ini
  pm = ondemand
  pm.max_children = 2
  pm.max_requests = 500
  ```
- **MariaDB** (e.g. `/etc/mysql/mariadb.conf.d/99-flexiqueue.cnf`):
  ```ini
  [mysqld]
  innodb_buffer_pool_size = 32M
  ```
- **Sysctl** (`/etc/sysctl.d/99-lowram.conf`):
  ```
  vm.swappiness = 10
  ```
  Then `sudo sysctl -p /etc/sysctl.d/99-lowram.conf`.

- **Reverb systemd** (e.g. `/etc/systemd/system/flexiqueue-reverb.service`): `Restart=always`; optionally add a timer to `systemctl restart flexiqueue-reverb` every 6–12 hours.

*Expand: Full my.cnf for 512MB, Reverb timer unit, logrotate for Laravel.*

---

### Optional: HTTPS

For HTTPS on the Pi, add a self-signed cert and Nginx SSL config. See [05-SECURITY-CONTROLS.md](05-SECURITY-CONTROLS.md). *Expand as needed.*

*Expand: Troubleshooting (502, Reverb not connecting, DB connection), log locations.*
