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

requirePermission('hr.requests.approve');
requireCsrfToken();

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
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'decision must be Approved or Rejected.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT employee_id, request_type, start_date, end_date, status FROM hr_requests WHERE id = :id');
    $stmt->execute([':id' => $request_id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Request not found.']);
        exit;
    }
    if ($row['status'] !== 'Pending') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Request has already been ' . strtolower($row['status']) . '.']);
        exit;
    }

    // Segregation of duties: an approver can never act on their own request,
    // even if their account happens to also hold hr.requests.approve. Checked
    // via the reviewer's OWN linked employee_id (getSessionEmployeeId()), not
    // anything client-supplied, so it can't be bypassed by a crafted request.
    $reviewerEmployeeId = getSessionEmployeeId();
    if ($reviewerEmployeeId !== null && $reviewerEmployeeId === (int)$row['employee_id']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You cannot review your own request.']);
        exit;
    }

    $pdo->beginTransaction();

    // Leave requests deduct from the employee's balance on approval — re-validated
    // here (not just at submission) in case other approvals since then already
    // ate into the balance.
    if ($decision === 'Approved' && $row['request_type'] === 'Leave' && $row['start_date'] && $row['end_date']) {
        $days = (new DateTime($row['start_date']))->diff(new DateTime($row['end_date']))->days + 1;

        $balStmt = $pdo->prepare('SELECT leave_balance FROM employees WHERE id = :id');
        $balStmt->execute([':id' => $row['employee_id']]);
        $balance = $balStmt->fetchColumn();

        if ($balance === false) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Employee record not found.']);
            exit;
        }
        if ((float)$balance < $days) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "Employee's leave balance ({$balance} day(s)) is insufficient for this {$days}-day request."]);
            exit;
        }

        $pdo->prepare('UPDATE employees SET leave_balance = leave_balance - :days WHERE id = :id')
            ->execute([':days' => $days, ':id' => $row['employee_id']]);
    }

    $pdo->prepare(
        "UPDATE hr_requests
         SET status=:status, reviewed_by=:by, reviewed_at=datetime('now'), review_notes=:notes
         WHERE id=:id"
    )->execute([
        ':status' => $decision,
        ':by'     => $_SESSION['admin_user_id'],
        ':notes'  => $review_notes,
        ':id'     => $request_id,
    ]);

    $pdo->commit();

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('hr_request_review.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to process review.']);
}
