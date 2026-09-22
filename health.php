<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    require __DIR__ . '/config.php';
    db()->query('SELECT 1');
    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
