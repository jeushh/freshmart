<?php
declare(strict_types=1);
/**
 * =====================================================================================
 * backend/cash_drawer.php — CASH MANAGEMENT / END-OF-DAY RECONCILIATION ADAPTER
 * =====================================================================================
 * Requires finance.manage. One row per business_date.
 *   GET  ?date=YYYY-MM-DD (defaults to today) -> current drawer + computed Expected Cash
 *   POST action=open  {opening_cash}                    -> opens today's drawer
 *   POST action=close {actual_cash}                      -> closes it, computes variance
 *   POST action=cash_in  {amount, note}  / action=cash_out {amount, note} -> adjusts drawer
 *
 * Expected Cash = opening_cash + cash sales (from financial_transactions, Sale type,
 * payment_method=Cash, for that business_date) + cash_in - cash_out.
 * Cash Variance = actual_cash - Expected Cash (computed at close time).
 * =====================================================================================
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';
requirePermission('finance.manage');
requireCsrfToken();

function computeExpectedCash(PDO $pdo, array $drawer): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM financial_transactions
         WHERE transaction_type = 'Sale' AND payment_method = 'Cash' AND date(created_at) = :d"
    );
    $stmt->execute([':d' => $drawer['business_date']]);
    $cashSales = (float) $stmt->fetchColumn();
    return round((float) $drawer['opening_cash'] + $cashSales + (float) $drawer['cash_in'] - (float) $drawer['cash_out'], 2);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $date = trim((string) ($_GET['date'] ?? '')) ?: date('Y-m-d');
        $stmt = $pdo->prepare('SELECT * FROM cash_drawers WHERE business_date = :d');
        $stmt->execute([':d' => $date]);
        $drawer = $stmt->fetch();

        if (!$drawer) {
            echo json_encode(['ok' => true, 'drawer' => null, 'business_date' => $date]);
            exit;
        }
        $drawer['expected_cash'] = computeExpectedCash($pdo, $drawer);
        echo json_encode(['ok' => true, 'drawer' => $drawer]);
        exit;
    }

    // ---- POST ----
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;
    $action = trim((string) ($post['action'] ?? ''));
    $date = trim((string) ($post['business_date'] ?? '')) ?: date('Y-m-d');
    $username = $_SESSION['admin_username'] ?? 'unknown';

    if ($action === 'open') {
        $opening = (float) ($post['opening_cash'] ?? 0);
        $existsStmt = $pdo->prepare('SELECT id FROM cash_drawers WHERE business_date = :d');
        $existsStmt->execute([':d' => $date]);
        if ($existsStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "A cash drawer for {$date} is already open."]);
            exit;
        }
        $pdo->prepare("INSERT INTO cash_drawers (business_date, opened_by, opening_cash) VALUES (:d, :by, :o)")
            ->execute([':d' => $date, ':by' => $username, ':o' => $opening]);
        logAudit($pdo, 'Cash drawer opened', 'cash_drawer', $date, '₱' . number_format($opening, 2));
        echo json_encode(['ok' => true]);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM cash_drawers WHERE business_date = :d');
    $stmt->execute([':d' => $date]);
    $drawer = $stmt->fetch();
    if (!$drawer) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "No cash drawer open for {$date}. Open one first."]);
        exit;
    }
    if ($drawer['status'] === 'Closed' && $action !== 'reopen_check') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "The {$date} cash drawer is already closed."]);
        exit;
    }

    if ($action === 'cash_in' || $action === 'cash_out') {
        $amount = (float) ($post['amount'] ?? 0);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'amount must be greater than zero.']);
            exit;
        }
        $col = $action === 'cash_in' ? 'cash_in' : 'cash_out';
        $pdo->prepare("UPDATE cash_drawers SET {$col} = {$col} + :amt WHERE id = :id")
            ->execute([':amt' => $amount, ':id' => $drawer['id']]);
        logAudit($pdo, "Cash drawer {$action}", 'cash_drawer', $date, '₱' . number_format($amount, 2));
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'close') {
        $actual = (float) ($post['actual_cash'] ?? 0);
        $expected = computeExpectedCash($pdo, $drawer);
        $variance = round($actual - $expected, 2);
        $pdo->prepare(
            "UPDATE cash_drawers SET status='Closed', actual_cash=:actual, closed_by=:by, closed_at=datetime('now') WHERE id=:id"
        )->execute([':actual' => $actual, ':by' => $username, ':id' => $drawer['id']]);
        logAudit($pdo, 'Cash drawer closed', 'cash_drawer', $date, "Expected ₱{$expected}, Actual ₱{$actual}, Variance ₱{$variance}");
        echo json_encode(['ok' => true, 'expected_cash' => $expected, 'actual_cash' => $actual, 'variance' => $variance]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action. Use open, cash_in, cash_out, or close.']);
} catch (Throwable $e) {
    error_log('cash_drawer.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cash drawer operation failed.']);
}
