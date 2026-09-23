import { test, expect, login, api, navigate, password } from './helpers';

// API calls arrange cross-role state; browser assertions verify the rendered outcome.
test('peer nomination: evidence, rejection, escalation, HR override and peer submission', async ({ page, browser }) => {
  await login(page, 'alex', 'employee-dashboard.html#feedback');
  await page.locator('[data-nominate-peer]').click();
  const form = page.locator('#peerNominationForm');
  await form.locator('[name=peerId]').selectOption({ label: (await form.locator('[name=peerId] option').allTextContents()).find(text => text.includes('Blair Hayes'))! });
  await form.locator('[name=sharedWork]').fill('Playwright release verification');
  await form.locator('[name=collaborationDetails]').fill('We planned and verified the complete release together, including delivery checks and handover.');
  await form.locator('[name=reviewerJustification]').fill('Blair directly observed my planning, communication and delivery throughout this shared release.');
  await form.locator('[name=directKnowledgeConfirmed]').check();
  await form.getByRole('button', { name: 'Send nomination to manager' }).click();
  const nomination = page.locator('.nomination-item').filter({ hasText: 'Playwright release verification' });
  await expect(nomination).toContainText('Pending');
  const personal = (await api(page.request, 'workspace&scope=employee')).data;
  const record = personal.nominations.find((n: any) => n.sharedWork === 'Playwright release verification');
  expect(record).toBeTruthy();
  const managerContext = await browser.newContext();
  const hrContext = await browser.newContext();
  const peerContext = await browser.newContext();
  try {
    const manager = await managerContext.newPage();
    await login(manager, 'casey');
    await api(manager.request, 'decide_peer', { id: record.id, status: 'rejected', reason: 'No' }, 422);
    await api(manager.request, 'decide_peer', { id: record.id, status: 'rejected', reason: 'Please provide further evidence covering the complete review period.' });
    await page.reload();
    await nomination.getByRole('button', { name: 'Forward decision to HR' }).click();
    await page.locator('dialog[open] textarea').fill('Blair observed the whole delivery period and directly verified every milestone in the release.');
    await page.locator('dialog[open] button[type=submit]').click();
    await expect(nomination).toContainText('Awaiting HR decision');
    const hr = await hrContext.newPage();
    await login(hr, 'riley');
    await navigate(hr, 'cases');
    await expect(hr.locator('#casesOpen')).toContainText('Playwright release verification');
    const cases = (await api(hr.request, 'hr_dashboard')).cases;
    const appeal = cases.find((c: any) => Number(c.nomination_id) === Number(record.id));
    expect(appeal).toBeTruthy();
    await api(hr.request, 'hr_case_resolve', { id: appeal.id, outcome: 'resolved_overturned', note: 'The direct evidence supports the reviewer and covers the complete delivery period.' });
    await page.reload();
    await expect(nomination).toContainText('Approved by HR');
    const peer = await peerContext.newPage();
    await login(peer, 'blair', 'employee-dashboard.html#feedback');
    const request = peer.locator('.personal-item').filter({ has: peer.locator('strong', { hasText: /^Feedback for Alex Morgan$/ }) });
    await request.getByRole('button', { name: 'Open form' }).click();
    await expect(peer.locator('#feedbackForm fieldset').first()).toBeVisible();
    for (const field of await peer.locator('#feedbackForm fieldset').all()) await field.locator('select').selectOption('4');
    await peer.getByRole('button', { name: 'Submit feedback', exact: true }).click();
    await expect(request).toContainText('Submitted');
  } finally {
    await managerContext.close(); await hrContext.close(); await peerContext.close();
  }
});

test('administrator creates an account, resets password and deactivates it', async ({ page, playwright }) => {
  await login(page, 'devon');
  const admin = await api(page.request, 'admin_dashboard');
  await navigate(page, 'users');
  await page.locator('#newUserBtn').click();
  const email = `playwright.${Date.now()}@demo.pppm.test`;
  await page.locator('#userFullName').fill('Playwright Test User');
  await page.locator('#userEmail').fill(email);
  await page.locator('#userEmpCode').fill(`PW-${Date.now()}`);
  await page.locator('#userJobTitle').fill('Test Analyst');
  await page.locator('#userRoleSelect').selectOption('employee');
  await page.locator('#userDepartment').selectOption(String(admin.departments[0].id));
  await page.locator('#userReviewEligible').uncheck();
  await page.locator('[data-save-user]').click();
  await expect(page.locator('#modalTitle')).toHaveText('Temporary password');
  const temporary = await page.locator('#modalBody .modal-notice strong').innerText();
  const client = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8187' });
  try {
    const created = await api(client, 'login', { email, password: temporary });
    await api(client, 'change_password', { currentPassword: temporary, newPassword: 'BrowserVerified123!', confirmPassword: 'BrowserVerified123!' });
    const reset = await api(page.request, 'admin_user_password', { id: created.user.id });
    await api(client, 'login', { email, password: reset.temporaryPassword });
    await api(page.request, 'admin_user_status', { id: created.user.id, isActive: false });
    await api(client, 'login', { email, password: reset.temporaryPassword }, 401);
    await api(page.request, 'admin_user_status', { id: admin.user.id, isActive: false }, 409);
  } finally { await client.dispose(); }
  await page.locator('#modalClose').click();
  await page.locator('#userSearch').fill(email);
  await expect(page.locator('#usersTable')).toContainText('Inactive');
});

test('PIP ownership, close-note validation and closed-plan immutability', async ({ page, playwright }) => {
  await login(page, 'taylor');
  const plans = (await api(page.request, 'workspace&scope=hr')).data.pips;
  const plan = plans.find((p: any) => p.status === 'active');
  expect(plan).toBeTruthy();
  const outsider = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8187' });
  try {
    await api(outsider, 'login', { email: 'riley@demo.pppm.test', password });
    await api(outsider, 'hr_pip_update', { id: plan.id, status: 'closed', outcomeNote: 'Reviewed the evidence and recommend closure.' }, 403);
  } finally { await outsider.dispose(); }
  await api(page.request, 'hr_pip_update', { id: plan.id, status: 'closed', outcomeNote: 'Short' }, 422);
  await api(page.request, 'hr_pip_update', { id: plan.id, status: 'closed', outcomeNote: 'Reviewed the complete evidence and formally closed this plan.' });
  const step = plan.objectives[0].steps[0];
  await api(page.request, 'set_step_status', { id: step.id, version: step.version, status: 'in_progress' }, 409);
  await page.reload();
  await navigate(page, 'pips');
  await page.locator('#pipFilter').selectOption('all');
  await expect(page.locator('#pipsTable')).toContainText('Closed');
});

test('organization department creation persists and can be edited', async ({ page }) => {
  await login(page, 'riley', 'org-structure.html#management');
  await page.locator('[data-org-tab="departments"]').click();
  const form = page.locator('#departmentForm');
  await form.locator('[name=departmentCode]').fill(`PW-${Date.now()}`);
  await form.locator('[name=departmentName]').fill('Playwright Quality Department');
  await form.getByRole('button', { name: 'Save department' }).click();
  await expect(page.locator('#departmentList')).toContainText('Playwright Quality Department');
  await page.reload();
  await page.locator('[data-org-tab="departments"]').click();
  await page.locator('[data-edit-department]').filter({ hasText: 'Playwright Quality Department' }).click();
  await form.locator('[name=departmentName]').fill('Playwright Quality Engineering');
  await form.getByRole('button', { name: 'Save department' }).click();
  await expect(page.locator('#departmentList')).toContainText('Playwright Quality Engineering');
});

