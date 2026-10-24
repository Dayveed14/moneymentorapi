<?php
/**
 * Creates/updates the database schema and (optionally) the first admin user.
 * Runs automatically when the container starts — Render's free plan has no shell.
 *
 *   php bin/migrate.php
 *
 * Works on a fresh database and on the existing InfinityFree tables
 * (adds any columns the old schema is missing).
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/env.php';

function out(string $msg): void { fwrite(STDOUT, "[migrate] $msg\n"); }

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'moneymentor'));
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
if (env_bool('DB_SSL')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = ca_bundle() ?? '';
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = env_bool('DB_SSL_VERIFY', true);
}

// The database may still be starting (docker compose) — retry for ~60s
$pdo = null;
for ($i = 1; $i <= 20; $i++) {
    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), $options);
        $pdo->exec("SET time_zone = '+00:00'");
        break;
    } catch (PDOException $e) {
        out("database not ready ($i/20): " . $e->getMessage());
        sleep(3);
    }
}
if (!$pdo) {
    out('giving up — could not connect to the database');
    exit(1);
}

require_once __DIR__ . '/../src/migrations.php';
run_migrations($pdo, 'out');
@file_put_contents(schema_flag_file(), SCHEMA_VERSION);

out('done');
