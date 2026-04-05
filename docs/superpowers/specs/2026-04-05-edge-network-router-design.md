# Edge Network Router — Phase 1 Design

**Date:** 2026-04-05
**Project:** FlexiQueue Edge Mode — Network Router Feature
**Scope:** Phase E10 (or next milestone)
**Status:** Design — pending implementation plan

---

## 1. Problem Statement

FlexiQueue edge devices (Orange Pi Zero 3 and similar) currently operate as **WiFi/LAN clients only** — they connect to an existing venue network and rely on that network for internet uplink. There is no support for:

- Acting as a router between an uplink (WiFi STA or USB 4G ethernet) and a downstream LAN
- Providing DHCP to clients when the venue has no DHCP server
- Transparent bridging when an uplink DHCP server is available
- NAT fallback when no uplink DHCP is received

This limits deployment scenarios: staff must bring their own AP/switch, or the venue must provide DHCP on a wired segment.

---

## 2. Goals

1. Edge device connects to venue WiFi as uplink (STA mode) — SSID/password set at **build time**
2. USB ethernet port provides downstream LAN to an external AP/switch — clients connect to that AP
3. **Bridge mode** (default): Pi bridges `wlan0 ↔ eth0` transparently; clients get IPs from venue DHCP server
4. **NAT fallback**: if uplink DHCP fails within 30s, Pi runs `dnsmasq` on `eth0`, hands out `192.168.8.x`, NATs traffic to `wlan0`
5. Zero new PHP/Laravel code — purely shell scripts baked into the golden image

---

## 3. Architecture

```
                         Orange Pi Zero 3
                    ┌──────────────────────┐
                    │                      │
  Venue WiFi ◄─── wlan0 │  Linux routing/   │ eth0 ◄── USB ethernet
 (uplink, STA)          │  bridge + NAT      │         │
                        │                    │         ▼
                        │  dnsmasq (fallback)│  External AP/switch
                        │  DHCP server       │  (downstream LAN)
                        └──────────────────────┘
                                      │
                              Clients (phones, tablets)
```

**Data flow — Bridge mode (uplink DHCP available):**
```
Client → External AP → eth0 → br0 (bridge) → wlan0 → Venue WiFi → Internet
```
No IP routing; Pi acts as a transparent layer-2 bridge. Clients get IPs from venue router.

**Data flow — NAT fallback (no uplink DHCP within 30s):**
```
Client → External AP → eth0 (dnsmasq DHCP 192.168.8.x) → NAT → wlan0 (uplink) → Internet
```
Pi acts as router/NAT. `dnsmasq` assigns `192.168.8.50–200`.

---

## 4. Components

### 4.1 Per-Board Network Profiles

**Location:** `scripts/pi/network/boards/`

Each board has a shell profile with hardware-specific defaults.

**`scripts/pi/network/boards/orangepi-zero3.sh`:**
```bash
#!/usr/bin/env bash
BOARD_NAME="orangepi-zero3"
UPLINK_IFACE="wlan0"           # built-in WiFi → uplink (STA)
LAN_IFACE="eth0"                # USB ethernet → LAN (downstream)
WIFI_COUNTRY="PH"              # Philippines
DHCP_RANGE="192.168.8.50,192.168.8.200,12h"
DHCP_GW="192.168.8.1"
NAT_FALLBACK_TIMEOUT=30
```

**`scripts/pi/network/boards/orangepi-one.sh`:**
```bash
#!/usr/bin/env bash
BOARD_NAME="orangepi-one"
UPLINK_IFACE="wlan0"
LAN_IFACE="eth0"
WIFI_COUNTRY="PH"
DHCP_RANGE="192.168.8.50,192.168.8.200,12h"
DHCP_GW="192.168.8.1"
NAT_FALLBACK_TIMEOUT=30
```

Board profiles are sourced by `setup-network.sh`. Adding a new board = adding a new file.

### 4.2 `setup-network.sh` — Main Script

**Location:** `scripts/pi/network/setup-network.sh`

Run at boot via `flexiqueue-network.service`. Reads board profile, configures interfaces.

**Flow:**

```bash
#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BOARD_PROFILE="${BOARD_PROFILE:-$(cat /etc/flexiqueue/board-name 2>/dev/null || echo unknown)}"

source "$SCRIPT_DIR/boards/${BOARD_PROFILE}.sh"

# Step 1: Connect WiFi uplink via nmcli
nmcli con add type wifi con-name fq-uplink \
  ifname "$UPLINK_IFACE" ssid "$WIFI_SSID"
nmcli con modify fq-uplink wifi-sec.psk "$WIFI_PASS"
nmcli con modify fq-uplink wifi.country "$WIFI_COUNTRY"
nmcli con modify fq-uplink ipv4.method auto
nmcli con up fq-uplink

# Step 2: Wait for uplink DHCP (up to NAT_FALLBACK_TIMEOUT seconds)
UPLINK_IP=""
for i in $(seq 1 $NAT_FALLBACK_TIMEOUT); do
  UPLINK_IP=$(ip -4 addr show "$UPLINK_IFACE" | grep -oP 'inet \K[\d.]+' | head -1)
  [ -n "$UPLINK_IP" ] && break
  sleep 1
done

# Step 3a: Bridge mode — uplink DHCP received
if [ -n "$UPLINK_IP" ]; then
  ip link add br0 type bridge
  ip link set "$UPLINK_IFACE" master br0
  ip link set "$LAN_IFACE" master br0
  ip link set br0 up
  # Allow IPv4 forwarding for bridge
  echo 1 > /proc/sys/net/ipv4/ip_forward
  echo "[fq-network] Bridge mode active (uplink: $UPLINK_IP)"

# Step 3b: NAT fallback — no uplink DHCP
else
  ip addr add 192.168.8.1/24 dev "$LAN_IFACE"
  ip link set "$LAN_IFACE" up

  # dnsmasq DHCP (no DNS port, only DHCP on LAN)
  cat > /etc/dnsmasq.d/fq-lan.conf <<EOF
interface=$LAN_IFACE
dhcp-range=$DHCP_RANGE
dhcp-option=3,$DHCP_GW
dhcp-option=6,8.8.8.8,8.8.4.4
port=53
EOF

  systemctl enable dnsmasq
  systemctl restart dnsmasq

  # NAT from LAN to uplink
  iptables -t nat -A POSTROUTING -o "$UPLINK_IFACE" -j MASQUERADE
  iptables -A FORWARD -i "$LAN_IFACE" -o "$UPLINK_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT
  iptables -A FORWARD -i "$LAN_IFACE" -o "$UPLINK_IFACE" -j ACCEPT
  echo 1 > /proc/sys/net/ipv4/ip_forward

  echo "[fq-network] NAT fallback mode (LAN: 192.168.8.1)"
fi
```

### 4.3 dnsmasq Template

**Location:** `scripts/pi/network/dnsmasq-lan.conf` (copied to `/etc/dnsmasq.d/fq-lan.conf` at runtime)

```dnsmasq
interface={{LAN_IFACE}}
dhcp-range={{DHCP_RANGE}}
dhcp-option=3,{{DHCP_GW}}
dhcp-option=6,8.8.8.8,8.8.4.4
port=53
log-queries
log-dhcp
```

### 4.4 Systemd Service

**Location:** `scripts/pi/network/flexiqueue-network.service`

```ini
[Unit]
Description=FlexiQueue Edge Network Router
After=network.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/var/www/flexiqueue/scripts/pi/network/setup-network.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
```

### 4.5 Board Name Marker

**Location:** `/etc/flexiqueue/board-name` inside the golden image (written at build time)

The golden image build writes the board name to this file so the service knows which profile to load at boot:

```bash
echo "orangepi-zero3" > "$CHROOT_DIR/etc/flexiqueue/board-name"
```

---

## 5. Golden Image Integration

In `scripts/pi/build-golden-image.sh`, after extracting the app and before `enable_services()`:

```bash
# Copy network scripts
cp -r "$SCRIPT_DIR/network" "$CHROOT_DIR/var/www/flexiqueue/scripts/pi/network"

# Write board name marker
mkdir -p "$CHROOT_DIR/etc/flexiqueue"
echo "$BOARD" > "$CHROOT_DIR/etc/flexiqueue/board-name"

# Copy systemd service
cp "$SCRIPT_DIR/network/flexiqueue-network.service" "$CHROOT_DIR/etc/systemd/system/"
```

WiFi uplink credentials injected at build time via env vars:

```bash
WIFI_SSID="MyVenueWiFi" WIFI_PASS="venuepass" \
  ./scripts/edge/build/golden/build-edge-golden-image.sh \
    --board=orangepi-zero3 \
    Armbian_H618.img \
    flexiqueue.tar.gz
```

---

## 6. File Structure

```
scripts/pi/
├── build-golden-image.sh                    # modified: copy network scripts, write board name
├── network/
│   ├── setup-network.sh                     # main boot script
│   ├── dnsmasq-lan.conf                    # dnsmasq DHCP template
│   ├── flexiqueue-network.service           # systemd unit
│   └── boards/
│       ├── orangepi-zero3.sh                # H618 (AArch64)
│       └── orangepi-one.sh                  # H5 (ARMv7)
```

---

## 7. Error Handling

| Scenario | Behavior |
|----------|----------|
| WiFi SSID not found / wrong password | `nmcli` retries indefinitely; no fallback triggers — clients on LAN have no internet until STA reconnects |
| USB ethernet unplugged at boot | NAT fallback does not activate (no `eth0`); bridge mode cannot bring up `eth0`; Pi continues without downstream LAN |
| Uplink restored after NAT fallback | Bridge mode does not auto-recover until next boot; logged as `[fq-network] uplink restored, reboot for bridge mode` |
| Both links fail | Pi runs local-only; FlexiQueue works locally, Reverb queues locally |
| `dnsmasq` fails to start | Script exits with error; systemd logs show failure; `/var/log/syslog` captures |

---

## 8. Not in Scope (Phase 2)

- Web UI for changing WiFi uplink credentials at runtime (Laravel-backed network manager)
- ZeroTier integration with AP mode (link-local VPN over AP LAN)
- AP mode using Pi's built-in WiFi (requires `hostapd`, single-radio limitation)
- Automatic uplink switching (WiFi ↔ USB 4G ethernet)
- mDNS/bonjour relay across bridge

---

## 9. Testing Plan

1. **Bridge mode test:** Connect Pi to a router with DHCP; verify clients behind external AP get venue router IPs
2. **NAT fallback test:** Connect Pi to a network with no DHCP on the uplink; verify clients get `192.168.8.x` IPs
3. **Uplink recovery test:** Connect Pi to WiFi, then unplug; verify clients can still reach the Pi and local FlexiQueue
4. **Golden image test:** Flash image to SD, boot Pi; verify network starts automatically without SSH
5. **Multi-board test:** Verify both `orangepi-zero3` and `orangepi-one` profiles load correctly on their respective hardware
