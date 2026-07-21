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
requirePermission(permissionForMethod('leases', $method));
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$tenantId = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

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

function syncRoomStatus(PDO $db, int $roomId): void
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM leases WHERE room_id = ? AND status = "active"');
    $stmt->execute([$roomId]);
    $hasActive = (int) $stmt->fetchColumn() > 0;

    $update = $db->prepare('UPDATE rooms SET status = ? WHERE id = ?');
    $update->execute([$hasActive ? 'occupied' : 'available', $roomId]);
}

function roomHasActiveLease(PDO $db, int $roomId, int $excludeLeaseId = 0): bool
{
    $sql = 'SELECT COUNT(*) FROM leases WHERE room_id = ? AND status = "active"';
    $params = [$roomId];

    if ($excludeLeaseId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $excludeLeaseId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

$validStatuses = ['active', 'expired', 'terminated'];

try {
    $db = getDB();

    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $db->prepare(
                'SELECT l.*, t.full_name AS tenant_name, r.room_number, a.name AS apartment_name
                 FROM leases l
                 JOIN tenants t ON t.id = l.tenant_id
                 JOIN rooms r ON r.id = l.room_id
                 JOIN apartments a ON a.id = r.apartment_id
                 WHERE l.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $lease = $stmt->fetch();

            if (!$lease) {
                jsonResponse(false, 'Lease not found.', [], 404);
            }

            jsonResponse(true, 'Lease loaded.', ['lease' => $lease]);
        }

        $sql = 'SELECT l.*, t.full_name AS tenant_name, r.room_number, a.name AS apartment_name
                FROM leases l
                JOIN tenants t ON t.id = l.tenant_id
                JOIN rooms r ON r.id = l.room_id
                JOIN apartments a ON a.id = r.apartment_id
                WHERE 1=1';
        $params = [];

        if ($tenantId > 0) {
            $sql .= ' AND l.tenant_id = ?';
            $params[] = $tenantId;
        }

        if ($statusFilter !== '' && in_array($statusFilter, $validStatuses, true)) {
            $sql .= ' AND l.status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY l.start_date DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        jsonResponse(true, 'Leases loaded.', ['leases' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $roomId = (int) ($input['room_id'] ?? 0);
        $startDate = trim($input['start_date'] ?? '');
        $endDate = trim($input['end_date'] ?? '');
        $monthlyRent = (float) ($input['monthly_rent'] ?? 0);
        $depositAmount = (float) ($input['deposit_amount'] ?? 0);
        $status = $input['status'] ?? 'active';
        $notes = trim($input['notes'] ?? '');

        if ($tenantId <= 0 || $roomId <= 0 || $startDate === '') {
            jsonResponse(false, 'Tenant, room, and start date are required.', [], 400);
        }

        if (!in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid lease status.', [], 400);
        }

        if ($status === 'active' && roomHasActiveLease($db, $roomId)) {
            jsonResponse(false, 'This room already has an active lease.', [], 409);
        }

        $deposit = $db->prepare('SELECT a.rent_deposit_amount FROM rooms r JOIN apartments a ON a.id = r.apartment_id WHERE r.id = ? LIMIT 1');
        $deposit->execute([$roomId]);
        $depositAmount = (float) ($deposit->fetchColumn() ?: 0);

        $stmt = $db->prepare(
            'INSERT INTO leases (tenant_id, room_id, start_date, end_date, monthly_rent, deposit_amount, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            $roomId,
            $startDate,
            $endDate !== '' ? $endDate : null,
            $monthlyRent,
            $depositAmount,
            $status,
            $notes !== '' ? $notes : null,
        ]);

        syncRoomStatus($db, $roomId);

        jsonResponse(true, 'Lease created successfully.', [
            'lease' => ['id' => (int) $db->lastInsertId()],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $roomId = (int) ($input['room_id'] ?? 0);
        $startDate = trim($input['start_date'] ?? '');
        $endDate = trim($input['end_date'] ?? '');
        $monthlyRent = (float) ($input['monthly_rent'] ?? 0);
        $depositAmount = (float) ($input['deposit_amount'] ?? 0);
        $status = $input['status'] ?? 'active';
        $notes = trim($input['notes'] ?? '');

        if ($id <= 0 || $tenantId <= 0 || $roomId <= 0 || $startDate === '') {
            jsonResponse(false, 'Valid ID, tenant, room, and start date are required.', [], 400);
        }

        if (!in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid lease status.', [], 400);
        }

        $current = $db->prepare('SELECT room_id, deposit_amount FROM leases WHERE id = ? LIMIT 1');
        $current->execute([$id]);
        $existing = $current->fetch();

        if (!$existing) {
            jsonResponse(false, 'Lease not found.', [], 404);
        }

        // A lease keeps the one-time deposit set when it was created.
        $depositAmount = (float) $existing['deposit_amount'];

        if ($status === 'active' && roomHasActiveLease($db, $roomId, $id)) {
            jsonResponse(false, 'This room already has an active lease.', [], 409);
        }

        $stmt = $db->prepare(
            'UPDATE leases
             SET tenant_id = ?, room_id = ?, start_date = ?, end_date = ?,
                 monthly_rent = ?, deposit_amount = ?, status = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $tenantId,
            $roomId,
            $startDate,
            $endDate !== '' ? $endDate : null,
            $monthlyRent,
            $depositAmount,
            $status,
            $notes !== '' ? $notes : null,
            $id,
        ]);

        syncRoomStatus($db, (int) $existing['room_id']);
        if ((int) $existing['room_id'] !== $roomId) {
            syncRoomStatus($db, $roomId);
        }

        jsonResponse(true, 'Lease updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid lease ID is required.', [], 400);
        }

        $current = $db->prepare('SELECT room_id FROM leases WHERE id = ? LIMIT 1');
        $current->execute([$id]);
        $existing = $current->fetch();

        if (!$existing) {
            jsonResponse(false, 'Lease not found.', [], 404);
        }

        $check = $db->prepare('SELECT COUNT(*) FROM payments WHERE lease_id = ?');
        $check->execute([$id]);
        if ((int) $check->fetchColumn() > 0) {
            jsonResponse(false, 'Cannot delete lease with recorded payments.', [], 409);
        }

        $stmt = $db->prepare('DELETE FROM leases WHERE id = ?');
        $stmt->execute([$id]);

        syncRoomStatus($db, (int) $existing['room_id']);

        jsonResponse(true, 'Lease deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
