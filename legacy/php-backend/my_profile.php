<?php
/**
 * =====================================================================================
 * backend/my_profile.php — SELF-SERVICE PROFILE READ ADAPTER
 * =====================================================================================
 * GET-only. Returns the employee record linked to the CURRENT session only —
 * there's no employee_id parameter to accept, so there's nothing to spoof.
 * Any authenticated session whose admin_users row has an employee_id can use
 * this; it isn't gated on a specific permission because it can't leak anyone
 * else's data by construction.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAdminAuth();

$employeeId = getSessionEmployeeId();
if ($employeeId === null) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'This account is not linked to an employee record.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, employee_no, full_name, position, department, email, phone, hire_date, employment_status, leave_balance
         FROM employees WHERE id = :id'
    );
    $stmt->execute([':id' => $employeeId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Linked employee record not found.']);
        exit;
    }

    $row['id']            = (int)$row['id'];
    $row['leave_balance'] = (float)$row['leave_balance'];

    echo json_encode(['ok' => true, 'employee' => $row]);
} catch (Throwable $e) {
    error_log('my_profile.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load profile.']);
}
