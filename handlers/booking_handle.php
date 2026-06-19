<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit();
}
include '../db.php';

$owner_id = (int)$_SESSION['user_id'];
$action   = $_POST['action'] ?? '';

/* ── ตรวจ ownership ── */
function verifyBookingOwner($conn, $bid, $owner_id) {
    $s = $conn->prepare("
        SELECT b.booking_id, b.status, b.activity_id,
               (b.adult_quantity + b.kid_quantity) AS pax
        FROM booking b
        JOIN activity a ON b.activity_id = a.activity_id
        JOIN shop s ON a.shop_id = s.shop_id
        WHERE b.booking_id = ? AND s.owner_id = ?
    ");
    $s->bind_param("ii", $bid, $owner_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row;
}

/* ══════════════════════════════════════════════════
   update_status
   Flow:  Paid → Completed  (กิจกรรมจบ ลูกค้ามาแล้ว → reset capacity → Active รับจองรอบใหม่)
          Paid → Cancel     (ยกเลิกหลังจ่าย → คืน capacity)
   ══════════════════════════════════════════════════ */
if ($action === 'update_status') {
    $bid        = (int)($_POST['booking_id'] ?? 0);
    $new_status = trim($_POST['new_status']  ?? '');

    // สถานะที่ owner เปลี่ยนได้
    $allowed = ['Completed', 'Cancel'];
    if (!in_array($new_status, $allowed)) {
        echo json_encode(['ok'=>false,'msg'=>'สถานะไม่ถูกต้อง']); exit();
    }

    $row = verifyBookingOwner($conn, $bid, $owner_id);
    if (!$row) {
        echo json_encode(['ok'=>false,'msg'=>'ไม่พบการจอง หรือไม่มีสิทธิ์']); exit();
    }

    // transition ที่อนุญาต
    $transitions = [
        'Pending'   => ['Cancel'],
        'Paid'      => ['Completed', 'Cancel'],
        'Completed' => [],
        'Cancel'    => [],
    ];
    if (!in_array($new_status, $transitions[$row['status']] ?? [])) {
        echo json_encode(['ok'=>false,'msg'=>"ไม่สามารถเปลี่ยนจาก {$row['status']} → {$new_status} ได้"]); exit();
    }

    $conn->begin_transaction();
    try {
        // ── ตรวจสอบ enum ให้ครบ (ต้องตรงกับ payment_slip_upload.php และ user.php) ──
        $conn->query("
            ALTER TABLE booking
            MODIFY COLUMN status ENUM('Pending','PendingReview','Paid','Completed','Cancel','Rejected') NOT NULL DEFAULT 'Pending'
        ");

        // อัปเดต booking status
        $u = $conn->prepare("UPDATE booking SET status=? WHERE booking_id=?");
        $u->bind_param("si", $new_status, $bid);
        $u->execute(); $u->close();

        if ($new_status === 'Cancel') {
            // คืน capacity_remaining
            $upd = $conn->prepare("
                UPDATE activity
                SET capacity_remaining = capacity_remaining + ?
                WHERE activity_id = ?
            ");
            $upd->bind_param("ii", $row['pax'], $row['activity_id']);
            $upd->execute(); $upd->close();
        }

        if ($new_status === 'Completed') {
            // Reset capacity_remaining กลับเป็น max_capacity
            // และคง status = 'Active' เพื่อรับจองรอบใหม่ได้เรื่อยๆ
            $upd = $conn->prepare("
                UPDATE activity
                SET capacity_remaining = max_capacity,
                    status = 'Active'
                WHERE activity_id = ?
            ");
            $upd->bind_param("i", $row['activity_id']);
            $upd->execute(); $upd->close();
        }

        $conn->commit();
        echo json_encode(['ok'=>true, 'new_status'=>$new_status]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit();
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action: '.$action]);