<?php
session_start();

$receipt = $_SESSION['payment_receipt'] ?? null;
if (!$receipt) {
    header('Location: truck_register.php');
    exit;
}

unset($_SESSION['payment_receipt']);

$truckImage = !empty($receipt['truck_image'])
    ? $receipt['truck_image']
    : 'image/volvo 3.jpg';
$isMobilePayment = ($receipt['method'] ?? '') === 'mobile';
$channelName = $isMobilePayment
    ? ($receipt['provider'] ?? 'Mobile Network')
    : ($receipt['bank_name'] ?? 'Bank');
$channelNumber = $isMobilePayment
    ? ($receipt['phone_number'] ?? '—')
    : ($receipt['account_number'] ?? '—');

function receiptValue($value, string $fallback = '—'): string
{
    return htmlspecialchars((string)($value ?: $fallback), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            padding: 28px 16px;
            color: #182235;
            font-family: "Segoe UI", Arial, sans-serif;
            background:
                linear-gradient(135deg, rgba(18, 43, 58, .86), rgba(38, 83, 80, .76)),
                url('image/volvo 3.jpg') center / cover fixed;
        }
        .receipt {
            width: min(100%, 680px);
            margin: 0 auto;
            overflow: hidden;
            background: rgba(241, 245, 243, .97);
            border-radius: 18px;
            box-shadow: 0 20px 54px rgba(3, 18, 35, .34);
        }
        .receipt-head { padding: 30px 32px; color: #f1f5f3; background: #205653; }
        .receipt-head h1 { margin: 0 0 6px; font-size: 28px; }
        .receipt-head p { margin: 0; color: #d3e1df; }
        .receipt-body { padding: 28px 32px; }
        .paid {
            display: inline-block;
            margin-bottom: 22px;
            padding: 7px 13px;
            color: #245b45;
            font-weight: 800;
            background: #c9e2d5;
            border-radius: 20px;
        }
        .truck-card {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 18px;
            align-items: center;
            margin-bottom: 24px;
            padding: 16px;
            background: #e8efed;
            border: 1px solid #ccd9d7;
            border-radius: 12px;
        }
        .truck-card img {
            width: 150px;
            height: 105px;
            object-fit: cover;
            border: 2px solid #b8cdca;
            border-radius: 9px;
        }
        .truck-card h2 { margin: 0 0 8px; color: #245653; font-size: 20px; }
        .truck-meta, .channel-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 14px;
        }
        .truck-meta span { color: #5f7072; font-size: 13px; }
        .route-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 20px 0;
            padding: 16px;
            background: #dce9e6;
            border: 1px solid #bfd3cf;
            border-radius: 11px;
        }
        .route-card small, .channel-details small, .detail small {
            display: block;
            margin-bottom: 4px;
            color: #64777a;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .route-card strong { color: #245653; font-size: 15px; overflow-wrap: anywhere; }
        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            padding-top: 20px;
            border-top: 1px solid #cbd8d5;
        }
        .detail strong { color: #245653; font-size: 16px; }
        .channel-card {
            margin-top: 18px;
            padding: 16px;
            color: #665b37;
            background: #eee8d8;
            border: 1px solid #d8cfae;
            border-radius: 11px;
        }
        .channel-card h3 { margin: 0 0 10px; color: #6b5d32; font-size: 15px; }
        .channel-details small { color: #827650; }
        .channel-details strong { font-size: 14px; }
        .total {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 25px;
            padding: 17px;
            color: #245653;
            font-size: 19px;
            font-weight: 800;
            background: #dce9e6;
            border-radius: 10px;
        }
        .actions { display: flex; gap: 12px; margin-top: 25px; }
        .actions a, .actions button {
            flex: 1;
            padding: 12px;
            border: 0;
            border-radius: 8px;
            text-align: center;
            text-decoration: none;
            font-weight: 800;
            cursor: pointer;
        }
        .print { color: #f1f5f3; background: #286a68; }
        .home { color: #30494b; background: #d5dfdf; }
        @media (max-width: 560px) {
            .receipt-head, .receipt-body { padding: 24px 20px; }
            .details, .route-card, .truck-meta, .channel-details { grid-template-columns: 1fr; }
            .truck-card { grid-template-columns: 1fr; }
            .truck-card img { width: 100%; height: 180px; }
            .actions { flex-direction: column; }
        }
        @media print {
            @page { size: A4; margin: 8mm; }
            html, body { width: 100%; min-height: 0; padding: 0; background: #fff !important; }
            .receipt { display: block !important; visibility: visible !important; width: 100%; margin: 0; box-shadow: none; border-radius: 0; page-break-inside: auto; }
            .receipt * { visibility: visible !important; }
            .receipt-head { padding: 14px 18px; }
            .receipt-head h1 { font-size: 22px; }
            .receipt-body { padding: 14px 18px; }
            .paid { margin-bottom: 10px; padding: 5px 10px; }
            .truck-card { gap: 12px; margin-bottom: 12px; padding: 10px; }
            .truck-card img { width: 110px; height: 75px; }
            .truck-card h2 { margin-bottom: 4px; font-size: 16px; }
            .route-card { gap: 8px; margin: 10px 0; padding: 10px; }
            .details { gap: 8px 14px; padding-top: 10px; }
            .channel-card { margin-top: 10px; padding: 10px; }
            .total { gap: 8px; margin-top: 10px; padding: 10px; font-size: 14px; }
            .actions { display: none; }
            .truck-card, .route-card, .details, .channel-card, .total { page-break-inside: avoid; }
            .receipt-head {
                background: #1e3c72 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <main class="receipt">
        <header class="receipt-head">
            <h1>Payment Receipt</h1>
            <p>Rental Truck Management System</p>
        </header>

        <section class="receipt-body">
            <span class="paid">Payment Completed</span>

            <div class="truck-card">
                <img src="<?php echo receiptValue($truckImage); ?>" alt="<?php echo receiptValue($receipt['truck_name']); ?>">
                <div>
                    <h2><?php echo receiptValue($receipt['truck_name']); ?></h2>
                    <div class="truck-meta">
                        <span>Plate: <strong><?php echo receiptValue($receipt['truck_plate']); ?></strong></span>
                        <span>Capacity: <strong><?php echo receiptValue($receipt['truck_capacity']); ?></strong></span>
                        <span>Fuel: <strong><?php echo receiptValue($receipt['truck_fuel']); ?></strong></span>
                        <span>Cargo: <strong><?php echo receiptValue($receipt['truck_cargo']); ?></strong></span>
                    </div>
                </div>
            </div>

            <div class="route-card">
                <div><small>Pickup</small><strong><?php echo receiptValue($receipt['pickup']); ?></strong></div>
                <div><small>Destination</small><strong><?php echo receiptValue($receipt['destination']); ?></strong></div>
                <div><small>Trip Date</small><strong><?php echo receiptValue($receipt['trip_date']); ?></strong></div>
                <div><small>Booking ID</small><strong><?php echo receiptValue($receipt['booking_id']); ?></strong></div>
            </div>

            <div class="details">
                <div class="detail"><small>Payment Reference</small><strong><?php echo receiptValue($receipt['reference']); ?></strong></div>
                <div class="detail"><small>Customer</small><strong><?php echo receiptValue($receipt['fullname']); ?></strong></div>
                <div class="detail"><small>Email</small><strong><?php echo receiptValue($receipt['email']); ?></strong></div>
                <div class="detail"><small>Phone</small><strong><?php echo receiptValue($receipt['phone']); ?></strong></div>
                <div class="detail"><small>Paid At</small><strong><?php echo receiptValue($receipt['paid_at']); ?></strong></div>
            </div>

            <div class="channel-card">
                <h3>Payment Channel</h3>
                <div class="channel-details">
                    <div><small>Method</small><strong><?php echo receiptValue(ucfirst($receipt['method'] ?? '')); ?></strong></div>
                    <div><small><?php echo $isMobilePayment ? 'Mobile Network' : 'Bank'; ?></small><strong><?php echo receiptValue($channelName); ?></strong></div>
                    <div><small><?php echo $isMobilePayment ? 'Phone Number' : 'Account Number'; ?></small><strong><?php echo receiptValue($channelNumber); ?></strong></div>
                </div>
            </div>

            <div class="total">
                <div>
                    <span>Full Trip Cost</span>
                    <span><?php echo number_format((float)($receipt['full_trip_amount'] ?? $receipt['original_amount'] ?? $receipt['amount'] ?? 0), 2); ?> TZS</span>
                </div>
                <div>
                    <span>Discount</span>
                    <span><?php echo !empty($receipt['discount_percent']) ? '-' . number_format((float)($receipt['discount_amount'] ?? 0), 2) . ' TZS (' . number_format((float)$receipt['discount_percent'], 0) . '%)' : 'No discount'; ?></span>
                </div>
                <div>
                    <span>Total Paid</span>
                    <span><?php echo number_format((float)($receipt['amount'] ?? 0), 2); ?> TZS</span>
                </div>
            </div>

            <div class="actions">
                <button class="print" type="button" onclick="window.print()">Print Receipt</button>
                <a class="home" href="truck_register.php">Book Another Truck</a>
            </div>
        </section>
    </main>
</body>
</html>
