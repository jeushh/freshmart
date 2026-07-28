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
require_once __DIR__ . '/inventory_finance_shared.php';

requirePermission('hr.employees.edit');
requireCsrfToken();

const VALID_STATUSES = ['Active', 'On Leave', 'Terminated'];

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $id         = isset($post['id']) && $post['id'] !== '' ? (int)$post['id'] : null;
    $full_name  = trim((string)($post['full_name']  ?? ''));
    $position   = trim((string)($post['position']   ?? ''));
    $department = trim((string)($post['department'] ?? ''));
    $hire_date  = trim((string)($post['hire_date']  ?? ''));
    $email      = trim((string)($post['email']      ?? '')) ?: null;
    $phone      = trim((string)($post['phone']      ?? '')) ?: null;
    $status     = trim((string)($post['employment_status'] ?? 'Active'));
    $emp_no     = trim((string)($post['employee_no'] ?? ''));
    $leave_balance = isset($post['leave_balance']) && $post['leave_balance'] !== '' ? (float)$post['leave_balance'] : 15.0;
    $pay_type = trim((string)($post['pay_type'] ?? 'Monthly'));
    $basic_salary = isset($post['basic_salary']) && $post['basic_salary'] !== '' ? (float)$post['basic_salary'] : 0.0;
    $hourly_rate = isset($post['hourly_rate']) && $post['hourly_rate'] !== '' ? (float)$post['hourly_rate'] : 0.0;

    if ($full_name === '' || $position === '' || $department === '' || $hire_date === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'full_name, position, department, and hire_date are required.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date) || !checkdate(
        (int)substr($hire_date, 5, 2),
        (int)substr($hire_date, 8, 2),
        (int)substr($hire_date, 0, 4)
    )) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'hire_date must be a valid date in Y-m-d format.']);
        exit;
    }
    if (!in_array($status, VALID_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'employment_status must be Active, On Leave, or Terminated.']);
        exit;
    }
    if ($leave_balance < 0 || $basic_salary < 0 || $hourly_rate < 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Leave balance and pay rates cannot be negative.']);
        exit;
    }
    if (!in_array($pay_type, ['Monthly', 'Hourly'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'pay_type must be Monthly or Hourly.']);
        exit;
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Enter a valid email address.']);
        exit;
    }
    if ($pay_type === 'Monthly' && $basic_salary <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Basic Monthly Salary is required for monthly employees.']);
        exit;
    }
    if ($pay_type === 'Hourly' && $hourly_rate <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Hourly Rate is required for hourly employees.']);
        exit;
    }

    if ($id !== null) {
        $pdo->prepare(
            "UPDATE employees SET full_name=:fn, position=:pos, department=:dept,
             email=:email, phone=:phone, hire_date=:hd, employment_status=:status, leave_balance=:lb,
             pay_type=:pay_type, basic_salary=:basic_salary, hourly_rate=:hourly_rate
             WHERE id=:id"
        )->execute([
            ':fn' => $full_name, ':pos' => $position, ':dept' => $department,
            ':email' => $email, ':phone' => $phone, ':hd' => $hire_date,
            ':status' => $status, ':lb' => $leave_balance, ':pay_type' => $pay_type,
            ':basic_salary' => $basic_salary, ':hourly_rate' => $hourly_rate, ':id' => $id,
        ]);
        logAudit($pdo, 'Employee updated', 'employee', (string)$id, json_encode(['employee_no'=>$emp_no,'name'=>$full_name,'status'=>$status]));
        echo json_encode(['ok' => true, 'id' => $id]);
    } else {
        // Auto-generate employee_no if not supplied: insert first to get the AUTOINCREMENT id
        $pdo->prepare(
            "INSERT INTO employees (employee_no, full_name, position, department, email, phone, hire_date, employment_status, leave_balance, pay_type, basic_salary, hourly_rate)
             VALUES (:eno, :fn, :pos, :dept, :email, :phone, :hd, :status, :lb, :pay_type, :basic_salary, :hourly_rate)"
        )->execute([
            ':eno'    => $emp_no !== '' ? $emp_no : 'EMP-TEMP',
            ':fn'     => $full_name, ':pos' => $position, ':dept' => $department,
            ':email'  => $email, ':phone' => $phone, ':hd' => $hire_date,
            ':status' => $status, ':lb' => $leave_balance, ':pay_type' => $pay_type,
            ':basic_salary' => $basic_salary, ':hourly_rate' => $hourly_rate,
        ]);
        $newId = (int)$pdo->lastInsertId();
        if ($emp_no === '') {
            $emp_no = 'EMP-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE employees SET employee_no=:eno WHERE id=:id")
                ->execute([':eno' => $emp_no, ':id' => $newId]);
        }
        logAudit($pdo, 'Employee created', 'employee', (string)$newId, json_encode(['employee_no'=>$emp_no,'name'=>$full_name]));
        echo json_encode(['ok' => true, 'id' => $newId, 'employee_no' => $emp_no]);
    }
} catch (Throwable $e) {
    error_log('employee_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save employee.']);
}
