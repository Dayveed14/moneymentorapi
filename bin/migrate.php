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

// 4. Starter scam alerts, only when the table is empty (admins can edit or remove them)
if ((int) $pdo->query('SELECT COUNT(*) FROM scam_alerts')->fetchColumn() === 0) {
    $seed = [
        ['"Your bank" calls asking for your OTP', 'Callers pretend to be from your bank, say there is a problem with your account and ask you to read out the code just sent to your phone.',
         "Caller asks for an OTP, PIN, password or BVN\nThey say your account will be blocked or debited\nThey pressure you to act right now",
         "Hang up. Your bank will never ask for your OTP\nCall your bank using the number on your card or in its official app\nIf you already shared a code, call your bank immediately to block your account", 'high'],
        ['SMS links to "verify your BVN" or "unblock" your account', 'Text messages warn that your account or BVN will be suspended and include a link to "update" your details on a fake website.',
         "A link in an SMS from an unknown number\nSpelling mistakes or odd web addresses\nThreats that your account will be closed today",
         "Don't tap the link\nOpen your bank's official app or website yourself to check\nDelete the message and block the number", 'high'],
        ['Investments that "double your money"', 'Schemes promise guaranteed, very high returns in days or weeks. Early members are paid with new members\' money until the scheme collapses.',
         "Guaranteed returns, especially more than a few percent a month\nRewards for bringing in friends\nPressure to invest quickly before it \"closes\"",
         "Remember: real investments never guarantee big, fast returns\nCheck whether the company is registered with your country's securities regulator\nNever invest money you can't afford to lose", 'high'],
        ['Fake transfer alerts', 'A buyer shows or sends a fake credit alert and leaves with your goods before you notice no money arrived.',
         "Alert arrives by SMS but your balance hasn't changed\nBuyer is in a hurry to leave\nScreenshot of a transfer instead of real money in your account",
         "Always confirm the money in your banking app before releasing goods\nDon't rely on SMS alerts or screenshots\nTake your time; a real buyer will wait", 'medium'],
        ['Job offers that ask for a fee', 'Fake recruiters offer well-paid jobs, then ask you to pay for training, forms or a "processing fee" before you can start.',
         "You have to pay to get the job\nThe offer comes without a real interview\nThe pay sounds too good for the work",
         "Never pay to get a job\nLook up the company yourself and contact it through its official website\nReport the advert to the platform where you saw it", 'medium'],
    ];
    $ins = $pdo->prepare('INSERT INTO scam_alerts (title, summary, red_flags, what_to_do, severity) VALUES (?, ?, ?, ?, ?)');
    foreach ($seed as $row) {
        $ins->execute($row);
    }
    out('added starter scam alerts');
}

// 5. First admin, from ADMIN_EMAIL / ADMIN_PASSWORD (only if that email doesn't exist yet)
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
