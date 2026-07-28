<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';

requirePermission('system.roles.manage');
requireCsrfToken();

const PERMISSION_CATALOG = [
    'system.roles.manage',
    'system.users.manage',
    'system.audit.view',
    'system.settings.manage',
    'hr.employees.view', 'hr.employees.edit',
    'hr.attendance.view', 'hr.attendance.edit',
    'hr.requests.view', 'hr.requests.approve',
    'finance.requests.view', 'finance.requests.approve',
    'inventory.manage',
    'sales.view',
    'pos.access',
    'pos.refund',
    'employee.self',
    'restock.request', 'restock.approve',
    'finance.manage',
    'payroll.manage',
];

const VALID_LANDING_PAGES = ['pos', 'inventory', 'hr', 'finance', 'admin', 'employee'];

try {
    $raw = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $name         = trim((string)($post['name'] ?? ''));
    $description  = trim((string)($post['description'] ?? ''));
    $permsInput   = $post['permissions'] ?? [];
    if (!is_array($permsInput)) $permsInput = [];
    $landingPage  = trim((string)($post['landing_page'] ?? 'pos'));

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Role name is required.']);
        exit;
    }

    $unknown = array_diff($permsInput, PERMISSION_CATALOG);
    if (!empty($unknown)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown permission keys: ' . implode(', ', $unknown)]);
        exit;
    }

    if (!in_array($landingPage, VALID_LANDING_PAGES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'landing_page must be one of: ' . implode(', ', VALID_LANDING_PAGES)]);
        exit;
    }

    $permsJson = json_encode(array_values($permsInput));
    $id = isset($post['id']) && $post['id'] !== '' ? (int)$post['id'] : null;

    if ($id !== null) {
        $pdo->prepare("UPDATE roles SET name=:name, description=:desc, permissions=:perms, landing_page=:landing WHERE id=:id")
            ->execute([':name' => $name, ':desc' => $description, ':perms' => $permsJson, ':landing' => $landingPage, ':id' => $id]);
        logAudit($pdo, 'Role updated', 'role', (string)$id, $name);
        echo json_encode(['ok' => true, 'id' => $id]);
    } else {
        $pdo->prepare("INSERT INTO roles (name, description, permissions, landing_page) VALUES (:name, :desc, :perms, :landing)")
            ->execute([':name' => $name, ':desc' => $description, ':perms' => $permsJson, ':landing' => $landingPage]);
        $newId = (int)$pdo->lastInsertId();
        logAudit($pdo, 'Role created', 'role', (string)$newId, $name);
        echo json_encode(['ok' => true, 'id' => $newId]);
    }
} catch (Throwable $e) {
    error_log('role_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save role.']);
}
