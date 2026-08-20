<?php
declare(strict_types=1);

// Database and application settings.
define("DB_HOST", getenv("PPPM_DB_HOST") ?: "127.0.0.1");
define("DB_NAME", getenv("PPPM_DB_NAME") ?: "perf_tracker");
define("DB_USER", getenv("PPPM_DB_USER") ?: "root");
define("DB_PASS", getenv("PPPM_DB_PASS") ?: "");
define(
    "APP_DEBUG",
    filter_var(getenv("PPPM_APP_DEBUG") ?: "false", FILTER_VALIDATE_BOOLEAN),
);
define("SESSION_IDLE_TIMEOUT", 1800);

// Database connection.
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    return $pdo;
}

// Session security helpers.
function is_https(): bool
{
    return !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set("session.use_strict_mode", "1");
    session_name("PPPMSESSID");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => is_https(),
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_start();
}

function destroy_app_session(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", [
            "expires" => time() - 42000,
            "path" => $params["path"],
            "domain" => $params["domain"],
            "secure" => $params["secure"],
            "httponly" => $params["httponly"],
            "samesite" => $params["samesite"] ?? "Lax",
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// Safe JSON responses and request validation.
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: no-referrer");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function require_method(string $method): void
{
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== $method) {
        header("Allow: " . $method);
        json_response(["ok" => false, "error" => "Method not allowed"], 405);
    }
}

function input(): array
{
    $contentLength = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);
    if ($contentLength > 1_000_000) {
        json_response(["ok" => false, "error" => "Request is too large"], 413);
    }
    $raw = file_get_contents("php://input");
    if (!$raw) {
        return $_POST ?: [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(["ok" => false, "error" => "Invalid JSON request"], 400);
    }
    return $data;
}

// CSRF protection for database-changing requests.
function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION["csrf_token"];
}

function require_csrf(): void
{
    $provided = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if ($provided === "" || !hash_equals(csrf_token(), $provided)) {
        json_response(
            [
                "ok" => false,
                "error" =>
                    "Security token is missing or expired. Refresh the page and try again.",
            ],
            403,
        );
    }
}

// Login and role-permission checks.
function require_login(): array
{
    start_app_session();
    if (empty($_SESSION["user_id"])) {
        json_response(["ok" => false, "error" => "Not authenticated"], 401);
    }
    $lastActivity = (int) ($_SESSION["last_activity"] ?? time());
    if (time() - $lastActivity > SESSION_IDLE_TIMEOUT) {
        destroy_app_session();
        json_response(
            ["ok" => false, "error" => "Your session has expired"],
            401,
        );
    }
    $_SESSION["last_activity"] = time();
    $sql = <<<'SQL'
    SELECT
        u.id,
        u.emp_code,
        u.full_name,
        u.email,
        u.role,
        u.job_title,
        u.department,
        r.display_name AS role_name,
        r.dashboard_path
    FROM users u
    JOIN roles r ON r.role_code = u.role
    WHERE u.id = ?
      AND u.is_active = 1
      AND r.is_active = 1
    SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute([(int) $_SESSION["user_id"]]);
    $user = $stmt->fetch();
    if (!$user) {
        destroy_app_session();
        json_response(
            ["ok" => false, "error" => "User account not found"],
            401,
        );
    }
    $_SESSION["role"] = $user["role"];
    return $user;
}

function permissions_for_role(string $role): array
{
    $sql = <<<'SQL'
    SELECT p.permission_code
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE rp.role_code = ?
      AND p.is_active = 1
    ORDER BY p.permission_code
    SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute([$role]);
    return array_column($stmt->fetchAll(), "permission_code");
}

function has_permission(int $userId, string $permission): bool
{
    $sql = <<<'SQL'
    SELECT COUNT(*)
    FROM users u
    JOIN role_permissions rp ON rp.role_code = u.role
    JOIN permissions p ON p.id = rp.permission_id
    WHERE u.id = ?
      AND u.is_active = 1
      AND p.permission_code = ?
      AND p.is_active = 1
    SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId, $permission]);
    return (bool) $stmt->fetchColumn();
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!has_permission((int) $user["id"], $permission)) {
        audit(
            (int) $user["id"],
            "DENIED_PERMISSION",
            "permission",
            null,
            $permission,
        );
        json_response(
            [
                "ok" => false,
                "error" => "You do not have permission to perform this action",
            ],
            403,
        );
    }
    return $user;
}

// Audit logging and manager-to-employee relationship checks.
function client_ip(): ?string
{
    $ip = trim((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
    return $ip === "" ? null : substr($ip, 0, 45);
}

function audit(
    int $userId,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $detail = null,
): void {
    $stmt = db()->prepare(
        "INSERT INTO audit_log (user_id, action, entity_type, entity_id, detail, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
    );
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $detail,
        client_ip(),
    ]);
}

function manager_employee(int $managerId, int $employeeId): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM users WHERE id = ? AND manager_id = ? AND role = 'employee' AND is_active = 1",
    );
    $stmt->execute([$employeeId, $managerId]);
    return (bool) $stmt->fetchColumn();
}

function participant_for_manager(int $managerId, int $participantId): ?array
{
    $sql = <<<'SQL'
    SELECT rp.*, rc.status AS cycle_status
    FROM review_participants rp
    JOIN review_cycles rc ON rc.id = rp.cycle_id
    WHERE rp.id = ?
      AND rp.manager_id = ?
    SQL;
    $stmt = db()->prepare($sql);
    $stmt->execute([$participantId, $managerId]);
    return $stmt->fetch() ?: null;
}

// Strict date validation for forms.
function valid_date(string $value): bool
{
    if ($value === "") {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false &&
        ($errors === false ||
            ($errors["warning_count"] === 0 && $errors["error_count"] === 0)) &&
        $date->format("Y-m-d") === $value;
}
