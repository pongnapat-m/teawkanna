<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']); exit();
}

include '../db.php';

$owner_id = (int)$_SESSION['user_id'];

if (empty($_FILES['shop_pic']) || $_FILES['shop_pic']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['shop_pic']['error'] ?? -1;
    echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์ (error code: ' . $errCode . ')']); exit();
}

$file     = $_FILES['shop_pic'];
$allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$mime     = mime_content_type($file['tmp_name']);

if (!isset($allowed[$mime])) {
    echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะ JPG, PNG, WEBP']); exit();
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'ไฟล์ใหญ่เกิน 5MB']); exit();
}

/* ── ตรวจ shop ── */
$chk = $conn->prepare("SELECT shop_id FROM shop WHERE owner_id = ? LIMIT 1");
$chk->bind_param("i", $owner_id);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลร้าน']); exit();
}
$shop_id = (int)$row['shop_id'];

/* ── บันทึกไฟล์ ── */
$upload_dir = __DIR__ . '/uploads/shop_pics/';
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        echo json_encode(['success' => false, 'message' => 'สร้าง folder ไม่สำเร็จ กรุณาตรวจสอบ permission']); exit();
    }
}

/* ลบรูปเก่าออกก่อน (ถ้ามี) */
$old_q = $conn->prepare("SELECT shop_picture FROM shop WHERE shop_id = ? LIMIT 1");
$old_q->bind_param("i", $shop_id);
$old_q->execute();
$old_row = $old_q->get_result()->fetch_assoc();
$old_q->close();
if (!empty($old_row['shop_picture'])) {
    $old_file = __DIR__ . '/' . ltrim($old_row['shop_picture'], '/');
    if (file_exists($old_file)) @unlink($old_file);
}

$ext      = $allowed[$mime];
$filename = 'shop_' . $shop_id . '_' . time() . '.' . $ext;
$dest     = $upload_dir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    $err = error_get_last();
    echo json_encode(['success' => false, 'message' => 'อัปโหลดไม่สำเร็จ: ' . ($err['message'] ?? 'permission denied')]); exit();
}

$pic_path = 'uploads/shop_pics/' . $filename;

/* ── UPDATE DB ── */
$upd = $conn->prepare("UPDATE shop SET shop_picture = ? WHERE shop_id = ? AND owner_id = ?");
$upd->bind_param("sii", $pic_path, $shop_id, $owner_id);
$upd->execute();
$upd->close();

$pic_url = '/tkn/handlers/' . $pic_path;
echo json_encode([
    'success' => true,
    'path' => $pic_path,
    'url' => $pic_url . '?v=' . time(),
]);
