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

/** Emails a published alert to every subscriber. Returns [sent, failed]. */
function email_alert_to_subscribers(array $alert): array
{
    require_once __DIR__ . '/mail.php';
    $a = format_alert($alert);
    $li = fn (array $items) => '<ul style="margin:0 0 14px;padding-left:20px">'
        . implode('', array_map(fn ($i) => '<li>' . htmlspecialchars($i, ENT_QUOTES) . '</li>', $items)) . '</ul>';
    $link = frontend_url('alerts#alert-' . $a['id']);
    $html = '<p>' . htmlspecialchars($a['summary'], ENT_QUOTES) . '</p>'
        . '<p style="margin:0 0 6px"><strong>Red flags</strong></p>' . $li($a['red_flags'])
        . '<p style="margin:0 0 6px"><strong>What to do</strong></p>' . $li($a['what_to_do'])
        . mail_button($link, 'Read and share this alert');
    $text = "{$a['summary']}\n\nRed flags:\n- " . implode("\n- ", $a['red_flags'])
        . "\n\nWhat to do:\n- " . implode("\n- ", $a['what_to_do']) . "\n\n$link";
    return mail_subscribers('Scam alert: ' . $a['title'], 'Scam alert: ' . $a['title'], $html, $text);
}
