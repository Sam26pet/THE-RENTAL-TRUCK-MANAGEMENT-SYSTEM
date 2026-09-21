<?php
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'authenticated' => false,
        'error' => 'Please log in before booking a truck.'
    ]);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user' => [
        'fullname' => $_SESSION['user_fullname'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'mobile' => $_SESSION['user_mobile'] ?? ''
    ]
]);