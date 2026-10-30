import { defineConfig, devices } from '@playwright/test';
import { AUTHOR_STATE } from './e2e/state.js';

const port = Number(process.env['PORT'] ?? 8124);

/*
 * End-to-end tests of the Filament integration in a real panel: the Testbench
 * workbench (two teams, a Puzzle resource and a dashboard widget), rebuilt and
 * seeded before the server starts. Requires composer install in this directory
 * and npm ci at the repository root.
 */
export default defineConfig({
  testDir: 'e2e',
  // One seeded database and one session store: run serially.
  workers: 1,
  forbidOnly: process.env['CI'] !== undefined,
  retries: 0,
  timeout: 60_000,
  reporter: process.env['CI'] !== undefined ? [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}/`,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    ...(
      [
        ['chromium', 'Desktop Chrome'],
        ['firefox', 'Desktop Firefox'],
        ['webkit', 'Desktop Safari'],
      ] as const
    ).map(([name, device]) => ({
      name,
      dependencies: ['setup'],
      use: { ...devices[device], storageState: AUTHOR_STATE },
    })),
  ],
  webServer: {
    command: `php vendor/bin/testbench workbench:build && php vendor/bin/testbench serve --host=127.0.0.1 --port=${port}`,
    url: `http://127.0.0.1:${port}/admin/login`,
    reuseExistingServer: process.env['CI'] === undefined,
    timeout: 120_000,
  },
});
