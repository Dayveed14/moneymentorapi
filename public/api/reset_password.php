<?php
// POST /api/reset_password.php  { token, password }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('POST');
$in = input_json();
$token = str_field($in, 'token', 100);
$password = (string) ($in['password'] ?? '');

enforce_rate_limit('reset:' . client_ip(), 20, 3600, 'Too many attempts. Try again later.');

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail('This reset link is invalid. Ask for a new one.');
}
if (strlen($password) < 8 || strlen($password) > 72) {
    fail('Your new password needs 8 to 72 characters.');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id FROM password_resets
                       WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
$stmt->execute([hash('sha256', $token)]);
$reset = $stmt->fetch();
if (!$reset) {
    fail('This reset link has expired or was already used. Ask for a new one.');
}

// Changing password_changed_at signs out every device that had the old password
$pdo->prepare('UPDATE users SET password = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?')
    ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
$pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$reset['user_id']]);

json_out(['success' => true, 'message' => 'Password changed. You can log in now.']);
