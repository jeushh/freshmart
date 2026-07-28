<?php
/**
 * =====================================================================================
 * backend/finance_request_submit.php — FINANCE REQUESTS WRITE ADAPTER (submit)
 * =====================================================================================
 * POST-only. Employee self-service users file requests only for themselves.
 * Finance managers review, approve, reject, and pay requests through separate endpoints. Covers both request types:
 * Reimbursement and Purchase.
 * =====================================================================================
 */

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

requirePermission('employee.self');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $employee_id  = resolveEmployeeScope([], null);
    $request_type = trim((string)($post['request_type'] ?? ''));
    $amount       = isset($post['amount']) && $post['amount'] !== '' ? (float)$post['amount'] : null;
    $category     = trim((string)($post['category']    ?? ''));
    $description  = trim((string)($post['description'] ?? ''));

    if (!in_array($request_type, ['Reimbursement', 'Purchase'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'request_type must be Reimbursement or Purchase.']);
        exit;
    }
    if ($amount === null || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'amount must be a positive number.']);
        exit;
    }
    if ($description === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'description is required.']);
        exit;
    }

    $emp = $pdo->prepare('SELECT id FROM employees WHERE id = :id');
    $emp->execute([':id' => $employee_id]);
    if (!$emp->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Employee not found.']);
        exit;
    }

    $pdo->prepare(
        "INSERT INTO finance_requests (employee_id, request_type, amount, category, description)
         VALUES (:emp, :type, :amount, :category, :description)"
    )->execute([
        ':emp'         => $employee_id,
        ':type'        => $request_type,
        ':amount'      => $amount,
        ':category'    => $category !== '' ? $category : null,
        ':description' => $description,
    ]);

    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('finance_request_submit.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to submit finance request.']);
}
