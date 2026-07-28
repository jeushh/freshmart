<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requirePermission('system.roles.manage');

try {
    $rows = $pdo->query("SELECT id, name, description, permissions, landing_page FROM roles ORDER BY id")->fetchAll();
    $roles = array_map(function (array $r): array {
        $r['permissions'] = json_decode((string)$r['permissions'], true) ?? [];
        return $r;
    }, $rows);
    echo json_encode(['ok' => true, 'roles' => $roles]);
} catch (Throwable $e) {
    error_log('roles.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load roles.']);
}
