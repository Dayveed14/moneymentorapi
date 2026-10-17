<?php
declare(strict_types=1);

/** Turns the stored newline-separated lists into arrays for the frontend. */
function format_alert(array $row): array
{
    $row['id'] = (int) $row['id'];
    foreach (['red_flags', 'what_to_do'] as $k) {
        $row[$k] = array_values(array_filter(array_map('trim', explode("\n", (string) $row[$k])), 'strlen'));
    }
    return $row;
}

/** Accepts an array or newline-separated text; returns cleaned newline-separated text. */
function alert_list_text(mixed $value, int $maxItems = 10): string
{
    $items = is_array($value) ? $value : explode("\n", (string) $value);
    $items = array_slice(array_values(array_filter(array_map(fn ($i) => mb_substr(trim((string) $i), 0, 200), $items), 'strlen')), 0, $maxItems);
    return implode("\n", $items);
}
