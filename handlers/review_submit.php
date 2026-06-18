<?php
/**
 * review_submit.php — รับ POST: booking_id, rating, comment, is_public
 * สร้างรีวิวใหม่ โดยหนึ่งผู้ใช้รีวิวกิจกรรมเดิมได้หนึ่งครั้ง
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit;
}

include '../db.php';

$uid        = (int)$_SESSION['user_id'];
$booking_id = (int)($_POST['booking_id'] ?? 0);
$rating     = (int)($_POST['rating']     ?? 0);
$comment    = trim($_POST['comment']     ?? '');
$is_public  = isset($_POST['is_public']) && $_POST['is_public'] === '1' ? 1 : 0;

if (!$booking_id || $rating < 1 || $rating > 5) {
    echo json_encode(['ok' => false, 'msg' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}

// ตรวจว่า booking นี้เป็นของ user และมีสถานะ Completed
$chk = $conn->prepare(
    "SELECT b.booking_id, b.activity_id FROM booking b
     WHERE b.booking_id = ? AND b.user_id = ? AND b.status = 'Completed'
     LIMIT 1"
);
$chk->bind_param("ii", $booking_id, $uid);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) {
    echo json_encode(['ok' => false, 'msg' => 'ไม่พบการจองที่มีสิทธิ์รีวิว']);
    exit;
}

$activity_id = (int)$row['activity_id'];

// ตรวจสอบว่าเคยรีวิว booking นี้หรือยัง (ถ้า review table มีคอลัมน์ booking_id)
// ป้องกันรีวิวซ้ำด้วย user_id + activity_id
$dup = $conn->prepare("SELECT review_id FROM review WHERE user_id = ? AND activity_id = ? LIMIT 1");
$dup->bind_param("ii", $uid, $activity_id);
$dup->execute();
$existing = $dup->get_result()->fetch_assoc();
$dup->close();

// เพิ่ม is_public ถ้ายังไม่มีคอลัมน์นี้ (safe migration)
$colCheck = $conn->query("SHOW COLUMNS FROM `review` LIKE 'is_public'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE `review` ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 1");
}

if ($existing) {
    echo json_encode(['ok' => false, 'msg' => 'คุณรีวิวกิจกรรมนี้ไปแล้ว']);
    exit;
}

// สร้างรีวิวใหม่
$ins = $conn->prepare(
    "INSERT INTO review (rating, comment, user_id, activity_id, is_public, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())"
);
$ins->bind_param("isiii", $rating, $comment, $uid, $activity_id, $is_public);
$ins->execute();
$ins->close();

// อัปเดต average_rating และ total_reviews ในตาราง shop
$conn->query(
    "UPDATE shop s
     JOIN activity a ON a.shop_id = s.shop_id
     SET s.average_rating = (
         SELECT ROUND(AVG(r.rating), 1) FROM review r WHERE r.activity_id IN (
             SELECT activity_id FROM activity WHERE shop_id = s.shop_id
         ) AND r.is_public = 1
     ),
     s.total_reviews = (
         SELECT COUNT(*) FROM review r WHERE r.activity_id IN (
             SELECT activity_id FROM activity WHERE shop_id = s.shop_id
         ) AND r.is_public = 1
     )
     WHERE a.activity_id = {$activity_id}"
);

echo json_encode(['ok' => true, 'msg' => 'ส่งรีวิวเรียบร้อยแล้ว']);
