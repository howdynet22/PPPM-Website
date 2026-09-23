import { test as base, expect, Page, APIRequestContext } from '@playwright/test';

export const test = base.extend<{ browserErrors: string[] }>({
  browserErrors: [async ({ page }, use) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    await use(errors);
    expect(errors, 'Uncaught browser JavaScript errors').toEqual([]);
  }, { auto: true }],
});
export { expect };
export const password = 'password123';
export async function api(request: APIRequestContext, action: string, data?: object, status = 200, token?: string) {
  if (data && token === undefined && action !== 'login') {
    const me = await request.get('/api.php?action=me');
    token = (await me.json()).csrfToken;
  }
  const response = data
    ? await request.post(`/api.php?action=${action}`, { data, headers: { 'X-CSRF-Token': token || '' } })
    : await request.get(`/api.php?action=${action}`);
  const body = await response.json();
  expect(response.status(), `${action}: ${JSON.stringify(body)}`).toBe(status);
  expect(body.ok, action).toBe(status < 400);
  return body;
}
export async function login(page: Page, name = 'alex', path?: string) {
  await page.goto('/index.html');
  await page.getByLabel('Email Address', { exact: true }).fill(`${name}@demo.pppm.test`);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign In', exact: true }).click();
  await expect(page).toHaveURL(/dashboard\.html/);
  if (path) await page.goto(`/${path}`);
  await expect(page.locator('html')).toHaveAttribute('data-authenticated', 'true');
  if (page.url().includes('employee-dashboard')) {
    await expect(page.locator('#employeeContent')).toHaveAttribute('aria-busy', 'false');
    await expect(page.locator('#employeeMessage')).toBeEmpty();
  } else if (page.url().includes('manager-dashboard')) {
    await expect(page.locator('#kpiTeam')).toHaveText(/\d+/);
  } else if (page.url().includes('hr-dashboard')) {
    await expect(page.locator('#kpiHeadcount')).toHaveText(/\d+/);
  } else if (page.url().includes('admin-dashboard')) {
    await expect(page.locator('#adminSync')).toHaveText('System data loaded.');
  }
}
export async function navigate(page: Page, section: string) {
  await page.locator(`.sidebar [data-page="${section}"]`).click();
  await expect(page.locator(`#page-${section}`)).toBeVisible();
}
export async function downloadCsv(page: Page, selector: string) {
  const download = page.waitForEvent('download');
  await page.locator(selector).click();
  const file = await download;
  expect(file.suggestedFilename()).toMatch(/\.csv$/);
  expect(await file.failure()).toBeNull();
  const stream = await file.createReadStream();
  const chunks: Buffer[] = [];
  for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
  const csv = Buffer.concat(chunks).toString('utf8');
  expect(csv.trim().split(/\r?\n/).length).toBeGreaterThan(1);
  return csv;
}
