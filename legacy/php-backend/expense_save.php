<?php
declare(strict_types=1);
/**
 * backend/expense_save.php — EXPENSE CREATE ADAPTER (POST-only, requires finance.manage)
 * Creates a new expense in 'Pending' status, unpaid. Approval/payment happen via
 * expense_review.php. Mirrors the existing finance_request_submit.php pattern.
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

    $category    = trim((string) ($post['category'] ?? ''));
    $amount      = isset($post['amount']) ? (float) $post['amount'] : null;
    $description = trim((string) ($post['description'] ?? ''));
    $expenseDate = trim((string) ($post['expense_date'] ?? '')) ?: date('Y-m-d');

    if ($category === '' || $description === '' || $amount === null || $amount < 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'category, description, and a non-negative amount are required.']);
        exit;
    }

    $requestedBy = $_SESSION['admin_username'] ?? 'unknown';

    $pdo->prepare(
        "INSERT INTO expenses (category, amount, description, expense_date, requested_by, status, payment_status)
         VALUES (:cat, :amt, :desc, :date, :by, 'Pending', 'Unpaid')"
    )->execute([
        ':cat' => $category, ':amt' => $amount, ':desc' => $description, ':date' => $expenseDate, ':by' => $requestedBy,
    ]);
    $newId = (int) $pdo->lastInsertId();

    logAudit($pdo, 'Expense submitted', 'expense', (string) $newId, "{$category}: ₱" . number_format($amount, 2));

    echo json_encode(['ok' => true, 'id' => $newId]);
} catch (Throwable $e) {
    error_log('expense_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to submit expense.']);
}
