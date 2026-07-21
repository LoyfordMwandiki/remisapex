<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/database.php';

/**
 * Role permission map.
 * Use module.action or module.* wildcard. Super Admin has full access.
 */
function rolePermissions(): array
{
    return [
        'Super Admin' => ['*'],
        'Manager' => [
            'dashboard.view',
            // Managers use reports and correct existing payments only.
            'payments.view',
            'payments.update',
            'reports.view',
            'reports.generate',
            'settings.password',
        ],
        'Staff' => [
            'dashboard.view',
            'apartments.view',
            'rooms.view',
            'tenants.view',
            'tenants.create',
            'leases.view',
            'leases.create',
            'payments.view',
            'payments.create',
            'rent_deposits.view',
            'rent_deposits.create',
            'maintenance.view',
            'maintenance.create',
            'reports.view',
        ],
    ];
}

function currentUser(): ?array
{
    return $_SESSION['rentsys_user'] ?? null;
}

function setAuthUser(array $user): void
{
    $_SESSION['rentsys_user'] = [
        'id' => (int) $user['id'],
        'name' => $user['name'] ?? $user['full_name'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'Staff',
    ];
}

function clearAuthUser(): void
{
    unset($_SESSION['rentsys_user']);
}

function authJson(bool $success, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        authJson(false, 'Authentication required. Please log in.', [], 401);
    }
    return $user;
}

function userCan(?array $user, string $permission): bool
{
    if (!$user) {
        return false;
    }

    $role = $user['role'] ?? '';
    $map = rolePermissions();
    $perms = $map[$role] ?? [];

    if (in_array('*', $perms, true)) {
        return true;
    }

    if (in_array($permission, $perms, true)) {
        return true;
    }

    $parts = explode('.', $permission, 2);
    if (count($parts) === 2) {
        $wildcard = $parts[0] . '.*';
        if (in_array($wildcard, $perms, true)) {
            return true;
        }
    }

    return false;
}

function requirePermission(string $permission): array
{
    $user = requireLogin();
    if (!userCan($user, $permission)) {
        authJson(false, 'You do not have permission to perform this action.', [], 403);
    }
    return $user;
}

function permissionForMethod(string $module, string $method): string
{
    $actions = [
        'GET' => 'view',
        'POST' => 'create',
        'PUT' => 'update',
        'DELETE' => 'delete',
    ];

    return $module . '.' . ($actions[$method] ?? 'view');
}

function validRoles(): array
{
    return ['Super Admin', 'Manager', 'Staff'];
}
