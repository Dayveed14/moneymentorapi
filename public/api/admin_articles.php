<?php
// Admin article management
//   GET  /api/admin_articles.php?page=1&status=draft|published
//   POST /api/admin_articles.php  { action: "publish" | "unpublish" | "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/articles.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $id = (int) ($in['id'] ?? 0);
    $action = str_field($in, 'action', 20);

    $stmt = $pdo->prepare('SELECT id, status, image_path, image_public_id, published_date FROM articles WHERE id = ?');
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article) {
        fail('Article not found.', 404);
    }

    switch ($action) {
        case 'publish':
            // Keep the original publish date if it was published before
            $pdo->prepare("UPDATE articles SET status = 'published', published_date = COALESCE(published_date, NOW()) WHERE id = ?")
                ->execute([$id]);
            json_out(['success' => true, 'message' => 'Article published.']);
        case 'unpublish':
            $pdo->prepare("UPDATE articles SET status = 'draft' WHERE id = ?")->execute([$id]);
            json_out(['success' => true, 'message' => 'Article moved to drafts.']);
        case 'delete':
            $pdo->prepare('DELETE FROM articles WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM saved_articles WHERE article_id = ?')->execute([$id]);
            delete_stored_image($article['image_path'], $article['image_public_id']);
            json_out(['success' => true, 'message' => 'Article deleted.']);
        default:
            fail('Unknown action.');
    }
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$status = str_field($_GET, 'status', 20);
$where = in_array($status, ['draft', 'published'], true) ? 'WHERE a.status = ?' : '';
$params = $where ? [$status] : [];

$count = $pdo->prepare("SELECT COUNT(*) FROM articles a $where");
$count->execute($params);
$total = (int) $count->fetchColumn();

$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.category, a.status, a.published_date, a.created_at, a.view_count,
           u.name AS author_name
    FROM articles a LEFT JOIN users u ON u.id = a.author_id
    $where
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT $limit OFFSET " . (($page - 1) * $limit)
);
$stmt->execute($params);

json_out([
    'success' => true,
    'data' => array_map('format_article', $stmt->fetchAll()),
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
