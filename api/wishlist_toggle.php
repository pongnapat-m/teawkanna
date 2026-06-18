<?php
// api/wishlist_toggle.php
// POST: { activity_id } → toggle wishlist (add/remove)
// Returns JSON: { status: 'added'|'removed', wishlisted: bool }

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid method']);
    exit;
}

include '../db.php';

$uid = (int)$_SESSION['user_id'];
$aid = (int)($_POST['activity_id'] ?? 0);

if ($aid <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid activity_id']);
    exit;
}

// ตรวจว่ามีอยู่แล้วไหม
$chk = $conn->prepare("SELECT wishlist_id FROM wishlist WHERE user_id = ? AND activity_id = ?");
$chk->bind_param("ii", $uid, $aid);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();

if ($existing) {
    // มีแล้ว → ลบออก
    $del = $conn->prepare("DELETE FROM wishlist WHERE user_id = ? AND activity_id = ?");
    $del->bind_param("ii", $uid, $aid);
    $del->execute();
    $del->close();
    echo json_encode(['status' => 'removed', 'wishlisted' => false]);
} else {
    // ยังไม่มี → เพิ่ม
    $ins = $conn->prepare("INSERT INTO wishlist (user_id, activity_id) VALUES (?, ?)");
    $ins->bind_param("ii", $uid, $aid);
    $ins->execute();
    $ins->close();
    echo json_encode(['status' => 'added', 'wishlisted' => true]);
}
