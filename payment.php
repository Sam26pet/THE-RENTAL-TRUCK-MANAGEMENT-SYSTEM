<?php
session_start();

function paymentAlert(string $message): void {
  echo '<script src="app-alert.js"></script><script>document.addEventListener("DOMContentLoaded",function(){showAppAlert(' . json_encode($message) . ', "Payment issue");});</script>';
}

$pending = $_SESSION['pending_booking'] ?? null;
if (!$pending || (time() - (int)($pending['created_at'] ?? 0)) > 1800) {
  unset($_SESSION['pending_booking']);
  header('Location: truck_register.php');
  exit;
}

$conn = new mysqli('localhost', 'root', '', 'rental_truck');
if ($conn->connect_error) {
  die('Unable to connect to the payment database.');
}
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS pickup_lat DECIMAL(10,7) NULL AFTER destination");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS pickup_lng DECIMAL(10,7) NULL AFTER pickup_lat");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS destination_lat DECIMAL(10,7) NULL AFTER pickup_lng");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS destination_lng DECIMAL(10,7) NULL AFTER destination_lat");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS original_cost DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER distance");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER original_cost");
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_percent");
$conn->query("CREATE TABLE IF NOT EXISTS payments (
  payment_id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id INT NULL,
  fullname VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL,
  payfor VARCHAR(80) DEFAULT 'Truck booking',
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

$previewCompletedBookings = 0;
$previewStmt = $conn->prepare("SELECT COUNT(*) AS completed_bookings FROM booking b INNER JOIN payments p ON p.booking_id = b.booking_id WHERE LOWER(b.email) = LOWER(?) AND LOWER(COALESCE(p.payment_status, '')) IN ('paid', 'completed', 'success')");
if ($previewStmt) {
  $previewStmt->bind_param('s', $pending['email']);
  $previewStmt->execute();
  $previewCompletedBookings = (int)($previewStmt->get_result()->fetch_assoc()['completed_bookings'] ?? 0);
  $previewStmt->close();
}
$previewFullTripCost = (float)$pending['cost'];
$previewDiscountPercent = $previewCompletedBookings >= 5 ? 10 : 0;
$previewDiscountAmount = round($previewFullTripCost * ($previewDiscountPercent / 100), 2);
$previewFinalCost = round($previewFullTripCost - $previewDiscountAmount, 2);

$paymentColumns = [
  'booking_id' => "ALTER TABLE payments ADD COLUMN booking_id INT NULL",
  'fullname' => "ALTER TABLE payments ADD COLUMN fullname VARCHAR(100) NULL",
  'email' => "ALTER TABLE payments ADD COLUMN email VARCHAR(150) NULL",
  'payfor' => "ALTER TABLE payments ADD COLUMN payfor VARCHAR(80) NULL DEFAULT 'Truck booking'",
  'method' => "ALTER TABLE payments ADD COLUMN method VARCHAR(40) NULL",
  'bankname' => "ALTER TABLE payments ADD COLUMN bankname VARCHAR(100) NULL",
  'account_number' => "ALTER TABLE payments ADD COLUMN account_number VARCHAR(100) NULL",
  'simcardprovider' => "ALTER TABLE payments ADD COLUMN simcardprovider VARCHAR(50) NULL",
  'phone_number' => "ALTER TABLE payments ADD COLUMN phone_number VARCHAR(30) NULL",
  'amount' => "ALTER TABLE payments ADD COLUMN amount DECIMAL(10,2) NULL",
  'payment_reference' => "ALTER TABLE payments ADD COLUMN payment_reference VARCHAR(80) NULL",
  'payment_status' => "ALTER TABLE payments ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'paid'",
  'paid_at' => "ALTER TABLE payments ADD COLUMN paid_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP"
];
foreach ($paymentColumns as $columnName => $migration) {
  $columnCheck = $conn->query("SHOW COLUMNS FROM payments LIKE '" . $conn->real_escape_string($columnName) . "'");
  if ($columnCheck && $columnCheck->num_rows === 0) {
    $conn->query($migration);
  }
}

// Keep the payment relation aligned with the application's singular booking table.
$foreignKeyResult = $conn->query("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'payments'
    AND COLUMN_NAME = 'booking_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
  LIMIT 1");
if ($foreignKeyResult && ($foreignKey = $foreignKeyResult->fetch_assoc()) && strtolower($foreignKey['REFERENCED_TABLE_NAME']) !== 'booking') {
  $constraintName = str_replace('`', '', $foreignKey['CONSTRAINT_NAME']);
  $conn->query("ALTER TABLE payments DROP FOREIGN KEY `" . $conn->real_escape_string($constraintName) . "`");
  $parentTable = $conn->query("SHOW TABLES LIKE 'booking'");
  if ($parentTable && $parentTable->num_rows > 0) {
    $conn->query("ALTER TABLE payments ADD CONSTRAINT payments_booking_fk FOREIGN KEY (booking_id) REFERENCES booking(booking_id)");
  }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $method = trim($_POST['payment_method'] ?? '');
  $bankName = trim($_POST['bank_name'] ?? '');
  $accountNumber = trim($_POST['account_number'] ?? '');
  $provider = trim($_POST['sim_provider'] ?? '');
  $phoneNumber = trim($_POST['phone_number'] ?? '');
  $confirmed = isset($_POST['payment_confirmation']);
  if (!$confirmed) {
    $error = 'Please confirm that you have completed the payment before submitting.';
  } elseif (!in_array($method, ['bank', 'mobile'], true)) {
    $error = 'Please select a payment method.';
  } elseif ($method === 'bank' && (!$bankName || !$accountNumber)) {
    $error = 'Please provide the bank name and account number.';
  } elseif ($method === 'mobile' && (!$provider || !$phoneNumber)) {
    $error = 'Please provide the mobile provider and phone number.';
  } else {
    try {
      $conn->begin_transaction();
      
      // Verify truck availability
      $truckStmt = $conn->prepare('SELECT status, truck_name FROM trucks WHERE truck_id = ? FOR UPDATE');
      $truckStmt->bind_param('i', $pending['truck_id']);
      $truckStmt->execute();
      $truck = $truckStmt->get_result()->fetch_assoc();
      $truckStmt->close();
      
      if (!$truck || strtolower($truck['status']) !== 'available') {
        throw new RuntimeException('This truck is no longer available. Please choose another truck.');
      }

      // Calculate cost & discount
      $originalCost = (float)$pending['cost'];
      $discountPercent = 0.0;
      $discountAmount = 0.0;
      $finalCost = $originalCost;
      
      // Check loyalty discount (5+ completed bookings = 10% off)
      $loyaltyStmt = $conn->prepare("SELECT COUNT(*) AS completed_bookings FROM booking b INNER JOIN payments p ON p.booking_id = b.booking_id WHERE LOWER(b.email) = LOWER(?) AND LOWER(COALESCE(p.payment_status, '')) IN ('paid', 'completed', 'success')");
      $loyaltyStmt->bind_param('s', $pending['email']);
      $loyaltyStmt->execute();
      $completedBookings = (int)($loyaltyStmt->get_result()->fetch_assoc()['completed_bookings'] ?? 0);
      $loyaltyStmt->close();
      
      if ($completedBookings >= 5) {
        $discountPercent = 10.0;
        $discountAmount = round($originalCost * 0.10, 2);
        $finalCost = round($originalCost - $discountAmount, 2);
      }
      
      // Every booking reserves one truck for one customer.
      $bookingStatus = 'active';
      
      // INSERT BOOKING
      $bookingStmt = $conn->prepare("INSERT INTO booking (truck_id, truck_name, booking_status, fullname, email, phone, trip_date, pickup, destination, distance, original_cost, discount_percent, discount_amount, cost, pickup_lat, pickup_lng, destination_lat, destination_lng, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
      if (!$bookingStmt) {
        throw new RuntimeException('Booking insert failed: ' . $conn->error);
      }
      $bookingStmt->bind_param('issssssssddddddddd', 
        $pending['truck_id'], 
        $pending['truck_name'], 
        $bookingStatus, 
        $pending['fullname'], 
        $pending['email'], 
        $pending['phone'], 
        $pending['trip_date'], 
        $pending['pickup'], 
        $pending['destination'], 
        $pending['distance'], 
        $originalCost, 
        $discountPercent, 
        $discountAmount, 
        $finalCost, 
        $pending['pickup_lat'], 
        $pending['pickup_lng'], 
        $pending['destination_lat'], 
        $pending['destination_lng']
      );
      $bookingStmt->execute();
      $bookingId = $bookingStmt->insert_id;
      $bookingStmt->close();

      // UPDATE TRUCK STATUS TO BOOKED
      $statusStmt = $conn->prepare("UPDATE trucks SET status = 'booked' WHERE truck_id = ?");
      $statusStmt->bind_param('i', $pending['truck_id']);
      $statusStmt->execute();
      $statusStmt->close();

      // INSERT PAYMENT
      $reference = 'PMT-' . strtoupper(bin2hex(random_bytes(5)));
      $payStmt = $conn->prepare("INSERT INTO payments (booking_id, fullname, email, payfor, method, bankname, account_number, simcardprovider, phone_number, amount, payment_reference, payment_status) VALUES (?, ?, ?, 'Truck booking', ?, ?, ?, ?, ?, ?, ?, 'paid')");
      if (!$payStmt) {
        throw new RuntimeException('Payment insert failed: ' . $conn->error);
      }
      $payStmt->bind_param('issssssdss', 
        $bookingId, 
        $pending['fullname'], 
        $pending['email'], 
        $method, 
        $bankName, 
        $accountNumber, 
        $provider, 
        $phoneNumber, 
        $finalCost, 
        $reference
      );
      $payStmt->execute();
      $payStmt->close();
      $conn->commit();

      $_SESSION['payment_receipt'] = [
        'reference' => $reference,
        'booking_id' => $bookingId,
        'fullname' => $pending['fullname'],
        'email' => $pending['email'],
        'phone' => $pending['phone'],
        'truck_name' => $pending['truck_name'],
        'truck_plate' => $pending['truck_plate'] ?? '',
        'truck_capacity' => $pending['truck_capacity'] ?? '',
        'truck_fuel' => $pending['truck_fuel'] ?? '',
        'truck_cargo' => $pending['cargo_type'] ?? '',
        'truck_image' => $pending['truck_image'] ?? '',
        'pickup' => $pending['pickup'],
        'destination' => $pending['destination'],
        'trip_date' => $pending['trip_date'],
        'amount' => $finalCost,
        'full_trip_amount' => $fullTripCost,
        'original_amount' => $originalCost,
        'discount_percent' => $discountPercent,
        'discount_amount' => $discountAmount,
        'method' => $method,
        'provider' => $provider,
        'bank_name' => $bankName,
        'account_number' => $accountNumber,
        'phone_number' => $phoneNumber,
        'paid_at' => date('Y-m-d H:i:s')
      ];
      unset($_SESSION['pending_booking']);
      header('Location: payment_success.php');
      exit;
    } catch (Throwable $exception) {
      $conn->rollback();
      $error = $exception->getMessage();
    }
  }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Process Payment</title>
    <style>
     /* Reset */
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: "Segoe UI", Arial, sans-serif;
}

body {
  background: #121212; /* dark background */
  color: #f1f1f1;
  line-height: 1.6;
  transition: background-image 1s ease-in-out;
  background-size: cover;
  background-position: center;
}

.payment-summary {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 24px;
  padding: 16px;
  border-radius: 10px;
  background: rgba(21, 54, 63, .9);
  color: #effffb;
  border: 1px solid rgba(143, 224, 207, .3);
  box-shadow: 0 8px 20px rgba(0, 0, 0, .18);
}

.payment-summary div { display: flex; flex-direction: column; gap: 3px; }
.payment-summary small { color: #9fd4c8; font-weight: 700; text-transform: uppercase; }
.payment-summary strong { font-size: 15px; overflow-wrap: anywhere; }
.payment-error { margin-bottom: 18px; padding: 12px; border-radius: 8px; background: #fde8eb; color: #a61b2b; font-weight: 700; }
.payment-confirmation label { display: flex; align-items: center; gap: 11px; color: #effffb; font-size: 14px; font-weight: 700; }
.payment-confirmation { margin-top: 8px; padding: 15px 16px; border: 1px solid rgba(143, 224, 207, .45); border-radius: 10px; background: rgba(20, 77, 70, .78); box-shadow: 0 6px 16px rgba(0, 0, 0, .14); }
.payment-confirmation label { cursor: pointer; }
.payment-confirmation input { width: 20px; height: 20px; accent-color: #73d6c2; }

.booked-truck-photo {
  width: 100%;
  height: 190px;
  display: block;
  object-fit: cover;
  object-position: center;
  border: 1px solid rgba(143, 224, 207, .35);
  border-radius: 12px;
  background: #16353d;
}

.booked-truck-photo-frame {
  position: relative;
  margin-bottom: 18px;
  overflow: hidden;
  border-radius: 12px;
  background: #16353d;
}

.booked-truck-photo-caption {
  position: absolute;
  right: 12px;
  bottom: 10px;
  left: 12px;
  padding: 7px 10px;
  border-radius: 7px;
  background: rgba(5, 22, 27, .78);
  color: #effffb;
  font-size: 13px;
  font-weight: 700;
}

/* Header */
header {
  background-color: #1e3c72; /* dark blue */
  color: #ffcc00;            /* yellow text */
  padding: 20px;
  text-align: center;
  font-weight: bold;
  font-size: 22px;
}

/* Payment Container */
.payment-container {
  background: rgba(22,33,62,0.9); /* semi-transparent dark blue */
  width: 90%;
  max-width: 600px;
  margin: 40px auto;
  padding: 30px;
  border-radius: 12px;
  box-shadow: 0 6px 12px rgba(0,0,0,.6);
}

.btn-primary {
  background: linear-gradient(135deg,#ffcc00,#ff8800);
  color: #121212;
  padding: 10px 20px;
  border: none;
  border-radius: 6px;
  font-weight: bold;
  cursor: pointer;
  transition: .3s;
  text-decoration: none;
}


/* Title */
h2 {
  text-align: center;
  color: #ffcc00; /* yellow heading */
  margin-bottom: 20px;
  font-size: 24px;
}

/* Form Groups */
.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  font-weight: bold;
  margin-bottom: 8px;
  color: #ffcc00; /* yellow labels */
}

.form-group input,
.form-group select {
  width: 100%;
  padding: 10px;
  border-radius: 8px;
  border: 1px solid #ccc;
  background: #1e3c72; /* dark blue inputs */
  color: #f1f1f1;
  transition: border .3s;
}

.form-group input:focus,
.form-group select:focus {
  border: 1px solid #ffcc00;
  outline: none;
}

/* Buttons */
.form-btn {
  display: inline-block;
  background: linear-gradient(135deg,#ffcc00,#ff8800);
  color: #121212;
  border: none;
  padding: 10px 20px;
  font-size: 16px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
  transition: .3s;
  text-decoration: none;
}

.form-btn:hover {
  background: linear-gradient(135deg,#ffd633,#ff9933);
  transform: scale(1.05);
}

body { background-color: #0f252b; color: #e7f6f2; }
header { background: #1e3c72; padding: 22px 20px; color: #fff; }
header h1 { margin: 0; font-size: 28px; letter-spacing: .3px; }
.payment-container { background: rgba(10, 35, 42, .94); max-width: 760px; padding: 34px 38px; border: 1px solid rgba(143, 224, 207, .24); border-radius: 18px; box-shadow: 0 18px 50px rgba(0, 0, 0, .35); }
h2 { color: #b9f1e4; font-size: 28px; margin-bottom: 24px; }
.form-group { margin-bottom: 18px; }
.form-group label { color: #b9d9d3; font-size: 13px; letter-spacing: .35px; text-transform: uppercase; }
.form-group input, .form-group select { padding: 13px 14px; border: 1px solid rgba(143, 224, 207, .3); background: #16353d; color: #effffb; font-size: 15px; }
.form-group input:focus, .form-group select:focus { border-color: #73d6c2; box-shadow: 0 0 0 3px rgba(115,214,194,.16); }
.form-btn { padding: 13px 22px; border-radius: 9px; background: #1e3c72; color: #fff; }
.form-btn:hover { background: #247f74; transform: translateY(-1px); }
.btn-primary { display: inline-block; border-radius: 9px; background: #e2e8f0; color: #334155; }
@media(max-width:600px){.payment-container{width:calc(100% - 24px);padding:25px 20px}.payment-summary{grid-template-columns:1fr}.form-btn,.btn-primary{display:block;width:100%;text-align:center;margin-top:10px}}
    </style>
</head>
<body>
<header>
    <h1>Rental Truck</h1>
</header>
<div class="payment-container">
    <h2>Complete Your Payment</h2>
    <?php if ($error): ?><div class="payment-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php
      $bookedTruckImage = !empty($pending['truck_image']) ? $pending['truck_image'] : 'image/volvo 3.jpg';
      $bookedTruckName = $pending['truck_name'] ?? 'Booked truck';
    ?>
    <div class="booked-truck-photo-frame">
      <img class="booked-truck-photo" src="<?php echo htmlspecialchars($bookedTruckImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($bookedTruckName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.src='image/volvo 3.jpg'">
      <div class="booked-truck-photo-caption">Your booked truck: <?php echo htmlspecialchars($bookedTruckName, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div class="payment-summary">
      <div><small>Customer</small><strong><?php echo htmlspecialchars($pending['fullname']); ?></strong></div>
      <div><small>Truck</small><strong><?php echo htmlspecialchars($pending['truck_name']); ?></strong></div>
      <div><small>Trip</small><strong><?php echo htmlspecialchars($pending['pickup']); ?> to <?php echo htmlspecialchars($pending['destination']); ?></strong></div>
      <div><small>Full Trip Cost</small><strong><?php echo number_format($previewFullTripCost, 2); ?> TZS</strong></div>
      <div><small>Your Trip Cost</small><strong><?php echo number_format($previewFullTripCost, 2); ?> TZS</strong></div>
      <div><small>Discount</small><strong><?php echo $previewDiscountPercent ? '-' . number_format($previewDiscountAmount, 2) . ' TZS (' . number_format($previewDiscountPercent, 0) . '%)' : 'No discount'; ?></strong></div>
      <div><small>Amount Due</small><strong id="amountDue"><?php echo number_format($previewFinalCost, 2); ?> TZS</strong></div>
    </div>
    <form action="payment.php" method="post">
        <div class="form-group">
            <label for="fullname">Full Name:</label>
        <input type="text" id="fullname" name="fullname" value="<?php echo htmlspecialchars($pending['fullname']); ?>" readonly>
        </div>

        <div class="form-group">
            <label for="email">Email Address:</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($pending['email']); ?>" readonly>
        </div>

       

        <div class="form-group">
            <label for="payment_method">Payment Method:</label>
            <select id="payment_method" name="payment_method" required onchange="toggleFields()">
                <option value="">-- Select Method --</option>
                <option value="bank">Bank</option>
                <option value="mobile">Mobile Network</option>
            </select>
        </div>

        <!-- Bank fields -->
        <div id="bankFields" style="display:none;">
            <div class="form-group">
                <label for="bank_name">Bank Name:</label>
                <select id="bank_name" name="bank_name">
                    <option value="">-- Select Bank --</option>
                    <option value="CRDB Bank">CRDB Bank</option>
                    <option value="NMB Bank">NMB Bank</option>
                    <option value="Standard Chartered Bank">Standard Chartered Bank</option>
                    <option value="Exim Bank">Exim Bank</option>
                    <option value="Equity Bank">Equity Bank</option>
                    <option value="NBC Bank">NBC Bank</option>
                </select>
            </div>
            <div class="form-group">
                <label for="account_number">Account Number:</label>
                <input type="text" id="account_number" name="account_number">
            </div>
        </div>

        <!-- Mobile fields -->
        <div id="mobileFields" style="display:none;">
            <div class="form-group">
                <label for="sim_provider">Simcard Provider:</label>
                <select id="sim_provider" name="sim_provider">
                    <option value="">-- Select Provider --</option>
                    <option value="Vodacom">Vodacom</option>
                    <option value="Airtel">Airtel</option>
                    <option value="Tigo">Tigo</option>
                    <option value="Halotel">Halotel</option>
                </select>
            </div>
            <div class="form-group">
                <label for="phone_number">Phone Number:</label>
                <input type="text" id="phone_number" name="phone_number">
            </div>
        </div>

        <div class="form-group">
            <label for="amount">Amount (Tsh):</label>
            <input type="number" id="amount" name="amount" value="<?php echo htmlspecialchars((string)$previewFinalCost); ?>" readonly>
        </div>

      <div class="form-group payment-confirmation">
        <label><input type="checkbox" name="payment_confirmation" required> I confirm that I have completed this payment.</label>
      </div>

        <button type="submit" class="form-btn">Submit Payment</button>
        <a href="truck_register.php" class="btn-primary">Back to Trucks</a>
    </form>
</div>

<script>
function toggleFields() {
    var method = document.getElementById("payment_method").value;
    document.getElementById("bankFields").style.display = (method === "bank") ? "block" : "none";
    document.getElementById("mobileFields").style.display = (method === "mobile") ? "block" : "none";
}

// Background slideshow
const images = [
  "image/volvo 3.jpg", "image/meat van3.jfif"
];
let currentIndex = 0;

function changeBackground() {
  document.body.style.backgroundImage = `url('${images[currentIndex]}')`;
  currentIndex = (currentIndex + 1) % images.length;
}

changeBackground();
setInterval(changeBackground, 6000);
</script>
</body>
</html>
