<?php
// Account settings (logged in)
//   POST /api/profile.php  { action: "update_name", name }
//   POST /api/profile.php  { action: "change_password", current_password, new_password }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('POST');
$user = require_user();
$in = input_json();
$action = str_field($in, 'action', 30);

if ($action === 'update_name') {
    $name = str_field($in, 'name', 120);
    if (mb_strlen($name) < 2) {
        fail('Enter your full name.');
    }
    db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
    $user['name'] = $name;
    json_out(['success' => true, 'message' => 'Name updated.', 'user' => public_user($user)]);
}

if ($action === 'change_password') {
    enforce_rate_limit('pwchange:' . $user['id'], 5, 900, 'Too many attempts. Wait 15 minutes and try again.');
    $current = (string) ($in['current_password'] ?? '');
    $new = (string) ($in['new_password'] ?? '');

    $stmt = db()->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    if (!password_verify($current, (string) $stmt->fetchColumn())) {
        fail('Your current password is incorrect.', 400);
    }
    if (strlen($new) < 8 || strlen($new) > 72) {
        fail('Your new password needs 8 to 72 characters.');
    }
    if ($new === $current) {
        fail('Choose a password different from your current one.');
    }
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    rate_limit_clear('pwchange:' . $user['id']);
    json_out(['success' => true, 'message' => 'Password changed.']);
}

fail('Unknown action.');
