<?php
declare(strict_types=1);

function send_cors_headers(): void
{
    // Comma-separated list, e.g. "https://moneymentor.vercel.app,http://localhost:5173"
    $allowed = array_filter(array_map(
        fn ($o) => rtrim(trim($o), '/'),
        explode(',', env('ALLOWED_ORIGINS', 'http://localhost:5173'))
    ));
    $origin = rtrim($_SERVER['HTTP_ORIGIN'] ?? '', '/');

    header('Vary: Origin');
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }
}

/** Send a JSON response and stop. */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    json_out(['success' => false, 'message' => $message] + $extra, $status);
}

function require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        fail('Method not allowed.', 405);
    }
}

/** Decoded JSON request body (empty array if missing or invalid). */
function input_json(): array
{
    static $data = null;
    if ($data === null) {
        $decoded = json_decode(file_get_contents('php://input') ?: '', true);
        $data = is_array($decoded) ? $decoded : [];
    }
    return $data;
}

/** Trimmed string field from an array, never null. */
function str_field(array $src, string $key, int $maxLen = 10000): string
{
    $value = $src[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    return mb_substr(trim((string) $value), 0, $maxLen);
}

function int_query(string $key, int $default, int $min, int $max): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : max($min, min($max, $value));
}

function client_ip(): string
{
    // Render (and most hosts) sit behind a proxy that sets X-Forwarded-For
    if (env_bool('TRUST_PROXY', true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function valid_email(string $email): bool
{
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
