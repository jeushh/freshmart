<?php
declare(strict_types=1);
/**
 * backend/accounts_payable_pay.php — SUPPLIER PAYMENT ADAPTER
 * POST-only, requires finance.manage. Records a (possibly partial) payment against
 * an accounts_payable row: reduces the outstanding balance, updates its status, and
 * posts a 'Supplier Payment' financial_transaction (cash out).
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
requirePermission('finance.manage');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $id     = isset($post['id']) ? (int) $post['id'] : 0;
    $amount = isset($post['amount']) ? (float) $post['amount'] : 0.0;
    $method = trim((string) ($post['payment_method'] ?? 'Cash'));

    if ($id <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid id and payment amount > 0 are required.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM accounts_payable WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $ap = $stmt->fetch();
    if (!$ap) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Accounts payable record not found.']);
        exit;
    }
    if ($ap['status'] === 'Paid') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'This payable is already fully paid.']);
        exit;
    }

    $balance = (float) $ap['total_amount'] - (float) $ap['amount_paid'];
    if ($amount > $balance + 0.005) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Payment of ₱" . number_format($amount, 2) . " exceeds the remaining balance of ₱" . number_format($balance, 2) . "."]);
        exit;
    }

    $newPaid = round((float) $ap['amount_paid'] + $amount, 2);
    $newStatus = $newPaid >= (float) $ap['total_amount'] - 0.005 ? 'Paid' : 'Partially Paid';

    $pdo->prepare('UPDATE accounts_payable SET amount_paid = :paid, status = :status WHERE id = :id')
        ->execute([':paid' => $newPaid, ':status' => $newStatus, ':id' => $id]);

    recordFinancialTransaction(
        $pdo, 'Supplier Payment', $amount, 'accounts_payable', (string) $id,
        'Payment to supplier for AP #' . $id, 'Accounts Payable', $method
    );

    logAudit($pdo, 'Supplier payment recorded', 'accounts_payable', (string) $id, '₱' . number_format($amount, 2));

    $pdo->commit();
    echo json_encode(['ok' => true, 'status' => $newStatus, 'amount_paid' => $newPaid, 'balance' => round((float) $ap['total_amount'] - $newPaid, 2)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('accounts_payable_pay.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to record supplier payment.']);
}
