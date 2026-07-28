<?php
/**
 * =====================================================================================
 * backend/suppliers.php — SUPPLIER DIRECTORY READ ADAPTER
 * =====================================================================================
 * GET-only. Any of inventory.manage / restock.request / restock.approve / finance.manage
 * may read the supplier directory (products, restock requests, POs, and AP all need it).
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAnyPermission(['inventory.manage', 'restock.request', 'restock.approve', 'finance.manage']);

try {
    $stmt = $pdo->query('SELECT id, name, contact_person, phone, email, address, status, created_at FROM suppliers ORDER BY name ASC');
    $suppliers = array_map(static function (array $r): array {
        $r['id'] = (int) $r['id'];
        return $r;
    }, $stmt->fetchAll());
    echo json_encode(['ok' => true, 'suppliers' => $suppliers]);
} catch (Throwable $e) {
    error_log('suppliers.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load suppliers.']);
}
