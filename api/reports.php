<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/validation.php';

$method = $_SERVER['REQUEST_METHOD'];
$mode = isset($_GET['mode']) ? trim($_GET['mode']) : 'summary';
$format = isset($_GET['format']) ? trim($_GET['format']) : 'json';

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

function sendCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function fetchCustomReport(PDO $db, string $entity, string $fromDate, string $toDate): array
{
    $validEntities = ['apartments', 'rooms', 'tenants', 'leases', 'payments', 'maintenance'];
    if (!in_array($entity, $validEntities, true)) {
        return ['error' => 'Invalid report entity.'];
    }

    switch ($entity) {
        case 'apartments':
            $stmt = $db->prepare(
                'SELECT a.id, a.name, a.address, a.city, a.total_floors, a.is_active, a.created_at,
                        COUNT(r.id) AS room_count,
                        SUM(CASE WHEN r.status = "occupied" THEN 1 ELSE 0 END) AS occupied_rooms
                 FROM apartments a
                 LEFT JOIN rooms r ON r.apartment_id = a.id
                 WHERE DATE(a.created_at) BETWEEN ? AND ?
                 GROUP BY a.id, a.name, a.address, a.city, a.total_floors, a.is_active, a.created_at
                 ORDER BY a.name ASC'
            );
            $stmt->execute([$fromDate, $toDate]);
            return ['entity' => $entity, 'rows' => $stmt->fetchAll()];

        case 'rooms':
            $stmt = $db->prepare(
                'SELECT r.id, r.room_number, r.floor, r.rent_amount, r.bedrooms, r.bathrooms,
                        r.status, r.listed_date, r.created_at, a.name AS apartment_name
                 FROM rooms r
                 JOIN apartments a ON a.id = r.apartment_id
                 WHERE COALESCE(r.listed_date, DATE(r.created_at)) BETWEEN ? AND ?
                 ORDER BY a.name ASC, r.room_number ASC'
            );
            $stmt->execute([$fromDate, $toDate]);
            return ['entity' => $entity, 'rows' => $stmt->fetchAll()];

        case 'tenants':
            $stmt = $db->prepare(
                'SELECT t.id, t.full_name, t.phone, t.email, t.id_number, t.is_active,
                        t.registered_date, t.created_at,
                        (SELECT COUNT(*) FROM leases l WHERE l.tenant_id = t.id AND l.status = "active") AS active_leases
                 FROM tenants t
                 WHERE COALESCE(t.registered_date, DATE(t.created_at)) BETWEEN ? AND ?
                 ORDER BY t.full_name ASC'
            );
            $stmt->execute([$fromDate, $toDate]);
            return ['entity' => $entity, 'rows' => $stmt->fetchAll()];

        case 'leases':
            $stmt = $db->prepare(
                'SELECT l.id, l.start_date, l.end_date, l.monthly_rent, l.deposit_amount, l.status,
                        t.full_name AS tenant_name, r.room_number, a.name AS apartment_name
                 FROM leases l
                 JOIN tenants t ON t.id = l.tenant_id
                 JOIN rooms r ON r.id = l.room_id
                 JOIN apartments a ON a.id = r.apartment_id
                 WHERE l.start_date BETWEEN ? AND ?
                 ORDER BY l.start_date DESC'
            );
            $stmt->execute([$fromDate, $toDate]);
            return ['entity' => $entity, 'rows' => $stmt->fetchAll()];

        case 'payments':
            $stmt = $db->prepare(
                'SELECT p.id, p.amount, p.payment_date, p.payment_method, p.status, p.reference_number,
                        t.full_name AS tenant_name, r.room_number, a.name AS apartment_name
                 FROM payments p
                 JOIN tenants t ON t.id = p.tenant_id
                 JOIN leases l ON l.id = p.lease_id
                 JOIN rooms r ON r.id = l.room_id
                 JOIN apartments a ON a.id = r.apartment_id
                 WHERE p.payment_date BETWEEN ? AND ?
                 ORDER BY p.payment_date DESC'
            );
            $stmt->execute([$fromDate, $toDate]);
            return ['entity' => $entity, 'rows' => $stmt->fetchAll()];

        case 'maintenance':
            $stmt = $db->prepare(
                'SELECT m.id, m.title, m.priority, m.status, m.reported_date, m.completed_date, m.cost,
                        a.name AS apartment_name, r.room_number
                 FROM maintenance_requests m
                 LEFT JOIN apartments a ON a.id = m.apartment_id
                 LEFT JOIN rooms r ON r.id = m.room_id
                 WHERE m.reported_date BETWEEN ? AND ?
                 ORDER BY m.reported_date DESC'
            );
            $stmt->execute([$fromDate, $toDate]);
            return ['entity' => $entity, 'rows' => $stmt->fetchAll()];
    }

    return ['error' => 'Unknown entity.'];
}

try {
    $db = getDB();

    if ($method === 'POST') {
        requirePermission('reports.generate');
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $entity = trim($input['entity'] ?? '');
        $period = trim($input['period'] ?? 'monthly');
        $user = requireLogin();

        $validEntities = ['apartments', 'rooms', 'tenants', 'leases', 'payments', 'maintenance'];
        $validPeriods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];

        if ($name === '' || !in_array($entity, $validEntities, true) || !in_array($period, $validPeriods, true)) {
            jsonResponse(false, 'Name, entity, and valid period are required.', [], 400);
        }

        $stmt = $db->prepare(
            'INSERT INTO report_schedules (name, entity, period, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $entity, $period, (int) $user['id']]);

        jsonResponse(true, 'Periodic report schedule saved.', [
            'schedule' => ['id' => (int) $db->lastInsertId()],
        ], 201);
    }

    if ($method === 'DELETE') {
        requirePermission('reports.generate');
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0) {
            jsonResponse(false, 'Valid schedule ID is required.', [], 400);
        }
        $stmt = $db->prepare('DELETE FROM report_schedules WHERE id = ?');
        $stmt->execute([$id]);
        jsonResponse(true, 'Report schedule deleted.');
    }

    requirePermission($mode === 'custom' || $format === 'csv' ? 'reports.generate' : 'reports.view');

    if ($mode === 'schedules') {
        $schedules = $db->query(
            'SELECT rs.*, u.full_name AS created_by_name
             FROM report_schedules rs
             LEFT JOIN users u ON u.id = rs.created_by
             WHERE rs.is_active = 1
             ORDER BY rs.name ASC'
        )->fetchAll();
        jsonResponse(true, 'Schedules loaded.', ['schedules' => $schedules]);
    }

    if ($mode === 'custom') {
        $entity = trim($_GET['entity'] ?? 'payments');
        $period = trim($_GET['period'] ?? 'monthly');
        $fromDate = trim($_GET['from'] ?? '');
        $toDate = trim($_GET['to'] ?? '');

        $range = resolveReportDateRange($period, $fromDate !== '' ? $fromDate : null, $toDate !== '' ? $toDate : null);
        $fromDate = $range['from'];
        $toDate = $range['to'];

        $fromError = validateNotFutureDate($fromDate, 'Start date');
        $toError = validateNotFutureDate($toDate, 'End date');
        if ($fromError !== null || $toError !== null) {
            jsonResponse(false, $fromError ?? $toError, [], 400);
        }

        if ($fromDate > $toDate) {
            jsonResponse(false, 'Start date must be on or before end date.', [], 400);
        }

        $result = fetchCustomReport($db, $entity, $fromDate, $toDate);
        if (isset($result['error'])) {
            jsonResponse(false, $result['error'], [], 400);
        }

        $rows = $result['rows'];

        if ($format === 'csv' && count($rows) > 0) {
            $headers = array_keys($rows[0]);
            $csvRows = array_map(function ($row) {
                return array_values($row);
            }, $rows);
            sendCsv($entity . '_report_' . $fromDate . '_to_' . $toDate . '.csv', $headers, $csvRows);
        }

        jsonResponse(true, 'Custom report generated.', [
            'entity' => $entity,
            'period' => $period,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'row_count' => count($rows),
            'rows' => $rows,
        ]);
    }

    $summary = [
        'apartments' => (int) $db->query('SELECT COUNT(*) FROM apartments')->fetchColumn(),
        'rooms' => (int) $db->query('SELECT COUNT(*) FROM rooms')->fetchColumn(),
        'rooms_occupied' => (int) $db->query('SELECT COUNT(*) FROM rooms WHERE status = "occupied"')->fetchColumn(),
        'rooms_available' => (int) $db->query('SELECT COUNT(*) FROM rooms WHERE status = "available"')->fetchColumn(),
        'rooms_maintenance' => (int) $db->query('SELECT COUNT(*) FROM rooms WHERE status = "maintenance"')->fetchColumn(),
        'tenants' => (int) $db->query('SELECT COUNT(*) FROM tenants WHERE is_active = 1')->fetchColumn(),
        'active_leases' => (int) $db->query('SELECT COUNT(*) FROM leases WHERE status = "active"')->fetchColumn(),
        'monthly_rent_expected' => (float) $db->query('SELECT COALESCE(SUM(monthly_rent), 0) FROM leases WHERE status = "active"')->fetchColumn(),
        'payments_paid' => (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "paid"')->fetchColumn(),
        'payments_pending' => (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "pending"')->fetchColumn(),
        'payments_overdue' => (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "overdue"')->fetchColumn(),
        'open_maintenance' => (int) $db->query('SELECT COUNT(*) FROM maintenance_requests WHERE status IN ("open", "in_progress")')->fetchColumn(),
    ];

    $roomsTotal = max(1, $summary['rooms']);
    $summary['occupancy_rate'] = round(($summary['rooms_occupied'] / $roomsTotal) * 100, 1);

    $monthly = $db->query(
        'SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month,
                COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = "overdue" THEN amount ELSE 0 END), 0) AS overdue
         FROM payments
         GROUP BY DATE_FORMAT(payment_date, "%Y-%m")
         ORDER BY month DESC
         LIMIT 6'
    )->fetchAll();

    $occupancyByApartment = $db->query(
        'SELECT a.id, a.name,
                COUNT(r.id) AS total_rooms,
                SUM(CASE WHEN r.status = "occupied" THEN 1 ELSE 0 END) AS occupied_rooms,
                SUM(CASE WHEN r.status = "available" THEN 1 ELSE 0 END) AS available_rooms
         FROM apartments a
         LEFT JOIN rooms r ON r.apartment_id = a.id
         GROUP BY a.id, a.name
         ORDER BY a.name ASC'
    )->fetchAll();

    $leaseStatus = $db->query(
        'SELECT status, COUNT(*) AS total FROM leases GROUP BY status'
    )->fetchAll();

    $recentPayments = $db->query(
        'SELECT p.id, p.amount, p.payment_date, p.status, p.payment_method,
                t.full_name AS tenant_name, r.room_number, a.name AS apartment_name
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         JOIN leases l ON l.id = p.lease_id
         JOIN rooms r ON r.id = l.room_id
         JOIN apartments a ON a.id = r.apartment_id
         ORDER BY p.payment_date DESC
         LIMIT 10'
    )->fetchAll();

    $overdueList = $db->query(
        'SELECT p.id, p.amount, p.payment_date, p.status,
                t.full_name AS tenant_name, t.phone, r.room_number, a.name AS apartment_name
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         JOIN leases l ON l.id = p.lease_id
         JOIN rooms r ON r.id = l.room_id
         JOIN apartments a ON a.id = r.apartment_id
         WHERE p.status IN ("overdue", "pending")
         ORDER BY p.payment_date ASC'
    )->fetchAll();

    $expiringLeases = $db->query(
        'SELECT l.id, l.end_date, l.monthly_rent, t.full_name AS tenant_name,
                r.room_number, a.name AS apartment_name
         FROM leases l
         JOIN tenants t ON t.id = l.tenant_id
         JOIN rooms r ON r.id = l.room_id
         JOIN apartments a ON a.id = r.apartment_id
         WHERE l.status = "active" AND l.end_date IS NOT NULL
           AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
         ORDER BY l.end_date ASC'
    )->fetchAll();

    jsonResponse(true, 'Reports loaded.', [
        'summary' => $summary,
        'monthly_collections' => array_reverse($monthly),
        'occupancy_by_apartment' => $occupancyByApartment,
        'lease_status' => $leaseStatus,
        'recent_payments' => $recentPayments,
        'outstanding_payments' => $overdueList,
        'expiring_leases' => $expiringLeases,
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
