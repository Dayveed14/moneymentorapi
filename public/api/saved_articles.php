<?php
// Bookmarks
//   GET  /api/saved_articles.php  →  saved, still-published articles (newest saved first)
//   POST /api/saved_articles.php  { article_id, saved: true|false }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/articles.php';

require_method('GET', 'POST');
$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $articleId = (int) ($in['article_id'] ?? 0);
    if (($in['saved'] ?? true) === false) {
        $pdo->prepare('DELETE FROM saved_articles WHERE user_id = ? AND article_id = ?')->execute([$user['id'], $articleId]);
    } else {
        $check = $pdo->prepare("SELECT id FROM articles WHERE id = ? AND status = 'published'");
        $check->execute([$articleId]);
        if (!$check->fetch()) {
            fail('Article not found.', 404);
        }
        $pdo->prepare('INSERT IGNORE INTO saved_articles (user_id, article_id) VALUES (?, ?)')->execute([$user['id'], $articleId]);
    }
}

$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.summary, a.image_path, a.category, a.published_date, a.view_count,
           " . READING_MINUTES_SQL . " AS reading_minutes, s.created_at AS saved_at
    FROM saved_articles s
    JOIN articles a ON a.id = s.article_id AND a.status = 'published'
    WHERE s.user_id = ?
    ORDER BY s.created_at DESC
    LIMIT 100
");
$stmt->execute([$user['id']]);

json_out(['success' => true, 'data' => array_map('format_article', $stmt->fetchAll())]);
