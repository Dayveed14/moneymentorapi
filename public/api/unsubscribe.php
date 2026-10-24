<?php
// POST /api/unsubscribe.php  { email, token }  — one-click link from newsletter emails
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('POST');
$in = input_json();
$email = strtolower(str_field($in, 'email', 190));
$token = str_field($in, 'token', 64);

if (!valid_email($email) || !hash_equals(unsubscribe_token($email), $token)) {
    fail('This unsubscribe link is invalid.');
}
db()->prepare('DELETE FROM subscribers WHERE email = ?')->execute([$email]);
json_out(['success' => true, 'message' => 'You\'ve been unsubscribed.']);
