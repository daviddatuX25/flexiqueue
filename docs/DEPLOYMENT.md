# FlexiQueue Deployment Guide

## Overview
Two deployments exist:
- Central — the hosted web app at flexiqueue.click (Hestia hosting)
- Edge — Orange Pi One devices at venues (runs offline-capable)

### Spatie permissions (RBAC)

After any deploy that changes **roles, permissions, seeders**, or **migrations** touching Spatie tables (`roles`, `permissions`, pivots), run on the target environment:

```bash
php artisan permission:cache-reset
```

Spatie caches permission lookups; a stale cache can cause **403** for users who should pass. Run this **after** `php artisan migrate` when migrations alter permission data. See [`docs/plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md`](plans/RBAC_SPATIE_PERMISSIONS_MIGRATION_PLAN.md) and [`docs/architecture/PERMISSIONS.md`](architecture/PERMISSIONS.md).

#### Phase 6 — Spatie teams + `RbacTeam` (scoped site/program permissions)

Deploys that include **`rbac_teams`** and **nullable `team_id`** on Spatie tables must run migrations **before** relying on authorization. Order:

1. **Backup** the database (structural change; rollback = restore backup).
2. `php artisan migrate` — creates `rbac_teams`, adds `team_id` columns, seeds global team id `1` per migration.
3. `php artisan rbac:sync-teams` — idempotent: ensures every `sites` / `programs` row has a matching `RbacTeam` (safe on fresh or upgraded DBs).
4. `php artisan permission:cache-reset` — required after permission/team schema changes.

Global HTTP requests use team id `1` via middleware (`SetGlobalPermissionsTeam`). Scoped direct grants use **non-global** `RbacTeam` rows; see [`docs/architecture/PERMISSIONS-TEAMS-AND-UI.md`](architecture/PERMISSIONS-TEAMS-AND-UI.md).

**Rollback:** Restore the DB backup from step 1; do not partially remove `team_id` columns without a migration.

---

## Part 1 — Deploying Central (Hestia)

### Transactional mail and DNS (password resets)

Password resets and other transactional notifications use Laravel’s mailer. Per [`docs/plans/HYBRID_AUTH_ADMIN_FIRST_PRD.md`](plans/HYBRID_AUTH_ADMIN_FIRST_PRD.md) **§0.2** and **§3.3 (PWD-2, PWD-6)**:

| Rule | Detail |
|------|--------|
| **SMTP on your domain** | Configure **`MAIL_MAILER=smtp`** against the **Agila / HestiaCP** mail server for the site’s domain (host, port **465** with SSL or **587** with STARTTLS/TLS — match the panel and provider docs). |
| **From address** | Set **`MAIL_FROM_ADDRESS`** and **`MAIL_FROM_NAME`** to a mailbox/domain you control and that passes SPF/DKIM. |
| **No third-party transactional APIs** | Do **not** use SendGrid, Mailgun, or the **Gmail API** for application password-reset or notification mail in production — align with the hybrid auth PRD. |

**DNS (deliverability):** For the **sending domain**, configure at least:

- **SPF** — authorize Hestia’s outbound mail hosts.
- **DKIM** — sign messages (Hestia usually provides the key/selector to publish).
- **DMARC** — start with `p=none` or `quarantine` while monitoring, then tighten as appropriate.

This reduces spam-folder placement when users receive resets at **Gmail** (recovery address on file).

**Google Sign-In (OAuth):** After code deploy, create an OAuth **Web client** in Google Cloud Console and set `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` (and ensure `APP_URL` matches the live HTTPS URL). Step-by-step checklist: [`docs/GOOGLE_OAUTH_AND_AGILA_HOSTING.md`](GOOGLE_OAUTH_AND_AGILA_HOSTING.md).

### Authentication posture (no public registration)

Per the same PRD: there is **no public self-registration**. Accounts are **admin-provisioned**; do not add open `/register` routes in deployments or forks without an explicit product decision.

### First deploy ever (one time only)
1. Make sure .env on the Hestia server has these set:
   SUPER_ADMIN_EMAIL=your@email.com
   SUPER_ADMIN_PASSWORD=your-strong-password
2. In Hestia panel → Run PHP, run:
   php /home/avelinht/web/flexiqueue.click/public_html/php-run-scripts/initial-setup.php
3. Log in at https://flexiqueue.click with the superadmin credentials.

### Every deploy after that (automated)
1. From your machine, run:
   ./scripts/release-central.sh vX.Y.Z
2. Wait for the FTP sync to finish.
3. The Hestia cron runs Laravel's scheduler every minute, which:
   - Picks up the deploy marker and runs migrations/config cache when present.
   - Runs initial-setup once, then self-disables via a flag file.

### Hestia cron (set once, every minute)

Set **one** cron entry on Hestia to run Laravel's scheduler:

```bash
* * * * * /usr/bin/php8.2 /home/avelinht/web/flexiqueue.click/public_html/artisan schedule:run >> /dev/null 2>&1
```

If your server uses a different PHP binary path (for example `/usr/local/bin/php8.2`), adjust the cron accordingly.  
You can verify the correct path by checking an existing working PHP cron or running `which php8.2` via SSH.

### Emergency bootstrap (site shows 500 after deploy)

If the site returns 500 after a deploy (e.g. broken config cache), run this **once** via Hestia cron or Run PHP panel, then remove the cron after the site is back up:

```bash
/usr/bin/php8.2 /home/avelinht/web/flexiqueue.click/public_html/php-run-scripts/bootstrap.php
```

This script does not require Laravel to be booted: it clears `bootstrap/cache/*.php`, then runs `artisan migrate`, `config:cache`, `route:cache`, etc. via shell. After it completes, the normal scheduler cron handles future deploys.

If the site shows 500 immediately after a deploy, also check `bootstrap/cache/` on the server:

- If `bootstrap/cache/config.php` is missing, the scheduler may not have run yet (wait 1–2 minutes).
- If `artisan` is broken due to a partial `vendor/` upload, run the emergency bootstrap script above to rebuild caches and restore `vendor/` autoloaders.

### Rollback
Re-run with an older tag:
  ./scripts/release-central.sh v1.0.0

---

## Part 2 — Building the Edge Golden Image (one time per version)

### Prerequisites (install once on your build machine)
  sudo apt install qemu-user-static qemu-utils kpartx

### Steps
1. Download Armbian base image for Orange Pi One from https://www.armbian.com/orange-pi-one/
   Get the Minimal/CLI variant. Extract it:
   xz -d Armbian_*.img.xz

2. Build the edge tarball:
   ./scripts/build-deploy-tarball-sail.sh
   mv flexiqueue-deploy.tar.gz flexiqueue-vX.Y.Z-edge.tar.gz

3. Build the golden image:
   ./scripts/pi/build-golden-image.sh \
     /path/to/armbian-base.img \
     flexiqueue-vX.Y.Z-edge.tar.gz \
     vX.Y.Z
   Output: flexiqueue-golden-vX.Y.Z.img.gz

4. Flash to SD card:
   Option A — Balena Etcher (recommended):
     Open Etcher, select flexiqueue-golden-vX.Y.Z.img.gz, select SD card, flash.
   Option B — dd:
     gunzip flexiqueue-golden-vX.Y.Z.img.gz
     sudo dd if=flexiqueue-golden-vX.Y.Z.img of=/dev/sdX bs=4M status=progress
     sync

---

## Part 3 — Setting Up an Edge Device (per Pi, after flashing)

### What you need
- A flashed SD card (from Part 2)
- The Pi connected to LAN via ethernet or WiFi
- A phone, tablet, or laptop on the same network
- A pairing code from the central admin panel

### Steps
1. Insert SD card into Pi, connect ethernet, power on.
2. Wait ~60 seconds for boot.
3. On your phone/laptop, add to /etc/hosts (use Pi's real LAN IP):
   192.168.x.x  flexiqueue.edge
   (Or add a DNS entry in Pi-hole/dnsmasq if you use one.)
4. Open https://flexiqueue.edge in your browser.
5. Accept the self-signed certificate warning once.
6. The setup wizard appears:
   - Step 1: Confirm central URL (default: https://flexiqueue.click)
   - Step 2: Enter the pairing code from central admin
   - Step 3: Select mode — Sync on the go or Sync after session
   - Step 4: Confirm — device configures itself automatically
7. Wait for program sync to complete.
8. Normal edge UI appears. Device is ready.

### Getting a pairing code
In the central app: Site settings → Edge Devices → Add device
A short code appears (e.g. ABCD-1234). It expires in 10 minutes.

### No monitor needed
The Pi runs headlessly. All setup happens in the browser.
SSH is only needed for troubleshooting, never for normal setup.

---

## Part 4 — Updating Edge Devices

### OTA update (no PC needed)
SSH into the Pi and run:
  sudo flexiqueue-update "https://github.com/daviddatuX25/flexiqueue/releases/download/vX.Y.Z/flexiqueue-vX.Y.Z-edge.tar.gz"

### LAN push from PC
  PI_HOST=flexiqueue.edge ./scripts/deploy-to-pi.sh --build

### After update
Device retains its pairing, mode, and program assignment.
No re-setup needed.

---

## Part 5 — Releasing a New Version

### Full release (central + edge)
1. Merge dev into main:
   git checkout main && git merge dev && git push origin main
2. Tag the release:
   git tag vX.Y.Z && git push origin vX.Y.Z
3. Deploy central:
   ./scripts/release-central.sh vX.Y.Z
4. Build and publish edge release:
   ./scripts/release-edge.sh vX.Y.Z
5. Update edge devices via OTA or LAN push (Part 4).

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Wizard does not appear | Check Pi is on LAN, check /etc/hosts entry, wait 60s after power on |
| Certificate warning keeps appearing | Accept it once per browser/device — this is normal for self-signed certs |
| Cron not running migrations | Verify the scheduler cron entry in Hestia exists and that `bootstrap/cache/deploy_pending` is created after deploy |
| FTP deploy fails | Check FTP_HOST, FTP_USER, FTP_PASSWORD in .env.hosting |
| Edge tarball build fails | Make sure Sail/Docker is running: ./vendor/bin/sail up -d |
| Pi not reachable at flexiqueue.edge | Check /etc/hosts has correct Pi IP, check Pi is powered and on same network |

---

