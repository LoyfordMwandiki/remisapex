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
requirePermission(permissionForMethod('maintenance', $method));
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
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

$validPriorities = ['low', 'medium', 'high', 'urgent'];
$validStatuses = ['open', 'in_progress', 'completed', 'cancelled'];

try {
    $db = getDB();

    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $db->prepare(
                'SELECT m.*, a.name AS apartment_name, r.room_number
                 FROM maintenance_requests m
                 JOIN apartments a ON a.id = m.apartment_id
                 LEFT JOIN rooms r ON r.id = m.room_id
                 WHERE m.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $item = $stmt->fetch();

            if (!$item) {
                jsonResponse(false, 'Maintenance request not found.', [], 404);
            }

            jsonResponse(true, 'Maintenance request loaded.', ['request' => $item]);
        }

        $sql = 'SELECT m.*, a.name AS apartment_name, r.room_number
                FROM maintenance_requests m
                JOIN apartments a ON a.id = m.apartment_id
                LEFT JOIN rooms r ON r.id = m.room_id
                WHERE 1=1';
        $params = [];

        if ($statusFilter !== '' && in_array($statusFilter, $validStatuses, true)) {
            $sql .= ' AND m.status = ?';
            $params[] = $statusFilter;
        }

        if ($apartmentId > 0) {
            $sql .= ' AND m.apartment_id = ?';
            $params[] = $apartmentId;
        }

        $sql .= ' ORDER BY FIELD(m.priority, "urgent", "high", "medium", "low"), m.reported_date DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        jsonResponse(true, 'Maintenance requests loaded.', ['requests' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $apartmentId = (int) ($input['apartment_id'] ?? 0);
        $roomId = (int) ($input['room_id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $priority = $input['priority'] ?? 'medium';
        $status = $input['status'] ?? 'open';
        $reportedDate = trim($input['reported_date'] ?? date('Y-m-d'));
        $completedDate = trim($input['completed_date'] ?? '');
        $cost = (float) ($input['cost'] ?? 0);
        $assignedTo = trim($input['assigned_to'] ?? '');

        if ($apartmentId <= 0 || $title === '') {
            jsonResponse(false, 'Apartment and title are required.', [], 400);
        }

        if (!in_array($priority, $validPriorities, true) || !in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid priority or status.', [], 400);
        }

        $stmt = $db->prepare(
            'INSERT INTO maintenance_requests
             (apartment_id, room_id, title, description, priority, status, reported_date, completed_date, cost, assigned_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $apartmentId,
            $roomId > 0 ? $roomId : null,
            $title,
            $description !== '' ? $description : null,
            $priority,
            $status,
            $reportedDate,
            $completedDate !== '' ? $completedDate : null,
            $cost,
            $assignedTo !== '' ? $assignedTo : null,
        ]);

        if ($roomId > 0 && $status === 'in_progress') {
            $db->prepare('UPDATE rooms SET status = "maintenance" WHERE id = ?')->execute([$roomId]);
        }

        jsonResponse(true, 'Maintenance request created.', [
            'request' => ['id' => (int) $db->lastInsertId()],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $apartmentId = (int) ($input['apartment_id'] ?? 0);
        $roomId = (int) ($input['room_id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $priority = $input['priority'] ?? 'medium';
        $status = $input['status'] ?? 'open';
        $reportedDate = trim($input['reported_date'] ?? '');
        $completedDate = trim($input['completed_date'] ?? '');
        $cost = (float) ($input['cost'] ?? 0);
        $assignedTo = trim($input['assigned_to'] ?? '');

        if ($id <= 0 || $apartmentId <= 0 || $title === '' || $reportedDate === '') {
            jsonResponse(false, 'Valid ID, apartment, title, and reported date are required.', [], 400);
        }

        if (!in_array($priority, $validPriorities, true) || !in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid priority or status.', [], 400);
        }

        if ($status === 'completed' && $completedDate === '') {
            $completedDate = date('Y-m-d');
        }

        $stmt = $db->prepare(
            'UPDATE maintenance_requests
             SET apartment_id = ?, room_id = ?, title = ?, description = ?, priority = ?,
                 status = ?, reported_date = ?, completed_date = ?, cost = ?, assigned_to = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $apartmentId,
            $roomId > 0 ? $roomId : null,
            $title,
            $description !== '' ? $description : null,
            $priority,
            $status,
            $reportedDate,
            $completedDate !== '' ? $completedDate : null,
            $cost,
            $assignedTo !== '' ? $assignedTo : null,
            $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Maintenance request not found or no changes made.', [], 404);
        }

        if ($roomId > 0) {
            if ($status === 'in_progress') {
                $db->prepare('UPDATE rooms SET status = "maintenance" WHERE id = ?')->execute([$roomId]);
            } elseif (in_array($status, ['completed', 'cancelled'], true)) {
                $activeLease = $db->prepare('SELECT COUNT(*) FROM leases WHERE room_id = ? AND status = "active"');
                $activeLease->execute([$roomId]);
                $newStatus = ((int) $activeLease->fetchColumn() > 0) ? 'occupied' : 'available';
                $db->prepare('UPDATE rooms SET status = ? WHERE id = ?')->execute([$newStatus, $roomId]);
            }
        }

        jsonResponse(true, 'Maintenance request updated.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid request ID is required.', [], 400);
        }

        $stmt = $db->prepare('DELETE FROM maintenance_requests WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Maintenance request not found.', [], 404);
        }

        jsonResponse(true, 'Maintenance request deleted.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
