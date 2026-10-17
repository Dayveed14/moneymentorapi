<?php
/**
 * Shared setup for every endpoint: config, errors, CORS, JSON helpers, DB, auth.
 * Lives outside public/ so it can never be requested directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ratelimit.php';

// Never print PHP warnings into a JSON response; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('[moneymentor] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'message' => 'Something went wrong on our side. Please try again.']);
});

send_cors_headers();
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
