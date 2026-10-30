import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env['PORT'] ?? 8125);
const dist = process.env['CONSUMER_DIST'];
if (dist === undefined) {
  throw new Error('Set CONSUMER_DIST to the built consumer app; tools/js-consumer/run.sh does this.');
}

/* Browser checks of the React and Vue adapters as installed from the packed tarballs. */
export default defineConfig({
  testDir: '.',
  forbidOnly: process.env['CI'] !== undefined,
  retries: 0,
  reporter: 'list',
  use: { baseURL: `http://127.0.0.1:${port}/`, trace: 'retain-on-failure' },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],
  webServer: {
    command: `node ../serve.mjs "${dist}" ${port}`,
    url: `http://127.0.0.1:${port}/`,
    reuseExistingServer: false,
  },
});
