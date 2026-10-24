<?php
// Ask an expert (logged in)
//   GET  /api/questions.php  →  my questions and their answers
//   POST /api/questions.php  { topic, question }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/questions.php';

require_method('GET', 'POST');
$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $topic = str_field($in, 'topic', 30) ?: 'other';
    $question = str_field($in, 'question', 2000);
    if (!in_array($topic, QUESTION_TOPICS, true)) {
        fail('Choose a topic.');
    }
    if (mb_strlen($question) < 15) {
        fail('Write your question in a sentence or two so we can help properly.');
    }
    enforce_rate_limit('ask:' . $user['id'], 5, 86400, 'You can ask up to 5 questions a day. We\'ll answer the ones you\'ve sent first.');
    $pdo->prepare('INSERT INTO questions (user_id, topic, question) VALUES (?, ?, ?)')->execute([$user['id'], $topic, $question]);
    json_out(['success' => true, 'message' => 'Question sent. We\'ll email you when it\'s answered.'], 201);
}

$stmt = $pdo->prepare('SELECT id, topic, question, answer, status, is_public, answered_at, created_at
                       FROM questions WHERE user_id = ? ORDER BY created_at DESC, id DESC');
$stmt->execute([$user['id']]);
json_out(['success' => true, 'data' => array_map('format_question', $stmt->fetchAll())]);
