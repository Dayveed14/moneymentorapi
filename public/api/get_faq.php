<?php
// GET /api/get_faq.php?topic=scams&search=otp  →  answered questions an admin made public (no names)
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/questions.php';

require_method('GET');
$where = ["status = 'answered'", 'is_public = 1'];
$params = [];
$topic = str_field($_GET, 'topic', 30);
if (in_array($topic, QUESTION_TOPICS, true)) {
    $where[] = 'topic = ?';
    $params[] = $topic;
}
$search = str_field($_GET, 'search', 100);
if ($search !== '') {
    $where[] = '(LOWER(question) LIKE ? OR LOWER(answer) LIKE ?)';
    $like = '%' . mb_strtolower($search) . '%';
    array_push($params, $like, $like);
}
$stmt = db()->prepare('SELECT id, topic, question, answer, answered_at FROM questions WHERE ' . implode(' AND ', $where)
    . ' ORDER BY answered_at DESC, id DESC LIMIT 100');
$stmt->execute($params);
header('Cache-Control: public, max-age=120');
json_out(['success' => true, 'data' => array_map('format_question', $stmt->fetchAll())]);
