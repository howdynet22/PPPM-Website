# PPPM — manager / employee update

Based on `actionable-goal-steps` (8936ccc). This update implements the employee
workspace and updates the manager experience. HR and system administrator
screens are maintained separately by a teammate; those HTML screens are unchanged.

## Development and workspaces

Managers and HR staff keep their existing account and can choose **Employee**
from the workspace selector to see their own goals, PDPs, PIPs and feedback.
The selected workspace is remembered per user on this browser. Available
workspaces come from database permissions, not job titles or the selected UI.
The CEO demo account uses the existing leadership destination and has no
employee workspace.

Open any development goal to update its steps to **Not started**, **In progress**,
**Blocked** or **Completed**. Notes are optional. Mark complete and Reopen provide
quick actions. Reopening clears the completion timestamp; changing a note on an
already completed step preserves it. Step authors retain definition editing;
assignees can update progress but cannot rewrite assigned steps.

Progress is completed steps / total steps, weighted equally across a plan's
non-cancelled actions. A goal without steps has zero progress and cannot complete.
A plan with an empty action remains incomplete even if its defined steps are all
done. Blocked and overdue are separate health indicators. Completing every action
completes the plan; reopening restores its draft/agreed state. Closed PIPs and
cancelled development actions are read-only.

The static review checklist has been removed. Assigned self/peer requests open
real feedback forms; submitted forms are read-only. Employee results stay hidden
until release, and anonymous aggregates still require the cycle's minimum peers.

Views reload after saves, on window focus, when a tab becomes visible, and every
15 seconds while visible. Step dialogs also refresh when there is no unsaved draft.
A version check rejects stale step saves. Failed saves retain the draft and show
an error; they do not show an unsaved completion as persisted.

## Access boundaries

- Employees access their own personal records.
- Managers create records for current direct reports and retain access to records
  explicitly assigned to them. Descendant directory access does not grant access
  to descendants' private PDP, review or PIP records.
- HR PIP step access requires both `hr.pips` and explicit `hr_owner_id` ownership.
- Existing `hr` is the senior HR permission set; `hr_partner` has HR case and
  directory access, while `hr_coordinator` has a personal workspace and basic HR
  entry/reporting-path access. These are reusable permissions, not job titles.
- The shared `me` response includes `workspaces: [{key,label,path}]` for the
  teammate's HR/admin screens. Their screen implementations are outside this PR.

## Fresh demo setup

Requires PHP 8.1+ and MySQL/MariaDB (XAMPP works).

1. Use a **new empty database** for the focused demo. `schema.sql` selects
   `perf_tracker` by default; to use another name, replace its database name
   before import and set `PPPM_DB_NAME` to the same name in PHP's environment.
2. Import `schema.sql`. It creates schema and permissions, without dropping
   an existing database or embedding people in the schema.
3. From the project directory run `php scripts/demo.php seed`.
4. Serve the project through Apache or `php -S 127.0.0.1:8080 -t .`.
5. Open `index.html` and sign in with an account from `users.txt`.

The fictional fixture has 1 CEO, 3 managers at successive reporting levels,
3 HR staff with different permission sets, and 5 other employees. All 11
non-CEO accounts have a small personal plan. There is one illustrative PIP,
two review cycles, a few requests and one released anonymous feedback example.
Dates are relative to the time the demo is seeded.

Good demo starting accounts (password `password123`):

| Account | Demonstrates |
| --- | --- |
| casey@demo.pppm.test | Team management and switching to a personal PDP |
| alex@demo.pppm.test | Four step states, overdue development, a completed goal, self review |
| morgan@demo.pppm.test | Senior management plus released personal 360 feedback |
| taylor@demo.pppm.test | Personal employee view and assigned HR PIP access |
| sam@demo.pppm.test | Junior HR access without other employees' PIP data |

## Recreate demo data safely

`php scripts/demo.php seed` is idempotent: it leaves an existing fixture alone.
`php scripts/demo.php reset` recreates only registered fixture content and
personal records created for those demo accounts. It preserves unrelated users
and records. Foreign-key dependencies from outside the fixture cause a rollback
rather than removal of unrelated content. Sign in again after a reset because
fixture account IDs are recreated.

The old 22-person dataset is unregistered. The seeder deliberately refuses to
adopt or delete those users automatically. For this smaller demo, initialize a
separate database and point the demo app at it. No current machine database is
reset by this code update.

## Upgrade the latest branch's existing database

Back up the database and apply `migrations/003_employee_workspaces.sql` once
to the selected database. Databases older than `actionable-goal-steps` must
first apply migrations 001 and 002 in order. Migration 003 preserves users and
records, adds step state/note/version fields, backfills completion states and
adds workspace permissions. Use migrations for upgrades, not `schema.sql`.

Environment settings remain `PPPM_DB_HOST`, `PPPM_DB_NAME`, `PPPM_DB_USER`,
`PPPM_DB_PASS`, and `PPPM_APP_DEBUG` (development only).

## Verification

Run checks against an isolated, freshly seeded test database:

- `PPPM_TEST_URL=http://127.0.0.1:8080 node tests/employee_workspaces.mjs`
  (in PowerShell set `$env:PPPM_TEST_URL` first). Tests change fictional records.
- `php tests/demo_reset.php` with `PPPM_DB_NAME` containing `test`.
  Tests preservation of an unrelated user and goal, fixture count and idempotency.
- Import `tests/organization_checks.sql` and `tests/actionable_steps_checks.sql`
  into the database being checked; integrity queries should return no rows.
- Lint PHP files with `php -l` and JavaScript files with `node --check`.

Verified locally with PHP 8.2 / MariaDB: fresh installation, migration of all
22 existing users and 123 steps, permission/API scenarios, safe reset, and browser
flows for workspace switching, step saves, reload persistence and mobile layout.
