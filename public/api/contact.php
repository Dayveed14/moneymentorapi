<?php
// POST /api/contact.php  { name, email, subject, message }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('POST');
$in = input_json();
$name = str_field($in, 'name', 120);
$email = strtolower(str_field($in, 'email', 190));
$subject = str_field($in, 'subject', 200);
$message = str_field($in, 'message', 5000);

if ($name === '' || $email === '' || $message === '') {
    fail('Add your name, email and a message.');
}
if (!valid_email($email)) {
    fail('Enter a valid email address so we can reply.');
}

enforce_rate_limit('contact:' . client_ip(), 5, 3600, 'You\'ve sent several messages already. Try again in an hour.');

db()->prepare('INSERT INTO contact_messages (name, email, subject, message, ip_address) VALUES (?, ?, ?, ?, ?)')
    ->execute([$name, $email, $subject, $message, client_ip()]);

json_out(['success' => true, 'message' => 'Message sent.'], 201);
