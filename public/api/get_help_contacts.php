<?php
// GET /api/get_help_contacts.php  →  published bank fraud lines and agencies
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
$rows = db()->query("SELECT id, name, kind, ussd, phone, url, note FROM help_contacts
                     WHERE status = 'published' ORDER BY kind, sort_order, name")->fetchAll();
header('Cache-Control: public, max-age=60');
json_out(['success' => true, 'data' => array_map(fn ($r) => ['id' => (int) $r['id']] + $r, $rows)]);
