<?php
// GET /api/admin_stats.php  (admin)  →  dashboard numbers + recent activity
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
require_user('admin');
$pdo = db();

$one = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();

$stats = [
    'users_total' => $one('SELECT COUNT(*) FROM users'),
    'users_last_30_days' => $one('SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY'),
    'articles_published' => $one("SELECT COUNT(*) FROM articles WHERE status = 'published'"),
    'articles_draft' => $one("SELECT COUNT(*) FROM articles WHERE status = 'draft'"),
    'views_total' => $one('SELECT COALESCE(SUM(view_count), 0) FROM articles'),
    'subscribers_total' => $one('SELECT COUNT(*) FROM subscribers'),
    'messages_unread' => $one('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0'),
    'lessons_completed' => $one('SELECT COUNT(*) FROM lesson_progress'),
    'quiz_attempts' => $one('SELECT COUNT(*) FROM quiz_attempts'),
    'active_learners' => $one('SELECT COUNT(DISTINCT user_id) FROM lesson_progress WHERE completed_at >= NOW() - INTERVAL 30 DAY'),
];

// Latest events across users, articles and messages (queried separately so
// tables with different collations never have to be combined in SQL)
$activity = array_merge(
    $pdo->query("SELECT 'user' AS type, CONCAT(name, ' joined') AS title, created_at AS at
                 FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll(),
    $pdo->query("SELECT 'article' AS type, CONCAT(IF(status = 'published', 'Published: ', 'Draft saved: '), title) AS title,
                 created_at AS at FROM articles ORDER BY created_at DESC LIMIT 5")->fetchAll(),
    $pdo->query("SELECT 'message' AS type, CONCAT('Message from ', name, IF(subject <> '', CONCAT(': ', subject), '')) AS title,
                 created_at AS at FROM contact_messages ORDER BY created_at DESC LIMIT 5")->fetchAll()
);
usort($activity, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
$activity = array_slice($activity, 0, 8);

json_out(['success' => true, 'stats' => $stats, 'activity' => $activity]);
