<?php
$menuItems = [
    'dashboard.html' => ['dashboard.view', 'fa-gauge', 'Dashboard'],
    'apartments.html' => ['apartments.view', 'fa-building-user', 'Apartments'],
    'rooms.html' => ['rooms.view', 'fa-door-open', 'Rooms'],
    'tenants.html' => ['tenants.view', 'fa-users', 'Tenants'],
    'leases.html' => ['leases.view', 'fa-file-contract', 'Leases'],
    'payments.html' => ['payments.view', 'fa-money-bill-wave', 'Payments'],
    'maintenance.html' => ['maintenance.view', 'fa-screwdriver-wrench', 'Maintenance'],
    'reports.html' => ['reports.view', 'fa-chart-column', 'Reports'],
    'users.html' => ['users.view', 'fa-user-shield', 'Users'],
    'settings.html' => ['settings.view', 'fa-gear', 'Settings'],
];

function buildMenu(string $active): string
{
    global $menuItems;
    $html = '';
    foreach ($menuItems as $href => [$perm, $icon, $label]) {
        $cls = $href === $active ? ' class="active"' : '';
        $html .= "<a href=\"{$href}\"{$cls} data-perm=\"{$perm}\"><i class=\"fa-solid {$icon}\"></i><span>{$label}</span></a>\n";
    }
    $html .= "</div>\n<div class=\"sidebar-footer\">\n";
    $html .= '<a href="#" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>';
    return $html;
}

$files = [
    'dashboard.html',
    'apartments.html',
    'rooms.html',
    'tenants.html',
    'leases.html',
    'payments.html',
    'maintenance.html',
    'reports.html',
    'settings.html',
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    $html = file_get_contents($path);

    if (!preg_match('/<div class="menu">([\s\S]*?)<\/div>\s*(?:<!-- Topbar -->|<div class="topbar")/', $html, $m)) {
        echo "SKIP pattern: $file\n";
        continue;
    }

    $newInner = "\n" . buildMenu($file) . "\n";
    $replacement = '<div class="menu">' . $newInner . '</div>' . "\n</div>";

    // Replace the menu div contents carefully
    $html = preg_replace(
        '/<div class="menu">[\s\S]*?<\/div>(\s*)(?:<\/div>\s*)?(<!-- Topbar -->|<div class="topbar")/',
        $replacement . '$1$2',
        $html,
        1,
        $count
    );

    if ($count) {
        file_put_contents($path, $html);
        echo "Updated menu: $file\n";
    } else {
        echo "FAILED: $file\n";
    }

    // Add data-page-perm on body if missing
    $pagePermMap = [
        'dashboard.html' => 'dashboard.view',
        'apartments.html' => 'apartments.view',
        'rooms.html' => 'rooms.view',
        'tenants.html' => 'tenants.view',
        'leases.html' => 'leases.view',
        'payments.html' => 'payments.view',
        'maintenance.html' => 'maintenance.view',
        'reports.html' => 'reports.view',
        'settings.html' => 'settings.view',
    ];

    if (isset($pagePermMap[$file]) && strpos($html, 'data-page-perm') === false) {
        $html = file_get_contents($path);
        $html = str_replace('<body>', '<body data-page-perm="' . $pagePermMap[$file] . '">', $html);
        $html = str_replace('<body>', '<body data-page-perm="' . $pagePermMap[$file] . '">', $html);
        // dashboard might already be body without attrs
        if (strpos($html, 'data-page-perm') === false) {
            $html = preg_replace('/<body([^>]*)>/', '<body$1 data-page-perm="' . $pagePermMap[$file] . '">', $html, 1);
        }
        file_put_contents($path, $html);
        echo "Added page perm: $file\n";
    }
}
