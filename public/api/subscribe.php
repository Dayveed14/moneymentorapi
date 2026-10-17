<?php
// POST /api/subscribe.php  { email }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('POST');
$email = strtolower(str_field(input_json(), 'email', 190));
if (!valid_email($email)) {
    fail('Enter a valid email address.');
}

enforce_rate_limit('subscribe:' . client_ip(), 10, 3600, 'Too many requests. Try again later.');

// Already subscribed counts as success — no need to reveal who is on the list
db()->prepare('INSERT IGNORE INTO subscribers (email) VALUES (?)')->execute([$email]);
json_out(['success' => true, 'message' => 'Subscribed.']);
