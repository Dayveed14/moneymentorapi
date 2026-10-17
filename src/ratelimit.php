<?php
declare(strict_types=1);

/**
 * Fixed-window rate limit stored in MySQL (no Redis needed).
 * Returns false when the caller is over the limit.
 */
function rate_limit_hit(string $bucket, int $max, int $windowSeconds): bool
{
    $pdo = db();
    $key = hash('sha256', $bucket);
    $now = time();

    $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);

    $stmt = $pdo->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row || $row['window_start'] <= $now - $windowSeconds) {
        $pdo->prepare('REPLACE INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)')
            ->execute([$key, $now]);
        return true;
    }
    if ((int) $row['hits'] >= $max) {
        return false;
    }
    $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$key]);
    return true;
}

function rate_limit_clear(string $bucket): void
{
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([hash('sha256', $bucket)]);
}

function enforce_rate_limit(string $bucket, int $max, int $windowSeconds, string $message): void
{
    if (!rate_limit_hit($bucket, $max, $windowSeconds)) {
        header('Retry-After: ' . $windowSeconds);
        fail($message, 429);
    }
}
