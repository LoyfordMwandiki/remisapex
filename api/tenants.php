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
require_once __DIR__ . '/../config/validation.php';

$method = $_SERVER['REQUEST_METHOD'];
requirePermission(permissionForMethod('tenants', $method));
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

    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $db->prepare(
                'SELECT t.*,
                    (SELECT COUNT(*) FROM leases l WHERE l.tenant_id = t.id AND l.status = "active") AS active_leases
                 FROM tenants t WHERE t.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $tenant = $stmt->fetch();

            if (!$tenant) {
                jsonResponse(false, 'Tenant not found.', [], 404);
            }

            jsonResponse(true, 'Tenant loaded.', ['tenant' => $tenant]);
        }

        $stmt = $db->query(
            'SELECT t.*,
                (SELECT COUNT(*) FROM leases l WHERE l.tenant_id = t.id AND l.status = "active") AS active_leases
             FROM tenants t
             ORDER BY t.full_name ASC'
        );

        jsonResponse(true, 'Tenants loaded.', ['tenants' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $fullName = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $idNumber = trim($input['id_number'] ?? '');
        $emergencyContact = trim($input['emergency_contact'] ?? '');
        $emergencyPhone = trim($input['emergency_phone'] ?? '');
        $notes = trim($input['notes'] ?? '');
        $registeredDate = trim($input['registered_date'] ?? '');
        if ($registeredDate === '') {
            $registeredDate = date('Y-m-d');
        }

        if ($fullName === '' || $phone === '') {
            jsonResponse(false, 'Full name and phone are required.', [], 400);
        }

        $dateErr = validateNotFutureDate($registeredDate, 'Registration date');
        if ($dateErr !== null) {
            jsonResponse(false, $dateErr, [], 400);
        }

        $stmt = $db->prepare(
            'INSERT INTO tenants (full_name, email, phone, id_number, emergency_contact, emergency_phone, notes, registered_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fullName,
            $email !== '' ? $email : null,
            $phone,
            $idNumber !== '' ? $idNumber : null,
            $emergencyContact !== '' ? $emergencyContact : null,
            $emergencyPhone !== '' ? $emergencyPhone : null,
            $notes !== '' ? $notes : null,
            $registeredDate,
        ]);

        jsonResponse(true, 'Tenant created successfully.', [
            'tenant' => ['id' => (int) $db->lastInsertId(), 'full_name' => $fullName],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $fullName = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $idNumber = trim($input['id_number'] ?? '');
        $emergencyContact = trim($input['emergency_contact'] ?? '');
        $emergencyPhone = trim($input['emergency_phone'] ?? '');
        $notes = trim($input['notes'] ?? '');
        $registeredDate = trim($input['registered_date'] ?? '');
        $isActive = isset($input['is_active']) ? (int) (bool) $input['is_active'] : 1;

        if ($id <= 0 || $fullName === '' || $phone === '') {
            jsonResponse(false, 'Valid ID, full name, and phone are required.', [], 400);
        }

        $dateErr = validateNotFutureDate($registeredDate, 'Registration date');
        if ($dateErr !== null) {
            jsonResponse(false, $dateErr, [], 400);
        }

        $stmt = $db->prepare(
            'UPDATE tenants
             SET full_name = ?, email = ?, phone = ?, id_number = ?,
                 emergency_contact = ?, emergency_phone = ?, notes = ?, registered_date = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $fullName,
            $email !== '' ? $email : null,
            $phone,
            $idNumber !== '' ? $idNumber : null,
            $emergencyContact !== '' ? $emergencyContact : null,
            $emergencyPhone !== '' ? $emergencyPhone : null,
            $notes !== '' ? $notes : null,
            $registeredDate !== '' ? $registeredDate : null,
            $isActive,
            $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Tenant not found or no changes made.', [], 404);
        }

        jsonResponse(true, 'Tenant updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid tenant ID is required.', [], 400);
        }

        $check = $db->prepare('SELECT COUNT(*) FROM leases WHERE tenant_id = ?');
        $check->execute([$id]);
        if ((int) $check->fetchColumn() > 0) {
            jsonResponse(false, 'Cannot delete tenant with existing leases.', [], 409);
        }

        $stmt = $db->prepare('DELETE FROM tenants WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Tenant not found.', [], 404);
        }

        jsonResponse(true, 'Tenant deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
