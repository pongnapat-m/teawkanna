<?php
/**
 * payment_status_return.php
 * หน้า redirect กลับจาก Omise Internet Banking (sandbox หรือจริง)
 * Omise จะ redirect มาที่นี่พร้อม ?booking_id=XXX
 */
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(0);
include '../db.php';

$booking_id = (int)($_GET['booking_id'] ?? 0);
if (!$booking_id || !isset($_SESSION['user_id'])) {
    header('Location: /tkn/home');
    exit;
}

// ดึง charge_id จาก DB แล้วเช็คสถานะจาก Omise
include '../config/omise_config.php';

$stmt = $conn->prepare(
    "SELECT p.charge_id, p.status AS pay_status, b.status AS book_status
     FROM payment p JOIN booking b ON p.booking_id = b.booking_id
     WHERE p.booking_id = ? AND b.user_id = ? LIMIT 1"
);
$stmt->bind_param('ii', $booking_id, $_SESSION['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$paid = false;
if ($row && $row['charge_id']) {
    // เช็คสถานะจาก Omise API
    $ch = curl_init(OMISE_API_BASE . '/charges/' . $row['charge_id']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Omise-Version: ' . OMISE_API_VERSION],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp   = curl_exec($ch);
    curl_close($ch);
    $charge = json_decode($resp, true);

    if (($charge['status'] ?? '') === 'successful') {
        $paid = true;
        $conn->query("UPDATE payment SET status='Approved' WHERE booking_id=$booking_id");
        $conn->query("UPDATE booking  SET status='Paid'     WHERE booking_id=$booking_id");
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $paid ? 'ชำระเงินสำเร็จ' : 'กำลังตรวจสอบ' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  body { font-family:'Kanit',sans-serif; background:#f0f4ee; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
  .box { background:#fff; border-radius:20px; padding:40px 32px; text-align:center; max-width:400px; width:90%; box-shadow:0 4px 24px rgba(0,0,0,.1); }
  .icon { font-size:64px; margin-bottom:16px; }
  h2 { font-size:22px; font-weight:700; color:#1D3718; margin:0 0 8px; }
  p  { font-size:14px; color:#666; margin:0 0 24px; }
  a  { display:inline-block; padding:12px 28px; background:#2C5A22; color:#fff; border-radius:10px; text-decoration:none; font-weight:600; font-size:14px; }
</style>
</head>
<body>
<div class="box">
  <?php if ($paid): ?>
    <div class="icon">✅</div>
    <h2>ชำระเงินสำเร็จ!</h2>
    <p>การจองหมายเลข #<?= $booking_id ?> ได้รับการยืนยันแล้วค่ะ</p>
    <a href="/tkn/home">กลับหน้าหลัก</a>
  <?php else: ?>
    <div class="icon">⏳</div>
    <h2>กำลังตรวจสอบการชำระเงิน</h2>
    <p>ระบบกำลังตรวจสอบสถานะ กรุณารอสักครู่ หรือติดต่อเราหากไม่ได้รับการยืนยัน</p>
    <a href="/tkn/home">กลับหน้าหลัก</a>
  <?php endif; ?>
</div>
</body>
</html>
