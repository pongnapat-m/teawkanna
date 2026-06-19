<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

include '../db.php';
include_once '../config/promotion.php';
ensurePromotionSchema($conn);

// Ensure new field exists (run once in setup). If not, create it safely.
$check = $conn->query("SHOW COLUMNS FROM `booking` LIKE 'payment_deadline'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE `booking` ADD COLUMN `payment_deadline` DATETIME NULL DEFAULT NULL");
}

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนจอง']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

function parseRecurringFromNote($note) {
    if (!$note || !is_string($note)) return null;
    if (!preg_match('/จัดซ้ำ\s*[:：]\s*([^\]]+)/iu', $note, $m)) return null;
    $content = trim($m[1]);
    if (!preg_match('/^([^0-9]+)\s+([0-9]{1,2}:[0-9]{2})\s*-\s*([0-9]{1,2}:[0-9]{2})/u', $content, $p)) {
        return null;
    }
    $dayPart = trim($p[1]);
    $thaiDays = [
        'อาทิตย์' => 0, 'อา' => 0, 'Sunday' => 0, 'Sun' => 0,
        'จันทร์' => 1, 'จ' => 1, 'Monday' => 1, 'Mon' => 1,
        'อังคาร' => 2, 'อ' => 2, 'Tuesday' => 2, 'Tue' => 2,
        'พุธ' => 3, 'พ' => 3, 'Wednesday' => 3, 'Wed' => 3,
        'พฤหัส' => 4, 'พฤ' => 4, 'Thursday' => 4, 'Thu' => 4,
        'ศุกร์' => 5, 'ศ' => 5, 'Friday' => 5, 'Fri' => 5,
        'เสาร์' => 6, 'ส' => 6, 'Saturday' => 6, 'Sat' => 6,
    ];
    $dayItems = preg_split('/\s*,\s*/u', $dayPart);
    $days = [];
    foreach ($dayItems as $d) {
        $d = trim($d);
        if ($d === '') continue;
        if (isset($thaiDays[$d])) {
            $days[] = $thaiDays[$d];
        }
    }
    $days = array_values(array_unique($days));
    if (empty($days)) return null;
    return ['days'=> $days];
}

// ── Input validation ──────────────────────────────────────────────────────────
$activity_id    = (int)($_POST['activity_id']    ?? 0);
$adult_qty      = (int)($_POST['adult']           ?? 0);
$kid_qty        = (int)($_POST['child']            ?? 0);
$total          = (float)($_POST['total']          ?? 0);
$promotion_id   = max(0, (int)($_POST['promotion_id'] ?? 0));
$travel_date    = trim($_POST['travel_date']       ?? '');
$pay_option = in_array($_POST['pay_option'] ?? '', ['now','later']) ? $_POST['pay_option'] : 'now';
$payment_method = null;
$bank_name      = null;
if ($pay_option === 'now') {
    $payment_method = in_array($_POST['payment_method'] ?? '', ['mobile','qr','card'])
                  ? $_POST['payment_method'] : null;
    $bank_name      = ($payment_method === 'mobile')
                  ? trim($_POST['bank_name'] ?? '') : null;
}

if ($activity_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลกิจกรรม']);
    exit;
}
if ($adult_qty + $kid_qty <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุจำนวนผู้เข้าร่วมอย่างน้อย 1 คน']);
    exit;
}
if (empty($travel_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $travel_date)) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกวันเดินทาง']);
    exit;
}
// ตรวจว่าวันที่ไม่ใช่อดีต
if ($travel_date < date('Y-m-d')) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถจองวันที่ผ่านมาแล้วได้']);
    exit;
}

if ($pay_option === 'now' && !$payment_method) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกวิธีการชำระเงิน']);
    exit;
}
if ($pay_option === 'now' && $payment_method === 'mobile' && empty($bank_name)) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกธนาคารสำหรับ Mobile Banking']);
    exit;
}

// ── ดึง activity เพื่อตรวจสอบ ─────────────────────────────────────────────────
$chk = $conn->prepare("
    SELECT a.activity_id, a.adult_price, a.kid_price,
           a.max_capacity, a.status,
            COALESCE(SUM(CASE WHEN (b.status IN ('Paid','PendingReview','Completed') OR (b.status = 'Pending' AND (b.payment_deadline IS NULL OR b.payment_deadline >= NOW()))) AND DATE(b.booking_date)=? THEN b.adult_quantity+b.kid_quantity ELSE 0 END),0) AS used_pax
    FROM   activity a
    LEFT JOIN booking b ON a.activity_id = b.activity_id
    WHERE  a.activity_id = ?
    GROUP BY a.activity_id
    FOR UPDATE
");
$chk->bind_param("si", $travel_date, $activity_id);
$chk->execute();
$act = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$act) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบกิจกรรมนี้']);
    exit;
}
if ($act['status'] !== 'Active') {
    echo json_encode(['status' => 'error', 'message' => 'กิจกรรมนี้ไม่เปิดรับจองในขณะนี้']);
    exit;
}

// ตรวจสอบช่วงวันที่จาก activity_open_request
$req_stmt = $conn->prepare(
    "SELECT requested_start_date, requested_end_date
     FROM activity_open_request
     WHERE new_activity_id = ? AND status = 'Approved'
     ORDER BY requested_start_date ASC
     LIMIT 1"
);
$req_stmt->bind_param('i', $activity_id);
$req_stmt->execute();
$req_row = $req_stmt->get_result()->fetch_assoc();
$req_stmt->close();

if (!$req_row) {
    echo json_encode(['status' => 'error', 'message' => 'กิจกรรมนี้ยังไม่เปิดรับจอง']);
    exit;
}

$req_start = substr($req_row['requested_start_date'], 0, 10);
$req_end   = substr($req_row['requested_end_date'], 0, 10);
$open_recurring = parseRecurringFromNote($req_row['note'] ?? '');

if ($travel_date < $req_start || $travel_date > $req_end) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกวันที่ภายในช่วง ' . $req_start . ' ถึง ' . $req_end]);
    exit;
}

if (!empty($open_recurring['days'])) {
    $ts = strtotime($travel_date);
    if ($ts === false) {
        echo json_encode(['status' => 'error', 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']);
        exit;
    }
    $weekday = (int)date('w', $ts);
    if (!in_array($weekday, $open_recurring['days'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'วันที่เลือกไม่ตรงกับรอบการจัดกิจกรรม']);
        exit;
    }
}

// เช็ค capacity เฉพาะวันที่เลือก (per-date) ป้องกัน overbooking รายวัน
$remaining    = (int)$act['max_capacity'] - (int)$act['used_pax'];
$total_people = $adult_qty + $kid_qty;
if ($remaining < $total_people) {
    echo json_encode([
        'status'  => 'error',
        'message' => "ที่นั่งไม่พอ (เหลือ {$remaining} ที่) กรุณาลดจำนวนผู้เข้าร่วม"
    ]);
    exit;
}

// ── คำนวณราคา server-side (ไม่ trust client total) ───────────────────────────
$adult_price   = (float)$act['adult_price'];
$kid_price     = (float)$act['kid_price'];
$correct_total = ($adult_qty * $adult_price) + ($kid_qty * $kid_price);
$original_total = $correct_total;
$discount_amount = 0.0;
$selected_promotion = null;

if ($promotion_id > 0) {
    $promo = $conn->prepare(
        "SELECT p.*
         FROM promotion p
         WHERE p.promotion_id=? AND p.status='Active'
           AND CURDATE() BETWEEN p.start_date AND p.end_date
         LIMIT 1"
    );
    $promo->bind_param('i', $promotion_id);
    $promo->execute();
    $selected_promotion = $promo->get_result()->fetch_assoc();
    $promo->close();

    if (!$selected_promotion) {
        echo json_encode(['status'=>'error', 'message'=>'โปรโมชั่นนี้หมดอายุหรือไม่สามารถใช้งานได้']);
        exit;
    }
    if ($original_total < (float)$selected_promotion['min_price']) {
        echo json_encode(['status'=>'error', 'message'=>'ยอดจองไม่ถึงขั้นต่ำของโปรโมชั่น']);
        exit;
    }
    $used = $conn->prepare(
        "SELECT usage_id FROM promotion_usage
         WHERE user_id=? AND promotion_id=? LIMIT 1"
    );
    $used->bind_param('ii', $_SESSION['user_id'], $promotion_id);
    $used->execute();
    $already_used = (bool)$used->get_result()->fetch_assoc();
    $used->close();
    if ($already_used) {
        echo json_encode(['status'=>'error', 'message'=>'คุณเคยใช้โปรโมชั่นนี้แล้ว']);
        exit;
    }
    $discount_amount = calculatePromotionDiscount($selected_promotion, $original_total);
    $correct_total = round($original_total - $discount_amount, 2);
}

// ── Transaction: insert booking + update capacity ─────────────────────────────
// For now/pay-now flow, booking is marked Pending until payment confirmation (mobile/QR) completes.
$status = 'Pending';
$payment_deadline = ($pay_option === 'later') ? date('Y-m-d H:i:s', strtotime('+2 days')) : date('Y-m-d H:i:s', strtotime('+30 minutes'));
$promotion_db_id = $promotion_id > 0 ? $promotion_id : null;

$conn->begin_transaction();
try {
    // Insert booking
    $ins = $conn->prepare("
        INSERT INTO booking
            (booking_date, total_price, original_price, discount_amount, promotion_id,
             status, payment_method, bank_name,
             user_id, activity_id,
             adult_quantity, kid_quantity, booked_adult_price, booked_kid_price, payment_deadline)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->bind_param(
        "sdddisssiiiidds",
        $travel_date,
        $correct_total,
        $original_total,
        $discount_amount,
        $promotion_db_id,
        $status,
        $payment_method,
        $bank_name,
        $_SESSION['user_id'],
        $activity_id,
        $adult_qty,
        $kid_qty,
        $adult_price,
        $kid_price,
        $payment_deadline
    );
    $ins->execute();
    $booking_id = (int)$conn->insert_id;
    $ins->close();

    if ($promotion_id > 0) {
        $usage = $conn->prepare(
            "INSERT INTO promotion_usage
                (user_id, promotion_id, booking_id, discount_amount)
             VALUES (?,?,?,?)"
        );
        $usage->bind_param(
            'iiid',
            $_SESSION['user_id'],
            $promotion_id,
            $booking_id,
            $discount_amount
        );
        if (!$usage->execute()) {
            throw new RuntimeException('โปรโมชั่นนี้ถูกใช้ไปแล้ว');
        }
        $usage->close();
    }

    // ไม่หัก capacity_remaining ที่นี่ — จะหักเมื่อ admin อนุมัติ payment เท่านั้น

    $conn->commit();

    // แสดงข้อความสีเขียวเฉพาะตอนเลือกชำระภายหลัง (pay later) เท่านั้น
    if ($pay_option === 'later') {
        $_SESSION['booking_success'] = 'การจองสำเร็จแล้ว กรุณาชำระภายใน 2 วัน ถึง ' . date('d/m/Y H:i', strtotime('+2 days'));
    }
    echo json_encode([
        'status'     => 'success',
        'booking_id' => $booking_id,
        'original_total' => $original_total,
        'discount_amount' => $discount_amount,
        'total'      => $correct_total
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
