<?php
// Expense tracking (logged in)
//   GET  /api/expenses.php?month=2026-10  →  that month's expenses, totals by type and category, and the budget plan
//   POST /api/expenses.php  { action: "add", amount, category, type, note, spent_on }
//                           { action: "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
$user = require_user();
$pdo = db();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $action = str_field($in, 'action', 10);

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM expenses WHERE id = ? AND user_id = ?')->execute([(int) ($in['id'] ?? 0), $uid]);
        json_out(['success' => true, 'message' => 'Expense removed.']);
    }
    if ($action !== 'add') {
        fail('Unknown action.');
    }

    $amount = $in['amount'] ?? null;
    $category = str_field($in, 'category', 80);
    $type = str_field($in, 'type', 10) ?: 'need';
    $note = str_field($in, 'note', 200);
    $date = str_field($in, 'spent_on', 10) ?: gmdate('Y-m-d');

    if (!is_numeric($amount) || $amount <= 0 || $amount > 1e12) {
        fail('Enter an amount greater than zero.');
    }
    if ($category === '') {
        fail('Choose or type a category.');
    }
    if (!in_array($type, ['need', 'want', 'saving'], true)) {
        fail('Choose need, want or saving.');
    }
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $d > new DateTime('tomorrow')) {
        fail('Enter a valid date that isn\'t in the future.');
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE user_id = ?');
    $count->execute([$uid]);
    if ((int) $count->fetchColumn() >= 5000) {
        fail('You\'ve reached the limit of 5,000 expenses. Delete some old ones first.');
    }
    $pdo->prepare('INSERT INTO expenses (user_id, amount, category, type, note, spent_on) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$uid, round((float) $amount, 2), $category, $type, $note, $date]);
    json_out(['success' => true, 'message' => 'Expense added.', 'id' => (int) $pdo->lastInsertId()], 201);
}

$month = str_field($_GET, 'month', 7);
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = gmdate('Y-m');
}
$start = "$month-01";
$end = (new DateTime($start))->modify('first day of next month')->format('Y-m-d');

$stmt = $pdo->prepare('SELECT id, amount, category, type, note, spent_on FROM expenses
                       WHERE user_id = ? AND spent_on >= ? AND spent_on < ? ORDER BY spent_on DESC, id DESC');
$stmt->execute([$uid, $start, $end]);
$rows = array_map(fn ($r) => ['id' => (int) $r['id'], 'amount' => (float) $r['amount']] + $r, $stmt->fetchAll());

$byType = ['need' => 0.0, 'want' => 0.0, 'saving' => 0.0];
$byCategory = [];
foreach ($rows as $r) {
    $byType[$r['type']] += $r['amount'];
    $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + $r['amount'];
}
arsort($byCategory);

// Last six months' totals, for the trend
$trend = $pdo->prepare("SELECT DATE_FORMAT(spent_on, '%Y-%m') AS m, SUM(amount) AS total FROM expenses
                        WHERE user_id = ? AND spent_on >= ? AND spent_on < ? GROUP BY m ORDER BY m");
$trend->execute([$uid, (new DateTime($start))->modify('-5 months')->format('Y-m-d'), $end]);

$budget = $pdo->prepare('SELECT data FROM budgets WHERE user_id = ?');
$budget->execute([$uid]);
$plan = $budget->fetchColumn();

json_out([
    'success' => true,
    'month' => $month,
    'data' => $rows,
    'total' => array_sum($byType),
    'by_type' => $byType,
    'by_category' => array_map(fn ($c, $t) => ['category' => $c, 'total' => $t], array_keys($byCategory), array_values($byCategory)),
    'trend' => array_map(fn ($t) => ['month' => $t['m'], 'total' => (float) $t['total']], $trend->fetchAll()),
    'budget' => $plan ? json_decode($plan, true) : null,
]);
