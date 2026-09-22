<?php
declare(strict_types=1);

// Railway/container startup bootstrap. It creates a fresh schema once and
// leaves every subsequent deployment (and all application data) untouched.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config.php';

$pdo = null;
$lastError = null;
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $pdo = db();
        $pdo->query('SELECT 1');
        break;
    } catch (PDOException $error) {
        $lastError = $error;
        fwrite(STDERR, "Waiting for MySQL ($attempt/30)...\n");
        sleep(2);
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException(
        'Could not connect to MySQL after 60 seconds: ' . ($lastError?->getMessage() ?? 'unknown error')
    );
}

$lockName = 'pppm_schema_bootstrap_' . substr(hash('sha256', DB_NAME), 0, 40);
$lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
$lock->execute([$lockName]);
if ((int) $lock->fetchColumn() !== 1) {
    throw new RuntimeException('Could not acquire the database bootstrap lock.');
}

try {
    $tables = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_type = ?'
    );
    $tables->execute([DB_NAME, 'BASE TABLE']);
    $tableCount = (int) $tables->fetchColumn();

    $marker = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
    );
    $marker->execute([DB_NAME, 'demo_records']);
    $schemaReady = (int) $marker->fetchColumn() === 1;

    if (!$schemaReady && $tableCount > 0) {
        throw new RuntimeException(
            'The selected database is not empty and does not contain a complete PPPM schema. ' .
            'Use a new Railway MySQL database or migrate the existing database first.'
        );
    }

    if (!$schemaReady) {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Could not read schema.sql.');
        }

        // Railway creates and selects MYSQLDATABASE for us. Never create or
        // switch to the local XAMPP database name embedded in schema.sql.
        $schema = preg_replace('/^CREATE DATABASE IF NOT EXISTS .*?;\s*$/mi', '', $schema);
        $schema = preg_replace('/^USE\s+`?perf_tracker`?\s*;\s*$/mi', '', (string) $schema);

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->exec((string) $schema);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        fwrite(STDOUT, "Created the PPPM database schema in " . DB_NAME . ".\n");
    } else {
        fwrite(STDOUT, "PPPM database schema is already present.\n");
    }
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
