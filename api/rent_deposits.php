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
requirePermission(permissionForMethod('rent_deposits', $method));
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

function validateDeposit(PDO $db, array $input, int $excludeDepositId = 0): array
{
    $tenantId = (int) ($input['tenant_id'] ?? 0);
    $apartmentId = (int) ($input['apartment_id'] ?? 0);
    $roomId = (int) ($input['room_id'] ?? 0);
    $amountPaid = (float) ($input['amount_paid'] ?? 0);
    $datePaid = trim($input['date_paid'] ?? '');
    $paymentMethod = $input['payment_method'] ?? 'cash';
    $referenceNumber = trim($input['reference_number'] ?? '');
    $notes = trim($input['notes'] ?? '');

    if ($tenantId <= 0 || $apartmentId <= 0 || $roomId <= 0 || $amountPaid <= 0 || $datePaid === '') {
        jsonResponse(false, 'Tenant, apartment, room, amount paid, and date paid are required.', [], 400);
    }
    $dateErr = validateNotFutureDate($datePaid, 'Date paid');
    if ($dateErr !== null) jsonResponse(false, $dateErr, [], 400);
    if (!in_array($paymentMethod, ['cash', 'mpesa', 'bank', 'card'], true)) {
        jsonResponse(false, 'Invalid payment method.', [], 400);
    }

    $lease = $db->prepare(
        'SELECT l.id, l.deposit_amount, r.apartment_id
         FROM leases l JOIN rooms r ON r.id = l.room_id
         WHERE l.tenant_id = ? AND l.room_id = ? AND l.status = "active" LIMIT 1'
    );
    $lease->execute([$tenantId, $roomId]);
    $leaseRow = $lease->fetch();
    if (!$leaseRow || (int) $leaseRow['apartment_id'] !== $apartmentId) {
        jsonResponse(false, 'Select the tenant\'s active leased room in the selected apartment.', [], 400);
    }

    $alreadyPaid = $db->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM rent_deposits WHERE lease_id = ? AND id != ?');
    $alreadyPaid->execute([(int) $leaseRow['id'], $excludeDepositId]);
    if ((float) $alreadyPaid->fetchColumn() + $amountPaid > (float) $leaseRow['deposit_amount']) {
        jsonResponse(false, 'The amount paid exceeds the remaining deposit balance.', [], 400);
    }

    return [$tenantId, $apartmentId, $roomId, (int) $leaseRow['id'], $amountPaid, $datePaid, $paymentMethod, $referenceNumber, $notes];
}

try {
    $db = getDB();

    if ($method === 'GET') {
        $sql = 'SELECT rd.*, t.full_name AS tenant_name, a.name AS apartment_name, r.room_number,
                       l.deposit_amount,
                       COALESCE((SELECT SUM(rd2.amount_paid) FROM rent_deposits rd2 WHERE rd2.lease_id = rd.lease_id), 0) AS total_deposit_paid
                FROM rent_deposits rd
                JOIN tenants t ON t.id = rd.tenant_id
                JOIN apartments a ON a.id = rd.apartment_id
                JOIN rooms r ON r.id = rd.room_id
                JOIN leases l ON l.id = rd.lease_id';
        $params = [];
        if ($id > 0) {
            $sql .= ' WHERE rd.id = ?';
            $params[] = $id;
        }
        $sql .= ' ORDER BY rd.date_paid DESC, rd.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $deposits = $stmt->fetchAll();
        if ($id > 0 && !$deposits) jsonResponse(false, 'Rent deposit not found.', [], 404);
        jsonResponse(true, 'Rent deposits loaded.', $id > 0 ? ['deposit' => $deposits[0]] : ['deposits' => $deposits]);
    }

    if ($method === 'POST') {
        [$tenantId, $apartmentId, $roomId, $leaseId, $amountPaid, $datePaid, $paymentMethod, $referenceNumber, $notes] = validateDeposit($db, getInput());
        $stmt = $db->prepare('INSERT INTO rent_deposits (lease_id, tenant_id, apartment_id, room_id, amount_paid, date_paid, payment_method, reference_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$leaseId, $tenantId, $apartmentId, $roomId, $amountPaid, $datePaid, $paymentMethod, $referenceNumber ?: null, $notes ?: null]);
        jsonResponse(true, 'Rent deposit recorded successfully.', ['deposit' => ['id' => (int) $db->lastInsertId()]], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) jsonResponse(false, 'Valid rent deposit ID is required.', [], 400);
        [$tenantId, $apartmentId, $roomId, $leaseId, $amountPaid, $datePaid, $paymentMethod, $referenceNumber, $notes] = validateDeposit($db, $input, $id);
        $stmt = $db->prepare('UPDATE rent_deposits SET lease_id = ?, tenant_id = ?, apartment_id = ?, room_id = ?, amount_paid = ?, date_paid = ?, payment_method = ?, reference_number = ?, notes = ? WHERE id = ?');
        $stmt->execute([$leaseId, $tenantId, $apartmentId, $roomId, $amountPaid, $datePaid, $paymentMethod, $referenceNumber ?: null, $notes ?: null, $id]);
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Rent deposit not found or no changes made.', [], 404);
        jsonResponse(true, 'Rent deposit updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) jsonResponse(false, 'Valid rent deposit ID is required.', [], 400);
        $stmt = $db->prepare('DELETE FROM rent_deposits WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) jsonResponse(false, 'Rent deposit not found.', [], 404);
        jsonResponse(true, 'Rent deposit deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
