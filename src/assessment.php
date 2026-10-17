<?php
declare(strict_types=1);

const ASSESSMENT_TOPICS = ['security', 'saving', 'budgeting', 'credit', 'investing'];
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
