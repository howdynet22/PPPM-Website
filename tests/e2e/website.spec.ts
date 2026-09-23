import { test, expect, login, api, navigate, downloadCsv, password } from './helpers';

// All cases run against the real PHP API and a fresh, isolated demo database.
// Each case has its own browser/session; no stored credentials or mocked success.
test.describe('Public pages and authentication', () => {
  test('login fields, required validation and password masking', async ({ page }) => {
    await page.goto('/index.html');
    await expect(page).toHaveTitle('Employee Performance & Development Tracker');
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'password');
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    expect(await page.locator('#email').evaluate((e: HTMLInputElement) => e.validity.valueMissing)).toBe(true);
    await page.locator('#email').fill('invalid-email');
    expect(await page.locator('#email').evaluate((e: HTMLInputElement) => e.validity.typeMismatch)).toBe(true);
    await expect(page).toHaveURL(/index\.html$/);
  });
  test('invalid credentials show an error and allow retry', async ({ page }) => {
    await page.goto('/index.html');
    await page.locator('#email').fill('missing-user@demo.pppm.test');
    await page.locator('#password').fill('Incorrect123!');
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page.locator('#message')).toHaveClass('error');
    await expect(page.locator('#message')).not.toBeEmpty();
    await expect(page.getByRole('button', { name: 'Sign In', exact: true })).toBeEnabled();
  });
  test('password help provides instructions and returns to login', async ({ page }) => {
    await page.goto('/index.html');
    await page.getByRole('link', { name: 'Forgot password?' }).click();
    await page.getByLabel('Work Email Address').fill('alex@demo.pppm.test');
    await page.getByRole('button', { name: 'Show Recovery Instructions' }).click();
    await expect(page.locator('#result')).toContainText('Contact your system administrator');
    await page.getByRole('link', { name: /Back to Login/ }).click();
    await expect(page.locator('#loginForm')).toBeVisible();
  });
  for (const [name, destination] of [
    ['alex', 'employee'], ['casey', 'manager'], ['riley', 'hr'],
    ['taylor', 'hr'], ['sam', 'hr'], ['devon', 'admin'], ['avery', 'admin'],
  ]) {
    test(`${name} signs in to the ${destination} dashboard`, async ({ page }) => {
      await login(page, name);
      await expect(page).toHaveURL(new RegExp(`${destination}-dashboard\\.html`));
      await expect(page.locator('.profile-badge')).toContainText(name[0].toUpperCase() + name.slice(1));
    });
  }
  for (const file of ['employee-dashboard', 'manager-dashboard', 'hr-dashboard', 'admin-dashboard', 'org-structure']) {
    test(`${file} redirects an anonymous visitor to login`, async ({ page }) => {
      await page.goto(`/${file}.html`);
      await expect(page).toHaveURL(/index\.html$/);
    });
  }
  test('remember email persists, sign out invalidates the session, unchecking clears it', async ({ page }) => {
    await page.goto('/index.html');
    await page.locator('#rememberMe').check();
    await page.locator('#email').fill('alex@demo.pppm.test');
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page.locator('#logoutBtn')).toBeVisible();
    await page.locator('#logoutBtn').click();
    await expect(page).toHaveURL(/index\.html$/);
    await expect(page.locator('#email')).toHaveValue('alex@demo.pppm.test');
    await expect(page.locator('#rememberMe')).toBeChecked();
    await api(page.request, 'me', undefined, 401);
    await page.locator('#rememberMe').uncheck();
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Sign In', exact: true }).click();
    await expect(page.locator('#logoutBtn')).toBeVisible();
    expect(await page.evaluate(() => localStorage.getItem('rememberedEmail'))).toBeNull();
  });
  test('password dialog rejects mismatches and supports cancel', async ({ page }) => {
    await login(page);
    await page.locator('#changePasswordBtn').click();
    await page.locator('#currentPassword').fill(password);
    await page.locator('#newPassword').fill('DifferentPassword123!');
    await page.locator('#confirmPassword').fill('MismatchPassword123!');
    await page.locator('#changePasswordForm button[type=submit]').click();
    await expect(page.locator('#passwordMessage')).toHaveClass(/error/);
    await page.locator('#cancelPassword').click();
    await expect(page.locator('#changePasswordModal')).not.toHaveClass(/open/);
  });
});

test.describe('Personal workspace', () => {
  for (const [section, title] of [['development', 'My development plans'], ['improvement', 'My improvement plans'], ['feedback', 'Reviews &'], ['notifications', 'Notifications']]) {
    test(`${section} navigation and reload`, async ({ page }) => {
      await login(page);
      await page.locator(`.sidebar a[href="#${section}"]`).click();
      await expect(page.locator(`#${section}`)).toBeVisible();
      await expect(page.locator(`#${section} h2`).first()).toContainText(title);
      await page.reload();
      await expect(page.locator(`#${section}`)).toBeVisible();
    });
  }
  test('development goal creates, persists, edits steps and calculates completion', async ({ page }) => {
    await login(page);
    const title = `Playwright development ${Date.now()}`;
    await page.locator('[data-create="pdp"]').click();
    const form = page.locator('#createPersonal');
    await form.getByLabel('Title', { exact: true }).fill(title);
    await form.getByLabel('Expected outcome').fill('Demonstrate a repeatable browser testing workflow.');
    await form.getByLabel('Due date').fill('2099-12-31');
    await form.getByLabel('Actionable steps').fill('Prepare example\nReview example');
    await form.getByRole('button', { name: 'Create goal' }).click();
    await expect(page.locator('#createPersonal')).not.toBeVisible();
    const card = page.locator('.personal-item').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
    await card.getByRole('button', { name: 'Open goal' }).click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog.locator('[data-step]')).toHaveCount(2);
    for (const status of ['in_progress', 'blocked', 'completed', 'not_started']) {
      const step = dialog.locator('[data-step]').first();
      await step.getByLabel('Status', { exact: true }).selectOption(status);
      await step.locator('textarea').fill(`Evidence: ${status}`);
      await step.getByRole('button', { name: 'Save step' }).click();
      await expect(dialog.locator('#workMessage')).toHaveText('Saved.');
      await expect(dialog.locator('[data-step]').first().getByLabel('Status', { exact: true })).toHaveValue(status);
    }
    await dialog.locator('[data-step]').first().getByRole('button', { name: 'Mark complete' }).click();
    await expect(dialog.locator('#workMessage')).toHaveText('Saved.');
    await dialog.locator('[data-step]').nth(1).getByRole('button', { name: 'Mark complete' }).click();
    await expect(dialog.locator('progress')).toHaveAttribute('value', '100');
    await dialog.getByRole('button', { name: 'Close goal' }).click();
    await page.reload();
    await card.getByRole('button', { name: 'Open goal' }).click();
    await expect(dialog.locator('progress')).toHaveAttribute('value', '100');
    await dialog.locator('[data-step]').first().getByRole('button', { name: 'Reopen', exact: true }).click();
    await expect(dialog.locator('progress')).toHaveAttribute('value', '50');
    await dialog.locator('[data-step]').first().getByText('Edit step definition').click();
    await dialog.locator('[name=definition]').first().fill('Prepare a better example');
    await dialog.locator('[data-rename]').first().click();
    await expect(dialog.locator('[data-step]').first()).toContainText('Prepare a better example');
    await dialog.locator('#addStepForm input').fill('Reflect on results');
    await dialog.getByRole('button', { name: 'Add step', exact: true }).click();
    await expect(dialog.locator('[data-step]')).toHaveCount(3);
    await dialog.locator('[data-step]').last().getByText('Edit step definition').click();
    await dialog.locator('[data-remove]').last().click();
    await expect(dialog.locator('[data-step]')).toHaveCount(2);
  });
  test('failed step save retains the draft without persisting it', async ({ page }) => {
    await login(page);
    await page.locator('[data-work-id]').first().click();
    const dialog = page.locator('dialog[open]');
    const note = dialog.locator('[data-step] textarea').first();
    const original = await note.inputValue();
    await note.fill('Unsaved network failure draft');
    // Only this explicit failure-path test intercepts a request.
    await page.route('**/api.php?action=set_step_status', route => route.fulfill({ status: 503, json: { ok: false, error: 'Test service unavailable' } }));
    await dialog.locator('[data-step]').first().getByRole('button', { name: 'Save step' }).click();
    await expect(dialog.locator('#workMessage')).toContainText('Your changes were not saved');
    await expect(note).toHaveValue('Unsaved network failure draft');
    await page.unroute('**/api.php?action=set_step_status');
    await dialog.getByRole('button', { name: 'Close goal' }).click();
    await page.locator('[data-work-id]').first().click();
    await expect(note).toHaveValue(original);
  });
  test('self feedback submits once and becomes read-only', async ({ page }) => {
    await login(page, 'sam', 'employee-dashboard.html#feedback');
    const row = page.locator('.personal-item').filter({ has: page.locator('strong', { hasText: /^Self review$/ }) });
    await row.getByRole('button', { name: 'Open form' }).click();
    const form = page.locator('#feedbackForm');
    await expect(form.locator('fieldset').first()).toBeVisible();
    for (const field of await form.locator('fieldset').all()) {
      await field.locator('select').selectOption('4');
      await field.locator('textarea').fill('Documented delivery and collaboration evidence.');
    }
    await form.getByRole('button', { name: 'Submit feedback' }).click();
    await expect(row).toContainText('Submitted');
    await row.getByRole('button', { name: 'View form' }).click();
    await expect(page.locator('#feedbackForm select').first()).toBeDisabled();
    await expect(page.locator('#feedbackForm')).toContainText('This form is read-only.');
  });
  test('released results show anonymous aggregates; unreleased results stay hidden', async ({ page }) => {
    await login(page, 'morgan', 'employee-dashboard.html#feedback');
    await expect(page.locator('.review-result').filter({ hasText: 'Anonymous peer feedback' })).toBeVisible();
    const personal = await api(page.request, 'workspace&scope=employee');
    const released = personal.data.reviews.find((r: any) => r.status === 'released');
    expect(released.feedback.length).toBeGreaterThan(0);
    expect(JSON.stringify(released.feedback)).not.toMatch(/respondent|@demo\.pppm\.test/);
    for (const review of personal.data.reviews.filter((r: any) => r.status !== 'released')) {
      expect(review.final_rating).toBeNull();
      expect(review.manager_summary).toBeNull();
      expect(review.feedback).toEqual([]);
    }
  });
  test('mark all notifications read persists after reload', async ({ page }) => {
    await login(page);
    await page.locator('.sidebar a[href="#notifications"]').click();
    const button = page.locator('[data-mark-notifications]');
    if (await button.isEnabled()) await button.click();
    await expect(button).toHaveText('Mark all read (0)');
    await page.reload();
    await expect(page.locator('[data-mark-notifications]')).toBeDisabled();
    expect((await api(page.request, 'get_notifications&unread=1')).notifications).toEqual([]);
  });
});

test.describe('Manager, HR and administrator workspaces', () => {
  const sections: Record<string, string[]> = {
    casey: ['overview', 'team', 'reviews', 'goals', 'pips', 'reports', 'notifications'],
    riley: ['overview', 'directory', 'cases', 'pips', 'cycles', 'competencies', 'reports'],
    devon: ['overview', 'users', 'roles', 'audit', 'security'],
  };
  for (const [account, pages] of Object.entries(sections)) {
    for (const section of pages) {
      test(`${account}: ${section} section renders`, async ({ page }) => {
        await login(page, account);
        await navigate(page, section);
        await expect(page.locator(`#page-${section}`)).not.toBeEmpty();
        await expect(page.locator('#pageTitle')).not.toBeEmpty();
      });
    }
  }
  test('manager switches to Personal and remembers the workspace', async ({ page }) => {
    await login(page, 'casey');
    await page.getByLabel('Workspace', { exact: true }).selectOption('employee');
    await expect(page).toHaveURL(/employee-dashboard/);
    await expect(page.locator('#employeeContent')).toHaveAttribute('aria-busy', 'false');
    await page.locator('#logoutBtn').click();
    await login(page, 'casey');
    await expect(page).toHaveURL(/employee-dashboard/);
    await expect(page.getByLabel('Workspace', { exact: true })).toHaveValue('employee');
  });
  for (const [account, section, search, table] of [
    ['casey', 'team', '#teamSearch', '#teamTable'],
    ['riley', 'directory', '#directorySearch', '#directoryTable'],
    ['devon', 'users', '#userSearch', '#usersTable'],
  ]) {
    test(`${account}: search finds Alex, handles no matches and clears`, async ({ page }) => {
      await login(page, account);
      await navigate(page, section);
      await page.locator(search).fill('Alex Morgan');
      await expect(page.locator(table)).toContainText('Alex Morgan');
      await page.locator(search).fill('zz-no-such-person-zz');
      await expect(page.locator(table)).not.toContainText('Alex Morgan');
      await page.locator(search).fill('');
      await expect(page.locator(table)).toContainText('Alex Morgan');
    });
  }
  for (const [account, section, button] of [
    ['casey', 'reports', '#exportTeamBtn'], ['riley', 'directory', '#exportDirectoryBtn'],
    ['taylor', 'pips', '#exportPipsBtn'], ['riley', 'reports', '#exportDepartmentsBtn'],
    ['devon', 'users', '#exportUsersBtn'], ['devon', 'audit', '#exportAuditBtn'],
  ]) {
    test(`${account}: ${section} exports populated CSV`, async ({ page }) => {
      await login(page, account);
      await navigate(page, section);
      await downloadCsv(page, button);
    });
  }
  test('manager quick actions and report generation work', async ({ page }) => {
    await login(page, 'casey');
    await page.locator('#quickActionBtn').click();
    await expect(page.locator('#modalTitle')).toHaveText('Quick actions');
    await page.locator('#modalClose').click();
    await navigate(page, 'reports');
    await page.locator('#generateReportBtn').click();
    await expect(page.locator('#reportOutput')).toContainText('Team performance report generated');
  });
  test('HR coordinator cannot see case, PIP, cycle or report controls', async ({ page }) => {
    await login(page, 'sam');
    for (const section of ['cases', 'pips', 'cycles', 'reports', 'competencies']) {
      await expect(page.locator(`.sidebar [data-page="${section}"]`)).toBeHidden();
    }
    await navigate(page, 'directory');
    await expect(page.locator('#directoryTable')).not.toBeEmpty();
  });
  test('leadership sees feedback but cannot manage users or roles', async ({ page }) => {
    await login(page, 'avery');
    for (const section of ['users', 'roles', 'audit', 'security']) {
      await expect(page.locator(`.sidebar [data-page="${section}"]`)).toBeHidden();
    }
    await navigate(page, 'feedback');
    await expect(page.locator('#leadershipFeedback')).not.toBeEmpty();
    await api(page.request, 'admin_user_save', { id: 0 }, 403);
  });
});

test.describe('Organization and responsive layout', () => {
  test('directory searches, reporting path and hierarchy display real people', async ({ page }) => {
    await login(page, 'riley', 'org-structure.html');
    await expect(page.locator('#orgEmployeeRows')).toContainText('Alex Morgan');
    await page.locator('#orgSearch').fill('Alex Morgan');
    await expect(page.locator('#orgEmployeeRows tr')).toHaveCount(1);
    await page.locator('#orgEmployeeRows').getByRole('button', { name: 'View path' }).click();
    await expect(page.locator('#org-page-reporting')).toBeVisible();
    await expect(page.locator('#orgPath')).toContainText('Casey Brooks');
    await page.locator('.sidebar [data-org-page="hierarchy"]').click();
    await expect(page.locator('#orgTree')).toContainText('Avery Lane');
    await page.locator('.sidebar [data-org-page="directory"]').click();
    await page.locator('#orgClearFilters').click();
    expect(await page.locator('#orgEmployeeRows tr').count()).toBeGreaterThan(1);
  });
  test('organization management tabs are accessible to HR', async ({ page }) => {
    await login(page, 'riley', 'org-structure.html#management');
    for (const tab of ['assignments', 'departments', 'teams']) {
      await page.locator(`[data-org-tab="${tab}"]`).click();
      await expect(page.locator(`#org-tab-${tab}`)).toBeVisible();
    }
  });
  for (const [width, height] of [[390, 844], [768, 1024], [1440, 900]]) {
    test(`personal workspace navigation at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height });
      await login(page);
      await page.locator('.sidebar a[href="#feedback"]').click();
      await expect(page.locator('#feedback')).toBeVisible();
      await page.locator('#changePasswordBtn').click();
      await expect(page.locator('#currentPassword')).toBeVisible();
      await page.locator('#cancelPassword').click();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2);
      expect(overflow, 'Page must not overflow the viewport horizontally').toBe(false);
    });
  }
});

test.describe('API security and record boundaries', () => {
  for (const action of ['me', 'dashboard', 'workspace&scope=employee', 'hr_dashboard', 'admin_dashboard', 'get_notifications', 'org_tree']) {
    test(`anonymous ${action} is refused`, async ({ request }) => {
      await api(request, action, undefined, 401);
    });
  }
  test('employee cannot read privileged dashboards or another person’s steps', async ({ page, playwright }) => {
    await login(page);
    for (const action of ['dashboard', 'hr_dashboard', 'admin_dashboard', 'workspace&scope=hr']) {
      await api(page.request, action, undefined, 403);
    }
    const own = (await api(page.request, 'workspace&scope=employee')).data;
    const other = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8187' });
    try {
      await api(other, 'login', { email: 'jordan@demo.pppm.test', password });
      await api(other, `work_item&type=pdp_action&id=${own.plans[0].actions[0].id}`, undefined, 404);
    } finally { await other.dispose(); }
  });
  test('missing CSRF and stale step versions are rejected', async ({ page }) => {
    await login(page);
    const own = (await api(page.request, 'workspace&scope=employee')).data;
    const id = own.plans[0].actions[0].id;
    const item = (await api(page.request, `work_item&type=pdp_action&id=${id}`)).item;
    const step = item.steps[0];
    await api(page.request, 'set_step_status', { id: step.id, status: step.status }, 403, '');
    await api(page.request, 'set_step_status', { id: step.id, version: step.version, status: step.status, note: step.note });
    await api(page.request, 'set_step_status', { id: step.id, version: step.version, status: step.status }, 409);
  });
});


