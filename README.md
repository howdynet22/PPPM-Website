# PPPM — manager / personal workspace update

Based on `actionable-goal-steps` (8936ccc). This update implements the personal
workspace and updates the manager experience. The HR and system administrator
screens are now implemented; see "HR and administrator workspaces" below.

## Development and workspaces

Managers and HR staff keep their existing account and can choose **Personal**
from the workspace selector to see their own goals, PDPs, PIPs and feedback.
The selected workspace is remembered per user on this browser. Available
workspaces come from database permissions, not job titles or the selected UI.
The CEO demo account uses the existing leadership destination and has no
personal workspace.

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

Personal users can nominate a peer during an open peer-review window. A valid
nomination names the shared project or deliverable, gives at least 30 characters
about the work completed together, gives at least 30 characters explaining what
the peer directly observed, and confirms first-hand knowledge. The assigned
manager reviews this evidence. Approving creates the peer's feedback request;
rejecting requires a 15–1,000 character reason that the employee can see. An
employee may forward one rejected decision to HR with a 30–2,000 character
reason. The escalation is stored as `pending_hr`; resolution fields are reserved
for the teammate's future HR workflow.

Views reload after saves, on window focus, when a tab becomes visible, and every
15 seconds while visible. Step dialogs also refresh when there is no unsaved draft.
A version check rejects stale step saves. Failed saves retain the draft and show
an error; they do not show an unsaved completion as persisted.

## Access boundaries

- People using the Personal workspace access only their own records.
- Managers create records for current direct reports and retain access to records
  explicitly assigned to them. Descendant directory access does not grant access
  to descendants' private PDP, review or PIP records.
- HR PIP step access requires both `hr.pips` and explicit `hr_owner_id` ownership.
- Existing `hr` is the senior HR permission set; `hr_partner` has HR case and
  directory access, while `hr_coordinator` has a personal workspace and basic HR
  entry/reporting-path access. These are reusable permissions, not job titles.
- The shared `me` response includes `workspaces: [{key,label,path}]` for the
  teammate's HR/admin screens. Their screen implementations are outside this PR.

## HR and administrator workspaces

`hr-dashboard.html` and `admin-dashboard.html` replace the earlier placeholder
screens. Both follow the manager dashboard's structure and share `Js/dashboard-ui.js`
for navigation, dialogs, toasts, tables and CSV export. Server code lives in
`hr.php` and `admin.php`, dispatched from `api.php` by the `hr_` and `admin_`
action prefixes, matching the existing `org_` pattern.

Apply `migrations/005_hr_admin_dashboards.sql` once. It adds only two
permissions and reuses the rest:

- `hr.cases` — resolve escalated peer nominations. Granted to `hr`, `hr_partner`
  and `admin`, following the documented boundary that HR coordinators do not
  handle cases.
- `admin.audit` — read the audit log and sign-in records. Administrator only.

### HR workspace

Overview, employee directory, escalated cases, improvement plans, review cycles
and aggregate reports. Sections appear only when the signed-in role holds the
matching permission, so an `hr_coordinator` sees the directory but not cases,
plans or reports.

The escalation queue completes the workflow migration 004 reserved. HR sees the
nomination evidence, the manager's rejection reason and the employee's
escalation reason, then either upholds or overturns with a 15–2,000 character
resolution note.

**Overturning does not rewrite the nomination.** The `peer_nominations` row stays
`rejected` because it is the manager's own decision record, and
`tests/peer_nomination_checks.sql` asserts that an escalation only ever follows a
rejection. Overturning instead creates the peer's `feedback_requests` row and
records the override on the escalation as `resolved_overturned`. The employee's
workspace already reads `escalationStatus` beside the nomination, so both the
rejection and the override are visible. Overturning is refused once the cycle is
released or closed, once the participant reaches `manager_submitted`, or after
the peer deadline — the same limits that bind a manager.

Improvement plan writes require both `hr.pips` and matching `hr_owner_id`, so
oversight of every plan does not imply the ability to edit one. Closing a plan
requires an outcome note, and a closed plan is read-only. In the employee record,
PIP reasons and outcomes are returned as `NULL` unless the viewer owns the plan.

### Administrator workspace

Overview, user accounts, roles and permissions, audit log and sign-in security.
Leadership accounts reach this page with only `admin.dashboard` and `hr.reports`,
so the management sections stay hidden for them and the server returns those
sections empty rather than relying on the hidden markup.

Accounts are deactivated, never deleted, so review, plan and audit history
survives. Creating an account or resetting a password returns a generated
temporary password once; it satisfies the same policy the sign-in endpoint
enforces and is stored only as a hash.

Two lockout guards run inside the transaction and roll back rather than commit:

- You cannot deactivate your own account, change your own role, or remove
  `admin.dashboard`, `admin.users` or `admin.roles` from your own role.
- No change may leave zero active accounts able to manage users. This covers
  role reassignment, deactivation, retiring a role and rewriting a permission set.

A role with active accounts cannot be retired. Every write is audited.

### Creating an administrator account

The demo fixture deliberately has no `admin` account, and `tests/demo_reset.php`
asserts a fixture of exactly 12 users, so the seeder is left unchanged. To try
the administrator workspace, promote an existing demo account:

```sql
UPDATE users SET role='admin' WHERE email='riley@demo.pppm.test';
```

Sign in again afterwards. Use a separate demo database if you want to keep
Riley as senior HR. Reverting is the same statement with `role='hr'`.

### Verification performed

Against a freshly seeded MariaDB 10.11 database on PHP 8.3:

- `tests/employee_workspaces.mjs` passes unchanged.
- `tests/organization_checks.sql`, `tests/actionable_steps_checks.sql` and
  `tests/peer_nomination_checks.sql` report no violations, including after an
  overturned escalation.
- Escalation flow end to end: note-length validation, CSRF rejection, overturn
  creating the peer feedback request, and replay returning a conflict.
- Permission boundaries: `hr_coordinator` refused on cases and plan writes;
  employee accounts refused on both dashboards; leadership receives a scoped
  administrator payload.
- Account lifecycle: create, sign in with the temporary password, reporting line
  assignment, deactivate, sign-in refused afterwards, password reset.
- All five lockout and self-protection guards refuse as intended.

PHP files pass `php -l` and JavaScript files pass `node --check`. The dashboards
were not opened in a browser in that environment, so the rendering layer is
verified by lint and by a static check that every element the scripts reference
exists on its page.

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

Back up the database and apply `migrations/003_employee_workspaces.sql`, then
`migrations/004_peer_nomination_workflow.sql`, then
`migrations/005_hr_admin_dashboards.sql`, once each to the selected database.
Databases older than `actionable-goal-steps` must first apply migrations 001 and
002 in order. Migration 003 preserves users and records, adds step
state/note/version fields, backfills completion states and adds workspace
permissions. Migration 004 preserves existing nominations, adds required work
evidence and decision fields, and creates the durable HR-escalation table. Use
migrations for upgrades, not `schema.sql`.

Environment settings remain `PPPM_DB_HOST`, `PPPM_DB_NAME`, `PPPM_DB_USER`,
`PPPM_DB_PASS`, and `PPPM_APP_DEBUG` (development only).

## Verification

Run checks against an isolated, freshly seeded test database:

- `PPPM_TEST_URL=http://127.0.0.1:8080 node tests/employee_workspaces.mjs`
  (in PowerShell set `$env:PPPM_TEST_URL` first). Tests change fictional records.
- `php tests/demo_reset.php` with `PPPM_DB_NAME` containing `test`.
  Tests preservation of an unrelated user and goal, fixture count and idempotency.
- Import `tests/organization_checks.sql`, `tests/actionable_steps_checks.sql` and
  `tests/peer_nomination_checks.sql` into the database being checked; integrity
  queries should return no rows.
- Lint PHP files with `php -l` and JavaScript files with `node --check`.

Verified locally with PHP 8.2 / MariaDB: fresh installation, migration of all
22 existing users and 123 steps, permission/API scenarios, safe reset, and browser
flows for workspace switching, step saves, reload persistence and mobile layout.
