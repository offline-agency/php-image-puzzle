import { test as setup } from '@playwright/test';
import { AUTHOR_STATE } from './state.js';

// Filament throttles logins (five per minute): sign in once and reuse the session.
setup('sign in as the Acme author', async ({ page }) => {
  await page.goto('admin/login');
  await page.locator('[id="form.email"]').fill('author@example.test');
  await page.locator('[id="form.password"]').fill('password');
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL(/\/admin\/acme$/);
  await page.context().storageState({ path: AUTHOR_STATE });
});
