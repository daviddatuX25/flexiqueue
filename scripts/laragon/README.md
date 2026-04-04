# Laragon setup and deploy

Laragon is a host target for both roles:

- Central on Laragon
- Edge on Laragon

Use canonical role-first entrypoints from repo root.

## 1) Prepare Laragon once

- Ensure PHP 8.2+, Composer, Node, npm are installed.
- Ensure SSH access from your deployment machine (`LARAGON_HOST`, `LARAGON_USER`).
- Choose app path (default used by scripts): `/var/www/flexiqueue`.
- Ensure database exists when running central mode (example DB name: `flexiqueue`).

## 2) Choose env template

### Central on Laragon

1. Start from `env.laragon.central.example`
2. Copy to target `.env`
3. Set DB values:
   - `DB_DATABASE=flexiqueue` (or your chosen DB)
   - `DB_USERNAME`, `DB_PASSWORD`
4. Choose realtime mode:
   - Pusher-style vars from `env.central.pusher.example`, or
   - Reverb-style vars from `env.central.reverb.example`

### Edge on Laragon

1. Start from `env.laragon.edge.example` (or `env.edge.reverb.example`)
2. Copy to target `.env`
3. Set edge sync values:
   - `CENTRAL_URL`
   - `CENTRAL_API_KEY`
   - `SITE_ID`
4. Keep sqlite values for edge:
   - `DB_CONNECTION=sqlite`
   - `DB_DATABASE=database/database.sqlite`

## 3) Deploy commands

### Central on Laragon

```bash
LARAGON_HOST=laptop.local ./scripts/central/deploy/laragon/deploy-central-laragon.sh --build --migrate=incremental
```

### Edge on Laragon

```bash
LARAGON_HOST=laptop.local ./scripts/edge/deploy/laragon/deploy-edge-laragon.sh --build --migrate=incremental
```

## 4) Manual apply (on target)

```bash
cd /var/www/flexiqueue
sudo ./scripts/laragon/apply-tarball-central.sh /tmp/flexiqueue-deploy.tar.gz --migrate=incremental
sudo ./scripts/laragon/apply-tarball-edge.sh /tmp/flexiqueue-deploy.tar.gz --migrate=incremental
```

## 5) Post-deploy checks

- App loads at configured host URL
- `php artisan migrate:status` works
- Queue worker running (if used)
- Reverb running (if used)
- For edge: admin shows edge mode banner and sync settings are present

## Laragon + Apache + SSL + Reverb (local HTTPS)

If you use **HTTPS** on Laragon (`*.test` with SSL enabled), WebSockets must use **`wss://`** via Apache proxying **`/app`** to Reverb. Plain **`wss://localhost:6001`** will not work unless Reverb terminates TLS itself.

**Canonical guide:** [docs/SETUP-LARAGON-APACHE-REVERB-HTTPS.md](../SETUP-LARAGON-APACHE-REVERB-HTTPS.md) — SSL menu, `httpd-ssl.conf` vs site vhost, **`ProxyPass` with `ws://`**, `VITE_REVERB_VIA_PROXY=true`, verification, and troubleshooting.
