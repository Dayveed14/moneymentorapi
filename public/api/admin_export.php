<?php
// GET /api/admin_export.php?type=users|articles|messages|subscribers  (admin)  →  CSV download
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
require_user('admin');

$queries = [
    'users' => 'SELECT id, name, email, role, created_at, last_login FROM users ORDER BY id',
    'articles' => 'SELECT a.id, a.title, a.category, a.status, a.published_date, a.view_count, u.name AS author
                   FROM articles a LEFT JOIN users u ON u.id = a.author_id ORDER BY a.id',
    'messages' => 'SELECT id, name, email, subject, message, is_read, created_at FROM contact_messages ORDER BY id',
    'subscribers' => 'SELECT id, email, created_at FROM subscribers ORDER BY id',
];
$type = str_field($_GET, 'type', 20);
$sql = $queries[$type] ?? fail('Unknown export type.');

$rows = db()->query($sql)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="moneymentor-' . $type . '-' . gmdate('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
if ($rows) {
    fputcsv($out, array_keys($rows[0]));
    foreach ($rows as $row) {
        // Stop spreadsheet formula injection (cells starting with = + - @)
        fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $row));
    }
}
fclose($out);
