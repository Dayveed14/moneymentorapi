<?php
// POST /api/add_article.php  (admin only, multipart/form-data)
// fields: title, summary, body, category, status, image
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/articles.php';

require_method('POST');
$admin = require_user('admin'); // the author is the logged-in admin, never a form field

$title = str_field($_POST, 'title', 1000);
$summary = str_field($_POST, 'summary', 1000);
$body = str_field($_POST, 'body', 200000);
$category = str_field($_POST, 'category', 50);
$status = str_field($_POST, 'status', 20) ?: 'draft';

if ($title === '' || $summary === '' || $body === '' || $category === '') {
    fail('Fill in the title, category, summary and body.');
}
if (mb_strlen($title) > 200) {
    fail('Keep the title to 200 characters or fewer.');
}
if (mb_strlen($summary) > 300) {
    fail('Keep the summary to 300 characters or fewer.');
}
if (!in_array($category, ARTICLE_CATEGORIES, true)) {
    fail('Choose a category from the list.');
}
if (!in_array($status, ['draft', 'published'], true)) {
    fail('Status must be draft or published.');
}

$image = store_uploaded_image($_FILES['image'] ?? []);

try {
    db()->prepare('
        INSERT INTO articles (title, summary, body, category, image_path, image_public_id, author_id, status, published_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $title, $summary, $body, $category, $image['path'], $image['public_id'], $admin['id'], $status,
        $status === 'published' ? gmdate('Y-m-d H:i:s') : null,
    ]);
} catch (Throwable $e) {
    // Don't leave an orphaned image behind if the article couldn't be saved
    delete_stored_image($image['path'], $image['public_id']);
    throw $e;
}

json_out([
    'success' => true,
    'message' => $status === 'published' ? 'Article published.' : 'Draft saved.',
    'article_id' => (int) db()->lastInsertId(),
], 201);
