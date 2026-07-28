<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Only GET and POST are supported.']); exit; }
require_once __DIR__ . '/auth.php'; require_once __DIR__ . '/db.php'; require_once __DIR__ . '/inventory_finance_shared.php';
requirePermission('system.settings.manage');
requireCsrfToken();

const EDITABLE_SYSTEM_SETTINGS = ['business_name', 'business_address', 'business_phone', 'business_email', 'system_name'];
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key')->fetchAll();
        $settings = [];
        foreach ($rows as $row) $settings[$row['setting_key']] = $row['setting_value'];
        echo json_encode(['ok' => true, 'settings' => $settings]); exit;
    }
    $data = json_decode(file_get_contents('php://input'), true); if (!is_array($data)) $data = $_POST;
    $settings = $data['settings'] ?? $data; if (!is_array($settings)) throw new InvalidArgumentException('Settings payload is invalid.');
    $values = [];
    foreach (EDITABLE_SYSTEM_SETTINGS as $key) {
        if (!array_key_exists($key, $settings)) continue;
        $value = trim((string)$settings[$key]);
        if (mb_strlen($value) > 250) throw new InvalidArgumentException('Settings values must be 250 characters or fewer.');
        if ($key === 'business_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Provide a valid email address.');
        $values[$key] = $value;
    }
    if (!$values) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'No supported settings were supplied.']); exit; }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, datetime(\'now\')) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value, updated_at=excluded.updated_at');
    foreach ($values as $key => $value) $stmt->execute([':key' => $key, ':value' => $value]);
    $pdo->commit(); logAudit($pdo, 'System settings updated', 'system_settings', null, implode(', ', array_keys($values)));
    echo json_encode(['ok' => true]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); http_response_code(400); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack(); error_log('system_settings.php: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Failed to save settings.']);
}
