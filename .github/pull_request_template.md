## Summary

<!-- What changed and why (1–3 sentences). -->

## Testing

<!-- e.g. `php artisan test`, Playwright, manual steps. -->

## RBAC (R1) — freeze the bridge

<!-- Required reading: docs/plans/PR-CHECKLIST-RBAC-R1.md -->

- [ ] This PR does **not** add **new** authorization based only on `users.role` / enum (use `can()`, `permission:` middleware, policies).
- [ ] This PR does **not** add **new** authorization based on `program_supervisors` pivot without a documented bridge + follow-up.
- [ ] N/A — no auth, users, programs, routes, or policies touched.
