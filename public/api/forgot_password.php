<?php
// POST /api/forgot_password.php  { email }
// Always answers the same way, so nobody can use it to find out who has an account.
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('POST');
$email = strtolower(str_field(input_json(), 'email', 190));
if (!valid_email($email)) {
    fail('Enter a valid email address.');
}

enforce_rate_limit('forgot:' . client_ip(), 10, 3600, 'Too many requests. Try again in an hour.');
$done = ['success' => true, 'message' => 'If that email has an account, we\'ve sent a link to reset the password. Check your inbox and spam folder.'];

// Only one email per address every 2 minutes
if (!rate_limit_hit('forgot-email:' . $email, 1, 120)) {
    json_out($done);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user) {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)')
        ->execute([$user['id'], hash('sha256', $token)]);

    $link = frontend_url('reset-password?token=' . $token);
    $name = htmlspecialchars(explode(' ', $user['name'])[0], ENT_QUOTES);
    $html = mail_layout(
        'Reset your password',
        "<p>Hi $name,</p><p>Someone (hopefully you) asked to reset your MoneyMentor password. The link works for one hour.</p>"
        . mail_button($link, 'Choose a new password')
        . '<p style="color:#5b6f69;font-size:13px">If you didn\'t ask for this, ignore this email. Your password won\'t change. We will never ask you for your password, OTP or PIN.</p>'
    );
    $text = "Hi {$user['name']},\n\nReset your MoneyMentor password (link valid for one hour):\n$link\n\nIf you didn't ask for this, ignore this email.";
    send_mail($user['email'], 'Reset your MoneyMentor password', $html, $text);
}

json_out($done);
