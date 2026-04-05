# FlexiQueue Edge — Testing Checklist

> Updated after each E-phase milestone. Physical Pi required for all groups unless noted.

## Before You Start

- [ ] Commit uncommitted E11 work — `git add` the files above and commit `feat(e11): SSH toggle + SQLCipher + kiosk scripts`
- [ ] Deploy dev to your central server (`git pull` on server, `php artisan migrate`, `npm run build`)
- [ ] Flash or update your Pi with the new `build-golden-image.sh` (or `git pull` + restart services on existing Pi)
- [ ] Pi is on the same network as your central server (or ZeroTier connected)
- [ ] ZeroTier is running on both Pi and dev machine (if testing remotely)

---

## Group 1 — Initial Pairing (E2–E3) — do this first

- [ ] Open central admin → generate pairing code for a site
- [ ] On Pi, navigate to `/edge/setup` — enter the code + central URL
- [ ] Confirm Pi shows the waiting page (`/edge/waiting`)
- [ ] In central admin, assign a program to the edge device
- [ ] Pi transitions from waiting → active kiosk view

---

## Group 2 — Edge Kiosk Lockdown (E9 + E11)

- [ ] Pi browser is fullscreen (no address bar, no tabs)
- [ ] Login page on Pi hides Google OAuth and Forgot Password links
- [ ] Attempting to POST to admin routes on Pi → blocked by write protection
- [ ] Pi shows admin pages in read-only mode (Edit/Delete buttons hidden)

---

## Group 3 — Package Import & Versioning (E4–E5, E7)

- [ ] Central: export a program package (dump)
- [ ] Pi: import the package via `/edge/import`
- [ ] Pi: `/edge/sync` page shows import status = complete with correct `package_version`
- [ ] Update the program on central, re-export → Pi `/edge/sync` shows `package_stale` warning banner

---

## Group 4 — Sync Page (E10)

- [ ] Navigate to `/edge/sync` on Pi
- [ ] Shows correct device info (name, site, status)
- [ ] Re-sync button triggers sync + shows progress
- [ ] Sync history table populates after sync completes
- [ ] EdgeModeBanner at top shows "Sync details" link pointing to `/edge/sync`

---

## Group 5 — Batch Sync (E6)

- [ ] Run an exam session on Pi (queue data builds up)
- [ ] Trigger sync → confirm data appears on central
- [ ] Simulate network failure → confirm retry logic works (E6.4)
- [ ] Check `edge_sync_receipts` table on central for receipt records

---

## Group 6 — Update Available (E8)

- [ ] Deploy a new version to central
- [ ] Wait for Pi heartbeat → Pi banner should show "Update available"
- [ ] After Pi updates: banner disappears

---

## Group 7 — Revoke / Re-pair (E9.4)

- [ ] In central admin: revoke the edge device
- [ ] Pi heartbeat picks up revocation → redirects to `/edge/revoked` page
- [ ] Re-pair same Pi (new pairing code) → `is_revoked` clears → Pi works again

---

## Group 8 — SSH Toggle (E11)

- [ ] On Pi's `/edge/sync` page, click "Enable SSH"
- [ ] SSH becomes accessible on the Pi for 30 minutes
- [ ] After 30 min (or test with a short timer): SSH access closes automatically
- [ ] Clicking "Enable SSH" when `enable-ssh.sh` is missing → shows 503 error message

---

## Group 9 — SQLCipher (E11)

- [ ] Pi database file is encrypted (confirm `pi.db | grep SQLite` returns nothing — it's opaque)
- [ ] App reads/writes normally through the encrypted DB
- [ ] Reboot Pi → app resumes correctly (key derived from `APP_KEY`)

---

## Group 10 — Edge Network Router (E12)

> Requires physical Pi with WiFi uplink + USB ethernet adapter.

### Proxy ARP Mode (uplink DHCP available)

- [ ] Pi connects to venue WiFi (`nmcli con up fq-uplink` succeeds)
- [ ] `journalctl -u flexiqueue-network` shows `[fq-network] Proxy ARP mode active (uplink: X.X.X.X)`
- [ ] Client behind external AP/switch gets venue router IP (not `192.168.8.x`)
- [ ] Client can ping `8.8.8.8` and load FlexiQueue in browser

### NAT Fallback Mode (no uplink DHCP)

- [ ] Disconnect uplink / connect to WiFi with no DHCP server
- [ ] After 30s timeout, `journalctl -u flexiqueue-network` shows `[fq-network] NAT fallback mode (LAN: 192.168.8.1)`
- [ ] Client gets `192.168.8.50–200` from `dnsmasq` DHCP
- [ ] Client can ping `8.8.8.8` and load FlexiQueue in browser
- [ ] Second boot: no duplicate iptables rules (idempotency check)

### Service Lifecycle

- [ ] Missing `/etc/flexiqueue/wifi.conf` → service fails with readable error in `journalctl -u flexiqueue-network`
- [ ] Missing `/etc/flexiqueue/board-name` → service fails with readable error
- [ ] `systemctl status flexiqueue-network` shows `active (exited)` after boot

### Multi-Board

- [ ] `orangepi-zero3` board profile loads without error
- [ ] `orangepi-one` board profile loads without error (identical behavior as expected)

---

## What Requires the Pi Specifically

Everything in Groups 1–10 needs the physical Pi (or a Pi emulator). The SSH toggle (Group 8) needs sudo access and the `enable-ssh.sh` script in place. SQLCipher (Group 9) only works if you ran `build-golden-image.sh` which compiles the PHP SQLCipher extension. Network Router (Group 10) needs parprouted + dnsmasq + iptables installed and a USB ethernet adapter on the Pi.

---

## Quick Local Smoke Test (no Pi needed)

```bash
php artisan test        # already passing: 1202/1202
npm run build           # check for any frontend errors
```
