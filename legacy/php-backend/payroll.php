<?php
declare(strict_types=1);
/**
 * backend/payroll.php — PAYROLL READ ADAPTER (GET-only, requires payroll.manage)
 * Optional ?employee_id= or ?status= filter.
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
requirePermission('payroll.manage');

try {
    $employeeId = isset($_GET['employee_id']) && $_GET['employee_id'] !== '' ? (int) $_GET['employee_id'] : null;
    $status     = trim((string) ($_GET['status'] ?? ''));

    $sql = "SELECT pr.*, e.full_name, e.employee_no, e.department, e.position
            FROM payroll pr JOIN employees e ON e.id = pr.employee_id";
    $where = [];
    $params = [];
    if ($employeeId !== null) { $where[] = 'pr.employee_id = :eid'; $params[':eid'] = $employeeId; }
    if ($status !== '')       { $where[] = 'pr.status = :status';    $params[':status'] = $status; }
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY pr.pay_period_start DESC, pr.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $numeric = ['basic_salary','hourly_rate','regular_hours','overtime_hours','overtime_pay','allowances','bonuses','deductions','net_pay'];
    $rows = array_map(static function (array $r) use ($numeric): array {
        $r['id'] = (int) $r['id'];
        $r['employee_id'] = (int) $r['employee_id'];
        foreach ($numeric as $k) { $r[$k] = (float) $r[$k]; }
        return $r;
    }, $stmt->fetchAll());

    echo json_encode(['ok' => true, 'payroll' => $rows]);
} catch (Throwable $e) {
    error_log('payroll.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load payroll.']);
}
