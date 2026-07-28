<?php
/**
 * =====================================================================================
 * backend/attendance.php — HR ATTENDANCE READ ADAPTER
 * =====================================================================================
 * GET-only. hr.attendance.view sees everyone, filterable by employee_id and/or a
 * date_from/date_to range. An employee.self account only ever sees their own —
 * enforced server-side. Defaults to the current week (Mon-Sun) if no dates given.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAnyPermission(['hr.attendance.view', 'employee.self']);
$hasFullView = in_array('hr.attendance.view', $_SESSION['admin_permissions'] ?? [], true);

try {
    $where  = [];
    $params = [];

    if ($hasFullView) {
        $empId = trim((string)($_GET['employee_id'] ?? ''));
        if ($empId !== '') {
            $where[]           = 'a.employee_id = :emp_id';
            $params[':emp_id'] = (int)$empId;
        }
    } else {
        $ownId = getSessionEmployeeId();
        if ($ownId === null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'This account is not linked to an employee record.']);
            exit;
        }
        $where[]           = 'a.employee_id = :emp_id';
        $params[':emp_id'] = $ownId;
    }

    $dateFrom = trim((string)($_GET['date_from'] ?? ''));
    $dateTo   = trim((string)($_GET['date_to'] ?? ''));

    // Default window: current week (Monday through Sunday) if nothing supplied.
    // Computed via DateTime::format('N') (1=Monday..7=Sunday) rather than
    // strtotime('monday this week'), which has known edge-case behavior right
    // at week boundaries depending on PHP version/locale — this is unambiguous.
    if ($dateFrom === '' && $dateTo === '') {
        $today       = new DateTime('today');
        $isoWeekday  = (int) $today->format('N');
        $dateFrom    = (clone $today)->modify('-' . ($isoWeekday - 1) . ' days')->format('Y-m-d');
        $dateTo      = (clone $today)->modify('+' . (7 - $isoWeekday) . ' days')->format('Y-m-d');
    }

    if ($dateFrom !== '') {
        $where[]             = 'a.log_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]           = 'a.log_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $sql = "SELECT a.id, a.employee_id, a.log_date, a.time_in, a.time_out, a.status, a.notes,
                   e.full_name AS employee_name, e.employee_no AS employee_no
            FROM attendance_logs a
            LEFT JOIN employees e ON e.id = a.employee_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY a.log_date DESC, e.full_name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok'         => true,
        'attendance' => $stmt->fetchAll(),
        'date_from'  => $dateFrom,
        'date_to'    => $dateTo,
    ]);
} catch (Throwable $e) {
    error_log('attendance.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load attendance records.']);
}
