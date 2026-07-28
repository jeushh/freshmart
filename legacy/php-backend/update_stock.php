<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Only POST requests are supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';
requirePermission('inventory.manage');
requireCsrfToken();

$productId = trim((string) ($_POST['product_id'] ?? ''));
$newStock = trim((string) ($_POST['new_stock'] ?? ''));

if (!ctype_digit($productId) || (int) $productId < 1) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'product_id must be a positive integer.']);
    exit;
}

if (!ctype_digit($newStock)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'new_stock must be a non-negative integer.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $productCheck = $pdo->prepare('SELECT id, sku, stock_quantity FROM products WHERE id = :id');
    $productCheck->execute([':id' => (int) $productId]);
    $product = $productCheck->fetch();
    if (!$product) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Product not found.']);
        exit;
    }

    $previousStock = (int) $product['stock_quantity'];
    $newStockInt = (int) $newStock;

    $update = $pdo->prepare(
        'UPDATE products SET stock_quantity = :new_stock WHERE id = :product_id'
    );
    $update->execute([
        ':new_stock'  => $newStockInt,
        ':product_id' => (int) $productId,
    ]);

    if ($newStockInt !== $previousStock) {
        recordInventoryMovement(
            $pdo, (int) $productId, $product['sku'], 'Adjustment', $newStockInt - $previousStock,
            $previousStock, $newStockInt, null, 'Manual stock update'
        );
    }

    $pdo->commit();
    echo json_encode([
        'status'  => 'success',
        'message' => 'Inventory successfully updated in database ledger.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('update_stock.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Inventory update failed.']);
}
