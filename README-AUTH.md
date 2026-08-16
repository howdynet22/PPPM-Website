# PPPM authentication and authorization

## Included
- No registration flow.
- Database-backed login using `users.password_hash` and PHP `password_verify()`.
- Database-backed role-to-dashboard routing using `roles.dashboard_path`.
- Database-backed RBAC using `permissions` and `role_permissions`.
- Server-side permission enforcement in `api.php` for manager actions.
- Session-based authentication with HttpOnly + SameSite cookies.
- Logout that destroys the PHP session and cookie.
- Signed-in password change with current-password verification and `password_hash()`.
- Dashboard guards that redirect unauthenticated users to login and unauthorized users to their database-defined dashboard.
- UI permission hiding using the permissions returned by `api.php?action=me`.
- Audit entries for login, logout-related access, password changes, and denied permissions.

## Roles
- `admin`
- `hr`
- `manager`
- `employee`
- `leadership` (retained from the original schema and routed to the administrator dashboard)

## Demo credentials
All seeded accounts use `password123`.

- admin@demo.lk
- hr@demo.lk
- manager@demo.lk
- nimal@demo.lk
- amaya@demo.lk
- tharindu@demo.lk
- ishara@demo.lk

## Setup
1. Start Apache and MySQL in XAMPP.
2. Import `schema.sql` in phpMyAdmin. It creates the `perf_tracker` database.
3. Put the website directory under `htdocs`.
4. Open the site through `http://localhost/.../index.html` rather than using `file://`.
5. If MySQL root has a password, edit `config.php`.

## Password change
There is deliberately no public registration or public password reset. After login, each role gets a **Change password** control. The server requires the current password and writes a new PHP password hash to `users.password_hash`.

## RBAC
Do not rely on hiding buttons in the browser for security. The manager API also checks the database permissions before changing reviews, goals/PDPs, or PIPs. Add or remove access by editing `role_permissions` in MySQL.
