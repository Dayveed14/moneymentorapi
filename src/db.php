<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'moneymentor')
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    // TiDB Cloud (and most hosted MySQL) requires TLS
    if (env_bool('DB_SSL')) {
        $ca = ca_bundle();
        if ($ca === null) {
            error_log('[moneymentor] DB_SSL is on but no CA bundle was found — set CA_BUNDLE in .env');
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca ?? '';
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = env_bool('DB_SSL_VERIFY', true);
    }

    try {
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), $options);
        $pdo->exec("SET time_zone = '+00:00'"); // store and compare all times in UTC
    } catch (PDOException $e) {
        error_log('[moneymentor] DB connection failed: ' . $e->getMessage());
        fail('The service is temporarily unavailable. Please try again shortly.', 503);
    }
    return $pdo;
}
