# Central scripts

Use this folder when your target role is **central**.

## Structure

- `build/` - build central artifacts
- `deploy/hosting/` - deploy central to hosting/FTP
- `deploy/laragon/` - deploy central to a Laragon host
- `release/` - central release flow

## Quick commands

```bash
# Build central hosting artifact
./scripts/central/build/build-central-hosting.sh

# Deploy central to hosting (FTP/lftp path)
./scripts/central/deploy/hosting/deploy-central-ftp.sh

# Deploy central to Laragon host
LARAGON_HOST=laptop.local ./scripts/central/deploy/laragon/deploy-central-laragon.sh --build

# Release central
./scripts/central/release/release-central.sh v0.1.0
```

## Realtime profile

Central realtime is profile-based:

- Pusher profile: `env.central.pusher.example`
- Reverb profile: `env.central.reverb.example`
