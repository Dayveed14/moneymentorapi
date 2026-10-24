<?php
// Help contacts (admin) — bank fraud codes/lines and agencies shown on the "Got scammed?" page
//   GET  /api/admin_help_contacts.php
//   POST { action: "create" | "update", id?, name, kind, ussd, phone, url, note, sort_order, status }
//   POST { action: "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $action = str_field($in, 'action', 20);
    $id = (int) ($in['id'] ?? 0);

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM help_contacts WHERE id = ?')->execute([$id]);
        json_out(['success' => true, 'message' => 'Contact deleted.']);
    }
    if (!in_array($action, ['create', 'update'], true)) {
        fail('Unknown action.');
    }

    $name = str_field($in, 'name', 120);
    $kind = str_field($in, 'kind', 10);
    $ussd = str_field($in, 'ussd', 40);
    $phone = str_field($in, 'phone', 120);
    $url = str_field($in, 'url', 300);
    $note = str_field($in, 'note', 300);
    $sort = (int) ($in['sort_order'] ?? 0);
    $status = str_field($in, 'status', 10) ?: 'draft';

    if ($name === '') {
        fail('Add a name.');
    }
    if (!in_array($kind, ['bank', 'agency'], true) || !in_array($status, ['draft', 'published'], true)) {
        fail('Invalid type or status.');
    }
    if ($ussd !== '' && !preg_match('/^[*#0-9]{3,40}$/', $ussd)) {
        fail('USSD codes can only contain digits, * and #.');
    }
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        fail('Links must start with https://');
    }
    if ($ussd === '' && $phone === '' && $url === '') {
        fail('Add at least a USSD code, a phone number or a link.');
    }

    if ($action === 'create') {
        $pdo->prepare('INSERT INTO help_contacts (name, kind, ussd, phone, url, note, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $kind, $ussd, $phone, $url, $note, $sort, $status]);
        json_out(['success' => true, 'message' => 'Contact added.'], 201);
    }
    $pdo->prepare('UPDATE help_contacts SET name = ?, kind = ?, ussd = ?, phone = ?, url = ?, note = ?, sort_order = ?, status = ? WHERE id = ?')
        ->execute([$name, $kind, $ussd, $phone, $url, $note, $sort, $status, $id]);
    json_out(['success' => true, 'message' => 'Contact updated.']);
}

$rows = $pdo->query('SELECT id, name, kind, ussd, phone, url, note, sort_order, status, updated_at FROM help_contacts ORDER BY kind, sort_order, name')->fetchAll();
json_out(['success' => true, 'data' => array_map(fn ($r) => ['id' => (int) $r['id'], 'sort_order' => (int) $r['sort_order']] + $r, $rows)]);
