# PPPM Demonstration and Feature Tests

Demonstration runbook  |  Performance and Development Tracker  |  September 2026

Use this runbook to demonstrate every implemented feature group and record manual test results. Run it on the focused fictional demo database. The steps change data, so use separate browser profiles for different people and reset the demo before starting a new full run.

## Prepare the demonstration

1. Place the complete project in the XAMPP htdocs directory and start Apache and MySQL. Keep the Js folder capitalization unchanged.

2. For a fresh demo, create an empty database using schema.sql, then run php scripts/demo.php seed from the project directory. To use a different database name, adjust the schema target and PPPM_DB_NAME consistently.

3. For an existing installation, back it up and apply migrations 001, 002 and 003 once, in order. Do not load schema.sql over that installation. Use a separate fresh database for the 12-person fixture.

4. Open index.html through localhost. Use separate browser profiles or private windows for employee and manager sessions; tabs in the same profile share one login.

5. Run php scripts/demo.php reset only for the registered fictional demo when a clean restart is needed. Sign in again after reset because demo account IDs are recreated.

| Login prefix | Role and demonstration purpose |
| --- | --- |
| alex | Employee steps, personal goals, self review and nominations |
| casey | Manager of Alex, Jamie and Drew; personal workspace switch |
| jamie | Manager-ready review and an employee PIP example |
| drew | Peer feedback recipient and the seeded PIP employee |
| morgan and jordan | Successive manager levels; Morgan has released feedback |
| riley and taylor | Organization management and explicit HR PIP ownership |
| sam and avery | Limited HR access and leadership placeholder |

Append @demo.pppm.test to each prefix. Initial password for every demo account: password123. The fixture also includes Blair and Quinn. HR and admin placeholder destinations are shown only to explain the handoff, not as completed screens.

## Suggested presentation sequence

For a 15 to 20 minute presentation, use T01, T02, T04, T07, T10, T13 to T18, T20 to T23 and T25. Use the full run for acceptance testing. Finish with the HR/admin boundary and the automated verification evidence.

Tester ____________________  Date ____________________
Commit or branch ____________________  Environment ____________________
Record Pass, Fail or Blocked for each case. Expected results below are a test plan, not pre-filled evidence of execution.

## Access and personal workspace

### T01 Login and incorrect credentials

Actions  Use alex@demo.pppm.test. Try an incorrect password, then password123.

Expected  Incorrect credentials show an error. Valid login opens the employee workspace and identifies Alex Morgan.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T02 Personal data and workspace separation

Actions  Read Alex’s goals, development plan and improvement section. Then use a separate browser profile to sign in as Casey and switch between Manager and Employee.

Expected  Alex has a completed onboarding goal and a four-step development action. Casey sees their own personal plan in Employee and three direct reports in Manager.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T03 Unauthorized destinations

Actions  As Alex, enter manager-dashboard.html, hr-dashboard.html and admin-dashboard.html in the address bar.

Expected  The account is redirected to an allowed destination. API authorization is also checked in the automated suites; hiding a page alone is not the access control.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T04 Reporting path

Actions  As Alex, click My reporting path and open the path view.

Expected  The path includes Alex, Casey, Jordan, Morgan and Avery in that order. Higher levels are derived from reporting relationships.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T05 Create a personal goal

Actions  As Alex, choose + Goal. Use title Demo handover, an expected outcome, a future due date and two steps on separate lines.

Expected  A new personal goal appears with 0 of 2 steps complete. Reload and confirm that the goal remains.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T06 Create a development goal

Actions  Choose + Development goal. Enter Demo learning exercise, an outcome, a future due date and two concrete steps.

Expected  The new development action appears in Alex’s personal plan. It is distinguishable from the manager-assigned action.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

## Actionable progress and reliability

### T07 Four progress states and notes

Actions  Open Alex’s Present a clear project update development action. Save a step as Not started, In progress, Blocked and Completed, adding a note each time.

Expected  The selected state and note persist after save. Only Completed increases the completed-step count. Blocked is shown separately from progress.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T08 Completion and reopening

Actions  Complete the remaining steps, close the dialog and check the plan. Reopen one step and check again.

Expected  All completed steps produce 100 percent and completed status. Reopening removes that step’s completion timestamp and restores unfinished plan status.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T09 Step authorship

Actions  On Casey’s assigned action, check that Alex cannot edit definitions. On Alex’s new personal goal, rename a step, add one and remove an extra step.

Expected  Assigned definitions remain protected; self-authored definitions are editable. The last step cannot be removed through the normal controls.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T10 Cross-account refresh

Actions  Keep Casey’s Goals & PDPs view open in a second browser profile. Save a step as Alex, then focus Casey’s window or wait up to 15 seconds.

Expected  The matching goal or development action shows the saved progress. Reload both pages to confirm database persistence.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T11 Failed save preserves the draft

Actions  Open an editable step and type a new note. In browser developer tools, temporarily block the set_step_status request or take the network offline; click Save step. Restore the network afterwards.

Expected  An explicit error appears, the draft remains and the interface does not falsely report a successful save. Retry when online.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T12 Stale edit and overdue health

Actions  Open the same step in Alex and Casey sessions. Type drafts in both; save Casey’s change first, then Alex’s old version. Also inspect Alex’s initially overdue action before completing it.

Expected  The stale save is rejected with a reload message. Due dates affect Overdue separately from completion. A fully completed item is no longer overdue.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

## Feedback and review demonstration

### T13 Submit the self review

Actions  As Alex, open the self-review request for Demo development check-in. Rate every competency and add comments. Submit, reload and reopen the form.

Expected  The request becomes submitted and the form is read-only. The review advances from Not started; a second submission is rejected.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T14 Nominate a peer

Actions  In Alex’s Peer nominations area, choose Drew Parker and send the nomination.

Expected  A pending nomination appears. Self, the assigned manager and already nominated colleagues are not eligible choices.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T15 Approve and deliver the form

Actions  As Casey, open Reviews & Feedback and approve Drew’s nomination for Alex. Sign in as Drew in another profile and open Reviews & feedback.

Expected  Casey sees the recorded decision. Drew receives exactly one pending peer-feedback form for Alex in the open cycle.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T16 Submit peer feedback and reject another nomination

Actions  As Drew, complete the new form. As Alex, nominate Blair; as Casey, reject Blair’s nomination.

Expected  Drew’s request becomes read-only after submission. Blair receives no form from the rejected nomination. Repeated manager decisions are blocked.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T17 Manager review submission

Actions  As Casey, open Jamie Vale’s Demo manager review. Choose overall rating 4, rate every competency and enter a summary. Submit and reopen while the manager stage remains open.

Expected  The record becomes Manager submitted and saved ratings reappear. Alex’s separate open-stage review cannot be submitted as a manager review yet.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T18 Privacy and released results

Actions  As Jamie, inspect the newly submitted review. Then sign in as Morgan and open Employee > Reviews & feedback.

Expected  Jamie’s final results remain hidden until formal release. Morgan’s previous released review shows rating 4 and anonymous competency averages. Low-response aggregates remain hidden; HR release controls are teammate work.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

## Manager goals and improvement plans

### T19 Team search and employee detail

Actions  As Casey, open My Team; search Alex and use Needs attention and On track filters. Open Alex’s details, then clear the search.

Expected  Search and filters change visible rows. Details show only permitted review, goal, skill and development information.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T20 Assign a goal and development action

Actions  As Casey, create a goal and PDP action for Alex, each with a future due date and two steps. View them as Alex.

Expected  Both appear in Alex’s employee workspace. Casey retains ownership of the assigned definitions. A manager cannot create records for a non-direct-report through the API.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T21 Create an improvement plan

Actions  As Casey, create a new PIP for Jamie. Select Taylor as HR owner, dates from today through 30 days ahead, a reason, success criteria, an objective and steps.

Expected  The plan appears as Draft with the assigned employee, owner, period and initial objective. Invalid date ranges are rejected.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T22 Objectives and check-ins

Actions  Open the new PIP. Add a second objective inside the plan period and a dated check-in with progress notes.

Expected  Both records persist; the first check-in activates a draft PIP. Objective/check-in dates outside the period are rejected.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T23 PIP outcomes and locked records

Actions  Record an extension with an outcome note, then close the demonstration PIP with a final note. Attempt another step save or check-in.

Expected  Outcome notes are required. Closed plans reject new progress/check-ins and cannot return to Active. Use a separate PIP to demonstrate successful or unsuccessful outcomes.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T24 Employee and HR ownership views

Actions  As Jamie, inspect the assigned PIP and its check-in. For existing Drew’s PIP, compare Taylor’s HR API scope with Riley and Sam, using the automated tests.

Expected  The employee can see their own plan. Only an explicitly assigned HR owner with hr.pips gets HR PIP data. The dedicated HR case screen remains for the teammate.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

## Reports hierarchy and shared controls

### T25 Reports and export

Actions  As Casey, open Reports & Insights, generate a report, export CSV and open Print. Cancel printing after preview.

Expected  The report reflects the live team and ratings. CSV downloads as manager-team-performance.csv. The print dialog opens. Skill-gap display includes Alex’s structured communication gap.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T26 Notification persistence

Actions  Open Notifications, mark the current unread notifications as read, then reload.

Expected  The same notification identifiers stay read. A later new nomination or review task can appear as a separate unread notification.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T27 Hierarchy directory scope

Actions  Compare the organization page as Alex, Casey, Jordan and Riley. Search names and departments; expand reporting nodes.

Expected  Alex sees personal scope and their path; managers can inspect their permitted descendant directory; authorized HR can view the organization. Directory visibility does not grant private plan access.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T28 Department and team management

Actions  As Riley, open Organization hierarchy. Create unused department DEMO-QA and a team in it; rename them, then delete the empty team and department.

Expected  Changes persist and appear in lists. The team belongs to the selected department. Deleting a department or team with members is blocked.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T29 Membership and reporting change

Actions  In the isolated demo only, assign a test employee to a compatible department/team and change their primary manager with an effective date after the existing relationship start. Inspect the path.

Expected  Membership and the reporting path update. Historical reviews/goals retain their explicit owners. Self-reporting, circular reporting and an invalid team/department pair are rejected.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T30 Dotted-line relationship

Actions  As Riley, record a dotted-line relationship for a demo employee using the relationship selector. Check that the primary path is unchanged.

Expected  The supporting relationship is recorded without replacing the primary manager or granting private performance-record access. Reset the demo after hierarchy mutations before rerunning earlier tests.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

## Authentication validation and completion record

### T31 Password change and sign-out

Actions  Use a disposable demo account. Test a wrong current password, a weak password and mismatched confirmation; then set DemoPass12345 and sign out. Log in using the new password.

Expected  Invalid changes show errors. The valid password persists, the old password fails, and signed-out API requests are rejected. Reset the demo to restore password123.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T32 Password help and remembered email

Actions  At login, check Remember Me, sign in, then sign out. Open Password Help and enter a valid email.

Expected  The email is remembered locally. Password Help provides administrator-contact instructions; no reset email or automatic account recovery is claimed.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T33 Invalid forms and CSRF

Actions  Try blank goal fields, a past due date, more than 20 steps, a score outside 1 to 5 and an invalid PIP period. Run the automated API suite for missing-CSRF requests.

Expected  Invalid inputs are rejected without creating partial records. Requests missing a valid CSRF token cannot mutate protected data.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T34 Mobile and keyboard check

Actions  Use a phone-sized viewport. Navigate login, employee goals, feedback and manager tables. Tab through controls; open and close dialogs using keyboard controls.

Expected  Main content remains usable, forms are labelled, dialogs can close and tables scroll within their containers. Record any device-specific visual problem.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T35 Fresh setup and upgrade

Actions  On an isolated database, follow the fresh setup steps. Separately upgrade a copy of the old main database with migrations 001, 002 and 003 in order.

Expected  Fresh schema and fixture load. Existing user identities and records survive the upgrade; migrations stay in the selected database. Do not re-import the fresh schema over an existing database.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

### T36 Safe reset and automated checks

Actions  Run the Verify final product workflow or the commands in README.md against the test database. Run demo_reset.php only where the database name contains test.

Expected  The suite checks an unrelated account and goal survive demo reset. Record the workflow URL, commit and conclusion separately from manual case outcomes.

Result  Pass / Fail / Blocked     Evidence or defect ____________________

## Coverage and final signoff

| Feature group | Cases |
| --- | --- |
| Authentication and workspace selection | T01 to T03 and T31 to T34 |
| Personal goals and development plans | T05 to T12 |
| Self review peer nominations and feedback | T13 to T18 |
| Manager team and work assignment | T19 and T20 |
| Improvement plans and ownership | T21 to T24 |
| Reports notifications and organization | T04 and T25 to T30 |
| Setup migrations and reset | T35 and T36 |

Automated suites: tests/work_progress.php, tests/demo_reset.php, tests/employee_workspaces.mjs, tests/handoff.mjs and tests/browser.mjs. The GitHub Actions workflow also verifies schema upgrade and fresh setup. Inspect the latest run for the exact commit being demonstrated.

Automated run URL ____________________
Commit ____________________  Conclusion ____________________
Manual cases passed ____  Failed ____  Blocked ____
Outstanding defects and owner ________________________________________
Retest date ____________________

## Acceptance for this handoff

Accept the employee and manager handoff when the current automated checks pass, the priority demonstration cases pass locally, persisted changes are visible across the employee/manager boundary, and privacy controls behave as described. Record any local XAMPP issue before the live presentation.

The groupmate’s remaining work is the HR and administrator screens and their missing administrative workflows, including account/role management, review-cycle setup and release, and the HR case-management interface. Shared organization management and HR-owned PIP access already exist and should be reused.

Do not mark HR/admin placeholders, password reset email delivery, or employee-profile fields that have not been added as completed features. Formal review release must be demonstrated after the teammate implements its authorized controls.
