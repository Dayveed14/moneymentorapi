<?php
// POST /api/login.php  { email, password }  →  { success, token, user }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('POST');
$in = input_json();
$email = strtolower(str_field($in, 'email', 190));
$password = (string) ($in['password'] ?? '');

if ($email === '' || $password === '') {
    fail('Enter your email and password.');
}

// Slow down password guessing: 5 failed tries per email+IP every 15 minutes
$bucket = 'login:' . client_ip() . ':' . $email;
enforce_rate_limit($bucket, 5, 900, 'Too many login attempts. Wait 15 minutes and try again.');

$stmt = db()->prepare('SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

// Same work whether or not the email exists, so response time doesn't reveal accounts
$hash = $user['password'] ?? '$2y$10$9fdrJKPYn7cuXAcR.pFunOjYuNqPsQw2R2YGwKHNAEsFY0bxZDSJW'; // dummy hash
if (!password_verify($password, $hash) || !$user) {
    fail('That email and password don\'t match. Check them and try again.', 401);
}

rate_limit_clear($bucket);

$updates = ['last_login = NOW()'];
$params = [];
if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
    $updates[] = 'password = ?';
    $params[] = password_hash($password, PASSWORD_DEFAULT);
}
$params[] = $user['id'];
db()->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

json_out([
    'success' => true,
    'message' => 'Logged in.',
    'token' => issue_token($user),
    'user' => public_user($user),
]);
