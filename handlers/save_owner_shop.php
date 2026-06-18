<?php
/**
 * handlers/save_owner_shop.php
 * รับข้อมูลร้านจากหน้า owner_setup.php แล้ว INSERT ลงตาราง shop (status='Pending')
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

// ต้องมี session owner_pending_id เท่านั้น
if (empty($_SESSION['owner_pending_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณาลงทะเบียนใหม่']);
    exit();
}

include '../db.php';

$owner_id = (int)$_SESSION['owner_pending_id'];

// ── ตรวจสอบว่า owner นี้ยังไม่มี shop ──
$chk = $conn->prepare("SELECT shop_id FROM shop WHERE owner_id = ? LIMIT 1");
$chk->bind_param("i", $owner_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
$chk->close();

if ($existing) {
    // มี shop แล้ว — ไม่ต้อง insert ซ้ำ
    unset($_SESSION['owner_pending_id'], $_SESSION['owner_pending_name']);
    echo json_encode(['success' => true, 'message' => 'ร้านถูกสร้างแล้ว']);
    exit();
}

// ── รับข้อมูล ──
$shop_name   = trim($_POST['shop_name']        ?? '');
$shop_phone  = trim($_POST['shop_phonenumber'] ?? '');
$location    = trim($_POST['location']         ?? '');
$district    = trim($_POST['district']         ?? '');
$province    = trim($_POST['province']         ?? '');

if ($shop_name === '' || $shop_phone === '' || $location === '' || $district === '' || $province === '') {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit();
}
if (!preg_match('/^0[0-9]{9}$/', $shop_phone)) {
    echo json_encode(['success' => false, 'message' => 'เบอร์โทรต้องเป็นตัวเลข 10 หลัก']);
    exit();
}

// ── INSERT shop (status = 'Pending') ──
$ins = $conn->prepare(
    "INSERT INTO shop (shop_name, location, district, province, shop_phonenumber, owner_id, status,
                       shop_category_id, latitude, longtitude, average_rating, total_reviews)
     VALUES (?, ?, ?, ?, ?, ?, 'Pending', 1, 0, 0, 0, 0)"
);
$ins->bind_param("sssssi", $shop_name, $location, $district, $province, $shop_phone, $owner_id);

if (!$ins->execute()) {
    echo json_encode(['success' => false, 'message' => 'บันทึกข้อมูลร้านไม่สำเร็จ: ' . $ins->error]);
    $ins->close();
    exit();
}
$shop_id = (int)$conn->insert_id;
$ins->close();

// ── อัปโหลดรูปร้าน (ถ้ามี) ──
$pic_path = '';
if (!empty($_FILES['shop_pic']) && $_FILES['shop_pic']['error'] === UPLOAD_ERR_OK) {
    $file    = $_FILES['shop_pic'];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime    = mime_content_type($file['tmp_name']);

    if (isset($allowed[$mime]) && $file['size'] <= 5 * 1024 * 1024) {
        $upload_dir = __DIR__ . '/uploads/shop_pics/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $ext      = $allowed[$mime];
        $filename = 'shop_' . $shop_id . '_' . time() . '.' . $ext;
        $dest     = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $pic_path = 'uploads/shop_pics/' . $filename;
            // อัปเดตรูปใน DB
            $upd = $conn->prepare("UPDATE shop SET shop_picture = ? WHERE shop_id = ?");
            $upd->bind_param("si", $pic_path, $shop_id);
            $upd->execute();
            $upd->close();
        }
    }
}

// ── Clear session pending ──
unset($_SESSION['owner_pending_id'], $_SESSION['owner_pending_name']);

echo json_encode(['success' => true, 'shop_id' => $shop_id]);