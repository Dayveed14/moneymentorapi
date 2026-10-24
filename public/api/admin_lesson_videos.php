<?php
// Lesson videos (admin)
//   POST /api/admin_lesson_videos.php  { module, lesson, url }   (empty url removes the video)
// Accepts any normal YouTube link (watch, youtu.be, shorts, embed) and stores only the video id.
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/modules.php';

require_method('POST');
require_user('admin');
$in = input_json();
$module = str_field($in, 'module', 40);
$lesson = str_field($in, 'lesson', 60);
$url = str_field($in, 'url', 300);

if (!valid_lesson($module, $lesson)) {
    fail('Unknown lesson.');
}
$pdo = db();
if ($url === '') {
    $pdo->prepare('DELETE FROM lesson_videos WHERE module_id = ? AND lesson_id = ?')->execute([$module, $lesson]);
    json_out(['success' => true, 'message' => 'Video removed.']);
}

$id = null;
if (preg_match('~^(?:https?://)?(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
    $id = $m[1];
} elseif (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
    $id = $url;
}
if (!$id) {
    fail('Paste a YouTube link, for example https://www.youtube.com/watch?v=...');
}
$pdo->prepare('REPLACE INTO lesson_videos (module_id, lesson_id, youtube_id) VALUES (?, ?, ?)')->execute([$module, $lesson, $id]);
json_out(['success' => true, 'message' => 'Video saved.', 'youtube_id' => $id]);
