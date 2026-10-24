<?php
declare(strict_types=1);

const ASSESSMENT_TOPICS = ['security', 'saving', 'budgeting', 'credit', 'investing', 'planning'];
const ASSESSMENT_LEVELS = ['Beginner', 'Developing', 'Confident'];

/** Has this user taken the knowledge check? */
function user_assessed(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM assessments WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/** Latest knowledge-check result, decoded, or null. */
function latest_assessment(int $userId): ?array
{
    $stmt = db()->prepare('SELECT score, total, level, topic_scores, goals, path, created_at
                           FROM assessments WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    foreach (['topic_scores', 'goals', 'path'] as $k) {
        $row[$k] = json_decode($row[$k], true);
    }
    $row['score'] = (int) $row['score'];
    $row['total'] = (int) $row['total'];
    return $row;
}

/** Every result for one user, oldest first (for before/after comparisons). */
function assessment_history(int $userId): array
{
    $stmt = db()->prepare('SELECT score, total, level, topic_scores, created_at FROM assessments WHERE user_id = ? ORDER BY created_at, id');
    $stmt->execute([$userId]);
    return array_map(fn ($r) => [
        'score' => (int) $r['score'],
        'total' => (int) $r['total'],
        'percent' => (int) round($r['score'] * 100 / max(1, $r['total'])),
        'level' => $r['level'],
        'topic_scores' => json_decode($r['topic_scores'], true),
        'created_at' => $r['created_at'],
    ], $stmt->fetchAll());
}

/**
 * First vs latest knowledge check for every user who has taken it.
 * Returns one row per user (anonymised id) — used for the admin "knowledge gain"
 * panel and the research CSV export.
 */
function knowledge_gain_rows(): array
{
    $pdo = db();
    $rows = $pdo->query('SELECT user_id, score, total, level, topic_scores, created_at FROM assessments ORDER BY user_id, created_at, id')->fetchAll();
    $byUser = [];
    foreach ($rows as $r) {
        $byUser[(int) $r['user_id']][] = $r;
    }

    $lessons = array_column($pdo->query('SELECT user_id, COUNT(*) AS n FROM lesson_progress GROUP BY user_id')->fetchAll(), 'n', 'user_id');
    $passed = array_column($pdo->query('SELECT user_id, COUNT(DISTINCT module_id) AS n FROM quiz_attempts WHERE score * 100 / total >= 80 GROUP BY user_id')->fetchAll(), 'n', 'user_id');

    $out = [];
    foreach ($byUser as $uid => $list) {
        $first = $list[0];
        $last = $list[count($list) - 1];
        $ft = json_decode($first['topic_scores'], true) ?: [];
        $lt = json_decode($last['topic_scores'], true) ?: [];
        $row = [
            'participant' => substr(hash_hmac('sha256', "participant|$uid", app_secret()), 0, 10),
            'attempts' => count($list),
            'first_date' => substr($first['created_at'], 0, 10),
            'latest_date' => substr($last['created_at'], 0, 10),
            'days_between' => (int) round((strtotime($last['created_at']) - strtotime($first['created_at'])) / 86400),
            'first_percent' => (int) round($first['score'] * 100 / max(1, $first['total'])),
            'latest_percent' => (int) round($last['score'] * 100 / max(1, $last['total'])),
            'first_level' => $first['level'],
            'latest_level' => $last['level'],
            'lessons_completed' => (int) ($lessons[$uid] ?? 0),
            'quizzes_passed' => (int) ($passed[$uid] ?? 0),
        ];
        $row['change_points'] = $row['latest_percent'] - $row['first_percent'];
        // Each topic has two questions → percent correct; blank if a topic wasn't in that version of the check
        foreach (ASSESSMENT_TOPICS as $t) {
            $row["first_$t"] = isset($ft[$t]) ? (int) $ft[$t] * 50 : '';
            $row["latest_$t"] = isset($lt[$t]) ? (int) $lt[$t] * 50 : '';
        }
        $out[] = $row;
    }
    return $out;
}

/** Summary of knowledge_gain_rows() for people who took the check at least twice. */
function knowledge_gain_summary(): array
{
    $retested = array_values(array_filter(knowledge_gain_rows(), fn ($r) => $r['attempts'] >= 2));
    $n = count($retested);
    $avg = fn (array $vals) => $vals ? (int) round(array_sum($vals) / count($vals)) : null;

    $topics = [];
    foreach (ASSESSMENT_TOPICS as $t) {
        $pairs = array_filter($retested, fn ($r) => $r["first_$t"] !== '' && $r["latest_$t"] !== '');
        $topics[$t] = [
            'first' => $avg(array_column($pairs, "first_$t")),
            'latest' => $avg(array_column($pairs, "latest_$t")),
            'learners' => count($pairs),
        ];
    }
    return [
        'retested' => $n,
        'first_average' => $avg(array_column($retested, 'first_percent')),
        'latest_average' => $avg(array_column($retested, 'latest_percent')),
        'improved' => count(array_filter($retested, fn ($r) => $r['change_points'] > 0)),
        'same' => count(array_filter($retested, fn ($r) => $r['change_points'] === 0)),
        'declined' => count(array_filter($retested, fn ($r) => $r['change_points'] < 0)),
        'average_days_between' => $avg(array_column($retested, 'days_between')),
        'topics' => $topics,
    ];
}
