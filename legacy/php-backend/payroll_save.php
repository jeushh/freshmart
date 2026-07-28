<?php
declare(strict_types=1);
/**
 * backend/payroll_save.php — PAYROLL CREATE/UPDATE ADAPTER (POST-only, requires payroll.manage)
 * Creates a new Draft payroll row, or updates an existing one while it's still Draft
 * (once submitted for approval it's locked — resubmit as a fresh entry instead).
 * overtime_pay defaults to overtime_hours * hourly_rate * 1.25 if not explicitly given.
 * net_pay is always computed server-side so it can never drift from its inputs.
 */
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
require_once __DIR__ . '/inventory_finance_shared.php';
requirePermission('payroll.manage');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $id             = isset($post['id']) && $post['id'] !== '' ? (int) $post['id'] : null;
    $employeeId     = isset($post['employee_id']) ? (int) $post['employee_id'] : 0;
    $periodStart    = trim((string) ($post['pay_period_start'] ?? ''));
    $periodEnd      = trim((string) ($post['pay_period_end'] ?? ''));
    $payFrequency   = trim((string) ($post['pay_frequency'] ?? 'Semi-monthly'));
    $basicSalary    = (float) ($post['basic_salary'] ?? 0);
    $hourlyRate     = (float) ($post['hourly_rate'] ?? 0);
    $regularHours   = (float) ($post['regular_hours'] ?? 0);
    $overtimeHours  = (float) ($post['overtime_hours'] ?? 0);
    $allowances     = (float) ($post['allowances'] ?? 0);
    $bonuses        = (float) ($post['bonuses'] ?? 0);
    $deductions     = (float) ($post['deductions'] ?? 0);
    $overtimePayIn  = isset($post['overtime_pay']) && $post['overtime_pay'] !== '' ? (float) $post['overtime_pay'] : null;

    if ($employeeId <= 0 || $periodStart === '' || $periodEnd === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'employee_id, pay_period_start, and pay_period_end are required.']);
        exit;
    }
    if (!in_array($payFrequency, ['Weekly','Biweekly','Semi-monthly','Monthly'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid payroll frequency.']);
        exit;
    }
    if ($regularHours < 0 || $overtimeHours < 0 || $allowances < 0 || $bonuses < 0 || $deductions < 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Hours and payroll amounts cannot be negative.']);
        exit;
    }
    if ($periodEnd < $periodStart) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'pay_period_end cannot be before pay_period_start.']);
        exit;
    }

    $empStmt = $pdo->prepare('SELECT id, pay_type, basic_salary, hourly_rate FROM employees WHERE id = :id');
    $empStmt->execute([':id' => $employeeId]);
    $employee = $empStmt->fetch();
    if (!$employee) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
        exit;
    }

    // Compensation comes from the employee profile, not editable client input.
    $payType = (string)($employee['pay_type'] ?? 'Monthly');
    $basicSalary = (float)$employee['basic_salary'];
    $hourlyRate = (float)$employee['hourly_rate'];

    if ($payType === 'Monthly' && $basicSalary <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This employee has no Basic Monthly Salary. Update the Employee profile before creating payroll.']);
        exit;
    }
    if ($payType === 'Hourly' && $hourlyRate <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'This employee has no Hourly Rate. Update the Employee profile before creating payroll.']);
        exit;
    }
    if ($overtimeHours > 0 && $hourlyRate <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Overtime hours require a saved hourly/overtime rate in the Employee profile.']);
        exit;
    }
    $frequencyFactor = match ($payFrequency) {
        'Weekly' => 12 / 52,
        'Biweekly' => 12 / 26,
        'Semi-monthly' => 0.5,
        default => 1.0,
    };
    $basePay = $payType === 'Hourly'
        ? round($regularHours * $hourlyRate, 2)
        : round($basicSalary * $frequencyFactor, 2);
    $overtimePay = $overtimePayIn ?? round($overtimeHours * $hourlyRate * 1.25, 2);
    $netPay = round($basePay + $overtimePay + $allowances + $bonuses - $deductions, 2);
    if ($netPay <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Net pay must be greater than zero. Review hours, rates, and deductions.']);
        exit;
    }

    $createdBy = $_SESSION['admin_username'] ?? 'unknown';

    if ($id !== null) {
        $existStmt = $pdo->prepare('SELECT status FROM payroll WHERE id = :id');
        $existStmt->execute([':id' => $id]);
        $status = $existStmt->fetchColumn();
        if ($status === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Payroll record not found.']);
            exit;
        }
        if ($status !== 'Draft') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "Only a Draft payroll record can be edited (current status: {$status})."]);
            exit;
        }
        $pdo->prepare(
            'UPDATE payroll SET employee_id=:eid, pay_period_start=:ps, pay_period_end=:pe, pay_frequency=:pf, basic_salary=:bs,
                    hourly_rate=:hr, regular_hours=:rh, overtime_hours=:oh, overtime_pay=:op,
                    allowances=:al, bonuses=:bo, deductions=:de, net_pay=:net
             WHERE id=:id'
        )->execute([
            ':eid' => $employeeId, ':ps' => $periodStart, ':pe' => $periodEnd, ':pf' => $payFrequency, ':bs' => $basicSalary,
            ':hr' => $hourlyRate, ':rh' => $regularHours, ':oh' => $overtimeHours, ':op' => $overtimePay,
            ':al' => $allowances, ':bo' => $bonuses, ':de' => $deductions, ':net' => $netPay, ':id' => $id,
        ]);
        logAudit($pdo, 'Payroll draft updated', 'payroll', (string) $id, '₱' . number_format($netPay, 2));
        echo json_encode(['ok' => true, 'id' => $id, 'net_pay' => $netPay]);
    } else {
        $pdo->prepare(
            "INSERT INTO payroll (employee_id, pay_period_start, pay_period_end, pay_frequency, basic_salary, hourly_rate,
                                   regular_hours, overtime_hours, overtime_pay, allowances, bonuses, deductions,
                                   net_pay, status, created_by)
             VALUES (:eid, :ps, :pe, :pf, :bs, :hr, :rh, :oh, :op, :al, :bo, :de, :net, 'Draft', :by)"
        )->execute([
            ':eid' => $employeeId, ':ps' => $periodStart, ':pe' => $periodEnd, ':pf' => $payFrequency, ':bs' => $basicSalary,
            ':hr' => $hourlyRate, ':rh' => $regularHours, ':oh' => $overtimeHours, ':op' => $overtimePay,
            ':al' => $allowances, ':bo' => $bonuses, ':de' => $deductions, ':net' => $netPay, ':by' => $createdBy,
        ]);
        $newId = (int) $pdo->lastInsertId();
        logAudit($pdo, 'Payroll draft created', 'payroll', (string) $newId, '₱' . number_format($netPay, 2));
        echo json_encode(['ok' => true, 'id' => $newId, 'net_pay' => $netPay]);
    }
} catch (Throwable $e) {
    error_log('payroll_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save payroll record.']);
}
