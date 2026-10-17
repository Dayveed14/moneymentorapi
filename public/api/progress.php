<?php
// Learner progress
//   GET  /api/progress.php  →  { lessons: {module: [lessonIds]}, quizzes: {module: {best, total, attempts, passed}} }
//   POST /api/progress.php  { module, lesson, completed: true|false }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/modules.php';

require_method('GET', 'POST');
$user = require_user();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $module = str_field($in, 'module', 40);
    $lesson = str_field($in, 'lesson', 60);
    if (!valid_lesson($module, $lesson)) {
        fail('Unknown module or lesson.');
    }
    if (($in['completed'] ?? true) === false) {
        $pdo->prepare('DELETE FROM lesson_progress WHERE user_id = ? AND module_id = ? AND lesson_id = ?')
            ->execute([$user['id'], $module, $lesson]);
    } else {
        $pdo->prepare('INSERT IGNORE INTO lesson_progress (user_id, module_id, lesson_id) VALUES (?, ?, ?)')
            ->execute([$user['id'], $module, $lesson]);
    }
}

$lessons = array_fill_keys(array_keys(LEARNING_MODULES), []);
$stmt = $pdo->prepare('SELECT module_id, lesson_id FROM lesson_progress WHERE user_id = ? ORDER BY completed_at');
$stmt->execute([$user['id']]);
foreach ($stmt->fetchAll() as $row) {
    if (valid_lesson($row['module_id'], $row['lesson_id'])) {
        $lessons[$row['module_id']][] = $row['lesson_id'];
    }
}

$quizzes = new stdClass();
$stmt = $pdo->prepare('
    SELECT module_id, MAX(score * 100 DIV total) AS best_pct, COUNT(*) AS attempts, MAX(created_at) AS last_at
    FROM quiz_attempts WHERE user_id = ? GROUP BY module_id
');
$stmt->execute([$user['id']]);
foreach ($stmt->fetchAll() as $row) {
    $quizzes->{$row['module_id']} = [
        'best_percent' => (int) $row['best_pct'],
        'attempts' => (int) $row['attempts'],
        'passed' => (int) $row['best_pct'] >= 80,
        'last_at' => $row['last_at'],
    ];
}

json_out(['success' => true, 'lessons' => $lessons, 'quizzes' => $quizzes]);
