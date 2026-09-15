# PPPM Core Features

Performance and Development Tracker  |  Handoff inventory  |  15 September 2026

This inventory describes the integrated employee and manager product and the shared services available for the HR and administrator screens. Use it to divide the remaining group work and explain what the current application does during the demonstration.

## Handoff scope

The employee and manager workspaces, dynamic hierarchy and actionable progress model are integrated. The branch reconciliation retains all nine source branch histories, including the rollback history, while restoring the completed employee update. Existing branches remain available.

| Area | Current position |
| --- | --- |
| Employee and manager | Implemented workspaces and connected workflows described on pages 2 and 3. |
| Hierarchy and shared services | Implemented common page, permissions, data model and API foundations. |
| HR and administrator screens | Placeholder screens remain for the assigned teammate to build. |
| Review cycle administration | HR must implement cycle creation, participant assignment, stage transitions and formal release controls. |
| Account administration | User and role management interfaces, account recovery and any additional HR profile fields remain teammate work. |

## Boundaries to carry into the handoff

Password Help currently gives administrator-contact instructions; it does not send a reset email. Skill-gap reporting reads existing assessments and requirements; an assessment administration screen is not included. HR PIP ownership data is available, but a complete HR case-management screen is not included. Leadership currently uses the administrator destination as a placeholder.

The employee record stores employee code, name, email, system role, job title, department, team, joining date and active status. Employment type, location and a richer employment-status model are not current profile features; agree those fields with the teammate before building their forms.

No application is beyond further improvement. Handoff completion means the implemented employee and manager scope is integrated and checked; it does not mean the full HR/admin product or every possible enhancement is complete.

## Source of truth

Repository: https://github.com/howdynet22/PPPM-Website
Final destination: main
Companion document: PPPM Demonstration and Feature Tests

## Employee workspace

E01 Personal dashboard  Shows assigned goals, development plans, improvement plans, feedback requests and released review results. Counts come from stored records.

E02 Personal goals  Create a goal with a title, expected outcome, due date and 1 to 20 actionable steps. Manager-assigned goals also appear here.

E03 Development plans  View personal development plans and create development goals. Each action retains its assigning owner and due date.

E04 Step progress  Open a goal or objective and choose Not started, In progress, Blocked or Completed. Save optional progress or blocker notes; Mark complete and Reopen provide shortcuts.

E05 Definition ownership  The step author can rename, add or remove steps. An assignee can record progress on assigned steps but cannot rewrite the author’s definitions.

E06 Completion and health  Completion is calculated from completed steps. Reopening reverses completion. Blocked and overdue are separate indicators. A task with no steps cannot complete; cancelled actions and closed PIPs are read-only.

E07 Persistence and refresh  Successful changes persist after reload and appear in the owner’s view. Views refresh after saves, on focus and every 15 seconds while visible. Failed saves retain the draft. Version checks reject stale step saves.

E08 Self and peer feedback  Open assigned forms, rate every active competency from 1 to 5, add optional comments and submit once. Previously submitted forms remain readable. Submission windows are enforced.

E09 Peer nominations  Nominate an eligible active colleague for an open review, see pending/approved/rejected decisions, and prevent duplicate nominations. Approval creates the colleague’s feedback form.

E10 Released review results  Read the final rating and manager summary after formal release. Anonymous competency averages require the cycle’s minimum number of submitted peer responses; raw responses and respondent identities remain hidden.

E11 Personal improvement plans  Read PIP reason, dates, manager, HR owner, objectives, check-ins and outcome. Update assigned objective steps while the plan is open.

E12 Reporting path  Open My reporting path to see the employee’s chain to the top of the organization. The hierarchy is derived from reporting relationships.

## Manager workspace

M01 Dashboard and team directory  Shows team counts, available ratings, review tasks and development steps. Search employees and filter Needs attention or On track; open individual employee details.

M02 Access scope  Current direct reports support new assignments. Managers retain access to records explicitly owned by them. Descendants are directory-only unless the manager separately owns the performance record.

M03 Review assessment  For an assigned review in the manager-review stage, enter an overall rating, competency scores and summary. Save changes while the stage and deadline permit. Employee results remain hidden until release.

M04 Nomination decisions  Approve or reject pending nominations for assigned reviews. An approval and the assigned peer form are saved together. Repeated decisions and closed submission windows are rejected.

M05 Anonymous feedback  Inspect aggregate competency scores once the review’s anonymity threshold is met. Low response counts remain hidden.

M06 Team goals and PDPs  Create goals and development actions for direct reports. Set an outcome, due date and concrete steps; inspect progress and blockers through the shared work-item dialog.

M07 PIP creation  Create a draft improvement plan with an employee, explicit HR owner, reason, period, objective, success criteria and actionable steps.

M08 PIP follow-up  Add objectives and dated check-ins. Record permitted transitions among draft, active, extended, successful, unsuccessful and closed. Outcome notes are required for extension, outcomes and closure; completed plans reject further step edits.

M09 Reports and skill gaps  View rating charts, goal completion, active PIPs and current versus required skills. Generate a live team summary, export a CSV and use the browser print dialog.

M10 Notifications  Review pending review and nomination notifications and persist their read state across reloads.

M11 Personal workspace switch  Managers can switch to their own employee workspace using the same account. Their own goals and PDPs stay separate from team records.

## Shared capabilities and teammate handoff

S01 Authentication  Database-backed login, session checks, role-based destinations, password changes, sign-out, CSRF checks for mutations, session expiry and failed-login throttling are implemented. Remember Me remembers the email, not a persistent signed-in session.

S02 Permission model  Workspaces and API access are determined by role permissions. Job title and organizational seniority do not automatically grant access to other people’s private performance records.

S03 Organizational hierarchy  Primary reporting relationships support multiple levels and effective dates. One open primary relationship per employee, self-report checks, cycle rejection, historical ownership and dotted-line records are implemented.

S04 Organization management  The shared hierarchy page provides permission-controlled department and team management, employee membership assignment, reporting-line changes, directory search and reporting-path views. Referenced departments and teams cannot be deleted without moving their members.

S05 HR integration foundation  HR workspace metadata and explicitly owned HR PIP data are available. Senior HR, HR partner and HR coordinator permissions differ. These foundations are ready for the teammate’s screen implementation.

S06 Data and audit foundations  MySQL/MariaDB schema, ordered upgrade migrations, activity audit records and a registered fictional demo fixture are included. Demo seed is idempotent; reset targets registered demo content and preserves unrelated records.

S07 Verification automation  Repository checks cover PHP/JavaScript syntax, an upgrade from the previous main schema, fresh setup, demo reset, progress calculations, API workflows and browser demonstrations. Manual demonstration outcomes must be recorded separately.

## Integration contract

Reuse Js/auth.js, Js/work-items.js, css/styles.css and api.php. The me response supplies permissions, workspaces and a CSRF token. Each mutation must send X-CSRF-Token. HR can read workspace&scope=hr; executive counts are exposed through workspace&scope=executive. Apply migrations in order to existing databases and preserve current record-owner permissions.

Use an empty database for the focused demonstration. Import schema.sql and run php scripts/demo.php seed. The fixture contains 12 people, 11 personal development plans, three review cycles and an illustrative active PIP. All demo passwords are password123. Full accounts and setup instructions are in users.txt and README.md.
