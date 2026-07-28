<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Shared lookup used by Employees, Attendance, HR Requests, and Finance Requests —
// any one of these permissions is enough to read the directory (editing employee
// records themselves still requires hr.employees.edit specifically, in employee_save.php).
requireAnyPermission([
    'hr.employees.view', 'hr.employees.edit',
    'hr.attendance.view', 'hr.attendance.edit',
    'hr.requests.view', 'hr.requests.approve',
    'finance.requests.view', 'finance.requests.approve',
]);

try {
    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare(
            "SELECT * FROM employees
             WHERE full_name LIKE :s OR employee_no LIKE :s
             ORDER BY full_name"
        );
        $stmt->execute([':s' => $like]);
    } else {
        $stmt = $pdo->query("SELECT * FROM employees ORDER BY full_name");
    }
    echo json_encode(['ok' => true, 'employees' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('employees.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load employees.']);
}
