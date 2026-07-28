<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Full HR staff (hr.requests.view) can list/filter across everyone. An
// employee.self account can only ever see their own requests — the
// employee_id filter is forced server-side, never trusting the query param.
requireAnyPermission(['hr.requests.view', 'employee.self']);
$hasFullView = in_array('hr.requests.view', $_SESSION['admin_permissions'] ?? [], true);

try {
    $where  = [];
    $params = [];

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        $where[]           = 'r.status = :status';
        $params[':status'] = $status;
    }

    if ($hasFullView) {
        $empId = trim((string)($_GET['employee_id'] ?? ''));
        if ($empId !== '') {
            $where[]           = 'r.employee_id = :emp_id';
            $params[':emp_id'] = (int)$empId;
        }
    } else {
        $ownId = getSessionEmployeeId();
        if ($ownId === null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'This account is not linked to an employee record.']);
            exit;
        }
        $where[]           = 'r.employee_id = :emp_id';
        $params[':emp_id'] = $ownId;
    }

    $sql = "SELECT r.*,
                   e.full_name   AS employee_name,
                   e.employee_no AS employee_no,
                   a.full_name   AS reviewer_name
            FROM hr_requests r
            LEFT JOIN employees   e ON e.id = r.employee_id
            LEFT JOIN admin_users a ON a.id = r.reviewed_by"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY r.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true, 'requests' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('hr_requests.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load HR requests.']);
}
