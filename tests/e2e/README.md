# Automated website tests with Playwright

These tests exercise the PHP/MySQL website, including real database writes. The
Next.js app in `app/` is a separate placeholder and is not the website under test.
The test package is independent so you do not need to install the Next.js dependencies.

## Run in your VS Code Playwright extension

1. Open the project folder in VS Code.
2. Start **MySQL** in the XAMPP Control Panel. Apache is not required.
3. The **Playwright Test for VSCode** extension by Microsoft
   (`ms-playwright.playwright`) is already installed on this machine.
4. Open the **Testing** panel (flask icon). Select
   `tests/e2e/playwright.config.ts` and the **chromium** project in the Playwright
   configuration picker if they are not selected automatically.
5. Expand a test file and click its play icon, or click **Run All Tests**.
   Enable **Show browser** to watch. Right-click a test and choose **Debug Test**
   to step through it. Use the trace viewer to investigate failures.

If tests are not listed, run **Developer: Reload Window** from the Command Palette
and select the configuration again. Opening `tests/e2e` itself as a VS Code folder
also exposes the package and configuration directly.

Playwright and Chromium have been installed locally. On a new machine, run these
commands from the project root first:

```powershell
npm.cmd ci --prefix tests/e2e
npm.cmd --prefix tests/e2e run browsers
```

Official extension guide: https://playwright.dev/docs/getting-started-vscode

## Command-line alternatives

Run from the project root:

```powershell
npm.cmd --prefix tests/e2e test
npm.cmd --prefix tests/e2e run test:headed
npm.cmd --prefix tests/e2e run test:ui
npm.cmd --prefix tests/e2e run report
```

Or enter `tests/e2e` and run `npx.cmd playwright test`. To run one file:

```powershell
npm.cmd --prefix tests/e2e test -- website.spec.ts
npm.cmd --prefix tests/e2e test -- workflows.spec.ts
npm.cmd --prefix tests/e2e test -- z-review-cycle.spec.ts
```

## Local server and data isolation

The configuration starts PHP at `http://127.0.0.1:8187`. It detects
`C:/xampp/php/php.exe`, otherwise uses `php` from PATH. MySQL must already be
running. A fresh database named `pppm_pw_<timestamp>_<random>` is created from
`schema.sql` and `scripts/demo.php` for each test run. The normal `perf_tracker`
database is never selected. No manual import, account promotion or demo reset is
needed. The current seeder includes Devon, the administrator, even though the
older `users.txt` list omits that account.

Normal teardown deletes only that run's temporary database. Force-killing VS Code
or the computer may leave a temporary database behind; its exact name is recorded
in `tests/e2e/.cache/database.json`. Never delete your ordinary application database.
The local MySQL test account needs permission to create and drop these databases.
No application DB URL or remote database is used by this configuration.

Defaults match XAMPP: localhost, port 3306, user root, empty password. For a different
installation, set these variables **before launching VS Code** so its extension
inherits them (setting them only in an integrated terminal affects terminal runs):

```powershell
$env:PPPM_PHP = 'C:/xampp/php/php.exe'
$env:PPPM_TEST_DB_PORT = '3306'
$env:PPPM_TEST_DB_USER = 'root'
$env:PPPM_TEST_DB_PASS = 'your-local-test-password'
code .
```

Close all existing VS Code windows first when changing inherited environment
variables. Do not commit passwords. Port 8187 must be free. Run one suite at a time.

## Coverage

| File | Checks |
| --- | --- |
| `website.spec.ts` | Public pages; input validation; all access roles; authentication redirects; remembered email; sign-out; password validation; all dashboard sections; Personal navigation; development goal creation; step states, completion, reopen, rename, add/remove, reload persistence and failed-save draft retention; self feedback; released-result privacy; notifications; searches; populated CSV downloads; workspace persistence; HR/leadership restrictions; organization directory/hierarchy/reporting path; 390/768/1440px layouts; anonymous API rejection, access boundaries, CSRF and stale versions. |
| `workflows.spec.ts` | Peer nomination through rejection, employee appeal, HR override and peer feedback; account creation through password change/reset/deactivation; administrator self-protection; HR PIP ownership, close-note validation and locked steps; department creation and editing. |
| `z-review-cycle.spec.ts` | Full self/peer/manager review progression, result release, closure, refusal of late resubmission, then draft creation and publication of the next cycle. |

Tests use the real server. Only the explicitly named failed-save scenario injects
a 503 response to test recovery. Cross-role setup and several governance checks use
Playwright's API client alongside browser interactions. No finite suite proves all
possible inputs; these are regression checks for the listed behavior, not an
exhaustive accessibility, performance, penetration or cross-browser audit.

## Independence and diagnostics

Run any individual test on a fresh run. Tests use separate browser sessions, one
worker and no retries. The final lifecycle scenario intentionally runs last and
withdraws other fixture participants through the audited HR API to focus on one
complete review. Do not enable parallel execution or repeat tests against the
same running server: submissions and lifecycle transitions are irreversible.
Stop and restart UI mode for a fresh database before rerunning a mutation test.

Reports: `tests/e2e/playwright-report/index.html`. Failure screenshots, video and
traces: `tests/e2e/test-results/`. These and browser dependencies are ignored by Git.
The older PHP/SQL/Node tests elsewhere in `tests/` remain separate; this config
only discovers `*.spec.ts` inside this directory.

## Verified result

On 23 September 2026, the complete suite passed: **77 passed**, Chromium,
Playwright 1.63.0, PHP 8.2.12 and local XAMPP MySQL, in approximately 1.9 minutes.
The normal teardown removed the run's temporary database. The Microsoft
Playwright VS Code extension was confirmed installed. CLI execution was verified;
the VS Code Testing-panel UI was not driven automatically.
