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
    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;
    $username = trim((string)($data['username'] ?? '')); $fullName = trim((string)($data['full_name'] ?? ''));
    $roleId = (int)($data['role_id'] ?? 0); $status = trim((string)($data['status'] ?? 'Active'));
    $password = (string)($data['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || $fullName === '' || $roleId <= 0 || !in_array($status, ['Active', 'Disabled'], true)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Provide a valid username, name, role, and status.']); exit;
    }
    $roleCheck = $pdo->prepare('SELECT name FROM roles WHERE id = ?'); $roleCheck->execute([$roleId]);
    if ($roleCheck->fetchColumn() === false) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Selected role does not exist.']); exit; }
    if ($id === null && strlen($password) < 6) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'New users require a password of at least 6 characters.']); exit; }
    if ($id !== null && $id === (int)$_SESSION['admin_user_id'] && $status !== 'Active') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'You cannot deactivate your own account.']); exit; }
    if ($id === null) {
        $pdo->prepare('INSERT INTO admin_users (username, password_hash, full_name, role_id, status) VALUES (:username, :hash, :name, :role, :status)')
            ->execute([':username' => $username, ':hash' => password_hash($password, PASSWORD_DEFAULT), ':name' => $fullName, ':role' => $roleId, ':status' => $status]);
        $id = (int)$pdo->lastInsertId(); logAudit($pdo, 'User created', 'user', (string)$id, $username);
    } else {
        $existing = $pdo->prepare('SELECT username, status FROM admin_users WHERE id = ?');
        $existing->execute([$id]); $previous = $existing->fetch();
        if (!$previous) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'User not found.']); exit; }
        $stmt = $pdo->prepare('UPDATE admin_users SET username=:username, full_name=:name, role_id=:role, status=:status WHERE id=:id');
        $stmt->execute([':username' => $username, ':name' => $fullName, ':role' => $roleId, ':status' => $status, ':id' => $id]);
        $action = $previous['status'] !== $status
            ? ($status === 'Disabled' ? 'User deactivated' : 'User activated')
            : 'User updated';
        logAudit($pdo, $action, 'user', (string)$id, $username);
    }
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (PDOException $e) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Username is already in use or the user could not be saved.']);
} catch (Throwable $e) {
    error_log('user_save.php: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to save user.']);
}
