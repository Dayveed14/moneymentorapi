<?php
// The logged-in user's budget plan
//   GET  /api/budget.php  →  { budget: {income, items: [{name, amount, type}]} | null, updated_at }
//   POST /api/budget.php  { income, items }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $income = $in['income'] ?? 0;
    $items = $in['items'] ?? [];

    if (!is_numeric($income) || $income < 0 || $income > 1e12) {
        fail('Enter your monthly income as a positive number.');
    }
    if (!is_array($items) || count($items) > 60) {
        fail('A budget can have up to 60 lines.');
    }

    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = str_field($item, 'name', 60);
        $amount = $item['amount'] ?? 0;
        $type = $item['type'] ?? 'need';
        if ($name === '' && (float) $amount == 0.0) {
            continue; // skip empty rows
        }
        if (!is_numeric($amount) || $amount < 0 || $amount > 1e12) {
            fail("Check the amount for \"$name\".");
        }
        if (!in_array($type, ['need', 'want', 'saving'], true)) {
            fail('Each line must be a need, a want or a saving.');
        }
        $clean[] = ['name' => $name === '' ? 'Untitled' : $name, 'amount' => round((float) $amount, 2), 'type' => $type];
    }

    $data = json_encode(['income' => round((float) $income, 2), 'items' => $clean]);
    $pdo->prepare('REPLACE INTO budgets (user_id, data) VALUES (?, ?)')->execute([$user['id'], $data]);
}

$stmt = $pdo->prepare('SELECT data, updated_at FROM budgets WHERE user_id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();

json_out([
    'success' => true,
    'budget' => $row ? json_decode($row['data'], true) : null,
    'updated_at' => $row['updated_at'] ?? null,
]);
