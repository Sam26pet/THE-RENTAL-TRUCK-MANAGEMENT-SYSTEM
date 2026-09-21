<?php
session_start();

$receipt = null;
$error = '';
$hasCustomerSession = !empty($_SESSION['user_id']) && !empty($_SESSION['user_email']);
$reference = trim($_GET['reference'] ?? '');
$identity = trim($_GET['identity'] ?? '');

function receiptLookupValue($value, string $fallback = '—'): string
{
    return htmlspecialchars((string)($value ?: $fallback), ENT_QUOTES, 'UTF-8');
}

if (!$hasCustomerSession) {
    $error = 'No booking records are linked to this session. Please log in and complete a booking first.';
} elseif ($reference !== '' && $identity !== '') {
    $email = $_SESSION['user_email'];
    $conn = new mysqli('localhost', 'root', '', 'rental_truck');

    if ($conn->connect_error) {
        $error = 'Receipt records are temporarily unavailable. Please try again later.';
    } else {
        $sql = "SELECT p.payment_reference, p.payment_status, p.paid_at,
                       p.fullname, p.email, p.method, p.bankname, p.account_number,
                       p.simcardprovider, p.phone_number, p.amount,
                       b.booking_id, b.phone, b.trip_date, b.pickup, b.destination,
                       b.distance, b.original_cost, b.discount_percent, b.discount_amount,
                       COALESCE(b.truck_name, t.truck_name) AS truck_name,
                       t.plate_number, t.capacity, t.fuel_type, t.cargo_type,
                                             t.image_url, d.full_name AS driver_name,
                                             d.phone AS driver_phone, d.license_number AS driver_license
                FROM payments p
                LEFT JOIN booking b ON b.booking_id = p.booking_id
                LEFT JOIN trucks t ON t.truck_id = b.truck_id
                                LEFT JOIN truck_driver_assignments a ON a.booking_id = b.booking_id
                                LEFT JOIN drivers d ON d.driver_id = a.driver_id
                WHERE p.payment_reference = ?
                  AND LOWER(COALESCE(p.payment_status, '')) IN ('paid', 'completed', 'success')
                                    AND LOWER(p.email) = LOWER(?)
                  AND (
                                            LOWER(p.email) = LOWER(?)
                                            OR CAST(p.booking_id AS CHAR) = ?
                  )
                LIMIT 1";
        $stmt = $conn->prepare($sql);
                $stmt->bind_param('ssss', $reference, $email, $email, $identity);
        $stmt->execute();
        $receipt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();

        if (!$receipt) {
            $error = 'No completed booking or payment receipt matches those details.';
        }
    }
}

$truckImage = $receipt['image_url'] ?? 'image/volvo 3.jpg';
$isMobilePayment = ($receipt['method'] ?? '') === 'mobile';
$channelName = $isMobilePayment ? ($receipt['simcardprovider'] ?? 'Mobile Network') : ($receipt['bankname'] ?? 'Bank');
$channelNumber = $isMobilePayment ? ($receipt['phone_number'] ?? '—') : ($receipt['account_number'] ?? '—');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Payment Receipt</title>
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            padding: 28px 16px;
            color: #f1f5f3;
            font-family: "Segoe UI", Arial, sans-serif;
            background: linear-gradient(135deg, rgba(8, 24, 52, .88), rgba(21, 96, 91, .74)), url('image/volvo 3.jpg') center / cover fixed;
        }
        .page { width: min(100%, 760px); margin: 0 auto; }
        .back-link { display: inline-block; margin-bottom: 18px; color: #fff0b2; font-weight: 800; text-decoration: none; }
        .back-link:hover { color: #fff; }
        .lookup-card, .receipt-card {
            overflow: hidden;
            background: #d7e4e1;
            color: #182235;
            border-radius: 18px;
            box-shadow: 0 22px 58px rgba(3, 18, 35, .35);
        }
        .lookup-card { padding: 28px; }
        h1 { margin: 0 0 8px; color: #075568; font-size: 28px; }
        .intro { margin: 0 0 22px; color: #60758a; line-height: 1.55; }
        .error { padding: 14px 16px; color: #8b2e2e; background: #fde8e8; border-radius: 10px; font-weight: 700; }
        .lookup-form { display: grid; gap: 14px; }
        .lookup-form label { display: grid; gap: 6px; color: #245653; font-size: 13px; font-weight: 800; }
        .lookup-form input { width: 100%; padding: 12px 13px; color: #182235; background: #edf3f1; border: 1px solid #a9c2bd; border-radius: 9px; font: inherit; }
        .lookup-form input:focus { outline: 3px solid rgba(40, 106, 104, .2); border-color: #286a68; }
        .lookup-submit { padding: 12px; color: #f1f5f3; background: #286a68; border: 0; border-radius: 9px; font-weight: 800; cursor: pointer; }
        .receipt-card { margin-top: 24px; }
        .receipt-head { padding: 26px 30px; color: #f1f5f3; background: #205653; }
        .receipt-head h2 { margin: 0 0 5px; font-size: 25px; }
        .receipt-head p { margin: 0; color: #d3e1df; }
        .receipt-body { padding: 26px 30px; background: #d7e4e1; }
        .paid { display: inline-block; margin-bottom: 18px; padding: 7px 12px; color: #245b45; font-weight: 800; background: #c9e2d5; border-radius: 20px; }
        .truck-summary { display: grid; grid-template-columns: 150px 1fr; gap: 18px; align-items: center; padding: 16px; background: #c8dbd6; border: 1px solid #a9c2bd; border-radius: 12px; }
        .truck-summary img { width: 150px; height: 105px; object-fit: cover; border: 2px solid #b8cdca; border-radius: 9px; }
        .truck-summary h3 { margin: 0 0 8px; color: #245653; }
        .route, .details, .channel { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 18px; padding: 16px; border-radius: 11px; }
        .route { background: #dce9e6; border: 1px solid #bfd3cf; }
        .details { background: #dfeae7; border: 1px solid #c5d7d2; }
        .channel { background: #eee8d8; border: 1px solid #d8cfae; }
        small { display: block; margin-bottom: 4px; color: #64777a; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        strong { color: #245653; overflow-wrap: anywhere; }
        .channel strong { color: #665b37; }
        .total { display: flex; justify-content: space-between; margin-top: 18px; padding: 16px; color: #245653; font-size: 19px; font-weight: 800; background: #dce9e6; border-radius: 10px; }
        .actions { display: flex; gap: 12px; margin-top: 22px; }
        .actions a, .actions button { flex: 1; padding: 12px; border: 0; border-radius: 9px; text-align: center; text-decoration: none; font-weight: 800; cursor: pointer; }
        .print { color: #fff; background: #286a68; }
        .back { color: #30494b; background: #d5dfdf; }
        @media (max-width: 560px) {
            .lookup-card, .receipt-body { padding: 22px 18px; }
            .receipt-head { padding: 24px 18px; }
            .truck-summary, .route, .details, .channel { grid-template-columns: 1fr; }
            .truck-summary img { width: 100%; height: 180px; }
            .actions { flex-direction: column; }
        }
        @media print {
            @page { size: A4; margin: 8mm; }
            html, body { min-height: 0; padding: 0; background: #fff !important; }
            body { color: #182235 !important; }
            .page { display: block !important; width: 100% !important; margin: 0 !important; }
            .lookup-card, .back-link, .actions { display: none !important; }
            .receipt-card { display: block !important; visibility: visible !important; width: 100%; margin: 0; box-shadow: none; border-radius: 0; page-break-inside: auto; }
            .receipt-card * { visibility: visible !important; }
            .receipt-head { padding: 14px 18px; }
            .receipt-head h2 { font-size: 21px; }
            .receipt-body { padding: 14px 18px; }
            .paid { margin-bottom: 10px; padding: 5px 10px; }
            .truck-summary { gap: 12px; padding: 10px; }
            .truck-summary img { width: 105px; height: 72px; }
            .truck-summary h3 { margin-bottom: 4px; font-size: 16px; }
            .route, .details, .channel { gap: 8px; margin-top: 10px; padding: 10px; }
            .total { gap: 8px; margin-top: 10px; padding: 10px; font-size: 13px; }
            .truck-summary, .route, .details, .channel, .total { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <main class="page">
        <a class="back-link" href="truck_register.php">← Back to truck selection</a>
        <?php if (!$receipt): ?>
            <section class="lookup-card">
                <h1>Find Your Payment Receipt</h1>
                <p class="intro">Enter the payment reference and the email or booking ID used for your completed booking.</p>
                <?php if ($error): ?>
                    <div class="error"><?php echo receiptLookupValue($error); ?></div>
                <?php endif; ?>
                <form class="lookup-form" method="get" action="receipt_lookup.php">
                    <label for="reference">Payment reference
                        <input id="reference" name="reference" type="text" value="<?php echo receiptLookupValue($reference, ''); ?>" placeholder="Example: PMT-ABC123" required>
                    </label>
                    <label for="identity">Email or booking ID
                        <input id="identity" name="identity" type="text" value="<?php echo receiptLookupValue($identity, ''); ?>" placeholder="Enter your email or booking ID" required>
                    </label>
                    <button class="lookup-submit" type="submit">Find Receipt</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($receipt): ?>
            <section class="receipt-card">
                <header class="receipt-head">
                    <h2>Payment Receipt</h2>
                    <p>Rental Truck Management System</p>
                </header>
                <div class="receipt-body">
                    <span class="paid">Payment Completed</span>
                    <div class="truck-summary">
                        <img src="<?php echo receiptLookupValue($truckImage); ?>" alt="<?php echo receiptLookupValue($receipt['truck_name']); ?>">
                        <div>
                            <h3><?php echo receiptLookupValue($receipt['truck_name']); ?></h3>
                            <div>Plate: <strong><?php echo receiptLookupValue($receipt['plate_number']); ?></strong></div>
                            <div>Capacity: <strong><?php echo receiptLookupValue($receipt['capacity']); ?></strong></div>
                            <div>Cargo: <strong><?php echo receiptLookupValue($receipt['cargo_type']); ?></strong></div>
                        </div>
                    </div>
                    <div class="route">
                        <div><small>Pickup</small><strong><?php echo receiptLookupValue($receipt['pickup']); ?></strong></div>
                        <div><small>Destination</small><strong><?php echo receiptLookupValue($receipt['destination']); ?></strong></div>
                        <div><small>Trip Date</small><strong><?php echo receiptLookupValue($receipt['trip_date']); ?></strong></div>
                        <div><small>Booking ID</small><strong><?php echo receiptLookupValue($receipt['booking_id']); ?></strong></div>
                    </div>
                    <div class="details">
                        <div><small>Payment Reference</small><strong><?php echo receiptLookupValue($receipt['payment_reference']); ?></strong></div>
                        <div><small>Customer</small><strong><?php echo receiptLookupValue($receipt['fullname']); ?></strong></div>
                        <div><small>Email</small><strong><?php echo receiptLookupValue($receipt['email']); ?></strong></div>
                        <div><small>Phone</small><strong><?php echo receiptLookupValue($receipt['phone'] ?: $receipt['phone_number']); ?></strong></div>
                        <div><small>Paid At</small><strong><?php echo receiptLookupValue($receipt['paid_at']); ?></strong></div>
                    </div>
                    <div class="channel">
                        <div><small>Payment Method</small><strong><?php echo receiptLookupValue(ucfirst($receipt['method'] ?? '')); ?></strong></div>
                        <div><small><?php echo $isMobilePayment ? 'Mobile Network' : 'Bank'; ?></small><strong><?php echo receiptLookupValue($channelName); ?></strong></div>
                        <div><small><?php echo $isMobilePayment ? 'Phone Number' : 'Account Number'; ?></small><strong><?php echo receiptLookupValue($channelNumber); ?></strong></div>
                    </div>
                    <div class="total">
                        <div><small>Full trip cost</small><strong><?php echo number_format((float)($receipt['original_cost'] ?? $receipt['amount'] ?? 0), 2); ?> TZS</strong></div>
                        <div><small>Discount</small><strong><?php echo !empty($receipt['discount_amount']) ? '-' . number_format((float)$receipt['discount_amount'], 2) . ' TZS (' . number_format((float)($receipt['discount_percent'] ?? 0), 0) . '%)' : 'No discount'; ?></strong></div>
                        <div><small>Total paid</small><strong><?php echo number_format((float)($receipt['amount'] ?? 0), 2); ?> TZS</strong></div>
                    </div>
                    <div class="actions">
                        <button class="print" type="button" onclick="window.print()">Print Receipt</button>
                        <a class="back" href="truck_register.php">Book Another Truck</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
