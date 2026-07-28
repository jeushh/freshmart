<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Only POST is supported.']); exit; }
require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/db.php'; require_once __DIR__ . '/inventory_finance_shared.php';
requirePermission('system.users.manage');
requireCsrfToken();
try {
    $data = json_decode(file_get_contents('php://input'), true); if (!is_array($data)) $data = $_POST;
    $id = (int)($data['id'] ?? 0); $password = (string)($data['password'] ?? '');
    if ($id <= 0 || strlen($password) < 6) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Provide a user and a password of at least 6 characters.']); exit; }
    $lookup = $pdo->prepare('SELECT username FROM admin_users WHERE id = ?'); $lookup->execute([$id]); $username = $lookup->fetchColumn();
    if ($username === false) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'User not found.']); exit; }
    $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
    logAudit($pdo, 'User password reset', 'user', (string)$id, (string)$username);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) { error_log('user_reset_password.php: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to reset password.']); }
