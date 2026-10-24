<?php
// Contact messages (admin)
//   GET  /api/admin_messages.php?page=1&filter=unread
//   POST /api/admin_messages.php  { action: "mark_read" | "mark_unread" | "delete", id }
//                                 { action: "reply", id, reply }  → emails the sender
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('GET', 'POST');
require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $id = (int) ($in['id'] ?? 0);
    $action = str_field($in, 'action', 20);

    if ($action === 'reply') {
        $reply = str_field($in, 'reply', 5000);
        if (mb_strlen($reply) < 2) {
            fail('Write a reply first.');
        }
        $stmt = $pdo->prepare('SELECT name, email, subject, message FROM contact_messages WHERE id = ?');
        $stmt->execute([$id]);
        $msg = $stmt->fetch() ?: fail('Message not found.', 404);

        $subject = 'Re: ' . ($msg['subject'] !== '' ? $msg['subject'] : 'your message to MoneyMentor');
        $first = explode(' ', $msg['name'])[0];
        $quoted = '<blockquote style="margin:16px 0 0;padding:10px 14px;border-left:3px solid #d7e9de;color:#5b6f69">'
            . nl2br(htmlspecialchars(mb_substr($msg['message'], 0, 1500), ENT_QUOTES)) . '</blockquote>';
        $html = mail_layout('Reply from MoneyMentor', '<p>Hi ' . htmlspecialchars($first, ENT_QUOTES) . ',</p>' . text_to_html($reply)
            . '<p style="margin-top:18px;color:#5b6f69;font-size:13px">You wrote:</p>' . $quoted);
        if (!send_mail($msg['email'], $subject, $html, "Hi $first,\n\n$reply\n\n---\nYou wrote:\n{$msg['message']}")) {
            fail('The email could not be sent. Check the mail settings and try again.', 502);
        }
        $pdo->prepare('UPDATE contact_messages SET reply = ?, replied_at = UTC_TIMESTAMP(), is_read = 1 WHERE id = ?')->execute([$reply, $id]);
        json_out(['success' => true, 'message' => mail_enabled() ? "Reply sent to {$msg['email']}." : 'Reply saved (email is in test mode, so nothing was sent).']);
    }

    $sql = [
        'mark_read' => 'UPDATE contact_messages SET is_read = 1 WHERE id = ?',
        'mark_unread' => 'UPDATE contact_messages SET is_read = 0 WHERE id = ?',
        'delete' => 'DELETE FROM contact_messages WHERE id = ?',
    ][$action] ?? fail('Unknown action.');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    json_out(['success' => true, 'message' => $action === 'delete' ? 'Message deleted.' : 'Message updated.']);
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$where = str_field($_GET, 'filter', 10) === 'unread' ? 'WHERE is_read = 0' : '';
$total = (int) $pdo->query("SELECT COUNT(*) FROM contact_messages $where")->fetchColumn();
$rows = $pdo->query("SELECT id, name, email, subject, message, is_read, reply, replied_at, created_at FROM contact_messages $where
                     ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit))->fetchAll();
$rows = array_map(fn ($r) => ['id' => (int) $r['id'], 'is_read' => (bool) $r['is_read']] + $r, $rows);

json_out([
    'success' => true,
    'data' => $rows,
    'mail_enabled' => mail_enabled(),
    'unread' => (int) $pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn(),
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
