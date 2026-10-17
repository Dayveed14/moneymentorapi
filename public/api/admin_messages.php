<?php
// Contact messages (admin)
//   GET  /api/admin_messages.php?page=1&filter=unread
//   POST /api/admin_messages.php  { action: "mark_read" | "mark_unread" | "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $id = (int) ($in['id'] ?? 0);
    $action = str_field($in, 'action', 20);
    $sql = [
        'mark_read' => 'UPDATE contact_messages SET is_read = 1 WHERE id = ?',
        'mark_unread' => 'UPDATE contact_messages SET is_read = 0 WHERE id = ?',
        'delete' => 'DELETE FROM contact_messages WHERE id = ?',
    ][$action] ?? fail('Unknown action.');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    json_out(['success' => true, 'message' => $action === 'delete' ? 'Message deleted.' : 'Message updated.']);
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$where = str_field($_GET, 'filter', 10) === 'unread' ? 'WHERE is_read = 0' : '';
$total = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages $where")->fetchColumn();
$rows = $pdo->query("SELECT id, name, email, subject, message, is_read, created_at FROM contact_messages $where
                     ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit))->fetchAll();
$rows = array_map(fn ($r) => ['id' => (int) $r['id'], 'is_read' => (bool) $r['is_read']] + $r, $rows);

json_out([
    'success' => true,
    'data' => $rows,
    'unread' => (int) $pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn(),
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
