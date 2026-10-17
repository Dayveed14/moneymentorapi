<?php
// GET /api/admin_users.php?page=1&search=ada  (admin)
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
require_user('admin');

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$search = str_field($_GET, 'search', 100);

$where = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
    $where = 'WHERE LOWER(name) LIKE ? OR LOWER(email) LIKE ?';
    $params = [$like, $like];
}

$count = db()->prepare("SELECT COUNT(*) FROM users $where");
$count->execute($params);
$total = (int) $count->fetchColumn();

$stmt = db()->prepare("
    SELECT id, name, email, role, created_at, last_login
    FROM users $where
    ORDER BY created_at DESC, id DESC
    LIMIT $limit OFFSET " . (($page - 1) * $limit)
);
$stmt->execute($params);
$users = array_map(fn ($u) => ['id' => (int) $u['id']] + $u, $stmt->fetchAll());

json_out([
    'success' => true,
    'data' => $users,
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
