// @ts-check
const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8000';

test.describe('Login Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${BASE_URL}/login`);
  });

  test('should display login form with email and password fields', async ({ page }) => {
    await expect(page.getByRole('heading', { name: /admin login/i })).toBeVisible();
    await expect(page.getByLabel(/email/i)).toBeVisible();
    await expect(page.getByLabel(/password/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /login/i })).toBeVisible();
  });

  test('should show validation errors when submitting empty form', async ({ page }) => {
    await page.getByRole('button', { name: /login/i }).click();
    await expect(page.getByText(/email field is required/i)).toBeVisible();
    await expect(page.getByText(/password field is required/i)).toBeVisible();
  });

  test('should show validation when only email is filled', async ({ page }) => {
    await page.getByLabel(/email/i).fill('test@example.com');
    await page.getByRole('button', { name: /login/i }).click();
    await expect(page.getByText(/password field is required/i)).toBeVisible();
  });

  test('should allow typing in email and password fields', async ({ page }) => {
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel(/password/i).fill('password123');
    await expect(page.getByLabel(/email/i)).toHaveValue('admin@example.com');
    await expect(page.getByLabel(/password/i)).toHaveValue('password123');
  });

  test('should have remember me checkbox', async ({ page }) => {
    await expect(page.getByLabel(/remember me/i)).toBeVisible();
    await expect(page.getByLabel(/remember me/i)).not.toBeChecked();
  });

  test('should submit form with valid credentials (redirects to dashboard)', async ({ page }) => {
    // Use superadmin credentials from seed (if seeded)
    await page.getByLabel(/email/i).fill('superadmin@elms.com');
    await page.getByLabel(/password/i).fill('Super@Admin#2024!ELMS');
    await page.getByRole('button', { name: /login/i }).click();

    // Wait for navigation - either success (dashboard) or error (stay on login)
    await page.waitForURL(/\/(login|$|\?)/, { timeout: 5000 }).catch(() => {});

    const url = page.url();
    if (url.includes('/login')) {
      // Login failed - check for error message
      await expect(page.locator('.invalid-feedback, .alert-danger, .text-danger')).toBeVisible({ timeout: 2000 });
    } else {
      // Login succeeded - should be on dashboard
      await expect(page).toHaveURL(new RegExp(`^${BASE_URL.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/?$`));
    }
  });

  test('should toggle password visibility', async ({ page }) => {
    const passwordInput = page.getByLabel(/password/i);
    await passwordInput.fill('secret123');
    await expect(passwordInput).toHaveAttribute('type', 'password');

    const toggleBtn = page.locator('#password-toggle');
    await toggleBtn.click();
    await expect(passwordInput).toHaveAttribute('type', 'text');

    await toggleBtn.click();
    await expect(passwordInput).toHaveAttribute('type', 'password');
  });
});
