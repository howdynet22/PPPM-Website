# PPPM Website

## Organization hierarchy

The tracker now uses normalized `departments`, `teams` and effective-dated
`reporting_relationships`. A user's `role` grants application permissions;
their `job_title` describes their position; and the active primary reporting
relationship determines their place in the organization. These three concepts
are intentionally independent.

The organization page supports searchable employees, department/team filters,
expandable branches, reporting paths and HR/administrator assignment forms.
Managers see directory information for direct reports and all descendants.
Performance records remain protected by their own `manager_id` owner, so an
ancestor does not automatically gain review, peer-feedback, goal, PDP or PIP
ownership.

## Actionable work steps

Goals, PDP actions and PIP objectives are measured with ordered, checkable
steps rather than percentages. Creation forms require at least one concrete
step, dashboards show completed steps out of total steps, and parent statuses
are recalculated when steps are checked or reopened.

The person who creates a task owns its step definitions. A self-created task
can therefore be edited by its assignee. When another person assigns the task,
the assignee can check steps off but cannot add, rename or remove them; those
changes remain with the person who set the steps. The API enforces this rule in
addition to hiding edit controls in the dashboard.

## Code cleanup update

- Moved all the page styling into one `css/styles.css` file.
- Removed the CSS that was written inside the HTML pages and JavaScript.
- Formatted the HTML, CSS, JavaScript, PHP and SQL so it is easier to read.
- Split long code and generated HTML into proper lines instead of one huge line.
- Added simple comments explaining what each main code section does.
- Renamed unclear temporary variables where it made the code easier to follow.
- Kept the website features and database behaviour the same.

## Manager dashboard update

I focused on completing the manager side of the website for this update.

- Connected all the manager dashboard buttons, searches and filters.
- Replaced the hard-coded manager profile details with data from the database.
- Fixed the team goal, PDP, review and report calculations.
- Fixed empty ratings so they show properly instead of showing `0.0/5`.
- Fixed submitted reviews so the saved manager summary loads again when editing.
- Added proper review-cycle checks so reviews can only be submitted at the correct time.
- Added better PIP validation, including checking the dates and required fields.
- Made notification read/unread changes save properly.
- Added CSRF protection to requests that change data.
- Added session expiry and better session security.
- Added login throttling to reduce repeated login attempts.
- Improved password requirements and backend error handling.
- Improved the security of CSV exports and anonymous feedback results.
- Fixed the login form HTML, labels and mobile layout.
- Updated the database schema and setup instructions.
- Added Apache security rules using `.htaccess`.
- Left the Employee, HR and Admin dashboards as placeholders for now.

## Running the project

1. Put the project folder inside `C:\\xampp\\htdocs\\pppm`.
2. Start Apache and MySQL in XAMPP.
3. Import `schema.sql` using phpMyAdmin.
4. Open `http://localhost/pppm/index.html`.
5. Log in with any account in `users.txt`; all demo passwords are `password123`.

Re-importing `schema.sql` will reset the demo database and remove existing test changes.

After importing, `tests/organization_checks.sql` provides read-only checks for
active-manager uniqueness, foreign-key integrity, the seeded reporting path,
historical ownership and job-title/permission separation.
`tests/actionable_steps_checks.sql` checks step ownership, parent links and
completed-status consistency.

### Upgrading an existing database

Back up the database, then import `migrations/001_organization.sql` once,
followed by `migrations/002_actionable_work_steps.sql`. The first migration
normalizes the organization structure. The second converts existing goal and
PDP percentages, plus PIP objective statuses, into seeded actionable steps and
then removes the obsolete percentage columns. Existing manager IDs are retained
as record ownership/history and as the authors of migrated step definitions.

Because the legacy schema stored no relationship dates, the employee's join
date is used as the earliest available effective date and that inference is
recorded in the relationship change note.

Unused departments and teams can be deleted from the organization page. The API
rejects deletion while employees or teams still reference the record. Active
records can also be retained and disabled with `is_active`.

Hierarchy traversal uses cycle-safe, level-by-level queries and does not depend on
recursive CTE support.

## Demo data update

- Added two managers with separate fictional teams for role-boundary testing.
- Added eleven fictional employees with different roles and skill levels.
- Added a completed review period and a current review period.
- Added mixed review stages, ratings and manager summaries.
- Added more peer nominations and anonymous 360 feedback.
- Added completed, active, missed and not-started goals.
- Added PDP actions with ordered, checkable steps and update notes.
- Added active, extended and successful PIP examples.
- Added sample notifications, login activity and audit records.
