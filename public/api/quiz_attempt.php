<?php
// POST /api/quiz_attempt.php  { module, score, total }  →  saves a module quiz result
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/modules.php';

require_method('POST');
$user = require_user();
$in = input_json();
$module = str_field($in, 'module', 40);
$score = filter_var($in['score'] ?? null, FILTER_VALIDATE_INT);
$total = filter_var($in['total'] ?? null, FILTER_VALIDATE_INT);

if (!valid_module($module)) {
    fail('Unknown module.');
}
if ($total === false || $score === false || $total < 1 || $total > 50 || $score < 0 || $score > $total) {
    fail('Invalid score.');
}

db()->prepare('INSERT INTO quiz_attempts (user_id, module_id, score, total) VALUES (?, ?, ?, ?)')
    ->execute([$user['id'], $module, $score, $total]);

$pct = intdiv($score * 100, $total);
json_out(['success' => true, 'percent' => $pct, 'passed' => $pct >= 80], 201);
