# Laragon / Laptop deployment

Deploy FlexiQueue to a Laragon host or laptop using the same tarball and apply flow as the Pi. The **prod-as-staging** flow: merge to prod and push, build from prod worktree, then scp + run apply script on the target.

## Prepare the target (once)

- **App directory:** Create the app root (e.g. `/var/www/flexiqueue` on WSL, or your Laragon www path). The deploy script will scp the tarball and run `scripts/laragon/apply-tarball.sh` there.
- **SSH:** Target must be reachable via SSH (e.g. OpenSSH on Windows, or WSL). Use `LARAGON_USER` if not root.
- **PHP:** PHP 8.2+ with required extensions (sqlite3, mbstring, xml, curl, zip, bcmath, intl). Laragon provides this.
- **Reverb (WebSocket):** Run `php artisan reverb:start` or install as a service (e.g. systemd on WSL). For same-origin proxy, configure Nginx or your web server to proxy `/app` to `127.0.0.1:6001` (see [scripts/pi/nginx-flexiqueue.conf](../pi/nginx-flexiqueue.conf)).
- **Queue worker (TTS and other queued jobs):** For token/station TTS pre-generation and any queued jobs, the queue worker must be running. Run `php artisan queue:work --tries=3` in a separate terminal, or on WSL with systemd copy `scripts/pi/flexiqueue-queue.service` to `/etc/systemd/system/`, then `sudo systemctl enable --now flexiqueue-queue`. Apply-tarball restarts `flexiqueue-queue` if the unit is present.

## Deploy from your PC

```bash
# From repo root: merge current branch into prod, build from prod, deploy to Laragon host
LARAGON_HOST=laptop.local ./scripts/deploy-to-laragon.sh --build

# Optional: skip merge (e.g. already on prod), or set migrate option
LARAGON_HOST=192.168.1.10 ./scripts/deploy-to-laragon.sh --build --no-merge --migrate=incremental
```

You will see: **"Merged to prod and pushed. Tarball built from prod."** before the tarball is copied to the target.

## Apply tarball on the target (manual)

If you already have the tarball on the target (e.g. copied by hand):

```bash
cd /var/www/flexiqueue   # or set LARAGON_APP_DIR
sudo ./scripts/laragon/apply-tarball.sh /path/to/flexiqueue-deploy.tar.gz [--migrate=incremental|fresh|skip]
```

Default is `--migrate=incremental`. Use `LARAGON_APP_DIR=/path` and `RUN_USER=www-data` (or your user) if different from defaults.

## See also

- [scripts/README.md](../README.md) — Quick reference and all script types
- [docs/architecture/10-DEPLOYMENT.md](../../docs/architecture/10-DEPLOYMENT.md) — Full deployment runbook
