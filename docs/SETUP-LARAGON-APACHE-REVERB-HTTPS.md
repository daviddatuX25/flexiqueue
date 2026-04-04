# Laragon + Apache + SSL + Laravel Reverb (HTTPS)

Use this when you run FlexiQueue on **Laragon** with **HTTPS** and need **broadcasting / Echo / Reverb** to work in the browser.

## Why this exists

- Pages loaded over **`https://`** require **secure WebSockets (`wss://`)**. The browser will not use plain **`ws://`** to another host/port (mixed content).
- `php artisan reverb:start` listens for **plain WebSocket** on **`127.0.0.1:6001`** (no TLS on that port). So **`wss://localhost:6001`** will fail unless you add TLS to Reverb itself (extra setup).
- **Robust approach (recommended):** terminate SSL on **Apache**, proxy **`/app`** to Reverb with **`ws://`** to `127.0.0.1:6001`, and set **`VITE_REVERB_VIA_PROXY=true`** so the browser connects to **`wss://your-site.test/app/...`** on port **443** (same origin as the page).

This matches production patterns (e.g. Nginx `location /app` → Reverb) and avoids maintaining certificates on the Reverb process.

---

## 1) Enable SSL in Laragon

1. Open **Laragon** (tray icon).
2. **Menu → Apache → SSL**.
3. Use **Enabled** (or follow Laragon’s SSL wizard) so your `*.test` site can be served on **HTTPS** (port **443**).

The global file **Menu → Apache → SSL → `httpd-ssl.conf`** sets things like `Listen 443`, cipher suites, and session cache. That file is **normal**; it is **not** where you add the Reverb proxy (see step 3).

---

## 2) Apache modules (once)

In **Menu → Apache → httpd.conf**, ensure these modules are **loaded** (uncomment `LoadModule` lines if needed):

- `mod_proxy`
- `mod_proxy_http`
- **`mod_proxy_wstunnel`** (needed for WebSocket upgrades)

Reload Apache.

Verify from a terminal:

```text
httpd.exe -M | findstr proxy
```

You should see `proxy_module`, `proxy_http_module`, and `proxy_wstunnel_module`.

---

## 3) Site vhost: proxy `/app` with **`ws://`** (required)

Edit the **auto vhost** for your site, e.g.:

`C:\laragon\etc\apache2\sites-enabled\auto.flexiqueue.test.conf`

(or the file Laragon generates for your `ServerName`).

You should have **`define ROOT`**, **`define SITE`**, **`<VirtualHost *:80>`**, and **`<VirtualHost *:443>`** with `SSLEngine on` and Laragon’s cert paths.

Inside **`<VirtualHost *:443>`**, **after** `SSLCertificateKeyFile` (and **before** `</VirtualHost>`), add:

```apache
    ProxyRequests Off
    ProxyPreserveHost On

    ProxyPass        /app ws://127.0.0.1:6001/app
    ProxyPassReverse /app ws://127.0.0.1:6001/app
```

**Critical:** use **`ws://`** in `ProxyPass`, **not** `http://`. Plain `http://` to Reverb often forwards a **normal HTTP GET** without a proper WebSocket upgrade; Reverb then errors (e.g. `PusherController` type mismatch between HTTP and WebSocket connections).

If needed, you can use a block instead:

```apache
    <Location /app>
        ProxyPass ws://127.0.0.1:6001/app
        ProxyPassReverse ws://127.0.0.1:6001/app
    </Location>
```

Reload Apache.

---

## 4) Application `.env` (Laragon + HTTPS + proxy)

Set at least:

| Variable | Purpose |
|----------|---------|
| `APP_URL` | `https://flexiqueue.test` (match your `SITE` / browser URL) |
| `VITE_REVERB_VIA_PROXY` | `true` — Echo uses same host as the page (`wss://flexiqueue.test/...`), not `:6001` |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | Where Reverb **binds** (e.g. `0.0.0.0` and `6001`) |
| `REVERB_APP_KEY` / `REVERB_APP_ID` / `REVERB_APP_SECRET` | Must match; `VITE_REVERB_APP_KEY` should mirror the key for the frontend build |

Keep **`REVERB_SCHEME=http`** for server-side defaults to the Reverb **process**; TLS is handled by Apache, not by port 6001.

After changing **`VITE_*`**, restart **`npm run dev`** (or rebuild) so Vite embeds the new values.

See **`.env.example`** for commented defaults and a Laragon HTTPS block.

---

## 5) Run Reverb (and optional dev stack)

From the project root:

```bash
php artisan reverb:start
# optional:
php artisan reverb:start --debug
```

To run Vite + Reverb + queue together (no Sail), use:

```bash
./scripts/dev-start-local.sh
```

Apache still serves the Laravel app; this script only starts **Vite**, **Reverb**, and the **queue** worker.

---

## 6) Verify

1. **Port:** `netstat -an | findstr 6001` — should show `LISTENING` on `0.0.0.0:6001` (or similar).
2. **Browser:** DevTools → Network → **WS** — the socket URL should be **`wss://flexiqueue.test/app/...`** (port **443**), **not** `wss://localhost:6001`.
3. **Reverb debug:** With `reverb:start --debug`, you should see WebSocket activity; you should **not** see the HTTP-only error where `PusherController` receives an **HTTP** connection type for `/app`.

---

## Troubleshooting (short)

| Symptom | Likely cause |
|--------|----------------|
| `wss://localhost:6001` failed | Direct TLS to Reverb not configured; use **proxy** + `VITE_REVERB_VIA_PROXY=true` instead. |
| `PusherController` / `Http\Connection` vs WebSocket `Connection` | **`ProxyPass` used `http://`** or Upgrade not forwarded; switch to **`ws://`** as above. |
| Echo still wrong URL | **`VITE_REVERB_VIA_PROXY`** not `true`, or **Vite not restarted** after `.env` change. |
| 502 / proxy errors | Reverb not running, or wrong host/port in `ProxyPass`. |

---

## Reference

- Nginx equivalent (Pi / production): `scripts/pi/nginx-flexiqueue-ssl.conf` (`location /app` → `127.0.0.1:6001`).
- Laravel Reverb docs: [Running Reverb in production](https://laravel.com/docs/reverb#production) (reverse proxy; `/app` and `/apps`).
