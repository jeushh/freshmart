<?php
declare(strict_types=1);
/**
 * backend/payroll_review.php — PAYROLL STATUS TRANSITION ADAPTER (POST-only, requires payroll.manage)
 *   action=submit  -> Draft -> Pending Approval
 *   action=approve -> Pending Approval -> Approved
 *   action=reject  -> Pending Approval -> Draft (sent back for edits)
 * Paid is handled separately by payroll_pay.php since it also posts a finance transaction.
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

const PAYROLL_TRANSITIONS = [
    'submit'  => ['from' => 'Draft',            'to' => 'Pending Approval'],
    'approve' => ['from' => 'Pending Approval',  'to' => 'Approved'],
    'reject'  => ['from' => 'Pending Approval',  'to' => 'Draft'],
];

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $id     = isset($post['id']) ? (int) $post['id'] : 0;
    $action = trim((string) ($post['action'] ?? ''));

    if ($id <= 0 || !isset(PAYROLL_TRANSITIONS[$action])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid id and action (submit|approve|reject) are required.']);
        exit;
    }

    $transition = PAYROLL_TRANSITIONS[$action];

    $stmt = $pdo->prepare('SELECT status, net_pay FROM payroll WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch();
    $current = $record['status'] ?? false;
    if ($current === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Payroll record not found.']);
        exit;
    }
    if ($current !== $transition['from']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Cannot {$action} a payroll record that is currently '{$current}'."]);
        exit;
    }
    if (in_array($action, ['submit', 'approve'], true) && (float)($record['net_pay'] ?? 0) <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Payroll with a zero or negative net pay cannot be submitted or approved. Configure employee compensation and recalculate the draft first.']);
        exit;
    }

    $reviewer = $_SESSION['admin_username'] ?? 'unknown';
    if ($action === 'approve') {
        $pdo->prepare("UPDATE payroll SET status = :s, approved_by = :by WHERE id = :id")
            ->execute([':s' => $transition['to'], ':by' => $reviewer, ':id' => $id]);
    } else {
        $pdo->prepare('UPDATE payroll SET status = :s WHERE id = :id')
            ->execute([':s' => $transition['to'], ':id' => $id]);
    }

    logAudit($pdo, "Payroll {$action}: {$current} -> {$transition['to']}", 'payroll', (string) $id, null);

    echo json_encode(['ok' => true, 'status' => $transition['to']]);
} catch (Throwable $e) {
    error_log('payroll_review.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update payroll status.']);
}
