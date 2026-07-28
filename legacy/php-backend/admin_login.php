<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';

try {
    $username = (string)($_POST['username'] ?? 'admin');
    $password = (string)($_POST['password'] ?? '');

    if ($password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Password is required.']);
        exit;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $attemptStmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = :u AND ip_address = :ip AND successful = 0 AND attempted_at >= datetime('now','-15 minutes')");
    $attemptStmt->execute([':u' => $username, ':ip' => $ip]);
    if ((int)$attemptStmt->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many failed login attempts. Try again in 15 minutes.']);
        exit;
    }

    $user = verifyAdminCredentials($username, $password);
    if ($user === null) {
        $pdo->prepare('INSERT INTO login_attempts (username, ip_address, successful) VALUES (:u,:ip,0)')->execute([':u'=>$username, ':ip'=>$ip]);
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid credentials.']);
        exit;
    }

    session_regenerate_id(true);
    ensureCsrfToken();
    $_SESSION['last_activity_at'] = time();
    $pdo->prepare('INSERT INTO login_attempts (username, ip_address, successful) VALUES (:u,:ip,1)')->execute([':u'=>$username, ':ip'=>$ip]);
    $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now','-30 days')")->execute();

    $_SESSION['admin_user_id']      = $user['id'];
    $_SESSION['admin_username']     = $user['username'];
    $_SESSION['admin_full_name']    = $user['full_name'];
    $_SESSION['admin_permissions']  = $user['permissions'];
    $_SESSION['admin_landing_page'] = $user['landing_page'];
    $_SESSION['admin_employee_id']  = $user['employee_id'] !== null ? (int)$user['employee_id'] : null;
    $pdo->prepare("UPDATE admin_users SET last_login = datetime('now') WHERE id = :id")
        ->execute([':id' => $user['id']]);
    logAudit($pdo, 'User login', 'user', (string)$user['id'], $user['username']);

    echo json_encode(['ok' => true, 'landing_page' => $user['landing_page']]);
} catch (Throwable $e) {
    error_log('admin_login.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An internal error occurred.']);
}
