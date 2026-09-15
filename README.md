# PPPM Performance and Development Tracker

## Final employee and manager handoff

The final product integrates all nine original branch histories. The completed
employee update is restored after reconciling the rollback branch. Earlier
manager and demo branches that had already been cherry-picked are included in
the ancestry without overwriting newer work. No source branches were deleted.

- [Core feature inventory](docs/core-features.md)
- [Demonstration and feature tests](docs/demonstration-tests.md) — 36 manual cases
- [Verified application run](https://github.com/howdynet22/PPPM-Website/actions/runs/34936470147)

The employee and manager workspaces are implemented. The teammate should build
the HR and system administrator screens and their remaining administrative
workflows: review-cycle setup, participant assignment, stage changes and release;
user/role management and recovery; and HR case management. Employment type,
location and richer employment-status fields are not implemented yet.

Reuse the shared organization page, authentication, work-item controls and API
permissions. The current HR/admin pages are placeholders. Password Help provides
contact instructions, not email reset. Leadership uses the admin destination as
a placeholder; it is not a completed executive dashboard.

Peer nominations now run from employee nomination to manager approval and an
assigned peer form. Decisions are transactional and repeated decisions are
rejected. Manager reviews respect cycle stage and deadline. The focused demo
includes Jamie's manager-ready review so it can be demonstrated without HR
cycle controls. Existing installations retain their existing data; the demo
fixture is an explicit separate setup.

### Shared API contract for the teammate

All actions use `api.php?action=...`. GET reads do not mutate data. POST writes
require JSON and `X-CSRF-Token` from `me`. Authorization is enforced server-side.

| Read action | Returned capability |
| --- | --- |
| `me` | Identity, permissions, available workspaces, CSRF token |
| `workspace&scope=employee` | Current account's goals, PDPs, PIPs, reviews, requests and nominations |
| `workspace&scope=hr` | Explicitly owned PIPs when `hr.pips` is granted, plus reporting path |
| `workspace&scope=executive` | Active people, department and team counts |
| `dashboard` | Permitted manager team and performance records |
| `org_tree`, `org_path`, `org_departments`, `org_teams` | Permission-scoped organizational views |
| `work_item&type=goal&id=...` | Shared work-item dialog data; also `pdp_action` and `pip_objective` |
| `peer_candidates&participantId=...` | Eligible colleagues for an owned open review |
| `feedback_form&id=...` | The current respondent's assigned form |

Mutations already include `nominate_peer`, `decide_peer`, `submit_personal_feedback`,
`submit_review`, `create_goal`, `create_pdp`, `create_pip`, PIP follow-up actions,
step updates, notification read state and organization management. HR cycle
administration, account CRUD and role administration APIs are **not** completed
by the presence of permission names alone.

Do not use job titles or selected workspaces as authorization. Preserve private
record ownership when reorganizing reporting lines. Formal release must continue
to gate employee results and the anonymity threshold must continue to gate peer
aggregates. The CEO fixture has no personal workspace; other demo people do.


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

Progress is completed steps / total steps, with each step weighted equally across a plan's
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
three review cycles, a few requests and one released anonymous feedback example.
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
  into the database being checked. Violation queries should return no rows;
  the invalid-parent count should be zero and the authorship query is informational.
- Lint PHP files with `php -l` and JavaScript files with `node --check`.

Verified in GitHub Actions using PHP 8.2, MySQL 8 and Chromium: syntax,
upgrade preservation of the previous main user identities, fresh schema,
safe fixture reset, step progress, ownership/CSRF checks, employee workflows,
peer nominations and feedback, manager review submission, PIP transitions,
password/sign-out, search, report/CSV, workspace switching, mobile saves,
reload persistence and failed-save draft retention.

For the added handoff and browser suites, start from a freshly reset isolated
fixture before **each** suite:

```sh
php scripts/demo.php reset
PPPM_TEST_URL=http://127.0.0.1:8080 node tests/handoff.mjs
php scripts/demo.php reset
PPPM_TEST_URL=http://127.0.0.1:8080 node tests/browser.mjs
```

The browser suite requires Playwright and Chromium (the workflow installs them).
`php tests/work_progress.php` runs without a database. `tests/demo_reset.php`
requires a database name containing `test`. Do not run destructive test fixtures
against the live installation. The manual runbook is for your local XAMPP
rehearsal; automated success does not mark its blank results as completed.

