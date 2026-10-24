<?php
declare(strict_types=1);

/*
 * Database setup and upgrades, shared by bin/migrate.php (Docker/Render start-up)
 * and by db() on the first request after an update (XAMPP and other hosts that
 * never run the start-up script). Every step is safe to run more than once.
 *
 * Bump SCHEMA_VERSION whenever schema.sql or the steps below change.
 */
const SCHEMA_VERSION = '6';

function run_migrations(PDO $pdo, callable $out): void
{
    // 1. Tables
    $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*$/m', $sql))) as $statement) {
        if (!preg_match('/^\s*(--.*\n\s*)*$/', $statement)) {
            $pdo->exec($statement);
        }
    }
    $out('tables ok');

    // 2. Columns the original (v1) tables may not have
    $needed = [
        'users' => [
            'role' => "ENUM('user','admin') NOT NULL DEFAULT 'user'",
            'last_login' => 'DATETIME NULL',
            'password_changed_at' => 'DATETIME NULL',
            'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
        'articles' => [
            'view_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'image_public_id' => 'VARCHAR(255) NULL',
            'published_date' => 'DATETIME NULL',
            'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ],
        'contact_messages' => [
            'reply' => 'TEXT NULL',
            'replied_at' => 'DATETIME NULL',
        ],
        'scam_alerts' => [
            'summary_pcm' => 'TEXT NULL',          // Pidgin version of the summary
            'emailed_at' => 'DATETIME NULL',
        ],
    ];
    $colStmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($needed as $table => $columns) {
        $colStmt->execute([$table]);
        $existing = array_column($colStmt->fetchAll(), 'COLUMN_NAME');
        foreach ($columns as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                $out("added $table.$column");
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
                $out("converted $table to utf8mb4");
                $done = true;
                break;
            } catch (PDOException $e) {
                $out("could not convert $table with \"$sql\": " . $e->getMessage());
            }
        }
        if (!$done) {
            $out("left $table as {$t['TABLE_COLLATION']} — the API still works, but emoji in names/articles won't save");
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
        $out('added starter scam alerts');
    }

    // 4b. Help contacts, only when the table is empty.
    //     Agencies are published. Bank codes start as DRAFTS: they change and different
    //     sources disagree, so an admin must confirm each one with the bank before publishing.
    if ((int) $pdo->query('SELECT COUNT(*) FROM help_contacts')->fetchColumn() === 0) {
        $agencies = [
            ['NPF National Cybercrime Centre (NPF-NCCC)', 'https://nccc.npf.gov.ng/', 'Report online fraud and cybercrime to the Nigeria Police Force.', 10],
            ['Economic and Financial Crimes Commission (EFCC)', 'https://www.efcc.gov.ng/', 'Report financial fraud, Ponzi schemes and advance-fee scams.', 20],
            ['FCCPC: approved digital lenders', 'https://fccpc.gov.ng/registration-of-digital-money-lenders/approvals-of-dmls/', 'Check a loan app before you borrow, and report harassment by lenders.', 30],
            ['Securities and Exchange Commission (SEC) Nigeria', 'https://sec.gov.ng/', 'Check whether an investment scheme is registered and read SEC warnings.', 40],
            ['Central Bank of Nigeria (CBN): consumer protection', 'https://www.cbn.gov.ng/', 'Escalate a complaint if your bank does not resolve it.', 50],
        ];
        $ins = $pdo->prepare("INSERT INTO help_contacts (name, kind, url, note, sort_order, status) VALUES (?, 'agency', ?, ?, ?, 'published')");
        foreach ($agencies as $a) {
            $ins->execute($a);
        }
        $banks = [
            ['Access Bank', '*901*911#'], ['Ecobank', '*326*911#'], ['Fidelity Bank', '*770*911#'],
            ['First Bank', '*894*911#'], ['FCMB', '*329*911#'], ['GTBank', '*737*51*74#'],
            ['Polaris Bank', '*833*911#'], ['Sterling Bank', '*822*911#'], ['UBA', '*919*9#'],
            ['Union Bank', '*826*6#'], ['Wema Bank', '*945*911#'], ['Zenith Bank', '*966*911#'],
        ];
        $ins = $pdo->prepare("INSERT INTO help_contacts (name, kind, ussd, note, sort_order, status) VALUES (?, 'bank', ?, '', ?, 'draft')");
        foreach ($banks as $i => [$bank, $code]) {
            $ins->execute([$bank, $code, ($i + 1) * 10]);
        }
        $out('added help contacts (bank codes are drafts until confirmed)');
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
                $out('ADMIN_PASSWORD must be at least 12 characters — admin not created');
            } else {
                $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')")
                    ->execute([env('ADMIN_NAME', 'Admin'), $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
                $out("created admin $adminEmail");
            }
        } elseif ($row['role'] !== 'admin') {
            $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$row['id']]);
            $out("promoted $adminEmail to admin");
        }
    }

    // Remember which version this database is on
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_meta (name VARCHAR(40) PRIMARY KEY, value VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->prepare('REPLACE INTO app_meta (name, value) VALUES (\'schema_version\', ?)')->execute([SCHEMA_VERSION]);
}

function schema_flag_file(): string
{
    return dirname(__DIR__) . '/storage/schema-version';
}

/**
 * Runs the migrations if this database hasn't been upgraded to SCHEMA_VERSION yet.
 * Fast path: a small file in storage/ remembers the version, so normal requests
 * don't touch the database for this.
 */
function ensure_schema(PDO $pdo): void
{
    $flag = schema_flag_file();
    if (is_file($flag) && trim((string) @file_get_contents($flag)) === SCHEMA_VERSION) {
        return;
    }

    $current = null;
    try {
        $current = $pdo->query("SELECT value FROM app_meta WHERE name = 'schema_version'")->fetchColumn() ?: null;
    } catch (PDOException) {
        // app_meta doesn't exist yet: an older database
    }

    if ($current !== SCHEMA_VERSION) {
        $dir = dirname($flag);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Only one request upgrades at a time; others wait for it
        $lock = @fopen("$dir/migrate.lock", 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $again = null;
            try {
                $again = $pdo->query("SELECT value FROM app_meta WHERE name = 'schema_version'")->fetchColumn() ?: null;
            } catch (PDOException) {
            }
            if ($again !== SCHEMA_VERSION) {
                error_log('[moneymentor] upgrading database to schema version ' . SCHEMA_VERSION);
                run_migrations($pdo, fn (string $m) => error_log("[moneymentor] migrate: $m"));
            }
        } catch (Throwable $e) {
            error_log('[moneymentor] automatic migration failed: ' . $e->getMessage() . ' (run php bin/migrate.php to see details)');
            return;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
    @file_put_contents($flag, SCHEMA_VERSION);
}
