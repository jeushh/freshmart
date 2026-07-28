<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';
requireAdminAuth();
requireCsrfToken();

if (isAdminAuthenticated()) {
    logAudit($pdo, 'User logout', 'user', (string)$_SESSION['admin_user_id'], $_SESSION['admin_username'] ?? null);
}
session_unset();
session_destroy();

echo json_encode(['ok' => true]);
