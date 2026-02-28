# Orange Pi setup helpers

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
