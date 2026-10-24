<?php
// POST /api/subscribe.php  { email }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('POST');
$email = strtolower(str_field(input_json(), 'email', 190));
if (!valid_email($email)) {
    fail('Enter a valid email address.');
}

enforce_rate_limit('subscribe:' . client_ip(), 10, 3600, 'Too many requests. Try again later.');

// Already subscribed counts as success — no need to reveal who is on the list
$stmt = db()->prepare('INSERT IGNORE INTO subscribers (email) VALUES (?)');
$stmt->execute([$email]);

if ($stmt->rowCount() === 1) {
    $unsub = unsubscribe_url($email);
    $alerts = frontend_url('alerts');
    send_mail(
        $email,
        'You\'re subscribed to MoneyMentor',
        mail_layout(
            'Welcome to MoneyMentor',
            '<p>Thanks for subscribing. We\'ll email you new scam alerts and money tips, never more than a few times a month.</p>'
            . '<p>One rule to remember today: <strong>never share your OTP, PIN or password with anyone, even someone who says they\'re from your bank.</strong></p>'
            . mail_button($alerts, 'See the latest scam alerts'),
            '<br><a href="' . htmlspecialchars($unsub, ENT_QUOTES) . '" style="color:#3d5c53">Unsubscribe</a>'
        ),
        "Thanks for subscribing to MoneyMentor.\n\nNever share your OTP, PIN or password with anyone.\n\nLatest scam alerts: $alerts\n\nUnsubscribe: $unsub"
    );
}
json_out(['success' => true, 'message' => 'Subscribed.']);
