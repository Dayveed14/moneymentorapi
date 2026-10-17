<?php
/**
 * One-off: copies existing article images to Cloudinary and updates the articles.
 * Handles images stored in the database (api/image.php?id=…) and old files in
 * public/uploads/articles/. Safe to run more than once.
 *
 *   php bin/move_images_to_cloudinary.php            # do it
 *   php bin/move_images_to_cloudinary.php --dry-run  # just list what would move
 *
 * Run it from your machine with DB_* and CLOUDINARY_URL pointing at production
 * (e.g. in .env), since Render's free plan has no shell.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/env.php';
require_once __DIR__ . '/../src/http.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/cloudinary.php';

$dryRun = in_array('--dry-run', $argv, true);
if (!cloudinary_config()) {
    fwrite(STDERR, "Set CLOUDINARY_URL first.\n");
    exit(1);
}

$pdo = db();
$rows = $pdo->query("SELECT id, title, image_path FROM articles
                     WHERE image_public_id IS NULL AND image_path <> '' AND image_path NOT LIKE 'http%'")->fetchAll();
echo count($rows) . " article image(s) to move\n";

$moved = 0;
foreach ($rows as $row) {
    $tmp = tempnam(sys_get_temp_dir(), 'mm');
    $dbImageId = null;

    if (preg_match('/image\.php\?id=(\d+)/', $row['image_path'], $m)) {
        $dbImageId = (int) $m[1];
        $img = $pdo->prepare('SELECT mime, data FROM article_images WHERE id = ?');
        $img->execute([$dbImageId]);
        $img = $img->fetch();
        if (!$img) {
            echo "  #{$row['id']} skipped: stored image $dbImageId not found\n";
            continue;
        }
        file_put_contents($tmp, $img['data']);
        $mime = $img['mime'];
    } else {
        $file = realpath(__DIR__ . '/../public/' . ltrim($row['image_path'], '/'));
        $uploads = realpath(__DIR__ . '/../public/uploads');
        if (!$file || !$uploads || !str_starts_with($file, $uploads) || !is_file($file)) {
            echo "  #{$row['id']} skipped: file {$row['image_path']} not found\n";
            continue;
        }
        copy($file, $tmp);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    }

    if ($dryRun) {
        echo "  #{$row['id']} would move {$row['image_path']}\n";
        unlink($tmp);
        continue;
    }

    try {
        $up = cloudinary_upload($tmp, $mime);
        $pdo->prepare('UPDATE articles SET image_path = ?, image_public_id = ? WHERE id = ?')
            ->execute([$up['url'], $up['public_id'], $row['id']]);
        if ($dbImageId) {
            $pdo->prepare('DELETE FROM article_images WHERE id = ?')->execute([$dbImageId]);
        }
        echo "  #{$row['id']} moved → {$up['url']}\n";
        $moved++;
    } catch (Throwable $e) {
        echo "  #{$row['id']} failed: {$e->getMessage()}\n";
    } finally {
        @unlink($tmp);
    }
}
echo "done: $moved moved\n";
