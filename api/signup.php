<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    $input = $_POST;
}

$fullName = trim($input['full_name'] ?? '');
$email = strtolower(trim($input['email'] ?? ''));
$phone = trim($input['phone'] ?? '');
$password = $input['password'] ?? '';
$confirmPassword = $input['confirm_password'] ?? '';

if ($fullName === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

try {
    $db = getDB();

    $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);

    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Public signup creates Staff accounts only. Managers/Admins are created via Users module.
    $stmt = $db->prepare(
        'INSERT INTO users (full_name, email, password, phone, role) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $fullName,
        $email,
        $hash,
        $phone !== '' ? $phone : null,
        'Staff',
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully. You can now sign in.',
        'user' => [
            'id' => (int) $db->lastInsertId(),
            'name' => $fullName,
            'email' => $email,
            'role' => 'Staff',
        ],
    ]);
} catch (PDOException $e) {
    // Keep database details out of the browser, but retain them in the server log
    // so a hosting/configuration problem can be diagnosed.
    error_log('REMIS signup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to create the account because the database is unavailable. Confirm that the rentsys database has been imported and that config/database.php has the correct database credentials.',
    ]);
}
