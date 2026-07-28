<?php
/**
 * =====================================================================================
 * backend/products.php — INVENTORY MANAGEMENT SYSTEM (IMS) READ ADAPTER
 * =====================================================================================
 * Standardized JSON read-only interface onto the `products` table. The frontend never
 * queries SQLite itself — it only ever calls this endpoint, which is the equivalent of
 * an "IMS Adapter" exposing a stable contract regardless of what's behind it.
 *
 * Supported requests (all GET):
 *   /backend/products.php                          -> all products
 *   /backend/products.php?category=Fruits           -> products in one category
 *   /backend/products.php?search=mango               -> name/SKU search
 *   /backend/products.php?action=categories            -> distinct category list
 *   /backend/products.php?action=check_stock&sku=FRU-001 -> live stock check for one SKU
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php'; // provides $pdo
requireAdminAuth(); // any logged-in role can read the catalog (POS, HR, Finance, Admin all may need it)

$action = $_GET['action'] ?? null;

try {
    // ---- Low stock / out of stock dashboard (current_stock <= reorder_level) ----
    if ($action === 'low_stock') {
        $stmt = $pdo->query(
            "SELECT p.id, p.name, p.sku, p.category, p.stock_quantity, p.unit, p.emoji,
                    p.reorder_level, p.max_stock, p.cost_price, p.supplier_id, s.name AS supplier_name
             FROM products p
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.stock_quantity <= p.reorder_level AND p.status = 'Active'
             ORDER BY p.stock_quantity ASC, p.name ASC"
        );
        $rows = array_map(static function (array $r): array {
            $r['id']             = (int) $r['id'];
            $r['stock_quantity'] = (int) $r['stock_quantity'];
            $r['reorder_level']  = (int) $r['reorder_level'];
            $r['max_stock']      = (int) $r['max_stock'];
            $r['cost_price']     = (float) $r['cost_price'];
            $r['supplier_id']    = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
            $r['status_label']   = (int) $r['stock_quantity'] <= 0 ? 'Out of Stock' : 'Low Stock';
            return $r;
        }, $stmt->fetchAll());
        echo json_encode(['ok' => true, 'products' => $rows]);
        exit;
    }

    // ---- Live single-SKU stock check (used by the frontend before adding to cart) ----
    if ($action === 'check_stock') {
        $sku = trim($_GET['sku'] ?? '');
        if ($sku === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing sku parameter']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT sku, name, price, stock_quantity FROM products WHERE sku = :sku');
        $stmt->execute([':sku' => $sku]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "No product found for SKU {$sku}"]);
            exit;
        }

        echo json_encode([
            'ok'    => true,
            'sku'   => $row['sku'],
            'name'  => $row['name'],
            'price' => (float) $row['price'],
            'stock' => (int) $row['stock_quantity'],
        ]);
        exit;
    }

    // ---- Distinct category list (drives the left navigation rail) ----
    if ($action === 'categories') {
        $stmt = $pdo->query('SELECT DISTINCT category FROM products ORDER BY category ASC');
        $categories = array_column($stmt->fetchAll(), 'category');
        echo json_encode(['ok' => true, 'categories' => $categories]);
        exit;
    }

    // ---- Default: list products, optionally filtered by category or search term ----
    $category = trim($_GET['category'] ?? '');
    $search   = trim($_GET['search'] ?? '');

    $baseSelect = 'SELECT p.id, p.name, p.sku, p.price, p.category, p.stock_quantity, p.unit, p.emoji,
                          p.cost_price, p.reorder_level, p.min_stock, p.max_stock, p.supplier_id, p.status,
                          s.name AS supplier_name
                   FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id';

    if ($search !== '') {
        $stmt = $pdo->prepare(
            $baseSelect . ' WHERE p.name LIKE :term OR p.sku LIKE :term ORDER BY p.category ASC, p.name ASC'
        );
        $stmt->execute([':term' => '%' . $search . '%']);
    } elseif ($category !== '') {
        $stmt = $pdo->prepare(
            $baseSelect . ' WHERE p.category = :category ORDER BY p.name ASC'
        );
        $stmt->execute([':category' => $category]);
    } else {
        $stmt = $pdo->query($baseSelect . ' ORDER BY p.category ASC, p.name ASC');
    }

    $rows = $stmt->fetchAll();
    // Normalize numeric types since PDO/SQLite can return them as strings
    $products = array_map(static function (array $r): array {
        $r['price']          = (float) $r['price'];
        $r['stock_quantity'] = (int) $r['stock_quantity'];
        $r['id']              = (int) $r['id'];
        $r['cost_price']      = (float) $r['cost_price'];
        $r['reorder_level']   = (int) $r['reorder_level'];
        $r['min_stock']       = (int) $r['min_stock'];
        $r['max_stock']       = (int) $r['max_stock'];
        $r['supplier_id']     = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
        $r['low_stock']       = $r['stock_quantity'] <= $r['reorder_level'];
        return $r;
    }, $rows);

    echo json_encode(['ok' => true, 'products' => $products]);

} catch (Throwable $e) {
    error_log('products.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An internal error occurred.']);
}
