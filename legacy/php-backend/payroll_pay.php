<?php
declare(strict_types=1);
/**
 * backend/payroll_pay.php — PAYROLL PAYMENT ADAPTER (POST-only, requires payroll.manage)
 * Approved -> Paid. Posts one 'Payroll' financial_transaction (category 'Payroll Expense')
 * for the net_pay amount, per spec section 14.
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
    $id = isset($post['id']) ? (int) $post['id'] : 0;

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid id is required.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT pr.*, e.full_name FROM payroll pr JOIN employees e ON e.id = pr.employee_id WHERE pr.id = :id');
    $stmt->execute([':id' => $id]);
    $payroll = $stmt->fetch();

    if (!$payroll) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Payroll record not found.']);
        exit;
    }
    if ($payroll['status'] !== 'Approved') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Only an Approved payroll record can be paid (current status: {$payroll['status']})."]);
        exit;
    }
    if ((float)$payroll['net_pay'] <= 0) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'A zero or negative payroll cannot be marked paid. Recreate the pay run after configuring employee compensation.']);
        exit;
    }

    $pdo->prepare("UPDATE payroll SET status='Paid', paid_at=datetime('now') WHERE id=:id")->execute([':id' => $id]);

    recordFinancialTransaction(
        $pdo, 'Payroll', (float) $payroll['net_pay'], 'payroll', (string) $id,
        "Payroll for {$payroll['full_name']} ({$payroll['pay_period_start']} to {$payroll['pay_period_end']})",
        'Payroll Expense'
    );

    logAudit($pdo, 'Payroll paid', 'payroll', (string) $id, '₱' . number_format((float) $payroll['net_pay'], 2));

    $pdo->commit();
    echo json_encode(['ok' => true, 'status' => 'Paid']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('payroll_pay.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to mark payroll as paid.']);
}
