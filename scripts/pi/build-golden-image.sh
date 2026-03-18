#!/usr/bin/env bash

############################################
# FlexiQueue Pi: Golden Image Builder
#
# Builds a flashable "golden image" for FlexiQueue edge deployments.
#
# To add a new board:
# 1. Add a case entry in the BOARD_CONFIG section.
# 2. Set QEMU_BINARY, PHP_VERSION, ARCH_LABEL.
# 3. Test with a base image for that board.
# 4. Update the --help output.
############################################

set -euo pipefail

############################################
# SECTION 0: GLOBALS & ARG PARSING
############################################

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

BOARD="orangepi-one"
BASE_IMG=""
EDGE_TARBALL=""
VERSION=""

QEMU_BINARY=""
PHP_VERSION=""
ARCH_LABEL=""

USE_KPARTX=0
LOOPDEV=""
ROOT_MAPPER=""
BOOT_MAPPER=""
WORK_DIR=""
CHROOT_DIR=""

usage() {
  cat <<EOF
Usage: $(basename "$0") [--board=<name>] <base-img> <edge-tarball> [version]

Boards:
  orangepi-one  (default)

Example:
  ./$(basename "$0") --board=orangepi-one armbian.img flexiqueue-v1.0.0-edge.tar.gz v1.0.0
EOF
}

parse_args() {
  for arg in "$@"; do
    case "$arg" in
      --help|-h)
        usage
        exit 0
        ;;
      --board=*)
        BOARD="${arg#--board=}"
        ;;
      *)
        # positional; handled after loop
        ;;
    esac
  done

  # Strip options and leave positionals
  local positional=()
  for arg in "$@"; do
    case "$arg" in
      --board=*|--help|-h)
        ;;
      *)
        positional+=("$arg")
        ;;
    esac
  done

  if [ "${#positional[@]}" -lt 2 ] || [ "${#positional[@]}" -gt 3 ]; then
    echo "ERROR: Invalid arguments." >&2
    usage
    exit 1
  fi

  BASE_IMG="${positional[0]}"
  EDGE_TARBALL="${positional[1]}"
  VERSION="${positional[2]:-}"
  if [ -z "$VERSION" ]; then
    VERSION="$(date +%Y%m%d-%H%M%S)"
  fi
}

############################################
# SECTION 1: PREREQUISITES CHECK
############################################

check_prereqs() {
  echo "=== Checking prerequisites ==="

  local cmds=(losetup chroot tar gzip dd qemu-arm-static qemu-aarch64-static)
  local has_kpartx=0
  local has_partx=0

  if command -v kpartx >/dev/null 2>&1; then
    has_kpartx=1
  fi
  if command -v partx >/dev/null 2>&1; then
    has_partx=1
  fi

  if [ "$has_kpartx" -eq 0 ] && [ "$has_partx" -eq 0 ]; then
    echo "ERROR: Neither 'kpartx' nor 'partx' is available; one of them is required." >&2
    exit 1
  fi

  for c in "${cmds[@]}"; do
    if ! command -v "$c" >/dev/null 2>&1; then
      echo "ERROR: Required command '$c' not found in PATH." >&2
      exit 1
    fi
  done

  # qemu binaries checked after BOARD_CONFIG selects which one
}

############################################
# SECTION 11: BOARD CONFIGURATION
############################################

configure_board() {
  case "$BOARD" in
    orangepi-one)
      QEMU_BINARY="qemu-arm-static"
      PHP_VERSION="8.3"
      ARCH_LABEL="armv7"
      ;;
    raspberrypi|orangepi-zero)
      echo "Board '$BOARD' is not yet supported. Supported: orangepi-one" >&2
      exit 1
      ;;
    *)
      echo "Board '$BOARD' is not recognized. Supported: orangepi-one" >&2
      exit 1
      ;;
  esac

  if ! command -v "$QEMU_BINARY" >/dev/null 2>&1; then
    # Try explicit path for typical qemu-user-static installs
    if [ ! -x "/usr/bin/$QEMU_BINARY" ]; then
      echo "ERROR: Required QEMU binary '$QEMU_BINARY' not found (expected at /usr/bin/$QEMU_BINARY)." >&2
      exit 1
    fi
  fi
}

############################################
# SECTION 2: BASE IMAGE MOUNT
############################################

cleanup() {
  set +e

  if [ -n "${CHROOT_DIR:-}" ] && [ -d "$CHROOT_DIR" ]; then
    for mp in dev/pts dev sys proc; do
      if mountpoint -q "$CHROOT_DIR/$mp"; then
        umount "$CHROOT_DIR/$mp" || true
      fi
    done

    if [ -n "${QEMU_BINARY:-}" ] && [ -f "$CHROOT_DIR/usr/bin/$QEMU_BINARY" ]; then
      rm -f "$CHROOT_DIR/usr/bin/$QEMU_BINARY" || true
    fi
  fi

  if [ -n "${CHROOT_DIR:-}" ] && [ -d "$CHROOT_DIR" ]; then
    if [ -n "${BOOT_MAPPER:-}" ] && mountpoint -q "$CHROOT_DIR/boot"; then
      umount "$CHROOT_DIR/boot" || true
    fi
    if [ -n "${ROOT_MAPPER:-}" ] && mountpoint -q "$CHROOT_DIR"; then
      umount "$CHROOT_DIR" || true
    fi
  fi

  if [ "$USE_KPARTX" -eq 1 ] && [ -n "${LOOPDEV:-}" ]; then
    kpartx -d "$LOOPDEV" 2>/dev/null || true
  elif [ -n "${LOOPDEV:-}" ]; then
    partx -d "$LOOPDEV" 2>/dev/null || true
  fi

  if [ -n "${LOOPDEV:-}" ]; then
    losetup -d "$LOOPDEV" 2>/dev/null || true
  fi

  if [ -n "${WORK_DIR:-}" ] && [ -d "$WORK_DIR" ]; then
    rm -rf "$WORK_DIR" || true
  fi
}
trap cleanup EXIT

setup_workdirs_and_image() {
  echo "=== Preparing working image ==="

  if [ ! -f "$BASE_IMG" ]; then
    echo "ERROR: Base image '$BASE_IMG' does not exist." >&2
    exit 1
  fi
  if [ ! -f "$EDGE_TARBALL" ]; then
    echo "ERROR: Edge tarball '$EDGE_TARBALL' does not exist." >&2
    exit 1
  fi

  WORK_DIR="$(mktemp -d -p "${TMPDIR:-/tmp}" flexiqueue-golden-build-XXXXXX)"
  CHROOT_DIR="$WORK_DIR/chroot"
  mkdir -p "$CHROOT_DIR"

  local working_img="$WORK_DIR/working.img"
  echo "Copying base image to working image: $working_img"
  cp --reflink=auto "$BASE_IMG" "$working_img" 2>/dev/null || cp "$BASE_IMG" "$working_img"

  echo "Attaching loop device..."
  LOOPDEV="$(losetup --find --show "$working_img")"
  echo "Loop device: $LOOPDEV"

  if command -v kpartx >/dev/null 2>&1; then
    echo "Using kpartx to map partitions..."
    USE_KPARTX=1
    kpartx -avs "$LOOPDEV" >/tmp/kpartx-output.$$ 2>&1

    local base loopname p1 p2
    base="$(basename "$LOOPDEV")"
    loopname="$base"
    p1="/dev/mapper/${loopname}p1"
    p2="/dev/mapper/${loopname}p2"

    if [ -e "$p2" ]; then
      ROOT_MAPPER="$p2"
      BOOT_MAPPER="$p1"
    elif [ -e "$p1" ]; then
      ROOT_MAPPER="$p1"
      BOOT_MAPPER=""
    else
      echo "ERROR: Could not find partition mappings under /dev/mapper for $LOOPDEV." >&2
      echo "kpartx output:" >&2
      cat /tmp/kpartx-output.$$ >&2 || true
      exit 1
    fi
  else
    echo "Using partx to map partitions..."
    USE_KPARTX=0
    partx -a "$LOOPDEV"

    local p1 p2
    p1="${LOOPDEV}p1"
    p2="${LOOPDEV}p2"
    if [ -e "$p2" ]; then
      ROOT_MAPPER="$p2"
      BOOT_MAPPER="$p1"
    elif [ -e "$p1" ]; then
      ROOT_MAPPER="$p1"
      BOOT_MAPPER=""
    else
      echo "ERROR: Could not find partition devices ${LOOPDEV}p1/2." >&2
      exit 1
    fi
  fi

  echo "Root partition: $ROOT_MAPPER"
  if [ -n "$BOOT_MAPPER" ]; then
    echo "Boot partition: $BOOT_MAPPER"
  else
    echo "Boot partition: (none / single-partition image)"
  fi

  echo "Mounting root filesystem at $CHROOT_DIR..."
  mount "$ROOT_MAPPER" "$CHROOT_DIR"

  if [ -n "$BOOT_MAPPER" ]; then
    mkdir -p "$CHROOT_DIR/boot"
    echo "Mounting boot filesystem at $CHROOT_DIR/boot..."
    mount "$BOOT_MAPPER" "$CHROOT_DIR/boot"
  fi
}

############################################
# SECTION 3: QEMU STATIC BINARY FOR ARM CHROOT
############################################

setup_chroot_env() {
  echo "=== Setting up chroot environment (${ARCH_LABEL}) ==="

  local qemu_src="/usr/bin/$QEMU_BINARY"
  if [ ! -x "$qemu_src" ]; then
    qemu_src="$(command -v "$QEMU_BINARY")"
  fi

  if [ -z "$qemu_src" ] || [ ! -x "$qemu_src" ]; then
    echo "ERROR: QEMU binary '$QEMU_BINARY' not found or not executable." >&2
    exit 1
  fi

  mkdir -p "$CHROOT_DIR/usr/bin"
  cp "$qemu_src" "$CHROOT_DIR/usr/bin/$QEMU_BINARY"

  mount --bind /proc "$CHROOT_DIR/proc"
  mount --bind /sys "$CHROOT_DIR/sys"
  mount --bind /dev "$CHROOT_DIR/dev"
  mkdir -p "$CHROOT_DIR/dev/pts"
  mount --bind /dev/pts "$CHROOT_DIR/dev/pts"
}

chroot_exec() {
  chroot "$CHROOT_DIR" /usr/bin/env -i \
    HOME=/root \
    LANG=C.UTF-8 \
    PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    DEBIAN_FRONTEND=noninteractive \
    "$@"
}

############################################
# SECTION 4: PACKAGE INSTALL INSIDE CHROOT
############################################

install_packages_inside_chroot() {
  echo "=== Installing base packages inside chroot ==="

  local php="php${PHP_VERSION}"
  local pkgs=(
    "$php"
    "${php}-fpm"
    "${php}-sqlite3"
    "${php}-mbstring"
    "${php}-xml"
    "${php}-curl"
    "${php}-zip"
    "${php}-bcmath"
    "${php}-intl"
    nginx
    sqlite3
    curl
    unzip
  )

  chroot_exec bash -c "apt-get update"
  chroot_exec bash -c "apt-get install -y --no-install-recommends ${pkgs[*]}"
}

############################################
# SECTION 5: APP EXTRACTION
############################################

extract_app_inside_chroot() {
  echo "=== Extracting FlexiQueue app into chroot ==="

  local target="/var/www/flexiqueue"
  chroot_exec mkdir -p "$target"

  local chroot_tar="/tmp/flexiqueue-edge.tar"
  cp "$EDGE_TARBALL" "$CHROOT_DIR$chroot_tar"

  chroot_exec bash -c "cd '$target' && tar xf '$chroot_tar'"
  chroot_exec rm -f "$chroot_tar"

  chroot_exec chown -R www-data:www-data "$target"
}

############################################
# SECTION 6: LARAVEL SETUP INSIDE CHROOT
############################################

setup_laravel_inside_chroot() {
  echo "=== Setting up Laravel inside chroot ==="

  local app_root="/var/www/flexiqueue"

  chroot_exec bash -c "cd '$app_root' && if [ ! -f .env ] && [ -f .env.example ]; then cp .env.example .env; fi"
  chroot_exec bash -c "cd '$app_root' && if [ ! -f .env ]; then touch .env; fi"

  chroot_exec bash -c "cd '$app_root' && \
    env_file='.env'; \
    ensure_var() { \
      local k=\"\$1\" v=\"\$2\"; \
      if grep -q \"^\\\$k=\" \"\$env_file\"; then \
        sed -i \"s|^\\\$k=.*|\\\$k=\\\$v|\" \"\$env_file\"; \
      else \
        echo \"\\\$k=\\\$v\" >> \"\$env_file\"; \
      fi; \
    }; \
    ensure_var APP_MODE edge; \
    ensure_var DB_CONNECTION sqlite; \
    ensure_var APP_URL https://flexiqueue.edge; \
    ensure_var DB_DATABASE database/database.sqlite"

  chroot_exec bash -c "cd '$app_root' && mkdir -p database && touch database/database.sqlite"
  chroot_exec bash -c "cd '$app_root' && mkdir -p storage bootstrap/cache"

  chroot_exec bash -c "cd '$app_root' && chown -R www-data:www-data storage database bootstrap/cache"
  chroot_exec bash -c "cd '$app_root' && chmod -R u+rwX,g+rwX storage database bootstrap/cache"

  chroot_exec bash -c "cd '$app_root' && php artisan key:generate --force"
  chroot_exec bash -c "cd '$app_root' && php artisan migrate --force"
  chroot_exec bash -c "cd '$app_root' && php artisan config:cache"
  chroot_exec bash -c "cd '$app_root' && php artisan storage:link || true"
}

############################################
# SECTION 7: SSL SETUP INSIDE CHROOT
############################################

setup_ssl_inside_chroot() {
  echo "=== Configuring SSL and Nginx inside chroot ==="

  local app_root="/var/www/flexiqueue"
  local fq_hostname="flexiqueue.edge"
  local src_conf="$SCRIPT_DIR/nginx-flexiqueue-ssl.conf"
  local setup_script_host="$SCRIPT_DIR/setup-ssl.sh"
  local setup_script_chroot="$CHROOT_DIR$app_root/scripts/pi/setup-ssl.sh"

  if [ ! -f "$src_conf" ]; then
    echo "ERROR: Nginx SSL config '$src_conf' not found." >&2
    exit 1
  fi

  # Ensure the latest SSL nginx config and setup script are present inside the image
  mkdir -p "$CHROOT_DIR$app_root/scripts/pi"
  cp "$src_conf" "$CHROOT_DIR$app_root/scripts/pi/nginx-flexiqueue-ssl.conf"
  if [ -f "$setup_script_host" ]; then
    cp "$setup_script_host" "$setup_script_chroot"
    chmod +x "$setup_script_chroot"
  fi

  # Ensure APP_URL uses HTTPS inside the image
  chroot_exec bash -c "cd '$app_root' && \
    env_file='.env'; \
    if [ -f \"\$env_file\" ]; then \
      if grep -q '^APP_URL=' \"\$env_file\"; then \
        sed -i 's|^APP_URL=.*|APP_URL=https://$fq_hostname|' \"\$env_file\"; \
      else \
        echo 'APP_URL=https://$fq_hostname' >> \"\$env_file\"; \
      fi; \
    fi"

  # Run SSL setup script inside chroot non-interactively; it will:
  # - generate self-signed certs under /etc/nginx/ssl
  # - install nginx-flexiqueue-ssl.conf and enable it via sites-enabled
  # - update APP_URL and cache config
  chroot_exec bash -c "cd '$app_root' && FQ_HOSTNAME='$fq_hostname' ./scripts/pi/setup-ssl.sh --no-reload"
}

############################################
# SECTION 8: SYSTEMD SERVICES INSIDE CHROOT
############################################

enable_services() {
  echo "=== Enabling systemd services in image ==="

  local systemd_dir="$CHROOT_DIR/etc/systemd/system"
  mkdir -p "$systemd_dir"

  cp "$SCRIPT_DIR/flexiqueue-reverb.service" "$systemd_dir/flexiqueue-reverb.service"
  cp "$SCRIPT_DIR/flexiqueue-queue.service" "$systemd_dir/flexiqueue-queue.service"

  local php_fpm_unit="php${PHP_VERSION}-fpm"

  systemctl --root="$CHROOT_DIR" enable \
    flexiqueue-reverb.service \
    flexiqueue-queue.service \
    nginx.service \
    "$php_fpm_unit".service
}

############################################
# SECTION 9: CLEANUP AND OUTPUT IMAGE
############################################

finalize_image() {
  echo "=== Finalizing golden image ==="

  local output_name="flexiqueue-golden-$VERSION.img"
  local output_gz="${output_name}.gz"
  local working_img="$WORK_DIR/working.img"

  if [ ! -f "$working_img" ]; then
    echo "ERROR: Working image '$working_img' not found before finalization." >&2
    exit 1
  fi

  cp "$working_img" "./$output_name"
  echo "Compressing image to $output_gz ..."
  gzip -9 -f "./$output_name"

  if [ ! -f "$output_gz" ]; then
    echo "ERROR: Failed to create compressed image '$output_gz'." >&2
    exit 1
  fi

  local size
  size="$(du -h "$output_gz" | awk '{print $1}')"

  echo ""
  echo "Golden image created: $output_gz"
  echo "Size: $size"
  echo ""

  echo "Flashing instructions:"
  echo ""
  echo "Option A (Balena Etcher):"
  echo "  1. Open Balena Etcher."
  echo "  2. Select $output_gz as the image."
  echo "  3. Select the target SD card."
  echo "  4. Click 'Flash' and wait for completion."
  echo ""
  echo "Option B (dd):"
  echo "  gunzip $output_gz"
  echo "  sudo dd if=$output_name of=/dev/sdX bs=4M status=progress"
  echo "  sync"
  echo ""
}

############################################
# MAIN
############################################

main() {
  parse_args "$@"
  check_prereqs
  configure_board
  setup_workdirs_and_image
  setup_chroot_env
  install_packages_inside_chroot
  extract_app_inside_chroot
  setup_laravel_inside_chroot
  setup_ssl_inside_chroot
  enable_services
  finalize_image

  echo "Build complete."
}

main "$@"

