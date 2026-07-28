<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';

$authenticated = isAdminSessionStillValid();
echo json_encode([
    'ok'            => true,
    'authenticated' => $authenticated,
    'username'      => $authenticated ? ($_SESSION['admin_username']  ?? null) : null,
    'full_name'     => $authenticated ? ($_SESSION['admin_full_name'] ?? null) : null,
    'permissions'   => $authenticated ? ($_SESSION['admin_permissions'] ?? []) : [],
    'landing_page'  => $authenticated ? ($_SESSION['admin_landing_page'] ?? 'pos') : null,
    'employee_id'   => $authenticated ? ($_SESSION['admin_employee_id'] ?? null) : null,
    'csrf_token'    => $authenticated ? ensureCsrfToken() : null,
]);
