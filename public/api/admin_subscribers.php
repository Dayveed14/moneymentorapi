<?php
// Newsletter subscribers (admin)
//   GET  /api/admin_subscribers.php?page=1
//   POST /api/admin_subscribers.php  { action: "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    if (str_field($in, 'action', 20) !== 'delete') {
        fail('Unknown action.');
    }
    $pdo->prepare('DELETE FROM subscribers WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
    json_out(['success' => true, 'message' => 'Subscriber removed.']);
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 50, 1, 100);
$total = (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
$rows = $pdo->query('SELECT id, email, created_at FROM subscribers ORDER BY created_at DESC, id DESC LIMIT '
    . $limit . ' OFFSET ' . (($page - 1) * $limit))->fetchAll();

json_out([
    'success' => true,
    'data' => array_map(fn ($r) => ['id' => (int) $r['id']] + $r, $rows),
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
