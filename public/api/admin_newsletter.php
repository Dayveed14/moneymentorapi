<?php
// Newsletter (admin)
//   POST /api/admin_newsletter.php  { action: "test" | "send", subject, body }
//   "test" sends only to the logged-in admin; "send" goes to every subscriber.
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('POST');
$admin = require_user('admin');
$in = input_json();
$action = str_field($in, 'action', 10);
$subject = str_field($in, 'subject', 150);
$body = str_field($in, 'body', 20000);

if ($subject === '' || mb_strlen($body) < 10) {
    fail('Add a subject and a message.');
}
$html = text_to_html($body) . mail_button(frontend_url(), 'Visit MoneyMentor');
$text = $body . "\n\n" . frontend_url();

if ($action === 'test') {
    $ok = send_mail($admin['email'], "[Test] $subject", mail_layout($subject, $html), $text);
    $ok ? json_out(['success' => true, 'message' => "Test sent to {$admin['email']}" . (mail_enabled() ? '.' : ' (test mode: written to the mail log).')])
        : fail('The test email could not be sent. Check the mail settings.', 502);
}
if ($action !== 'send') {
    fail('Unknown action.');
}

enforce_rate_limit('newsletter:' . $admin['id'], 5, 3600, 'You\'ve sent several newsletters in the last hour. Wait a little.');
[$sent, $failed] = mail_subscribers($subject, $subject, $html, $text);
json_out([
    'success' => true,
    'message' => "Sent to $sent subscriber" . ($sent === 1 ? '' : 's') . ($failed ? ", $failed failed" : '') . (mail_enabled() ? '.' : ' (test mode: written to the mail log, not sent).'),
    'sent' => $sent,
    'failed' => $failed,
]);
