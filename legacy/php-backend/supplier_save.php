<?php
/**
 * =====================================================================================
 * backend/supplier_save.php — SUPPLIER WRITE ADAPTER
 * =====================================================================================
 * POST-only, requires inventory.manage. Creates or updates a supplier.
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

    $id             = isset($post['id']) && $post['id'] !== '' ? (int) $post['id'] : null;
    $name           = trim((string) ($post['name'] ?? ''));
    $contactPerson  = trim((string) ($post['contact_person'] ?? ''));
    $phone          = trim((string) ($post['phone'] ?? ''));
    $email          = trim((string) ($post['email'] ?? ''));
    $address        = trim((string) ($post['address'] ?? ''));
    $status         = trim((string) ($post['status'] ?? 'Active'));

    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Supplier name is required.']);
        exit;
    }
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'status must be Active or Inactive.']);
        exit;
    }

    if ($id !== null) {
        $existsStmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = :id');
        $existsStmt->execute([':id' => $id]);
        if (!$existsStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Supplier not found.']);
            exit;
        }
        $pdo->prepare(
            'UPDATE suppliers SET name=:name, contact_person=:cp, phone=:phone, email=:email, address=:addr, status=:status WHERE id=:id'
        )->execute([
            ':name' => $name, ':cp' => $contactPerson ?: null, ':phone' => $phone ?: null,
            ':email' => $email ?: null, ':addr' => $address ?: null, ':status' => $status, ':id' => $id,
        ]);
        logAudit($pdo, 'Supplier updated', 'supplier', (string) $id, $name);
        echo json_encode(['ok' => true, 'id' => $id]);
    } else {
        $pdo->prepare(
            'INSERT INTO suppliers (name, contact_person, phone, email, address, status)
             VALUES (:name, :cp, :phone, :email, :addr, :status)'
        )->execute([
            ':name' => $name, ':cp' => $contactPerson ?: null, ':phone' => $phone ?: null,
            ':email' => $email ?: null, ':addr' => $address ?: null, ':status' => $status,
        ]);
        $newId = (int) $pdo->lastInsertId();
        logAudit($pdo, 'Supplier created', 'supplier', (string) $newId, $name);
        echo json_encode(['ok' => true, 'id' => $newId]);
    }
} catch (Throwable $e) {
    error_log('supplier_save.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save supplier.']);
}
