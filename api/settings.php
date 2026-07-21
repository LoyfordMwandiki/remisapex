<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

$method = $_SERVER['REQUEST_METHOD'];

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

$allowedKeys = [
    'company_name',
    'company_email',
    'company_phone',
    'company_address',
    'currency',
    'currency_symbol',
    'rent_due_day',
    'late_fee_percent',
];

try {
    $db = getDB();

    if ($method === 'GET') {
        requirePermission('settings.view');
        $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
        $rows = $stmt->fetchAll();
        $settings = [];

        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $settings)) {
                $settings[$key] = '';
            }
        }

        jsonResponse(true, 'Settings loaded.', ['settings' => $settings]);
    }

    if ($method === 'PUT') {
        requirePermission('settings.update');
        $input = getInput();
        $settings = $input['settings'] ?? $input;

        if (!is_array($settings)) {
            jsonResponse(false, 'Invalid settings payload.', [], 400);
        }

        $stmt = $db->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = trim((string) $settings[$key]);
            $stmt->execute([$key, $value]);
        }

        jsonResponse(true, 'Settings saved successfully.');
    }

    if ($method === 'POST') {
        requirePermission('settings.password');
        $input = getInput();
        $action = $input['action'] ?? '';

        if ($action !== 'change_password') {
            jsonResponse(false, 'Unknown action.', [], 400);
        }

        $userId = (int) ($input['user_id'] ?? 0);
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        $actor = currentUser();

        if (!$actor || $userId !== (int) $actor['id']) {
            jsonResponse(false, 'You can only change your own password.', [], 403);
        }

        if ($userId <= 0 || $currentPassword === '' || $newPassword === '') {
            jsonResponse(false, 'User, current password, and new password are required.', [], 400);
        }

        if (strlen($newPassword) < 6) {
            jsonResponse(false, 'New password must be at least 6 characters.', [], 400);
        }

        if ($newPassword !== $confirmPassword) {
            jsonResponse(false, 'New passwords do not match.', [], 400);
        }

        $stmt = $db->prepare('SELECT id, password FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            jsonResponse(false, 'Current password is incorrect.', [], 401);
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $update->execute([$hash, $userId]);

        jsonResponse(true, 'Password updated successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
