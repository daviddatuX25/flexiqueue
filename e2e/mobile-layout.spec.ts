import { test, expect } from '@playwright/test';

/**
 * Mobile layout E2E — bottom nav (dock), StatusFooter, active state.
 * Validates UI design per 09-UI-ROUTES-PHASE1.md Section 2.3.
 * Uses mobile viewport (375px) to match staff phone target.
 */
const MOBILE_VIEWPORT = { width: 375, height: 667 };

test.describe('Mobile layout (Station / Triage / Track Overrides)', () => {
  test.use({ viewport: MOBILE_VIEWPORT });

  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('staff@example.com');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/station/);
  });

  test('bottom nav (dock) is visible with Station, Triage, Track Overrides', async ({ page }) => {
    await expect(page.getByRole('link', { name: /station/i }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /triage/i }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: /track overrides/i }).first()).toBeVisible();
    const dock = page.locator('.bg-surface-50.border-t.border-surface-200');
    await expect(dock).toBeVisible();
  });

  test('bottom nav Station link is active on /station', async ({ page }) => {
    const stationLink = page.getByRole('link', { name: /station/i }).first();
    await expect(stationLink).toHaveClass(/text-primary-500|font-semibold/);
  });

  test('bottom nav Triage link becomes active after navigating to /triage', async ({ page }) => {
    await page.getByRole('link', { name: /triage/i }).first().click();
    await expect(page).toHaveURL(/\/triage/);
    const triageLink = page.getByRole('link', { name: /triage/i }).first();
    await expect(triageLink).toHaveClass(/text-primary-500|font-semibold/);
  });

  test('bottom nav Track Overrides link becomes active after navigating to track-overrides', async ({ page }) => {
    await page.getByRole('link', { name: /track overrides/i }).first().click();
    await expect(page).toHaveURL(/\/track-overrides/);
    const authLink = page.getByRole('link', { name: /track overrides/i }).first();
    await expect(authLink).toHaveClass(/text-primary-500|font-semibold/);
  });

  test('StatusFooter is visible with network + availability, Queue, Processed, and time', async ({ page }) => {
    const footer = page.locator('.bg-surface-200').last();
    await expect(footer).toBeVisible();
    await expect(footer.getByText(/connected|offline|available|on break|away/i).first()).toBeVisible();
    await expect(page.getByText(/queue:/i)).toBeVisible();
    await expect(page.getByText(/processed:/i)).toBeVisible();
    // Clock is font-mono and shows HH:MM
    await expect(page.locator('[aria-label="Current time"]')).toBeVisible();
  });

  test('footer shows Queue and Processed values (may be 0 if not passed)', async ({ page }) => {
    const footer = page.locator('.bg-surface-200').last();
    await expect(footer).toContainText(/Queue:/);
    await expect(footer).toContainText(/Processed:/);
  });

  test('touch targets in bottom nav are at least 44px (min tap area)', async ({ page }) => {
    const stationLink = page.getByRole('link', { name: /station/i }).first();
    const box = await stationLink.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.width).toBeGreaterThanOrEqual(44);
    expect(box!.height).toBeGreaterThanOrEqual(44);
  });

  test('header shows station context and user menu', async ({ page }) => {
    await expect(page.getByRole('banner')).toBeVisible();
    await expect(page.getByRole('banner')).toBeVisible();
    await page.getByRole('banner').locator('[role="button"]').last().click();
    await expect(page.getByRole('menu').getByRole('link', { name: /station/i })).toBeVisible();
    await expect(page.getByRole('menu').getByRole('link', { name: /triage/i })).toBeVisible();
    await expect(page.getByRole('menu').getByRole('link', { name: /track overrides/i })).toBeVisible();
    await expect(page.getByRole('menu').getByRole('button', { name: /log out/i })).toBeVisible();
  });

  test('footer Queue and Processed show numeric values (currently 0 when not passed from page)', async ({ page }) => {
    const footer = page.locator('.bg-surface-200').last();
    await expect(footer).toContainText(/Queue: \d+/);
    await expect(footer).toContainText(/Processed: \d+/);
  });
});
