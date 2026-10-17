<?php
// GET /api/health.php — used by Render's health check
declare(strict_types=1);
require_once __DIR__ . '/../../src/bootstrap.php';

db()->query('SELECT 1');
json_out(['success' => true, 'status' => 'ok']);
