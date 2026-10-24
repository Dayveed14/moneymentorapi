<?php
// The knowledge check that personalises a learner's path
//   GET  /api/assessment.php  →  { assessment: latest result | null, history: every result oldest first }
//   POST /api/assessment.php  { score, total, level, topic_scores, goals, path }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/assessment.php';
require_once __DIR__ . '/../../src/modules.php';

require_method('GET', 'POST');
$user = require_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $score = filter_var($in['score'] ?? null, FILTER_VALIDATE_INT);
    $total = filter_var($in['total'] ?? null, FILTER_VALIDATE_INT);
    $level = str_field($in, 'level', 20);
    $topics = $in['topic_scores'] ?? null;
    $goals = $in['goals'] ?? [];
    $path = $in['path'] ?? null;

    if ($total === false || $score === false || $total < 1 || $total > 50 || $score < 0 || $score > $total) {
        fail('Invalid score.');
    }
    if (!in_array($level, ASSESSMENT_LEVELS, true)) {
        fail('Invalid level.');
    }
    if (!is_array($topics) || array_diff(array_keys($topics), ASSESSMENT_TOPICS)) {
        fail('Invalid topic scores.');
    }
    $topics = array_map(fn ($v) => max(0, min(10, (int) $v)), $topics);
    if (!is_array($goals) || array_diff($goals, ASSESSMENT_TOPICS)) {
        fail('Invalid goals.');
    }
    // Path: module order (known ids only) and key lessons (known ids only)
    $order = array_values(array_filter((array) ($path['modules'] ?? []), fn ($m) => is_string($m) && valid_module($m)));
    $keyLessons = [];
    foreach ((array) ($path['key_lessons'] ?? []) as $kl) {
        if (is_array($kl) && valid_lesson((string) ($kl['module'] ?? ''), (string) ($kl['lesson'] ?? ''))) {
            $keyLessons[] = ['module' => $kl['module'], 'lesson' => $kl['lesson']];
        }
    }
    $articleTopics = array_values(array_filter((array) ($path['article_categories'] ?? []), 'is_string'));
    $cleanPath = ['modules' => $order, 'key_lessons' => array_slice($keyLessons, 0, 8), 'article_categories' => array_slice($articleTopics, 0, 5)];

    db()->prepare('INSERT INTO assessments (user_id, score, total, level, topic_scores, goals, path) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], $score, $total, $level, json_encode($topics), json_encode(array_values(array_unique($goals))), json_encode($cleanPath)]);
}

json_out([
    'success' => true,
    'assessment' => latest_assessment((int) $user['id']),
    'history' => assessment_history((int) $user['id']),
]);
