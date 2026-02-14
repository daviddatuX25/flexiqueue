import { test, expect } from '@playwright/test';

/**
 * Station page E2E — staff sees queue UI, Call Next, etc.
 * Requires: staff with assigned station, active program (seeded).
 */
test.describe('Station', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('staff@example.com');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/station/);
  });

  test('station page shows queue UI with no client active', async ({ page }) => {
    await expect(page.getByText(/no client active/i)).toBeVisible();
    await expect(page.getByText(/queue is empty|call next client/i)).toBeVisible();
  });

  test('station page shows station name in header', async ({ page }) => {
    await expect(page.getByText(/verification/i).first()).toBeVisible();
  });
});
