<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/auth.php';

requirePermission('dashboard.view');

function jsonResponse(bool $success, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed.', [], 405);
}

try {
    $db = getDB();

    $summary = [
        'apartments' => (int) $db->query('SELECT COUNT(*) FROM apartments')->fetchColumn(),
        'rooms' => (int) $db->query('SELECT COUNT(*) FROM rooms')->fetchColumn(),
        'rooms_occupied' => (int) $db->query('SELECT COUNT(*) FROM rooms WHERE status = "occupied"')->fetchColumn(),
        'rooms_available' => (int) $db->query('SELECT COUNT(*) FROM rooms WHERE status = "available"')->fetchColumn(),
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

    $currentMonth = date('Y-m');
    $stmtMonth = $db->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = "paid" AND DATE_FORMAT(payment_date, "%Y-%m") = ?'
    );
    $stmtMonth->execute([$currentMonth]);
    $summary['collected_this_month'] = (float) $stmtMonth->fetchColumn();
    $summary['outstanding_total'] = $summary['payments_pending'] + $summary['payments_overdue'];

    $monthly = $db->query(
        'SELECT DATE_FORMAT(payment_date, "%Y-%m") AS month,
                COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN status = "pending" THEN amount ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = "overdue" THEN amount ELSE 0 END), 0) AS overdue
         FROM payments
         GROUP BY DATE_FORMAT(payment_date, "%Y-%m")
         ORDER BY month DESC
         LIMIT 7'
    )->fetchAll();

    $occupancyByApartment = $db->query(
        'SELECT a.id, a.name,
                COUNT(r.id) AS total_rooms,
                SUM(CASE WHEN r.status = "occupied" THEN 1 ELSE 0 END) AS occupied_rooms
         FROM apartments a
         LEFT JOIN rooms r ON r.apartment_id = a.id
         GROUP BY a.id, a.name
         ORDER BY a.name ASC
         LIMIT 8'
    )->fetchAll();

    $recentPayments = $db->query(
        'SELECT p.id, p.amount, p.payment_date, p.status,
                t.full_name AS tenant_name, r.room_number, a.name AS apartment_name
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         JOIN leases l ON l.id = p.lease_id
         JOIN rooms r ON r.id = l.room_id
         JOIN apartments a ON a.id = r.apartment_id
         ORDER BY p.payment_date DESC, p.id DESC
         LIMIT 8'
    )->fetchAll();

    $activityItems = [];

    $paymentActivity = $db->query(
        'SELECT p.id, p.amount, p.status, p.updated_at,
                t.full_name AS tenant_name, r.room_number
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         JOIN leases l ON l.id = p.lease_id
         JOIN rooms r ON r.id = l.room_id
         ORDER BY p.updated_at DESC
         LIMIT 5'
    )->fetchAll();

    foreach ($paymentActivity as $row) {
        $activityItems[] = [
            'type' => 'payment',
            'icon' => 'fa-hand-holding-dollar',
            'color' => 'green',
            'title' => 'Payment ' . ($row['status'] === 'paid' ? 'received' : 'recorded') . ' — ' . $row['tenant_name'],
            'detail' => 'KES ' . number_format((float) $row['amount'], 0) . ' · Room ' . $row['room_number'],
            'timestamp' => $row['updated_at'],
        ];
    }

    $tenantActivity = $db->query(
        'SELECT id, full_name, created_at FROM tenants ORDER BY created_at DESC LIMIT 3'
    )->fetchAll();

    foreach ($tenantActivity as $row) {
        $activityItems[] = [
            'type' => 'tenant',
            'icon' => 'fa-user-plus',
            'color' => 'blue',
            'title' => 'New tenant registered — ' . $row['full_name'],
            'detail' => 'Tenant profile created',
            'timestamp' => $row['created_at'],
        ];
    }

    $leaseActivity = $db->query(
        'SELECT l.status, l.updated_at, t.full_name AS tenant_name, r.room_number
         FROM leases l
         JOIN tenants t ON t.id = l.tenant_id
         JOIN rooms r ON r.id = l.room_id
         ORDER BY l.updated_at DESC
         LIMIT 3'
    )->fetchAll();

    foreach ($leaseActivity as $row) {
        $activityItems[] = [
            'type' => 'lease',
            'icon' => 'fa-file-contract',
            'color' => 'orange',
            'title' => 'Lease ' . $row['status'] . ' — ' . $row['tenant_name'],
            'detail' => 'Room ' . $row['room_number'],
            'timestamp' => $row['updated_at'],
        ];
    }

    $maintActivity = $db->query(
        'SELECT m.title, m.status, m.updated_at, a.name AS apartment_name
         FROM maintenance_requests m
         LEFT JOIN apartments a ON a.id = m.apartment_id
         ORDER BY m.updated_at DESC
         LIMIT 3'
    )->fetchAll();

    foreach ($maintActivity as $row) {
        $activityItems[] = [
            'type' => 'maintenance',
            'icon' => 'fa-screwdriver-wrench',
            'color' => 'blue',
            'title' => 'Maintenance — ' . $row['title'],
            'detail' => ($row['apartment_name'] ?? 'Property') . ' · ' . $row['status'],
            'timestamp' => $row['updated_at'],
        ];
    }

    usort($activityItems, function ($a, $b) {
        return strtotime($b['timestamp']) <=> strtotime($a['timestamp']);
    });

    jsonResponse(true, 'Dashboard loaded.', [
        'summary' => $summary,
        'monthly_collections' => array_reverse($monthly),
        'occupancy_by_apartment' => $occupancyByApartment,
        'recent_payments' => $recentPayments,
        'recent_activity' => array_slice($activityItems, 0, 8),
        'refreshed_at' => date('c'),
    ]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error. Please try again later.', [], 500);
}
