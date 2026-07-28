<?php
declare(strict_types=1);
/**
 * backend/audit_logs.php — AUDIT LOG READ ADAPTER (GET-only, requires system.audit.view)
 * Restricted to System Administrators since the audit trail can reveal sensitive
 * cross-module activity (approvals, rejections, payments, role changes).
 * Optional ?entity_type= or ?action= (LIKE match) filter.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requirePermission('system.audit.view');

try {
    $entityType = trim((string) ($_GET['entity_type'] ?? ''));
    $action     = trim((string) ($_GET['action'] ?? ''));
    $username   = trim((string) ($_GET['username'] ?? ''));
    $dateFrom   = trim((string) ($_GET['date_from'] ?? ''));
    $dateTo     = trim((string) ($_GET['date_to'] ?? ''));
    $search     = trim((string) ($_GET['search'] ?? ''));

    $sql = 'SELECT a.*, r.name AS role_name FROM audit_logs a
            LEFT JOIN admin_users u ON u.username = a.username
            LEFT JOIN roles r ON r.id = u.role_id';
    $where = [];
    $params = [];
    if ($entityType !== '') { $where[] = 'a.entity_type = :et'; $params[':et'] = $entityType; }
    if ($action !== '')     { $where[] = 'a.action LIKE :a';    $params[':a'] = '%' . $action . '%'; }
    if ($username !== '')   { $where[] = 'a.username = :u';     $params[':u'] = $username; }
    if ($dateFrom !== '')   { $where[] = 'a.created_at >= :df'; $params[':df'] = $dateFrom . ' 00:00:00'; }
    if ($dateTo !== '')     { $where[] = 'a.created_at <= :dt'; $params[':dt'] = $dateTo . ' 23:59:59'; }
    if ($search !== '') {
        $where[] = '(a.action LIKE :search OR a.entity_type LIKE :search OR a.entity_id LIKE :search OR a.details LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY a.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map(static function (array $r): array {
        $r['id'] = (int) $r['id'];
        return $r;
    }, $stmt->fetchAll());

    echo json_encode(['ok' => true, 'logs' => $rows]);
} catch (Throwable $e) {
    error_log('audit_logs.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load audit logs.']);
}
