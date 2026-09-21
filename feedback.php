<?php
session_start();

$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function feedbackResponse(string $status, bool $isAjaxRequest): void
{
    if ($isAjaxRequest) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $status === 'success',
            'status' => $status
        ]);
        exit;
    }

    header('Location: home.html#feedback&feedback=' . $status);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    feedbackResponse('invalid', $isAjaxRequest);
}

$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
$message = trim($_POST['message'] ?? '');
$guestName = trim($_POST['guest_name'] ?? '');
$guestEmail = strtolower(trim($_POST['guest_email'] ?? ''));

if (!$rating || $rating < 1 || $rating > 5 || $message === '' || strlen($message) > 2000) {
    feedbackResponse('invalid', $isAjaxRequest);
}

if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    feedbackResponse('invalid', $isAjaxRequest);
}

$fullName = $_SESSION['user_fullname'] ?? $guestName;
$email = $_SESSION['user_email'] ?? $guestEmail;
$userId = $_SESSION['user_id'] ?? null;

if ($fullName === '') {
    $fullName = 'Guest customer';
}

$conn = new mysqli('localhost', 'root', '', 'rental_truck');
if ($conn->connect_error) {
    feedbackResponse('error', $isAjaxRequest);
}

$conn->query("CREATE TABLE IF NOT EXISTS feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NULL,
    rating TINYINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Older installations may already have a feedback table with a legacy `id` key.
$feedbackColumns = [
    'user_id' => 'ALTER TABLE feedback ADD COLUMN user_id INT NULL',
    'full_name' => 'ALTER TABLE feedback ADD COLUMN full_name VARCHAR(120) NULL',
    'name' => 'ALTER TABLE feedback ADD COLUMN name VARCHAR(120) NULL',
    'rating' => 'ALTER TABLE feedback ADD COLUMN rating TINYINT UNSIGNED NULL'
];
foreach ($feedbackColumns as $columnName => $migration) {
    $columnCheck = $conn->query("SHOW COLUMNS FROM feedback LIKE '" . $conn->real_escape_string($columnName) . "'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query($migration);
    }
}

$stmt = $conn->prepare('INSERT INTO feedback (user_id, full_name, name, email, rating, message) VALUES (?, ?, ?, ?, ?, ?)');
if (!$stmt) {
    $conn->close();
    feedbackResponse('error', $isAjaxRequest);
}
$stmt->bind_param('isssis', $userId, $fullName, $fullName, $email, $rating, $message);
$success = $stmt->execute();
$stmt->close();
$conn->close();

feedbackResponse($success ? 'success' : 'error', $isAjaxRequest);