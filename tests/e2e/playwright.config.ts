import { defineConfig, devices } from '@playwright/test';

// A separate package avoids installing the unrelated Next.js starter to test PHP.
export default defineConfig({
  testDir: '.',
  globalTeardown: './teardown.ts',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1, // Shared workflow records and PHP's development server are sequential.
  retries: 0, // Do not replay irreversible feedback submissions automatically.
  forbidOnly: !!process.env.CI,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:8187',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'node server.mjs',
    cwd: __dirname,
    url: 'http://127.0.0.1:8187/index.html',
    reuseExistingServer: false,
    stderr: 'ignore',
    timeout: 90_000,
  },
});

