# Edge scripts

Use this folder when your target role is **edge**.

## Structure

- `build/tar/` - build deploy tarball for edge updates
- `build/golden/` - build flashable golden image
- `deploy/pi/` - deploy edge to Pi over SSH/scp
- `deploy/laragon/` - deploy edge to Laragon host
- `setup/pi/` - first-time Pi setup helpers
- `release/` - edge release publishing

## Decide first: Tar vs Golden

- **Tar**: existing device, remote update (`scp + ssh apply`)
- **Golden**: new/multiple devices, SD image rollout

## Quick commands

```bash
# Build edge tar
./scripts/edge/build/tar/build-edge-tarball.sh
./scripts/edge/build/tar/build-edge-tarball-sail.sh

# Build edge golden image
./scripts/edge/build/golden/build-edge-golden-image.sh --board=orangepi-one <base-img> <edge-tarball> [version]

# Deploy edge to Pi
PI_HOST=flexiqueue.edge ./scripts/edge/deploy/pi/deploy-edge-pi.sh --build

# Deploy generic tar path to Pi
PI_HOST=flexiqueue.edge ./scripts/edge/deploy/pi/deploy-edge-pi-tar.sh --build

# Deploy edge to Laragon
LARAGON_HOST=laptop.local ./scripts/edge/deploy/laragon/deploy-edge-laragon.sh --build

# Pi setup
sudo ./scripts/edge/setup/pi/setup-edge-pi.sh
sudo ./scripts/edge/setup/pi/setup-edge-ssl.sh --hostname=flexiqueue.edge

# Release edge
./scripts/edge/release/release-edge.sh v0.1.0
```

## Realtime profile

Edge default profile: `env.edge.reverb.example` (and `env.edge` when used).
