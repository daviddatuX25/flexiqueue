# One-time deploy: Truncate temporary_authorizations

When deploying the **Scope approval, QR rename, phase-out, and Track overrides** refactor (temp PIN/QR phase-out):

Run a one-time **`TRUNCATE temporary_authorizations`** (e.g. via Artisan tinker or manual SQL). No migration is required. The app is in dev mode and no production data needs preserving.

Example (Artisan tinker):

```bash
./vendor/bin/sail artisan tinker
>>> DB::statement('TRUNCATE temporary_authorizations');
>>> exit
```

Or via MySQL/MariaDB client:

```sql
TRUNCATE temporary_authorizations;
```

Do not forget this step when rolling out the phase-out.
