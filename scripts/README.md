# FlexiQueue scripts

Role-first script organization:

1. Choose role: `central` or `edge`
2. Choose operation: `build`, `deploy`, `release`, `setup`
3. Choose host/variant when needed: `hosting`, `pi`, `laragon`, `tar`, `golden`

Run all commands from repo root.

## Development modes

- `Sail` mode: use `./scripts/dev-start-sail.sh` and `./scripts/dev-stop-sail.sh`
- `Local/Laragon` mode: use `./scripts/dev-start-local.sh` and `./scripts/dev-stop-local.sh`
- Laragon is treated as local runtime for development, and as host target for deploy flows.

## Canonical entrypoints

### Central

- Build (hosting artifact): `./scripts/central/build/build-central-hosting.sh`
- Deploy (FTP hosting): `./scripts/central/deploy/hosting/deploy-central-ftp.sh`
- Deploy (Laragon central): `LARAGON_HOST=... ./scripts/central/deploy/laragon/deploy-central-laragon.sh --build`
- Release central: `./scripts/central/release/release-central.sh v0.1.0`

### Edge

- Build tarball (host): `./scripts/edge/build/tar/build-edge-tarball.sh`
- Build tarball (Sail): `./scripts/edge/build/tar/build-edge-tarball-sail.sh`
- Build golden image: `./scripts/edge/build/golden/build-edge-golden-image.sh --board=orangepi-one <base-img> <edge-tarball> [version]`
- Deploy edge to Pi (edge mode): `PI_HOST=... ./scripts/edge/deploy/pi/deploy-edge-pi.sh --build`
- Deploy edge to Pi (generic tar apply): `PI_HOST=... ./scripts/edge/deploy/pi/deploy-edge-pi-tar.sh --build`
- Deploy edge to Laragon: `LARAGON_HOST=... ./scripts/edge/deploy/laragon/deploy-edge-laragon.sh --build`
- Setup edge Pi: `sudo ./scripts/edge/setup/pi/setup-edge-pi.sh`
- Setup edge SSL on Pi: `sudo ./scripts/edge/setup/pi/setup-edge-ssl.sh --hostname=flexiqueue.edge`
- Release edge: `./scripts/edge/release/release-edge.sh v0.1.0`

## Realtime profile model

Realtime is profile-based (not folder-based):

- Central Pusher profile: `env.central.pusher.example`
- Central Reverb profile: `env.central.reverb.example`
- Edge Reverb profile: `env.edge.reverb.example`
- Existing edge template: `env.edge`

## Golden vs Tar decision

- Use **tar** path when deploying to an existing device over SSH/scp.
- Use **golden** path when preparing a flashable image for repeated device rollout.

## Supporting docs

- Laragon setup and apply details: `scripts/laragon/README.md`
- Pi operational and golden-image details: `scripts/pi/README.md`
- Deployment runbook: `docs/architecture/10-DEPLOYMENT.md`

## Legacy and transition

- Historical/deprecated scripts are kept under `scripts/archive/legacy/`.
- Older operation-first paths (`scripts/build`, `scripts/deploy`, `scripts/release`, root legacy names) are non-canonical.
