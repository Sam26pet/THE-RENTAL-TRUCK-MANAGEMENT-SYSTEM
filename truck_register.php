<?php
session_start();

// Database connection
$servername = "localhost";
$username   = "root";       
$password   = "";           
$dbname     = "rental_truck"; 

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { 
    die("Connection failed: " . $conn->connect_error); 
}

$conn->query("ALTER TABLE trucks ADD COLUMN IF NOT EXISTS price_per_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cargo_type");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS pickup_lat DECIMAL(10,7) NULL AFTER destination");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS pickup_lng DECIMAL(10,7) NULL AFTER pickup_lat");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS destination_lat DECIMAL(10,7) NULL AFTER pickup_lng");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS destination_lng DECIMAL(10,7) NULL AFTER destination_lat");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_name VARCHAR(120) NULL AFTER cost");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_phone VARCHAR(30) NULL AFTER assigned_driver_name");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_email VARCHAR(150) NULL AFTER assigned_driver_phone");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_license VARCHAR(80) NULL AFTER assigned_driver_email");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS assigned_driver_photo VARCHAR(255) NULL AFTER assigned_driver_license");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS original_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER distance");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER original_cost");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_percent");

// Handle search input
$search = $_GET['search'] ?? '';
$view = $_GET['view'] ?? 'trucks';
$customerName = trim($_SESSION['user_fullname'] ?? '');
$result = null;
$show_results = false;
$dashboardBookings = [];
$dashboardError = '';
$completedBookingCount = 0;
$cargoRates = [
    'Guta' => 1200,
    'Meat van' => 1800,
    'Refrigerated vans' => 2500,
    'Town ice' => 3000,
    'Heavy trucks' => 3800
];

if ($customerName !== '' && $view === 'dashboard') {
    // Show every booking belonging to the authenticated customer.
    $dashboardStmt = $conn->prepare("SELECT 
            b.booking_id, b.trip_date, b.pickup, b.destination,
            b.distance, b.original_cost, b.discount_percent, b.discount_amount, b.cost, b.created_at,
            b.pickup_lat, b.pickup_lng, b.destination_lat, b.destination_lng,
            CASE
                WHEN LOWER(TRIM(COALESCE(b.booking_status, 'active'))) = 'released' THEN 'released'
                ELSE 'active'
            END AS booking_status,
            COALESCE(b.truck_name, t.truck_name) AS truck_name, 
            t.plate_number, t.capacity, t.cargo_type, t.image_url,
            p.payment_reference, p.payment_status, p.paid_at, p.method,
            COALESCE(b.assigned_driver_name, d.full_name) AS driver_name,
            COALESCE(b.assigned_driver_phone, d.phone) AS driver_phone,
            COALESCE(b.assigned_driver_email, d.email) AS driver_email,
            COALESCE(b.assigned_driver_license, d.license_number) AS driver_license,
            COALESCE(b.assigned_driver_photo, d.photo_url) AS driver_photo_url,
            a.assignment_id
        FROM booking b
        LEFT JOIN trucks t ON t.truck_id = b.truck_id
        LEFT JOIN payments p ON p.booking_id = b.booking_id
        LEFT JOIN truck_driver_assignments a ON a.booking_id = b.booking_id
        LEFT JOIN drivers d ON d.driver_id = a.driver_id
        WHERE LOWER(b.email) = LOWER(?)
        ORDER BY b.created_at DESC");
    
    if ($dashboardStmt) {
        $dashboardStmt->bind_param('s', $_SESSION['user_email']);
        $dashboardStmt->execute();
        $dashboardResult = $dashboardStmt->get_result();
        while ($row = $dashboardResult->fetch_assoc()) {
            $dashboardBookings[] = $row;
        }
        $dashboardStmt->close();
    } else {
        $dashboardError = 'Booking history is temporarily unavailable.';
    }
    $conn->query("CREATE TABLE IF NOT EXISTS payments (
        payment_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NULL,
        fullname VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL,
        method VARCHAR(40) NOT NULL,
        bankname VARCHAR(100) NULL,
        account_number VARCHAR(100) NULL,
        simcardprovider VARCHAR(50) NULL,
        phone_number VARCHAR(30) NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_reference VARCHAR(80) NOT NULL,
        payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
        paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS drivers (
        driver_id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        email VARCHAR(150) NULL,
        license_number VARCHAR(80) NOT NULL,
        photo_url VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $driverPhotoColumn = $conn->query("SHOW COLUMNS FROM drivers LIKE 'photo_url'");
    if ($driverPhotoColumn && $driverPhotoColumn->num_rows === 0) {
        $conn->query("ALTER TABLE drivers ADD COLUMN photo_url VARCHAR(255) NULL AFTER license_number");
    }
    $conn->query("CREATE TABLE IF NOT EXISTS truck_driver_assignments (
        assignment_id INT AUTO_INCREMENT PRIMARY KEY,
        booking_id INT NOT NULL UNIQUE,
        truck_id INT NOT NULL,
        driver_id INT NOT NULL,
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $loyaltyStmt = $conn->prepare("SELECT COUNT(*) AS completed_bookings FROM booking b INNER JOIN payments p ON p.booking_id = b.booking_id WHERE LOWER(b.email) = LOWER(?) AND LOWER(COALESCE(p.payment_status, '')) IN ('paid', 'completed', 'success')");
    if ($loyaltyStmt) {
        $loyaltyStmt->bind_param('s', $_SESSION['user_email']);
        $loyaltyStmt->execute();
        $completedBookingCount = (int)($loyaltyStmt->get_result()->fetch_assoc()['completed_bookings'] ?? 0);
        $loyaltyStmt->close();
    }
}

if (!empty($search)) {
    $sql = "SELECT * FROM trucks WHERE cargo_type LIKE ? ORDER BY truck_id DESC";
    $stmt = $conn->prepare($sql);
    $like = "%".$search."%";
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();
    $show_results = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Trucks by Cargo Type</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        /* ============================================
                   GLOBAL STYLES
                   ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            padding: 30px 20px;
            color: #f1f1f1;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            transition: background-image 1.2s ease;
            position: relative;
        }

        /* Dark overlay for readability */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(8, 10, 25, 0.78);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            z-index: 0;
        }

        /* All content sits above overlay */
        .header,
        .search-container,
        .results-container {
            position: relative;
            z-index: 1;
        }

        /* ============================================
                   HEADER
                   ============================================ */
        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h2 {
            color: #ffcc00;
            font-size: 20px;
            font-weight: 700;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            letter-spacing: 1px;
            text-shadow: 0 2px 20px rgba(0, 0, 0, 0.8), 0 2px 10px rgba(255, 204, 0, 0.2);
        }

        .header p {
            color: #d0d8e8;
            font-size: 16px;
            margin-top: 8px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.6);
        }

        .header-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 16px;
        }

        .header-actions .btn {
            padding: 10px 16px;
            font-size: 14px;
        }

        .dashboard-link {
            background: rgba(255, 204, 0, 0.14);
            color: #fff4b8;
            border: 1px solid rgba(255, 204, 0, 0.25);
        }

        .dashboard-link:hover {
            background: rgba(255, 204, 0, 0.25);
            border-color: #ffcc00;
        }

        .welcome-user {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            padding: 9px 16px;
            border: 1px solid rgba(255, 204, 0, 0.25);
            border-radius: 999px;
            background: rgba(255, 204, 0, 0.12);
            color: #fff4b8;
            font-size: 12px;
            font-weight: 700;
        }

        /* ============================================
                   SEARCH BAR
                   ============================================ */
        .search-container {
            max-width: 700px;
            margin: 0 auto 30px auto;
            background: rgba(10, 14, 35, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 25px 30px;
            border-radius: 16px;
            border: 1px solid rgba(255, 204, 0, 0.15);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        }

        .search-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: center;
        }

        .search-bar select {
            flex: 1 1 280px;
            padding: 14px 20px;
            border-radius: 12px;
            border: 2px solid rgba(255, 204, 0, 0.25);
            background: rgba(255, 255, 255, 0.07);
            color: #f1f1f1;
            font-size: 16px;
            cursor: pointer;
            transition: 0.3s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23ffcc00' stroke-width='2' fill='none'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
        }

        .search-bar select:hover {
            border-color: #ffcc00;
        }

        .search-bar select:focus {
            outline: none;
            border-color: #ffcc00;
            box-shadow: 0 0 0 4px rgba(255, 204, 0, 0.12);
        }

        .search-bar select option {
            background: #1a1a2e;
            color: #f1f1f1;
            padding: 10px;
        }

        .search-bar select option:checked {
            background: #ffcc00;
            color: #121212;
        }

        .search-bar .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-search {
            background: linear-gradient(135deg, #ffcc00, #ff8800);
            color: #121212;
            box-shadow: 0 4px 15px rgba(255, 204, 0, 0.25);
        }

        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 204, 0, 0.35);
        }

        .btn-search:active {
            transform: translateY(0px);
        }

        .btn-reset {
            background: rgba(255, 255, 255, 0.08);
            color: #f1f1f1;
            border: 2px solid rgba(255, 255, 255, 0.15);
        }

        .btn-reset:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: #ffcc00;
            transform: translateY(-2px);
        }

        .btn-back {
            background: rgba(108, 122, 146, 0.25);
            color: #f1f1f1;
            border: 2px solid rgba(255, 255, 255, 0.08);
        }

        .btn-back:hover {
            background: rgba(108, 122, 146, 0.45);
            border-color: #6c7a92;
            transform: translateY(-2px);
        }

        /* ============================================
                   RESULTS SECTION
                   ============================================ */
        .results-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(255, 204, 0, 0.15);
        }

        .results-header h3 {
            color: #ffcc00;
            font-size: 20px;
            font-weight: 600;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .results-header .count {
            background: rgba(255, 204, 0, 0.12);
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 14px;
            color: #ffcc00;
            border: 1px solid rgba(255, 204, 0, 0.1);
        }

        /* ============================================
                   TRUCK CARDS GRID
                   ============================================ */
        .truck-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
        }

        .truck-card {
            background: rgba(12, 16, 40, 0.75);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            transition: 0.3s;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .truck-card:hover {
            transform: translateY(-8px);
            border-color: rgba(255, 204, 0, 0.25);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
        }

        .truck-card .image-container {
            width: 100%;
            height: 180px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 15px;
            background: rgba(0, 0, 0, 0.4);
            position: relative;
        }

        .truck-card .image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: 0.5s;
            border: 2px solid rgba(255, 204, 0, 0.15);
            border-radius: 12px;
        }

        .truck-card .image-container img:hover {
            transform: scale(1.05);
            border-color: #ffcc00;
        }

        .truck-card .badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .badge-available {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }

        .badge-unavailable {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.3);
        }

        .badge-maintenance {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        .truck-card h3 {
            color: #ffcc00;
            font-size: 18px;
            margin-bottom: 8px;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
        }

        .truck-card .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            text-align: left;
            margin: 12px 0;
            padding: 10px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 10px;
        }

        .truck-card .details .label {
            color: #a0aec0;
            font-size: 12px;
            font-weight: 500;
        }

        .truck-card .details .value {
            color: #f1f1f1;
            font-size: 13px;
            font-weight: 600;
            text-align: right;
        }

        .truck-card .details .value.cargo {
            color: #ffcc00;
        }

        .btn-book {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #ffcc00, #ff8800);
            color: #121212;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 700;
            font-size: 15px;
            transition: 0.3s;
            box-shadow: 0 4px 15px rgba(255, 204, 0, 0.15);
            margin-top: 10px;
            width: 100%;
            text-align: center;
        }

        .btn-book:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 204, 0, 0.3);
        }

        /* ============================================
                   EMPTY STATE
                   ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: rgba(12, 16, 40, 0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: 16px;
            border: 2px dashed rgba(255, 255, 255, 0.06);
        }

        .empty-state .icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.6;
        }

        .empty-state h3 {
            color: #ffcc00;
            font-size: 24px;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
        }

        .empty-state p {
            color: #c8d0e0;
            font-size: 16px;
        }

        .dashboard {
            max-width: 1200px;
            margin: 0 auto 30px;
            position: relative;
            z-index: 1;
        }

        .dashboard-heading {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 16px;
            margin-bottom: 20px;
        }

        .dashboard-heading h3 {
            color: #ffcc00;
            font-size: 26px;
            margin-bottom: 5px;
        }

        .dashboard-heading p {
            color: #d0d8e8;
        }

        .dashboard-heading .btn {
            padding: 10px 16px;
            font-size: 14px;
            white-space: nowrap;
        }

        .dashboard-heading .header-actions {
            margin-top: 0;
            justify-content: flex-end;
        }

        .booking-history {
            display: grid;
            gap: 18px;
        }

        .booking-record {
            display: grid;
            grid-template-columns: minmax(190px, .8fr) 1.2fr;
            gap: 20px;
            padding: 20px;
            background: rgba(12, 16, 40, 0.82);
            border: 1px solid rgba(255, 204, 0, 0.16);
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.3);
        }

        .booking-record.is-hidden-by-user {
            display: none;
        }

        .classic-confirm {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(0, 0, 0, .48);
        }
        .classic-confirm.is-open { display: flex; }
        .classic-confirm-box {
            width: min(360px, 100%);
            border: 1px solid #b8c2d1;
            border-radius: 6px;
            background: #fff;
            color: #26364a;
            box-shadow: 0 12px 35px rgba(0, 0, 0, .35);
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        .classic-confirm-title {
            padding: 11px 14px;
            border-bottom: 1px solid #d5dce6;
            background: #1e3c72;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
        }
        .classic-confirm-message { padding: 18px 14px 12px; font-size: 14px; line-height: 1.45; }
        .classic-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; padding: 0 14px 14px; }
        .classic-confirm-actions button { min-width: 78px; padding: 7px 12px; border: 1px solid #9daabd; border-radius: 4px; background: #f3f5f8; color: #26364a; cursor: pointer; }
        .classic-confirm-actions .confirm-remove { border-color: #b94a48; background: #c9574a; color: #fff; }

        .booking-record-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .hide-booking-btn,
        .restore-bookings-btn {
            border: 1px solid rgba(255, 204, 0, .3);
            border-radius: 9px;
            padding: 8px 12px;
            background: rgba(255, 204, 0, .1);
            color: #fff4b8;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .2s ease;
        }

        .hide-booking-btn:hover,
        .restore-bookings-btn:hover {
            background: rgba(255, 204, 0, .22);
            border-color: #ffcc00;
            transform: translateY(-1px);
        }

        .restore-bookings-btn {
            display: none;
        }

        .restore-bookings-btn.is-visible {
            display: inline-flex;
        }

        .booking-history-empty {
            display: none;
            padding: 28px 20px;
            border: 1px dashed rgba(255, 204, 0, .3);
            border-radius: 14px;
            background: rgba(10, 14, 35, .6);
            color: #d0d8e8;
            text-align: center;
        }

        .booking-record h4 {
            color: #ffcc00;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .booking-photos {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 16px;
        }

        .booking-photo {
            width: 100%;
            height: 130px;
            object-fit: cover;
            border: 2px solid rgba(255, 204, 0, 0.2);
            border-radius: 10px;
        }

        .booking-photo.driver-photo {
            width: auto;
            height: auto;
            max-width: 100%;
            max-height: 110px;
            object-fit: contain;
            background: rgba(0, 0, 0, 0.25);
            display: block;
            margin: 0 auto;
        }

        .photo-label {
            display: block;
            margin-bottom: 5px;
            color: #aebbd0;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .photo-placeholder {
            display: grid;
            place-items: center;
            height: 130px;
            color: #aebbd0;
            background: rgba(0, 0, 0, 0.25);
            border: 1px dashed rgba(255, 255, 255, 0.16);
            border-radius: 10px;
            font-size: 13px;
            text-align: center;
        }

        .booking-record p {
            color: #d0d8e8;
            margin: 6px 0;
        }

        .booking-record strong {
            color: #fff;
        }

        .booking-route-map {
            height: 230px;
            margin-top: 16px;
            border: 1px solid rgba(255, 204, 0, .2);
            border-radius: 10px;
            overflow: hidden;
        }

        .agreement-view-button {
            margin-top: 10px;
            padding: 10px 14px;
            border: 1px solid rgba(115, 214, 194, .35);
            border-radius: 8px;
            background: #163b44;
            color: #b9f1e4;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .agreement-view-button:hover { background: #205653; color: #fff; }
        .dashboard-agreement-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 5000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
            background: rgba(3, 17, 23, .82);
        }
        .dashboard-agreement-modal.is-open { display: flex; }
        .dashboard-agreement {
            width: min(1000px, 100%);
            padding: 28px 32px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #1a2a3a;
            box-shadow: 0 24px 70px rgba(0, 0, 0, .45);
        }
        .dashboard-agreement-head { display: flex; justify-content: space-between; gap: 20px; padding-bottom: 16px; border-bottom: 2px solid #205653; }
        .dashboard-agreement-head h2 { color: #173b6d; font-size: 24px; }
        .dashboard-agreement-head p { margin-top: 4px; color: #64777a; font-size: 13px; }
        .dashboard-agreement-ref { color: #205653; font-size: 13px; font-weight: 800; text-align: right; }
        .dashboard-agreement h3 { margin: 20px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #d5dde5; color: #173b6d; font-size: 15px; }
        .dashboard-agreement p, .dashboard-agreement li { color: #334155; font-size: 13px; line-height: 1.6; }
        .dashboard-agreement ul { margin: 6px 0 12px; padding-left: 22px; }
        .dashboard-agreement-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 18px 0; padding: 14px; background: #edf5f5; border: 1px solid #cbdfe0; border-radius: 6px; }
        .dashboard-agreement-summary span { display: block; color: #64777a; font-size: 11px; text-transform: uppercase; }
        .dashboard-agreement-summary strong { display: block; color: #205653; font-size: 13px; overflow-wrap: anywhere; }
        .dashboard-agreement-signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 22px; }
        .dashboard-signature { min-height: 90px; padding: 12px; border: 1px dashed #9aa8b8; }
        .dashboard-signature small { display: block; color: #64777a; font-weight: 700; text-transform: uppercase; }
        .dashboard-signature strong { display: block; margin-top: 18px; color: #173b6d; }
        .provider-stamp { display: inline-grid; width: 110px; height: 52px; place-items: center; margin-top: 8px; border: 2px solid #205653; border-radius: 50%; color: #205653; font-size: 9px; font-weight: 800; line-height: 1.1; text-align: center; transform: rotate(-6deg); }
        .dashboard-agreement-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; padding-top: 14px; border-top: 1px solid #d5dde5; }
        .dashboard-agreement-actions button { padding: 9px 15px; border: 1px solid #9aa8b8; border-radius: 5px; background: #f1f5f9; color: #26364a; cursor: pointer; }
        .dashboard-agreement-actions .print-agreement { border-color: #205653; background: #205653; color: #fff; }
        @media (max-width: 650px) { .dashboard-agreement-modal { padding: 8px; } .dashboard-agreement { padding: 20px 16px; } .dashboard-agreement-head { flex-direction: column; } .dashboard-agreement-ref { text-align: left; } .dashboard-agreement-summary { grid-template-columns: 1fr 1fr; } .dashboard-agreement-signatures { grid-template-columns: 1fr; } }
        @media print { @page { size: A4; margin: 8mm; } body.print-agreement-mode > * { display: none !important; } body.print-agreement-mode #dashboardAgreementModal { display: block !important; position: static; padding: 0; background: #fff !important; } body.print-agreement-mode #dashboardAgreementModal .dashboard-agreement { width: 100%; padding: 8mm; border: 0; box-shadow: none; } body.print-agreement-mode #dashboardAgreementModal .dashboard-agreement-actions { display: none; } }

        .route-unavailable {
            margin-top: 16px;
            padding: 12px;
            border-radius: 10px;
            color: #aab7c8;
            background: rgba(255, 255, 255, .05);
            font-size: 13px;
        }

        .record-status {
            display: inline-block;
            margin-bottom: 12px;
            padding: 5px 10px;
            border-radius: 20px;
            background: rgba(40, 167, 69, 0.18);
            color: #7ee2a0;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .record-status.released {
            background: rgba(148, 163, 184, 0.2);
            color: #cbd5e1;
        }

        .record-section {
            padding: 14px;
            background: rgba(0, 0, 0, 0.22);
            border-radius: 10px;
        }

        .record-section + .record-section {
            margin-top: 12px;
        }

        .record-section h5 {
            color: #ffcc00;
            font-size: 13px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .record-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
        }

        .record-grid span {
            color: #aebbd0;
            font-size: 13px;
        }

        .record-grid b {
            display: block;
            color: #f1f1f1;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .record-muted {
            color: #aebbd0;
            font-size: 14px;
        }

        /* ============================================
                   RESPONSIVE
                   ============================================ */
        @media (max-width: 768px) {
            .search-bar {
                flex-direction: column;
            }

            .search-bar select {
                flex: 1 1 100%;
                width: 100%;
            }

            .search-bar .btn-group {
                width: 100%;
            }

            .search-bar .btn-group .btn {
                flex: 1;
                justify-content: center;
            }

            .truck-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 0 auto;
            }

            .header h2 {
                font-size: 24px;
            }

            .results-header {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }

            .booking-record {
                grid-template-columns: 1fr;
            }

            .booking-record-header {
                flex-direction: column;
            }

            .dashboard-heading {
                align-items: stretch;
                flex-direction: column;
            }

            .truck-card .details {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .search-container {
                padding: 18px 16px;
            }

            .btn {
                padding: 12px 18px;
                font-size: 14px;
            }

            .truck-card .image-container {
                height: 140px;
            }

            .record-grid {
                grid-template-columns: 1fr;
            }

            .booking-photos {
                grid-template-columns: 1fr;
            }
        }

        /* ============================================
                   SCROLLBAR STYLING
                   ============================================ */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #0a0e1f;
        }

        ::-webkit-scrollbar-thumb {
            background: #ffcc00;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #ff8800;
        }

        /* ============================================
                   ANIMATION
                   ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .truck-card {
            animation: fadeInUp 0.5s ease forwards;
        }

        .truck-card:nth-child(1) { animation-delay: 0.05s; }
        .truck-card:nth-child(2) { animation-delay: 0.1s; }
        .truck-card:nth-child(3) { animation-delay: 0.15s; }
        .truck-card:nth-child(4) { animation-delay: 0.2s; }
        .truck-card:nth-child(5) { animation-delay: 0.25s; }
        .truck-card:nth-child(6) { animation-delay: 0.3s; }
    </style>
</head>
<body>

    <!-- ============================================
    HEADER
    ============================================ -->
    <div class="header">
      <!--  <h2> Search Trucks by Cargo Type</h2>-->
        
        <?php if ($customerName !== ''): ?>
            <div class="welcome-user">
                <span aria-hidden="true"></span>
             <i> <h2>  Welcome, <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></h2></i>
            </div>
            <div class="header-actions">
                <a href="truck_register.php?view=dashboard" class="btn dashboard-link">My Dashboard</a>
                <a href="customer_logout.php" class="btn btn-back">Sign out</a>
            </div>

            <p>Choose a cargo type from the dropdown below and click <strong>Search</strong> to find available trucks.</p>
                
        <?php endif; ?>
    </div>

    <?php if ($view === 'dashboard' && $customerName !== ''): ?>
        <main class="dashboard" data-dashboard-user="<?php echo htmlspecialchars(strtolower(trim($_SESSION['user_email'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="dashboard-heading">
                <div>
                    <h3>My Booking Dashboard</h3>
                    <p class="loyalty-message">Completed rides: <?php echo $completedBookingCount; ?></p>
                    <?php if ($completedBookingCount >= 5): ?>
                        <p class="loyalty-message">Discount unlocked: you receive 10% off every new ride.</p>
                    <?php endif; ?>
                </div>
                <div class="header-actions">
                    <button type="button" class="restore-bookings-btn" id="restoreBookingsBtn">Restore hidden</button>
                    <a href="truck_register.php" class="btn btn-back">Find Trucks</a>
                </div>
            </div>

            <?php if ($dashboardError): ?>
                <div class="empty-state">
                    <h3>Dashboard Unavailable</h3>
                    <p><?php echo htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            <?php elseif (!$dashboardBookings): ?>
                <div class="empty-state">
                    <div class="icon">📋</div>
                    <h3>No Booking History Yet</h3>
                    <p>Your completed bookings and receipts will appear here.</p>
                    <a href="truck_register.php" class="btn btn-search" style="margin-top:18px;">Find a Truck</a>
                </div>
            <?php else: ?>
                <div class="booking-history" id="bookingHistory">
                    <?php foreach ($dashboardBookings as $booking): ?>
                        <?php
                            $bookingStatus = strtolower(trim($booking['booking_status'] ?? 'active')) === 'released' ? 'released' : 'active';
                            $paymentStatus = strtolower($booking['payment_status'] ?? '');
                            $driverHeading = !empty($booking['assignment_id']) ? 'Assigned driver' : 'Driver history';
                        ?>
                        <article class="booking-record" data-booking-id="<?php echo (int)$booking['booking_id']; ?>">
                            <div>
                                <div class="booking-photos">
                                    <div>
                                        <span class="photo-label">Truck photo</span>
                                        <?php if (!empty($booking['image_url'])): ?>
                                            <img class="booking-photo" src="<?php echo htmlspecialchars($booking['image_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($booking['truck_name'] ?? 'Booked truck', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                            <span class="photo-placeholder" style="display:none;">Truck photo unavailable</span>
                                        <?php else: ?>
                                            <span class="photo-placeholder">Truck photo unavailable</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <span class="photo-label">Driver photo</span>
                                        <?php if (!empty($booking['driver_photo_url'])): ?>
                                            <img class="booking-photo driver-photo" src="<?php echo htmlspecialchars($booking['driver_photo_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($booking['driver_name'] ?? 'Assigned driver', ENT_QUOTES, 'UTF-8'); ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                            <span class="photo-placeholder" style="display:none;">Driver photo unavailable</span>
                                        <?php else: ?>
                                            <span class="photo-placeholder">Driver not assigned</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="record-status <?php echo $bookingStatus === 'released' ? 'released' : 'active'; ?>">
                                    <?php echo $bookingStatus === 'released' ? 'Released' : 'Active'; ?>
                                </span>
                                <div class="booking-record-header">
                                    <h4>Booking #<?php echo (int)$booking['booking_id']; ?></h4>
                                    <button type="button" class="hide-booking-btn" data-hide-booking>Remove from view</button>
                                </div>
                                <p><strong>Trip date:</strong> <?php echo htmlspecialchars($booking['trip_date'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Route:</strong> <?php echo htmlspecialchars($booking['pickup'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars($booking['destination'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Distance:</strong> <?php echo number_format((float)($booking['distance'] ?? 0), 2); ?> km</p>
                                <?php if ((float)($booking['discount_amount'] ?? 0) > 0): ?>
                                    <p><strong>Full cost:</strong> <?php echo number_format((float)($booking['original_cost'] ?: $booking['cost']), 2); ?> TZS</p>
                                    <p><strong>Discount:</strong> <?php echo number_format((float)($booking['discount_percent'] ?: 10), 0); ?>% (-<?php echo number_format((float)$booking['discount_amount'], 2); ?> TZS)</p>
                                    <p><strong>Total after discount:</strong> <?php echo number_format((float)($booking['cost'] ?? 0), 2); ?> TZS</p>
                                <?php else: ?>
                                    <p><strong>Total:</strong> <?php echo number_format((float)($booking['cost'] ?? 0), 2); ?> TZS</p>
                                <?php endif; ?>
                                <?php if ((float)($booking['pickup_lat'] ?? 0) && (float)($booking['pickup_lng'] ?? 0) && (float)($booking['destination_lat'] ?? 0) && (float)($booking['destination_lng'] ?? 0)): ?>
                                    <div class="booking-route-map"
                                         data-pickup-lat="<?php echo htmlspecialchars((string)$booking['pickup_lat'], ENT_QUOTES, 'UTF-8'); ?>"
                                         data-pickup-lng="<?php echo htmlspecialchars((string)$booking['pickup_lng'], ENT_QUOTES, 'UTF-8'); ?>"
                                         data-destination-lat="<?php echo htmlspecialchars((string)$booking['destination_lat'], ENT_QUOTES, 'UTF-8'); ?>"
                                         data-destination-lng="<?php echo htmlspecialchars((string)$booking['destination_lng'], ENT_QUOTES, 'UTF-8'); ?>"
                                         aria-label="Booked route map"></div>
                                    <button type="button" class="agreement-view-button" data-view-agreement
                                        data-booking-id="<?php echo (int)$booking['booking_id']; ?>"
                                        data-customer-name="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-customer-email="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-customer-phone="<?php echo htmlspecialchars($_SESSION['user_mobile'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-truck-name="<?php echo htmlspecialchars($booking['truck_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-plate="<?php echo htmlspecialchars($booking['plate_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-capacity="<?php echo htmlspecialchars($booking['capacity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-cargo="<?php echo htmlspecialchars($booking['cargo_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-driver="<?php echo htmlspecialchars($booking['driver_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-trip-date="<?php echo htmlspecialchars($booking['trip_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-pickup="<?php echo htmlspecialchars($booking['pickup'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-destination="<?php echo htmlspecialchars($booking['destination'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-distance="<?php echo htmlspecialchars((string)($booking['distance'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-original-cost="<?php echo htmlspecialchars((string)($booking['original_cost'] ?? $booking['cost'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-discount-percent="<?php echo htmlspecialchars((string)($booking['discount_percent'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-discount-amount="<?php echo htmlspecialchars((string)($booking['discount_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-cost="<?php echo htmlspecialchars((string)($booking['cost'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">View Digital Agreement</button>
                                <?php else: ?>
                                    <div class="route-unavailable">Route map is unavailable for this older booking.</div>
                                    <button type="button" class="agreement-view-button" data-view-agreement
                                        data-booking-id="<?php echo (int)$booking['booking_id']; ?>"
                                        data-customer-name="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-customer-email="<?php echo htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-customer-phone="<?php echo htmlspecialchars($_SESSION['user_mobile'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-truck-name="<?php echo htmlspecialchars($booking['truck_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-plate="<?php echo htmlspecialchars($booking['plate_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-capacity="<?php echo htmlspecialchars($booking['capacity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-cargo="<?php echo htmlspecialchars($booking['cargo_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-driver="<?php echo htmlspecialchars($booking['driver_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-trip-date="<?php echo htmlspecialchars($booking['trip_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-pickup="<?php echo htmlspecialchars($booking['pickup'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-destination="<?php echo htmlspecialchars($booking['destination'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-distance="<?php echo htmlspecialchars((string)($booking['distance'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-original-cost="<?php echo htmlspecialchars((string)($booking['original_cost'] ?? $booking['cost'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-discount-percent="<?php echo htmlspecialchars((string)($booking['discount_percent'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-discount-amount="<?php echo htmlspecialchars((string)($booking['discount_amount'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-cost="<?php echo htmlspecialchars((string)($booking['cost'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>">View Digital Agreement</button>
                                <?php endif; ?>
                            </div>

                            <div>
                                <section class="record-section">
                                    <h5>Truck details</h5>
                                    <div class="record-grid">
                                        <span>Name<b><?php echo htmlspecialchars($booking['truck_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                        <span>Plate<b><?php echo htmlspecialchars($booking['plate_number'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                        <span>Capacity<b><?php echo htmlspecialchars($booking['capacity'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                        <span>Cargo<b><?php echo htmlspecialchars($booking['cargo_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                    </div>
                                </section>

                                <section class="record-section">
                                    <h5>Receipt</h5>
                                    <?php if (!empty($booking['payment_reference'])): ?>
                                        <div class="record-grid">
                                            <span>Reference<b><?php echo htmlspecialchars($booking['payment_reference'], ENT_QUOTES, 'UTF-8'); ?></b></span>
                                            <span>Status<b><?php echo htmlspecialchars($paymentStatus ?: 'paid', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                            <span>Paid at<b><?php echo htmlspecialchars($booking['paid_at'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                            <span>Method<b><?php echo htmlspecialchars(ucfirst($booking['method'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></b></span>
                                        </div>
                                        <button type="button" class="agreement-view-button" onclick="window.location.href='receipt_lookup.php?reference=<?php echo rawurlencode($booking['payment_reference']); ?>&identity=<?php echo rawurlencode($_SESSION['user_email']); ?>'">View full receipt</button>
                                    <?php else: ?>
                                        <p class="record-muted">No receipt is available for this booking.</p>
                                    <?php endif; ?>
                                </section>

                                <section class="record-section">
                                    <h5><?php echo $driverHeading; ?></h5>
                                    <?php if (!empty($booking['driver_name'])): ?>
                                        <div class="record-grid">
                                            <span>Name<b><?php echo htmlspecialchars($booking['driver_name'], ENT_QUOTES, 'UTF-8'); ?></b></span>
                                            <span>Phone<b><?php echo htmlspecialchars($booking['driver_phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                            <span>Email<b><?php echo htmlspecialchars($booking['driver_email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                            <span>License<b><?php echo htmlspecialchars($booking['driver_license'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></b></span>
                                        </div>
                                    <?php else: ?>
                                        <p class="record-muted">No driver has been assigned yet.</p>
                                    <?php endif; ?>
                                </section>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <div class="booking-history-empty" id="bookingHistoryEmpty">
                        All visible booking records are hidden. Use “Restore hidden” to show them again.
                    </div>
                </div>
            <?php endif; ?>
        </main>
    <?php endif; ?>

    <div class="dashboard-agreement-modal" id="dashboardAgreementModal" role="dialog" aria-modal="true" aria-labelledby="dashboardAgreementTitle">
        <article class="dashboard-agreement">
            <div class="dashboard-agreement-head">
                <div><h2 id="dashboardAgreementTitle">RENTAL TRUCK SYSTEM</h2><p>Digital Rental Agreement · Tanzania</p></div>
                <div class="dashboard-agreement-ref">DIGITAL CONTRACT<br><span id="dashboardAgreementReference">BOOKING AGREEMENT</span></div>
            </div>
            <div class="dashboard-agreement-summary">
                <div><span>Customer</span><strong id="agreementCustomerName">—</strong></div>
                <div><span>Email</span><strong id="agreementCustomerEmail">—</strong></div>
                <div><span>Phone</span><strong id="agreementCustomerPhone">—</strong></div>
                <div><span>Truck</span><strong id="agreementTruckName">—</strong></div>
                <div><span>Plate</span><strong id="agreementTruckPlate">—</strong></div>
                <div><span>Capacity / cargo</span><strong id="agreementTruckCapacity">—</strong></div>
                <div><span>Pickup</span><strong id="agreementTripPickup">—</strong></div>
                <div><span>Destination</span><strong id="agreementTripDestination">—</strong></div>
                <div><span>Trip date / distance</span><strong id="agreementTripDate">—</strong></div><div><span>Driver</span><strong id="agreementDriver">—</strong></div>
            </div>
            <div class="dashboard-agreement-terms">
                <h3>1. Parties to this agreement</h3><p><strong>Service Provider:</strong> Rental Truck Management System, Dar es Salaam, Tanzania.</p><p><strong>Customer:</strong> <span id="agreementPartyCustomer">—</span>.</p>
                <h3>2. Rental service</h3><p>The Service Provider agrees to provide the selected vehicle together with a qualified driver for the trip and period shown above.</p>
                <h3>3. Payment terms</h3><ul><li>Payment must be completed before the trip begins.</li><li>Payment methods are bank transfer or mobile money.</li><li>Extra costs such as fuel, parking and tolls are paid by the Customer.</li><li>Full trip cost: <strong id="agreementFullCost">—</strong>.</li><li>Discount: <strong id="agreementDiscount">No discount</strong>.</li><li>Amount due: <strong id="agreementAmountDue">—</strong>.</li></ul>
                <h3>4. Customer responsibilities</h3><ul><li>Provide accurate booking information.</li><li>Do not misuse the vehicle or use it for illegal activities.</li><li>Do not transport prohibited or dangerous goods.</li><li>Cooperate with the assigned driver and report issues immediately.</li></ul>
                <h3>5. Insurance and safety</h3><p>Basic insurance applies to eligible road incidents during the trip. Damage caused by negligence, misuse or prohibited goods is excluded.</p>
                <h3>6. Driver provisions</h3><ul><li>The Service Provider assigns a qualified and licensed driver.</li><li>The driver operates the vehicle safely and professionally.</li><li>The Customer shall not request the driver to violate traffic laws.</li></ul>
                <h3>7. Cancellation policy</h3><ul><li>More than 24 hours before the trip: no charge.</li><li>Less than 24 hours before the trip: 20% of total rental cost.</li><li>No-show at pickup: 50% of total rental cost.</li></ul>
                <h3>8. Loyalty discount</h3><p>Eligible customers who complete five paid trips receive the displayed loyalty discount on future bookings.</p>
                <h3>9. Acknowledgement</h3><p>By accepting this agreement, the Customer confirms that they have read and understood all terms and agree to comply with them.</p>
            </div>
            <div class="dashboard-agreement-signatures">
                <div class="dashboard-signature"><small>Customer electronic signature</small><strong id="agreementCustomerSignature">—</strong><p id="agreementCustomerSignedAt">Signed with booking acceptance</p></div>
                <div class="dashboard-signature"><small>Service Provider official signature</small><strong>Rental Truck Management System</strong><span class="provider-stamp">RENTAL TRUCK<br>OFFICIAL STAMP</span><p>Digitally approved by Service Provider</p></div>
            </div>
            <div class="dashboard-agreement-actions"><button type="button" onclick="closeDashboardAgreement()">Close</button><button type="button" class="print-agreement" onclick="printDashboardAgreement()">Print Agreement</button></div>
        </article>
    </div>

    <!-- ============================================
    SEARCH BAR
    ============================================ -->
    <div class="search-container">
        <form method="get" action="" class="search-bar">
            <select name="search" id="cargoSelect">
                <option value="">-- Select Cargo Type --</option>
                <option value="Heavy trucks" <?php echo ($search == 'Heavy trucks') ? 'selected' : ''; ?>>Heavy trucks</option>
                <option value="Town ice" <?php echo ($search == 'Town ice') ? 'selected' : ''; ?>>Town ice</option>
                <option value="Refrigerated vans" <?php echo ($search == 'Refrigerated vans') ? 'selected' : ''; ?>>Refrigerated vans</option>
                <option value="Meat van" <?php echo ($search == 'Meat van') ? 'selected' : ''; ?>>Meat van</option>
                <option value="Guta" <?php echo ($search == 'Guta') ? 'selected' : ''; ?>>Guta</option>
            </select>

            <div class="btn-group">
                <button type="submit" class="btn btn-search">🔍 Search</button>
                <button type="button" class="btn btn-reset" onclick="resetSearch()">⟳ Reset</button>
             
            </div>
        </form>
    </div>

    <div class="classic-confirm" id="removeBookingConfirm" role="dialog" aria-modal="true" aria-labelledby="removeBookingTitle">
        <div class="classic-confirm-box">
            <div class="classic-confirm-title" id="removeBookingTitle">Remove booking</div>
            <div class="classic-confirm-message">Remove this booking from your dashboard? Your booking will remain saved.</div>
            <div class="classic-confirm-actions">
                <button type="button" id="cancelRemoveBooking">Cancel</button>
                <button type="button" class="confirm-remove" id="confirmRemoveBooking">Remove</button>
            </div>
        </div>
    </div>

    <!-- ============================================
    RESULTS
    ============================================ -->
    <div class="results-container">
        <?php if ($show_results): ?>
            <div class="results-header">
                <h3> Search Results</h3>
                <span class="count">
                    <?php 
                    if ($result && $result->num_rows > 0) {
                        echo $result->num_rows . ' truck(s) found';
                    } else {
                        echo 'No trucks found';
                    }
                    ?>
                </span>
            </div>

            <div class="truck-grid">
                <?php
                if ($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        // Determine status badge class
                        $status_class = 'badge-available';
                        $status_text = 'Available';
                        if (strtolower($row['status']) == 'unavailable' || strtolower($row['status']) == 'booked') {
                            $status_class = 'badge-unavailable';
                            $status_text = 'Booked';
                        } elseif (strtolower($row['status']) == 'maintenance') {
                            $status_class = 'badge-maintenance';
                            $status_text = 'Maintenance';
                        }
                        
                        // Default image if none provided
                        $image_url = !empty($row['image_url']) ? $row['image_url'] : 'https://via.placeholder.com/400x300/1a1a2e/ffcc00?text=No+Image';
                ?>
                        <div class="truck-card">
                            <div class="image-container">
                                <img src="<?php echo htmlspecialchars($image_url); ?>" 
                                     alt="<?php echo htmlspecialchars($row['truck_name']); ?>"
                                     onerror="this.src='https://via.placeholder.com/400x300/1a1a2e/ffcc00?text=Truck'">
                            </div>
                            
                            <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                            
                            <h3><?php echo htmlspecialchars($row['truck_name']); ?></h3>
                            
                            <div class="details">
                                <span class="label">Plate</span>
                                <span class="value"><?php echo htmlspecialchars($row['plate_number']); ?></span>
                                
                                <span class="label">Ton weight</span>
                                <span class="value"><?php echo htmlspecialchars($row['capacity']); ?></span>
                                
                                <span class="label">Cargo Type</span>
                                <span class="value cargo"><?php echo htmlspecialchars($row['cargo_type']); ?></span>
                                
                                <span class="label">Price/Km</span>
                                <span class="value"><?php
                                    $truckRate = (float)($row['price_per_km'] ?? 0);
                                    $truckRate = $truckRate > 0 ? $truckRate : ($cargoRates[$row['cargo_type']] ?? 0);
                                    echo $truckRate > 0 ? number_format($truckRate, 0) . ' TZS/km' : 'Rate unavailable';
                                ?></span>
                            </div>
                            
                            <?php if (strtolower($row['status']) === 'available'): ?>
                                <a href="customer.html?truck_id=<?php echo $row['truck_id']; ?>" class="btn-book">
                                    Book Now
                                </a>
                            <?php else: ?>
                                <span class="btn-book" style="background:rgba(148,163,184,.35);cursor:not-allowed;box-shadow:none;">
                                    🔒 Booking Unavailable
                                </span>
                            <?php endif; ?>
                        </div>
                <?php
                    }
                } else {
                ?>
                        <div class="empty-state" style="grid-column: 1 / -1;">
                            <div class="icon">🔍</div>
                            <h3>No Trucks Found</h3>
                            <p>We couldn't find any trucks matching "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
                            <p style="margin-top:10px; font-size:14px; color:#8a9bb0;">
                                Try selecting a different cargo type from the dropdown above.
                            </p>
                        </div>
                <?php
                }
                ?>
            </div>
        <?php else: ?>
           
        <?php endif; ?>
    </div>

    <!-- ============================================
    BACKGROUND SLIDESHOW (same as other pages)
    ============================================ -->
    <script>
        // Array of background images - same as other pages
        const bgImages = [
            "image/volvo 3.jpg", "image/meat van3.jfif"
        ];

        let bgIndex = 0;
        const body = document.body;

        function changeBackground() {
            body.style.backgroundImage = `url('${bgImages[bgIndex]}')`;
            bgIndex = (bgIndex + 1) % bgImages.length;
        }

        // Set initial background
        changeBackground();

        // Change every 6 seconds (same as other pages)
        setInterval(changeBackground, 6000);
    </script>

    <!-- ============================================
    JAVASCRIPT
    ============================================ -->
    <script>
        // ================================================================
        // RESET FUNCTION
        // ================================================================
        function resetSearch() {
            // Reset the select dropdown to default
            document.getElementById('cargoSelect').value = '';
            // Reload the page without search parameter
            window.location.href = window.location.pathname;
        }

        // Hide dashboard records only in this browser; the database remains unchanged.
        (function manageDashboardHistory() {
            const dashboard = document.querySelector('[data-dashboard-user]');
            if (!dashboard) return;

            const storageKey = 'rentalTruckHiddenBookings:' + dashboard.dataset.dashboardUser;
            const history = document.getElementById('bookingHistory');
            const emptyState = document.getElementById('bookingHistoryEmpty');
            const restoreButton = document.getElementById('restoreBookingsBtn');
            const confirmDialog = document.getElementById('removeBookingConfirm');
            const cancelRemoveButton = document.getElementById('cancelRemoveBooking');
            const confirmRemoveButton = document.getElementById('confirmRemoveBooking');
            if (!history || !emptyState || !restoreButton || !confirmDialog || !cancelRemoveButton || !confirmRemoveButton) return;

            let hiddenBookings = [];
            try {
                hiddenBookings = JSON.parse(localStorage.getItem(storageKey) || '[]')
                    .map(String)
                    .filter((id, index, ids) => ids.indexOf(id) === index);
            } catch (error) {
                hiddenBookings = [];
            }

            function saveHiddenBookings() {
                localStorage.setItem(storageKey, JSON.stringify(hiddenBookings));
            }

            function updateHistoryState() {
                const records = [...history.querySelectorAll('.booking-record')];
                records.forEach(record => {
                    record.classList.toggle('is-hidden-by-user', hiddenBookings.includes(record.dataset.bookingId));
                });
                const visibleCount = records.filter(record => !record.classList.contains('is-hidden-by-user')).length;
                emptyState.style.display = records.length > 0 && visibleCount === 0 ? 'block' : 'none';
                restoreButton.classList.toggle('is-visible', hiddenBookings.length > 0);
            }

            history.addEventListener('click', function(event) {
                const button = event.target.closest('[data-hide-booking]');
                if (!button) return;

                const record = button.closest('[data-booking-id]');
                const bookingId = record && record.dataset.bookingId;
                if (!bookingId) return;

                confirmDialog.classList.add('is-open');
                confirmRemoveButton.focus();
                const closeDialog = () => confirmDialog.classList.remove('is-open');
                cancelRemoveButton.onclick = closeDialog;
                confirmDialog.onclick = event => { if (event.target === confirmDialog) closeDialog(); };
                confirmRemoveButton.onclick = () => {
                    if (!hiddenBookings.includes(bookingId)) hiddenBookings.push(bookingId);
                    saveHiddenBookings();
                    updateHistoryState();
                    closeDialog();
                };
            });

            restoreButton.addEventListener('click', function() {
                hiddenBookings = [];
                saveHiddenBookings();
                updateHistoryState();
            });

            updateHistoryState();
        }());

        document.querySelectorAll('.booking-route-map').forEach(async function (mapElement) {
            const pickup = [Number(mapElement.dataset.pickupLat), Number(mapElement.dataset.pickupLng)];
            const destination = [Number(mapElement.dataset.destinationLat), Number(mapElement.dataset.destinationLng)];
            const routeMap = L.map(mapElement, { zoomControl: true, attributionControl: true });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(routeMap);
            L.marker(pickup).addTo(routeMap).bindPopup('Pickup location');
            L.marker(destination).addTo(routeMap).bindPopup('Destination');
            routeMap.fitBounds(L.latLngBounds([pickup, destination]), { padding: [20, 20] });

            try {
                const coordinates = `${pickup[1]},${pickup[0]};${destination[1]},${destination[0]}`;
                const response = await fetch(`https://router.project-osrm.org/route/v1/driving/${coordinates}?overview=full&geometries=geojson`);
                if (!response.ok) throw new Error('Route service unavailable');
                const data = await response.json();
                const geometry = data.routes && data.routes[0] && data.routes[0].geometry;
                if (!geometry) throw new Error('Route geometry unavailable');

                const routeLine = L.geoJSON(geometry, {
                    style: { color: '#1e3c72', weight: 5, opacity: .9 }
                }).addTo(routeMap);
                routeMap.fitBounds(routeLine.getBounds(), { padding: [20, 20] });
            } catch (error) {
                mapElement.title = 'Road route is temporarily unavailable';
            }
        });

        function agreementText(value) {
            const element = document.createElement('span');
            element.textContent = value || '—';
            return element.innerHTML;
        }

        function openDashboardAgreement(button) {
            document.getElementById('agreementCustomerName').textContent = button.dataset.customerName || '—';
            document.getElementById('agreementCustomerEmail').textContent = button.dataset.customerEmail || '—';
            document.getElementById('agreementCustomerPhone').textContent = button.dataset.customerPhone || '—';
            document.getElementById('agreementTruckName').textContent = button.dataset.truckName || '—';
            document.getElementById('agreementTruckPlate').textContent = button.dataset.plate || '—';
            document.getElementById('agreementTruckCapacity').textContent = `${button.dataset.capacity || '—'} / ${button.dataset.cargo || '—'}`;
            document.getElementById('agreementTripPickup').textContent = button.dataset.pickup || '—';
            document.getElementById('agreementTripDestination').textContent = button.dataset.destination || '—';
            document.getElementById('agreementTripDate').textContent = `${button.dataset.tripDate || '—'} / ${button.dataset.distance || '0'} km`;
            document.getElementById('agreementDriver').textContent = button.dataset.driver || 'Not assigned';
            document.getElementById('agreementPartyCustomer').textContent = button.dataset.customerName || '—';
            document.getElementById('agreementFullCost').textContent = `${Number(button.dataset.originalCost || 0).toLocaleString()} TZS`;
            document.getElementById('agreementDiscount').textContent = Number(button.dataset.discountAmount || 0) > 0
                ? `-${Number(button.dataset.discountAmount).toLocaleString()} TZS (${button.dataset.discountPercent || 0}%)`
                : 'No discount';
            document.getElementById('agreementAmountDue').textContent = `${Number(button.dataset.cost || 0).toLocaleString()} TZS`;
            document.getElementById('agreementCustomerSignature').textContent = button.dataset.customerName || '—';
            document.getElementById('agreementCustomerSignedAt').textContent = `Signed with booking acceptance on ${button.dataset.tripDate || '—'}`;
            document.getElementById('dashboardAgreementReference').textContent = `BOOKING #${button.dataset.bookingId}`;
            document.getElementById('dashboardAgreementModal').classList.add('is-open');
        }

        function closeDashboardAgreement() {
            document.getElementById('dashboardAgreementModal').classList.remove('is-open');
        }

        function printDashboardAgreement() {
            const agreement = document.querySelector('#dashboardAgreementModal .dashboard-agreement');
            if (!agreement) return;

            const printWindow = window.open('', '_blank', 'width=900,height=700');
            if (!printWindow) {
                window.alert('Please allow pop-ups for this page to print the contract.');
                return;
            }

            const pageStyles = Array.from(document.querySelectorAll('style'))
                .map(style => `<style>${style.textContent}</style>`)
                .join('');
            const printStyles = `
                <style>
                    @page { size: A4; margin: 8mm; }
                    html, body { margin: 0; padding: 0; background: #fff; }
                    .dashboard-agreement { width: 100% !important; max-width: none !important; padding: 0 !important; border: 0 !important; box-shadow: none !important; }
                    .dashboard-agreement-actions { display: none !important; }
                    .dashboard-agreement h3 { break-after: avoid; }
                    .dashboard-agreement p, .dashboard-agreement li { color: #334155 !important; }
                    .dashboard-agreement-summary { break-inside: avoid; }
                    .dashboard-agreement-signatures { break-inside: avoid; }
                </style>
            `;

            printWindow.document.open();
            printWindow.document.write(`<!doctype html><html><head><meta charset="UTF-8"><title>Booking Contract</title>${pageStyles}${printStyles}</head><body>${agreement.outerHTML}</body></html>`);
            printWindow.document.close();
            printWindow.focus();
            printWindow.addEventListener('load', () => {
                printWindow.print();
                printWindow.addEventListener('afterprint', () => printWindow.close(), { once: true });
            }, { once: true });
        }

        document.querySelectorAll('[data-view-agreement]').forEach(button => {
            button.addEventListener('click', () => openDashboardAgreement(button));
        });
        document.getElementById('dashboardAgreementModal').addEventListener('click', event => {
            if (event.target.id === 'dashboardAgreementModal') closeDashboardAgreement();
        });

    </script>

</body>
</html>