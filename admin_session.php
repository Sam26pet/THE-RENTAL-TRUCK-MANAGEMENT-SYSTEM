<?php
require_once __DIR__ . '/admin_access.php';
startAdminSession();
header('Content-Type: application/json');
echo json_encode([
    'authenticated' => !empty($_SESSION['admin_id']),
    'admin_name' => $_SESSION['admin_name'] ?? null
]);
