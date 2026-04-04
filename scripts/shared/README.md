# Shared scripts

Shared scripts are reusable helpers used by role-specific entrypoints.
Do not start from this folder unless a role entrypoint explicitly tells you to.

## Files

- `deploy-laragon.sh` - shared deploy logic for Laragon host with `--mode=central|edge`
- `git-worktree.sh` - shared worktree helper bridge
- `build-central-hosting.sh` - shared central hosting build implementation
- `build-edge-tarball.sh` - shared edge tarball build implementation
- `build-edge-tarball-sail.sh` - shared edge tarball Sail build implementation
- `deploy-edge-pi-tar.sh` - shared edge tar deploy implementation
- `deploy-edge-pi-mode.sh` - shared edge mode deploy implementation
- `release-central.sh` - shared central release implementation
- `release-edge.sh` - shared edge release implementation

## Typical usage

Usually invoked via:

- `scripts/central/deploy/laragon/deploy-central-laragon.sh`
- `scripts/edge/deploy/laragon/deploy-edge-laragon.sh`
