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
requirePermission(permissionForMethod('rooms', $method));
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$apartmentId = isset($_GET['apartment_id']) ? (int) $_GET['apartment_id'] : 0;

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

$validStatuses = ['available', 'occupied', 'maintenance'];

try {
    $db = getDB();

    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $db->prepare(
                'SELECT r.*, a.name AS apartment_name
                 FROM rooms r
                 JOIN apartments a ON a.id = r.apartment_id
                 WHERE r.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $room = $stmt->fetch();

            if (!$room) {
                jsonResponse(false, 'Room not found.', [], 404);
            }

            jsonResponse(true, 'Room loaded.', ['room' => $room]);
        }

        $sql = 'SELECT r.*, a.name AS apartment_name
                FROM rooms r
                JOIN apartments a ON a.id = r.apartment_id';
        $params = [];

        if ($apartmentId > 0) {
            $sql .= ' WHERE r.apartment_id = ?';
            $params[] = $apartmentId;
        }

        $sql .= ' ORDER BY a.name ASC, r.room_number ASC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        jsonResponse(true, 'Rooms loaded.', ['rooms' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $apartmentId = (int) ($input['apartment_id'] ?? 0);
        $roomNumber = trim($input['room_number'] ?? '');
        $floor = max(1, (int) ($input['floor'] ?? 1));
        $rentAmount = (float) ($input['rent_amount'] ?? 0);
        $bedrooms = max(0, (int) ($input['bedrooms'] ?? 1));
        $bathrooms = max(0, (int) ($input['bathrooms'] ?? 1));
        $status = $input['status'] ?? 'available';
        $description = trim($input['description'] ?? '');
        $listedDate = trim($input['listed_date'] ?? '');

        if ($apartmentId <= 0 || $roomNumber === '') {
            jsonResponse(false, 'Apartment and room number are required.', [], 400);
        }

        if (!in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid room status.', [], 400);
        }

        $dateErr = validateNotFutureDate($listedDate, 'Listed date');
        if ($dateErr !== null) {
            jsonResponse(false, $dateErr, [], 400);
        }

        $check = $db->prepare('SELECT id FROM apartments WHERE id = ? LIMIT 1');
        $check->execute([$apartmentId]);
        if (!$check->fetch()) {
            jsonResponse(false, 'Selected apartment does not exist.', [], 404);
        }

        $stmt = $db->prepare(
            'INSERT INTO rooms (apartment_id, room_number, floor, rent_amount, bedrooms, bathrooms, status, description, listed_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $apartmentId,
            $roomNumber,
            $floor,
            $rentAmount,
            $bedrooms,
            $bathrooms,
            $status,
            $description !== '' ? $description : null,
            $listedDate !== '' ? $listedDate : null,
        ]);

        jsonResponse(true, 'Room created successfully.', [
            'room' => ['id' => (int) $db->lastInsertId()],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $apartmentId = (int) ($input['apartment_id'] ?? 0);
        $roomNumber = trim($input['room_number'] ?? '');
        $floor = max(1, (int) ($input['floor'] ?? 1));
        $rentAmount = (float) ($input['rent_amount'] ?? 0);
        $bedrooms = max(0, (int) ($input['bedrooms'] ?? 1));
        $bathrooms = max(0, (int) ($input['bathrooms'] ?? 1));
        $status = $input['status'] ?? 'available';
        $description = trim($input['description'] ?? '');
        $listedDate = trim($input['listed_date'] ?? '');

        if ($id <= 0 || $apartmentId <= 0 || $roomNumber === '') {
            jsonResponse(false, 'Valid ID, apartment, and room number are required.', [], 400);
        }

        if (!in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid room status.', [], 400);
        }

        $dateErr = validateNotFutureDate($listedDate, 'Listed date');
        if ($dateErr !== null) {
            jsonResponse(false, $dateErr, [], 400);
        }

        $stmt = $db->prepare(
            'UPDATE rooms
             SET apartment_id = ?, room_number = ?, floor = ?, rent_amount = ?,
                 bedrooms = ?, bathrooms = ?, status = ?, description = ?, listed_date = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $apartmentId,
            $roomNumber,
            $floor,
            $rentAmount,
            $bedrooms,
            $bathrooms,
            $status,
            $description !== '' ? $description : null,
            $listedDate !== '' ? $listedDate : null,
            $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Room not found or no changes made.', [], 404);
        }

        jsonResponse(true, 'Room updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid room ID is required.', [], 400);
        }

        $stmt = $db->prepare('DELETE FROM rooms WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Room not found.', [], 404);
        }

        jsonResponse(true, 'Room deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    if ((int) $e->errorInfo[1] === 1062) {
        jsonResponse(false, 'This room number already exists in the selected apartment.', [], 409);
    }
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
