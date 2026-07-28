<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requirePermission('system.users.manage');

try {
    $search = trim((string)($_GET['search'] ?? ''));
    $roleId = isset($_GET['role_id']) && $_GET['role_id'] !== '' ? (int)$_GET['role_id'] : null;
    $status = trim((string)($_GET['status'] ?? ''));
    $where = []; $params = [];
    if ($search !== '') { $where[] = '(u.username LIKE :search OR u.full_name LIKE :search)'; $params[':search'] = '%' . $search . '%'; }
    if ($roleId !== null && $roleId > 0) { $where[] = 'u.role_id = :role'; $params[':role'] = $roleId; }
    if (in_array($status, ['Active', 'Disabled'], true)) { $where[] = 'u.status = :status'; $params[':status'] = $status; }
    $sql = 'SELECT u.id, u.username, u.full_name, u.role_id, u.status, u.created_at, u.last_login, r.name AS role_name
            FROM admin_users u JOIN roles r ON r.id = u.role_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY u.full_name, u.username';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $roles = $pdo->query('SELECT id, name FROM roles ORDER BY name')->fetchAll();
    echo json_encode(['ok' => true, 'users' => $stmt->fetchAll(), 'roles' => $roles]);
} catch (Throwable $e) {
    error_log('users.php: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to load users.']);
}
