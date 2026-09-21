<?php
declare(strict_types=1);

/**
 * System administration services.
 *
 * Two rules shape every write here. An administrator may not remove their own
 * administrative access, and the installation must always keep at least one
 * active account that can still manage users. Accounts are deactivated rather
 * than deleted so that audit, review and plan history stays intact.
 */

define("ADMIN_KEY_PERMISSION", "admin.users");

function admin_permissions(array $user): array
{
    return $user["permissions"] ?? permissions_for_role($user["role"]);
}

function admin_can(array $user, string $permission): bool
{
    return in_array($permission, admin_permissions($user), true);
}

/** Roles that can still manage users, used by the lockout guards. */
function admin_capable_roles(): array
{
    $stmt = db()->prepare(
        "SELECT rp.role_code FROM role_permissions rp
         JOIN permissions p ON p.id=rp.permission_id
         JOIN roles r ON r.role_code=rp.role_code
         WHERE p.permission_code=? AND p.is_active=1 AND r.is_active=1",
    );
    $stmt->execute([ADMIN_KEY_PERMISSION]);
    return array_column($stmt->fetchAll(), "role_code");
}

/** Count active accounts that can manage users, optionally ignoring one user. */
function admin_capable_user_count(?int $excludeUserId = null): int
{
    $roles = admin_capable_roles();
    if (!$roles) {
        return 0;
    }
    $placeholders = implode(",", array_fill(0, count($roles), "?"));
    $sql = "SELECT COUNT(*) FROM users WHERE is_active=1 AND role IN ({$placeholders})";
    $params = $roles;
    if ($excludeUserId !== null) {
        $sql .= " AND id<>?";
        $params[] = $excludeUserId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Stop a change that would leave nobody able to administer the system. */
function admin_guard_last_administrator(?int $excludeUserId = null): void
{
    if (admin_capable_user_count($excludeUserId) < 1) {
        json_response(
            [
                "ok" => false,
                "error" =>
                    "This change would leave no active account able to manage users. Grant another account administrator access first.",
            ],
            409,
        );
    }
}

/** A temporary password that satisfies the sign-in policy. */
function admin_temporary_password(): string
{
    $sets = ["ABCDEFGHJKLMNPQRSTUVWXYZ", "abcdefghijkmnpqrstuvwxyz", "23456789"];
    $password = "";
    foreach ($sets as $set) {
        for ($i = 0; $i < 4; $i++) {
            $password .= $set[random_int(0, strlen($set) - 1)];
        }
    }
    return str_shuffle($password);
}

function admin_valid_email(string $email): bool
{
    return $email !== "" &&
        strlen($email) <= 120 &&
        filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function admin_overview_metrics(): array
{
    $pdo = db();
    $scalar = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
    return [
        "users" => $scalar("SELECT COUNT(*) FROM users"),
        "activeUsers" => $scalar("SELECT COUNT(*) FROM users WHERE is_active=1"),
        "inactiveUsers" => $scalar("SELECT COUNT(*) FROM users WHERE is_active=0"),
        "roles" => $scalar("SELECT COUNT(*) FROM roles WHERE is_active=1"),
        "permissions" => $scalar("SELECT COUNT(*) FROM permissions WHERE is_active=1"),
        "administrators" => admin_capable_user_count(),
        "failedLogins24h" => $scalar(
            "SELECT COUNT(*) FROM login_attempts
             WHERE success=0 AND attempted_at > NOW() - INTERVAL 1 DAY",
        ),
        "successfulLogins24h" => $scalar(
            "SELECT COUNT(*) FROM login_attempts
             WHERE success=1 AND attempted_at > NOW() - INTERVAL 1 DAY",
        ),
        "auditEvents7d" => $scalar(
            "SELECT COUNT(*) FROM audit_log WHERE created_at > NOW() - INTERVAL 7 DAY",
        ),
        "deniedEvents7d" => $scalar(
            "SELECT COUNT(*) FROM audit_log
             WHERE action LIKE 'DENIED%' AND created_at > NOW() - INTERVAL 7 DAY",
        ),
        "usersWithoutManager" => $scalar(
            "SELECT COUNT(*) FROM users u WHERE u.is_active=1
             AND NOT EXISTS (SELECT 1 FROM active_primary_relationships rr WHERE rr.employee_id=u.id)",
        ),
    ];
}

function admin_user_rows(array $filters): array
{
    $sql = "SELECT u.id,u.emp_code,u.full_name,u.email,u.role,u.job_title,u.is_active,u.review_eligible,
                   u.date_joined,u.created_at,u.department_id,u.team_id,
                   r.display_name AS role_name,d.department_name,t.team_name,
                   mgr.id AS manager_id,mgr.full_name AS manager_name,
                   (SELECT MAX(attempted_at) FROM login_attempts la
                     WHERE la.email=u.email AND la.success=1) AS last_sign_in
            FROM users u
            JOIN roles r ON r.role_code=u.role
            JOIN departments d ON d.id=u.department_id
            LEFT JOIN teams t ON t.id=u.team_id
            LEFT JOIN active_primary_relationships rr ON rr.employee_id=u.id
            LEFT JOIN users mgr ON mgr.id=rr.reports_to_employee_id
            WHERE 1=1";
    $params = [];
    $search = trim((string) ($filters["search"] ?? ""));
    if ($search !== "") {
        $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.emp_code LIKE ?)";
        $like = "%" . $search . "%";
        array_push($params, $like, $like, $like);
    }
    $role = trim((string) ($filters["role"] ?? ""));
    if ($role !== "") {
        $sql .= " AND u.role=?";
        $params[] = $role;
    }
    $status = (string) ($filters["status"] ?? "");
    if ($status === "active") {
        $sql .= " AND u.is_active=1";
    } elseif ($status === "inactive") {
        $sql .= " AND u.is_active=0";
    }
    $sql .= " ORDER BY u.full_name LIMIT 500";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function admin_reference_data(): array
{
    $pdo = db();
    return [
        "roles" => $pdo
            ->query(
                "SELECT r.role_code,r.display_name,r.dashboard_path,r.is_active,
                        (SELECT COUNT(*) FROM users u WHERE u.role=r.role_code) AS user_count,
                        (SELECT COUNT(*) FROM users u WHERE u.role=r.role_code AND u.is_active=1) AS active_user_count
                 FROM roles r ORDER BY r.display_name",
            )
            ->fetchAll(),
        "departments" => $pdo
            ->query(
                "SELECT id,department_code,department_name,is_active FROM departments
                 WHERE is_active=1 ORDER BY department_name",
            )
            ->fetchAll(),
        "teams" => $pdo
            ->query(
                "SELECT id,department_id,team_name FROM teams WHERE is_active=1 ORDER BY team_name",
            )
            ->fetchAll(),
    ];
}

/** Roles, permissions and the grant matrix between them. */
function admin_role_matrix(): array
{
    $pdo = db();
    $grants = $pdo
        ->query(
            "SELECT rp.role_code,p.permission_code FROM role_permissions rp
             JOIN permissions p ON p.id=rp.permission_id",
        )
        ->fetchAll();
    $matrix = [];
    foreach ($grants as $grant) {
        $matrix[$grant["role_code"]][] = $grant["permission_code"];
    }
    return [
        "permissions" => $pdo
            ->query(
                "SELECT id,permission_code,description,is_active FROM permissions ORDER BY permission_code",
            )
            ->fetchAll(),
        "matrix" => $matrix,
    ];
}

function admin_audit_rows(array $filters): array
{
    $sql = "SELECT a.id,a.action,a.entity_type,a.entity_id,a.detail,a.ip_address,a.created_at,
                   a.user_id,u.full_name AS user_name,u.email
            FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE 1=1";
    $params = [];
    $search = trim((string) ($filters["search"] ?? ""));
    if ($search !== "") {
        $sql .= " AND (a.action LIKE ? OR a.detail LIKE ? OR u.full_name LIKE ?)";
        $like = "%" . $search . "%";
        array_push($params, $like, $like, $like);
    }
    $userId = (int) ($filters["userId"] ?? 0);
    if ($userId > 0) {
        $sql .= " AND a.user_id=?";
        $params[] = $userId;
    }
    $from = trim((string) ($filters["from"] ?? ""));
    if ($from !== "" && valid_date($from)) {
        $sql .= " AND a.created_at >= ?";
        $params[] = $from . " 00:00:00";
    }
    $to = trim((string) ($filters["to"] ?? ""));
    if ($to !== "" && valid_date($to)) {
        $sql .= " AND a.created_at <= ?";
        $params[] = $to . " 23:59:59";
    }
    $limit = min(500, max(25, (int) ($filters["limit"] ?? 200)));
    $sql .= " ORDER BY a.created_at DESC,a.id DESC LIMIT " . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Recent sign-in activity, summarised per account. */
function admin_security_rows(): array
{
    return db()
        ->query(
            "SELECT la.email,
                    SUM(la.success=0) AS failures,
                    SUM(la.success=1) AS successes,
                    MAX(la.attempted_at) AS last_attempt,
                    MAX(CASE WHEN la.success=0 THEN la.ip_address END) AS last_failed_ip,
                    (SELECT u.is_active FROM users u WHERE u.email=la.email) AS is_active
             FROM login_attempts la
             WHERE la.attempted_at > NOW() - INTERVAL 7 DAY
             GROUP BY la.email
             ORDER BY failures DESC,last_attempt DESC
             LIMIT 100",
        )
        ->fetchAll();
}

function admin_api(string $action): never
{
    $writes = [
        "admin_user_save",
        "admin_user_status",
        "admin_user_password",
        "admin_role_permissions",
        "admin_role_save",
    ];
    if (in_array($action, $writes, true)) {
        require_method("POST");
        require_csrf();
        $user = require_login();
        $in = input();
    } else {
        require_method("GET");
        $user = require_permission("admin.dashboard");
        $in = $_GET;
    }
    $user["permissions"] = permissions_for_role($user["role"]);
    $actorId = (int) $user["id"];
    $need = static function (array $user, string $permission) use ($actorId): void {
        if (!admin_can($user, $permission)) {
            audit($actorId, "DENIED_PERMISSION", "permission", null, $permission);
            json_response(
                ["ok" => false, "error" => "You do not have permission to perform this action"],
                403,
            );
        }
    };

    switch ($action) {
        // Everything the administrator dashboard renders on load. Sections the
        // signed-in role cannot use come back empty rather than being faked.
        case "admin_dashboard":
            $reference = admin_reference_data();
            $payload = [
                "ok" => true,
                "user" => $user,
                "metrics" => admin_overview_metrics(),
                "users" => admin_can($user, "admin.users")
                    ? admin_user_rows([
                        "search" => (string) ($in["search"] ?? ""),
                        "role" => (string) ($in["role"] ?? ""),
                        "status" => (string) ($in["status"] ?? ""),
                    ])
                    : [],
                "roles" => $reference["roles"],
                "departments" => $reference["departments"],
                "teams" => $reference["teams"],
                "rolePermissions" => admin_can($user, "admin.roles") ? admin_role_matrix() : null,
                "audit" => admin_can($user, "admin.audit") ? admin_audit_rows(["limit" => 100]) : [],
                "security" => admin_can($user, "admin.audit") ? admin_security_rows() : [],
                "csrfToken" => csrf_token(),
            ];
            json_response($payload);

        case "admin_users":
            $need($user, "admin.users");
            json_response([
                "ok" => true,
                "users" => admin_user_rows([
                    "search" => (string) ($in["search"] ?? ""),
                    "role" => (string) ($in["role"] ?? ""),
                    "status" => (string) ($in["status"] ?? ""),
                ]),
            ]);

        case "admin_audit":
            $need($user, "admin.audit");
            json_response([
                "ok" => true,
                "audit" => admin_audit_rows([
                    "search" => (string) ($in["search"] ?? ""),
                    "userId" => (int) ($in["userId"] ?? 0),
                    "from" => (string) ($in["from"] ?? ""),
                    "to" => (string) ($in["to"] ?? ""),
                    "limit" => (int) ($in["limit"] ?? 200),
                ]),
            ]);

        // Create a new account or edit an existing one.
        case "admin_user_save":
            $need($user, "admin.users");
            $id = (int) ($in["id"] ?? 0);
            $fullName = trim((string) ($in["fullName"] ?? ""));
            $email = strtolower(trim((string) ($in["email"] ?? "")));
            $empCode = strtoupper(trim((string) ($in["empCode"] ?? "")));
            $role = trim((string) ($in["role"] ?? ""));
            $jobTitle = trim((string) ($in["jobTitle"] ?? ""));
            $departmentId = (int) ($in["departmentId"] ?? 0);
            $teamId = isset($in["teamId"]) && $in["teamId"] !== "" ? (int) $in["teamId"] : null;
            $dateJoined = trim((string) ($in["dateJoined"] ?? ""));
            $managerId = isset($in["managerId"]) && $in["managerId"] !== "" ? (int) $in["managerId"] : null;
            $reviewEligible = filter_var($in['reviewEligible'] ?? true,FILTER_VALIDATE_BOOLEAN);

            if ($fullName === "" || strlen($fullName) > 120) {
                json_response(["ok" => false, "error" => "Enter a full name"], 422);
            }
            if (!admin_valid_email($email)) {
                json_response(["ok" => false, "error" => "Enter a valid email address"], 422);
            }
            if ($empCode === "" || strlen($empCode) > 20) {
                json_response(["ok" => false, "error" => "Enter an employee code"], 422);
            }
            if (strlen($jobTitle) > 100) {
                json_response(["ok" => false, "error" => "The job title is too long"], 422);
            }
            if ($dateJoined !== "" && !valid_date($dateJoined)) {
                json_response(["ok" => false, "error" => "Enter a valid joining date"], 422);
            }
            $stmt = db()->prepare("SELECT COUNT(*) FROM roles WHERE role_code=? AND is_active=1");
            $stmt->execute([$role]);
            if (!(bool) $stmt->fetchColumn()) {
                json_response(["ok" => false, "error" => "Choose an active role"], 422);
            }
            $stmt = db()->prepare("SELECT COUNT(*) FROM departments WHERE id=? AND is_active=1");
            $stmt->execute([$departmentId]);
            if (!(bool) $stmt->fetchColumn()) {
                json_response(["ok" => false, "error" => "Choose an active department"], 422);
            }
            if ($teamId !== null) {
                $stmt = db()->prepare(
                    "SELECT COUNT(*) FROM teams WHERE id=? AND department_id=? AND is_active=1",
                );
                $stmt->execute([$teamId, $departmentId]);
                if (!(bool) $stmt->fetchColumn()) {
                    json_response(
                        ["ok" => false, "error" => "The team must belong to the selected department"],
                        422,
                    );
                }
            }
            $stmt = db()->prepare("SELECT id FROM users WHERE (email=? OR emp_code=?) AND id<>?");
            $stmt->execute([$email, $empCode, $id]);
            if ($stmt->fetch()) {
                json_response(
                    ["ok" => false, "error" => "That email address or employee code is already in use"],
                    409,
                );
            }

            $temporaryPassword = null;
            if ($id > 0) {
                $stmt = db()->prepare("SELECT role,is_active FROM users WHERE id=?");
                $stmt->execute([$id]);
                $existing = $stmt->fetch();
                if (!$existing) {
                    json_response(["ok" => false, "error" => "User not found"], 404);
                }
                // An administrator cannot change their own role out of access.
                if ($id === $actorId && $role !== $existing["role"]) {
                    json_response(
                        ["ok" => false, "error" => "You cannot change your own role. Ask another administrator."],
                        409,
                    );
                }
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare(
                    "UPDATE users SET full_name=?,email=?,emp_code=?,role=?,job_title=?,
                     department_id=?,team_id=?,date_joined=?,review_eligible=? WHERE id=?",
                )->execute([
                    $fullName,
                    $email,
                    $empCode,
                    $role,
                    $jobTitle !== "" ? $jobTitle : null,
                    $departmentId,
                    $teamId,
                    $dateJoined !== "" ? $dateJoined : null,
                    $reviewEligible ? 1 : 0,
                    $id,
                ]);
                if (
                    (int) $existing["is_active"] === 1 &&
                    $role !== $existing["role"] &&
                    admin_capable_user_count() < 1
                ) {
                    $pdo->rollBack();
                    json_response(
                        [
                            "ok" => false,
                            "error" =>
                                "This role change would leave no active account able to manage users.",
                        ],
                        409,
                    );
                }
                $pdo->commit();
                audit($actorId, "UPDATE_USER", "user", $id, "Role {$role}; department {$departmentId}");
            } else {
                $temporaryPassword = admin_temporary_password();
                db()->prepare(
                    "INSERT INTO users(emp_code,full_name,email,password_hash,role,job_title,
                     department_id,team_id,date_joined,is_active,review_eligible) VALUES(?,?,?,?,?,?,?,?,?,1,?)",
                )->execute([
                    $empCode,
                    $fullName,
                    $email,
                    password_hash($temporaryPassword, PASSWORD_DEFAULT),
                    $role,
                    $jobTitle !== "" ? $jobTitle : null,
                    $departmentId,
                    $teamId,
                    $dateJoined !== "" ? $dateJoined : null,
                    $reviewEligible ? 1 : 0,
                ]);
                $id = (int) db()->lastInsertId();
                audit($actorId, "CREATE_USER", "user", $id, "Created {$email} with role {$role}");
            }

            $managerWarning = null;
            if ($managerId !== null && $managerId > 0) {
                try {
                    assign_primary_manager(
                        $id,
                        $managerId,
                        date("Y-m-d"),
                        $actorId,
                        "Set from the administrator dashboard",
                    );
                } catch (InvalidArgumentException $e) {
                    // The account is saved; only the reporting line failed.
                    $managerWarning = $e->getMessage();
                }
            }
            json_response([
                "ok" => true,
                "id" => $id,
                "temporaryPassword" => $temporaryPassword,
                "managerWarning" => $managerWarning,
                "message" => $temporaryPassword ? "Account created." : "Account updated.",
            ]);

        // Activate or deactivate an account.
        case "admin_user_status":
            $need($user, "admin.users");
            $id = (int) ($in["id"] ?? 0);
            $active = (bool) ($in["isActive"] ?? false);
            if ($id === $actorId) {
                json_response(
                    ["ok" => false, "error" => "You cannot change your own account status"],
                    409,
                );
            }
            $stmt = db()->prepare("SELECT full_name,is_active FROM users WHERE id=?");
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                json_response(["ok" => false, "error" => "User not found"], 404);
            }
            if (!$active) {
                admin_guard_last_administrator($id);
                $affected=db()->prepare("SELECT
                  (SELECT COUNT(*) FROM active_primary_relationships WHERE reports_to_employee_id=?) +
                  (SELECT COUNT(*) FROM review_participants rp JOIN review_cycles rc ON rc.id=rp.cycle_id WHERE rp.action_manager_id=? AND rc.status IN ('open','peer_review','manager_review')) +
                  (SELECT COUNT(*) FROM goals WHERE manager_id=? AND status NOT IN ('completed','missed')) +
                  (SELECT COUNT(*) FROM pdps WHERE manager_id=? AND status NOT IN ('completed','cancelled')) +
                  (SELECT COUNT(*) FROM pips WHERE manager_id=? AND status NOT IN ('successful','unsuccessful','closed'))");
                $affected->execute([$id,$id,$id,$id,$id]);
                if((int)$affected->fetchColumn()>0) json_response(['ok'=>false,'error'=>'Reassign this person’s active reports and performance records before deactivating the account.'],409);
            }
            db()->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$active ? 1 : 0, $id]);
            audit(
                $actorId,
                $active ? "ACTIVATE_USER" : "DEACTIVATE_USER",
                "user",
                $id,
                $target["full_name"],
            );
            json_response([
                "ok" => true,
                "message" => $active ? "Account activated." : "Account deactivated.",
            ]);

        // Issue a temporary password for an account.
        case "admin_user_password":
            $need($user, "admin.users");
            $id = (int) ($in["id"] ?? 0);
            $stmt = db()->prepare("SELECT full_name FROM users WHERE id=?");
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                json_response(["ok" => false, "error" => "User not found"], 404);
            }
            $temporaryPassword = admin_temporary_password();
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([password_hash($temporaryPassword, PASSWORD_DEFAULT), $id]);
            audit($actorId, "RESET_USER_PASSWORD", "user", $id, "Temporary password issued");
            json_response([
                "ok" => true,
                "temporaryPassword" => $temporaryPassword,
                "message" => "Temporary password issued for " . $target["full_name"] . ".",
            ]);

        // Edit a role's label, destination and availability.
        case "admin_role_save":
            $need($user, "admin.roles");
            $roleCode = trim((string) ($in["roleCode"] ?? ""));
            $displayName = trim((string) ($in["displayName"] ?? ""));
            $dashboardPath = trim((string) ($in["dashboardPath"] ?? ""));
            $isActive = (bool) ($in["isActive"] ?? true);
            $allowedPaths = [
                "admin-dashboard.html",
                "hr-dashboard.html",
                "manager-dashboard.html",
                "employee-dashboard.html",
            ];
            if ($displayName === "" || strlen($displayName) > 60) {
                json_response(["ok" => false, "error" => "Enter a role name"], 422);
            }
            if (!in_array($dashboardPath, $allowedPaths, true)) {
                json_response(["ok" => false, "error" => "Choose a valid destination dashboard"], 422);
            }
            $stmt = db()->prepare("SELECT role_code FROM roles WHERE role_code=?");
            $stmt->execute([$roleCode]);
            if (!$stmt->fetch()) {
                json_response(["ok" => false, "error" => "Role not found"], 404);
            }
            if (!$isActive) {
                $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role=? AND is_active=1");
                $stmt->execute([$roleCode]);
                if ((int) $stmt->fetchColumn() > 0) {
                    json_response(
                        ["ok" => false, "error" => "Move active accounts off this role before retiring it"],
                        409,
                    );
                }
                if ($roleCode === $user["role"]) {
                    json_response(["ok" => false, "error" => "You cannot retire your own role"], 409);
                }
            }
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare(
                "UPDATE roles SET display_name=?,dashboard_path=?,is_active=? WHERE role_code=?",
            )->execute([$displayName, $dashboardPath, $isActive ? 1 : 0, $roleCode]);
            if (!$isActive && admin_capable_user_count() < 1) {
                $pdo->rollBack();
                json_response(
                    [
                        "ok" => false,
                        "error" =>
                            "Retiring this role would leave no active account able to manage users.",
                    ],
                    409,
                );
            }
            $pdo->commit();
            audit($actorId, "UPDATE_ROLE", "role", null, $roleCode . " → " . $displayName);
            json_response(["ok" => true, "message" => "Role updated."]);

        // Replace the permission set granted to one role.
        case "admin_role_permissions":
            $need($user, "admin.roles");
            $roleCode = trim((string) ($in["roleCode"] ?? ""));
            $codes = array_values(
                array_unique(array_filter(array_map("strval", (array) ($in["permissions"] ?? [])))),
            );
            $stmt = db()->prepare("SELECT COUNT(*) FROM roles WHERE role_code=?");
            $stmt->execute([$roleCode]);
            if (!(bool) $stmt->fetchColumn()) {
                json_response(["ok" => false, "error" => "Role not found"], 404);
            }
            // Keep the signed-in administrator's own access intact.
            if ($roleCode === $user["role"]) {
                foreach (["admin.dashboard", "admin.users", "admin.roles"] as $keep) {
                    if (admin_can($user, $keep) && !in_array($keep, $codes, true)) {
                        json_response(
                            [
                                "ok" => false,
                                "error" =>
                                    "You cannot remove " . $keep . " from your own role. Ask another administrator.",
                            ],
                            409,
                        );
                    }
                }
            }
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM role_permissions WHERE role_code=?")->execute([$roleCode]);
                if ($codes) {
                    $placeholders = implode(",", array_fill(0, count($codes), "?"));
                    $pdo->prepare(
                        "INSERT INTO role_permissions(role_code,permission_id)
                         SELECT ?,id FROM permissions WHERE permission_code IN ({$placeholders}) AND is_active=1",
                    )->execute(array_merge([$roleCode], $codes));
                }
                // Measure the result before it is durable, so a change that
                // locks everyone out is discarded rather than committed.
                if (admin_capable_user_count() < 1) {
                    $pdo->rollBack();
                    json_response(
                        [
                            "ok" => false,
                            "error" =>
                                "This change would leave no active account able to manage users. Grant another role administrator access first.",
                        ],
                        409,
                    );
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            audit(
                $actorId,
                "UPDATE_ROLE_PERMISSIONS",
                "role",
                null,
                $roleCode . ": " . count($codes) . " permissions",
            );
            json_response(["ok" => true, "message" => "Permissions updated."]);

        default:
            json_response(["ok" => false, "error" => "Unknown action"], 404);
    }
}
