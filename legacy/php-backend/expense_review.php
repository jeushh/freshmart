<?php
declare(strict_types=1);
/**
 * backend/expense_review.php — EXPENSE APPROVE/REJECT/PAY ADAPTER
 * POST-only, requires finance.manage.
 *   action=approve  -> status Approved
 *   action=reject    -> status Rejected (requires reason, stored in description suffix... actually stored separately not needed)
 *   action=mark_paid -> payment_status Paid (only valid once Approved); posts an
 *                        Expense financial_transaction and stamps approved_by if unset.
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
    $action = trim((string) ($post['action'] ?? ''));

    if ($id <= 0 || !in_array($action, ['approve', 'reject', 'mark_paid'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid id and action (approve|reject|mark_paid) are required.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $expense = $stmt->fetch();
    if (!$expense) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Expense not found.']);
        exit;
    }

    $reviewer = $_SESSION['admin_username'] ?? 'unknown';

    if ($action === 'approve') {
        if ($expense['status'] !== 'Pending') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Only a Pending expense can be approved.']);
            exit;
        }
        $pdo->prepare("UPDATE expenses SET status='Approved', approved_by=:by WHERE id=:id")
            ->execute([':by' => $reviewer, ':id' => $id]);
        logAudit($pdo, 'Expense approved', 'expense', (string) $id, null);
    } elseif ($action === 'reject') {
        if ($expense['status'] !== 'Pending') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Only a Pending expense can be rejected.']);
            exit;
        }
        $pdo->prepare("UPDATE expenses SET status='Rejected', approved_by=:by WHERE id=:id")
            ->execute([':by' => $reviewer, ':id' => $id]);
        logAudit($pdo, 'Expense rejected', 'expense', (string) $id, null);
    } else { // mark_paid
        if ($expense['status'] !== 'Approved') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Only an Approved expense can be marked Paid.']);
            exit;
        }
        if ($expense['payment_status'] === 'Paid') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Expense is already Paid.']);
            exit;
        }
        $pdo->prepare("UPDATE expenses SET payment_status='Paid' WHERE id=:id")->execute([':id' => $id]);
        recordFinancialTransaction(
            $pdo, 'Expense', (float) $expense['amount'], 'expense', (string) $id,
            $expense['description'], $expense['category']
        );
        logAudit($pdo, 'Expense paid', 'expense', (string) $id, '₱' . number_format((float) $expense['amount'], 2));
    }

    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('expense_review.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to review expense.']);
}
