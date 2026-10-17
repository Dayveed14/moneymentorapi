<?php
// GET /api/admin_user.php?id=5  (admin)  →  one learner's profile, progress and knowledge-check result
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/assessment.php';
require_once __DIR__ . '/../../src/modules.php';

require_method('GET');
require_user('admin');
$id = int_query('id', 0, 0, PHP_INT_MAX);
$pdo = db();

$stmt = $pdo->prepare('SELECT id, name, email, role, created_at, last_login FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch() ?: fail('User not found.', 404);
$user['id'] = (int) $user['id'];

$modules = [];
foreach (LEARNING_MODULES as $module => $lessons) {
    $done = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND module_id = ?');
    $done->execute([$id, $module]);
    $quiz = $pdo->prepare('SELECT MAX(score * 100 DIV total) AS best, COUNT(*) AS attempts FROM quiz_attempts WHERE user_id = ? AND module_id = ?');
    $quiz->execute([$id, $module]);
    $q = $quiz->fetch();
    $modules[] = [
        'module' => $module,
        'lessons_done' => (int) $done->fetchColumn(),
        'lessons_total' => count($lessons),
        'quiz_best' => $q['best'] !== null ? (int) $q['best'] : null,
        'quiz_attempts' => (int) $q['attempts'],
    ];
}

json_out(['success' => true, 'user' => $user, 'modules' => $modules, 'assessment' => latest_assessment($id)]);
