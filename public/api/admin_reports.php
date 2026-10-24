<?php
// Scam reports from visitors (admin)
//   GET  /api/admin_reports.php?page=1&status=new|reviewed|published|rejected|all
//   POST /api/admin_reports.php  { action: "set_status", id, status }  |  { action: "delete", id }
// To publish a report as an alert, the dashboard calls admin_scam_alerts.php with report_id.
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();
$statuses = ['new', 'reviewed', 'published', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $id = (int) ($in['id'] ?? 0);
    $action = str_field($in, 'action', 20);
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM scam_reports WHERE id = ?')->execute([$id]);
        json_out(['success' => true, 'message' => 'Report deleted.']);
    }
    if ($action === 'set_status') {
        $status = str_field($in, 'status', 12);
        if (!in_array($status, $statuses, true)) {
            fail('Invalid status.');
        }
        $pdo->prepare('UPDATE scam_reports SET status = ? WHERE id = ?')->execute([$status, $id]);
        json_out(['success' => true, 'message' => 'Report updated.']);
    }
    fail('Unknown action.');
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$status = str_field($_GET, 'status', 12) ?: 'new';
$where = in_array($status, $statuses, true) ? 'WHERE status = ' . $pdo->quote($status) : '';
$total = (int) $pdo->query("SELECT COUNT(*) FROM scam_reports $where")->fetchColumn();
$rows = $pdo->query("SELECT id, user_id, name, email, channel, scammer_contact, description, lost_money, amount_lost, status, alert_id, created_at
                     FROM scam_reports $where ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit))->fetchAll();
$rows = array_map(fn ($r) => [
    'id' => (int) $r['id'],
    'lost_money' => (bool) $r['lost_money'],
    'amount_lost' => $r['amount_lost'] === null ? null : (float) $r['amount_lost'],
    'alert_id' => $r['alert_id'] === null ? null : (int) $r['alert_id'],
] + $r, $rows);

$counts = array_fill_keys($statuses, 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM scam_reports GROUP BY status') as $c) {
    $counts[$c['status']] = (int) $c['n'];
}
$channels = $pdo->query('SELECT channel, COUNT(*) AS n FROM scam_reports GROUP BY channel ORDER BY n DESC')->fetchAll();
$lost = $pdo->query('SELECT COUNT(*) AS reports, COALESCE(SUM(amount_lost), 0) AS total FROM scam_reports WHERE lost_money = 1')->fetch();

json_out([
    'success' => true,
    'data' => $rows,
    'counts' => $counts,
    'channels' => array_map(fn ($c) => ['channel' => $c['channel'], 'count' => (int) $c['n']], $channels),
    'money_lost' => ['reports' => (int) $lost['reports'], 'total' => (float) $lost['total']],
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
