<?php
$modules = [
    'apartments.php' => 'apartments',
    'rooms.php' => 'rooms',
    'tenants.php' => 'tenants',
    'leases.php' => 'leases',
    'payments.php' => 'payments',
    'maintenance.php' => 'maintenance',
];

$dir = __DIR__ . '/api';

foreach ($modules as $file => $module) {
    $path = $dir . '/' . $file;
    $c = file_get_contents($path);

    $c = str_replace(
        "header('Access-Control-Allow-Origin: http://localhost');\\nheader('Access-Control-Allow-Credentials: true');",
        "header('Access-Control-Allow-Origin: http://localhost');\nheader('Access-Control-Allow-Credentials: true');",
        $c
    );

    $c = str_replace(
        "header('Access-Control-Allow-Origin: *');",
        "header('Access-Control-Allow-Origin: http://localhost');\nheader('Access-Control-Allow-Credentials: true');",
        $c
    );

    $c = str_replace(
        "require_once __DIR__ . '/../config/database.php';",
        "require_once __DIR__ . '/../config/auth.php';",
        $c
    );

    if (strpos($c, 'requirePermission(') === false) {
        $c = str_replace(
            "\$method = \$_SERVER['REQUEST_METHOD'];",
            "\$method = \$_SERVER['REQUEST_METHOD'];\nrequirePermission(permissionForMethod('$module', \$method));",
            $c
        );
    }

    file_put_contents($path, $c);
    echo "Fixed $file\n";
}
