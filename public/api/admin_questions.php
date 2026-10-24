<?php
// Ask an expert (admin)
//   GET  /api/admin_questions.php?status=pending|answered|all&page=1
//   POST { action: "answer", id, answer, is_public }   → saves and emails the learner
//        { action: "set_public", id, is_public }
//        { action: "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/questions.php';
require_once __DIR__ . '/../../src/mail.php';

require_method('GET', 'POST');
$admin = require_user('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $id = (int) ($in['id'] ?? 0);
    $action = str_field($in, 'action', 20);

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
        json_out(['success' => true, 'message' => 'Question deleted.']);
    }
    if ($action === 'set_public') {
        $stmt = $pdo->prepare("UPDATE questions SET is_public = ? WHERE id = ? AND status = 'answered'");
        $stmt->execute([empty($in['is_public']) ? 0 : 1, $id]);
        json_out(['success' => true, 'message' => empty($in['is_public']) ? 'Removed from the public FAQ.' : 'Added to the public FAQ.']);
    }
    if ($action !== 'answer') {
        fail('Unknown action.');
    }

    $answer = str_field($in, 'answer', 8000);
    if (mb_strlen($answer) < 10) {
        fail('Write an answer first.');
    }
    $stmt = $pdo->prepare('SELECT q.id, q.question, q.status, u.name, u.email FROM questions q JOIN users u ON u.id = q.user_id WHERE q.id = ?');
    $stmt->execute([$id]);
    $q = $stmt->fetch() ?: fail('Question not found.', 404);

    $pdo->prepare("UPDATE questions SET answer = ?, status = 'answered', is_public = ?, answered_by = ?, answered_at = UTC_TIMESTAMP() WHERE id = ?")
        ->execute([$answer, empty($in['is_public']) ? 0 : 1, $admin['id'], $id]);

    $link = frontend_url('account/questions');
    $first = explode(' ', $q['name'])[0];
    $html = '<p>Hi ' . htmlspecialchars($first, ENT_QUOTES) . ', our team has answered your question.</p>'
        . '<blockquote style="margin:0 0 14px;padding:10px 14px;border-left:3px solid #d7e9de;color:#5b6f69">'
        . nl2br(htmlspecialchars($q['question'], ENT_QUOTES)) . '</blockquote>'
        . text_to_html($answer) . mail_button($link, 'See it on MoneyMentor');
    send_mail($q['email'], 'Your question has been answered', mail_layout('Your question has an answer', $html),
        "Hi $first,\n\nYou asked:\n{$q['question']}\n\nOur answer:\n$answer\n\n$link");

    json_out(['success' => true, 'message' => $q['status'] === 'answered' ? 'Answer updated and emailed.' : 'Answer saved and emailed.']);
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$status = str_field($_GET, 'status', 10) ?: 'pending';
$where = in_array($status, ['pending', 'answered'], true) ? 'WHERE q.status = ' . $pdo->quote($status) : '';
$total = (int) $pdo->query("SELECT COUNT(*) FROM questions q $where")->fetchColumn();
$rows = $pdo->query("SELECT q.id, q.topic, q.question, q.answer, q.status, q.is_public, q.answered_at, q.created_at,
                            u.name AS user_name, u.email AS user_email
                     FROM questions q LEFT JOIN users u ON u.id = q.user_id $where
                     ORDER BY q.status = 'pending' DESC, q.created_at DESC, q.id DESC
                     LIMIT $limit OFFSET " . (($page - 1) * $limit))->fetchAll();

json_out([
    'success' => true,
    'data' => array_map('format_question', $rows),
    'mail_enabled' => mail_enabled(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE status = 'pending'")->fetchColumn(),
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
