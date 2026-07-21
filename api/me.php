<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    authJson(false, 'Method not allowed.', [], 405);
}

$user = requireLogin();

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, full_name, email, phone, role, is_active FROM users WHERE id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([(int) $user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        clearAuthUser();
        authJson(false, 'Session expired. Please log in again.', [], 401);
    }

    $fresh = [
        'id' => (int) $row['id'],
        'name' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'phone' => $row['phone'],
    ];

    setAuthUser($fresh);

    $perms = [];
    foreach (rolePermissions()[$fresh['role']] ?? [] as $perm) {
        $perms[] = $perm;
    }

    authJson(true, 'Authenticated.', [
        'user' => $fresh,
        'permissions' => $perms,
    ]);
} catch (PDOException $e) {
    authJson(false, 'Database error.', [], 500);
}
