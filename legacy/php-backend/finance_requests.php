<?php
/**
 * =====================================================================================
 * backend/finance_requests.php — FINANCE REQUESTS READ ADAPTER
 * =====================================================================================
 * GET-only. finance.requests.view sees everyone's requests, filterable by
 * ?status=/?request_type=. An employee.self account only ever sees their own —
 * enforced server-side, not by trusting a client-supplied employee_id.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAnyPermission(['finance.requests.view', 'employee.self']);
$hasFullView = in_array('finance.requests.view', $_SESSION['admin_permissions'] ?? [], true);

try {
    $where  = [];
    $params = [];

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        $where[]           = 'f.status = :status';
        $params[':status'] = $status;
    }

    $requestType = trim((string)($_GET['request_type'] ?? ''));
    if ($requestType !== '') {
        $where[]        = 'f.request_type = :type';
        $params[':type'] = $requestType;
    }

    if ($hasFullView) {
        $empId = trim((string)($_GET['employee_id'] ?? ''));
        if ($empId !== '') {
            $where[]           = 'f.employee_id = :emp_id';
            $params[':emp_id'] = (int)$empId;
        }
    } else {
        $ownId = getSessionEmployeeId();
        if ($ownId === null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'This account is not linked to an employee record.']);
            exit;
        }
        $where[]           = 'f.employee_id = :emp_id';
        $params[':emp_id'] = $ownId;
    }

    $sql = "SELECT f.*,
                   e.full_name   AS employee_name,
                   e.employee_no AS employee_no,
                   a.full_name   AS reviewer_name
            FROM finance_requests f
            LEFT JOIN employees   e ON e.id = f.employee_id
            LEFT JOIN admin_users a ON a.id = f.reviewed_by"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY f.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true, 'requests' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('finance_requests.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load finance requests.']);
}
