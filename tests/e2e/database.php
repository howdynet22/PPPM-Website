<?php
// CLI only. Never accepts the application's usual database name or remote host.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = getenv('PPPM_DB_NAME');
if (!preg_match('/^pppm_pw_[0-9]{13}_[a-f0-9]{8}$/D', (string)$name)) {
    throw new RuntimeException('Refusing a database outside the generated Playwright namespace.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=' . (getenv('PPPM_DB_PORT') ?: '3306') . ';charset=utf8mb4',
    getenv('PPPM_DB_USER') ?: 'root', getenv('PPPM_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
if (($argv[1] ?? '') === 'drop') {
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    exit;
}
if (($argv[1] ?? '') !== 'create') throw new RuntimeException('Expected create or drop.');
// No IF NOT EXISTS: a collision must fail instead of adopting existing data.
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo->exec("USE `$name`");
    $schema = file_get_contents(__DIR__ . '/../../schema.sql');
    $schema = preg_replace('/^CREATE DATABASE IF NOT EXISTS .*?;\s*$/mi', '', $schema);
    $schema = preg_replace('/^USE\s+`?perf_tracker`?\s*;\s*$/mi', '', $schema);
    $pdo->exec($schema);
} catch (Throwable $error) {
    $pdo->exec("DROP DATABASE IF EXISTS `$name`");
    throw $error;
}

