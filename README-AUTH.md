# PPPM authentication, authorization and manager dashboard

## Current scope

This increment includes login/authentication, the database-backed Manager
dashboard, an employee workspace and a permission-scoped organization hierarchy.
See README.md for current setup and demo instructions.

## Included

- No public registration flow.
- Database-backed login using `users.password_hash` and PHP `password_verify()`.
- Database-backed role routing using `roles.dashboard_path`.
- Database-backed RBAC using `permissions` and `role_permissions`.
- Server-side permission and active primary-manager checks for new manager actions.
- Unlimited descendant directory scope without inheriting sensitive record ownership.
- Session authentication with strict-mode, HttpOnly and SameSite cookies.
- 30-minute idle session expiry and session-ID regeneration.
- CSRF tokens on every authenticated state-changing request.
- Sign-in throttling after repeated failed attempts.
- Logout that destroys the session and writes an audit entry.
- Signed-in password change with current-password verification and a stronger
  password rule.
- Generic production errors; set `PPPM_APP_DEBUG=true` locally only when
  detailed API errors are needed.
- Anonymous peer results hidden until the review cycle's minimum response
  threshold is reached.
- Persistent read/unread manager notifications.
- Manager workflows for reviews, peer decisions, goals, PDP actions, PIPs,
  reports and CSV export.

## Roles

- `admin`
- `hr`
- `hr_partner`
- `hr_coordinator`
- `manager`
- `employee`
- `leadership` (currently routed to the Administrator placeholder)

Job titles never grant permissions. `role` is the system role, `job_title` is
the organizational position, and `reporting_relationships` contains the dated
manager links.

## Demo credentials and reporting examples

See `users.txt` for the 12 fictional accounts and `README.md` for the focused demo.
All demo passwords start as `password123`. Managers and HR staff also have personal
employee workspaces. Job titles do not grant permissions.

## Required folder layout

Keep the directory names and capitalization exactly as shown:

```text
project/
  .htaccess
  api.php
  config.php
  organization.php
  org-structure.html
  index.html
  manager-dashboard.html
  css/
    styles.css
  Js/
    auth.js
    login.js
    forgot-password.js
    manager-db.js
    organization.js
  migrations/
    001_organization.sql
```

Linux hosting is case-sensitive, so `Js` and `js` are not interchangeable.

## Local setup

1. Use PHP 8.1 or newer and MySQL 8/MariaDB through XAMPP.
2. Start Apache and MySQL.
3. Import `schema.sql` into a new demo database and run `php scripts/demo.php seed`.
4. Put the project under XAMPP's `htdocs` directory.
5. Open `http://localhost/.../index.html`; do not use `file://`.
6. Sign in with an account in `users.txt` and `password123`.

The schema no longer drops databases. Use migration 003 to upgrade the latest
branch, and the separate demo seed/reset commands documented in README.md.

## Database configuration

Local XAMPP defaults remain available, but deployment credentials should be
provided as environment variables:

- `PPPM_DB_HOST`
- `PPPM_DB_NAME`
- `PPPM_DB_USER`
- `PPPM_DB_PASS`
- `PPPM_APP_DEBUG` (leave false in production)

## Password recovery

There is deliberately no fake email reset. The Password Help page tells a
locked-out user to contact the system administrator. A real emailed reset or
MFA flow requires a mail provider and will be implemented in a later increment.

## Deployment warning

GitHub Pages can display static HTML, CSS and JavaScript, but it cannot execute
PHP or host MySQL. This complete application requires a PHP/MySQL host.

## Security notes

The browser may hide controls the current role cannot use, but the API is
authoritative. Organization writes require a session, CSRF token and
`org.structure.manage`; manager workflow writes additionally enforce the active
primary manager or the business record's stored owner.

Keep `.htaccess` in the web root. It disables directory listing, adds browser
security headers and blocks direct access to configuration, schema, credential
notes and setup documentation.
