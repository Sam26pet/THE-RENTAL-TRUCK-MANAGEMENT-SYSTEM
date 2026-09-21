<?php
require_once __DIR__ . '/admin_access.php';
requireAdminSession();
// Database connection
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "rental_truck";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Keep older databases compatible with truck rate editing.
$conn->query("ALTER TABLE trucks ADD COLUMN IF NOT EXISTS price_per_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cargo_type");

$editTruck = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_truck'])) {
    $id = filter_input(INPUT_POST, 'truck_id', FILTER_VALIDATE_INT);
    $truckName = trim($_POST['truck_name'] ?? '');
    $plateNumber = trim($_POST['plate_number'] ?? '');
    $capacity = trim($_POST['capacity'] ?? '');
    $fuelType = trim($_POST['fuel_type'] ?? '');
    $cargoType = trim($_POST['cargo_type'] ?? '');
    $status = strtolower(trim($_POST['status'] ?? ''));
    $allowedCargoTypes = ['Heavy trucks', 'Town ice', 'Refrigerated vans', 'Meat van', 'Guta'];
    $cargoRates = [
        'Guta' => 1200,
        'Meat van' => 1800,
        'Refrigerated vans' => 2500,
        'Town ice' => 3000,
        'Heavy trucks' => 3800
    ];
    $pricePerKm = $cargoRates[$cargoType] ?? 0;
    $currentStatusStmt = $conn->prepare('SELECT status FROM trucks WHERE truck_id = ?');
    $currentStatusStmt->bind_param('i', $id);
    $currentStatusStmt->execute();
    $currentStatus = strtolower($currentStatusStmt->get_result()->fetch_assoc()['status'] ?? '');
    $currentStatusStmt->close();
    if ($id && $truckName && $plateNumber && $pricePerKm > 0 && in_array($cargoType, $allowedCargoTypes, true) && in_array($status, ['available', 'maintenance'], true)) {
        if ($currentStatus === 'booked' && $status === 'maintenance') {
            $message = '❌ Release this booked truck before placing it into maintenance.';
            $msg_type = 'error';
        }
        $newImagePath = '';
        if ((!isset($message) || $msg_type !== 'error') && isset($_FILES['image_url']) && $_FILES['image_url']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image_url']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['image_url']['tmp_name'])) {
                $message = '❌ The selected image could not be uploaded.';
                $msg_type = 'error';
            } else {
                $imageInfo = getimagesize($_FILES['image_url']['tmp_name']);
                $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                if (!$imageInfo || !in_array($imageInfo['mime'], $allowedTypes, true)) {
                    $message = '❌ Please select a JPG, PNG, WEBP, or GIF image.';
                    $msg_type = 'error';
                } else {
                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'trucks';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $extension = strtolower(pathinfo($_FILES['image_url']['name'], PATHINFO_EXTENSION));
                    $newImagePath = 'uploads/trucks/truck_' . $id . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
                    if (!move_uploaded_file($_FILES['image_url']['tmp_name'], __DIR__ . DIRECTORY_SEPARATOR . $newImagePath)) {
                        $message = '❌ The selected image could not be saved.';
                        $msg_type = 'error';
                        $newImagePath = '';
                    }
                }
            }
        }

        if (!isset($message) || $msg_type !== 'error') {
            if ($newImagePath) {
                $stmt = $conn->prepare('SELECT image_url FROM trucks WHERE truck_id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $oldTruck = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $stmt = $conn->prepare('UPDATE trucks SET truck_name = ?, plate_number = ?, capacity = ?, fuel_type = ?, cargo_type = ?, price_per_km = ?, status = ?, image_url = ? WHERE truck_id = ?');
                $stmt->bind_param('sssssdssi', $truckName, $plateNumber, $capacity, $fuelType, $cargoType, $pricePerKm, $status, $newImagePath, $id);
            } else {
                $stmt = $conn->prepare('UPDATE trucks SET truck_name = ?, plate_number = ?, capacity = ?, fuel_type = ?, cargo_type = ?, price_per_km = ?, status = ? WHERE truck_id = ?');
                $stmt->bind_param('sssssdsi', $truckName, $plateNumber, $capacity, $fuelType, $cargoType, $pricePerKm, $status, $id);
            }
        if ($stmt->execute()) {
            if (!empty($oldTruck['image_url']) && $newImagePath && is_file(__DIR__ . DIRECTORY_SEPARATOR . $oldTruck['image_url'])) {
                unlink(__DIR__ . DIRECTORY_SEPARATOR . $oldTruck['image_url']);
            }
            $message = '✅ Truck updated successfully!';
            $msg_type = 'success';
        } else {
            $message = '❌ Error updating truck: ' . $stmt->error;
            $msg_type = 'error';
        }
        $stmt->close();
        }
    } else {
        $message = '❌ Truck name, plate number, cargo type, and valid status are required.';
        $msg_type = 'error';
    }
}

if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM trucks WHERE truck_id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editTruck = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ============================================
// HANDLE ACTIONS (DELETE, MAINTENANCE, AVAILABLE)
// ============================================

// Delete Truck
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $sql = "DELETE FROM trucks WHERE truck_id = $id";
    if ($conn->query($sql)) {
        $message = "✅ Truck deleted successfully!";
        $msg_type = "success";
    } else {
        $message = "❌ Error deleting truck: " . $conn->error;
        $msg_type = "error";
    }
}

// Put on Maintenance
if (isset($_GET['maintenance']) && is_numeric($_GET['maintenance'])) {
    $id = intval($_GET['maintenance']);
    $sql = "UPDATE trucks SET status = 'maintenance' WHERE truck_id = $id AND status <> 'booked'";
    if ($conn->query($sql) && $conn->affected_rows === 1) {
        $message = "🔧 Truck put on maintenance!";
        $msg_type = "success";
    } else {
        $message = $conn->error ? "❌ Error: " . $conn->error : "❌ Release this booked truck before placing it into maintenance.";
        $msg_type = "error";
    }
}

// Make Available
if (isset($_GET['available']) && is_numeric($_GET['available'])) {
    $id = intval($_GET['available']);
    $sql = "UPDATE trucks SET status = 'available' WHERE truck_id = $id";
    if ($conn->query($sql)) {
        $message = "✅ Truck is now available!";
        $msg_type = "success";
    } else {
        $message = "❌ Error: " . $conn->error;
        $msg_type = "error";
    }
}

// ============================================
// FETCH ALL TRUCKS
// ============================================
$trucks = [];
$sql = "SELECT * FROM trucks ORDER BY truck_id DESC";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $trucks[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Trucks · Admin</title>
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
            padding: 0;
            color: #f1f1f1;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            transition: background-image 1.2s ease;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(5, 8, 20, 0.88);
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            z-index: 0;
        }

        .navbar,
        .container,
        .footer-location {
            position: relative;
            z-index: 1;
        }

        /* ============================================
                   NAVBAR
                   ============================================ */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 2.5rem;
            height: 72px;
            background: rgba(10, 18, 32, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            background: linear-gradient(135deg, #e0edff, #8bb3ff);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .nav-links {
            display: flex;
            list-style: none;
            gap: 2rem;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
            font-size: 0.95rem;
            transition: 0.2s;
            padding-bottom: 4px;
            border-bottom: 2px solid transparent;
        }

        .nav-links a:hover {
            color: #fff;
            border-bottom-color: #ffcc00;
        }

        .nav-links .admin-badge {
            background: rgba(255, 204, 0, 0.15);
            padding: 4px 16px;
            border-radius: 30px;
            font-size: 12px;
            color: #ffcc00;
            border: 1px solid rgba(255, 204, 0, 0.2);
        }

        .nav-links .logout-btn {
            background: rgba(220, 53, 69, 0.15);
            padding: 6px 18px;
            border-radius: 30px;
            color: #ff6b7a;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }

        .nav-links .logout-btn:hover {
            background: rgba(220, 53, 69, 0.25);
            border-color: #dc3545;
            color: #fff;
        }

        /* ============================================
                   CONTAINER
                   ============================================ */
        .container {
            max-width: 1300px;
            margin: 0 auto;
            padding: 30px 25px 20px;
        }

        /* ============================================
                   HEADER
                   ============================================ */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #e8fff8;
            text-shadow: 0 2px 20px rgba(0, 0, 0, 0.5);
        }

        .page-header h1 span {
            color: #73d6c2;
        }

        .page-header .subtitle {
            color: #b0c4d8;
            font-size: 0.95rem;
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffcc00, #ff8800);
            color: #121212;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 204, 0, 0.3);
        }

        .btn-back {
            background: rgba(255, 255, 255, 0.08);
            color: #f1f1f1;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .btn-back:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        /* ============================================
                   MESSAGE
                   ============================================ */
        .message {
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .message.success {
            background: rgba(40, 167, 69, 0.15);
            border: 1px solid rgba(40, 167, 69, 0.2);
            color: #28a745;
        }

        .message.error {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }

        .edit-input {
            display: block;
            width: 100%;
            margin-top: 6px;
            padding: 10px 12px;
            border: 1px solid rgba(115, 214, 194, 0.42);
            border-radius: 8px;
            background: #183b3a;
            color: #f2fffb;
            outline: none;
        }

        .edit-input:focus {
            border-color: #73d6c2;
            box-shadow: 0 0 0 3px rgba(115, 214, 194, 0.16);
        }

        select.edit-input {
            color: #f2fffb;
            color-scheme: dark;
            cursor: pointer;
        }

        select.edit-input option {
            background: #12302f;
            color: #f2fffb;
        }

        select.edit-input option:checked,
        select.edit-input option:hover {
            background: #2b8f80;
            color: #ffffff;
        }

        label { color: #a8eee0; font-weight: 600; font-size: 0.85rem; }

        /* ============================================
                   TRUCK TABLE
                   ============================================ */
        .table-container {
            background: rgba(12, 35, 35, 0.84);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, 0.06);
            padding: 20px 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            overflow-x: auto;
        }

        .table-container h3 {
            color: #8fe0cf;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid rgba(115, 214, 194, 0.24);
            padding-bottom: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            min-width: 800px;
        }

        table th {
            text-align: left;
            padding: 12px 10px;
            color: #9ed7cd;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(29, 82, 78, 0.75);
            border-bottom: 2px solid rgba(115, 214, 194, 0.24);
        }

        table td {
            padding: 12px 10px;
            border-bottom: 1px solid rgba(115, 214, 194, 0.12);
            color: #d9f5ef;
            vertical-align: middle;
        }

        table tr:hover td {
            background: rgba(115, 214, 194, 0.08);
        }

        /* Status Badge */
        .status-badge {
            padding: 4px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.available {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
        }

        .status-badge.booked {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }

        .status-badge.maintenance {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 5px 12px;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        .action-btn.edit {
            background: rgba(0, 123, 255, 0.15);
            color: #4f8fff;
            border: 1px solid rgba(0, 123, 255, 0.15);
        }

        .action-btn.edit:hover {
            background: rgba(0, 123, 255, 0.25);
        }

        .action-btn.maintenance {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.15);
        }

        .action-btn.maintenance:hover {
            background: rgba(255, 193, 7, 0.25);
        }

        .action-btn.available {
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.15);
        }

        .action-btn.available:hover {
            background: rgba(40, 167, 69, 0.25);
        }

        .action-btn.delete {
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.15);
        }

        .action-btn.delete:hover {
            background: rgba(220, 53, 69, 0.25);
        }

        /* Truck Image */
        .truck-img {
            width: 60px;
            height: 45px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .no-image {
            width: 60px;
            height: 45px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            color: #6c7a92;
            border: 1px dashed rgba(255, 255, 255, 0.06);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state .icon {
            font-size: 4rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h3 {
            color: #ffcc00;
            font-size: 1.5rem;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #8a9bb0;
            font-size: 1rem;
        }

        /* ============================================
                   FOOTER
                   ============================================ */
        .footer-location {
            text-align: center;
            padding: 18px 20px;
            margin: 30px 25px 20px 25px;
            background: rgba(10, 14, 35, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-radius: 16px;
            border: 1px solid rgba(255, 204, 0, 0.06);
        }

        .footer-location p {
            color: #b0c4d8;
            font-size: 0.95rem;
        }

        .footer-location strong {
            color: #ffcc00;
        }

        /* ============================================
                   RESPONSIVE
                   ============================================ */
        @media (max-width: 768px) {
            .navbar {
                padding: 0 1.5rem;
                flex-wrap: wrap;
                height: auto;
                padding-top: 12px;
                padding-bottom: 12px;
                gap: 8px;
            }
            .nav-links {
                gap: 1rem;
                flex-wrap: wrap;
            }
            .nav-links a {
                font-size: 0.85rem;
            }
            .container {
                padding: 20px 15px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .page-header h1 {
                font-size: 1.5rem;
            }
            .table-container {
                padding: 15px;
            }
            .footer-location {
                margin: 20px 15px 15px 15px;
                padding: 14px;
            }
        }

        @media (max-width: 480px) {
            .navbar {
                padding: 10px 1rem;
            }
            .nav-links {
                gap: 0.6rem;
            }
            .nav-links a {
                font-size: 0.8rem;
            }
            .action-btns {
                flex-direction: column;
                gap: 4px;
            }
            .action-btn {
                font-size: 0.7rem;
                padding: 4px 10px;
            }
        }

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
    </style>
</head>
<body>

    <!-- ============================================
    NAVBAR
    ============================================ -->
    <nav class="navbar">
        <div class="logo">Rental Truck System</div>
      
    </nav>

    <!-- ============================================
    MAIN CONTENT
    ============================================ -->
    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1> <span>Manage</span> All Trucks</h1>
                <div class="subtitle">  Total: <?php echo count($trucks); ?> trucks</div>
            </div>
            <div class="header-actions">
                <a href="truck.php" class="btn btn-primary">➕ Register New Truck</a>
                <a href="admin.php" class="btn btn-back">← Back to Dashboard</a>
            </div>
        </div>

        <!-- Message -->
        <?php if (isset($message)): ?>
            <div class="message <?php echo $msg_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($editTruck): ?>
            <div class="table-container" style="margin-bottom:25px;">
                <h3> Edit Truck</h3>
                <form method="POST" action="truck_edit.php?id=<?php echo (int)$editTruck['truck_id']; ?>" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;">
                    <input type="hidden" name="truck_id" value="<?php echo (int)$editTruck['truck_id']; ?>">
                    <label>Truck Name<input class="edit-input" name="truck_name" value="<?php echo htmlspecialchars($editTruck['truck_name']); ?>" required></label>
                    <label>Plate Number<input class="edit-input" name="plate_number" value="<?php echo htmlspecialchars($editTruck['plate_number']); ?>" required></label>
                    <label>Capacity<input class="edit-input" name="capacity" value="<?php echo htmlspecialchars($editTruck['capacity']); ?>"></label>
                    <label>Fuel Type<input class="edit-input" name="fuel_type" value="<?php echo htmlspecialchars($editTruck['fuel_type']); ?>"></label>
                    <label>Cargo Type
                        <select class="edit-input" name="cargo_type" required>
                            <option value="">-- Select Cargo Type --</option>
                            <option value="Heavy trucks" <?php echo $editTruck['cargo_type'] === 'Heavy trucks' ? 'selected' : ''; ?>>Heavy trucks</option>
                            <option value="Town ice" <?php echo $editTruck['cargo_type'] === 'Town ice' ? 'selected' : ''; ?>>Town ice</option>
                            <option value="Refrigerated vans" <?php echo $editTruck['cargo_type'] === 'Refrigerated vans' ? 'selected' : ''; ?>>Refrigerated vans</option>
                            <option value="Meat van" <?php echo $editTruck['cargo_type'] === 'Meat van' ? 'selected' : ''; ?>>Meat van</option>
                            <option value="Guta" <?php echo $editTruck['cargo_type'] === 'Guta' ? 'selected' : ''; ?>>Guta</option>
                        </select>
                    </label>
                    <label>Status<select class="edit-input" name="status"><option value="available" <?php echo strtolower($editTruck['status']) === 'available' ? 'selected' : ''; ?>>Available</option><option value="maintenance" <?php echo strtolower($editTruck['status']) === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option></select></label>
                    <label>Replace Photo<input class="edit-input" type="file" name="image_url" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewTruckImage(event)"></label>
                    <div><small style="color:#b0c4d8;display:block;margin-bottom:6px;">Current / new photo</small><img id="truckImagePreview" src="<?php echo htmlspecialchars($editTruck['image_url'] ?: 'https://via.placeholder.com/160x100/1e3c72/ffcc00?text=Truck'); ?>" alt="Truck preview" style="width:160px;height:100px;object-fit:cover;border-radius:8px;"></div>
                    <div style="grid-column:1/-1;display:flex;gap:10px;"><button class="btn btn-primary" type="submit" name="save_truck">Save Changes</button><a class="btn btn-back" href="truck_edit.php">Cancel</a></div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Trucks Table -->
        <div class="table-container">
            <h3>All Registered Trucks</h3>

            <?php if (count($trucks) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Truck Name</th>
                            <th>Plate</th>
                            <th>Capacity</th>
                            <th>Cargo Type</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trucks as $truck): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($truck['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($truck['image_url']); ?>" 
                                             alt="<?php echo htmlspecialchars($truck['truck_name']); ?>"
                                             class="truck-img"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="no-image" style="display:none;">No img</div>
                                    <?php else: ?>
                                        <div class="no-image">No img</div>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($truck['truck_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($truck['plate_number']); ?></td>
                                <td><?php echo htmlspecialchars($truck['capacity']); ?></td>
                                <td><?php echo htmlspecialchars($truck['cargo_type']); ?></td>
                                <td>
                                    <?php 
                                    $status = strtolower($truck['status']);
                                    $statusClass = 'available';
                                    if ($status == 'booked' || $status == 'unavailable') {
                                        $statusClass = 'booked';
                                    } elseif ($status == 'maintenance') {
                                        $statusClass = 'maintenance';
                                    }
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <!-- Edit -->
                                        <a href="truck_edit.php?id=<?php echo $truck['truck_id']; ?>" class="action-btn edit">
                                             Edit
                                        </a>
                                        
                                        <!-- Maintenance -->
                                        <?php if ($status != 'maintenance'): ?>
                                            <a href="truck_edit.php?maintenance=<?php echo $truck['truck_id']; ?>" 
                                               class="action-btn maintenance"
                                               onclick="return confirm('Put this truck on MAINTENANCE?')">
                                                🔧 Maintenance
                                            </a>
                                        <?php else: ?>
                                            <a href="truck_edit.php?available=<?php echo $truck['truck_id']; ?>" 
                                               class="action-btn available"
                                               onclick="return confirm('Make this truck AVAILABLE again?')">
                                                ✅ Available
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Delete -->
                                        <a href="truck_edit.php?delete=<?php echo $truck['truck_id']; ?>" 
                                           class="action-btn delete"
                                           onclick="return confirm('⚠️ Are you sure you want to DELETE this truck permanently from the database?')">
                                            🗑️ Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon"></div>
                    <h3>No Trucks Registered Yet</h3>
                    <p>Click the "Register New Truck" button above to add your first truck.</p>
                </div>
            <?php endif; ?>
        </div>

        <script>
            function previewTruckImage(event) {
                const file = event.target.files[0];
                if (file) {
                    document.getElementById('truckImagePreview').src = URL.createObjectURL(file);
                }
            }
        </script>

    </div>

    <!-- ============================================
    FOOTER
    ============================================ -->
    <div class="footer-location">
        <p>
             <strong>Rental Truck System</strong> ·  
            <span style="color:#ffcc00;">admin@rentaltruck.com</span> · 
            📞 +255 710 903 148
        </p>
    </div>

    <!-- ============================================
    BACKGROUND SLIDESHOW
    ============================================ -->
    <script>
        const bgImages = [
            "image/volvo 3.jpg", "image/meat van3.jfif"
        ];

        let bgIndex = 0;
        const body = document.body;

        function changeBackground() {
            body.style.backgroundImage = `url('${bgImages[bgIndex]}')`;
            bgIndex = (bgIndex + 1) % bgImages.length;
        }

        changeBackground();
        setInterval(changeBackground, 6000);
    </script>

</body>
</html>

<?php
$conn->close();
?>