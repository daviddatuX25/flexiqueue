import { test, expect } from '@playwright/test';

/**
 * Auth E2E — plain Playwright (no @hyvor/laravel-playwright).
 * DB is seeded once via globalSetup before tests run.
 * Run with --headed to see the browser.
 */
test.describe('Auth', () => {
  test('login page loads and shows sign in form', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByText(/sign in with your email/i)).toBeVisible();
    await expect(page.getByLabel(/email/i)).toBeVisible();
    await expect(page.getByLabel(/password/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /sign in/i })).toBeVisible();
  });

  test('valid login redirects admin to admin dashboard', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/admin\/dashboard/);
    await expect(page.getByText(/admin dashboard/i)).toBeVisible();
  });

  test('valid login redirects staff to station page', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('staff@example.com');
    await page.getByLabel(/password/i).fill('password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/station/);
  });

  test('invalid credentials show error and stay on login', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel(/password/i).fill('wrong-password');
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL('/login');
    await expect(page.getByText(/invalid|credentials|error/i)).toBeVisible();
  });
});
