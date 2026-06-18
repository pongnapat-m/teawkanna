<?php
/*
 * activity_handle.php
 * Handles: add_activity, edit_activity, toggle_status
 * Called via AJAX from dashboard2.php
 */
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['ok'=>false,'msg'=>'Unauthorized']); exit();
}
include '../db.php';

header('Content-Type: application/json; charset=utf-8');

$owner_id = (int)$_SESSION['user_id'];
$action   = $_POST['action'] ?? '';

/* ── helper: get shop_id for this owner ── */
function getShopId($conn, $owner_id) {
    $s = $conn->prepare("SELECT shop_id FROM shop WHERE owner_id=? LIMIT 1");
    $s->bind_param("i", $owner_id); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    return $row['shop_id'] ?? null;
}

/* ── helper: handle image upload, return path string or '' ── */
function handleActivityImageUpload($file_key, $activity_id) {
    if (empty($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
        return null; // null = ไม่มีไฟล์ใหม่
    }
    $file    = $_FILES[$file_key];
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        return false; // false = error
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return false;
    }
    $upload_dir = __DIR__ . '/uploads/activity_pics/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $ext      = $allowed[$mime];
    $filename = 'activity_' . $activity_id . '_' . time() . '.' . $ext;
    $dest     = $upload_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return false;

    return 'uploads/activity_pics/' . $filename;
}

/* ──────────────────────────────────────────
   ADD ACTIVITY
   ────────────────────────────────────────── */
if ($action === 'add_activity') {
    $shop_id = getShopId($conn, $owner_id);
    if (!$shop_id) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบร้านค้า']); exit(); }

    $name          = trim($_POST['activity_name'] ?? '');
    $desc          = trim($_POST['description']   ?? '');
    $kid_price     = (int)($_POST['kid_price']    ?? 0);
    $adult_price   = (int)($_POST['adult_price']  ?? 0);
    $max_capacity  = (int)($_POST['max_capacity'] ?? 20);
    $duration      = trim($_POST['duration_label']?? '');
    $points_reward = max(1, (int)($_POST['points_reward'] ?? 10));

    // suitable_for
    $sf_arr = $_POST['suitable_for'] ?? [];
    $suitable_for = is_array($sf_arr) ? implode(',', array_map('trim', $sf_arr)) : '';

    if (!$name) { echo json_encode(['ok'=>false,'msg'=>'กรุณาใส่ชื่อกิจกรรม']); exit(); }

    // Insert activity with status=Inactive (รอ admin อนุมัติ)
    $ins = $conn->prepare(
        "INSERT INTO activity (shop_id, activity_name, description, kid_price, adult_price,
                              max_capacity, capacity_remaining, status, duration_label, suitable_for, points_reward, activity_pic)
         VALUES (?,?,?,?,?,?,?,'Inactive',?,?,?,'')"
    );
    $ins->bind_param("issiiiiiss",
        $shop_id, $name, $desc, $kid_price, $adult_price,
        $max_capacity, $max_capacity, $duration, $suitable_for, $points_reward
    );

    if (!$ins->execute()) {
        echo json_encode(['ok'=>false,'msg'=>'DB error: '.$ins->error]); $ins->close(); exit();
    }
    $new_id = $ins->insert_id; $ins->close();

    // Handle image upload (after insert so we have activity_id)
    $pic_path = handleActivityImageUpload('activity_pic', $new_id);
    if ($pic_path === false) {
        // upload failed — ไม่ลบ activity แต่แจ้ง warning
        $pic_path = '';
    }
    if ($pic_path !== null && $pic_path !== '') {
        $upd = $conn->prepare("UPDATE activity SET activity_pic=? WHERE activity_id=?");
        $upd->bind_param("si", $pic_path, $new_id); $upd->execute(); $upd->close();
    }

    // Insert tags
    $tags = $_POST['tags'] ?? [];
    if (!empty($tags)) {
        foreach ($tags as $tid) {
            $tid = (int)$tid;
            $t = $conn->prepare("INSERT IGNORE INTO activity_tag (activity_id, tag_id) VALUES (?,?)");
            $t->bind_param("ii", $new_id, $tid); $t->execute(); $t->close();
        }
    }

    echo json_encode(['ok'=>true, 'activity_id'=>$new_id, 'msg'=>'สร้างกิจกรรมเรียบร้อย รอการอนุมัติจากแอดมิน']);
    exit();
}

/* ──────────────────────────────────────────
   EDIT ACTIVITY
   ────────────────────────────────────────── */
if ($action === 'edit_activity') {
    $aid = (int)($_POST['activity_id'] ?? 0);

    // ตรวจสอบความเป็นเจ้าของ
    $chk = $conn->prepare(
        "SELECT a.activity_id, a.activity_pic, a.max_capacity, a.capacity_remaining, a.shop_id
         FROM activity a
         JOIN shop s ON a.shop_id=s.shop_id
         WHERE a.activity_id=? AND s.owner_id=?"
    );
    $chk->bind_param("ii",$aid,$owner_id); $chk->execute();
    $existing = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$existing) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบกิจกรรม']); exit(); }

    $name          = trim($_POST['activity_name'] ?? '');
    $desc          = trim($_POST['description']   ?? '');
    $kid_price     = (int)($_POST['kid_price']    ?? 0);
    $adult_price   = (int)($_POST['adult_price']  ?? 0);
    $max_capacity  = (int)($_POST['max_capacity'] ?? 20);
    $duration      = trim($_POST['duration_label']?? '');
    $points_reward = max(1, (int)($_POST['points_reward'] ?? 10));
    $sf_arr        = $_POST['suitable_for'] ?? [];
    $suitable_for  = is_array($sf_arr) ? implode(',', array_map('trim', $sf_arr)) : '';

    if ($name === '') {
        echo json_encode(['ok'=>false,'msg'=>'กรุณาใส่ชื่อกิจกรรม']);
        exit();
    }
    if ($kid_price < 0 || $adult_price < 0 || $max_capacity < 1) {
        echo json_encode(['ok'=>false,'msg'=>'กรุณาตรวจสอบราคาและจำนวนผู้เข้าร่วม']);
        exit();
    }

    $booked_count = max(
        0,
        (int)$existing['max_capacity'] - (int)$existing['capacity_remaining']
    );
    if ($max_capacity < $booked_count) {
        echo json_encode([
            'ok'=>false,
            'msg'=>"จำนวนผู้เข้าร่วมสูงสุดต้องไม่น้อยกว่าผู้ที่จองแล้ว {$booked_count} คน"
        ]);
        exit();
    }
    $capacity_remaining = $max_capacity - $booked_count;

    // Handle image upload
    $pic_path = handleActivityImageUpload('activity_pic', $aid);
    if ($pic_path === false) {
        echo json_encode(['ok'=>false,'msg'=>'อัพโหลดรูปไม่สำเร็จ (รองรับ JPG, PNG, WEBP ขนาดไม่เกิน 5MB)']); exit();
    }

    // ถ้าไม่มีรูปใหม่ ให้ใช้รูปเดิม
    $final_pic = ($pic_path !== null && $pic_path !== '') ? $pic_path : ($existing['activity_pic'] ?? '');

    $tags = $_POST['tags'] ?? [];
    $conn->begin_transaction();
    try {
        $u = $conn->prepare(
            "UPDATE activity SET activity_name=?,description=?,kid_price=?,adult_price=?,
                                 max_capacity=?,capacity_remaining=?,duration_label=?,
                                 suitable_for=?,points_reward=?,activity_pic=?
             WHERE activity_id=? AND shop_id=?"
        );
        if (!$u) {
            throw new RuntimeException($conn->error);
        }
        $shop_id = (int)$existing['shop_id'];
        $u->bind_param("ssiiiissisii",
            $name,$desc,$kid_price,$adult_price,$max_capacity,$capacity_remaining,
            $duration,$suitable_for,$points_reward,$final_pic,$aid,$shop_id
        );
        if (!$u->execute()) {
            throw new RuntimeException($u->error);
        }
        $u->close();

        // Update tags in the same transaction as the activity.
        $del = $conn->prepare("DELETE FROM activity_tag WHERE activity_id=?");
        if (!$del) {
            throw new RuntimeException($conn->error);
        }
        $del->bind_param("i",$aid);
        if (!$del->execute()) {
            throw new RuntimeException($del->error);
        }
        $del->close();

        if (!empty($tags)) {
            $t = $conn->prepare("INSERT IGNORE INTO activity_tag (activity_id,tag_id) VALUES (?,?)");
            if (!$t) {
                throw new RuntimeException($conn->error);
            }
            foreach ($tags as $tid) {
                $tid = (int)$tid;
                if ($tid <= 0) continue;
                $t->bind_param("ii",$aid,$tid);
                if (!$t->execute()) {
                    throw new RuntimeException($t->error);
                }
            }
            $t->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ($pic_path) {
            $uploaded_file = __DIR__ . '/' . $pic_path;
            if (is_file($uploaded_file)) {
                unlink($uploaded_file);
            }
        }
        error_log('Edit activity failed: ' . $e->getMessage());
        echo json_encode(['ok'=>false,'msg'=>'บันทึกข้อมูลในฐานข้อมูลไม่สำเร็จ กรุณาลองใหม่']);
        exit();
    }

    echo json_encode([
        'ok'=>true,
        'new_pic'=>$final_pic,
        'capacity_remaining'=>$capacity_remaining,
        'msg'=>'บันทึกการแก้ไขเรียบร้อย'
    ]);
    exit();
}

/* ──────────────────────────────────────────
   TOGGLE STATUS (Active ↔ Inactive)
   ── NOTE: Inactive ที่มาจาก admin ปฏิเสธ จะไม่ toggle กลับ Active
   ────────────────────────────────────────── */
if ($action === 'toggle_status') {
    $aid = (int)($_POST['activity_id'] ?? 0);

    $chk = $conn->prepare("SELECT a.activity_id, a.status FROM activity a JOIN shop s ON a.shop_id=s.shop_id WHERE a.activity_id=? AND s.owner_id=?");
    $chk->bind_param("ii",$aid,$owner_id); $chk->execute();
    $row = $chk->get_result()->fetch_assoc(); $chk->close();

    if (!$row) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบกิจกรรม']); exit(); }
    if ($row['status'] === 'Completed') { echo json_encode(['ok'=>false,'msg'=>'กิจกรรมที่สำเร็จแล้วไม่สามารถเปลี่ยนสถานะได้']); exit(); }
    if ($row['status'] === 'Inactive') { echo json_encode(['ok'=>false,'msg'=>'กิจกรรมนี้รอการอนุมัติจากแอดมิน']); exit(); }

    $new_status = $row['status'] === 'Active' ? 'Inactive' : 'Active';
    $upd = $conn->prepare("UPDATE activity SET status=? WHERE activity_id=?");
    $upd->bind_param("si",$new_status,$aid); $upd->execute(); $upd->close();

    echo json_encode(['ok'=>true,'new_status'=>$new_status]);
    exit();
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action: '.$action]);
