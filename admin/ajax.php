<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Unauthorized']);
    exit();
}
include '../db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid request']);
    exit();
}

$action = $_POST['action'];

/* --- อนุมัติ / ปฏิเสธ Owner --- */
if ($action === 'approve_owner' || $action === 'reject_owner') {
    $oid    = (int)$_POST['owner_id'];
    $status = $action === 'approve_owner' ? 'Approved' : 'Rejected';
    $u = $conn->prepare("UPDATE owner SET status=? WHERE owner_id=?");
    $u->bind_param("si", $status, $oid);
    $u->execute();
    $u->close();

    // ── ถ้าอนุมัติ ให้ update shop ด้วย (status = 'Open') ──
    if ($action === 'approve_owner') {
        // ตรวจว่า shop มีอยู่แล้วหรือยัง
        $chk = $conn->prepare("SELECT shop_id FROM shop WHERE owner_id = ? LIMIT 1");
        $chk->bind_param("i", $oid);
        $chk->execute();
        $shop_row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($shop_row) {
            // มี shop อยู่แล้ว → แค่ update status
            $us = $conn->prepare("UPDATE shop SET status='Open' WHERE owner_id=?");
            $us->bind_param("i", $oid);
            $us->execute();
            $us->close();
        } else {
            // ไม่มี shop เลย (กรณี owner สมัครทางเก่า) → สร้าง shop ให้อัตโนมัติ
            $ow = $conn->prepare("SELECT owner_fullname, owner_phonenumber FROM owner WHERE owner_id=?");
            $ow->bind_param("i", $oid);
            $ow->execute();
            $orow = $ow->get_result()->fetch_assoc();
            $ow->close();
            if ($orow) {
                $shop_name = $orow['owner_fullname'] . "'s Shop";
                $phone     = $orow['owner_phonenumber'] ?? '';
                $ins = $conn->prepare(
                    "INSERT INTO shop (shop_name, location, district, province, shop_phonenumber,
                                      owner_id, status, latitude, longtitude, average_rating, total_reviews)
                     VALUES (?, '', '', '', ?, ?, 'Open', 0, 0, 0, 0)"
                );
                $ins->bind_param("ssi", $shop_name, $phone, $oid);
                $ins->execute();
                $ins->close();
            }
        }
    }

    // ถ้า Rejected → set shop เป็น Closed (ถ้ามี)
    if ($action === 'reject_owner') {
        $urs = $conn->prepare("UPDATE shop SET status='Closed' WHERE owner_id=?");
        $urs->bind_param("i", $oid);
        $urs->execute();
        $urs->close();
    }

    ob_clean();
    echo json_encode(['ok' => true, 'status' => $status]);
    exit();
}

/* --- อนุมัติ / ปฏิเสธ Activity ใหม่ --- */
if ($action === 'approve_activity' || $action === 'reject_activity') {
    $aid    = (int)$_POST['activity_id'];
    $status = $action === 'approve_activity' ? 'Active' : 'Rejected';
    $u = $conn->prepare("UPDATE activity SET status=? WHERE activity_id=?");
    $u->bind_param("si", $status, $aid);
    $u->execute();
    $u->close();
    ob_clean();
    echo json_encode(['ok' => true, 'status' => $status]);
    exit();
}

/* --- อนุมัติ / ปฏิเสธ Booking --- */
if ($action === 'approve_booking' || $action === 'reject_booking') {
    $bid    = (int)$_POST['booking_id'];
    $status = $action === 'approve_booking' ? 'Paid' : 'Cancel';
    $u = $conn->prepare("UPDATE booking SET status=? WHERE booking_id=?");
    $u->bind_param("si", $status, $bid);
    $u->execute();
    $u->close();

    if ($action === 'approve_booking') {
        $bk = $conn->prepare("SELECT activity_id, adult_quantity+kid_quantity AS pax FROM booking WHERE booking_id=?");
        $bk->bind_param("i", $bid);
        $bk->execute();
        $brow = $bk->get_result()->fetch_assoc();
        $bk->close();
        if ($brow) {
            $upd = $conn->prepare("UPDATE activity SET capacity_remaining=capacity_remaining-? WHERE activity_id=?");
            $upd->bind_param("ii", $brow['pax'], $brow['activity_id']);
            $upd->execute();
            $upd->close();
        }
    }
    ob_clean();
    echo json_encode(['ok' => true, 'status' => $status]);
    exit();
}

/* --- อนุมัติ Payment --- */
if ($action === 'approve_payment') {
    $pid = (int)$_POST['payment_id'];
    $bid = (int)$_POST['booking_id'];

    $u = $conn->prepare("UPDATE payment SET status='Approved' WHERE payment_id=?");
    $u->bind_param("i", $pid);
    $u->execute();
    $u->close();

    $u2 = $conn->prepare("UPDATE booking SET status='Paid' WHERE booking_id=?");
    $u2->bind_param("i", $bid);
    $u2->execute();
    $u2->close();

    $bk = $conn->prepare("SELECT activity_id, adult_quantity+kid_quantity AS pax FROM booking WHERE booking_id=?");
    $bk->bind_param("i", $bid);
    $bk->execute();
    $brow = $bk->get_result()->fetch_assoc();
    $bk->close();
    if ($brow) {
        $upd = $conn->prepare("UPDATE activity SET capacity_remaining=capacity_remaining-? WHERE activity_id=?");
        $upd->bind_param("ii", $brow['pax'], $brow['activity_id']);
        $upd->execute();
        $upd->close();
    }

    ob_clean();
    echo json_encode(['ok' => true]);
    exit();
}

echo json_encode(['ok' => false, 'msg' => 'Unknown action']);