<?php
$patches = [
    'rooms.html' => [
        ['<button class="btn btn-primary" id="addBtn"><i class="fa-solid fa-plus"></i> Add Room</button>',
         '<button class="btn btn-primary" id="addBtn" data-perm="rooms.create"><i class="fa-solid fa-plus"></i> Add Room</button>'],
        ['onclick="editRoom(${r.id})"', 'data-perm="rooms.update" onclick="editRoom(${r.id})"'],
        ['onclick="deleteRoom(${r.id}', 'data-perm="rooms.delete" onclick="deleteRoom(${r.id}'],
    ],
    'tenants.html' => [
        ['<button class="btn btn-primary" id="addBtn"><i class="fa-solid fa-plus"></i> Add Tenant</button>',
         '<button class="btn btn-primary" id="addBtn" data-perm="tenants.create"><i class="fa-solid fa-plus"></i> Add Tenant</button>'],
        ['onclick="editTenant(${t.id})"', 'data-perm="tenants.update" onclick="editTenant(${t.id})"'],
        ['onclick="deleteTenant(${t.id}', 'data-perm="tenants.delete" onclick="deleteTenant(${t.id}'],
    ],
    'leases.html' => [
        ['<button class="btn btn-primary" id="addBtn"><i class="fa-solid fa-plus"></i> Add Lease</button>',
         '<button class="btn btn-primary" id="addBtn" data-perm="leases.create"><i class="fa-solid fa-plus"></i> Add Lease</button>'],
        ['onclick="editLease(${l.id})"', 'data-perm="leases.update" onclick="editLease(${l.id})"'],
        ['onclick="deleteLease(${l.id})"', 'data-perm="leases.delete" onclick="deleteLease(${l.id})"'],
    ],
    'payments.html' => [
        ['<button class="btn btn-primary" id="addBtn"><i class="fa-solid fa-plus"></i> Record Payment</button>',
         '<button class="btn btn-primary" id="addBtn" data-perm="payments.create"><i class="fa-solid fa-plus"></i> Record Payment</button>'],
        ['onclick="editPayment(${p.id})"', 'data-perm="payments.update" onclick="editPayment(${p.id})"'],
        ['onclick="deletePayment(${p.id})"', 'data-perm="payments.delete" onclick="deletePayment(${p.id})"'],
    ],
    'maintenance.html' => [
        ['<button class="btn btn-primary" id="addBtn"><i class="fa-solid fa-plus"></i> New Request</button>',
         '<button class="btn btn-primary" id="addBtn" data-perm="maintenance.create"><i class="fa-solid fa-plus"></i> New Request</button>'],
        ['onclick="editRequest(${r.id})"', 'data-perm="maintenance.update" onclick="editRequest(${r.id})"'],
        ['onclick="deleteRequest(${r.id})"', 'data-perm="maintenance.delete" onclick="deleteRequest(${r.id})"'],
    ],
];

foreach ($patches as $file => $pairs) {
    $path = __DIR__ . '/' . $file;
    $c = file_get_contents($path);
    foreach ($pairs as [$from, $to]) {
        $c = str_replace($from, $to, $c);
    }
    // Add applyAccessControl after first table join in render
    if (strpos($c, 'applyAccessControl();') === false) {
        $c = preg_replace('/(`\)\.join\(''\');\s*\n\})/', "`).join('');\n    applyAccessControl();\n}", $c, 1);
    }
    file_put_contents($path, $c);
    echo "Patched $file\n";
}

// Settings: hide company save / billing for staff
$settings = file_get_contents(__DIR__ . '/settings.html');
$settings = str_replace(
    '<button class="btn btn-primary" id="saveSettingsBtn"',
    '<button class="btn btn-primary" id="saveSettingsBtn" data-perm="settings.update"',
    $settings
);
$settings = str_replace(
    '<div class="panel settings-card">
<h4>Company Profile</h4>',
    '<div class="panel settings-card" data-perm="settings.update">
<h4>Company Profile</h4>',
    $settings
);
$settings = str_replace(
    '<div class="panel settings-card">
<h4>Billing Preferences</h4>',
    '<div class="panel settings-card" data-perm="settings.update">
<h4>Billing Preferences</h4>',
    $settings
);
file_put_contents(__DIR__ . '/settings.html', $settings);
echo "Patched settings.html\n";
