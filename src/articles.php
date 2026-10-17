<?php
declare(strict_types=1);

require_once __DIR__ . '/cloudinary.php';

// Keep in sync with ARTICLE_CATEGORIES in the frontend (src/lib/articles.js)
const ARTICLE_CATEGORIES = [
    'Budgeting', 'Savings', 'Investing', 'Debt Management', 'Financial Planning',
    'Retirement', 'Tax Planning', 'Insurance', 'Credit Cards', 'Real Estate',
];

// 5 MB keeps each image row under TiDB Cloud's default 6 MiB row-size limit
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Approximate reading time (200 words a minute) computed in SQL from the body. */
const READING_MINUTES_SQL =
    "GREATEST(1, ROUND((CHAR_LENGTH(a.body) - CHAR_LENGTH(REPLACE(a.body, ' ', '')) + 1) / 200))";

function format_article(array $row): array
{
    foreach (['id', 'view_count', 'reading_minutes', 'author_id'] as $intField) {
        if (array_key_exists($intField, $row) && $row[$intField] !== null) {
            $row[$intField] = (int) $row[$intField];
        }
    }
    return $row;
}

/**
 * Validates an uploaded image by its real contents (not the browser-supplied
 * type or file name), then stores it:
 *  - on Cloudinary when CLOUDINARY_URL is set (production)
 *  - otherwise in the database (handy for local development)
 *
 * Returns ['path' => image URL/path for the frontend, 'public_id' => Cloudinary id or null].
 */
function store_uploaded_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        fail('Please upload an article image.');
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        fail('That image is too large. The maximum is 5MB.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        fail('The image upload failed. Please try again.');
    }
    if ($file['size'] > MAX_IMAGE_BYTES) {
        fail('That image is too large. The maximum is 5MB.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_IMAGE_TYPES, true) || @getimagesize($file['tmp_name']) === false) {
        fail('Please upload a JPEG, PNG or WebP image.');
    }

    if (cloudinary_config()) {
        try {
            $up = cloudinary_upload($file['tmp_name'], $mime);
            return ['path' => $up['url'], 'public_id' => $up['public_id']];
        } catch (Throwable $e) {
            error_log('[moneymentor] ' . $e->getMessage());
            fail('The image couldn\'t be uploaded right now. Please try again in a moment.', 502);
        }
    }

    // No Cloudinary configured: keep the image in the database
    $data = file_get_contents($file['tmp_name']);
    $stmt = db()->prepare('INSERT INTO article_images (mime, byte_size, data) VALUES (?, ?, ?)');
    $stmt->bindValue(1, $mime);
    $stmt->bindValue(2, strlen($data), PDO::PARAM_INT);
    $stmt->bindValue(3, $data, PDO::PARAM_LOB);
    $stmt->execute();

    return ['path' => 'api/image.php?id=' . db()->lastInsertId(), 'public_id' => null];
}

/** Removes an article's image from wherever it is stored. */
function delete_stored_image(?string $imagePath, ?string $publicId = null): void
{
    if ($publicId) {
        cloudinary_destroy($publicId);
    } elseif ($imagePath && preg_match('/image\.php\?id=(\d+)/', $imagePath, $m)) {
        db()->prepare('DELETE FROM article_images WHERE id = ?')->execute([(int) $m[1]]);
    }
}
