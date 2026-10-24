<?php
// Share links with a proper preview on WhatsApp, Facebook, X and LinkedIn.
//   GET /api/share.php?article=12   GET /api/share.php?alert=3   GET /api/share.php?page=alerts
// The frontend is a single-page app, so link-preview bots can't read its per-page tags.
// This page gives them the title, summary and image, then sends people on to the real page.
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('GET');
$pdo = db();
$title = 'MoneyMentor: in doubt about money? Check here first';
$description = 'Free lessons, scam alerts and a message checker to help you avoid fraud and manage your money.';
$image = frontend_url('og-image.png');
$target = frontend_url();

$scheme = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || ($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
$apiBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if (($id = int_query('article', 0, 0, PHP_INT_MAX)) > 0) {
    $stmt = $pdo->prepare("SELECT id, title, summary, image_path FROM articles WHERE id = ? AND status = 'published'");
    $stmt->execute([$id]);
    if ($a = $stmt->fetch()) {
        $title = $a['title'];
        $description = $a['summary'];
        $target = frontend_url('article/' . $a['id']);
        if ($a['image_path'] !== '') {
            $image = preg_match('#^https?://#', $a['image_path']) ? $a['image_path'] : $apiBase . '/' . ltrim($a['image_path'], '/');
        }
    }
} elseif (($id = int_query('alert', 0, 0, PHP_INT_MAX)) > 0) {
    $stmt = $pdo->prepare("SELECT id, title, summary FROM scam_alerts WHERE id = ? AND status = 'published'");
    $stmt->execute([$id]);
    if ($a = $stmt->fetch()) {
        $title = 'Scam alert: ' . $a['title'];
        $description = $a['summary'];
        $target = frontend_url('alerts#alert-' . $a['id']);
    }
} else {
    $pages = [
        'alerts' => ['Scam alerts | MoneyMentor', 'The latest scams going round, the red flags to look for and what to do.'],
        'help-now' => ['Got scammed? Do this now | MoneyMentor', 'What to do in the first hour after sharing a code or sending money to a scammer.'],
        'verify' => ['Check before you trust | MoneyMentor', 'Check a suspicious message, a loan app or an investment before you act.'],
    ];
    $page = str_field($_GET, 'page', 20);
    if (isset($pages[$page])) {
        [$title, $description] = $pages[$page];
        $target = frontend_url($page);
    }
}

$e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=600');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $e($title) ?></title>
<meta name="description" content="<?= $e($description) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="MoneyMentor">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:description" content="<?= $e($description) ?>">
<meta property="og:image" content="<?= $e($image) ?>">
<meta property="og:url" content="<?= $e($target) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="canonical" href="<?= $e($target) ?>">
<meta http-equiv="refresh" content="0; url=<?= $e($target) ?>">
</head>
<body style="font-family:Arial,sans-serif;padding:24px">
<p><a href="<?= $e($target) ?>">Continue to MoneyMentor</a></p>
<script>location.replace(<?= json_encode($target) ?>);</script>
</body>
</html>
