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
requirePermission(permissionForMethod('payments', $method));
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$leaseId = isset($_GET['lease_id']) ? (int) $_GET['lease_id'] : 0;
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

$validStatuses = ['paid', 'pending', 'overdue'];
$validMethods = ['cash', 'mpesa', 'bank', 'card'];

try {
    $db = getDB();

    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $db->prepare(
                'SELECT p.*, t.full_name AS tenant_name, r.room_number, a.name AS apartment_name,
                        l.deposit_amount,
                        COALESCE((SELECT SUM(rd.amount_paid) FROM rent_deposits rd WHERE rd.lease_id = l.id), 0) AS deposit_paid,
                        GREATEST(l.deposit_amount - COALESCE((SELECT SUM(rd.amount_paid) FROM rent_deposits rd WHERE rd.lease_id = l.id), 0), 0) AS deposit_balance
                 FROM payments p
                 JOIN tenants t ON t.id = p.tenant_id
                 JOIN leases l ON l.id = p.lease_id
                 JOIN rooms r ON r.id = l.room_id
                 JOIN apartments a ON a.id = r.apartment_id
                 WHERE p.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $payment = $stmt->fetch();

            if (!$payment) {
                jsonResponse(false, 'Payment not found.', [], 404);
            }

            jsonResponse(true, 'Payment loaded.', ['payment' => $payment]);
        }

        $sql = 'SELECT p.*, t.full_name AS tenant_name, r.room_number, a.name AS apartment_name,
                       l.deposit_amount,
                       COALESCE((SELECT SUM(rd.amount_paid) FROM rent_deposits rd WHERE rd.lease_id = l.id), 0) AS deposit_paid,
                       GREATEST(l.deposit_amount - COALESCE((SELECT SUM(rd.amount_paid) FROM rent_deposits rd WHERE rd.lease_id = l.id), 0), 0) AS deposit_balance
                FROM payments p
                JOIN tenants t ON t.id = p.tenant_id
                JOIN leases l ON l.id = p.lease_id
                JOIN rooms r ON r.id = l.room_id
                JOIN apartments a ON a.id = r.apartment_id
                WHERE 1=1';
        $params = [];

        if ($leaseId > 0) {
            $sql .= ' AND p.lease_id = ?';
            $params[] = $leaseId;
        }

        if ($tenantId > 0) {
            $sql .= ' AND p.tenant_id = ?';
            $params[] = $tenantId;
        }

        if ($statusFilter !== '' && in_array($statusFilter, $validStatuses, true)) {
            $sql .= ' AND p.status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY p.payment_date DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        jsonResponse(true, 'Payments loaded.', ['payments' => $stmt->fetchAll()]);
    }

    if ($method === 'POST') {
        $input = getInput();
        $leaseId = (int) ($input['lease_id'] ?? 0);
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $amount = (float) ($input['amount'] ?? 0);
        $paymentDate = trim($input['payment_date'] ?? '');
        $paymentMethod = $input['payment_method'] ?? 'cash';
        $status = $input['status'] ?? 'paid';
        $referenceNumber = trim($input['reference_number'] ?? '');
        $notes = trim($input['notes'] ?? '');

        if ($leaseId <= 0 || $tenantId <= 0 || $paymentDate === '' || $amount <= 0) {
            jsonResponse(false, 'Lease, tenant, date, and amount are required.', [], 400);
        }

        $dateErr = validateNotFutureDate($paymentDate, 'Payment date');
        if ($dateErr !== null) {
            jsonResponse(false, $dateErr, [], 400);
        }

        if (!in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid payment status.', [], 400);
        }

        if (!in_array($paymentMethod, $validMethods, true)) {
            jsonResponse(false, 'Invalid payment method.', [], 400);
        }

        $lease = $db->prepare('SELECT tenant_id FROM leases WHERE id = ? LIMIT 1');
        $lease->execute([$leaseId]);
        $leaseRow = $lease->fetch();

        if (!$leaseRow) {
            jsonResponse(false, 'Selected lease does not exist.', [], 404);
        }

        if ((int) $leaseRow['tenant_id'] !== $tenantId) {
            jsonResponse(false, 'Tenant does not match the selected lease.', [], 400);
        }

        $stmt = $db->prepare(
            'INSERT INTO payments (lease_id, tenant_id, amount, payment_date, payment_method, status, reference_number, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $leaseId,
            $tenantId,
            $amount,
            $paymentDate,
            $paymentMethod,
            $status,
            $referenceNumber !== '' ? $referenceNumber : null,
            $notes !== '' ? $notes : null,
        ]);

        jsonResponse(true, 'Payment recorded successfully.', [
            'payment' => ['id' => (int) $db->lastInsertId()],
        ], 201);
    }

    if ($method === 'PUT') {
        $input = getInput();
        $id = (int) ($input['id'] ?? 0);
        $leaseId = (int) ($input['lease_id'] ?? 0);
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $amount = (float) ($input['amount'] ?? 0);
        $paymentDate = trim($input['payment_date'] ?? '');
        $paymentMethod = $input['payment_method'] ?? 'cash';
        $status = $input['status'] ?? 'paid';
        $referenceNumber = trim($input['reference_number'] ?? '');
        $notes = trim($input['notes'] ?? '');

        if ($id <= 0 || $leaseId <= 0 || $tenantId <= 0 || $paymentDate === '' || $amount <= 0) {
            jsonResponse(false, 'Valid ID, lease, tenant, date, and amount are required.', [], 400);
        }

        $dateErr = validateNotFutureDate($paymentDate, 'Payment date');
        if ($dateErr !== null) {
            jsonResponse(false, $dateErr, [], 400);
        }

        if (!in_array($status, $validStatuses, true)) {
            jsonResponse(false, 'Invalid payment status.', [], 400);
        }

        if (!in_array($paymentMethod, $validMethods, true)) {
            jsonResponse(false, 'Invalid payment method.', [], 400);
        }

        $stmt = $db->prepare(
            'UPDATE payments
             SET lease_id = ?, tenant_id = ?, amount = ?, payment_date = ?,
                 payment_method = ?, status = ?, reference_number = ?, notes = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $leaseId,
            $tenantId,
            $amount,
            $paymentDate,
            $paymentMethod,
            $status,
            $referenceNumber !== '' ? $referenceNumber : null,
            $notes !== '' ? $notes : null,
            $id,
        ]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Payment not found or no changes made.', [], 404);
        }

        jsonResponse(true, 'Payment updated successfully.');
    }

    if ($method === 'DELETE') {
        if ($id <= 0) {
            jsonResponse(false, 'Valid payment ID is required.', [], 400);
        }

        $stmt = $db->prepare('DELETE FROM payments WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Payment not found.', [], 404);
        }

        jsonResponse(true, 'Payment deleted successfully.');
    }

    jsonResponse(false, 'Method not allowed.', [], 405);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
