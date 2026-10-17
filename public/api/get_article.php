<?php
// GET /api/get_article.php?id=12  →  one published article with its body
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/articles.php';

require_method('GET');
$id = int_query('id', 0, 0, PHP_INT_MAX);
if ($id < 1) {
    fail('Article not found.', 404);
}

$stmt = db()->prepare("
    SELECT a.id, a.title, a.summary, a.body, a.image_path, a.category, a.status,
           a.published_date, a.view_count, u.name AS author_name, " . READING_MINUTES_SQL . " AS reading_minutes
    FROM articles a
    LEFT JOIN users u ON u.id = a.author_id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$article = $stmt->fetch();

// Drafts are visible to admins only (so they can preview)
$isAdmin = (current_user()['role'] ?? null) === 'admin';
if (!$article || ($article['status'] !== 'published' && !$isAdmin)) {
    fail('Article not found.', 404);
}

if ($article['status'] === 'published') {
    db()->prepare('UPDATE articles SET view_count = view_count + 1 WHERE id = ?')->execute([$id]);
    $article['view_count']++;
}

json_out(['success' => true, 'data' => format_article($article)]);
