<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'perf_tracker';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }
}

function input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_login(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['user_id'])) json_response(['ok' => false, 'error' => 'Not authenticated'], 401);
    $stmt = db()->prepare("SELECT u.id, u.emp_code, u.full_name, u.email, u.role, u.job_title, u.department, r.display_name AS role_name, r.dashboard_path FROM users u JOIN roles r ON r.role_code=u.role WHERE u.id = ? AND u.is_active = 1 AND r.is_active = 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        json_response(['ok' => false, 'error' => 'User account not found'], 401);
    }
    $_SESSION['role'] = $user['role'];
    return $user;
}

function permissions_for_role(string $role): array {
    $stmt = db()->prepare("SELECT p.permission_code FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_code=? AND p.is_active=1 ORDER BY p.permission_code");
    $stmt->execute([$role]);
    return array_column($stmt->fetchAll(), 'permission_code');
}

function has_permission(int $userId, string $permission): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM users u JOIN role_permissions rp ON rp.role_code=u.role JOIN permissions p ON p.id=rp.permission_id WHERE u.id=? AND u.is_active=1 AND p.permission_code=? AND p.is_active=1");
    $stmt->execute([$userId, $permission]);
    return (bool)$stmt->fetchColumn();
}

function require_permission(string $permission): array {
    $user = require_login();
    if (!has_permission((int)$user['id'], $permission)) {
        audit((int)$user['id'], 'DENIED_PERMISSION', 'permission', null, $permission);
        json_response(['ok'=>false,'error'=>'You do not have permission to perform this action'],403);
    }
    return $user;
}

function audit(int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $detail = null): void {
    $stmt = db()->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, detail, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $entityType, $entityId, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function manager_employee(int $managerId, int $employeeId): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND manager_id = ? AND role = 'employee' AND is_active = 1");
    $stmt->execute([$employeeId, $managerId]);
    return (bool)$stmt->fetchColumn();
}

function participant_for_manager(int $managerId, int $participantId): ?array {
    $stmt = db()->prepare("SELECT * FROM review_participants WHERE id = ? AND manager_id = ?");
    $stmt->execute([$participantId, $managerId]);
    return $stmt->fetch() ?: null;
}
