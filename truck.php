<?php
require_once __DIR__ . '/admin_access.php';
requireAdminSession();
// Connection details
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "rental_truck";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Add the rate column automatically for existing installations.
$conn->query("ALTER TABLE trucks ADD COLUMN IF NOT EXISTS price_per_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER cargo_type");
$alertMessage = '';
$alertType = 'error';
$alertRedirect = '';

// Run only if form submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Collect form data safely
    $truck_name   = isset($_POST['truck_name']) ? trim($_POST['truck_name']) : '';
    $plate_number = isset($_POST['plate_number']) ? trim($_POST['plate_number']) : '';
    $capacity     = isset($_POST['capacity']) ? trim($_POST['capacity']) : '';
    $fuel_type    = isset($_POST['fuel_type']) ? trim($_POST['fuel_type']) : '';
    $cargo_type    = isset($_POST['cargo_type']) ? trim($_POST['cargo_type']) : '';
    $status       = isset($_POST['status']) ? trim($_POST['status']) : 'available';
    $cargoRates = [
      'Guta' => 1200,
      'Meat van' => 1800,
      'Refrigerated vans' => 2500,
      'Town ice' => 3000,
      'Heavy trucks' => 3800
    ];
    $price_per_km = $cargoRates[$cargo_type] ?? 0;
    if (!in_array($status, ['available', 'maintenance'], true)) {
      $status = 'available';
    }

    // Handle file upload
    $image        = isset($_FILES['image_url']['name']) ? $_FILES['image_url']['name'] : '';
    $target_dir   = "uploads/trucks/";

    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $target_file = $target_dir . basename($image);

    if (!empty($image)) {
        move_uploaded_file($_FILES["image_url"]["tmp_name"], $target_file);
    }

    // Prevent empty plate_number (unique field)
    if (!empty($plate_number) && $price_per_km > 0) {
      $stmt = $conn->prepare("INSERT INTO trucks (truck_name, plate_number, capacity, fuel_type, cargo_type, price_per_km, image_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("sssssdss", $truck_name, $plate_number, $capacity, $fuel_type, $cargo_type, $price_per_km, $target_file, $status);

        try {
          if ($stmt->execute()) {
            $alertMessage = 'Truck registered successfully.';
            $alertType = 'success';
            $alertRedirect = 'truck.php';
          }
        } catch (mysqli_sql_exception $exception) {
          if ($exception->getCode() === 1062 || strpos($exception->getMessage(), 'plate_number') !== false) {
            $alertMessage = 'This plate number is already registered. Please enter a different plate number.';
          } else {
            $alertMessage = 'The truck could not be registered. Please review the details and try again.';
          }
        }
    } else {
        $alertMessage = 'Plate number is required and must be unique.';
    }
}

$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Register Truck</title>
   <script src="app-alert.js"></script>
  
 <style>
*{box-sizing:border-box}
body{min-height:100vh;margin:0;padding:28px 18px;font-family:"Segoe UI",Arial,sans-serif;color:#172b4d;background-size:cover;background-position:center;background-attachment:fixed}
body::before{content:"";position:fixed;inset:0;background:linear-gradient(135deg,rgba(8,24,52,.82),rgba(21,96,91,.62));z-index:-1}
header{width:min(100%,900px);margin:0 auto 18px;padding:18px 24px;border:1px solid rgba(255,255,255,.2);border-radius:18px;background:rgba(8,27,58,.82);box-shadow:0 14px 34px rgba(0,0,0,.22)}
header h2{margin:0;color:#f9d342;font-size:clamp(22px,4vw,32px);letter-spacing:.2px}
.payment-container{width:min(100%,900px);margin:0 auto;padding:30px;background:rgba(13,38,53,.94);border:1px solid rgba(166,213,204,.3);border-radius:22px;box-shadow:0 24px 65px rgba(0,0,0,.3)}
form{display:grid;grid-template-columns:1fr 1fr;gap:18px 20px}
.form-group{margin:0}.form-group label{display:block;margin-bottom:7px;color:#d7ebe6;font-size:13px;font-weight:800;letter-spacing:.3px;text-transform:uppercase}
.form-group input,.form-group select{width:100%;min-height:48px;padding:12px 14px;border:1px solid #80aaa3;border-radius:11px;background:#dcebe8;color:#172b4d;font-size:15px;transition:.2s}
.form-group input:focus,.form-group select:focus{border-color:#f9d342;outline:0;box-shadow:0 0 0 4px rgba(249,211,66,.16);background:#edf5f3}
.form-group:nth-child(5),.form-group:nth-child(6),.form-group:nth-child(7){grid-column:1/-1}
.form-group:nth-child(6) input{padding:9px 12px}
.form-btn{min-height:48px;width:100%;padding:12px 18px;border:0;border-radius:11px;background:#1e3c72;color:#fff;font-size:15px;font-weight:800;cursor:pointer;transition:.2s}
.form-btn:hover{background:#247f74;transform:translateY(-2px);box-shadow:0 8px 18px rgba(36,127,116,.22)}
.form-btn.secondary{background:#42636a}
.form-btn.secondary:hover{background:#527b7c}
form>.form-btn{grid-column:span 1}
form>br{display:none}
@media(max-width:650px){body{padding:16px 12px}.payment-container{padding:22px 18px}form{grid-template-columns:1fr}.form-group:nth-child(5),.form-group:nth-child(6),.form-group:nth-child(7),form>.form-btn{grid-column:auto}}
 </style>
  <script>
    // Background changer
    const images = [
      "image/volvo 3.jpg", "image/meat van3.jfif"
    ];
    let currentIndex = 0;
    function changeBackground() {
      document.body.style.backgroundImage = `url('${images[currentIndex]}')`;
      currentIndex = (currentIndex + 1) % images.length;
    }
    setInterval(changeBackground, 5000);
    window.onload = changeBackground;
  </script>
</head>
<body>
  <header>
    <h2>Truck Registration</h2>
  </header>

  <div class="payment-container">
    <form action="truck.php" method="POST" enctype="multipart/form-data">
      <div class="form-group">
        <label>Truck Name</label>
        <input type="text" name="truck_name" required>
      </div>

      <div class="form-group">
        <label>Plate Number</label>
        <input type="text" name="plate_number" required>
      </div>

      <div class="form-group">
        <label>Capacity</label>
        <input type="text" name="capacity" required>
      </div>

      <div class="form-group">
        <label>Fuel Type</label>
        <input type="text" name="fuel_type" required>
      </div>
       <div class="form-group">
  <label>Cargo Type</label>
  <select name="cargo_type" required>
    <option value="">-- Select Cargo Type --</option>
    <option value="Heavy trucks">Heavy trucks</option>
    <option value="Town ice">Town ice</option>
    <option value="Refrigerated vans">Refrigerated vans</option>
    <option value="Meat van">Meat van</option>
    <option value="Guta">Guta</option>
  </select>
</div>


      <div class="form-group">
        <label>Truck Image</label>
        <input type="file" name="image_url" accept="image/*" required>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status" required>
          <option value="available">Available</option>
          <option value="maintenance">Maintenance</option>
        </select>
      </div>

      <button type="submit" class="form-btn">Save Truck</button><br>
      <button type="button" class="form-btn secondary" onclick="window.location.href='admin.php'">Go back</button>
    </form>
  </div>
  <?php if ($alertMessage !== ''): ?>
    <script>
      showAppAlert(<?php echo json_encode($alertMessage); ?>, <?php echo json_encode($alertType === 'success' ? 'Truck registered' : 'Registration issue'); ?>);
      <?php if ($alertRedirect !== ''): ?>
        setTimeout(() => { window.location.href = <?php echo json_encode($alertRedirect); ?>; }, 1800);
      <?php endif; ?>
    </script>
  <?php endif; ?>
</body>
</html>
