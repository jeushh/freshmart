<?php
/**
 * =====================================================================================
 * backend/product_save.php — PRODUCT / INVENTORY CATALOG WRITE ADAPTER
 * =====================================================================================
 * POST-only, requires inventory.manage. Creates or updates a product row including
 * the extended inventory fields (cost_price, reorder_level, min_stock, max_stock,
 * supplier_id, status). If a `new_stock` value is supplied for an existing product
 * and it differs from the current stock_quantity, records an Adjustment inventory
 * movement so manual stock corrections stay in the audit trail — mirrors the pattern
 * update_stock.php already established, but through the single product form instead
 * of a bare number field.
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
require_once __DIR__ . '/inventory_finance_shared.php';

requirePermission('inventory.manage');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $id            = isset($post['id']) && $post['id'] !== '' ? (int) $post['id'] : null;
    $name          = trim((string) ($post['name'] ?? ''));
    $sku           = trim((string) ($post['sku'] ?? ''));
    $category      = trim((string) ($post['category'] ?? ''));
    $price         = isset($post['price']) ? (float) $post['price'] : null;
    $costPrice     = isset($post['cost_price']) ? (float) $post['cost_price'] : 0.0;
    $unit          = trim((string) ($post['unit'] ?? 'pc')) ?: 'pc';
    $emoji         = trim((string) ($post['emoji'] ?? '')) ?: '🛒';
    $reorderLevel  = isset($post['reorder_level']) ? (int) $post['reorder_level'] : 5;
    $minStock      = isset($post['min_stock']) ? (int) $post['min_stock'] : 0;
    $maxStock      = isset($post['max_stock']) ? (int) $post['max_stock'] : 100;
    $supplierId    = isset($post['supplier_id']) && $post['supplier_id'] !== '' ? (int) $post['supplier_id'] : null;
    $status        = trim((string) ($post['status'] ?? 'Active'));
    $newStock      = isset($post['stock_quantity']) && $post['stock_quantity'] !== '' ? (int) $post['stock_quantity'] : null;

    if ($name === '' || $sku === '' || $category === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'name, sku, and category are required.']);
        exit;
    }
    if ($price === null || $price < 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'price must be a non-negative number.']);
        exit;
    }
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'status must be Active or Inactive.']);
        exit;
    }
    if ($maxStock < $minStock) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'max_stock cannot be less than min_stock.']);
        exit;
    }

    $pdo->beginTransaction();

    if ($id !== null) {
        // ---- UPDATE existing product ----
        $existingStmt = $pdo->prepare('SELECT stock_quantity, sku FROM products WHERE id = :id');
        $existingStmt->execute([':id' => $id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Product not found.']);
            exit;
        }

        $dupStmt = $pdo->prepare('SELECT id FROM products WHERE sku = :sku AND id != :id');
        $dupStmt->execute([':sku' => $sku, ':id' => $id]);
        if ($dupStmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "SKU {$sku} is already used by another product."]);
            exit;
        }

        $pdo->prepare(
            'UPDATE products SET name=:name, sku=:sku, price=:price, category=:category, unit=:unit, emoji=:emoji,
                    cost_price=:cost, reorder_level=:reorder, min_stock=:min, max_stock=:max,
                    supplier_id=:supplier, status=:status
             WHERE id=:id'
        )->execute([
            ':name' => $name, ':sku' => $sku, ':price' => $price, ':category' => $category,
            ':unit' => $unit, ':emoji' => $emoji, ':cost' => $costPrice, ':reorder' => $reorderLevel,
            ':min' => $minStock, ':max' => $maxStock, ':supplier' => $supplierId, ':status' => $status,
            ':id' => $id,
        ]);

        // Manual stock correction through the product form -> Adjustment movement.
        if ($newStock !== null && $newStock !== (int) $existing['stock_quantity']) {
            if ($newStock < 0) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'stock_quantity cannot be negative.']);
                exit;
            }
            $prev = (int) $existing['stock_quantity'];
            $pdo->prepare('UPDATE products SET stock_quantity = :s WHERE id = :id')
                ->execute([':s' => $newStock, ':id' => $id]);
            recordInventoryMovement(
                $pdo, $id, $sku, 'Adjustment', $newStock - $prev, $prev, $newStock,
                null, 'Manual adjustment via product form'
            );
        }

        logAudit($pdo, 'Product updated', 'product', (string) $id, "{$name} ({$sku})");
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $id]);
    } else {
        // ---- CREATE new product ----
        $dupStmt = $pdo->prepare('SELECT id FROM products WHERE sku = :sku');
        $dupStmt->execute([':sku' => $sku]);
        if ($dupStmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "SKU {$sku} already exists."]);
            exit;
        }

        $initialStock = $newStock !== null && $newStock >= 0 ? $newStock : 0;

        $pdo->prepare(
            'INSERT INTO products (name, sku, price, category, stock_quantity, unit, emoji,
                                    cost_price, reorder_level, min_stock, max_stock, supplier_id, status)
             VALUES (:name, :sku, :price, :category, :stock, :unit, :emoji,
                     :cost, :reorder, :min, :max, :supplier, :status)'
        )->execute([
            ':name' => $name, ':sku' => $sku, ':price' => $price, ':category' => $category,
            ':stock' => $initialStock, ':unit' => $unit, ':emoji' => $emoji, ':cost' => $costPrice,
            ':reorder' => $reorderLevel, ':min' => $minStock, ':max' => $maxStock,
            ':supplier' => $supplierId, ':status' => $status,
        ]);
        $newId = (int) $pdo->lastInsertId();

        if ($initialStock > 0) {
            recordInventoryMovement(
                $pdo, $newId, $sku, 'Stock In', $initialStock, 0, $initialStock,
                null, 'Initial stock on product creation'
            );
        }

        logAudit($pdo, 'Product created', 'product', (string) $newId, "{$name} ({$sku})");
        $pdo->commit();
        echo json_encode(['ok' => true, 'id' => $newId]);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('product_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save product.']);
}
