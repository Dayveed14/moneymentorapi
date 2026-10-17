<?php
// POST /api/update_article.php  (admin, multipart/form-data)
// fields: id, title, summary, body, category, status, image (optional — keeps the old one if absent)
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/articles.php';

require_method('POST');
require_user('admin');
$pdo = db();

$id = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, status, image_path, image_public_id, published_date FROM articles WHERE id = ?');
$stmt->execute([$id]);
$article = $stmt->fetch() ?: fail('Article not found.', 404);

$title = str_field($_POST, 'title', 1000);
$summary = str_field($_POST, 'summary', 1000);
$body = str_field($_POST, 'body', 200000);
$category = str_field($_POST, 'category', 50);
$status = str_field($_POST, 'status', 20) ?: $article['status'];

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

$newImage = null;
if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $newImage = store_uploaded_image($_FILES['image']);
}

try {
    $pdo->prepare('
        UPDATE articles SET title = ?, summary = ?, body = ?, category = ?, status = ?,
               published_date = CASE WHEN ? = \'published\' THEN COALESCE(published_date, UTC_TIMESTAMP()) ELSE published_date END,
               image_path = COALESCE(?, image_path), image_public_id = CASE WHEN ? IS NULL THEN image_public_id ELSE ? END
        WHERE id = ?
    ')->execute([
        $title, $summary, $body, $category, $status, $status,
        $newImage['path'] ?? null, $newImage['path'] ?? null, $newImage['public_id'] ?? null, $id,
    ]);
} catch (Throwable $e) {
    if ($newImage) {
        delete_stored_image($newImage['path'], $newImage['public_id']);
    }
    throw $e;
}

// Remove the old image only after the article points at the new one
if ($newImage) {
    delete_stored_image($article['image_path'], $article['image_public_id']);
}

json_out(['success' => true, 'message' => 'Article updated.']);
