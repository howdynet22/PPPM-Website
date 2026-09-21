# Notification integration verification

## Integration decisions

- The group contribution was ported onto the current workflow implementation rather than replacing `api.php`, `hr.php`, or `workspaces.php` with older copies.
- The new migration is `migrations/011_notifications.sql`, because current main already uses migrations through 010.
- Notifications are persistent, user-owned database rows. Read updates are scoped by both notification ID and signed-in user ID.
- HR receives the cycle-complete notification only when a cycle moves from `released` to `closed`. Employees receive their results notification at `released`.
- Reminder delivery and its duplicate guard are committed atomically. The incorrect `pa.employee_id` reference was replaced with the owning `pdps.employee_id` relationship.
- Reminder execution is CLI-only through `php scripts/run-reminders.php`; no unauthenticated web/cron endpoint was added.
- Personal and Manager interfaces reload notification rows through their existing database refresh behavior. The Personal interface also exposes a notification inbox and mark-all-read action.

## Automated coverage

- `tests/notifications.php`
  - recipient isolation;
  - cross-user mark-read denial;
  - persistent read state;
  - corrected PDP reminder query;
  - same-day reminder deduplication.
- `tests/review_cycle_lifecycle.mjs`
  - notification created when a cycle opens;
  - Manager receives nomination actions;
  - decided nomination no longer stays unread;
  - Personal UI refreshes from a newly written database notification;
  - employee result notice occurs at release;
  - HR close notice is absent at release and present at close;
  - the complete draft-to-closed review workflow and anonymity checks still pass.
- `.github/workflows/system-logic-audit.yml` runs the notification tests alongside the existing HTTP, SQL, permission, workflow, and Chromium UI regressions.

## Deployment requirements

1. Back up the production database.
2. Apply migrations in order through `migrations/011_notifications.sql`.
3. Configure a trusted scheduler to run `php scripts/run-reminders.php` daily if automated reminders are wanted.
4. Run the System logic audit workflow against the deployment candidate.
