<?php
// POST /api/signup.php  { firstName, lastName, email, password, confirmPassword }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('POST');
$in = input_json();
$first = str_field($in, 'firstName', 60);
$last = str_field($in, 'lastName', 60);
$email = strtolower(str_field($in, 'email', 190));
$password = (string) ($in['password'] ?? '');
$confirm = (string) ($in['confirmPassword'] ?? '');

if ($first === '' || $last === '' || $email === '' || $password === '') {
    fail('Fill in every field to create your account.');
}
if (!valid_email($email)) {
    fail('Enter a valid email address.');
}
if (strlen($password) < 8) {
    fail('Your password needs at least 8 characters.');
}
if (strlen($password) > 72) {
    fail('Your password can be at most 72 characters.');
}
if ($password !== $confirm) {
    fail('The passwords don\'t match.');
}

enforce_rate_limit('signup:' . client_ip(), 10, 3600, 'Too many sign-ups from this network. Try again in an hour.');

$stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    fail('An account with that email already exists. Log in instead.', 409);
}

$name = "$first $last";
db()->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')")
    ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

$user = ['id' => (int) db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'user'];

json_out([
    'success' => true,
    'message' => 'Account created.',
    'token' => issue_token($user),
    'user' => public_user($user),
], 201);
