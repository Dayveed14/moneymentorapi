<?php
// GET /api/me.php  (Authorization: Bearer <token>)  →  the logged-in user
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

require_method('GET');
json_out(['success' => true, 'user' => public_user(require_user())]);
