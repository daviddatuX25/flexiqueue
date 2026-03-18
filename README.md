# FlexiQueue

Queue management system for government social welfare offices (MSWDO).
Offline-first, single-node LAN deployment. COA-compliant.

## Quick links
- [Deployment guide](docs/DEPLOYMENT.md)
- [Edge device setup](scripts/pi/README.md)
- [Scripts reference](scripts/README.md)
- [Beginner deployment guide](docs/BEGINNER-DEPLOYMENT-GUIDE.md) (if exists)

## Stack
- Laravel + Inertia.js + Svelte 5
- WebSockets (Reverb on edge, Pusher on central)
- MariaDB (central) / SQLite (edge)
- PWA, offline-first

## Deployments
| Target | How |
|---|---|
| Central (flexiqueue.click) | ./scripts/release-central.sh vX.Y.Z |
| Edge (Orange Pi One) | Flash golden image → browser wizard |

## Development
```bash
# Start dev stack
./scripts/dev-start-sail.sh

# Run tests  
./vendor/bin/sail artisan test
```

## Release
```bash
git checkout main && git merge dev
git tag vX.Y.Z && git push origin vX.Y.Z
./scripts/release-central.sh vX.Y.Z
./scripts/release-edge.sh vX.Y.Z
```

