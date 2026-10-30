import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env['PORT'] ?? 8123);

/*
 * End-to-end tests of the Livewire component in a real Laravel application:
 * the Testbench workbench (composer serve) with the example definitions.
 * Requires composer install in this directory and npm ci at the repository root.
 */
export default defineConfig({
  testDir: 'e2e',
  // One session store and one save slot per puzzle: run serially.
  workers: 1,
  forbidOnly: process.env['CI'] !== undefined,
  retries: 0,
  reporter: process.env['CI'] !== undefined ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}/`,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
  webServer: {
    command: `php vendor/bin/testbench serve --host=127.0.0.1 --port=${port}`,
    url: `http://127.0.0.1:${port}/`,
    reuseExistingServer: process.env['CI'] === undefined,
    timeout: 60_000,
  },
});
