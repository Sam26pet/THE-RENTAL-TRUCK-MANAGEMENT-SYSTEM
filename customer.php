<?php
session_start();

if (empty($_SESSION['user_id'])) {
    showAlertAndRedirect('Please log in before booking a truck.', 'login.php', 'Login required');
}
// ================================================================
// DATABASE CONNECTION
// ================================================================
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "rental_truck";

function showAlertAndRedirect($message, $redirect, $title = 'Booking issue') {
    echo '<script src="app-alert.js"></script>';
    echo '<script>document.addEventListener("DOMContentLoaded", function () { showAppAlert(' . json_encode($message) . ', ' . json_encode($title) . '); setTimeout(function () { window.location.href = ' . json_encode($redirect) . '; }, 1800); });</script>';
    exit;
}

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->query("DROP TABLE IF EXISTS shared_ride_members");
$conn->query("DROP TABLE IF EXISTS shared_rides");
$conn->query("ALTER TABLE booking DROP COLUMN IF EXISTS shared_ride_id");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_name VARCHAR(120) NULL AFTER cost");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_phone VARCHAR(30) NULL AFTER assigned_driver_name");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_email VARCHAR(150) NULL AFTER assigned_driver_phone");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_license VARCHAR(80) NULL AFTER assigned_driver_email");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_photo VARCHAR(255) NULL AFTER assigned_driver_license");
// Ensure bookings can read rates on an existing database created before this feature.
$conn->query("ALTER TABLE trucks ADD COLUMN IF NOT EXISTS price_per_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cargo_type");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS truck_id INT NULL AFTER booking_id");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS truck_name VARCHAR(150) NULL AFTER truck_id");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS pickup_lat DECIMAL(10,7) NULL AFTER destination");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS pickup_lng DECIMAL(10,7) NULL AFTER pickup_lat");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS destination_lat DECIMAL(10,7) NULL AFTER pickup_lng");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS destination_lng DECIMAL(10,7) NULL AFTER destination_lat");
$statusColumn = $conn->query("SHOW COLUMNS FROM booking LIKE 'booking_status'");
if ($statusColumn && $statusColumn->num_rows === 0) {
    $conn->query("ALTER TABLE booking ADD COLUMN booking_status VARCHAR(20) NOT NULL DEFAULT 'active'");
}

// ================================================================
// COLLECT FORM DATA
// ================================================================
$fullname    = $_SESSION['user_fullname'] ?? ($_POST['fullname'] ?? '');
$email       = $_SESSION['user_email'] ?? ($_POST['email'] ?? '');
$phone       = $_SESSION['user_mobile'] ?? ($_POST['phone'] ?? '');
$trip_date   = $_POST['tripDate'] ?? '';
$pickup      = $_POST['pickup'] ?? '';
$destination = $_POST['destination'] ?? '';
$distance    = $_POST['distance'] ?? 0;
$cargo_type  = trim($_POST['cargo_type'] ?? '');
$truck_id    = !empty($_POST['truck_id']) ? (int)$_POST['truck_id'] : null;
$truck_name  = $_POST['truck_name'] ?? null;
$pickup_lat = (float)($_POST['pickup_lat'] ?? 0);
$pickup_lng = (float)($_POST['pickup_lng'] ?? 0);
$destination_lat = (float)($_POST['destination_lat'] ?? 0);
$destination_lng = (float)($_POST['destination_lng'] ?? 0);
$cargoRates = [
    'Guta' => 1200,
    'Meat van' => 1800,
    'Refrigerated vans' => 2500,
    'Town ice' => 3000,
    'Heavy trucks' => 3800
];

// Validate required fields
if (empty($fullname) || empty($email) || empty($phone) || empty($trip_date) || 
    empty($pickup) || empty($destination) || empty($distance)) {
    showAlertAndRedirect('All fields are required.', 'customer.html');
}

// Re-check availability on the server so maintenance trucks cannot be booked
// by submitting a stale page or manually crafting a request.
if ($truck_id) {
    $truckStmt = $conn->prepare("SELECT status, cargo_type, price_per_km, truck_name, plate_number, capacity, fuel_type, image_url FROM trucks WHERE truck_id = ?");
    $truckStmt->bind_param("i", $truck_id);
    $truckStmt->execute();
    $truckResult = $truckStmt->get_result();
    $truck = $truckResult->fetch_assoc();
    $truckStmt->close();
    if (!$truck || strtolower($truck['status']) !== 'available') {
        showAlertAndRedirect('This truck is currently unavailable for booking. Please choose another truck.', 'truck_register.php', 'Truck unavailable');
    }
    $cargo_type = trim($truck['cargo_type'] ?? '');
    $truck_name = $truck['truck_name'] ?? $truck_name;
    $truck_plate = $truck['plate_number'] ?? '';
    $truck_capacity = $truck['capacity'] ?? '';
    $truck_fuel = $truck['fuel_type'] ?? '';
    $truck_image = $truck['image_url'] ?? '';
    $price_per_km = (float)($truck['price_per_km'] ?? 0);
    if ($price_per_km <= 0) {
        $price_per_km = $cargoRates[$cargo_type] ?? 0;
    }
}

if (!$truck_id || $price_per_km <= 0) {
    showAlertAndRedirect('The selected truck has no valid price per kilometre. Please choose another truck.', 'truck_register.php', 'Invalid truck price');
}

$cost = (float)$distance * $price_per_km;

// Keep the booking in the session until payment is confirmed.
// The database record and truck lock are created by payment.php in one transaction.
$_SESSION['pending_booking'] = [
    'truck_id' => $truck_id,
    'truck_name' => $truck_name,
    'truck_plate' => $truck_plate ?? '',
    'truck_capacity' => $truck_capacity ?? '',
    'truck_fuel' => $truck_fuel ?? '',
    'truck_image' => $truck_image ?? '',
    'fullname' => $fullname,
    'email' => $email,
    'phone' => $phone,
    'trip_date' => $trip_date,
    'pickup' => $pickup,
    'destination' => $destination,
    'pickup_lat' => $pickup_lat,
    'pickup_lng' => $pickup_lng,
    'destination_lat' => $destination_lat,
    'destination_lng' => $destination_lng,
    'distance' => (float)$distance,
    'cost' => $cost,
    'base_cost' => (float)$distance * $price_per_km,
    'cargo_type' => $cargo_type,
    'created_at' => time()
];

header('Location: payment.php');
exit;
?>

