<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']); exit();
}

include '../db.php';

$owner_id = (int)$_SESSION['user_id'];

/* ── รับ JSON ── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']); exit();
}

$shop_name       = trim($data['shop_name']        ?? '');
$shop_phone      = trim($data['shop_phonenumber'] ?? '');
$location        = trim($data['location']         ?? '');
$district        = trim($data['district']         ?? '');
$province        = trim($data['province']         ?? '');
$shop_desc       = trim($data['shop_description'] ?? '');

if ($shop_name === '') {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อร้าน']); exit();
}

/* ── ตรวจสอบว่า owner มีร้านนี้ ── */
$chk = $conn->prepare("SELECT shop_id FROM shop WHERE owner_id = ? LIMIT 1");
$chk->bind_param("i", $owner_id);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลร้าน']); exit();
}
$shop_id = (int)$row['shop_id'];

/* ── safe-check shop_description column ── */
$_has_desc = $conn->query("SHOW COLUMNS FROM `shop` LIKE 'shop_description'");
$has_desc  = ($_has_desc && $_has_desc->num_rows > 0);

/* ── UPDATE ── */
if ($has_desc) {
    $stmt = $conn->prepare(
        "UPDATE shop SET
            shop_name        = ?,
            shop_phonenumber = ?,
            location         = ?,
            district         = ?,
            province         = ?,
            shop_description = ?
         WHERE shop_id = ? AND owner_id = ?"
    );
    $stmt->bind_param("ssssssii", $shop_name, $shop_phone, $location, $district, $province, $shop_desc, $shop_id, $owner_id);
} else {
    $stmt = $conn->prepare(
        "UPDATE shop SET
            shop_name        = ?,
            shop_phonenumber = ?,
            location         = ?,
            district         = ?,
            province         = ?
         WHERE shop_id = ? AND owner_id = ?"
    );
    $stmt->bind_param("sssssii", $shop_name, $shop_phone, $location, $district, $province, $shop_id, $owner_id);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'บันทึกไม่สำเร็จ: ' . $stmt->error]);
}
$stmt->close();
