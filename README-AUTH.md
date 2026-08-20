# PPPM authentication, authorization and manager dashboard

## Current scope

This increment completes the login/authentication layer and the database-backed
Manager dashboard. Employee, HR and Administrator dashboards intentionally
remain permission-protected placeholders for later increments.

## Included

- No public registration flow.
- Database-backed login using `users.password_hash` and PHP `password_verify()`.
- Database-backed role routing using `roles.dashboard_path`.
- Database-backed RBAC using `permissions` and `role_permissions`.
- Server-side permission and direct-report checks for every manager action.
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
- `manager`
- `employee`
- `leadership` (currently routed to the Administrator placeholder)

## Demo credentials

All seeded accounts initially use `password123`.

- admin@demo.lk
- hr@demo.lk
- manager@demo.lk
- nimal@demo.lk
- amaya@demo.lk
- tharindu@demo.lk
- ishara@demo.lk

Change demo passwords before using the application with real data.

## Required folder layout

Keep the directory names and capitalization exactly as shown:

```text
project/
  .htaccess
  api.php
  config.php
  index.html
  manager-dashboard.html
  css/
    styles.css
  Js/
    auth.js
    login.js
    forgot-password.js
    manager-db.js
```

Linux hosting is case-sensitive, so `Js` and `js` are not interchangeable.

## Local setup

1. Use PHP 8.1 or newer and MySQL 8/MariaDB through XAMPP.
2. Start Apache and MySQL.
3. Import `schema.sql` in phpMyAdmin.
4. Put the project under XAMPP's `htdocs` directory.
5. Open `http://localhost/.../index.html`; do not use `file://`.
6. Sign in with `manager@demo.lk` and `password123`.

The supplied schema begins with `DROP DATABASE IF EXISTS perf_tracker`.
Re-importing it resets the demo database and removes existing test changes.

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

The browser may hide controls the current role cannot use, but that is only a
usability feature. The API is authoritative: it checks the session, CSRF token,
permission and manager-to-employee relationship before changing data.

Keep `.htaccess` in the web root. It disables directory listing, adds browser
security headers and blocks direct access to configuration, schema, credential
notes and setup documentation.
