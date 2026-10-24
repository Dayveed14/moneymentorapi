<?php
// Admin user management
//   GET  /api/admin_users.php?page=1&search=ada
//   POST /api/admin_users.php  { action: "create", name, email, password, role }
//                              { action: "set_role", id, role }
//                              { action: "reset_password", id, password }
//                              { action: "delete", id }
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET', 'POST');
$admin = require_user('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = input_json();
    $action = str_field($in, 'action', 30);
    $pdo = db();

    $countAdmins = fn () => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $findUser = function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: fail('User not found.', 404);
    };
    $checkPassword = function (string $pw): void {
        if (strlen($pw) < 8 || strlen($pw) > 72) {
            fail('Passwords need 8 to 72 characters.');
        }
    };

    if ($action === 'create') {
        $name = str_field($in, 'name', 120);
        $email = strtolower(str_field($in, 'email', 190));
        $password = (string) ($in['password'] ?? '');
        $role = str_field($in, 'role', 10) ?: 'user';
        if (mb_strlen($name) < 2) {
            fail('Enter the person\'s full name.');
        }
        if (!valid_email($email)) {
            fail('Enter a valid email address.');
        }
        $checkPassword($password);
        if (!in_array($role, ['user', 'admin'], true)) {
            fail('Role must be user or admin.');
        }
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            fail('An account with that email already exists.', 409);
        }
        $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        json_out(['success' => true, 'message' => "Account created for $name.", 'id' => (int) $pdo->lastInsertId()], 201);
    }

    $id = (int) ($in['id'] ?? 0);
    $target = $findUser($id);

    if ($action === 'set_role') {
        $role = str_field($in, 'role', 10);
        if (!in_array($role, ['user', 'admin'], true)) {
            fail('Role must be user or admin.');
        }
        if ($target['role'] === 'admin' && $role === 'user' && $countAdmins() <= 1) {
            fail('You can\'t remove the last admin.');
        }
        if ((int) $target['id'] === (int) $admin['id'] && $role !== 'admin') {
            fail('You can\'t remove your own admin access.');
        }
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
        json_out(['success' => true, 'message' => $role === 'admin' ? "{$target['name']} is now an admin." : "{$target['name']} is now a regular user."]);
    }

    if ($action === 'reset_password') {
        $password = (string) ($in['password'] ?? '');
        $checkPassword($password);
        $pdo->prepare('UPDATE users SET password = ?, password_changed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        json_out(['success' => true, 'message' => "Password reset for {$target['name']}. Share it with them securely."]);
    }

    if ($action === 'delete') {
        if ((int) $target['id'] === (int) $admin['id']) {
            fail('You can\'t delete your own account here.');
        }
        if ($target['role'] === 'admin' && $countAdmins() <= 1) {
            fail('You can\'t delete the last admin.');
        }
        foreach (['lesson_progress', 'quiz_attempts', 'budgets', 'saved_articles', 'assessments', 'expenses', 'questions', 'password_resets'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE user_id = ?")->execute([$id]);
        }
        $pdo->prepare('UPDATE articles SET author_id = NULL WHERE author_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE scam_reports SET user_id = NULL WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        json_out(['success' => true, 'message' => "{$target['name']}'s account was deleted."]);
    }

    fail('Unknown action.');
}

$page = int_query('page', 1, 1, 10000);
$limit = int_query('limit', 20, 1, 50);
$search = str_field($_GET, 'search', 100);

$where = '';
$params = [];
if ($search !== '') {
    $like = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
    $where = 'WHERE LOWER(u.name) LIKE ? OR LOWER(u.email) LIKE ?';
    $params = [$like, $like];
}

$count = db()->prepare("SELECT COUNT(*) FROM users u $where");
$count->execute($params);
$total = (int) $count->fetchColumn();

$stmt = db()->prepare("
    SELECT u.id, u.name, u.email, u.role, u.created_at, u.last_login,
           (SELECT COUNT(*) FROM lesson_progress lp WHERE lp.user_id = u.id) AS lessons_done,
           (SELECT a.level FROM assessments a WHERE a.user_id = u.id ORDER BY a.created_at DESC, a.id DESC LIMIT 1) AS level
    FROM users u $where
    ORDER BY u.created_at DESC, u.id DESC
    LIMIT $limit OFFSET " . (($page - 1) * $limit)
);
$stmt->execute($params);
$users = array_map(fn ($u) => ['id' => (int) $u['id'], 'lessons_done' => (int) $u['lessons_done']] + $u, $stmt->fetchAll());

json_out([
    'success' => true,
    'data' => $users,
    'pagination' => ['current_page' => $page, 'total_pages' => max(1, (int) ceil($total / $limit)), 'total' => $total],
]);
