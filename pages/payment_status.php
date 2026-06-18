<?php
// payment_status.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

include '../db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$payment_id = (int)($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid payment_id']);
    exit;
}

// ดึงสถานะ payment พร้อมเช็คว่าเป็นของ user คนนี้เท่านั้น
$stmt = $conn->prepare("
    SELECT p.payment_id, p.status AS payment_status,
           b.status AS booking_status, b.user_id
    FROM payment p
    JOIN booking b ON p.booking_id = b.booking_id
    WHERE p.payment_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $payment_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูล payment']);
    exit;
}

// Security: ตรวจว่าเป็นของ user คนนี้
if ((int)$row['user_id'] !== (int)$_SESSION['user_id']) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

echo json_encode([
    'status'         => 'success',
    'payment_status' => $row['payment_status'],   // e.g. 'Approved', 'Pending', 'Rejected'
    'booking_status' => $row['booking_status'],   // e.g. 'Paid', 'Pending'
]);