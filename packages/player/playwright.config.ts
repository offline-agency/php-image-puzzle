import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env['PORT'] ?? 4173);

/*
 * Browser matrix: desktop Chromium, Firefox and WebKit plus touch-emulated
 * mobile Chromium (Pixel 7) and WebKit (iPhone 15). Tests run against the
 * built dist files through a static server, like a consumer page.
 */
export default defineConfig({
  testDir: 'e2e',
  fullyParallel: true,
  forbidOnly: process.env['CI'] !== undefined,
  retries: 0,
  reporter: process.env['CI'] !== undefined ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}/`,
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    { name: 'mobile-chromium', use: { ...devices['Pixel 7'] } },
    { name: 'mobile-webkit', use: { ...devices['iPhone 15'] } },
  ],
  webServer: {
    command: `node e2e/server.mjs`,
    url: `http://127.0.0.1:${port}/examples/player/index.html`,
    reuseExistingServer: process.env['CI'] === undefined,
    env: { PORT: String(port) },
  },
});
