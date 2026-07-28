<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']); exit; }
require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/db.php';
requireAnyPermission(['system.users.manage', 'system.audit.view', 'system.settings.manage', 'system.roles.manage']);
try {
    $summary = [
        'total_users' => (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn(),
        'active_users' => (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE status = 'Active'")->fetchColumn(),
        'inactive_users' => (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE status = 'Disabled'")->fetchColumn(),
        'total_roles' => (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn(),
    ];
    $recent = $pdo->query('SELECT id, username, action, entity_type, entity_id, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 8')->fetchAll();
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('business_name', 'system_name')")->fetchAll();
    $branding = []; foreach ($settings as $row) $branding[$row['setting_key']] = $row['setting_value'];
    echo json_encode(['ok' => true, 'summary' => $summary, 'recent_activity' => $recent, 'branding' => $branding, 'status' => [
        'database' => true, 'authentication' => true, 'authorization' => true, 'audit_logging' => true,
    ]]);
} catch (Throwable $e) { error_log('admin_dashboard.php: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to load dashboard.']); }
