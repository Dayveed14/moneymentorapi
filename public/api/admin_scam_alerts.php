<?php
// Scam alerts (admin)
//   GET  /api/admin_scam_alerts.php                       →  all alerts incl. drafts
//   POST /api/admin_scam_alerts.php  { action: "create", title, summary, red_flags, what_to_do, severity, status }
//                                    { action: "update", id, ...same fields }
//                                    { action: "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/alerts.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $action = str_field($in, 'action', 20);

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM scam_alerts WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        json_out(['success' => true, 'message' => 'Alert deleted.']);
    }
    if (!in_array($action, ['create', 'update'], true)) {
        fail('Unknown action.');
    }

    $title = str_field($in, 'title', 160);
    $summary = str_field($in, 'summary', 400);
    $flags = alert_list_text($in['red_flags'] ?? '');
    $steps = alert_list_text($in['what_to_do'] ?? '');
    $severity = str_field($in, 'severity', 10) ?: 'medium';
    $status = str_field($in, 'status', 10) ?: 'published';

    if ($title === '' || $summary === '') {
        fail('Add a title and a short summary.');
    }
    if ($flags === '' || $steps === '') {
        fail('Add at least one red flag and one thing to do.');
    }
    if (!in_array($severity, ['high', 'medium', 'low'], true) || !in_array($status, ['draft', 'published'], true)) {
        fail('Invalid severity or status.');
    }

    if ($action === 'create') {
        $pdo->prepare('INSERT INTO scam_alerts (title, summary, red_flags, what_to_do, severity, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$title, $summary, $flags, $steps, $severity, $status]);
        json_out(['success' => true, 'message' => $status === 'published' ? 'Alert published.' : 'Alert saved as draft.'], 201);
    }

    $stmt = $pdo->prepare('UPDATE scam_alerts SET title = ?, summary = ?, red_flags = ?, what_to_do = ?, severity = ?, status = ? WHERE id = ?');
    $stmt->execute([$title, $summary, $flags, $steps, $severity, $status, (int) ($in['id'] ?? 0)]);
    json_out(['success' => true, 'message' => 'Alert updated.']);
}

$rows = $pdo->query('SELECT id, title, summary, red_flags, what_to_do, severity, status, created_at, updated_at
                     FROM scam_alerts ORDER BY created_at DESC, id DESC')->fetchAll();
json_out(['success' => true, 'data' => array_map('format_alert', $rows)]);
