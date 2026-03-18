#!/usr/bin/env bash
# Build a flashable golden image for Orange Pi One (Armbian/Debian base).
# Run on a developer Linux machine. Produces flexiqueue-golden-<version>.img.gz.
#
# Usage:
#   sudo ./scripts/pi/build-golden-image.sh <base-armbian.img> <path-to-edge-tarball.tar.gz> [version]
#   version defaults to "dev" if omitted.
#
# Example:
#   sudo ./scripts/pi/build-golden-image.sh Armbian_24.5.0_orange-pi-one_bookworm.img flexiqueue-v1.0.0-edge.tar.gz v1.0.0

set -e

BASE_IMG=""
TARBALL=""
VERSION="dev"
WORK_IMG=""
ROOT_MNT=""
LOOP_DEV=""

usage() {
    echo "Usage: $0 <base-armbian.img> <path-to-edge-tarball.tar.gz> [version]"
    echo "       $0 --help"
    echo ""
    echo "  base-armbian.img   Path to base Armbian/Debian .img file"
    echo "  edge-tarball      Path to flexiqueue-vX.Y.Z-edge.tar.gz"
    echo "  version           Version string for output file (default: dev)"
    echo ""
    echo "Output: flexiqueue-golden-<version>.img.gz"
    echo "Flash: balena etcher, or: gunzip -c flexiqueue-golden-<v>.img.gz | sudo dd of=/dev/sdX bs=4M status=progress"
    exit 0
}

cleanup() {
    if [ -n "$ROOT_MNT" ] && [ -d "$ROOT_MNT" ]; then
        if mountpoint -q "$ROOT_MNT" 2>/dev/null; then
            sudo umount "$ROOT_MNT" 2>/dev/null || true
        fi
        rmdir "$ROOT_MNT" 2>/dev/null || true
        ROOT_MNT=""
    fi
    if [ -n "$LOOP_DEV" ]; then
        sudo losetup -d "$LOOP_DEV" 2>/dev/null || true
        LOOP_DEV=""
    fi
    if [ -n "$WORK_IMG" ] && [ -f "$WORK_IMG" ]; then
        rm -f "$WORK_IMG"
    fi
}
trap cleanup EXIT INT TERM

# ---- Parse args ----
for arg in "$@"; do
    if [ "$arg" = "--help" ] || [ "$arg" = "-h" ]; then
        usage
    fi
done

[ $# -lt 2 ] && echo "Error: need at least base image and tarball." && usage
BASE_IMG="$1"
TARBALL="$2"
[ -n "${3:-}" ] && VERSION="$3"

if [ ! -f "$BASE_IMG" ]; then
    echo "Error: Base image not found: $BASE_IMG"
    exit 1
fi
if [ ! -f "$TARBALL" ]; then
    echo "Error: Tarball not found: $TARBALL"
    exit 1
fi

# ---- Prerequisites ----
echo "=== Checking prerequisites ==="
for cmd in chroot losetup mount tar gzip; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "Error: Required command not found: $cmd"
        exit 1
    fi
done
echo "Prerequisites OK."

# ---- Working copy of image ----
echo "=== Copying base image (do not modify original) ==="
WORK_IMG=$(mktemp --suffix=.img)
cp "$BASE_IMG" "$WORK_IMG"

# ---- Mount image ----
echo "=== Mounting image ==="
ROOT_MNT=$(mktemp -d)
LOOP_DEV=$(sudo losetup -P -f --show "$WORK_IMG")
ROOT_PART="${LOOP_DEV}p2"
[ ! -b "$ROOT_PART" ] && ROOT_PART="${LOOP_DEV}2"
if [ ! -b "$ROOT_PART" ]; then
    echo "Error: Could not find root partition (tried ${LOOP_DEV}p2 and ${LOOP_DEV}2)."
    exit 1
fi
sudo mount "$ROOT_PART" "$ROOT_MNT"
echo "Mounted root at $ROOT_MNT"

# ---- Chroot: install packages ----
echo "=== Installing packages in chroot ==="
sudo chroot "$ROOT_MNT" /bin/bash -c '
    apt-get update -qq
    apt-get install -y -qq php8.3 php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl nginx sqlite3 curl unzip
    mkdir -p /var/www/flexiqueue
'

# ---- Extract tarball ----
echo "=== Extracting edge tarball into /var/www/flexiqueue ==="
TMP_EXTRACT=$(mktemp -d)
tar -xzf "$TARBALL" -C "$TMP_EXTRACT"
sudo cp -a "$TMP_EXTRACT"/* "$ROOT_MNT/var/www/flexiqueue/"
rm -rf "$TMP_EXTRACT"
sudo chown -R www-data:www-data "$ROOT_MNT/var/www/flexiqueue"

# ---- Chroot: Laravel and services ----
echo "=== Configuring app and services in chroot ==="
sudo chroot "$ROOT_MNT" /bin/bash -c "
    cd /var/www/flexiqueue
    sudo -u www-data php artisan key:generate --force
    ( grep -q '^APP_MODE=' .env && sed -i 's/^APP_MODE=.*/APP_MODE=edge/' .env || echo 'APP_MODE=edge' >> .env )
    ( grep -q '^DB_CONNECTION=' .env && sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env || echo 'DB_CONNECTION=sqlite' >> .env )
    ( grep -q '^DB_DATABASE=' .env && sed -i 's|^DB_DATABASE=.*|DB_DATABASE=database/database.sqlite|' .env || echo 'DB_DATABASE=database/database.sqlite' >> .env )
    sudo -u www-data php artisan migrate --force
    cp -f scripts/pi/flexiqueue-reverb.service /etc/systemd/system/
    cp -f scripts/pi/flexiqueue-queue.service /etc/systemd/system/
    systemctl enable flexiqueue-reverb flexiqueue-queue
    cp -f scripts/pi/nginx-flexiqueue.conf /etc/nginx/sites-available/flexiqueue
    ln -sf /etc/nginx/sites-available/flexiqueue /etc/nginx/sites-enabled/flexiqueue
"
# Do NOT set CENTRAL_URL, CENTRAL_API_KEY, or EDGE_BRIDGE_MODE (wizard sets these)

# ---- Unmount and detach ----
echo "=== Unmounting ==="
sudo umount "$ROOT_MNT"
rmdir "$ROOT_MNT"
ROOT_MNT=""
sudo losetup -d "$LOOP_DEV"
LOOP_DEV=""
trap - EXIT INT TERM

# ---- Compress ----
echo "=== Compressing output ==="
OUTPUT_NAME="flexiqueue-golden-${VERSION}.img.gz"
gzip -c "$WORK_IMG" > "$OUTPUT_NAME"
rm -f "$WORK_IMG"
WORK_IMG=""

echo ""
echo "=== Done ==="
echo "Output: $OUTPUT_NAME"
echo ""
echo "Flash instructions:"
echo "  - Using Balena Etcher: select $OUTPUT_NAME (gunzip on the fly if needed)."
echo "  - Using dd: gunzip -c $OUTPUT_NAME | sudo dd of=/dev/sdX bs=4M status=progress"
echo "  (Replace /dev/sdX with your SD card device.)"
echo ""
