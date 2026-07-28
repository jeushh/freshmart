<?php
/**
 * =====================================================================================
 * backend/finance_request_review.php — FINANCE REQUESTS WRITE ADAPTER (review)
 * =====================================================================================
 * POST-only, requires finance.requests.approve. Valid transitions only:
 *   Pending  -> Approved | Rejected
 *   Approved -> Paid
 * Any other transition (e.g. Rejected -> Paid, Paid -> anything) is rejected with 409.
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

requirePermission('finance.requests.approve');
requireCsrfToken();

const ALLOWED_TRANSITIONS = [
    'Pending'  => ['Approved', 'Rejected'],
    'Approved' => ['Paid'],
];

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $request_id   = isset($post['request_id'])   ? (int)$post['request_id']   : 0;
    $decision     = trim((string)($post['decision']      ?? ''));
    $review_notes = trim((string)($post['review_notes']  ?? '')) ?: null;

    if ($request_id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid request_id is required.']);
        exit;
    }
    if (!in_array($decision, ['Approved', 'Rejected', 'Paid'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'decision must be Approved, Rejected, or Paid.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT employee_id, status FROM finance_requests WHERE id = :id');
    $stmt->execute([':id' => $request_id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Request not found.']);
        exit;
    }

    // Segregation of duties: an approver can never act on their own request,
    // even if their account also happens to hold finance.requests.approve.
    $reviewerEmployeeId = getSessionEmployeeId();
    if ($reviewerEmployeeId !== null && $reviewerEmployeeId === (int)$row['employee_id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You cannot review your own request.']);
        exit;
    }

    $currentStatus     = $row['status'];
    $allowedNextStates = ALLOWED_TRANSITIONS[$currentStatus] ?? [];

    if (!in_array($decision, $allowedNextStates, true)) {
        http_response_code(409);
        echo json_encode([
            'ok'    => false,
            'error' => "Cannot move a {$currentStatus} request to {$decision}.",
        ]);
        exit;
    }

    $pdo->prepare(
        "UPDATE finance_requests
         SET status=:status, reviewed_by=:by, reviewed_at=datetime('now'), review_notes=:notes
         WHERE id=:id"
    )->execute([
        ':status' => $decision,
        ':by'     => $_SESSION['admin_user_id'],
        ':notes'  => $review_notes,
        ':id'     => $request_id,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('finance_request_review.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to process review.']);
}
