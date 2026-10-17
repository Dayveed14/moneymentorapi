<?php
declare(strict_types=1);

/*
 * Stateless signed tokens (same idea as a JWT, HS256), using APP_SECRET.
 * The old code returned a random string that was never stored or checked,
 * so it proved nothing. These tokens can be verified on every request.
 */

const TOKEN_TTL_SECONDS = 60 * 60 * 24 * 7; // 7 days

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string|false
{
    return base64_decode(strtr($data, '-_', '+/'), true);
}

function app_secret(): string
{
    $secret = env('APP_SECRET');
    if ($secret === null || strlen($secret) < 32) {
        error_log('[moneymentor] APP_SECRET is missing or shorter than 32 characters');
        fail('Server is not configured correctly.', 500);
    }
    return $secret;
}

function issue_token(array $user): string
{
    $header = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $now = time();
    $payload = b64url_encode(json_encode([
        'sub' => (int) $user['id'],
        'role' => $user['role'],
        'iat' => $now,
        'exp' => $now + TOKEN_TTL_SECONDS,
    ]));
    $sig = b64url_encode(hash_hmac('sha256', "$header.$payload", app_secret(), true));
    return "$header.$payload.$sig";
}

/** Returns the token payload, or null if the token is invalid or expired. */
function verify_token(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $payload, $sig] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$header.$payload", app_secret(), true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $data = json_decode((string) b64url_decode($payload), true);
    if (!is_array($data) || ($data['exp'] ?? 0) < time() || empty($data['sub'])) {
        return null;
    }
    return $data;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
}

/** The logged-in user (fresh from the database), or null. */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $token = bearer_token();
    $payload = $token ? verify_token($token) : null;
    if ($payload) {
        $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

/** Stop with 401/403 unless a user (optionally with a given role) is logged in. */
function require_user(?string $role = null): array
{
    $user = current_user();
    if (!$user) {
        fail('Please log in to continue.', 401);
    }
    if ($role !== null && $user['role'] !== $role) {
        fail("You don't have permission to do that.", 403);
    }
    return $user;
}

function public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'] ?? 'user',
    ];
}
