import { test, expect } from '@playwright/test';

/**
 * Admin Program Show — Manage steps modal (realtime: add and reorder reflect without closing).
 * Assumes DB seeded with E2E Test Program, Regular track, Verification + Interview + Cashier stations; 2 steps (Verification, Interview).
 * Run: ./vendor/bin/sail npx playwright test e2e/admin-program-steps.spec.ts
 */
test.describe('Admin Program Steps', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard/);
  });

  test('manage steps modal updates in place when adding a step', async ({ page }) => {
    await page.goto('/admin/programs');
    await expect(page.getByRole('heading', { name: 'Programs' })).toBeVisible();
    await page.getByRole('link', { name: /E2E Test Program/i }).click();
    await expect(page).toHaveURL(/\/admin\/programs\/\d+/);

    await page.getByRole('button', { name: /Manage steps/i }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText(/Steps: Regular/)).toBeVisible();
    const stepList = dialog.locator('ul li');
    await expect(stepList.filter({ hasText: 'Verification' })).toBeVisible();
    await expect(stepList.filter({ hasText: 'Interview' })).toBeVisible();

    const initialCount = await dialog.locator('ul li').count();
    expect(initialCount).toBe(2);

    await dialog.getByLabel(/Station/i).selectOption({ label: 'Cashier' });
    await dialog.getByRole('button', { name: 'Add' }).click();

    await expect(stepList).toHaveCount(3, { timeout: 5000 });
    await expect(stepList.filter({ hasText: 'Cashier' })).toBeVisible();
  });

  test('manage steps modal updates in place when reordering', async ({ page }) => {
    await page.goto('/admin/programs');
    await page.getByRole('link', { name: /E2E Test Program/i }).click();
    await expect(page).toHaveURL(/\/admin\/programs\/\d+/);

    await page.getByRole('button', { name: /Manage steps/i }).first().click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    const list = dialog.locator('ul li');
    await expect(list.first()).toBeVisible({ timeout: 5000 });
    const count = await list.count();
    expect(count).toBeGreaterThanOrEqual(2);
    const firstRow = list.first();
    const firstStationName = await firstRow.locator('.flex-1').textContent();
    const secondRow = list.nth(1);
    const secondStationName = await secondRow.locator('.flex-1').textContent();
    await firstRow.getByRole('button', { name: /Move down|↓/ }).click();

    await expect(list.first()).toContainText(secondStationName ?? '', { timeout: 3000 });
    await expect(list.nth(1)).toContainText(firstStationName ?? '');

    await dialog.locator('button[aria-label="Close"]').click();
    await expect(dialog).toBeHidden({ timeout: 3000 });
    await expect(page.getByRole('button', { name: /Manage steps/i })).toBeVisible({ timeout: 5000 });
  });
});
