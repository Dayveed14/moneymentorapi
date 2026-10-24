<?php
declare(strict_types=1);

const QUESTION_TOPICS = ['scams', 'saving', 'budgeting', 'debt', 'investing', 'planning', 'banking', 'other'];

function format_question(array $r): array
{
    $r['id'] = (int) $r['id'];
    if (array_key_exists('is_public', $r)) {
        $r['is_public'] = (bool) $r['is_public'];
    }
    return $r;
}
