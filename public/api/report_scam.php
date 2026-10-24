<?php
// POST /api/report_scam.php  { channel, scammer_contact, description, lost_money, amount_lost, name, email }
// Anyone can report a scam. Admins review reports and can turn them into public alerts.
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

const REPORT_CHANNELS = ['call', 'sms', 'whatsapp', 'email', 'social', 'website', 'in_person', 'other'];

require_method('POST');
$in = input_json();
$user = current_user();

$channel = str_field($in, 'channel', 20);
$contact = str_field($in, 'scammer_contact', 190);
$description = str_field($in, 'description', 5000);
$lost = !empty($in['lost_money']);
$amount = $lost ? filter_var($in['amount_lost'] ?? null, FILTER_VALIDATE_FLOAT) : null;
$name = $user['name'] ?? str_field($in, 'name', 120);
$email = strtolower($user['email'] ?? str_field($in, 'email', 190));

if (!in_array($channel, REPORT_CHANNELS, true)) {
    fail('Choose how the scammer contacted you.');
}
if (mb_strlen($description) < 20) {
    fail('Tell us a little more about what happened (at least a sentence or two).');
}
if ($email !== '' && !valid_email($email)) {
    fail('Enter a valid email address, or leave it empty.');
}
if ($amount !== null && ($amount === false || $amount < 0 || $amount > 1e12)) {
    $amount = null;
}

enforce_rate_limit('report:' . client_ip(), 5, 3600, 'You\'ve sent several reports already. Try again in an hour.');

db()->prepare('INSERT INTO scam_reports (user_id, name, email, channel, scammer_contact, description, lost_money, amount_lost, ip_address)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([$user['id'] ?? null, $name, $email, $channel, $contact, $description, $lost ? 1 : 0, $amount, client_ip()]);

json_out(['success' => true, 'message' => 'Thank you. Your report helps protect others.'], 201);
