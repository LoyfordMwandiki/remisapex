<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function jsonResponse(bool $success, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function getInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

try {
    $db = getDB();
    $actor = requirePermission(permissionForMethod('users', $method));

    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $db->prepare(
                'SELECT id, full_name, email, phone, role, is_active, created_at, updated_at
                 FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $user = $stmt->fetch();

            if (!$user) {
                jsonResponse(false, 'User not found.', [], 404);
            }

            jsonResponse(true, 'User loaded.', ['user' => $user]);
        }

        $stmt = $db->query(
            'SELECT id, full_name, email, phone, role, is_active, created_at, updated_at
             FROM users ORDER BY full_name ASC'
        );

        jsonResponse(true, 'Users loaded.', ['users' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $fullName = trim($input['full_name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $role = trim($input['role'] ?? 'Staff');
        $password = $input['password'] ?? '';
        $isActive = isset($input['is_active']) ? (int) (bool) $input['is_active'] : 1;

        if ($fullName === '' || $email === '' || $password === '') {
            jsonResponse(false, 'Name, email, and password are required.', [], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Please enter a valid email address.', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters.', [], 400);
        }

        if (!in_array($role, validRoles(), true)) {
            jsonResponse(false, 'Invalid role.', [], 400);
        }

        $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            jsonResponse(false, 'An account with this email already exists.', [], 409);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            'INSERT INTO users (full_name, email, password, phone, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fullName,
            $email,
            $hash,
            $phone !== '' ? $phone : null,
            $role,
            $isActive,
        ]);

        jsonResponse(true, 'User created successfully.', [
            'user' => ['id' => (int) $db->lastInsertId()],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $fullName = trim($input['full_name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $phone = trim($input['phone'] ?? '');
        $role = trim($input['role'] ?? 'Staff');
        $isActive = isset($input['is_active']) ? (int) (bool) $input['is_active'] : 1;
        $password = $input['password'] ?? '';

        if ($id <= 0 || $fullName === '' || $email === '') {
            jsonResponse(false, 'Valid ID, name, and email are required.', [], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Please enter a valid email address.', [], 400);
        }

        if (!in_array($role, validRoles(), true)) {
            jsonResponse(false, 'Invalid role.', [], 400);
        }

        if ($id === (int) $actor['id'] && !$isActive) {
            jsonResponse(false, 'You cannot deactivate your own account.', [], 400);
        }

        if ($id === (int) $actor['id'] && $role !== $actor['role']) {
            jsonResponse(false, 'You cannot change your own role.', [], 400);
        }

        $dup = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $dup->execute([$email, $id]);
        if ($dup->fetch()) {
            jsonResponse(false, 'An account with this email already exists.', [], 409);
        }

        if ($password !== '') {
            if (strlen($password) < 6) {
                jsonResponse(false, 'Password must be at least 6 characters.', [], 400);
            }
            $stmt = $db->prepare(
                'UPDATE users
                 SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ?, password = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $fullName,
                $email,
                $phone !== '' ? $phone : null,
                $role,
                $isActive,
                password_hash($password, PASSWORD_DEFAULT),
                $id,
            ]);
        } else {
            $stmt = $db->prepare(
                'UPDATE users
                 SET full_name = ?, email = ?, phone = ?, role = ?, is_active = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $fullName,
                $email,
                $phone !== '' ? $phone : null,
                $role,
                $isActive,
                $id,
            ]);
        }

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'User not found or no changes made.', [], 404);
        }

        jsonResponse(true, 'User updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid user ID is required.', [], 400);
        }

        if ($id === (int) $actor['id']) {
            jsonResponse(false, 'You cannot delete your own account.', [], 400);
        }

        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'User not found.', [], 404);
        }

        jsonResponse(true, 'User deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
