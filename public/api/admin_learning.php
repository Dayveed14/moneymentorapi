<?php
// GET /api/admin_learning.php  (admin)  →  how learners are doing across modules, quizzes and the knowledge check
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/modules.php';
require_once __DIR__ . '/../../src/assessment.php';

require_method('GET');
require_user('admin');
$pdo = db();

$modules = [];
foreach (LEARNING_MODULES as $module => $lessons) {
    $started = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM lesson_progress WHERE module_id = ?');
    $started->execute([$module]);
    $finished = $pdo->prepare('SELECT COUNT(*) FROM (SELECT user_id FROM lesson_progress WHERE module_id = ? GROUP BY user_id HAVING COUNT(*) >= ?) t');
    $finished->execute([$module, count($lessons)]);
    $quiz = $pdo->prepare('
        SELECT COUNT(*) AS attempts, COUNT(DISTINCT user_id) AS takers,
               AVG(score * 100 / total) AS avg_score,
               SUM(CASE WHEN score * 100 / total >= 80 THEN 1 ELSE 0 END) AS passes
        FROM quiz_attempts WHERE module_id = ?');
    $quiz->execute([$module]);
    $q = $quiz->fetch();
    $passedUsers = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM quiz_attempts WHERE module_id = ? AND score * 100 / total >= 80');
    $passedUsers->execute([$module]);

    // Lesson-by-lesson completions, to spot where learners stop
    $perLesson = $pdo->prepare('SELECT lesson_id, COUNT(*) AS n FROM lesson_progress WHERE module_id = ? GROUP BY lesson_id');
    $perLesson->execute([$module]);
    $counts = array_column($perLesson->fetchAll(), 'n', 'lesson_id');

    $modules[] = [
        'module' => $module,
        'learners_started' => (int) $started->fetchColumn(),
        'learners_finished_lessons' => (int) $finished->fetchColumn(),
        'quiz_attempts' => (int) $q['attempts'],
        'quiz_takers' => (int) $q['takers'],
        'quiz_passed_learners' => (int) $passedUsers->fetchColumn(),
        'quiz_average' => $q['avg_score'] !== null ? (int) round((float) $q['avg_score']) : null,
        'lessons' => array_map(fn ($l) => ['lesson' => $l, 'completions' => (int) ($counts[$l] ?? 0)], $lessons),
    ];
}

// Knowledge check: everyone's latest result
$latest = $pdo->query('
    SELECT a.level, a.score, a.total, a.topic_scores, a.goals
    FROM assessments a
    JOIN (SELECT user_id, MAX(id) AS id FROM assessments GROUP BY user_id) last ON last.id = a.id
')->fetchAll();

$levels = array_fill_keys(ASSESSMENT_LEVELS, 0);
$topicTotals = array_fill_keys(ASSESSMENT_TOPICS, 0);
$goalCounts = array_fill_keys(ASSESSMENT_TOPICS, 0);
foreach ($latest as $row) {
    $levels[$row['level']] = ($levels[$row['level']] ?? 0) + 1;
    foreach ((array) json_decode($row['topic_scores'], true) as $topic => $score) {
        if (isset($topicTotals[$topic])) {
            $topicTotals[$topic] += (int) $score;
        }
    }
    foreach ((array) json_decode($row['goals'], true) as $goal) {
        if (isset($goalCounts[$goal])) {
            $goalCounts[$goal]++;
        }
    }
}
$n = count($latest);
// Each topic has 2 questions → average correct out of 2, as a percentage
$topicAverages = array_map(fn ($t) => $n ? (int) round($t / ($n * 2) * 100) : null, $topicTotals);

json_out([
    'success' => true,
    'modules' => $modules,
    'assessment' => [
        'learners' => $n,
        'levels' => $levels,
        'topic_average_percent' => $topicAverages,
        'goals' => $goalCounts,
    ],
]);
