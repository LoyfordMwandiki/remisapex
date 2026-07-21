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
requirePermission(permissionForMethod('apartments', $method));
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
                'SELECT a.*,
                    (SELECT COUNT(*) FROM rooms r WHERE r.apartment_id = a.id) AS room_count,
                    (SELECT COUNT(*) FROM rooms r WHERE r.apartment_id = a.id AND r.status = "occupied") AS occupied_count,
                    (SELECT GROUP_CONCAT(r.room_number ORDER BY r.room_number) FROM rooms r WHERE r.apartment_id = a.id AND r.status = "available") AS available_room_numbers
                 FROM apartments a
                 WHERE a.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $apartment = $stmt->fetch();

            if (!$apartment) {
                jsonResponse(false, 'Apartment not found.', [], 404);
            }

            jsonResponse(true, 'Apartment loaded.', ['apartment' => $apartment]);
        }

        $stmt = $db->query(
            'SELECT a.*,
                (SELECT COUNT(*) FROM rooms r WHERE r.apartment_id = a.id) AS room_count,
                (SELECT COUNT(*) FROM rooms r WHERE r.apartment_id = a.id AND r.status = "occupied") AS occupied_count,
                (SELECT GROUP_CONCAT(r.room_number ORDER BY r.room_number) FROM rooms r WHERE r.apartment_id = a.id AND r.status = "available") AS available_room_numbers
             FROM apartments a
             ORDER BY a.name ASC'
        );

        jsonResponse(true, 'Apartments loaded.', ['apartments' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $address = trim($input['address'] ?? '');
        $city = trim($input['city'] ?? '');
        $description = trim($input['description'] ?? '');
        $totalFloors = max(1, (int) ($input['total_floors'] ?? 1));
        $monthlyRentAmount = max(0, (float) ($input['monthly_rent_amount'] ?? 0));
        $rentDepositAmount = max(0, (float) ($input['rent_deposit_amount'] ?? 0));

        if ($name === '' || $address === '') {
            jsonResponse(false, 'Name and address are required.', [], 400);
        }

        $stmt = $db->prepare(
            'INSERT INTO apartments (name, address, city, description, total_floors, monthly_rent_amount, rent_deposit_amount)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name,
            $address,
            $city !== '' ? $city : null,
            $description !== '' ? $description : null,
            $totalFloors,
            $monthlyRentAmount,
            $rentDepositAmount,
        ]);

        jsonResponse(true, 'Apartment created successfully.', [
            'apartment' => [
                'id' => (int) $db->lastInsertId(),
                'name' => $name,
                'address' => $address,
                'city' => $city,
                'total_floors' => $totalFloors,
                'monthly_rent_amount' => $monthlyRentAmount,
                'rent_deposit_amount' => $rentDepositAmount,
            ],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $address = trim($input['address'] ?? '');
        $city = trim($input['city'] ?? '');
        $description = trim($input['description'] ?? '');
        $totalFloors = max(1, (int) ($input['total_floors'] ?? 1));
        $monthlyRentAmount = max(0, (float) ($input['monthly_rent_amount'] ?? 0));
        $rentDepositAmount = max(0, (float) ($input['rent_deposit_amount'] ?? 0));
        $isActive = isset($input['is_active']) ? (int) (bool) $input['is_active'] : 1;

        if ($id <= 0 || $name === '' || $address === '') {
            jsonResponse(false, 'Valid ID, name, and address are required.', [], 400);
        }

        $stmt = $db->prepare(
            'UPDATE apartments
             SET name = ?, address = ?, city = ?, description = ?, total_floors = ?, monthly_rent_amount = ?, rent_deposit_amount = ?, is_active = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $address,
            $city !== '' ? $city : null,
            $description !== '' ? $description : null,
            $totalFloors,
            $monthlyRentAmount,
            $rentDepositAmount,
            $isActive,
            $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Apartment not found or no changes made.', [], 404);
        }

        jsonResponse(true, 'Apartment updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid apartment ID is required.', [], 400);
        }

        $stmt = $db->prepare('DELETE FROM apartments WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Apartment not found.', [], 404);
        }

        jsonResponse(true, 'Apartment deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
