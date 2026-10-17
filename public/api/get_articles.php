<?php
// GET /api/get_articles.php?page=1&limit=6&category=Savings&search=budget
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/articles.php';

require_method('GET');
$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 6, 1, 24);
$category = str_field($_GET, 'category', 50);
$search = str_field($_GET, 'search', 100);

$where = ["a.status = 'published'"];
$params = [];
if ($category !== '') {
    if (!in_array($category, ARTICLE_CATEGORIES, true)) {
        fail('Unknown category.');
    }
    $where[] = 'a.category = ?';
    $params[] = $category;
}
if ($search !== '') {
    // LOWER() on both sides so search is case-insensitive even on TiDB's default utf8mb4_bin collation
    $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
    $where[] = '(LOWER(a.title) LIKE ? OR LOWER(a.summary) LIKE ?)';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);

$count = db()->prepare("SELECT COUNT(*) FROM articles a WHERE $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$totalPages = max(1, (int) ceil($total / $limit));

$stmt = db()->prepare("
    SELECT a.id, a.title, a.summary, a.image_path, a.category, a.published_date,
           a.view_count, u.name AS author_name, " . READING_MINUTES_SQL . " AS reading_minutes
    FROM articles a
    LEFT JOIN users u ON u.id = a.author_id
    WHERE $whereSql
    ORDER BY a.published_date DESC, a.id DESC
    LIMIT $limit OFFSET " . (($page - 1) * $limit)
);
$stmt->execute($params);

json_out([
    'success' => true,
    'data' => array_map('format_article', $stmt->fetchAll()),
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $totalPages,
        'total_articles' => $total,
        'per_page' => $limit,
    ],
]);
