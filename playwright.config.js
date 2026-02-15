// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E config for FlexiQueue (Laravel + Inertia + Svelte).
 * App must be running (Sail up + npm run dev or built assets).
 * Run: ./vendor/bin/sail npx playwright test
 * To see the browser: run from host (not Sail) with --headed, e.g. npx playwright test --headed
 *   (Sail/Docker has no display; headed needs a real X/display)
 * Report: sail npm run test:e2e:report then open http://localhost:9323 (uses --host 0.0.0.0)
 */
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  fullyParallel: false, // serial so DB state is predictable
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: 'html',
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost',
    trace: 'on-first-retry',
    headless: true, // Sail/Docker has no display. For headed: run from host with --headed
    video: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
