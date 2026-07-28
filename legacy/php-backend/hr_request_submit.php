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

// Full HR staff can file on behalf of anyone; a self-service employee.self
// account can only ever file for themselves (resolveEmployeeScope() enforces
// this server-side — it ignores whatever employee_id the client sent if the
// session only has employee.self).
requireAnyPermission(['hr.employees.view', 'employee.self']);
requireCsrfToken();

function validDate(string $d): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $requestedEmployeeId = isset($post['employee_id']) && $post['employee_id'] !== '' ? (int)$post['employee_id'] : null;
    $employee_id  = resolveEmployeeScope(['hr.employees.view'], $requestedEmployeeId);
    $request_type = trim((string)($post['request_type'] ?? ''));
    $reason       = trim((string)($post['reason']       ?? ''));
    $start_date   = trim((string)($post['start_date']   ?? ''));
    $end_date     = trim((string)($post['end_date']     ?? ''));
    $hours        = isset($post['hours']) && $post['hours'] !== '' ? (float)$post['hours'] : null;

    if (!in_array($request_type, ['Leave', 'Overtime', 'Other'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'request_type must be Leave, Overtime, or Other.']);
        exit;
    }
    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'reason is required.']);
        exit;
    }

    $leaveDays = null;
    if ($request_type === 'Leave') {
        if (!validDate($start_date) || !validDate($end_date)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Leave requests require valid start_date and end_date (Y-m-d).']);
            exit;
        }
        if ($start_date > $end_date) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'start_date must not be after end_date.']);
            exit;
        }
        $leaveDays = (new DateTime($start_date))->diff(new DateTime($end_date))->days + 1;
    } elseif ($request_type === 'Overtime') {
        if ($hours === null || $hours <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Overtime requests require hours > 0.']);
            exit;
        }
    }

    // Verify employee exists, and for Leave requests, sanity-check the balance
    // now (it's re-validated again at approval time in hr_request_review.php,
    // since other approvals could eat into it between submission and review).
    $emp = $pdo->prepare('SELECT id, leave_balance FROM employees WHERE id = :id');
    $emp->execute([':id' => $employee_id]);
    $employeeRow = $emp->fetch();
    if (!$employeeRow) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
        exit;
    }
    if ($leaveDays !== null && (float)$employeeRow['leave_balance'] < $leaveDays) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Insufficient leave balance: {$employeeRow['leave_balance']} day(s) available, {$leaveDays} requested."]);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO hr_requests (employee_id, request_type, start_date, end_date, hours, reason)
         VALUES (:emp, :type, :sd, :ed, :hrs, :reason)"
    )->execute([
        ':emp'    => $employee_id,
        ':type'   => $request_type,
        ':sd'     => $start_date  !== '' ? $start_date  : null,
        ':ed'     => $end_date    !== '' ? $end_date    : null,
        ':hrs'    => $hours,
        ':reason' => $reason,
    ]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('hr_request_submit.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to submit HR request.']);
}
