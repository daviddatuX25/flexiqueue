import { execSync } from 'node:child_process';
import { resolve } from 'node:path';

/**
 * Runs migrate:fresh --seed before E2E tests. Execute via Sail when using Sail.
 */
export default async function globalSetup() {
  const cwd = resolve(process.cwd());
  try {
    execSync('php artisan migrate:fresh --seed --force', {
      cwd,
      stdio: 'inherit',
    });
  } catch {
    // Fallback: may be running outside Sail, try sail exec
    try {
      execSync('./vendor/bin/sail artisan migrate:fresh --seed --force', {
        cwd,
        stdio: 'inherit',
      });
    } catch (e) {
      console.warn('globalSetup: Could not run migrations. Ensure Sail is up and DB is ready.');
      throw e;
    }
  }
}
