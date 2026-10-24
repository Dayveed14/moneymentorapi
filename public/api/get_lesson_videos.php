<?php
// GET /api/get_lesson_videos.php?module=savings  →  { "lesson-id": "youtubeId", ... }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
$module = str_field($_GET, 'module', 40);
if ($module !== '') {
    $stmt = db()->prepare('SELECT module_id, lesson_id, youtube_id FROM lesson_videos WHERE module_id = ?');
    $stmt->execute([$module]);
} else {
    $stmt = db()->query('SELECT module_id, lesson_id, youtube_id FROM lesson_videos');
}
$out = [];
foreach ($stmt->fetchAll() as $r) {
    $out[$r['module_id']][$r['lesson_id']] = $r['youtube_id'];
}
header('Cache-Control: no-cache');
json_out(['success' => true, 'data' => $module !== '' ? ($out[$module] ?? (object) []) : ($out ?: (object) [])]);
