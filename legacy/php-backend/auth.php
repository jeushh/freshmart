<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isProduction() || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'The request could not be verified. Refresh the page and try again.']);
        exit;
    }
}

function verifyAdminCredentials(string $username, string $password): ?array
{
    global $pdo;
    require_once __DIR__ . '/db.php';
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.full_name, u.status, u.password_hash, u.employee_id, r.permissions, r.landing_page
         FROM admin_users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.username = :username"
    );
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'Active') return null;

    $stored = (string)$user['password_hash'];
    $valid = password_verify($password, $stored);
    $legacySha256 = preg_match('/^[a-f0-9]{64}$/i', $stored) === 1
        && hash_equals(strtolower($stored), hash('sha256', $password));
    if (!$valid && !$legacySha256) return null;

    if ($legacySha256 || password_needs_rehash($stored, PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')
            ->execute([':hash' => $newHash, ':id' => $user['id']]);
        $user['password_hash'] = $newHash;
    }

    $user['permissions'] = json_decode((string)($user['permissions'] ?? '[]'), true) ?? [];
    return $user;
}

function isAdminAuthenticated(): bool
{
    return !empty($_SESSION['admin_user_id']);
}

/**
 * Live check of whether the current session's user is still Active in the DB.
 * Destroys the session and returns false if the account was disabled/removed
 * since login. Shared by requireAdminAuth() (hard 401) and admin_session.php
 * (graceful status report) so both agree on what "still logged in" means.
 */
function isAdminSessionStillValid(): bool
{
    if (!isAdminAuthenticated()) {
        return false;
    }

    global $pdo;
    require_once __DIR__ . '/db.php';

    $stmt = $pdo->prepare('SELECT status FROM admin_users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['admin_user_id']]);
    $status = $stmt->fetchColumn();

    if ($status !== 'Active') {
        session_unset();
        session_destroy();
        return false;
    }

    return true;
}

function requireAdminAuth(): void
{
    $now = time();
    $last = (int)($_SESSION['last_activity_at'] ?? 0);
    if ($last > 0 && ($now - $last) > sessionIdleTimeoutSeconds()) {
        session_unset();
        session_destroy();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Your session expired due to inactivity. Please sign in again.']);
        exit;
    }
    $_SESSION['last_activity_at'] = $now;
    if (!isAdminSessionStillValid()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Admin authentication required.']);
        exit;
    }
}

/**
 * Returns the employee_id linked to the current session's admin_users row, or
 * null if this login isn't tied to an employee record (e.g. the demo `admin`
 * or `cashier` accounts). Read straight from the session (set at login by
 * admin_login.php) rather than hitting the DB again on every call.
 */
function getSessionEmployeeId(): ?int
{
    return isset($_SESSION['admin_employee_id']) && $_SESSION['admin_employee_id'] !== null
        ? (int) $_SESSION['admin_employee_id']
        : null;
}

/**
 * Resolves which employee_id an endpoint should actually operate on, given a
 * client-supplied value and a set of "full view/manage" permission keys:
 *
 *   - Session has one of $fullAccessKeys (e.g. hr.employees.view)  -> trusts
 *     $requestedEmployeeId as-is (staff can act on any employee).
 *   - Session only has employee.self                               -> IGNORES
 *     $requestedEmployeeId entirely and returns the session's own employee_id.
 *     This is the important part: a self-service account can never submit or
 *     view data under a different employee_id just by changing a form field.
 *   - Neither                                                        -> 403.
 */
function resolveEmployeeScope(array $fullAccessKeys, ?int $requestedEmployeeId): int
{
    requireAdminAuth();
    $granted = $_SESSION['admin_permissions'] ?? [];

    foreach ($fullAccessKeys as $key) {
        if (in_array($key, $granted, true)) {
            if ($requestedEmployeeId === null || $requestedEmployeeId <= 0) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'A valid employee_id is required.']);
                exit;
            }
            return $requestedEmployeeId;
        }
    }

    if (in_array('employee.self', $granted, true)) {
        $ownId = getSessionEmployeeId();
        if ($ownId === null) {
            http_response_code(409);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'This account is not linked to an employee record.']);
            exit;
        }
        return $ownId;
    }

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions.']);
    exit;
}

function requirePermission(string $key): void
{
    requireAdminAuth();
    if (!in_array($key, $_SESSION['admin_permissions'] ?? [], true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Insufficient permissions.']);
        exit;
    }
}

/**
 * Like requirePermission(), but passes if the session has ANY of the given keys.
 * Used for endpoints shared across modules — e.g. the employee directory is
 * looked up by Employees, Attendance, HR Requests, and Finance Requests alike,
 * so it shouldn't be pinned to just one of those modules' permission keys.
 */
function requireAnyPermission(array $keys): void
{
    requireAdminAuth();
    $granted = $_SESSION['admin_permissions'] ?? [];
    foreach ($keys as $key) {
        if (in_array($key, $granted, true)) {
            return;
        }
    }
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions.']);
    exit;
}
