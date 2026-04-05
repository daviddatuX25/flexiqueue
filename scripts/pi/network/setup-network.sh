#!/usr/bin/env bash
set -euo pipefail

# Source board profile (BOARD_PROFILE env var or fallback to /etc/flexiqueue/board-name)
BOARD_NAME="${BOARD_PROFILE:-$(cat /etc/flexiqueue/board-name 2>/dev/null || echo "")}"
if [ -z "$BOARD_NAME" ]; then
  echo "[fq-network] ERROR: BOARD_PROFILE not set and /etc/flexiqueue/board-name not found" >&2
  exit 1
fi

# shellcheck source=/dev/null
source "/var/www/flexiqueue/scripts/pi/network/boards/${BOARD_NAME}.sh"

# Source WiFi credentials
# shellcheck source=/dev/null
source /etc/flexiqueue/wifi.conf

# Step 1 — WiFi uplink via nmcli
nmcli con delete fq-uplink 2>/dev/null || true
nmcli con add type wifi con-name fq-uplink ifname "$UPLINK_IFACE" ssid "$WIFI_SSID"
nmcli con modify fq-uplink wifi-sec.key-mgmt wpa-psk
nmcli con modify fq-uplink wifi-sec.psk "$WIFI_PASS"
nmcli con modify fq-uplink wifi.country "$WIFI_COUNTRY"
nmcli con modify fq-uplink ipv4.method auto
if ! nmcli con up fq-uplink; then
  echo "[fq-network] WARN: uplink association failed, will retry in NAT fallback mode" >&2
fi

# Step 2 — Poll uplink DHCP (up to NAT_FALLBACK_TIMEOUT seconds)
UPLINK_IP=""
for i in $(seq 1 "$NAT_FALLBACK_TIMEOUT"); do
  UPLINK_IP=$(ip -4 addr show "$UPLINK_IFACE" | grep -oP 'inet \K[\d.]+' | head -1)
  [ -n "$UPLINK_IP" ] && break
  sleep 1
done

# Step 3a — Proxy ARP mode (if UPLINK_IP is non-empty)
if [ -n "$UPLINK_IP" ]; then
  echo 1 > /proc/sys/net/ipv4/ip_forward
  echo 1 > /proc/sys/net/ipv4/conf/"$UPLINK_IFACE"/proxy_arp
  echo 1 > /proc/sys/net/ipv4/conf/"$LAN_IFACE"/proxy_arp
  ip link set "$LAN_IFACE" up
  parprouted "$UPLINK_IFACE" "$LAN_IFACE" &
  wait $!
  echo "[fq-network] Proxy ARP mode active (uplink: $UPLINK_IP)"

# Step 3b — NAT fallback (if no UPLINK_IP within timeout)
else
  ip addr add "${DHCP_GW}/24" dev "$LAN_IFACE"
  ip link set "$LAN_IFACE" up

  cat > /etc/dnsmasq.d/fq-lan.conf <<EOF
interface=$LAN_IFACE
dhcp-range=$DHCP_RANGE
dhcp-option=3,$DHCP_GW
dhcp-option=6,8.8.8.8,8.8.4.4
port=53
log-queries
log-dhcp
EOF

  systemctl enable dnsmasq
  systemctl restart dnsmasq

  echo 1 > /proc/sys/net/ipv4/ip_forward
  iptables -t nat -F POSTROUTING
  iptables -F FORWARD
  iptables -t nat -A POSTROUTING -o "$UPLINK_IFACE" -j MASQUERADE
  iptables -A FORWARD -i "$LAN_IFACE" -o "$UPLINK_IFACE" -j ACCEPT
  iptables -A FORWARD -i "$UPLINK_IFACE" -o "$LAN_IFACE" -m state --state RELATED,ESTABLISHED -j ACCEPT

  echo "[fq-network] NAT fallback mode (LAN: ${DHCP_GW})"
fi
