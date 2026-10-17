<?php
// GET /api/image.php?id=3  →  the stored image bytes (long-cached)
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
$id = int_query('id', 0, 0, PHP_INT_MAX);
$stmt = db()->prepare('SELECT mime, data, created_at FROM article_images WHERE id = ?');
$stmt->execute([$id]);
$img = $stmt->fetch();

if (!$img) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}

$etag = '"img-' . $id . '-' . strlen($img['data']) . '"';
header('Content-Type: ' . $img['mime']);
header('Cache-Control: public, max-age=31536000, immutable'); // images never change once uploaded
header('ETag: ' . $etag);
header('Content-Security-Policy: default-src \'none\'');
header('Cross-Origin-Resource-Policy: cross-origin');

if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($img['data']));
echo $img['data'];
