<?php
// GET /api/get_scam_alerts.php?limit=6  →  published scam alerts, newest first
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/alerts.php';

require_method('GET');
$limit = int_query('limit', 20, 1, 50);
$rows = db()->query("SELECT id, title, summary, summary_pcm, red_flags, what_to_do, severity, created_at, updated_at
                     FROM scam_alerts WHERE status = 'published'
                     ORDER BY FIELD(severity, 'high', 'medium', 'low'), created_at DESC, id DESC
                     LIMIT $limit")->fetchAll();
header('Cache-Control: public, max-age=60');
json_out(['success' => true, 'data' => array_map('format_alert', $rows)]);
