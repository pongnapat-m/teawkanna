<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$owner_id   = $_SESSION['user_id'];
$owner_name = $_SESSION['fullname'] ?? 'ผู้ประกอบการ';

/* ── ดึง shop ── */
$sh = $conn->prepare("SELECT shop_id, shop_name FROM shop WHERE owner_id = ? LIMIT 1");
$sh->bind_param("i", $owner_id); $sh->execute();
$shop = $sh->get_result()->fetch_assoc(); $sh->close();
$shop_id   = $shop['shop_id']   ?? null;
$shop_name = $shop['shop_name'] ?? 'ร้านของคุณ';

function completeOwnerActivity(mysqli $conn, int $activityId, int $ownerId): array {
    $chk = $conn->prepare(
        "SELECT a.activity_id, a.points_reward, a.status, a.end_date
         FROM activity a
         JOIN shop s ON a.shop_id=s.shop_id
         JOIN activity_open_request r ON r.new_activity_id=a.activity_id
         WHERE a.activity_id=? AND s.owner_id=?
           AND r.owner_id=? AND r.status='Approved'
         LIMIT 1"
    );
    $chk->bind_param('iii', $activityId, $ownerId, $ownerId);
    $chk->execute();
    $activity = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$activity) return ['ok'=>false, 'msg'=>'ไม่พบกิจกรรม'];
    if ($activity['status'] === 'Completed') {
        return ['ok'=>true, 'awarded'=>0, 'points'=>(int)$activity['points_reward']];
    }
    if (strtotime($activity['end_date']) > time()) {
        return ['ok'=>false, 'msg'=>'กิจกรรมยังไม่สิ้นสุด จึงยังไม่สามารถกดเสร็จสิ้นได้'];
    }

    $points = (int)($activity['points_reward'] ?? 10);
    $awarded = 0;
    $conn->begin_transaction();
    try {
        $bookings = $conn->prepare(
            "SELECT b.booking_id, b.user_id
             FROM booking b
             WHERE b.activity_id=? AND b.status='Paid'"
        );
        $bookings->bind_param('i', $activityId);
        $bookings->execute();
        $paidBookings = $bookings->get_result()->fetch_all(MYSQLI_ASSOC);
        $bookings->close();

        foreach ($paidBookings as $booking) {
            $bookingUserId = (int)$booking['user_id'];
            $bookingId = (int)$booking['booking_id'];
            $passport = $conn->prepare(
                "INSERT IGNORE INTO activity_passport
                    (user_id, activity_id, booking_id, points_earned)
                 VALUES (?,?,?,?)"
            );
            $passport->bind_param(
                'iiii',
                $bookingUserId,
                $activityId,
                $bookingId,
                $points
            );
            $passport->execute();
            $inserted = $passport->affected_rows === 1;
            $passport->close();

            if ($inserted) {
                $user = $conn->prepare("UPDATE user SET point=point+? WHERE user_id=?");
                $user->bind_param('ii', $points, $bookingUserId);
                $user->execute();
                $user->close();
                $awarded++;
            }
        }

        $bookingsDone = $conn->prepare(
            "UPDATE booking SET status='Completed'
             WHERE activity_id=? AND status='Paid'"
        );
        $bookingsDone->bind_param('i', $activityId);
        $bookingsDone->execute();
        $bookingsDone->close();

        $activityDone = $conn->prepare(
            "UPDATE activity
             SET status='Completed', completed_at=NOW()
             WHERE activity_id=? AND status<>'Completed'"
        );
        $activityDone->bind_param('i', $activityId);
        $activityDone->execute();
        $activityDone->close();

        $conn->commit();
        return ['ok'=>true, 'awarded'=>$awarded, 'points'=>$points];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok'=>false, 'msg'=>'ไม่สามารถปิดกิจกรรมได้: '.$e->getMessage()];
    }
}

/* ปิดรอบอัตโนมัติเมื่อเลยเวลาจบครบ 24 ชั่วโมง */
if ($shop_id) {
    $auto = $conn->prepare(
        "SELECT DISTINCT a.activity_id
         FROM activity_open_request r
         JOIN activity a ON r.new_activity_id=a.activity_id
         WHERE r.owner_id=? AND r.status='Approved'
           AND a.status<>'Completed'
           AND a.end_date < DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $auto->bind_param('i', $owner_id);
    $auto->execute();
    $autoIds = array_column($auto->get_result()->fetch_all(MYSQLI_ASSOC), 'activity_id');
    $auto->close();
    foreach ($autoIds as $autoId) {
        completeOwnerActivity($conn, (int)$autoId, (int)$owner_id);
    }
}

/* ── tags ── */
$tag_rows = $conn->query("SELECT tag_id, tag_name FROM tag ORDER BY tag_id")->fetch_all(MYSQLI_ASSOC);

/* ── Notifications ── */
$total_notifs = 0; $notif_bookings = []; $notif_complaints = [];
if ($shop_id) {
    $nb = $conn->prepare("SELECT b.booking_id,u.fullname,a.activity_name,b.booking_date
        FROM booking b JOIN activity a ON b.activity_id=a.activity_id
        JOIN user u ON b.user_id=u.user_id
        WHERE a.shop_id=? AND b.status='Paid' ORDER BY b.booking_date DESC LIMIT 5");    $nb->bind_param("i",$shop_id); $nb->execute();
    $notif_bookings = $nb->get_result()->fetch_all(MYSQLI_ASSOC); $nb->close();

    $nc = $conn->prepare("SELECT c.complaint_id,c.topic,u.fullname FROM complaint c JOIN user u ON c.user_id=u.user_id WHERE c.status='Pending' LIMIT 5");
    $nc->execute(); $notif_complaints = $nc->get_result()->fetch_all(MYSQLI_ASSOC); $nc->close();
    $total_notifs = count($notif_bookings) + count($notif_complaints);

    /* ── Activities ── */
    $sort = $_GET['sort'] ?? 'default';
    $order = match($sort) {
        'name'    => 'a.activity_name ASC',
        'price'   => 'a.adult_price ASC',
        'status'  => 'a.status ASC',
        default   => 'a.activity_id ASC'
    };
    $per_page  = 10;
    $act_page  = max(1, (int)($_GET['page'] ?? 1));

    // COUNT
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM activity a WHERE a.shop_id=?");
    $cnt_stmt->bind_param("i", $shop_id); $cnt_stmt->execute();
    $total_acts  = (int)$cnt_stmt->get_result()->fetch_row()[0]; $cnt_stmt->close();
    $total_pages = max(1, (int)ceil($total_acts / $per_page));
    $act_page    = min($act_page, $total_pages);
    $offset      = ($act_page - 1) * $per_page;

    $aq = $conn->prepare(
        "SELECT a.*, GROUP_CONCAT(DISTINCT at2.tag_id) as tag_ids,
                (a.max_capacity - a.capacity_remaining) AS booked_pax,
                COALESCE((
                    SELECT COUNT(DISTINCT b.booking_id)
                    FROM booking b
                    WHERE b.activity_id = a.activity_id AND b.status='Paid'
                ),0) AS booking_count
         FROM activity a
         LEFT JOIN activity_tag at2 ON a.activity_id=at2.activity_id
         WHERE a.shop_id=?
         GROUP BY a.activity_id
         ORDER BY $order
         LIMIT $per_page OFFSET $offset"
    );
    $aq->bind_param("i",$shop_id); $aq->execute();
    $activities = $aq->get_result()->fetch_all(MYSQLI_ASSOC); $aq->close();
} else { $activities = []; $total_acts = 0; $total_pages = 1; $act_page = 1; $per_page = 10; $offset = 0; $sort = 'default'; }

/* ── ดึง activity_open_request ของ owner ── */
$open_requests = [];
if ($shop_id) {
    $rq = $conn->prepare("
        SELECT r.request_id, r.activity_id, r.requested_start_date, r.requested_end_date,
               r.note, r.admin_note, r.status AS req_status, r.requested_at, r.new_activity_id,
               a.activity_name, a.duration_label, a.adult_price, a.kid_price,
               na.status AS round_status, na.completed_at, na.start_date AS round_start_date,
               na.end_date AS round_end_date, na.max_capacity AS round_max_capacity,
               na.capacity_remaining AS round_capacity_remaining,
               na.points_reward AS round_points_reward
        FROM activity_open_request r
        JOIN activity a ON r.activity_id = a.activity_id
        LEFT JOIN activity na ON r.new_activity_id = na.activity_id
        WHERE r.owner_id = ?
          AND (r.status <> 'Approved' OR na.activity_id IS NOT NULL)
        ORDER BY r.requested_at DESC
    ");
    $rq->bind_param("i", $owner_id); $rq->execute();
    $open_requests = $rq->get_result()->fetch_all(MYSQLI_ASSOC); $rq->close();
}

// รวบรวม activity_id ที่ถูกสร้างจาก approved request (รอบใหม่) ไม่ให้ขึ้นใน dropdown / Tab1
$derived_activity_ids = array_filter(array_column($open_requests, 'new_activity_id'));
$derived_activity_ids = array_flip($derived_activity_ids); // ใช้ isset() แทน in_array() เพื่อความเร็ว

/* ── request status map: template_activity_id → ['Pending'=>n, 'Approved'=>n, ...] ── */
$act_request_status = [];
if ($shop_id) {
    $rsq = $conn->prepare("
        SELECT activity_id, status, COUNT(*) AS cnt
        FROM activity_open_request WHERE owner_id = ?
        GROUP BY activity_id, status
    ");
    $rsq->bind_param("i", $owner_id); $rsq->execute();
    foreach ($rsq->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $aid = (int)$row['activity_id'];
        $act_request_status[$aid][$row['status']] = (int)$row['cnt'];
    }
    $rsq->close();
}

/* ── Approved ที่ยังไม่หมดอายุ (รวม time_end จาก note ของกิจกรรม) ── */
$act_active_approved = []; // activity_id → true
if ($shop_id) {
    $aq = $conn->prepare("
        SELECT DISTINCT activity_id, requested_end_date, note
        FROM activity_open_request
        WHERE owner_id = ? AND status = 'Approved'
          AND DATE(requested_end_date) >= CURDATE()
    ");
    $aq->bind_param("i", $owner_id); $aq->execute();
    $now_check = new DateTime('now');
    foreach ($aq->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $end_dt = new DateTime($row['requested_end_date']);
        // พยายาม parse time_end จาก note (recurring) เช่น "จัดซ้ำ: เสาร์ 09:00-17:00"
        $time_end_str = '';
        if (!empty($row['note']) && preg_match('/([0-9]{1,2}:[0-9]{2})\s*(?:น\.?)?$/', trim($row['note']), $tm)) {
            $time_end_str = $tm[1];
        } elseif (!empty($row['note']) && preg_match('/([0-9]{1,2}:[0-9]{2})\s*-\s*([0-9]{1,2}:[0-9]{2})/', $row['note'], $tm)) {
            $time_end_str = $tm[2]; // เอาส่วนท้าย เช่น "17:00"
        }
        if ($time_end_str) {
            // ปรับ end_dt เป็นวันสุดท้าย + เวลาสิ้นสุดกิจกรรม
            list($h, $m) = explode(':', $time_end_str);
            $end_dt->setTime((int)$h, (int)$m, 0);
        } else {
            // ไม่มี time_end ใน note → ถือว่าหมดอายุสิ้นวัน (23:59:59)
            $end_dt->setTime(23, 59, 59);
        }
        if ($now_check <= $end_dt) {
            $act_active_approved[(int)$row['activity_id']] = true;
        }
    }
    $aq->close();
}

/* ── แยกรอบที่เปิดอยู่และประวัติการจัดกิจกรรม ── */
$approved_requests = array_values(array_filter(
    $open_requests,
    fn($r) => $r['req_status'] === 'Approved' && ($r['round_status'] ?? '') !== 'Completed'
));
$completed_activity_requests = array_values(array_filter(
    $open_requests,
    fn($r) => $r['req_status'] === 'Approved' && ($r['round_status'] ?? '') === 'Completed'
));
$all_approved_requests = array_merge($approved_requests, $completed_activity_requests);

/* ── Map กิจกรรมต้นแบบ → รอบที่อนุมัติและยังไม่ปิด ── */
$active_round_by_template = [];
foreach ($approved_requests as $req) {
    $templateId = (int)($req['activity_id'] ?? 0);
    $roundId = (int)($req['new_activity_id'] ?? 0);
    if (!$templateId || !$roundId || isset($active_round_by_template[$templateId])) continue;

    $roundEndValue = $req['round_end_date'] ?: $req['requested_end_date'];
    $roundEnd = new DateTime($roundEndValue);
    $roundStats = $conn->prepare(
        "SELECT COUNT(DISTINCT booking_id) AS booking_count,
                COALESCE(SUM(adult_quantity + kid_quantity), 0) AS booked_pax
         FROM booking
         WHERE activity_id=? AND status='Paid'"
    );
    $roundStats->bind_param('i', $roundId);
    $roundStats->execute();
    $stats = $roundStats->get_result()->fetch_assoc();
    $roundStats->close();

    $active_round_by_template[$templateId] = [
        'activity_id' => $roundId,
        'end_date' => $roundEndValue,
        'can_complete' => new DateTime('now') >= $roundEnd,
        'max_capacity' => (int)($req['round_max_capacity'] ?? 0),
        'capacity_remaining' => (int)($req['round_capacity_remaining'] ?? 0),
        'booking_count' => (int)($stats['booking_count'] ?? 0),
        'booked_pax' => (int)($stats['booked_pax'] ?? 0),
        'points_reward' => (int)($req['round_points_reward'] ?? 10),
    ];
}

/* ── Preload booking-by-date for each approved activity ── */
$approved_bookings_by_date = [];   // [new_activity_id => [ ['bdate'=>..., 'pax'=>..., 'booking_cnt'=>..., 'names'=>...], ... ]]
foreach ($all_approved_requests as $req) {
    $nid = (int)($req['new_activity_id'] ?? 0);
    if (!$nid) continue;
    $bdq = $conn->prepare(
        "SELECT DATE(b.booking_date) AS bdate,
                SUM(b.adult_quantity + b.kid_quantity) AS pax,
                COUNT(*) AS booking_cnt,
                GROUP_CONCAT(u.fullname ORDER BY b.booking_id SEPARATOR ', ') AS names
         FROM booking b JOIN user u ON b.user_id = u.user_id
         WHERE b.activity_id = ? AND b.status = 'Paid'
         GROUP BY DATE(b.booking_date)
         ORDER BY bdate ASC"
    );
    $bdq->bind_param("i", $nid); $bdq->execute();
    $approved_bookings_by_date[$nid] = $bdq->get_result()->fetch_all(MYSQLI_ASSOC);
    $bdq->close();
}

/* ── Preload completed-by-date for each approved activity ── */
$completed_by_date = [];   // [new_activity_id => [bdate => booking_cnt]]
foreach ($all_approved_requests as $req) {
    $nid = (int)($req['new_activity_id'] ?? 0);
    if (!$nid) continue;
    $cdq = $conn->prepare(
        "SELECT DATE(b.booking_date) AS bdate, COUNT(*) AS cnt
         FROM booking b
         WHERE b.activity_id = ? AND b.status = 'Completed'
         GROUP BY DATE(b.booking_date)"
    );
    $cdq->bind_param("i", $nid); $cdq->execute();
    $rows = $cdq->get_result()->fetch_all(MYSQLI_ASSOC); $cdq->close();
    foreach ($rows as $r) $completed_by_date[$nid][$r['bdate']] = (int)$r['cnt'];
}

/* ── Helper: generate weekly occurrence dates between start & end ── */
function getOccurrenceDates(string $start_date, string $end_date): array {
    $start   = new DateTime(explode(' ', $start_date)[0]);
    $end     = new DateTime(explode(' ', $end_date)[0]);
    $diffDays = (int)$start->diff($end)->days;
    if ($diffDays < 7) {
        // single event — only one date
        return [$start->format('Y-m-d')];
    }
    // Recurring weekly: repeat on same day-of-week as $start
    $dates   = [];
    $current = clone $start;
    while ($current <= $end) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+7 days');
    }
    return $dates;
}

$thaiDays  = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัส','ศุกร์','เสาร์'];
$thaiMonths= ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

/* ══ AJAX: complete_activity / request_activity ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    /* ── ส่งคำขอเปิดรอบกิจกรรม ── */
    if ($action === 'request_activity') {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $activity_id = (int)($_POST['activity_id'] ?? 0);
        $start_date  = $_POST['requested_start_date'] ?? '';
        $end_date    = $_POST['requested_end_date']   ?? '';
        $note        = trim($_POST['note'] ?? '');

        if (!$activity_id || !$start_date || !$end_date) {
            echo json_encode(['ok'=>false,'msg'=>'กรุณากรอกข้อมูลให้ครบ']); exit();
        }
        if ($end_date <= $start_date) {
            echo json_encode(['ok'=>false,'msg'=>'วันสิ้นสุดต้องหลังวันเริ่มต้น']); exit();
        }
        $today_str = date('Y-m-d');
        if (substr($start_date, 0, 10) < $today_str) {
            echo json_encode(['ok'=>false,'msg'=>'วันเริ่มต้นต้องเป็นวันนี้หรือวันข้างหน้าเท่านั้น']); exit();
        }

        if ($request_id) {
            $existing = $conn->prepare("
                SELECT activity_id
                FROM activity_open_request
                WHERE request_id=? AND owner_id=? AND shop_id=? AND status='Rejected'
            ");
            $existing->bind_param("iii", $request_id, $owner_id, $shop_id);
            $existing->execute();
            $existing_request = $existing->get_result()->fetch_assoc();
            $existing->close();
            if (!$existing_request) {
                echo json_encode(['ok'=>false,'msg'=>'ไม่พบคำขอที่สามารถแก้ไขและส่งใหม่ได้']); exit();
            }
            if ((int)$existing_request['activity_id'] !== $activity_id) {
                echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถเปลี่ยนกิจกรรมของคำขอเดิมได้']); exit();
            }
        }

        $chk = $conn->prepare("SELECT activity_id, duration_label FROM activity WHERE activity_id=? AND shop_id=?");
        $chk->bind_param("ii",$activity_id,$shop_id); $chk->execute();
        $activity_row = $chk->get_result()->fetch_assoc();
        if (!$activity_row) {
            echo json_encode(['ok'=>false,'msg'=>'ไม่พบกิจกรรม']); exit();
        }

        $durationLabel = trim($activity_row['duration_label'] ?? '');
        if ($durationLabel !== '') {
            try {
                $startDt = new DateTime($start_date);
                $endDt   = new DateTime($end_date);
                $dailyStart = (int)$startDt->format('H') * 60 + (int)$startDt->format('i');
                $dailyEnd   = (int)$endDt->format('H') * 60 + (int)$endDt->format('i');
                $dailyMinutes = $dailyEnd - $dailyStart;
                $requiredMinutes = [
                    '1 Hour' => 60,
                    '2 Hours' => 120,
                    'Half Day' => 240,
                    'Full Day' => 480
                ];
                if (!isset($requiredMinutes[$durationLabel])) {
                    echo json_encode([
                        'ok'=>false,
                        'msg'=>'ไม่พบข้อมูลระยะเวลาที่รองรับสำหรับกิจกรรมนี้'
                    ]);
                    exit();
                }
                if ($dailyMinutes !== $requiredMinutes[$durationLabel]) {
                    $hours = $requiredMinutes[$durationLabel] / 60;
                    echo json_encode([
                        'ok'=>false,
                        'msg'=>'กิจกรรม "'.$durationLabel.'" ต้องมีระยะเวลา '.$hours.' ชั่วโมงต่อรอบ'
                    ]);
                    exit();
                }
            } catch (Exception $e) {
                echo json_encode(['ok'=>false,'msg'=>'ข้อมูลวันเวลาไม่ถูกต้อง']); exit();
            }
        }

        if ($request_id) {
            $upd = $conn->prepare("
                UPDATE activity_open_request
                SET requested_start_date=?, requested_end_date=?, note=?,
                    status='Pending', admin_note=NULL, reviewed_at=NULL,
                    new_activity_id=NULL, requested_at=NOW()
                WHERE request_id=? AND owner_id=? AND shop_id=? AND status='Rejected'
            ");
            $upd->bind_param("sssiii", $start_date, $end_date, $note, $request_id, $owner_id, $shop_id);
            $upd->execute();
            if ($upd->affected_rows === 1) {
                echo json_encode(['ok'=>true,'msg'=>'แก้ไขและส่งคำขอกลับไปพิจารณาใหม่แล้ว']);
            } else {
                echo json_encode(['ok'=>false,'msg'=>'ไม่สามารถส่งคำขอนี้กลับไปพิจารณาใหม่ได้']);
            }
            $upd->close();
        } else {
            $ins = $conn->prepare("
                INSERT INTO activity_open_request
                    (activity_id, shop_id, owner_id, requested_start_date, requested_end_date, note, status, requested_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ");
            $ins->bind_param("iiisss",$activity_id,$shop_id,$owner_id,$start_date,$end_date,$note);
            if ($ins->execute()) {
                echo json_encode(['ok'=>true,'msg'=>'ส่งคำขอเรียบร้อยแล้ว! รอแอดมินพิจารณา']);
            } else {
                echo json_encode(['ok'=>false,'msg'=>'เกิดข้อผิดพลาดในการบันทึก: '.$conn->error]);
            }
            $ins->close();
        }
        exit();
    }

    if ($action === 'complete_activity') {
        $aid = (int)($_POST['activity_id'] ?? 0);
        echo json_encode(completeOwnerActivity($conn, $aid, (int)$owner_id));
        exit();
    }

    if ($action === 'complete_date') {
        $aid   = (int)($_POST['activity_id'] ?? 0);
        $bdate = trim($_POST['booking_date'] ?? '');

        if (!$aid || !$bdate) { echo json_encode(['ok'=>false,'msg'=>'ข้อมูลไม่ครบ']); exit(); }

        $chk = $conn->prepare("SELECT a.activity_id, a.points_reward FROM activity a JOIN shop s ON a.shop_id=s.shop_id WHERE a.activity_id=? AND s.owner_id=?");
        $chk->bind_param("ii", $aid, $owner_id); $chk->execute();
        $actRow = $chk->get_result()->fetch_assoc(); $chk->close();
        if (!$actRow) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบกิจกรรม']); exit(); }
        if ($bdate > date('Y-m-d')) {
            echo json_encode(['ok'=>false,'msg'=>'กิจกรรมรอบนี้ยังไม่ถึงวันที่จัด']); exit();
        }

        $pts = $actRow['points_reward'] ?? 10;

        // ดึง Paid bookings วันนั้น
        $bq = $conn->prepare("SELECT b.booking_id, b.user_id FROM booking b WHERE b.activity_id=? AND DATE(b.booking_date)=? AND b.status='Paid'");
        $bq->bind_param("is", $aid, $bdate); $bq->execute();
        $paid = $bq->get_result()->fetch_all(MYSQLI_ASSOC); $bq->close();

        $awarded = 0;
        foreach ($paid as $bk) {
            $bookingUserId = (int)$bk['user_id'];
            $bookingId = (int)$bk['booking_id'];
            $ins = $conn->prepare("INSERT IGNORE INTO activity_passport (user_id, activity_id, booking_id, points_earned) VALUES (?,?,?,?)");
            $ins->bind_param("iiii", $bookingUserId, $aid, $bookingId, $pts);
            $ins->execute();
            $inserted = $ins->affected_rows === 1;
            $ins->close();

            if ($inserted) {
                $upd = $conn->prepare("UPDATE user SET point = point + ? WHERE user_id=?");
                $upd->bind_param("ii", $pts, $bookingUserId);
                $upd->execute();
                $upd->close();
                $awarded++;
            }
        }

        // อัปเดต Paid → Completed เฉพาะวันนั้น
        $ubc = $conn->prepare("UPDATE booking SET status='Completed' WHERE activity_id=? AND DATE(booking_date)=? AND status='Paid'");
        $ubc->bind_param("is", $aid, $bdate); $ubc->execute(); $ubc->close();

        echo json_encode(['ok'=>true, 'awarded'=>$awarded, 'points'=>$pts]);
        exit();
    }

    echo json_encode(['ok'=>false,'msg'=>'unknown action']); exit();
}

// ===== ตรวจสอบภาษา =====
$lang = $_SESSION['lang'] ?? 'th';
$isEnglish = ($lang === 'en');

// ===== ข้อความ =====
if ($isEnglish) {
    // English
    $t = [
        'html_lang'         => 'en',
    ];
} else {
    // Thai
    $t = [
        'html_lang'         => 'th',
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= $t['html_lang'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Management — <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="/tkn/assets/css/ownerstyle2.css">
    <link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    /* ══ Modal Base ══ */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, .5);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 20px
    }

    .modal-overlay.open {
        display: flex
    }

    .modal {
        background: var(--white);
        border: 1px solid var(--border-light);
        border-radius: 20px;
        width: 100%;
        max-width: 620px;
        max-height: 90vh;
        overflow-y: auto;
        padding: 32px 28px;
        position: relative;
        box-shadow: 0 20px 60px rgba(0, 0, 0, .15)
    }

    .modal h2 {
        font-family: 'Kanit', sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 22px;
        display: flex;
        align-items: center;
        gap: 10px
    }

    .modal-close {
        position: absolute;
        top: 16px;
        right: 16px;
        background: none;
        border: none;
        color: var(--text-light);
        cursor: pointer;
        padding: 6px;
        border-radius: 8px;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center
    }

    .modal-close:hover {
        background: var(--bg-cream);
        color: var(--primary-dark)
    }

    .mform-group {
        margin-bottom: 16px
    }

    .mform-label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .8px;
        text-transform: uppercase;
        color: var(--text-light);
        margin-bottom: 7px;
        font-family: 'Kanit', sans-serif
    }

    .mform-input,
    .mform-select,
    .mform-textarea {
        width: 100%;
        padding: 10px 13px;
        background: var(--bg-cream);
        border: 1.5px solid var(--border-light);
        border-radius: 10px;
        color: var(--text-dark);
        font-family: 'Kanit', sans-serif;
        font-size: 14px;
        outline: none;
        transition: var(--transition)
    }

    .mform-textarea {
        min-height: 90px;
        resize: vertical
    }

    .mform-input:focus,
    .mform-select:focus,
    .mform-textarea:focus {
        border-color: var(--primary-dark);
        background: var(--white);
        box-shadow: 0 0 0 3px rgba(44, 74, 47, .08)
    }

    .mform-grid2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 13px
    }

    .check-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 4px
    }

    .check-item input {
        display: none
    }

    .check-item span {
        display: inline-flex;
        align-items: center;
        padding: 6px 14px;
        background: var(--white);
        border: 1.5px solid var(--border-light);
        border-radius: 999px;
        font-size: 12px;
        color: var(--text-light);
        cursor: pointer;
        transition: var(--transition);
        font-family: 'Kanit', sans-serif;
        user-select: none
    }

    .check-item:hover span {
        border-color: var(--primary-light);
        color: var(--primary-dark)
    }

    .check-item input:checked+span {
        background: rgba(44, 74, 47, .1);
        border-color: var(--primary-dark);
        color: var(--primary-dark);
        font-weight: 600
    }

    .modal-footer {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 24px;
        padding-top: 18px;
        border-top: 1px solid var(--border-light)
    }

    .mbtn {
        padding: 10px 22px;
        border-radius: 10px;
        font-family: 'Kanit', sans-serif;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: var(--transition)
    }

    .mbtn-cancel {
        background: var(--bg-cream);
        color: var(--text-dark);
        border: 1.5px solid var(--border-light)
    }

    .mbtn-cancel:hover {
        background: var(--border-light)
    }

    .mbtn-confirm {
        background: var(--primary-dark);
        color: var(--white)
    }

    .mbtn-confirm:hover {
        background: var(--primary-medium);
        transform: translateY(-1px);
        box-shadow: 0 4px 14px rgba(44, 74, 47, .3)
    }

    .img-upload-label {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        background: var(--bg-cream);
        border: 1.5px dashed var(--border-light);
        border-radius: 10px;
        cursor: pointer;
        color: var(--text-light);
        font-size: 13px;
        font-family: 'Kanit', sans-serif;
        transition: var(--transition)
    }

    .img-upload-label:hover {
        border-color: var(--primary-dark);
        color: var(--primary-dark);
        background: rgba(44, 74, 47, .04)
    }

    .status-badge.active-badge {
        background: rgba(16, 185, 129, .1);
        border: 1.5px solid #10B981;
        color: #059669
    }

    .status-badge.inactive-badge {
        background: rgba(239, 68, 68, .08);
        border: 1.5px solid var(--danger);
        color: var(--danger)
    }

    .status-badge.completed-badge {
        background: rgba(99, 102, 241, .1);
        border: 1.5px solid #6366f1;
        color: #4338ca
    }

    .toggle-btn {
        cursor: pointer;
        border: none;
        background: none;
        padding: 0;
        display: inline-flex
    }

    .toggle-btn:hover .status-badge {
        opacity: .8;
        transform: scale(.97)
    }

    .notif-empty {
        text-align: center;
        padding: 26px 16px;
        color: var(--text-light);
        font-size: 13px
    }

    .notif-empty svg {
        display: block;
        margin: 0 auto 10px;
        opacity: .3
    }

    .sort-option {
        font-family: 'Kanit', sans-serif
    }

    .sort-option.active-sort {
        color: var(--primary-dark) !important;
        font-weight: 700
    }

    /* ══ Activity Detail Panel ══ */
    .detail-panel {
        background: var(--bg-cream);
        border: 1.5px solid var(--border-light);
        border-radius: 14px;
        padding: 18px 20px;
        margin-top: 10px
    }

    .detail-panel-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px
    }

    .booking-date-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 10px 14px;
        background: var(--white);
        border-radius: 10px;
        border: 1px solid var(--border-light);
        margin-bottom: 8px;
        font-size: 13px;
        font-family: 'Kanit', sans-serif
    }

    .booking-date-row:last-child {
        margin-bottom: 0
    }

    .pax-bar {
        height: 6px;
        background: var(--border-light);
        border-radius: 99px;
        overflow: hidden;
        flex: 1;
        margin: 0 14px
    }

    .pax-fill {
        height: 100%;
        background: var(--primary-dark);
        border-radius: 99px;
        transition: width .6s ease
    }

    .pax-text {
        font-size: 12px;
        color: var(--text-light);
        white-space: nowrap
    }

    .pax-remaining {
        font-size: 12px;
        color: var(--danger);
        font-weight: 600;
        white-space: nowrap
    }

    /* ══ Complete Button ══ */
    .btn-complete {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 16px;
        border-radius: 8px;
        font-family: 'Kanit', sans-serif;
        font-size: 12px;
        font-weight: 700;
        border: none;
        cursor: pointer;
        background: rgba(99, 102, 241, .12);
        color: #4338ca;
        border: 1.5px solid rgba(99, 102, 241, .3);
        transition: var(--transition);
    }

    .btn-complete:hover {
        background: rgba(99, 102, 241, .22);
        transform: translateY(-1px)
    }

    .btn-complete:disabled {
        opacity: .5;
        cursor: not-allowed;
        transform: none
    }

    /* ══ Capacity badge ══ */
    .cap-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 9px;
        border-radius: 99px;
        background: rgba(44, 74, 47, .08);
        color: var(--primary-dark);
        border: 1px solid rgba(44, 74, 47, .15);
    }

    .cap-badge.full {
        background: rgba(239, 68, 68, .08);
        color: var(--danger);
        border-color: rgba(239, 68, 68, .2)
    }

    .cap-badge.empty {
        background: rgba(107, 114, 128, .08);
        color: var(--text-light);
        border-color: var(--border-light)
    }

    /* ══ Expandable row ══ */
    .expand-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-light);
        padding: 4px;
        border-radius: 6px;
        transition: var(--transition);
        display: inline-flex;
        align-items: center
    }

    .expand-btn:hover {
        background: var(--bg-cream);
        color: var(--primary-dark)
    }

    .expand-btn svg {
        transition: transform .2s
    }

    .expand-btn.open svg {
        transform: rotate(180deg)
    }

    .detail-row {
        display: none
    }

    .detail-row.open {
        display: table-row
    }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
    <div id="wrapper">

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <button class="sidebar-toggle" id="sidebarToggle">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M15 9l-6 6M9 9l6 6"></path>
                    </svg>
                </button>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <p class="nav-section-title">General</p>
                    <a href="/tkn/dashboard" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="3" width="7" height="7"></rect>
                            <rect x="14" y="3" width="7" height="7"></rect>
                            <rect x="14" y="14" width="7" height="7"></rect>
                            <rect x="3" y="14" width="7" height="7"></rect>
                        </svg>
                        <span>Dashboard</span>
                    </a>
                    <a href="/tkn/my-shop" class="nav-item active">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                        </svg>
                        <span>Activity Management</span>
                    </a>
                    <a href="/tkn/booking-history" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <span>Booking</span>
                    </a>
                    <a href="/tkn/billing" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                            <line x1="2" y1="10" x2="22" y2="10"></line>
                        </svg>
                        <span>Billing</span>
                    </a>
                </div>
                <div class="nav-section">
                    <p class="nav-section-title">Tools</p>
                    <a href="/tkn/owner-feedback" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                        </svg>
                        <span>Feedback</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
                <div class="header-actions">
                    <!-- Notification -->
                    <div class="notification-wrapper">
                        <button class="notification-btn" id="notificationBtn">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            <?php if($total_notifs>0): ?><span
                                class="notification-badge"><?=$total_notifs?></span><?php endif; ?>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header">
                                <h3>การแจ้งเตือน</h3>
                            </div>
                            <div class="notification-list">
                                <?php if($total_notifs===0): ?>
                                <div class="notif-empty">
                                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.5">
                                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                    </svg>
                                    ไม่มีการแจ้งเตือนใหม่
                                </div>
                                <?php else: ?>
                                <?php foreach($notif_bookings as $nb): ?>
                                <div class="notification-item unread">
                                    <div class="notification-icon"><svg width="20" height="20" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                        </svg></div>
                                    <div class="notification-content">
                                        <p class="notification-title">การจองใหม่ (อนุมัติแล้ว)</p>
                                        <p class="notification-text"><?=htmlspecialchars($nb['fullname'])?> จอง
                                            "<?=htmlspecialchars($nb['activity_name'])?>"</p>
                                        <p class="notification-time">
                                            <?=date('d/m/Y H:i',strtotime($nb['booking_date']))?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button class="notification-view-all">ดูทั้งหมด</button>
                        </div>
                    </div>
                    <!-- User Menu -->
                    <div class="user-menu-wrapper">
                        <button class="user-menu-btn" id="userMenuBtn">
                            <div class="user-avatar"><svg width="24" height="24" viewBox="0 0 24 24"
                                    fill="currentColor">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg></div>
                            <span
                                class="user-name">ผู้ประกอบการ<br><small><?=htmlspecialchars($owner_name)?></small></span>
                            <svg class="dropdown-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div class="user-dropdown" id="userDropdown">
                            <a href="/tkn/shop" class="user-dropdown-item"><svg width="18" height="18"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg><span>Edit Profile</span></a>
                            <a href="/tkn/logout" class="user-dropdown-item"><svg width="18" height="18"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                    <polyline points="16 17 21 12 16 7"></polyline>
                                    <line x1="21" y1="12" x2="9" y2="12"></line>
                                </svg><span>Logout</span></a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">จัดการกิจกรรม</h1>
                <div style="display:flex;gap:10px;align-items:center;">
                    <!-- Sort -->
                    <div style="position:relative;" id="sortWrapper">
                        <button class="sort-btn" id="sortBtn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M12 5v14M5 12l7 7 7-7"></path>
                            </svg>
                            <span id="sortLabel">จัดเรียง</span>
                        </button>
                        <div id="sortDropdown"
                            style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:var(--white);border:1px solid var(--border-light);border-radius:10px;min-width:170px;overflow:hidden;z-index:100;box-shadow:0 8px 24px rgba(0,0,0,.12)">
                            <?php $curSort=$_GET['sort']??'default'; ?>
                            <a href="?sort=default" class="sort-option <?=$curSort==='default'?'active-sort':''?>"
                                style="display:block;padding:10px 16px;color:var(--text-dark);font-size:13px;text-decoration:none;transition:background .15s"
                                onmouseover="this.style.background='var(--bg-cream)'"
                                onmouseout="this.style.background=''">เริ่มต้น</a>
                            <a href="?sort=name" class="sort-option <?=$curSort==='name'?'active-sort':''?>"
                                style="display:block;padding:10px 16px;color:var(--text-dark);font-size:13px;text-decoration:none;transition:background .15s"
                                onmouseover="this.style.background='var(--bg-cream)'"
                                onmouseout="this.style.background=''">ชื่อ A-Z</a>
                            <a href="?sort=price" class="sort-option <?=$curSort==='price'?'active-sort':''?>"
                                style="display:block;padding:10px 16px;color:var(--text-dark);font-size:13px;text-decoration:none;transition:background .15s"
                                onmouseover="this.style.background='var(--bg-cream)'"
                                onmouseout="this.style.background=''">ราคาน้อย→มาก</a>
                            <a href="?sort=status" class="sort-option <?=$curSort==='status'?'active-sort':''?>"
                                style="display:block;padding:10px 16px;color:var(--text-dark);font-size:13px;text-decoration:none;transition:background .15s"
                                onmouseover="this.style.background='var(--bg-cream)'"
                                onmouseout="this.style.background=''">สถานะ</a>
                        </div>
                    </div>
                    <!-- Add Activity -->
                    <button class="add-btn" onclick="openAddModal()">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        สร้างกิจกรรมใหม่
                    </button>
                    <!-- Request Open Round -->
                    <button class="add-btn" onclick="openRequestModal()" style="background:rgba(79,70,229,.85);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                            <line x1="12" y1="14" x2="12" y2="18" />
                            <line x1="10" y1="16" x2="14" y2="16" />
                        </svg>
                        ส่งคำขอจัดกิจกรรม
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <?php
    $pending_cnt  = count(array_filter($open_requests, fn($r)=>$r['req_status']==='Pending'));
    $approved_cnt = count($approved_requests);
    $completed_activity_cnt = count($completed_activity_requests);
    $rejected_cnt = count(array_filter($open_requests, fn($r)=>$r['req_status']==='Rejected'));
    $history_cnt  = count($open_requests);
    // นับเฉพาะ template activities (ไม่นับ derived)
    $template_cnt = count(array_filter($activities, fn($a) => !isset($derived_activity_ids[$a['activity_id']])));
    ?>
            <div
                style="display:flex;gap:0;border-bottom:2px solid var(--border-light);margin-bottom:18px;flex-wrap:wrap;">
                <button id="tab1Btn" onclick="switchTab(1)"
                    style="padding:10px 26px;font-family:'Kanit',sans-serif;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid var(--primary-dark);color:var(--primary-dark);margin-bottom:-2px;transition:all .2s;">
                    กิจกรรมทั้งหมด
                    <span
                        style="font-size:12px;background:rgba(44,74,47,.1);color:var(--primary-dark);padding:2px 8px;border-radius:99px;margin-left:6px;"><?=$template_cnt?></span>
                </button>
                <button id="tab2Btn" onclick="switchTab(2)"
                    style="padding:10px 26px;font-family:'Kanit',sans-serif;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;color:var(--text-light);margin-bottom:-2px;transition:all .2s;">
                    กิจกรรมที่เปิดรอบอยู่
                    <?php if($pending_cnt > 0): ?>
                    <span
                        style="font-size:12px;background:rgba(245,158,11,.15);color:#92400e;padding:2px 8px;border-radius:99px;margin-left:6px;"><?=$pending_cnt?>
                        รอพิจารณา</span>
                    <?php endif; ?>
                    <span
                        style="font-size:12px;background:rgba(16,185,129,.12);color:#065f46;padding:2px 8px;border-radius:99px;margin-left:4px;"><?=$approved_cnt?>
                        เปิดอยู่</span>
                </button>
                <button id="tab3Btn" onclick="switchTab(3)"
                    style="padding:10px 26px;font-family:'Kanit',sans-serif;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;color:var(--text-light);margin-bottom:-2px;transition:all .2s;">
                    ประวัติการจัดกิจกรรม
                    <span
                        style="font-size:12px;background:rgba(79,70,229,.1);color:#4338ca;padding:2px 8px;border-radius:99px;margin-left:6px;"><?=$completed_activity_cnt?>
                        รายการ</span>
                </button>
                <button id="tab4Btn" onclick="switchTab(4)"
                    style="padding:10px 26px;font-family:'Kanit',sans-serif;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;color:var(--text-light);margin-bottom:-2px;transition:all .2s;">
                    ประวัติการขอจัดกิจกรรม
                    <?php if($rejected_cnt > 0): ?>
                    <span
                        style="font-size:12px;background:rgba(239,68,68,.12);color:#991b1b;padding:2px 8px;border-radius:99px;margin-left:6px;"><?=$rejected_cnt?>
                        ปฏิเสธ</span>
                    <?php endif; ?>
                    <span
                        style="font-size:12px;background:rgba(100,116,139,.1);color:#475569;padding:2px 8px;border-radius:99px;margin-left:4px;"><?=$history_cnt?>
                        รายการ</span>
                </button>
            </div>

            <!-- ══ TAB 1: Activity Table ══ -->
            <div id="tab1Content">
                <!-- Activity Table -->
                <div class="activity-table-container">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th style="width:36px;"></th>
                                <th>#</th>
                                <th>ชื่อกิจกรรม</th>
                                <th>ระยะเวลา</th>
                                <th>ราคาเด็ก (฿)</th>
                                <th>ราคาผู้ใหญ่ (฿)</th>
                                <th>ความจุ / จอง</th>
                                <th>สถานะ</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($activities)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;padding:40px;color:var(--text-light);">
                                    ยังไม่มีกิจกรรม — กดปุ่ม "สร้างกิจกรรมใหม่" ด้านบน</td>
                            </tr>
                            <?php else: ?>
                            <?php $rowNum = 0; foreach($activities as $i=>$act):
                    // ข้าม derived activities (รอบที่สร้างจาก approved request — แสดงใน Tab 2 แทน)
                    if (isset($derived_activity_ids[$act['activity_id']])) continue;
                    $rowNum++;
                    $isActive    = $act['status'] === 'Active';
                    $isCompleted = $act['status'] === 'Completed';
                    $isInactive  = $act['status'] === 'Inactive';
                    $tagIds      = $act['tag_ids'] ? explode(',',$act['tag_ids']) : [];
                    // สถานะการเผยแพร่ตาม request
                    $reqMap        = $act_request_status[$act['activity_id']] ?? [];
                    $activeRound   = $active_round_by_template[(int)$act['activity_id']] ?? null;
                    $hasApproved   = $activeRound !== null;
                    $canCompleteActiveRound = $activeRound && $activeRound['can_complete'];
                    $hasPending    = ($reqMap['Pending']  ?? 0) > 0;
                    $displayActivityId = $activeRound
                        ? (int)$activeRound['activity_id']
                        : (int)$act['activity_id'];
                    $bookedPax    = $activeRound
                        ? (int)$activeRound['booked_pax']
                        : (int)$act['booked_pax'];
                    $bookingCount = $activeRound
                        ? (int)$activeRound['booking_count']
                        : (int)$act['booking_count'];
                    $maxCap       = $activeRound && (int)$activeRound['max_capacity'] > 0
                        ? (int)$activeRound['max_capacity']
                        : (int)$act['max_capacity'];
                    $remaining   = $maxCap - $bookedPax;
                    $pct         = $maxCap > 0 ? min(100, round($bookedPax/$maxCap*100)) : 0;

                    // ดึง bookings แยกตามวัน (Paid only)
                    $bdq = $conn->prepare(
                        "SELECT DATE(b.booking_date) as bdate,
                                SUM(b.adult_quantity+b.kid_quantity) as pax,
                                COUNT(*) as cnt,
                                GROUP_CONCAT(u.fullname ORDER BY b.booking_id SEPARATOR ', ') as names
                         FROM booking b JOIN user u ON b.user_id=u.user_id
                         WHERE b.activity_id=? AND b.status='Paid'
                         GROUP BY DATE(b.booking_date)
                         ORDER BY bdate ASC"
                    );
                    $bdq->bind_param("i",$displayActivityId); $bdq->execute();
                    $bookingsByDate = $bdq->get_result()->fetch_all(MYSQLI_ASSOC); $bdq->close();
                ?>
                            <!-- Main Row -->
                            <tr id="row-<?=$act['activity_id']?>">
                                <td>
                                    <button class="expand-btn" id="expand-<?=$act['activity_id']?>"
                                        onclick="toggleDetail(<?=$act['activity_id']?>)" title="ดูรายละเอียดการจอง">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <polyline points="6 9 12 15 18 9"></polyline>
                                        </svg>
                                    </button>
                                </td>
                                <td style="color:var(--text-light);font-size:13px;"><?=$rowNum?></td>
                                <td>
                                    <div style="font-weight:600;color:var(--primary-dark);max-width:200px;">
                                        <?=htmlspecialchars($act['activity_name'])?></div>
                                    <?php if($act['suitable_for']): ?>
                                    <div style="font-size:11px;color:var(--text-light);margin-top:3px;">
                                        <?=htmlspecialchars($act['suitable_for'])?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?=htmlspecialchars($act['duration_label']??'—')?></td>
                                <td><?=number_format($act['kid_price'])?></td>
                                <td><?=number_format($act['adult_price'])?></td>
                                <td>
                                    <div style="font-size:13px;font-weight:600;color:var(--text-dark);">
                                        <?=$bookedPax?>/<?=$maxCap?> คน</div>
                                    <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                                        <div
                                            style="flex:1;height:5px;background:var(--border-light);border-radius:99px;overflow:hidden;">
                                            <div
                                                style="width:<?=$pct?>%;height:100%;background:var(--primary-dark);border-radius:99px;">
                                            </div>
                                        </div>
                                        <?php if($remaining > 0): ?>
                                        <span class="cap-badge">ว่าง <?=$remaining?></span>
                                        <?php elseif($remaining === 0 && $maxCap > 0): ?>
                                        <span class="cap-badge full">เต็ม</span>
                                        <?php else: ?>
                                        <span class="cap-badge empty">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if($canCompleteActiveRound): ?>
                                    <span class="status-badge"
                                        style="background:rgba(245,158,11,.1);border:1.5px solid rgba(245,158,11,.3);color:#92400e;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <polyline points="12 6 12 12 16 14" />
                                        </svg>
                                        <span>รอยืนยันเสร็จสิ้น</span>
                                    </span>
                                    <?php elseif($hasApproved): ?>
                                    <span class="status-badge active-badge">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <path d="M8 12l3 3 5-5" />
                                        </svg>
                                        <span>มีรอบเปิดอยู่</span>
                                    </span>
                                    <?php elseif($hasPending): ?>
                                    <span class="status-badge"
                                        style="background:rgba(245,158,11,.1);border:1.5px solid rgba(245,158,11,.5);color:#92400e;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <polyline points="12 6 12 12 16 14" />
                                        </svg>
                                        <span>รอการอนุมัติ</span>
                                    </span>
                                    <?php else: ?>
                                    <span class="status-badge inactive-badge">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <line x1="8" y1="8" x2="16" y2="16" />
                                            <line x1="16" y1="8" x2="8" y2="16" />
                                        </svg>
                                        <span>ไม่เผยแพร่</span>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                                        <button class="action-btn edit-btn"
                                            onclick='openEditModal(<?=json_encode($act, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE)?>, <?=json_encode($tagIds)?>)'>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7">
                                                </path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z">
                                                </path>
                                            </svg>
                                            แก้ไข
                                        </button>
                                        <?php if($canCompleteActiveRound): ?>
                                        <button class="btn-complete"
                                            id="complete-template-<?=$activeRound['activity_id']?>"
                                            onclick='completeActivity(
                                                <?=$activeRound['activity_id']?>,
                                                <?=htmlspecialchars(json_encode($act["activity_name"], JSON_HEX_TAG | JSON_HEX_APOS), ENT_COMPAT)?>,
                                                <?=$activeRound['booking_count']?>,
                                                <?=$activeRound['booked_pax']?>,
                                                <?=$activeRound['points_reward']?>,
                                                "complete-template-<?=$activeRound['activity_id']?>"
                                            )'>
                                            เสร็จสิ้น
                                        </button>
                                        <?php endif; ?>
                                        <?php if(!$hasApproved && !$hasPending): ?>
                                        <button class="btn-complete"
                                            style="background:rgba(79,70,229,.1);color:#3730a3;border-color:rgba(79,70,229,.3);"
                                            onclick="openRequestModal(<?=$act['activity_id']?>)">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2.5">
                                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                                <line x1="12" y1="9" x2="12" y2="15" />
                                                <line x1="9" y1="12" x2="15" y2="12" />
                                            </svg>
                                            ขอเปิดรอบ
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Detail Row (expandable) -->
                            <tr class="detail-row" id="detail-row-<?=$act['activity_id']?>">
                                <td colspan="9" style="padding:0 16px 16px;background:var(--bg-cream);">
                                    <div class="detail-panel">
                                        <div class="detail-panel-title">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                            </svg>
                                            รายละเอียดการจอง (เฉพาะที่อนุมัติแล้ว)
                                        </div>
                                        <div class="mobile-activity-tools">
                                            <div class="mobile-activity-status">
                                                <span>สถานะ</span>
                                                <?php if($canCompleteActiveRound): ?>
                                                <strong class="is-pending">รอยืนยันเสร็จสิ้น</strong>
                                                <?php elseif($hasApproved): ?>
                                                <strong class="is-active">มีรอบเปิดอยู่</strong>
                                                <?php elseif($hasPending): ?>
                                                <strong class="is-pending">รอการอนุมัติ</strong>
                                                <?php else: ?>
                                                <strong class="is-inactive">ไม่เผยแพร่</strong>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mobile-activity-actions">
                                                <button class="action-btn edit-btn"
                                                    onclick='openEditModal(<?=json_encode($act, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_UNESCAPED_UNICODE)?>, <?=json_encode($tagIds)?>)'>
                                                    แก้ไข
                                                </button>
                                                <?php if($canCompleteActiveRound): ?>
                                                <button class="btn-complete"
                                                    onclick='completeActivity(
                                                        <?=$activeRound['activity_id']?>,
                                                        <?=htmlspecialchars(json_encode($act["activity_name"], JSON_HEX_TAG | JSON_HEX_APOS), ENT_COMPAT)?>,
                                                        <?=$activeRound['booking_count']?>,
                                                        <?=$activeRound['booked_pax']?>,
                                                        <?=$activeRound['points_reward']?>,
                                                        "complete-template-<?=$activeRound['activity_id']?>"
                                                    )'>
                                                    เสร็จสิ้น
                                                </button>
                                                <?php endif; ?>
                                                <?php if(!$hasApproved && !$hasPending): ?>
                                                <button class="btn-complete"
                                                    onclick="openRequestModal(<?=$act['activity_id']?>)">
                                                    ขอเปิดรอบ
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if(empty($bookingsByDate)): ?>
                                        <p
                                            style="font-size:13px;color:var(--text-light);text-align:center;padding:16px 0;">
                                            ยังไม่มีการจองที่ได้รับการอนุมัติ</p>
                                        <?php else: ?>
                                        <?php foreach($bookingsByDate as $bd):
                                    $bdPct = $maxCap > 0 ? min(100, round($bd['pax']/$maxCap*100)) : 0;
                                    $bdRemaining = $maxCap - $bd['pax'];
                                ?>
                                        <div class="booking-date-row">
                                            <div style="min-width:100px;font-weight:600;color:var(--primary-dark);">
                                                <?=date('d/m/Y', strtotime($bd['bdate']))?>
                                            </div>
                                            <div class="pax-bar">
                                                <div class="pax-fill" style="width:<?=$bdPct?>%;"></div>
                                            </div>
                                            <div class="pax-text" style="min-width:90px;">
                                                <strong><?=$bd['pax']?></strong>/<?=$maxCap?> คน
                                            </div>
                                            <div class="pax-remaining" style="min-width:80px;">
                                                <?php if($bdRemaining > 0): ?>
                                                ว่างอีก <?=$bdRemaining?> คน
                                                <?php else: ?>
                                                <span style="color:var(--danger);">เต็มแล้ว</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:11px;color:var(--text-light);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-left:10px;"
                                                title="<?=htmlspecialchars($bd['names'])?>">
                                                <?=htmlspecialchars(mb_substr($bd['names'],0,40))?><?=mb_strlen($bd['names'])>40?'...':''?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div
                                            style="font-size:12px;color:var(--text-light);margin-top:8px;text-align:right;">
                                            รวมทุกวัน: <strong
                                                style="color:var(--primary-dark);"><?=$bookedPax?></strong> คน จาก
                                            <?=$maxCap?> คน
                                            <?php if($bookedPax > 0): ?>
                                            · แต้มที่จะได้รับ/คน: <strong
                                                style="color:#4338ca;"><?=$act['points_reward']??10?> แต้ม</strong>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pager -->
                <?php if ($total_acts > 0): ?>
                <?php $base_p = '?sort=' . urlencode($sort) . '&'; ?>
                <div
                    style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;background:var(--white);border-top:1px solid var(--border-light);border-radius:0 0 16px 16px;flex-wrap:wrap;gap:10px;">
                    <span style="font-size:13px;color:var(--text-light);">
                        แสดง <?= $offset + 1 ?>ถึง<?= min($offset + $per_page, $total_acts) ?>
                        จาก <?= number_format($total_acts) ?> กิจกรรม
                    </span>
                    <?php if ($total_pages > 1): ?>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <?php
            $prev_dis = $act_page <= 1 ? 'opacity:.35;pointer-events:none;' : '';
            $next_dis = $act_page >= $total_pages ? 'opacity:.35;pointer-events:none;' : '';
            $btn = 'display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;border:1px solid var(--border-light);text-decoration:none;color:var(--text-dark);font-size:15px;';
            echo "<a href=\"{$base_p}page=" . ($act_page-1) . "\" style=\"background:var(--bg-cream);{$btn}{$prev_dis}\">‹</a>";
            $st = max(1, $act_page-2); $en = min($total_pages, $act_page+2);
            if ($st > 1) echo '<span style="padding:0 4px;color:var(--text-light);">&#8230;</span>';
            for ($i=$st; $i<=$en; $i++):
                $bg = $i===$act_page ? 'background:var(--primary-dark);color:#fff;' : 'background:var(--bg-cream);';
                echo "<a href=\"{$base_p}page=$i\" style=\"{$btn}{$bg}\">$i</a>";
            endfor;
            if ($en < $total_pages) echo '<span style="padding:0 4px;color:var(--text-light);">&#8230;</span>';
            echo "<a href=\"{$base_p}page=" . ($act_page+1) . "\" style=\"background:var(--bg-cream);{$btn}{$next_dis}\">›</a>";
            ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- FAB -->
                <!-- <button class="fab" onclick="openAddModal()">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
    </button> -->
            </div><!-- end tab1Content -->

            <!-- ══ TAB 2: กิจกรรมที่เปิดรอบอยู่ (Approved only) ══ -->
            <div id="tab2Content" style="display:none;">

                <?php if($pending_cnt > 0): ?>
                <!-- แจ้งเตือนมี Pending -->
                <div
                    style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:12px;padding:12px 18px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;color:#92400e;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10" />
                        <polyline points="12 6 12 12 16 14" />
                    </svg>
                    มีคำขอรออนุมัติ <strong><?=$pending_cnt?> รายการ</strong> — รอแอดมินพิจารณา
                </div>
                <?php endif; ?>

                <?php if(empty($approved_requests)): ?>
                <div style="text-align:center;padding:60px 20px;color:var(--text-light);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        style="opacity:.3;display:block;margin:0 auto 14px;">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    ยังไม่มีกิจกรรมที่ได้รับอนุมัติให้เปิดรอบ<br>
                    <span style="font-size:12px;">ส่งคำขอจากแท็บ "กิจกรรมทั้งหมด" หรือปุ่ม "ส่งคำขอจัดกิจกรรม"
                        ด้านบน</span>
                </div>
                <?php else: ?>
                <div class="activity-table-container">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th style="width:36px;"></th>
                                <th>#</th>
                                <th>กิจกรรม</th>
                                <th>ช่วงเวลา</th>
                                <th>รับได้ / จองแล้ว</th>
                                <th>ราคา (฿)</th>
                                <th>สถานะรอบ</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($approved_requests as $i=>$req):
                    $nid = (int)($req['new_activity_id'] ?? 0);

                    // ดึงข้อมูล activity ที่สร้างจาก approved
                    $new_act_data = null;
                    if ($nid) {
                        $naq = $conn->prepare("SELECT max_capacity, capacity_remaining,
                            (max_capacity - capacity_remaining) AS booked_pax, status,
                            COALESCE((SELECT COUNT(DISTINCT booking_id) FROM booking WHERE activity_id=? AND status='Paid'),0) AS booking_count,
                            start_date, end_date, points_reward
                            FROM activity WHERE activity_id=?");
                        $naq->bind_param("ii", $nid, $nid);
                        $naq->execute();
                        $new_act_data = $naq->get_result()->fetch_assoc();
                        $naq->close();
                    }

                    $bookingsForAct  = $approved_bookings_by_date[$nid] ?? [];
                    $bookingsMap     = [];
                    foreach ($bookingsForAct as $bd) $bookingsMap[$bd['bdate']] = $bd;
                    $completedDatesMap = $completed_by_date[$nid] ?? [];

                    // สร้าง occurrence dates
                    $occurrences = $nid
                        ? getOccurrenceDates($req['requested_start_date'], $req['requested_end_date'])
                        : [date('Y-m-d', strtotime($req['requested_start_date']))];

                    $isRecurring = count($occurrences) > 1;
                    $maxC  = $new_act_data ? (int)$new_act_data['max_capacity']          : 0;
                    $remC  = $new_act_data ? (int)$new_act_data['capacity_remaining']     : 0;
                    $bkdTotal = $new_act_data ? (int)$new_act_data['booked_pax']          : 0;
                    $pct2     = $maxC > 0 ? min(100, round($bkdTotal/$maxC*100))          : 0;
                    $actStatus = $new_act_data['status'] ?? '—';
                    $roundEnd = new DateTime($req['round_end_date'] ?: $req['requested_end_date']);
                    $canCompleteRound = new DateTime('now') >= $roundEnd;
                ?>
                            <!-- หัวแถว -->
                            <tr id="t2row-<?=$i?>">
                                <td>
                                    <button class="expand-btn" id="t2expand-<?=$i?>" onclick="toggleDetail2(<?=$i?>)"
                                        title="ดูการจองรายวัน">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <polyline points="6 9 12 15 18 9" />
                                        </svg>
                                    </button>
                                </td>
                                <td style="color:var(--text-light);font-size:13px;"><?=$i+1?></td>
                                <td>
                                    <div style="font-weight:600;color:var(--primary-dark);max-width:180px;">
                                        <?=htmlspecialchars($req['activity_name'])?></div>
                                    <div style="font-size:11px;color:var(--text-light);">
                                        <?=htmlspecialchars($req['duration_label']??'—')?></div>
                                    <?php if($isRecurring): ?>
                                    <div style="font-size:11px;color:rgba(79,70,229,.8);margin-top:3px;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;">
                                            <path d="M23 4v6h-6" />
                                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
                                        </svg>
                                        จัดซ้ำ <?=count($occurrences)?> รอบ
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:var(--text-dark);">
                                    <?php
                            $s = new DateTime($req['requested_start_date']);
                            $e = new DateTime($req['requested_end_date']);
                            echo $s->format('d/m/Y') . ($isRecurring ? ' –' : '') . '<br>';
                            if ($isRecurring) echo $e->format('d/m/Y');
                            else echo $s->format('H:i') . ' – ' . $e->format('H:i');
                        ?>
                                </td>
                                <td>
                                    <?php if($new_act_data): ?>
                                    <div style="font-size:13px;font-weight:600;color:var(--text-dark);">
                                        <?=$bkdTotal?>/<?=$maxC?> คน</div>
                                    <div style="display:flex;align-items:center;gap:6px;margin-top:4px;">
                                        <div
                                            style="flex:1;height:5px;background:var(--border-light);border-radius:99px;overflow:hidden;min-width:60px;">
                                            <div
                                                style="width:<?=$pct2?>%;height:100%;background:var(--primary-dark);border-radius:99px;">
                                            </div>
                                        </div>
                                        <?php if($remC > 0): ?><span class="cap-badge">ว่าง <?=$remC?></span>
                                        <?php elseif($remC === 0 && $maxC > 0): ?><span
                                            class="cap-badge full">เต็ม</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-light);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:12px;">ผู้ใหญ่:
                                        <strong><?=number_format($req['adult_price'])?></strong>
                                    </div>
                                    <div style="font-size:12px;color:var(--text-light);">เด็ก:
                                        <?=number_format($req['kid_price'])?></div>
                                </td>
                                <td>
                                    <?php if($canCompleteRound): ?>
                                    <span class="status-badge"
                                        style="background:rgba(245,158,11,.1);border:1.5px solid rgba(245,158,11,.3);color:#92400e;">
                                        รอยืนยันเสร็จสิ้น
                                    </span>
                                    <?php elseif($actStatus === 'Active'): ?>
                                    <span class="status-badge active-badge">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <path d="M8 12l3 3 5-5" />
                                        </svg>
                                        กำลังเปิดรับ
                                    </span>
                                    <?php elseif($actStatus === 'Completed'): ?>
                                    <span class="status-badge completed-badge">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                            <polyline points="22 4 12 14.01 9 11.01" />
                                        </svg>
                                        สำเร็จแล้ว
                                    </span>
                                    <?php else: ?>
                                    <span class="status-badge"
                                        style="background:rgba(16,185,129,.08);border:1.5px solid rgba(16,185,129,.3);color:#065f46;">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2.5">
                                            <path d="M20 6L9 17l-5-5" />
                                        </svg>
                                        อนุมัติแล้ว
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($canCompleteRound): ?>
                                    <button class="btn-complete" id="complete-<?=$nid?>"
                                        onclick='completeActivity(
                                            <?=$nid?>,
                                            <?=htmlspecialchars(json_encode($req["activity_name"], JSON_HEX_TAG | JSON_HEX_APOS), ENT_COMPAT)?>,
                                            <?=$new_act_data ? (int)$new_act_data["booking_count"] : 0?>,
                                            <?=$bkdTotal?>,
                                            <?=(int)($new_act_data["points_reward"] ?? 10)?>
                                        )'
                                        style="padding:7px 12px;font-size:11px;">
                                        เสร็จสิ้น
                                    </button>
                                    <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-light);">
                                        กดที่แต่ละวัน ↓
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- แถวขยาย: การจองรายวัน / รายรอบ -->
                            <tr class="detail-row" id="t2detail-row-<?=$i?>">
                                <td colspan="8" style="padding:0 16px 16px;background:var(--bg-cream);">
                                    <div class="detail-panel">
                                        <div class="detail-panel-title">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2">
                                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                                <line x1="16" y1="2" x2="16" y2="6" />
                                                <line x1="8" y1="2" x2="8" y2="6" />
                                                <line x1="3" y1="10" x2="21" y2="10" />
                                            </svg>
                                            <?=$isRecurring ? 'การจองแยกตามรอบวัน' : 'การจองในรอบนี้'?>
                                        </div>
                                        <div class="mobile-activity-tools">
                                            <div class="mobile-activity-status">
                                                <span>สถานะรอบ</span>
                                                <?php if($canCompleteRound): ?>
                                                <strong class="is-pending">รอยืนยันเสร็จสิ้น</strong>
                                                <?php elseif($actStatus === 'Active'): ?>
                                                <strong class="is-active">กำลังเปิดรับ</strong>
                                                <?php elseif($actStatus === 'Completed'): ?>
                                                <strong>สำเร็จแล้ว</strong>
                                                <?php else: ?>
                                                <strong class="is-active">อนุมัติแล้ว</strong>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($canCompleteRound): ?>
                                            <div class="mobile-activity-actions">
                                                <button class="btn-complete"
                                                    onclick='completeActivity(
                                                        <?=$nid?>,
                                                        <?=htmlspecialchars(json_encode($req["activity_name"], JSON_HEX_TAG | JSON_HEX_APOS), ENT_COMPAT)?>,
                                                        <?=$new_act_data ? (int)$new_act_data["booking_count"] : 0?>,
                                                        <?=$bkdTotal?>,
                                                        <?=(int)($new_act_data["points_reward"] ?? 10)?>
                                                    )'>
                                                    เสร็จสิ้น
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if(empty($occurrences)): ?>
                                        <p
                                            style="font-size:13px;color:var(--text-light);text-align:center;padding:12px 0;">
                                            ไม่พบข้อมูล</p>
                                        <?php else: ?>
                                        <?php foreach($occurrences as $occ):
                                    $occDt  = new DateTime($occ);
                                    $occDay = $thaiDays[(int)$occDt->format('w')];
                                    $occD   = (int)$occDt->format('j');
                                    $occM   = $thaiMonths[(int)$occDt->format('n')];
                                    $occY   = (int)$occDt->format('Y') + 543;
                                    $bdRow  = $bookingsMap[$occ] ?? null;
                                    $occPax = $bdRow ? (int)$bdRow['pax'] : 0;
                                    $occBookings = $bdRow ? (int)$bdRow['booking_cnt'] : 0;
                                    $occPct = $maxC > 0 ? min(100, round($occPax/$maxC*100)) : 0;
                                    $occRem = $maxC - $occPax;
                                ?>
                                        <div class="booking-date-row">
                                            <div
                                                style="min-width:160px;font-weight:600;color:var(--primary-dark);font-size:13px;">
                                                <?="วัน$occDay ที่ $occD $occM"?>
                                                <?php if($occPax === 0): ?>
                                                <span
                                                    style="font-size:11px;font-weight:400;color:var(--text-light);margin-left:6px;">ยังไม่มีการจอง</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="pax-bar">
                                                <div class="pax-fill" style="width:<?=$occPct?>%;"></div>
                                            </div>
                                            <div class="pax-text" style="min-width:90px;">
                                                <strong><?=$occPax?></strong>/<?=$maxC?> คน
                                            </div>
                                            <div class="pax-remaining" style="min-width:80px;">
                                                <?php if($occPax > 0 && $occRem > 0): ?>
                                                ว่างอีก <?=$occRem?> คน
                                                <?php elseif($occPax > 0 && $occRem <= 0 && $maxC > 0): ?>
                                                <span style="color:var(--danger);">เต็มแล้ว</span>
                                                <?php else: ?>
                                                <span style="color:var(--text-light);">—</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($bdRow && $bdRow['names']): ?>
                                            <div style="font-size:11px;color:var(--text-light);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-left:10px;"
                                                title="<?=htmlspecialchars($bdRow['names'])?>">
                                                <?=htmlspecialchars(mb_substr($bdRow['names'],0,30))?><?=mb_strlen($bdRow['names'])>30?'...':''?>
                                            </div>
                                            <?php endif; ?>
                                            <!-- ปุ่มเสร็จสิ้น / สถานะ ต่อวัน -->
                                            <div style="margin-left:12px;flex-shrink:0;">
                                                <?php
                                    $btnDateId = 'complete-date-'.$nid.'-'.str_replace('-','_',$occ);
                                    if ($bdRow && (int)$bdRow['booking_cnt'] > 0):
                                        $occPassed = ($occ <= date('Y-m-d')); ?>
                                                <button class="btn-complete" id="<?=$btnDateId?>"
                                                    <?php if (!$occPassed): ?> disabled
                                                    title="กิจกรรมยังไม่ถึงวันที่จัด (<?=$occ?>)"
                                                    style="padding:5px 12px;font-size:11px;opacity:0.45;cursor:not-allowed;"
                                                    <?php else: ?>
                                                    onclick="completeDateBookings(<?=$nid?>, '<?=$occ?>', <?=(int)$bdRow['booking_cnt']?>, <?=(int)$bdRow['pax']?>, <?=(int)($new_act_data['points_reward']??10)?>, <?=htmlspecialchars(json_encode($req['activity_name'],JSON_HEX_TAG|JSON_HEX_APOS),ENT_COMPAT)?>)"
                                                    style="padding:5px 12px;font-size:11px;" <?php endif; ?>>
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2.5">
                                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                                        <polyline points="22 4 12 14.01 9 11.01" />
                                                    </svg>
                                                    <?php echo $occPassed ? 'เสร็จสิ้น' : '⏳ ยังไม่ถึงวัน'; ?>
                                                </button>
                                                <?php elseif(isset($completedDatesMap[$occ]) && $completedDatesMap[$occ] > 0): ?>
                                                <span style="font-size:11px;color:#4338ca;font-weight:600;">✓
                                                    สำเร็จแล้ว</span>
                                                <?php else: ?>
                                                <span style="font-size:11px;color:var(--text-light);">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div
                                            style="font-size:12px;color:var(--text-light);margin-top:8px;text-align:right;">
                                            รวมทุกรอบ: <strong
                                                style="color:var(--primary-dark);"><?=$bkdTotal?></strong> คน
                                            · แต้มที่จะได้รับ/คน: <strong
                                                style="color:#4338ca;"><?=$new_act_data['points_reward']??10?>
                                                แต้ม</strong>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div><!-- end tab2Content -->

            <!-- ══ TAB 3: ประวัติการจัดกิจกรรม ══ -->
            <div id="tab3Content" style="display:none;">
                <?php if(empty($completed_activity_requests)): ?>
                <div style="text-align:center;padding:60px 20px;color:var(--text-light);">
                    ยังไม่มีประวัติการจัดกิจกรรม
                </div>
                <?php else: ?>
                <div class="activity-table-container">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>กิจกรรม</th>
                                <th>ช่วงเวลาที่จัด</th>
                                <th>ราคา (฿)</th>
                                <th>สถานะ</th>
                                <th>เสร็จสิ้นเมื่อ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($completed_activity_requests as $index => $req): ?>
                            <tr>
                                <td style="color:var(--text-light);"><?=$index + 1?></td>
                                <td>
                                    <div style="font-weight:600;color:var(--primary-dark);">
                                        <?=htmlspecialchars($req['activity_name'])?>
                                    </div>
                                    <div style="font-size:11px;color:var(--text-light);">
                                        <?=htmlspecialchars($req['duration_label'] ?? '—')?>
                                    </div>
                                </td>
                                <td style="font-size:12px;">
                                    <?=date('d/m/Y H:i', strtotime($req['round_start_date'] ?: $req['requested_start_date']))?>
                                    <br>–
                                    <?=date('d/m/Y H:i', strtotime($req['round_end_date'] ?: $req['requested_end_date']))?>
                                </td>
                                <td style="font-size:12px;">
                                    ผู้ใหญ่: <strong><?=number_format($req['adult_price'])?></strong><br>
                                    <span style="color:var(--text-light);">เด็ก: <?=number_format($req['kid_price'])?></span>
                                </td>
                                <td>
                                    <span class="status-badge completed-badge">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                        </svg>
                                        เสร็จสิ้นแล้ว
                                    </span>
                                </td>
                                <td style="font-size:12px;color:var(--text-light);">
                                    <?=$req['completed_at']
                                        ? date('d/m/Y H:i', strtotime($req['completed_at']))
                                        : '—'?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div><!-- end tab3Content -->

            <!-- ══ TAB 4: ประวัติการขอจัดกิจกรรม ══ -->
            <div id="tab4Content" style="display:none;">

                <?php if(empty($open_requests)): ?>
                <div style="text-align:center;padding:60px 20px;color:var(--text-light);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        style="opacity:.3;display:block;margin:0 auto 14px;">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="16" y1="13" x2="8" y2="13" />
                        <line x1="16" y1="17" x2="8" y2="17" />
                        <polyline points="10 9 9 9 8 9" />
                    </svg>
                    ยังไม่มีประวัติการขอจัดกิจกรรม<br>
                    <span style="font-size:12px;">เมื่อคุณส่งคำขอเปิดรอบกิจกรรม รายการจะแสดงที่นี่</span>
                </div>
                <?php else: ?>

                <!-- Summary cards -->
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                    <div
                        style="flex:1;min-width:120px;background:#fff;border:1.5px solid var(--border-light);border-radius:12px;padding:14px 18px;text-align:center;">
                        <div style="font-size:22px;font-weight:700;color:var(--primary-dark);"><?=$history_cnt?></div>
                        <div style="font-size:12px;color:var(--text-light);margin-top:2px;">คำขอทั้งหมด</div>
                    </div>
                    <div
                        style="flex:1;min-width:120px;background:#fffbeb;border:1.5px solid rgba(245,158,11,.3);border-radius:12px;padding:14px 18px;text-align:center;">
                        <div style="font-size:22px;font-weight:700;color:#92400e;"><?=$pending_cnt?></div>
                        <div style="font-size:12px;color:#92400e;margin-top:2px;">รอพิจารณา</div>
                    </div>
                    <div
                        style="flex:1;min-width:120px;background:#f0fdf4;border:1.5px solid rgba(16,185,129,.3);border-radius:12px;padding:14px 18px;text-align:center;">
                        <div style="font-size:22px;font-weight:700;color:#065f46;"><?=$approved_cnt?></div>
                        <div style="font-size:12px;color:#065f46;margin-top:2px;">อนุมัติแล้ว</div>
                    </div>
                    <div
                        style="flex:1;min-width:120px;background:#fef2f2;border:1.5px solid rgba(239,68,68,.25);border-radius:12px;padding:14px 18px;text-align:center;">
                        <div style="font-size:22px;font-weight:700;color:#991b1b;"><?=$rejected_cnt?></div>
                        <div style="font-size:12px;color:#991b1b;margin-top:2px;">ปฏิเสธ</div>
                    </div>
                </div>

                <!-- Request history table -->
                <div class="activity-table-container">
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>กิจกรรม</th>
                                <th>วันที่ขอ</th>
                                <th>ช่วงเวลาที่ขอ</th>
                                <th>หมายเหตุจาก Owner</th>
                                <th>สถานะ</th>
                                <th>หมายเหตุจากแอดมิน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($open_requests as $idx => $req):
                                $rs = $req['req_status'];
                                $reqDt = new DateTime($req['requested_at']);
                                $sDate = new DateTime(explode(' ', $req['requested_start_date'])[0]);
                                $eDate = new DateTime(explode(' ', $req['requested_end_date'])[0]);
                                $diffDays = (int)$sDate->diff($eDate)->days;
                                $isSingle = $diffDays < 7;
                            ?>
                            <tr
                                style="<?= $rs === 'Rejected' ? 'background:rgba(239,68,68,.02);' : ($rs === 'Approved' ? 'background:rgba(16,185,129,.02);' : '') ?>">
                                <td style="color:var(--text-light);font-size:13px;"><?=$idx+1?></td>
                                <td>
                                    <div style="font-weight:600;color:var(--primary-dark);max-width:180px;">
                                        <?=htmlspecialchars($req['activity_name'])?></div>
                                    <div style="font-size:11px;color:var(--text-light);">
                                        <?=htmlspecialchars($req['duration_label'] ?? '—')?>
                                    </div>
                                    <?php if($rs === 'Approved' && $req['new_activity_id']): ?>
                                    <div
                                        style="font-size:11px;color:#065f46;margin-top:3px;display:flex;align-items:center;gap:3px;">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2.5">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                            <polyline points="22 4 12 14.01 9 11.01" />
                                        </svg>
                                        <?=($req['round_status'] ?? '') === 'Completed'
                                            ? 'ขึ้นในแท็บ "ประวัติการจัดกิจกรรม"'
                                            : 'ขึ้นในแท็บ "กิจกรรมที่เปิดรอบอยู่"'?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:var(--text-dark);white-space:nowrap;">
                                    <?=$reqDt->format('d/m/Y')?><br>
                                    <span style="color:var(--text-light);"><?=$reqDt->format('H:i')?> น.</span>
                                </td>
                                <td style="font-size:12px;color:var(--text-dark);">
                                    <?php if($isSingle): ?>
                                    <?=$sDate->format('d/m/Y')?><br>
                                    <span
                                        style="color:var(--text-light);"><?=(new DateTime($req['requested_start_date']))->format('H:i')?>
                                        – <?=(new DateTime($req['requested_end_date']))->format('H:i')?> น.</span>
                                    <?php else: ?>
                                    <?=$sDate->format('d/m/Y')?> –<br><?=$eDate->format('d/m/Y')?>
                                    <div style="font-size:11px;color:rgba(79,70,229,.7);margin-top:2px;">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2.5" style="vertical-align:middle;">
                                            <path d="M23 4v6h-6" />
                                            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
                                        </svg>
                                        ซ้ำทุกสัปดาห์
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px;color:var(--text-dark);max-width:160px;">
                                    <?php if(trim($req['note'] ?? '')): ?>
                                    <?=nl2br(htmlspecialchars($req['note']))?>
                                    <?php else: ?>
                                    <span style="color:var(--text-light);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($rs === 'Pending'): ?>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;background:rgba(245,158,11,.12);color:#92400e;border:1.5px solid rgba(245,158,11,.3);padding:4px 10px;border-radius:99px;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <polyline points="12 6 12 12 16 14" />
                                        </svg>
                                        รอพิจารณา
                                    </span>
                                    <?php elseif($rs === 'Approved'): ?>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;background:rgba(16,185,129,.1);color:#065f46;border:1.5px solid rgba(16,185,129,.3);padding:4px 10px;border-radius:99px;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                            <polyline points="22 4 12 14.01 9 11.01" />
                                        </svg>
                                        อนุมัติแล้ว
                                    </span>
                                    <?php elseif($rs === 'Rejected'): ?>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;background:rgba(239,68,68,.1);color:#991b1b;border:1.5px solid rgba(239,68,68,.25);padding:4px 10px;border-radius:99px;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10" />
                                            <line x1="15" y1="9" x2="9" y2="15" />
                                            <line x1="9" y1="9" x2="15" y2="15" />
                                        </svg>
                                        ปฏิเสธ
                                    </span>
                                    <?php else: ?>
                                    <span
                                        style="font-size:12px;color:var(--text-light);"><?=htmlspecialchars($rs)?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width:200px;">
                                    <?php if($rs === 'Approved'): ?>
                                    <div
                                        style="font-size:12px;color:#065f46;display:flex;align-items:flex-start;gap:5px;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2"
                                            style="flex-shrink:0;margin-top:1px;">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                            <polyline points="22 4 12 14.01 9 11.01" />
                                        </svg>
                                        <span>
                                            <?php if(trim($req['admin_note'] ?? '')): ?>
                                            <?=nl2br(htmlspecialchars($req['admin_note']))?>
                                            <?php else: ?>
                                            <?=($req['round_status'] ?? '') === 'Completed'
                                                ? 'กิจกรรมเสร็จสิ้นแล้ว และถูกย้ายไปแท็บ "ประวัติการจัดกิจกรรม"'
                                                : 'อนุมัติคำขอแล้ว กิจกรรมถูกเปิดรอบในแท็บ "กิจกรรมที่เปิดรอบอยู่"'?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <?php elseif($rs === 'Rejected'): ?>
                                    <div
                                        style="font-size:12px;color:#991b1b;display:flex;align-items:flex-start;gap:5px;">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2"
                                            style="flex-shrink:0;margin-top:1px;">
                                            <circle cx="12" cy="12" r="10" />
                                            <line x1="15" y1="9" x2="9" y2="15" />
                                            <line x1="9" y1="9" x2="15" y2="15" />
                                        </svg>
                                        <span>
                                            <?php if(trim($req['admin_note'] ?? '')): ?>
                                            <?=nl2br(htmlspecialchars($req['admin_note']))?>
                                            <?php else: ?>
                                            <span style="color:var(--text-light);">ไม่มีเหตุผลเพิ่มเติม</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <button type="button" class="mbtn mbtn-confirm"
                                        style="margin-top:8px;padding:6px 11px;font-size:11px;background:#b91c1c;"
                                        onclick='openEditRequestModal(<?=htmlspecialchars(json_encode([
                                            "request_id" => (int)$req["request_id"],
                                            "activity_id" => (int)$req["activity_id"],
                                            "requested_start_date" => $req["requested_start_date"],
                                            "requested_end_date" => $req["requested_end_date"],
                                            "note" => $req["note"] ?? ""
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES)?>)'>
                                        แก้ไขและส่งใหม่
                                    </button>
                                    <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-light);">รอแอดมินพิจารณา</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div><!-- end tab4Content -->

        </main>

        <!-- ══════════════ REQUEST MODAL ══════════════ -->
        <div class="modal-overlay" id="requestModalOverlay">
            <div class="modal" style="max-width:600px;">
                <button class="modal-close" onclick="closeModal('requestModalOverlay')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <h2 id="requestModalTitle">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    <span id="requestModalTitleText">ส่งคำขอจัดกิจกรรม</span>
                </h2>
                <p id="requestModalIntro"
                    style="font-size:12px;background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.25);color:#3730a3;padding:10px 14px;border-radius:8px;margin-bottom:18px;">
                    📋 เลือกกิจกรรมต้นแบบ พร้อมระบุช่วงเวลาที่ต้องการจัด แอดมินจะพิจารณาและแจ้งผลให้ทราบ
                </p>
                <form id="requestForm">
                    <!-- Activity Select -->
                    <div class="mform-group">
                        <label class="mform-label">เลือกกิจกรรม <span style="color:#e07070">*</span></label>
                        <select class="mform-select" name="activity_id" id="req_activity_id" required>
                            <option value="">-- เลือกกิจกรรมที่ต้องการเปิดรอบ --</option>
                            <?php foreach($activities as $act): ?>
                            <?php if(isset($derived_activity_ids[$act['activity_id']])) continue; ?>
                            <option value="<?=$act['activity_id']?>"
                                data-duration="<?=htmlspecialchars($act['duration_label'] ?? '', ENT_QUOTES)?>">
                                <?=htmlspecialchars($act['activity_name'])?><?=$act['duration_label']?' ('.$act['duration_label'].')':'' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11px;color:var(--text-light);margin-top:5px;">
                            กิจกรรมเดิมสามารถเปิดรอบใหม่ได้หลายครั้ง ทุกครั้งต้องขออนุมัติใหม่</div>
                    </div>

                    <!-- Schedule Type Toggle -->
                    <div class="mform-group">
                        <label class="mform-label">รูปแบบวันที่จัด <span style="color:#e07070">*</span></label>
                        <div style="display:flex;gap:10px;margin-bottom:14px;">
                            <label style="flex:1;cursor:pointer;">
                                <input type="radio" name="schedule_type" value="fixed" id="sched_fixed" checked
                                    style="display:none;">
                                <div class="sched-type-btn" id="sched_fixed_btn"
                                    style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:2px solid var(--primary-dark);background:rgba(44,74,47,.07);transition:var(--transition);">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="var(--primary-dark)" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" />
                                        <line x1="16" y1="2" x2="16" y2="6" />
                                        <line x1="8" y1="2" x2="8" y2="6" />
                                        <line x1="3" y1="10" x2="21" y2="10" />
                                        <line x1="8" y1="14" x2="8" y2="14" stroke-linecap="round" stroke-width="3" />
                                    </svg>
                                    <div>
                                        <div
                                            style="font-size:13px;font-weight:700;color:var(--primary-dark);font-family:'Kanit',sans-serif;">
                                            กำหนดวันเอง</div>
                                        <div style="font-size:11px;color:var(--text-light);">
                                            ระบุวันเริ่ม-สิ้นสุดเฉพาะเจาะจง</div>
                                    </div>
                                </div>
                            </label>
                            <label style="flex:1;cursor:pointer;">
                                <input type="radio" name="schedule_type" value="recurring" id="sched_recurring"
                                    style="display:none;">
                                <div class="sched-type-btn" id="sched_recurring_btn"
                                    style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:12px;border:2px solid var(--border-light);background:var(--bg-cream);transition:var(--transition);">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="var(--text-light)" stroke-width="2">
                                        <path d="M23 4v6h-6" />
                                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10" />
                                    </svg>
                                    <div>
                                        <div
                                            style="font-size:13px;font-weight:700;color:var(--text-dark);font-family:'Kanit',sans-serif;">
                                            จัดซ้ำทุกสัปดาห์</div>
                                        <div style="font-size:11px;color:var(--text-light);">เลือกวันในสัปดาห์</div>
                                    </div>
                                </div>
                            </label>
                        </div>

                        <!-- Fixed date inputs -->
                        <div id="fixed_date_section">
                            <!-- วันที่จัดกิจกรรม -->
                            <div class="mform-grid2" style="margin-bottom:12px;">
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">วันเริ่มต้น <span style="color:#e07070">*</span></label>
                                    <input type="date" class="mform-input" id="fixed_date_from">
                                </div>
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">วันสิ้นสุด <span style="color:#e07070">*</span></label>
                                    <input type="date" class="mform-input" id="fixed_date_to">
                                </div>
                            </div>
                            <!-- เวลากิจกรรมต่อวัน -->
                            <div class="mform-grid2">
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">เวลาเริ่ม (ต่อวัน) <span
                                            style="color:#e07070">*</span></label>
                                    <input type="time" class="mform-input" id="fixed_time_start" value="09:00">
                                </div>
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">เวลาสิ้นสุด (ต่อวัน) <span
                                            style="color:#e07070">*</span></label>
                                    <input type="time" class="mform-input" id="fixed_time_end" value="12:00"
                                        readonly>
                                </div>
                            </div>
                            <div id="fixed_time_help"
                                style="font-size:11px;color:var(--text-light);margin-top:7px;">
                                เลือกกิจกรรมแล้วระบบจะกำหนดเวลาเริ่มต้นและคำนวณเวลาสิ้นสุดจากระยะเวลาในฐานข้อมูล
                            </div>
                            <!-- Duration preview -->
                            <div id="fixed_duration_preview"
                                style="display:none;margin-top:10px;padding:10px 14px;background:rgba(44,74,47,.06);border-radius:10px;font-size:12px;color:var(--primary-dark);font-family:'Kanit',sans-serif;">
                            </div>
                        </div>

                        <!-- Recurring inputs -->
                        <div id="recurring_date_section" style="display:none;">
                            <div style="margin-bottom:10px;">
                                <label class="mform-label">วันในสัปดาห์ที่จัด <span
                                        style="color:#e07070">*</span></label>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                                    <?php
                        $days = ['จันทร์'=>'Mon','อังคาร'=>'Tue','พุธ'=>'Wed','พฤหัส'=>'Thu','ศุกร์'=>'Fri','เสาร์'=>'Sat','อาทิตย์'=>'Sun'];
                        foreach($days as $label => $val): ?>
                                    <label style="cursor:pointer;">
                                        <input type="checkbox" name="recurring_days[]" value="<?=$val?>"
                                            class="day-check" style="display:none;">
                                        <span class="day-pill"
                                            style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:36px;border-radius:99px;border:1.5px solid var(--border-light);font-size:12px;font-weight:600;color:var(--text-light);cursor:pointer;transition:var(--transition);font-family:'Kanit',sans-serif;background:var(--white);"><?=$label?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mform-grid2" style="margin-bottom:0;">
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">ช่วงเวลา (เริ่ม) <span
                                            style="color:#e07070">*</span></label>
                                    <input type="time" class="mform-input" name="recurring_start_time"
                                        id="rec_time_start" value="09:00">
                                </div>
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">ช่วงเวลา (สิ้นสุด) <span
                                            style="color:#e07070">*</span></label>
                                    <input type="time" class="mform-input" name="recurring_end_time" id="rec_time_end"
                                        value="17:00" readonly>
                                </div>
                            </div>
                            <div class="mform-grid2" style="margin-top:12px;margin-bottom:0;">
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">ตั้งแต่วันที่ <span
                                            style="color:#e07070">*</span></label>
                                    <input type="date" class="mform-input" name="recurring_date_from"
                                        id="rec_date_from">
                                </div>
                                <div class="mform-group" style="margin-bottom:0;">
                                    <label class="mform-label">ถึงวันที่ <span style="color:#e07070">*</span></label>
                                    <input type="date" class="mform-input" name="recurring_date_to" id="rec_date_to">
                                </div>
                            </div>
                            <div id="recurring_preview"
                                style="display:none;margin-top:10px;padding:10px 14px;background:rgba(44,74,47,.06);border-radius:10px;font-size:12px;color:var(--primary-dark);font-family:'Kanit',sans-serif;">
                            </div>
                        </div>
                    </div>

                    <!-- Note -->
                    <div class="mform-group">
                        <label class="mform-label">หมายเหตุถึงแอดมิน (ถ้ามี)</label>
                        <textarea class="mform-textarea" name="note" id="request_note"
                            placeholder="เช่น เหตุผลที่ขอเปิดรอบ, จำนวนที่นั่งพิเศษ, รายละเอียดเพิ่มเติม..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="mbtn mbtn-cancel"
                            onclick="closeModal('requestModalOverlay')">ยกเลิก</button>
                        <button type="submit" id="requestSubmitButton" class="mbtn mbtn-confirm"
                            style="background:rgba(79,70,229,.85);">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" style="margin-right:5px;">
                                <line x1="22" y1="2" x2="11" y2="13" />
                                <polygon points="22 2 15 22 11 13 2 9 22 2" />
                            </svg>
                            <span id="requestSubmitText">ส่งคำขออนุมัติ</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ══════════════ EDIT MODAL ══════════════ -->
        <div class="modal-overlay" id="editModalOverlay">
            <div class="modal">
                <button class="modal-close" onclick="closeModal('editModalOverlay')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <h2>แก้ไขกิจกรรม</h2>
                <form id="editForm">
                    <input type="hidden" name="action" value="edit_activity">
                    <input type="hidden" name="activity_id" id="edit_activity_id">
                    <div class="mform-group">
                        <label class="mform-label">ชื่อกิจกรรม</label>
                        <input type="text" class="mform-input" name="activity_name" id="edit_activity_name" required>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">คำอธิบาย</label>
                        <textarea class="mform-textarea" name="description" id="edit_description"></textarea>
                    </div>
                    <div class="mform-grid2">
                        <div class="mform-group">
                            <label class="mform-label">ราคาเด็ก (฿)</label>
                            <input type="number" class="mform-input" name="kid_price" id="edit_kid_price" min="0">
                        </div>
                        <div class="mform-group">
                            <label class="mform-label">ราคาผู้ใหญ่ (฿)</label>
                            <input type="number" class="mform-input" name="adult_price" id="edit_adult_price" min="0">
                        </div>
                    </div>
                    <div class="mform-grid2">
                        <div class="mform-group">
                            <label class="mform-label">ความจุสูงสุด (คน)</label>
                            <input type="number" class="mform-input" name="max_capacity" id="edit_max_capacity" min="1">
                        </div>
                        <div class="mform-group">
                            <label class="mform-label">ระยะเวลา</label>
                            <select class="mform-select" name="duration_label" id="edit_duration_label">
                                <option value="">-- เลือก --</option>
                                <option value="1 Hour">1 Hour</option>
                                <option value="2 Hours">2 Hours</option>
                                <option value="Half Day">Half Day</option>
                                <option value="Full Day">Full Day</option>
                            </select>
                        </div>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">แต้มที่ผู้เข้าร่วมจะได้รับ (Points)</label>
                        <input type="number" class="mform-input" name="points_reward" id="edit_points_reward" min="1"
                            value="10">
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">เหมาะสำหรับ</label>
                        <div class="check-group">
                            <?php foreach(['Kids','Adults','Seniors','Family','Couples'] as $sf): ?>
                            <label class="check-item"><input type="checkbox" name="suitable_for[]" value="<?=$sf?>"
                                    class="edit-suitable"><span><?=$sf?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">แท็ก</label>
                        <div class="check-group">
                            <?php foreach($tag_rows as $tag): ?>
                            <label class="check-item"><input type="checkbox" name="tags[]" value="<?=$tag['tag_id']?>"
                                    class="edit-tag"><span><?=$tag['tag_name']?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">รูปภาพกิจกรรม</label>
                        <div id="edit_img_preview_wrap" style="margin-bottom:8px;display:none;">
                            <img id="edit_img_preview" src="" alt="preview"
                                style="width:100%;max-height:160px;object-fit:cover;border-radius:10px;border:1.5px solid var(--border-light);">
                        </div>
                        <label class="img-upload-label" id="edit_img_upload_label" for="edit_activity_pic_input">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="3" />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <polyline points="21 15 16 10 5 21" />
                            </svg>
                            <span id="edit_img_label_text">เปลี่ยนรูปภาพ (JPG, PNG, WEBP ไม่เกิน 5MB)</span>
                        </label>
                        <input type="file" id="edit_activity_pic_input" name="activity_pic"
                            accept="image/jpeg,image/png,image/webp" style="display:none;"
                            onchange="previewEditImg(this)">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="mbtn mbtn-cancel"
                            onclick="closeModal('editModalOverlay')">ยกเลิก</button>
                        <button type="submit" class="mbtn mbtn-confirm">บันทึกการแก้ไข</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ══════════════ ADD MODAL ══════════════ -->
        <div class="modal-overlay" id="addModalOverlay">
            <div class="modal">
                <button class="modal-close" onclick="closeModal('addModalOverlay')">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
                <h2>สร้างกิจกรรมใหม่</h2>
                <p
                    style="font-size:12px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:#92400e;padding:10px 14px;border-radius:8px;margin-bottom:18px;">
                    ⚠️ กิจกรรมใหม่จะอยู่ในสถานะ <strong>รอการอนุมัติ</strong> จากแอดมิน ก่อนจะเผยแพร่ให้ลูกค้าเห็น
                </p>
                <form id="addForm">
                    <input type="hidden" name="action" value="add_activity">
                    <div class="mform-group">
                        <label class="mform-label">ชื่อกิจกรรม <span style="color:#e07070">*</span></label>
                        <input type="text" class="mform-input" name="activity_name" required
                            placeholder="เช่น ปลูกผักออร์แกนิค">
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">คำอธิบาย</label>
                        <textarea class="mform-textarea" name="description"
                            placeholder="อธิบายรายละเอียดกิจกรรม..."></textarea>
                    </div>
                    <div class="mform-grid2">
                        <div class="mform-group">
                            <label class="mform-label">ราคาเด็ก (฿)</label>
                            <input type="number" class="mform-input" name="kid_price" min="0" value="0">
                        </div>
                        <div class="mform-group">
                            <label class="mform-label">ราคาผู้ใหญ่ (฿) <span style="color:#e07070">*</span></label>
                            <input type="number" class="mform-input" name="adult_price" min="0" required value="0">
                        </div>
                    </div>
                    <div class="mform-grid2">
                        <div class="mform-group">
                            <label class="mform-label">ความจุสูงสุด (คน) <span style="color:#e07070">*</span></label>
                            <input type="number" class="mform-input" name="max_capacity" min="1" required value="20">
                        </div>
                        <div class="mform-group">
                            <label class="mform-label">ระยะเวลา</label>
                            <select class="mform-select" name="duration_label">
                                <option value="">-- เลือก --</option>
                                <option value="1 Hour">1 Hour</option>
                                <option value="2 Hours">2 Hours</option>
                                <option value="Half Day">Half Day</option>
                                <option value="Full Day">Full Day</option>
                            </select>
                        </div>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">แต้มที่ผู้เข้าร่วมจะได้รับ (Points)</label>
                        <input type="number" class="mform-input" name="points_reward" min="1" value="10"
                            placeholder="10">
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">เหมาะสำหรับ</label>
                        <div class="check-group">
                            <?php foreach(['Kids','Adults','Seniors','Family','Couples'] as $sf): ?>
                            <label class="check-item"><input type="checkbox" name="suitable_for[]"
                                    value="<?=$sf?>"><span><?=$sf?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">แท็ก</label>
                        <div class="check-group">
                            <?php foreach($tag_rows as $tag): ?>
                            <label class="check-item"><input type="checkbox" name="tags[]"
                                    value="<?=$tag['tag_id']?>"><span><?=$tag['tag_name']?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mform-group">
                        <label class="mform-label">รูปภาพกิจกรรม</label>
                        <div id="add_img_preview_wrap" style="margin-bottom:8px;display:none;">
                            <img id="add_img_preview" src="" alt="preview"
                                style="width:100%;max-height:160px;object-fit:cover;border-radius:10px;border:1.5px solid var(--border-light);">
                        </div>
                        <label class="img-upload-label" id="add_img_upload_label" for="add_activity_pic_input">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <rect x="3" y="3" width="18" height="18" rx="3" />
                                <circle cx="8.5" cy="8.5" r="1.5" />
                                <polyline points="21 15 16 10 5 21" />
                            </svg>
                            <span id="add_img_label_text">อัพโหลดรูปภาพ (JPG, PNG, WEBP ไม่เกิน 5MB)</span>
                        </label>
                        <input type="file" id="add_activity_pic_input" name="activity_pic"
                            accept="image/jpeg,image/png,image/webp" style="display:none;"
                            onchange="previewAddImg(this)">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="mbtn mbtn-cancel"
                            onclick="closeModal('addModalOverlay')">ยกเลิก</button>
                        <button type="submit" class="mbtn mbtn-confirm">ยืนยันสร้างกิจกรรม</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        /* ── Set min date = today for all activity date inputs ── */
        (function() {
            var today = new Date();
            var yyyy = today.getFullYear();
            var mm = String(today.getMonth() + 1).padStart(2, '0');
            var dd = String(today.getDate()).padStart(2, '0');
            var todayStr = yyyy + '-' + mm + '-' + dd;
            ['fixed_date_from', 'fixed_date_to', 'rec_date_from', 'rec_date_to'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.min = todayStr;
            });
        })();

        /* ═══ Tab Switching ═══ */
        let activeActivityTab = 1;

        function switchTab(n) {
            activeActivityTab = n;
            const t1c = document.getElementById('tab1Content');
            const t2c = document.getElementById('tab2Content');
            const t3c = document.getElementById('tab3Content');
            const t4c = document.getElementById('tab4Content');
            const b1 = document.getElementById('tab1Btn');
            const b2 = document.getElementById('tab2Btn');
            const b3 = document.getElementById('tab3Btn');
            const b4 = document.getElementById('tab4Btn');
            const sw = document.getElementById('sortWrapper');

            // Hide all tabs first
            t1c.style.display = 'none';
            t2c.style.display = 'none';
            t3c.style.display = 'none';
            t4c.style.display = 'none';
            b1.style.borderBottomColor = 'transparent';
            b1.style.color = 'var(--text-light)';
            b2.style.borderBottomColor = 'transparent';
            b2.style.color = 'var(--text-light)';
            b3.style.borderBottomColor = 'transparent';
            b3.style.color = 'var(--text-light)';
            b4.style.borderBottomColor = 'transparent';
            b4.style.color = 'var(--text-light)';
            if (sw) sw.style.display = '';

            if (n === 1) {
                t1c.style.display = '';
                b1.style.borderBottomColor = 'var(--primary-dark)';
                b1.style.color = 'var(--primary-dark)';
            } else if (n === 2) {
                t2c.style.display = '';
                b2.style.borderBottomColor = 'rgba(79,70,229,.8)';
                b2.style.color = 'rgba(79,70,229,.9)';
            } else if (n === 3) {
                t3c.style.display = '';
                b3.style.borderBottomColor = 'rgba(79,70,229,.8)';
                b3.style.color = 'rgba(67,56,202,.9)';
            } else if (n === 4) {
                t4c.style.display = '';
                b4.style.borderBottomColor = 'rgba(239,68,68,.7)';
                b4.style.color = 'rgba(185,28,28,.9)';
            }
        }

        /* ═══ Schedule Type Toggle ═══ */
        document.querySelectorAll('input[name="schedule_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const isFixed = this.value === 'fixed';
                document.getElementById('fixed_date_section').style.display = isFixed ? '' : 'none';
                document.getElementById('recurring_date_section').style.display = isFixed ? 'none' : '';

                document.getElementById('sched_fixed_btn').style.borderColor = isFixed ?
                    'var(--primary-dark)' : 'var(--border-light)';
                document.getElementById('sched_fixed_btn').style.background = isFixed ?
                    'rgba(44,74,47,.07)' : 'var(--bg-cream)';
                document.getElementById('sched_fixed_btn').querySelector('div div:first-child').style
                    .color = isFixed ? 'var(--primary-dark)' : 'var(--text-dark)';

                document.getElementById('sched_recurring_btn').style.borderColor = !isFixed ?
                    'rgba(79,70,229,.7)' : 'var(--border-light)';
                document.getElementById('sched_recurring_btn').style.background = !isFixed ?
                    'rgba(79,70,229,.06)' : 'var(--bg-cream)';
            });
        });

        /* Day pill toggle */
        document.querySelectorAll('.day-check').forEach(cb => {
            cb.addEventListener('change', function() {
                const pill = this.nextElementSibling;
                if (this.checked) {
                    pill.style.background = 'rgba(79,70,229,.1)';
                    pill.style.borderColor = 'rgba(79,70,229,.7)';
                    pill.style.color = '#3730a3';
                } else {
                    pill.style.background = 'var(--white)';
                    pill.style.borderColor = 'var(--border-light)';
                    pill.style.color = 'var(--text-light)';
                }
                updateRecurringPreview();
            });
        });

        function updateRecurringPreview() {
            const days = [...document.querySelectorAll('.day-check:checked')].map(c => c.parentElement.querySelector(
                '.day-pill').textContent);
            const from = document.getElementById('rec_date_from').value;
            const to = document.getElementById('rec_date_to').value;
            const t1 = document.getElementById('rec_time_start').value;
            const t2 = document.getElementById('rec_time_end').value;
            const prev = document.getElementById('recurring_preview');
            if (days.length > 0 && from && to && t1 && t2) {
                prev.style.display = '';
                prev.innerHTML =
                    `📅 จัดทุก<strong>${days.join(', ')}</strong> เวลา ${t1}–${t2} น. ตั้งแต่ ${from} ถึง ${to}`;
            } else {
                prev.style.display = 'none';
            }
        }
        ['rec_date_from', 'rec_date_to', 'rec_time_start', 'rec_time_end'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', updateRecurringPreview);
        });

        // ── Fixed date duration preview ──
        function calcDurationLabel(t1, t2) {
            const [h1, m1] = t1.split(':').map(Number);
            const [h2, m2] = t2.split(':').map(Number);
            const diff = (h2 * 60 + m2) - (h1 * 60 + m1);
            if (diff <= 0) return null;
            if (diff <= 60) return '1 ชม.';
            if (diff <= 120) return '2 ชม.';
            if (diff <= 180) return '3 ชม.';
            if (diff <= 240) return '4 ชม.';
            if (diff <= 300) return '5 ชม.';
            if (diff <= 360) return '6 ชม.';
            if (diff <= 480) return 'ครึ่งวัน';
            return 'เต็มวัน';
        }

        function updateFixedPreview() {
            const from = document.getElementById('fixed_date_from').value;
            const to = document.getElementById('fixed_date_to').value;
            const t1 = document.getElementById('fixed_time_start').value;
            const t2 = document.getElementById('fixed_time_end').value;
            const prev = document.getElementById('fixed_duration_preview');
            if (from && to && t1 && t2) {
                const dur = calcDurationLabel(t1, t2);
                if (dur) {
                    prev.style.display = '';
                    const dateLabel = from === to ?
                        `📅 ${from}` :
                        `📅 ${from} – ${to}`;
                    prev.innerHTML = `${dateLabel} &nbsp; 🕐 ${t1}–${t2} น. &nbsp; <strong>${dur} / วัน</strong>`;
                } else {
                    prev.style.display = '';
                    prev.innerHTML = '⚠️ เวลาสิ้นสุดต้องหลังเวลาเริ่มต้น';
                }
            } else {
                prev.style.display = 'none';
            }
        }

        ['fixed_date_from', 'fixed_date_to', 'fixed_time_start', 'fixed_time_end'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', updateFixedPreview);
        });

        /* ═══ Request Modal ═══ */
        let editingRequestId = 0;

        function openRequestModal(preSelectActivityId) {
            editingRequestId = 0;
            document.getElementById('requestForm').reset();
            document.getElementById('req_activity_id').disabled = false;
            document.getElementById('requestModalTitleText').textContent = 'ส่งคำขอจัดกิจกรรม';
            document.getElementById('requestModalIntro').textContent =
                'เลือกกิจกรรมต้นแบบ พร้อมระบุช่วงเวลาที่ต้องการจัด แอดมินจะพิจารณาและแจ้งผลให้ทราบ';
            document.getElementById('requestSubmitText').textContent = 'ส่งคำขออนุมัติ';
            // reset fixed date inputs (form.reset() may not clear these since they have no name)
            ['fixed_date_from', 'fixed_date_to'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            document.getElementById('fixed_time_start').value = '09:00';
            document.getElementById('fixed_time_end').value = '12:00';
            document.getElementById('fixed_duration_preview').style.display = 'none';
            document.getElementById('fixed_date_section').style.display = '';
            document.getElementById('recurring_date_section').style.display = 'none';
            document.getElementById('sched_fixed_btn').style.borderColor = 'var(--primary-dark)';
            document.getElementById('sched_fixed_btn').style.background = 'rgba(44,74,47,.07)';
            document.getElementById('sched_recurring_btn').style.borderColor = 'var(--border-light)';
            document.getElementById('sched_recurring_btn').style.background = 'var(--bg-cream)';
            document.querySelectorAll('.day-pill').forEach(p => {
                p.style.background = 'var(--white)';
                p.style.borderColor = 'var(--border-light)';
                p.style.color = 'var(--text-light)';
            });
            document.getElementById('recurring_preview').style.display = 'none';
            // Pre-select activity ถ้าส่ง id มา
            if (preSelectActivityId) {
                const sel = document.getElementById('req_activity_id');
                if (sel) sel.value = String(preSelectActivityId);
            }
            syncRequestActivityTiming(true);
            openModal('requestModalOverlay');
        }

        function openEditRequestModal(request) {
            openRequestModal(request.activity_id);
            editingRequestId = Number(request.request_id) || 0;

            const activitySelect = document.getElementById('req_activity_id');
            activitySelect.value = String(request.activity_id);
            activitySelect.disabled = true;
            document.getElementById('requestModalTitleText').textContent = 'แก้ไขคำขอที่ถูกปฏิเสธ';
            document.getElementById('requestModalIntro').textContent =
                'ปรับข้อมูลเดิมแล้วส่งกลับไปให้แอดมินพิจารณาใหม่ สถานะจะเปลี่ยนเป็นรอพิจารณา';
            document.getElementById('requestSubmitText').textContent = 'บันทึกและส่งพิจารณาใหม่';

            const start = String(request.requested_start_date || '');
            const end = String(request.requested_end_date || '');
            const note = String(request.note || '');
            const recurringMatch = note.match(/\[จัดซ้ำ:\s*(.*?)\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})\]/);

            if (recurringMatch) {
                document.getElementById('sched_recurring').checked = true;
                document.getElementById('sched_recurring').dispatchEvent(new Event('change'));
                const selectedDays = recurringMatch[1].split(',').map(day => day.trim());
                document.querySelectorAll('.day-check').forEach(cb => {
                    const dayLabel = cb.parentElement.querySelector('.day-pill').textContent.trim();
                    cb.checked = selectedDays.includes(dayLabel);
                    cb.dispatchEvent(new Event('change'));
                });
                document.getElementById('rec_date_from').value = start.slice(0, 10);
                document.getElementById('rec_date_to').value = end.slice(0, 10);
                document.getElementById('rec_time_start').value = recurringMatch[2];
            } else {
                document.getElementById('sched_fixed').checked = true;
                document.getElementById('sched_fixed').dispatchEvent(new Event('change'));
                document.getElementById('fixed_date_from').value = start.slice(0, 10);
                document.getElementById('fixed_date_to').value = end.slice(0, 10);
                document.getElementById('fixed_time_start').value = start.slice(11, 16);
            }

            document.getElementById('request_note').value = note
                .replace(/\s*\[จัดซ้ำ:\s*.*?\s+\d{1,2}:\d{2}-\d{1,2}:\d{2}\]\s*$/, '')
                .trim();
            syncRequestActivityTiming();
        }

        function parseActivityDurationMinutes(label) {
            switch (label) {
                case '1 Hour': return 60;
                case '2 Hours': return 120;
                case 'Half Day': return 240;
                case 'Full Day': return 480;
                default: return null;
            }
        }

        function addMinutesToTime(time, minutes) {
            const [h, m] = time.split(':').map(Number);
            const total = h * 60 + m + minutes;
            if (total >= 24 * 60 || total < 0) return null;
            const nh = Math.floor(total / 60);
            const nm = total % 60;
            return `${String(nh).padStart(2, '0')}:${String(nm).padStart(2, '0')}`;
        }

        function latestStartTime(minutes) {
            const total = (24 * 60) - minutes;
            const h = Math.floor(total / 60);
            const m = total % 60;
            return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
        }

        function getSelectedActivityDuration() {
            const sel = document.getElementById('req_activity_id');
            if (!sel) return '';
            return sel.selectedOptions[0]?.dataset.duration || '';
        }

        function syncRequestActivityTiming(autoSelectStart = false) {
            const durationLabel = getSelectedActivityDuration();
            const minutes = parseActivityDurationMinutes(durationLabel);
            const fixedStart = document.getElementById('fixed_time_start');
            const fixedEnd = document.getElementById('fixed_time_end');
            const recStart = document.getElementById('rec_time_start');
            const recEnd = document.getElementById('rec_time_end');

            if (minutes !== null) {
                const maxStart = latestStartTime(minutes);
                fixedStart.max = maxStart;
                recStart.max = maxStart;
                if (autoSelectStart || fixedStart.value === '') {
                    fixedStart.value = '09:00';
                }
                if (autoSelectStart || recStart.value === '') {
                    recStart.value = '09:00';
                }
                if (fixedStart.value > maxStart) {
                    fixedStart.value = '09:00';
                }
                if (recStart.value > maxStart) {
                    recStart.value = '09:00';
                }
                const fixedNew = addMinutesToTime(fixedStart.value, minutes);
                if (fixedNew) fixedEnd.value = fixedNew;
                const recNew = addMinutesToTime(recStart.value, minutes);
                if (recNew) recEnd.value = recNew;

                fixedEnd.readOnly = true;
                recEnd.readOnly = true;
                fixedEnd.title = 'เวลาสิ้นสุดถูกคำนวณอัตโนมัติตามระยะเวลากิจกรรม';
                recEnd.title = 'เวลาสิ้นสุดถูกคำนวณอัตโนมัติตามระยะเวลากิจกรรม';
            } else {
                fixedStart.removeAttribute('max');
                recStart.removeAttribute('max');
                fixedEnd.readOnly = false;
                recEnd.readOnly = false;
                fixedEnd.title = '';
                recEnd.title = '';
            }

            updateFixedPreview();
            updateRecurringPreview();
        }

        document.getElementById('req_activity_id').addEventListener('change', () => {
            syncRequestActivityTiming(true);
        });
        document.getElementById('fixed_time_start').addEventListener('change', () => {
            syncRequestActivityTiming();
        });
        document.getElementById('rec_time_start').addEventListener('change', () => {
            syncRequestActivityTiming();
        });

        document.getElementById('requestForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const schedType = document.querySelector('input[name="schedule_type"]:checked').value;
            fd.set('activity_id', document.getElementById('req_activity_id').value);
            if (editingRequestId) {
                fd.set('request_id', String(editingRequestId));
            }

            // Build proper start/end from recurring if needed
            if (schedType === 'recurring') {
                const days = [...document.querySelectorAll('.day-check:checked')];
                if (days.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'กรุณาเลือกวันในสัปดาห์',
                        confirmButtonColor: '#2C4A2F'
                    });
                    return;
                }
                const from = fd.get('recurring_date_from');
                const to = fd.get('recurring_date_to');
                const t1 = fd.get('recurring_start_time');
                const t2 = fd.get('recurring_end_time');
                if (!from || !to || !t1 || !t2) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'กรุณากรอกข้อมูลให้ครบ',
                        confirmButtonColor: '#2C4A2F'
                    });
                    return;
                }
                fd.set('requested_start_date', from + 'T' + t1);
                fd.set('requested_end_date', to + 'T' + t2);
                fd.set('note', (fd.get('note') ? fd.get('note') + '\n' : '') + '[จัดซ้ำ: ' + days.map(c => c
                        .parentElement.querySelector('.day-pill').textContent).join(',') + ' ' + t1 +
                    '-' + t2 + ']');
            } else {
                // Fixed mode: build datetime from separate date + time inputs
                const fixedFrom = document.getElementById('fixed_date_from').value;
                const fixedTo = document.getElementById('fixed_date_to').value;
                const fixedT1 = document.getElementById('fixed_time_start').value;
                const fixedT2 = document.getElementById('fixed_time_end').value;
                if (!fixedFrom || !fixedTo || !fixedT1 || !fixedT2) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'กรุณากรอกวันเวลาให้ครบ',
                        text: 'กรุณาระบุวันเริ่ม วันสิ้นสุด เวลาเริ่ม และเวลาสิ้นสุด',
                        confirmButtonColor: '#2C4A2F'
                    });
                    return;
                }
                if (fixedT2 <= fixedT1 && fixedFrom === fixedTo) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'เวลาไม่ถูกต้อง',
                        text: 'เวลาสิ้นสุดต้องหลังเวลาเริ่มต้น',
                        confirmButtonColor: '#2C4A2F'
                    });
                    return;
                }
                fd.set('requested_start_date', fixedFrom + 'T' + fixedT1);
                fd.set('requested_end_date', fixedTo + 'T' + fixedT2);
            }

            fd.append('action', 'request_activity');
            const btn = this.querySelector('[type=submit]');
            const btnLabel = document.getElementById('requestSubmitText');
            btn.disabled = true;
            btnLabel.textContent = 'กำลังส่ง...';
            try {
                const r = await fetch('/tkn/my-shop', {
                    method: 'POST',
                    body: fd
                });
                const d = await r.json();
                if (d.ok) {
                    closeModal('requestModalOverlay');
                    await Swal.fire({
                        title: editingRequestId ? 'ส่งพิจารณาใหม่สำเร็จ!' : 'ส่งคำขอสำเร็จ!',
                        text: d.msg,
                        icon: 'success',
                        confirmButtonColor: '#2C4A2F',
                    });
                    location.href = location.pathname + '?sort=' + (new URLSearchParams(location.search)
                        .get('sort') || 'default') + '#tab2';
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: d.msg || 'ไม่ทราบสาเหตุ'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'เชื่อมต่อไม่ได้',
                    text: err.message
                });
            }
            btn.disabled = false;
            btnLabel.textContent = editingRequestId ? 'บันทึกและส่งพิจารณาใหม่' : 'ส่งคำขออนุมัติ';
        });

        // Auto-switch to requested tab if hash present
        if (location.hash === '#tab2') {
            switchTab(2);
            history.replaceState(null, '', location.pathname + location.search);
        } else if (location.hash === '#tab3') {
            switchTab(3);
            history.replaceState(null, '', location.pathname + location.search);
        } else if (location.hash === '#tab4') {
            switchTab(4);
            history.replaceState(null, '', location.pathname + location.search);
        }

        /* ═══ Modal helpers ═══ */
        function openModal(id) {
            document.getElementById(id).classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
            document.body.style.overflow = '';
        }
        document.querySelectorAll('.modal-overlay').forEach(ov => {
            ov.addEventListener('click', function(e) {
                if (e.target === this) closeModal(this.id);
            });
        });

        /* ═══ Toggle Detail Row (Tab 1) ═══ */
        function toggleDetail(aid) {
            const row = document.getElementById('detail-row-' + aid);
            const btn = document.getElementById('expand-' + aid);
            const isOpen = row.classList.contains('open');
            const detailCell = row.querySelector('td');
            if (detailCell) {
                detailCell.colSpan = window.innerWidth <= 900 ? 5 : 9;
            }
            row.classList.toggle('open', !isOpen);
            btn.classList.toggle('open', !isOpen);
        }

        /* ═══ Toggle Detail Row (Tab 2) ═══ */
        function toggleDetail2(idx) {
            const row = document.getElementById('t2detail-row-' + idx);
            const btn = document.getElementById('t2expand-' + idx);
            const isOpen = row.classList.contains('open');
            const detailCell = row.querySelector('td');
            if (detailCell) {
                detailCell.colSpan = window.innerWidth <= 900 ? 5 : 8;
            }
            row.classList.toggle('open', !isOpen);
            btn.classList.toggle('open', !isOpen);
        }

        /* ═══ Toggle Status ═══ */
        async function toggleStatus(aid, btn) {
            const badge = document.getElementById('badge-' + aid);
            const fd = new FormData();
            fd.append('action', 'toggle_status');
            fd.append('activity_id', aid);
            try {
                const r = await fetch('/tkn/handlers/activity_handle.php', {
                    method: 'POST',
                    body: fd
                });
                const d = await r.json();
                if (d.ok) {
                    const isNowActive = d.new_status === 'Active';
                    badge.className = 'status-badge ' + (isNowActive ? 'active-badge' : 'inactive-badge');
                    badge.innerHTML = isNowActive ?
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 12l3 3 5-5"></path></svg><span>เผยแพร่</span>` :
                        `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="8" x2="16" y2="16"></line><line x1="16" y1="8" x2="8" y2="16"></line></svg><span>หยุดพัก</span>`;
                }
            } catch (e) {
                console.error(e);
            }
        }

        /* ═══ Complete Activity ═══ */
        async function completeDateBookings(aid, bdate, bookingCount, pax, pts, actName) {
            const dateLabel = new Date(bdate + 'T00:00:00').toLocaleDateString('th-TH', {
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
            const result = await Swal.fire({
                title: 'ยืนยันกิจกรรมเสร็จสิ้น?',
                html: `<p><strong>${actName}</strong></p>
               <p style="margin-top:6px;color:#6b7280;font-size:13px;">📅 ${dateLabel}</p>
               <p style="margin-top:10px;">ลูกค้าที่จอง <strong>${bookingCount} ราย</strong> (รวม ${pax} ท่าน)<br>
               จะได้รับ <strong style="color:#4338ca">${pts} แต้ม</strong> ต่อการจอง</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน — เสร็จสิ้น',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#4338ca',
                cancelButtonColor: '#6b7280',
            });
            if (!result.isConfirmed) return;

            const btnId = 'complete-date-' + aid + '-' + bdate.replace(/-/g, '_');
            const btn = document.getElementById(btnId);
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'กำลังบันทึก...';
            }

            const fd = new FormData();
            fd.append('action', 'complete_date');
            fd.append('activity_id', aid);
            fd.append('booking_date', bdate);

            try {
                const r = await fetch('/tkn/my-shop', {
                    method: 'POST',
                    body: fd
                });
                const d = await r.json();
                if (d.ok) {
                    await Swal.fire({
                        title: 'บันทึกสำเร็จ! 🎉',
                        html: `มอบแต้มให้ลูกค้า <strong>${d.awarded} ราย</strong> คนละ <strong style="color:#4338ca">${d.points} แต้ม</strong> เรียบร้อยแล้ว`,
                        icon: 'success',
                        confirmButtonColor: '#2C4A2F',
                    });
                    location.href = location.pathname + location.search + '#tab2';
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: d.msg || 'ไม่ทราบสาเหตุ'
                    });
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'เสร็จสิ้น';
                    }
                }
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'เชื่อมต่อไม่ได้',
                    text: e.message
                });
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'เสร็จสิ้น';
                }
            }
        }

        async function completeActivity(aid, name, bookingCount, pax, pts, buttonId) {
            const result = await Swal.fire({
                title: 'ยืนยันกิจกรรมเสร็จสิ้น?',
                html: `<p>กิจกรรม <strong>${name}</strong></p>
               <p style="margin-top:8px">ลูกค้าที่จอง <strong>${bookingCount} คน</strong> (รวม ${pax} ท่าน) จะได้รับ <strong style="color:#4338ca">${pts} แต้ม</strong> ต่อการจอง<br>
               และจะถูกบันทึกลงใน Passport ของลูกค้า</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'ยืนยัน — กิจกรรมเสร็จสิ้น',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#4338ca',
                cancelButtonColor: '#6b7280',
            });
            if (!result.isConfirmed) return;

            const completeBtn = document.getElementById(buttonId || ('complete-' + aid));
            if (completeBtn) {
                completeBtn.disabled = true;
                completeBtn.textContent = 'กำลังดำเนินการ...';
            }

            const fd = new FormData();
            fd.append('action', 'complete_activity');
            fd.append('activity_id', aid);

            try {
                const r = await fetch('/tkn/my-shop', {
                    method: 'POST',
                    body: fd
                });
                const d = await r.json();
                if (d.ok) {
                    await Swal.fire({
                        title: 'กิจกรรมสำเร็จแล้ว!',
                        html: `มอบแต้มให้ลูกค้าที่จอง <strong>${d.awarded} คน</strong> คนละ <strong style="color:#4338ca">${d.points} แต้ม</strong> เรียบร้อยแล้ว`,
                        icon: 'success',
                        confirmButtonColor: '#2C4A2F',
                    });
                    location.href = location.pathname + location.search + '#tab3';
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: d.msg || 'ไม่ทราบสาเหตุ'
                    });
                    if (completeBtn) {
                        completeBtn.disabled = false;
                        completeBtn.textContent = 'กิจกรรมเสร็จสิ้น';
                    }
                }
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'เชื่อมต่อไม่ได้',
                    text: e.message
                });
                if (completeBtn) {
                    completeBtn.disabled = false;
                    completeBtn.textContent = 'กิจกรรมเสร็จสิ้น';
                }
            }
        }

        /* ═══ Edit Modal ═══ */
        function openEditModal(act, tagIds) {
            document.getElementById('edit_activity_id').value = act.activity_id;
            document.getElementById('edit_activity_name').value = act.activity_name;
            document.getElementById('edit_description').value = act.description;
            document.getElementById('edit_kid_price').value = act.kid_price;
            document.getElementById('edit_adult_price').value = act.adult_price;
            document.getElementById('edit_max_capacity').value = act.max_capacity;
            document.getElementById('edit_duration_label').value = act.duration_label || '';
            document.getElementById('edit_points_reward').value = act.points_reward || 10;
            const sfVals = (act.suitable_for || '').split(',');
            document.querySelectorAll('.edit-suitable').forEach(cb => {
                cb.checked = sfVals.includes(cb.value);
            });
            document.querySelectorAll('.edit-tag').forEach(cb => {
                cb.checked = tagIds.includes(String(cb.value));
            });
            // reset file input
            document.getElementById('edit_activity_pic_input').value = '';
            // show current image if exists
            const wrap = document.getElementById('edit_img_preview_wrap');
            const img = document.getElementById('edit_img_preview');
            if (act.activity_pic) {
                img.src = act.activity_pic.startsWith('http') ? act.activity_pic : '/tkn/handlers/' + act.activity_pic;
                wrap.style.display = 'block';
                document.getElementById('edit_img_label_text').textContent =
                    'เปลี่ยนรูปภาพ (JPG, PNG, WEBP ไม่เกิน 5MB)';
            } else {
                img.src = '';
                wrap.style.display = 'none';
                document.getElementById('edit_img_label_text').textContent =
                    'อัพโหลดรูปภาพ (JPG, PNG, WEBP ไม่เกิน 5MB)';
            }
            openModal('editModalOverlay');
        }

        function previewAddImg(input) {
            if (!input.files || !input.files[0]) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('add_img_preview').src = e.target.result;
                document.getElementById('add_img_preview_wrap').style.display = 'block';
                document.getElementById('add_img_label_text').textContent = input.files[0].name;
            };
            reader.readAsDataURL(input.files[0]);
        }

        function previewEditImg(input) {
            if (!input.files || !input.files[0]) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('edit_img_preview').src = e.target.result;
                document.getElementById('edit_img_preview_wrap').style.display = 'block';
                document.getElementById('edit_img_label_text').textContent = input.files[0].name;
            };
            reader.readAsDataURL(input.files[0]);
        }

        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const btn = this.querySelector('[type=submit]');
            btn.disabled = true;
            btn.textContent = 'กำลังบันทึก...';
            try {
                const r = await fetch('/tkn/handlers/activity_handle.php', {
                    method: 'POST',
                    body: fd
                });
                const text = await r.text();
                let d;
                try {
                    d = JSON.parse(text);
                } catch (pe) {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'Parse error: ' + text.substring(0, 200)
                    });
                    btn.disabled = false;
                    btn.textContent = 'บันทึกการแก้ไข';
                    return;
                }
                if (d.ok) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'บันทึกสำเร็จ! ✅',
                        text: 'แก้ไขกิจกรรมเรียบร้อยแล้ว',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    closeModal('editModalOverlay');
                    location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: d.msg || 'ไม่ทราบสาเหตุ'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'เชื่อมต่อไม่ได้',
                    text: err.message
                });
            }
            btn.disabled = false;
            btn.textContent = 'บันทึกการแก้ไข';
        });

        /* ═══ Add Modal ═══ */
        function openAddModal() {
            document.getElementById('addForm').reset();
            // reset image preview
            document.getElementById('add_img_preview_wrap').style.display = 'none';
            document.getElementById('add_img_preview').src = '';
            document.getElementById('add_img_label_text').textContent = 'อัพโหลดรูปภาพ (JPG, PNG, WEBP ไม่เกิน 5MB)';
            openModal('addModalOverlay');
        }

        document.getElementById('addForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            const btn = this.querySelector('[type=submit]');
            btn.disabled = true;
            btn.textContent = 'กำลังสร้าง...';
            try {
                const r = await fetch('/tkn/handlers/activity_handle.php', {
                    method: 'POST',
                    body: fd
                });
                const text = await r.text();
                let d;
                try {
                    d = JSON.parse(text);
                } catch (pe) {
                    alert('Parse error: ' + text.substring(0, 200));
                    btn.disabled = false;
                    btn.textContent = 'ยืนยันสร้างกิจกรรม';
                    return;
                }
                if (d.ok) {
                    closeModal('addModalOverlay');
                    location.reload();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: d.msg || 'ไม่ทราบสาเหตุ'
                    });
                }
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'เชื่อมต่อไม่ได้',
                    text: e.message
                });
            }
            btn.disabled = false;
            btn.textContent = 'ยืนยันสร้างกิจกรรม';
        });

        /* ═══ Sort Dropdown ═══ */
        document.getElementById('sortBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            const dd = document.getElementById('sortDropdown');
            dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
        });
        document.addEventListener('click', function() {
            document.getElementById('sortDropdown').style.display = 'none';
        });

        document.querySelectorAll('#sortDropdown .sort-option').forEach(option => {
            option.addEventListener('click', function(e) {
                if (activeActivityTab === 1) return;

                e.preventDefault();
                const sortType = new URL(this.href, window.location.href).searchParams.get('sort') || 'default';
                sortActivityTab(activeActivityTab, sortType);
                document.querySelectorAll('#sortDropdown .sort-option').forEach(item =>
                    item.classList.toggle('active-sort', item === this)
                );
                document.getElementById('sortLabel').textContent = this.textContent.trim();
                document.getElementById('sortDropdown').style.display = 'none';
            });
        });

        function sortActivityTab(tabNumber, sortType) {
            const table = document.querySelector(`#tab${tabNumber}Content .activity-table`);
            const tbody = table?.tBodies[0];
            if (!tbody) return;

            const rows = Array.from(tbody.children);
            const groups = [];
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                if (row.classList.contains('detail-row')) continue;
                if (!row.dataset.originalOrder) row.dataset.originalOrder = groups.length;
                const detail = rows[i + 1]?.classList.contains('detail-row') ? rows[i + 1] : null;
                groups.push({ row, detail });
            }

            const columns = {
                2: { name: 2, price: 5, status: 6 },
                3: { name: 1, price: 3, status: 4 },
                4: { name: 1, price: null, status: 5 }
            }[tabNumber];

            groups.sort((a, b) => {
                if (sortType === 'default') {
                    return Number(a.row.dataset.originalOrder) - Number(b.row.dataset.originalOrder);
                }
                const column = columns[sortType];
                if (column === null || column === undefined) return 0;
                const aText = a.row.cells[column]?.textContent.trim() || '';
                const bText = b.row.cells[column]?.textContent.trim() || '';
                if (sortType === 'price') {
                    return (Number(aText.replace(/[^0-9.-]/g, '')) || 0) -
                        (Number(bText.replace(/[^0-9.-]/g, '')) || 0);
                }
                return aText.localeCompare(bText, 'th', { sensitivity: 'base' });
            });

            groups.forEach(group => {
                tbody.appendChild(group.row);
                if (group.detail) tbody.appendChild(group.detail);
            });
        }

        /* ═══ Notification & User Menu ═══ */
        document.addEventListener('DOMContentLoaded', function() {
            const nb = document.getElementById('notificationBtn');
            const nd = document.getElementById('notificationDropdown');
            nb.addEventListener('click', function(e) {
                e.stopPropagation();
                nd.classList.toggle('active');
                document.getElementById('userDropdown').classList.remove('active');
            });
            document.addEventListener('click', function(e) {
                if (!nd.contains(e.target) && e.target !== nb) nd.classList.remove('active');
            });

            const ub = document.getElementById('userMenuBtn');
            const ud = document.getElementById('userDropdown');
            ub.addEventListener('click', function(e) {
                e.stopPropagation();
                ud.classList.toggle('active');
                ub.classList.toggle('active');
                nd.classList.remove('active');
            });
            document.addEventListener('click', function(e) {
                if (!ud.contains(e.target) && !ub.contains(e.target)) {
                    ud.classList.remove('active');
                    ub.classList.remove('active');
                }
            });

            const mb = document.getElementById('mobileMenuBtn');
            const sb = document.getElementById('sidebar');
            if (mb) mb.addEventListener('click', function(e) {
                e.stopPropagation();
                sb.classList.toggle('active');
            });
            document.getElementById('sidebarToggle').addEventListener('click', () => sb.classList.remove(
                'active'));
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 900 && !sb.contains(e.target) && !mb.contains(e.target)) sb.classList
                    .remove('active');
            });
        });
        </script>
</body>

</html>
