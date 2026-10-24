<?php
declare(strict_types=1);

/*
 * Sending email over an HTTP API (Render's free plan blocks SMTP ports).
 *
 *   MAIL_DRIVER=brevo   BREVO_API_KEY=...     (free: 300 emails/day, verify a single sender)
 *   MAIL_DRIVER=resend  RESEND_API_KEY=...    (free: 100/day, needs a verified domain)
 *   MAIL_DRIVER=log     (default) — nothing is sent; emails are written to storage/mail.log
 *
 *   MAIL_FROM=hello@yourdomain.com   MAIL_FROM_NAME=MoneyMentor
 */

function mail_driver(): string
{
    $driver = strtolower((string) env('MAIL_DRIVER', 'log'));
    return in_array($driver, ['brevo', 'resend', 'log'], true) ? $driver : 'log';
}

/** Whether real emails go out (the admin UI shows a note when they don't). */
function mail_enabled(): bool
{
    return mail_driver() !== 'log';
}

/** Public URL of the frontend, used for links inside emails. */
function frontend_url(string $path = ''): string
{
    $base = env('FRONTEND_URL');
    if (!$base) {
        $origins = array_filter(array_map('trim', explode(',', (string) env('ALLOWED_ORIGINS', 'http://localhost:5173'))));
        $base = $origins ? reset($origins) : 'http://localhost:5173';
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** Wraps content in a simple branded email layout. $bodyHtml must already be escaped. */
function mail_layout(string $heading, string $bodyHtml, string $footerHtml = ''): string
{
    $h = htmlspecialchars($heading, ENT_QUOTES);
    $site = htmlspecialchars(frontend_url(), ENT_QUOTES);
    return <<<HTML
<!doctype html><html><body style="margin:0;background:#f3faf5;font-family:Arial,Helvetica,sans-serif;color:#17332c">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3faf5;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #d7e9de">
<tr><td style="background:#0f3d33;padding:18px 24px;color:#def6e5;font-size:20px;font-weight:bold">MoneyMentor</td></tr>
<tr><td style="padding:24px">
<h1 style="font-size:20px;margin:0 0 14px;color:#0f3d33">$h</h1>
<div style="font-size:15px;line-height:1.6">$bodyHtml</div>
</td></tr>
<tr><td style="background:#def6e5;padding:14px 24px;font-size:12px;color:#3d5c53">
<strong>Your bank will never ask for your OTP, PIN or password.</strong> In doubt? <a href="$site" style="color:#0b6e8f">Check MoneyMentor first</a>.
$footerHtml
</td></tr></table></td></tr></table></body></html>
HTML;
}

/** Turns plain text (blank line = new paragraph) into safe HTML paragraphs. */
function text_to_html(string $text): string
{
    $paras = preg_split('/\R{2,}/', trim($text)) ?: [];
    return implode('', array_map(
        fn ($p) => '<p style="margin:0 0 12px">' . nl2br(htmlspecialchars($p, ENT_QUOTES)) . '</p>',
        $paras
    ));
}

/** A button-style link for emails. */
function mail_button(string $url, string $label): string
{
    $u = htmlspecialchars($url, ENT_QUOTES);
    $l = htmlspecialchars($label, ENT_QUOTES);
    return "<p style=\"margin:20px 0\"><a href=\"$u\" style=\"background:#0f3d33;color:#ffffff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:bold;display:inline-block\">$l</a></p>";
}

/**
 * Sends one email. Returns true on success; failures are logged, never thrown,
 * so a mail outage can't break the request that triggered it.
 */
function send_mail(string $to, string $subject, string $html, string $text, array $headers = []): bool
{
    $from = (string) env('MAIL_FROM', 'no-reply@moneymentor.local');
    $fromName = (string) env('MAIL_FROM_NAME', 'MoneyMentor');
    $driver = mail_driver();

    if ($driver === 'log') {
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $entry = sprintf("==== %s\nTo: %s\nSubject: %s\n\n%s\n\n", gmdate('c'), $to, $subject, $text);
        @file_put_contents("$dir/mail.log", $entry, FILE_APPEND | LOCK_EX);
        return true;
    }

    if ($driver === 'brevo') {
        $url = rtrim((string) env('BREVO_API_BASE', 'https://api.brevo.com/v3'), '/') . '/smtp/email';
        $payload = [
            'sender' => ['name' => $fromName, 'email' => $from],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ];
        if ($headers) {
            $payload['headers'] = $headers;
        }
        $httpHeaders = ['api-key: ' . env('BREVO_API_KEY', ''), 'Content-Type: application/json', 'Accept: application/json'];
    } else {
        $url = rtrim((string) env('RESEND_API_BASE', 'https://api.resend.com'), '/') . '/emails';
        $payload = ['from' => "$fromName <$from>", 'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => $text];
        if ($headers) {
            $payload['headers'] = $headers;
        }
        $httpHeaders = ['Authorization: Bearer ' . env('RESEND_API_KEY', ''), 'Content-Type: application/json'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    if (!ini_get('curl.cainfo') && ($ca = ca_bundle())) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status >= 300) {
        error_log("[moneymentor] mail via $driver to $to failed: " . ($err ?: "HTTP $status " . substr((string) $body, 0, 300)));
        return false;
    }
    return true;
}

/** Signed token for one-click unsubscribe links (no extra column needed). */
function unsubscribe_token(string $email): string
{
    return substr(hash_hmac('sha256', 'unsub|' . strtolower($email), app_secret()), 0, 32);
}

function unsubscribe_url(string $email): string
{
    return frontend_url('unsubscribe?e=' . rawurlencode($email) . '&t=' . unsubscribe_token($email));
}

/**
 * Sends the same message to every subscriber, each with their own unsubscribe link.
 * Returns [sent, failed]. Capped by MAIL_BATCH_LIMIT (Brevo free = 300/day).
 */
function mail_subscribers(string $subject, string $heading, string $bodyHtml, string $bodyText): array
{
    @set_time_limit(300);
    $limit = max(1, (int) env('MAIL_BATCH_LIMIT', '300'));
    $emails = db()->query("SELECT email FROM subscribers ORDER BY id LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    $failed = 0;
    foreach ($emails as $email) {
        $unsub = unsubscribe_url($email);
        $footer = '<br><a href="' . htmlspecialchars($unsub, ENT_QUOTES) . '" style="color:#3d5c53">Unsubscribe</a>';
        $ok = send_mail(
            $email,
            $subject,
            mail_layout($heading, $bodyHtml, $footer),
            $bodyText . "\n\nUnsubscribe: $unsub",
            ['List-Unsubscribe' => "<$unsub>"]
        );
        $ok ? $sent++ : $failed++;
    }
    return [$sent, $failed];
}
