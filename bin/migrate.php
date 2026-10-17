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

// 1. Tables
$sql = file_get_contents(__DIR__ . '/../database/schema.sql');
foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $statement) {
    if (!preg_match('/^\s*(--.*\n\s*)*$/', $statement)) {
        $pdo->exec($statement);
    }
}
out('tables ok');

// 2. Columns the original (v1) tables may not have
$needed = [
    'users' => [
        'role' => "ENUM('user','admin') NOT NULL DEFAULT 'user'",
        'last_login' => 'DATETIME NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],
    'articles' => [
        'view_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'image_public_id' => 'VARCHAR(255) NULL',
        'published_date' => 'DATETIME NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
];
$colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($needed as $table => $columns) {
    $colStmt->execute([$table]);
    $existing = array_column($colStmt->fetchAll(), 'COLUMN_NAME');
    foreach ($columns as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            out("added $table.$column");
        }
    }
}

// 3. Old tables were created with the 3-byte "utf8" charset; convert them so names and
//    articles can hold any character (including emoji). Tables that are already utf8mb4
//    (any collation) are left alone — TiDB can't change collation on existing tables.
$tblStmt = $pdo->query("SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('users', 'articles', 'contact_messages', 'subscribers')");
foreach ($tblStmt->fetchAll() as $t) {
    if (str_starts_with((string) $t['TABLE_COLLATION'], 'utf8mb4_')) {
        continue;
    }
    $table = $t['TABLE_NAME'];
    $attempts = [
        "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4", // fallback for TiDB
    ];
    $done = false;
    foreach ($attempts as $sql) {
        try {
            $pdo->exec($sql);
            out("converted $table to utf8mb4");
            $done = true;
            break;
        } catch (PDOException $e) {
            out("could not convert $table with \"$sql\": " . $e->getMessage());
        }
    }
    if (!$done) {
        out("left $table as {$t['TABLE_COLLATION']} — the API still works, but emoji in names/articles won't save");
    }
}

// 4. First admin, from ADMIN_EMAIL / ADMIN_PASSWORD (only if that email doesn't exist yet)
$adminEmail = strtolower((string) env('ADMIN_EMAIL', ''));
$adminPass = (string) env('ADMIN_PASSWORD', '');
if ($adminEmail !== '' && $adminPass !== '') {
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = ?');
    $stmt->execute([$adminEmail]);
    $row = $stmt->fetch();
    if (!$row) {
        if (strlen($adminPass) < 12) {
            out('ADMIN_PASSWORD must be at least 12 characters — admin not created');
        } else {
            $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')")
                ->execute([env('ADMIN_NAME', 'Admin'), $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
            out("created admin $adminEmail");
        }
    } elseif ($row['role'] !== 'admin') {
        $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$row['id']]);
        out("promoted $adminEmail to admin");
    }
}

out('done');
