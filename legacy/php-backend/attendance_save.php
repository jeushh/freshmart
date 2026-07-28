<?php
/**
 * =====================================================================================
 * backend/attendance_save.php — HR ATTENDANCE WRITE ADAPTER
 * =====================================================================================
 * POST-only, requires hr.attendance.edit. Upserts a single employee's attendance
 * record for a single day (unique on employee_id + log_date).
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';

requirePermission('hr.attendance.edit');
requireCsrfToken();

const VALID_STATUSES = ['Present', 'Late', 'Absent', 'On Leave'];

$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$logDate    = trim((string)($_POST['log_date'] ?? ''));
$timeIn     = trim((string)($_POST['time_in'] ?? ''));
$timeOut    = trim((string)($_POST['time_out'] ?? ''));
$status     = trim((string)($_POST['status'] ?? 'Present'));
$notes      = trim((string)($_POST['notes'] ?? ''));

if (!ctype_digit($employeeId) || (int)$employeeId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'employee_id must be a positive integer.']);
    exit;
}

$dateParts = explode('-', $logDate);
if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'log_date must be a valid date (YYYY-MM-DD).']);
    exit;
}

if (!in_array($status, VALID_STATUSES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'status must be one of: ' . implode(', ', VALID_STATUSES)]);
    exit;
}

if (in_array($status, ['Present', 'Late'], true) && $timeIn === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Time In is required when status is Present or Late.']);
    exit;
}
if ($timeIn !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeIn)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'time_in must use HH:MM format.']);
    exit;
}
if ($timeOut !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeOut)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'time_out must use HH:MM format.']);
    exit;
}

if ($timeIn !== '' && $timeOut !== '') {
    $inMinutes = ((int)substr($timeIn, 0, 2) * 60) + (int)substr($timeIn, 3, 2);
    $outMinutes = ((int)substr($timeOut, 0, 2) * 60) + (int)substr($timeOut, 3, 2);
    if ($outMinutes <= $inMinutes) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Time Out must be later than Time In for same-day attendance.']);
        exit;
    }
}

try {
    $employeeCheck = $pdo->prepare('SELECT id FROM employees WHERE id = :id');
    $employeeCheck->execute([':id' => (int)$employeeId]);
    if (!$employeeCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO attendance_logs (employee_id, log_date, time_in, time_out, status, notes)
         VALUES (:employee_id, :log_date, :time_in, :time_out, :status, :notes)
         ON CONFLICT(employee_id, log_date) DO UPDATE SET
            time_in = excluded.time_in,
            time_out = excluded.time_out,
            status = excluded.status,
            notes = excluded.notes"
    );
    $stmt->execute([
        ':employee_id' => (int)$employeeId,
        ':log_date'    => $logDate,
        ':time_in'     => $timeIn !== '' ? $timeIn : null,
        ':time_out'    => $timeOut !== '' ? $timeOut : null,
        ':status'      => $status,
        ':notes'       => $notes !== '' ? $notes : null,
    ]);

    logAudit($pdo, 'Attendance saved', 'attendance', $employeeId . ':' . $logDate, json_encode(['status'=>$status,'time_in'=>$timeIn,'time_out'=>$timeOut]));
    echo json_encode(['ok' => true, 'message' => "Attendance recorded for {$logDate}."]);
} catch (Throwable $e) {
    error_log('attendance_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save attendance record.']);
}
