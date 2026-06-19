<?php
require_once __DIR__ . '/../config/env.php';
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

include '../db.php';
include '../config/omise_config.php';
include_once '../config/promotion.php';
ensurePromotionSchema($conn);

// ===== ตรวจสอบภาษา =====
$lang = $_SESSION['lang'] ?? 'th';
$isEnglish = ($lang === 'en');

// ===== ข้อความ =====
if ($isEnglish) {
    // English
    $t = [
        'lang'              => 'en',
        'title'             => 'Teawkanna - Agricultural Tourism',
        'nav_home'          => 'Home',
        'nav_trips'         => 'Activities',
        'nav_contact'       => 'Contact',
        'nav_passport'      => 'Passport',
        'nav_logout'        => 'Logout',
        'nav_login'         => 'Login',
        'lang_switch_label' => 'TH',
        'lang_switch_href'  => addLangParam('', 'th'),
        'logout_confirm'    => 'Do you want to logout?',
    ];
} else {
    // Thai
    $t = [
        'lang'              => 'th',
        'title'             => 'เที่ยวกันนา - การท่องเที่ยวเชิงเกษตร',
        'nav_home'          => 'หน้าแรก',
        'nav_trips'         => 'กิจกรรม',
        'nav_contact'       => 'ติดต่อเรา',
        'nav_passport'      => 'พาสปอร์ต',
        'nav_logout'        => 'ออกจากระบบ',
        'nav_login'         => 'เข้าสู่ระบบ',
        'lang_switch_label' => 'EN',
        'lang_switch_href'  => addLangParam('', 'en'),
        'logout_confirm'    => 'คุณต้องการออกจากระบบใช่หรือไม่?',
    ];
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . addLangParam('/tkn/activities')); exit;
}

// ── Activity + Shop + Category ────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT a.*,
           s.shop_name, s.location, s.district, s.province,
           s.latitude,  s.longtitude,
           s.shop_picture, s.shop_phonenumber,
           s.shop_website, s.shop_socailmedia,
           s.status AS shop_status,
           sc.category_name
    FROM   activity a
    JOIN   shop s           ON a.shop_id = s.shop_id
    JOIN   shop_category sc ON s.shop_category_id = sc.shop_category_id
    WHERE  a.activity_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$activity) {
    die('<p style="text-align:center;padding:60px;font-size:1.2rem;">ไม่พบกิจกรรมนี้</p>');
}

$shop_picture_url = resolvePic((string)($activity['shop_picture'] ?? ''));

// โปรโมชั่นที่ยังใช้งานได้และผู้ใช้ยังไม่เคยใช้
$available_promotions = [];
if (isset($_SESSION['user_id'])) {
    $promo_stmt = $conn->prepare(
        "SELECT p.promotion_id, p.title, p.description, p.discount_type,
                p.discount_value, p.min_price, p.end_date
         FROM promotion p
         WHERE p.status='Active'
           AND CURDATE() BETWEEN p.start_date AND p.end_date
           AND NOT EXISTS (
               SELECT 1 FROM promotion_usage pu
               WHERE pu.user_id=? AND pu.promotion_id=p.promotion_id
           )
         ORDER BY p.discount_value DESC, p.promotion_id ASC"
    );
    $promo_stmt->bind_param('i', $_SESSION['user_id']);
    $promo_stmt->execute();
    $available_promotions = $promo_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $promo_stmt->close();
}

// ── Rating stats จาก review table ────────────────────────────────────────────
$rs = $conn->prepare("
    SELECT COUNT(*) AS total,
           ROUND(AVG(rating), 1) AS avg_rating,
           SUM(CASE WHEN rating >= 4.5 THEN 1 ELSE 0 END) AS star5,
           SUM(CASE WHEN rating >= 3.5 AND rating < 4.5 THEN 1 ELSE 0 END) AS star4,
           SUM(CASE WHEN rating >= 2.5 AND rating < 3.5 THEN 1 ELSE 0 END) AS star3,
           SUM(CASE WHEN rating >= 1.5 AND rating < 2.5 THEN 1 ELSE 0 END) AS star2,
           SUM(CASE WHEN rating < 1.5  THEN 1 ELSE 0 END) AS star1
    FROM review WHERE activity_id = ?
");
$rs->bind_param("i", $id);
$rs->execute();
$stats = $rs->get_result()->fetch_assoc();
$rs->close();

$total_reviews = (int)$stats['total'];
$avg_rating    = $total_reviews > 0 ? (float)$stats['avg_rating'] : 0;

// ── Tags ──────────────────────────────────────────────────────────────────────
$ts = $conn->prepare("
    SELECT t.tag_name, t.tag_name_en
    FROM activity_tag at2
    JOIN tag t ON at2.tag_id = t.tag_id
    WHERE at2.activity_id = ?
");
$ts->bind_param("i", $id);
$ts->execute();
$tags = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
$ts->close();

// ── Preview 2 reviews ─────────────────────────────────────────────────────────
$ps = $conn->prepare("
    SELECT r.rating, r.comment, r.created_at, u.fullname
    FROM review r
    JOIN user u ON r.user_id = u.user_id
    WHERE r.activity_id = ?
    ORDER BY r.created_at DESC
    LIMIT 2
");
$ps->bind_param("i", $id);
$ps->execute();
$preview_reviews = $ps->get_result()->fetch_all(MYSQLI_ASSOC);
$ps->close();

// ── Related activities (same shop first, then random) ─────────────────────────
$rel_stmt = $conn->prepare("
    SELECT a.activity_id, a.activity_name, a.description,
           a.adult_price, a.kid_price, a.duration_label,
           a.activity_pic,
           s.shop_name, s.shop_picture
    FROM activity a
    JOIN shop s ON a.shop_id = s.shop_id
    WHERE a.status = 'Active' AND a.activity_id != ?
    ORDER BY (a.shop_id = ?) DESC, RAND()
    LIMIT 3
");
$rel_stmt->bind_param("ii", $id, $activity['shop_id']);
$rel_stmt->execute();
$related = $rel_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rel_stmt->close();

// ── Wishlist check (ตรวจสอบว่า user บันทึกไว้แล้วหรือยัง) ───────────────────
$is_wishlisted = false;
if (isset($_SESSION['user_id'])) {
    $wq = $conn->prepare("SELECT wishlist_id FROM wishlist WHERE user_id = ? AND activity_id = ?");
    $wq->bind_param("ii", $_SESSION['user_id'], $id);
    $wq->execute();
    $is_wishlisted = (bool)$wq->get_result()->fetch_assoc();
    $wq->close();
}

// ── Map embed ─────────────────────────────────────────────────────────────────
$lat = (float)$activity['latitude'];
$lng = (float)$activity['longtitude'];
if ($lat != 0 && $lng != 0) {
    $map_src = "https://maps.google.com/maps?q={$lat},{$lng}&z=15&output=embed";
} else {
    $q = urlencode($activity['shop_name'] . ' ' . $activity['location']);
    $map_src = "https://maps.google.com/maps?q={$q}&output=embed";
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function stars(float $r): string {
    $f = (int)round($r);
    return str_repeat('★', $f) . str_repeat('☆', 5 - $f);
}
function barPct(int $n, int $total): int {
    return $total > 0 ? (int)round($n / $total * 100) : 0;
}
function avi(string $name): string {
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}

function parseRecurringFromNote($note) {
    if (!$note || !is_string($note)) return null;
    if (!preg_match('/จัดซ้ำ\s*[:：]\s*([^\]]+)/iu', $note, $m)) return null;
    $content = trim($m[1]);
    if (!preg_match('/^([^0-9]+)\s+([0-9]{1,2}:[0-9]{2})\s*-\s*([0-9]{1,2}:[0-9]{2})/u', $content, $p)) {
        return null;
    }

    $dayPart = trim($p[1]);
    $startTime = $p[2];
    $endTime = $p[3];

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

    return [
        'days' => $days,
        'time_start' => $startTime,
        'time_end' => $endTime,
    ];
}

$cssPath = dirname(__DIR__) . '/assets/css/booking.css';
$cssVer  = file_exists($cssPath) ? filemtime($cssPath) : time();
$max_cap   = (int)$activity['max_capacity'];

// ตรวจสอบช่วงวันที่จาก activity_open_request (เฉพาะ status Approved)
$open_req = null;
$open_stmt = $conn->prepare(
    "SELECT requested_start_date, requested_end_date, note
      FROM activity_open_request
      WHERE new_activity_id = ? AND status = 'Approved'
      ORDER BY requested_start_date ASC
      LIMIT 1"
);
$open_stmt->bind_param('i', $id);
$open_stmt->execute();
$open_req = $open_stmt->get_result()->fetch_assoc();
$open_stmt->close();

$has_open_request = !empty($open_req);
$open_start_dt = $has_open_request ? new DateTime($open_req['requested_start_date']) : null;
$open_end_dt   = $has_open_request ? new DateTime($open_req['requested_end_date'])   : null;
$open_recurring = $has_open_request ? parseRecurringFromNote($open_req['note'] ?? '') : null;

// ── เวลาจัดกิจกรรม ──────────────────────────────────────────────────────────
$activity_time_label = '';
if ($has_open_request) {
    if ($open_recurring && !empty($open_recurring['time_start']) && !empty($open_recurring['time_end'])) {
        // ดึงจาก note (recurring) เช่น "09:00 – 13:00"
        $activity_time_label = $open_recurring['time_start'] . ' – ' . $open_recurring['time_end'] . ' น.';
    } elseif ($open_start_dt) {
        // ดึงจาก datetime ของ open request
        $t1 = $open_start_dt->format('H:i');
        $t2 = $open_end_dt   ? $open_end_dt->format('H:i') : '';
        if ($t1 !== '00:00') {
            $activity_time_label = $t1 . ($t2 && $t2 !== '00:00' ? ' – ' . $t2 : '') . ' น.';
        }
    }
}

// ── คำนวณ effective_end_dt: รวม time_end ของกิจกรรมในวันสุดท้ายด้วย ──────────
// เช่น กิจกรรมจัดถึง 2025-05-30 เวลา 17:00 → expired หลัง 17:00 ไม่ใช่หลังเที่ยงคืน
$effective_end_dt = $open_end_dt;
if ($has_open_request && $open_end_dt) {
    $time_end_str = '';
    if (!empty($open_recurring['time_end'])) {
        // กรณี recurring: ดึง time_end จาก note
        $time_end_str = $open_recurring['time_end']; // "17:00"
    } else {
        // กรณีปกติ: ดึงเวลาจาก requested_end_date ถ้ามี
        $t2 = $open_end_dt->format('H:i');
        if ($t2 !== '00:00') {
            $time_end_str = $t2;
        }
    }
    if ($time_end_str) {
        // ตั้ง effective_end_dt เป็นวันสุดท้าย + เวลาสิ้นสุดกิจกรรม
        $effective_end_dt = clone $open_end_dt;
        list($h, $m) = explode(':', $time_end_str);
        $effective_end_dt->setTime((int)$h, (int)$m, 0);
    }
}

$now_dt = new DateTime('now');
$is_open_now = $has_open_request && $now_dt >= $open_start_dt && $now_dt <= $effective_end_dt;
if ($is_open_now && !empty($open_recurring['days'])) {
    $today_w = (int)$now_dt->format('w');
    $is_open_now = in_array($today_w, $open_recurring['days']);
}

// ── Block: ถ้าช่วงจองผ่านไปแล้ว (รวมเวลาสิ้นสุดกิจกรรม) redirect กลับหน้ากิจกรรม ──
$is_expired = $has_open_request && $now_dt > $effective_end_dt;
if ($is_expired) {
    header('Location: ' . addLangParam('/tkn/activities'));
    exit;
}

// คำนวณที่นั่งรายวัน (per-date) → ใช้แสดงบน calendar + JS validation
$per_date_stmt = $conn->prepare("
    SELECT DATE(booking_date) AS bdate,
           COALESCE(SUM(CASE WHEN (status = 'Paid' OR (status IN ('Pending','PendingReview') AND (payment_deadline IS NULL OR payment_deadline >= NOW()))) THEN adult_quantity+kid_quantity ELSE 0 END),0) AS used_pax
    FROM booking
    WHERE activity_id = ?
    GROUP BY DATE(booking_date)
");
$per_date_stmt->bind_param("i", $id);
$per_date_stmt->execute();
$per_date_rows = $per_date_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$per_date_stmt->close();
$per_date_cap = []; // 'YYYY-MM-DD' => remaining seats
foreach ($per_date_rows as $r) {
    $per_date_cap[$r['bdate']] = max(0, $max_cap - (int)$r['used_pax']);
}

// รอบที่จัดวันเดียวสามารถแสดงจำนวนที่ว่างจริงได้ทันทีโดยไม่ต้องรอเลือกวัน
$single_round_date = null;
if ($has_open_request && $open_start_dt && $open_end_dt
    && $open_start_dt->format('Y-m-d') === $open_end_dt->format('Y-m-d')) {
    $single_round_date = $open_start_dt->format('Y-m-d');
}
$remaining = $single_round_date !== null
    ? (int)($per_date_cap[$single_round_date] ?? $max_cap)
    : $max_cap;
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($activity['activity_name']) ?> – จองกิจกรรม</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/tkn/assets/css/style.css">
    <link rel="stylesheet" href="/tkn/assets/css/booking.css?v=<?= $cssVer ?>">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-nav.css">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-footer.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.omise.co/omise.js"></script>
    <script src="/tkn/assets/js/booking-sheet.js"></script>
    <script>
    // ── Early declarations (ต้องพร้อมก่อน inline onclick) ──
    var adultPrice = <?= (float)$activity['adult_price'] ?>;
    var childPrice = <?= (float)$activity['kid_price'] ?>;
    var activityId = <?= $id ?>;
    var maxCapPerSession = <?= $max_cap ?>; // ที่นั่งสูงสุดต่อรอบ
    var maxCapacity = <?= $remaining ?>; // จำนวนที่ว่างของรอบวันเดียว หรือค่าสูงสุดก่อนเลือกวัน
    var perDateCapacity = <?= json_encode($per_date_cap, JSON_UNESCAPED_UNICODE) ?>; // remaining รายวัน
    var hasKid = <?= (float)$activity['kid_price'] > 0 ? 'true' : 'false' ?>;
    var qty = {
        adult: 0,
        child: 0
    };

    var THAI_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];
    var DAY_NAMES = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    var calCurrent = (function() {
        var d = new Date();
        d.setDate(1);
        return d;
    })();
    var selectedDate = null;
    var calOpen = false;

    var fmt = function(v) {
        return new Intl.NumberFormat('th-TH', {
            style: 'currency',
            currency: 'THB',
            maximumFractionDigits: 0
        }).format(v);
    };

    // ── Wishlist state (PHP → JS) ─────────────────────────────────────────────
    var wishlisted = <?= $is_wishlisted ? 'true' : 'false' ?>;
    var isGuest = <?= !isset($_SESSION['user_id']) ? 'true' : 'false' ?>;
    var openRecurringDays = <?= $open_recurring ? json_encode($open_recurring['days']) : 'null' ?>;

    var hasOpenRequest = <?= $has_open_request ? 'true' : 'false' ?>;
    var openDateStart = <?= $has_open_request ? json_encode($open_start_dt->format('Y-m-d')) : 'null' ?>;
    var openDateEnd = <?= $has_open_request ? json_encode($open_end_dt->format('Y-m-d')) : 'null' ?>;
    var isOpenNow = <?= $is_open_now ? 'true' : 'false' ?>;
    var bookingRangeConnector = <?= json_encode(currentLang() === 'en' ? 'to' : 'ถึง') ?>;
    </script>
    <style>
    /* ── Calendar Dropdown ── */
    .cal-dropdown-wrap {
        position: relative;
    }

    .cal-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        z-index: 500;
        background: #fff;
        border: 1.5px solid #d0d8cc;
        border-radius: 14px;
        padding: 14px;
        box-shadow: 0 8px 32px rgba(44, 74, 47, .18);
        min-width: 280px;
        animation: calFadeIn .15s ease;
    }

    .cal-dropdown.open {
        display: block;
    }

    @keyframes calFadeIn {
        from {
            opacity: 0;
            transform: translateY(-6px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .cal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .cal-month-year {
        font-family: 'Kanit', sans-serif;
        font-size: 14px;
        font-weight: 600;
        color: #1a1a1a;
    }

    .cal-nav {
        background: none;
        border: 1px solid #ddd;
        border-radius: 8px;
        width: 30px;
        height: 30px;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
        color: #444;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background .15s;
    }

    .cal-nav:hover {
        background: #f0f4ee;
    }

    .cal-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 3px;
    }

    .cal-day-name {
        font-family: 'Kanit', sans-serif;
        font-size: 11px;
        font-weight: 600;
        color: #888;
        text-align: center;
        padding: 4px 0;
    }

    .cal-day {
        font-family: 'Kanit', sans-serif;
        font-size: 13px;
        text-align: center;
        padding: 7px 4px;
        border-radius: 8px;
        cursor: pointer;
        color: #1a1a1a;
        transition: background .15s, color .15s;
        user-select: none;
    }

    .cal-day:hover:not(.past):not(.empty) {
        background: #e8f0e4;
    }

    .cal-day.today {
        font-weight: 700;
        color: #2C4219;
    }

    .cal-day.selected {
        background: #2C4219;
        color: #fff;
        border-radius: 8px;
    }

    .cal-day.selected:hover {
        background: #3d5c2a;
    }

    .cal-day.past,
    .cal-day.disabled {
        color: #ccc;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .cal-day.empty {
        visibility: hidden;
    }

    /* ── Wishlist Button ── */
    .wishlist-btn {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: #fff;
        color: #888;
        border: 1.5px solid #ddd;
        border-radius: 6px;
        padding: 11px;
        font-size: 0.92rem;
        font-weight: 600;
        cursor: pointer;
        margin-top: 10px;
        transition: all 0.2s;
        font-family: 'Kanit', sans-serif;
    }

    .wishlist-btn:hover {
        border-color: #e53935;
        color: #e53935;
    }

    .wishlist-btn.wishlisted {
        border-color: #e53935;
        color: #e53935;
        background: #fff5f5;
    }

    .wishlist-btn i {
        font-size: 1rem;
        transition: transform .15s;
    }

    .wishlist-btn:hover i {
        transform: scale(1.2);
    }

    /* ── Wishlist Inline (title-row) ── */
    .action-btn.wishlist-inline {
        position: static;
        flex: 0 0 auto;
        align-self: flex-start;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 5px 2px 5px 12px;
        border: none;
        background: transparent;
        box-shadow: none;
        color: #466178;
        font-family: 'Kanit', sans-serif;
        font-size: 0.92rem;
        font-weight: 400;
        white-space: nowrap;
        cursor: pointer;
        transition: color 0.18s, opacity 0.18s;
    }

    .action-btn.wishlist-inline span {
        display: inline;
    }

    .action-btn.wishlist-inline:hover,
    .action-btn.wishlist-inline.wishlisted {
        color: #d96b3b;
    }

    .action-btn.wishlist-inline i {
        width: 28px;
        font-size: 1.65rem;
        line-height: 1;
        text-align: center;
        transition: transform .18s;
    }

    .action-btn.wishlist-inline:disabled {
        cursor: wait;
        opacity: 0.55;
    }

    @media (max-width: 600px) {
        .title-row {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
            padding-right: 0;
        }

        .action-btn.wishlist-inline {
            align-self: center;
            padding: 2px 0 2px 6px;
        }

        .action-btn.wishlist-inline span {
            font-size: 0.82rem;
        }

        .action-btn.wishlist-inline i {
            width: 24px;
            font-size: 1.45rem;
        }
    }

    @media (max-width: 420px) {
        .title-row {
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
        }

        .action-btn.wishlist-inline {
            margin-left: 0;
        }

        .action-btn.wishlist-inline span {
            display: none;
        }

        .action-btn.wishlist-inline i {
            width: 28px;
            font-size: 1.55rem;
        }
    }

    /* ── Modal Step ── */
    .modal-step-num {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #e0e0e0;
        color: #888;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Kanit', sans-serif;
        font-weight: 700;
        font-size: 16px;
        margin: 0 auto;
        transition: background .3s, color .3s;
    }

    .modal-step-num.active {
        background: #2C4219;
        color: #fff;
    }

    .modal-step-num.done {
        background: #4CAF50;
        color: #fff;
    }

    .pay-method-row:hover,
    .bank-row:hover {
        background: #f5f5f5;
    }

    .pay-method-row.selected,
    .bank-row.selected {
        background: #f0f7f0;
    }

    @keyframes spinPay {
        to {
            transform: rotate(360deg);
        }
    }

    /* ── Mobile: Booking Modal Responsive ── */

    /* Mobile close button — hidden on desktop */
    .modal-mobile-close {
        display: none;
    }

    /* Default (desktop): modal แสดงกลางจอ */
    #bookingModal.modal-open {
        align-items: center !important;
        justify-content: center !important;
        padding: 20px !important;
    }

    @media (max-width: 768px) {

        /* Mobile: modal slide ขึ้นจากล่าง — padding-top สร้างพื้นที่มืดด้านบนให้แตะปิดได้ */
        #bookingModal.modal-open {
            display: flex !important;
            align-items: flex-end !important;
            justify-content: center !important;
            padding: 60px 0 0 0 !important;
        }

        #bookingModal>div {
            border-radius: 20px 20px 0 0 !important;
            max-width: 100% !important;
            max-height: 95vh !important;
            width: 100% !important;
        }

        #bookingModal>div>div:first-child {
            padding: 20px 16px 0 !important;
        }

        /* Mobile close button — hidden on desktop */
        .modal-mobile-close {
            display: none;
        }

        @media (max-width: 768px) {
            .modal-mobile-close {
                display: flex !important;
                align-items: center;
                justify-content: center;
                position: sticky;
                top: 10px;
                float: right;
                margin: 10px 10px -42px 0;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: #f0f0f0;
                border: none;
                color: #555;
                font-size: 14px;
                cursor: pointer;
                z-index: 10;
                transition: background 0.15s;
                flex-shrink: 0;
            }

            .modal-mobile-close:hover {
                background: #e0e0e0;
            }
        }

        .modal-step-num {
            width: 30px !important;
            height: 30px !important;
            font-size: 13px !important;
        }

        .modal-step-label {
            font-size: 10px !important;
            font-weight: 500 !important;
            margin-top: 5px !important;
            color: #666 !important;
        }

        #bookingModal [id^="stepLine"] {
            margin-top: 15px !important;
        }

        #stepProgressBar {
            margin-top: 12px !important;
        }

        #modalStep1 {
            padding: 16px 16px 24px !important;
        }

        #step1Grid {
            grid-template-columns: 1fr !important;
            gap: 16px !important;
        }

        #modalStep2 {
            padding: 16px 16px 24px !important;
        }

        #modalStep3 {
            padding: 16px 16px 24px !important;
        }

        #step3Grid {
            grid-template-columns: 1fr !important;
            gap: 16px !important;
        }

        #modalStep4 {
            padding: 16px 16px 24px !important;
        }

        .pay-method-row {
            padding: 12px 14px !important;
        }

        #promotionSelect {
            font-size: 13px !important;
            padding: 10px 12px !important;
        }
    }
    </style>
</head>

<body>
    <div id="wrapper">

        <!-- Header -->
        <header class="header">
            <div class="container">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <nav class="nav">
                    <a href="/tkn/home" class="nav-link"><?= $t['nav_home'] ?></a>
                    <a href="/tkn/activities" class="nav-link"><?= $t['nav_trips'] ?></a>
                    <a href="/tkn/contact" class="nav-link"><?= $t['nav_contact'] ?></a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="nav-user-wrapper">
                        <button class="nav-user-btn" id="navUserBtn">
                            <div class="nav-user-avatar"><i class="fas fa-user"></i></div>
                            <span><?= htmlspecialchars($_SESSION['username']) ?></span>
                            <svg class="nav-dropdown-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </button>
                        <div class="nav-user-dropdown" id="navUserDropdown">
                            <a href="/tkn/profile" class="nav-dropdown-item">
                                <i class="fas fa-user"></i> โปรไฟล์
                            </a>
                            <a href="/tkn/profile?tab=passport" class="nav-dropdown-item">
                                <i class="fas fa-passport"></i> <?= $t['nav_passport'] ?>
                            </a>
                            <a href="/tkn/logout" class="nav-dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <a href="/tkn/login" class="nav-link"><?= $t['nav_login'] ?></a>
                    <?php endif; ?>
                    <!-- ปุ่มเปลี่ยนภาษา -->
                    <a href="<?= $t['lang_switch_href'] ?>" class="nav-link lang-switch-btn">
                        <i class="fas fa-globe"></i> <?= $t['lang_switch_label'] ?>
                    </a>
                </nav>
                <div class="mobile-menu-toggle">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
        </header>

        <!-- Hero Image -->
        <?php
        $hero_pic = '';
        if (!empty($activity['activity_pic'])) {
            $raw = $activity['activity_pic'];
            if (preg_match('#^https?://#', $raw)) {
                // URL เต็ม — ใช้ตรงๆ
                $hero_pic = $raw;
            } elseif (preg_match('#^uploads/activity_pics/#', $raw)) {
                // รูปที่ upload ผ่านระบบใหม่ → อยู่ใน handlers/uploads/
                $hero_pic = '/tkn/handlers/' . ltrim($raw, '/');
            } elseif (preg_match('#^(?:TKN/)?uploads/#', $raw)) {
                $hero_pic = '/tkn/assets/' . preg_replace('#^(?:TKN/)#', '', ltrim($raw, '/'));
            } else {
                $hero_pic = $raw;
            }
        }
        ?>
        <?php if ($hero_pic): ?>
        <div class="hero-image"
            style="background-image:url('<?= htmlspecialchars($hero_pic) ?>');background-size:cover;background-position:center;">
        </div>
        <?php else: ?>
        <div class="hero-image hero-image-empty">
            <svg viewBox="0 0 80 80" width="80" height="80" fill="none">
                <rect width="80" height="80" rx="16" fill="#d4e4c4" opacity="0.5" />
                <path d="M20 56 L20 24 Q20 20 24 20 L56 20 Q60 20 60 24 L60 56 Q60 60 56 60 L24 60 Q20 60 20 56Z"
                    fill="#b8ccaa" opacity="0.6" />
                <circle cx="32" cy="34" r="5" fill="#8aad7a" opacity="0.7" />
                <path d="M20 50 L32 38 L42 48 L50 40 L60 50" stroke="#6a9a5a" stroke-width="2.5" fill="none"
                    stroke-linecap="round" stroke-linejoin="round" opacity="0.7" />
            </svg>
            <p style="margin:10px 0 0;color:#8aad7a;font-family:'Kanit',sans-serif;font-size:0.9rem;">ยังไม่มีรูปกิจกรรม
            </p>
        </div>
        <?php endif; ?>

        <div class="main-container">

            <!-- ═══ LEFT CONTENT ══════════════════════════════════════════════════ -->
            <div class="content">

                <!-- Title + Wishlist inline -->
                <div class="title-row">
                    <h1><?= htmlspecialchars($activity['activity_name']) ?></h1>
                    <button class="action-btn wishlist-inline <?= $is_wishlisted ? 'wishlisted' : '' ?>"
                        id="wishlistBtnInline" onclick="toggleWishlist()">
                        <i class="<?= $is_wishlisted ? 'fas' : 'far' ?> fa-heart"></i>
                        <span><?= $is_wishlisted ? 'Wishlisted' : 'Wishlist' ?></span>
                    </button>
                </div>

                <!-- Rating -->
                <div class="rating">
                    <span class="stars"><?= $total_reviews > 0 ? stars($avg_rating) : '☆☆☆☆☆' ?></span>
                    <span>
                        <?php if ($total_reviews > 0): ?>
                        <?= $avg_rating ?> (<?= $total_reviews ?> รีวิว)
                        <?php else: ?>ยังไม่มีรีวิว<?php endif; ?>
                    </span>
                </div>

                <!-- Host / Shop -->
                <div class="host">
                    <div class="host-avatar" style="overflow:hidden;padding:0;">
                        <?php if ($shop_picture_url): ?>
                        <img src="<?= htmlspecialchars($shop_picture_url) ?>"
                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                            alt="<?= htmlspecialchars($activity['shop_name']) ?>"
                            style="width:40px;height:40px;object-fit:cover;border-radius:50%;display:block;">
                        <span style="display:none;width:40px;height:40px;align-items:center;justify-content:center;">
                            <i class="fas fa-store" style="font-size:1rem;color:#aaa;"></i>
                        </span>
                        <?php else: ?><i class="fas fa-store" style="font-size:1rem;color:#aaa;"></i><?php endif; ?>
                    </div>
                    <div>
                        <strong
                            style="font-size:0.95rem;color:#1a1a1a;"><?= htmlspecialchars($activity['shop_name']) ?></strong>
                        <span
                            style="margin-left:8px;font-size:0.75rem;color:#777;background:#f4f4f4;padding:2px 8px;border-radius:6px;">
                            <?= htmlspecialchars($activity['category_name']) ?>
                        </span><br>
                        <small style="color:#888;font-size:0.8rem;">
                            <i class="fas fa-map-marker-alt" style="color:#2b4218;"></i>
                            <?= htmlspecialchars($activity['district'] . ', ' . $activity['province']) ?>
                            <?php if ($activity['shop_phonenumber']): ?>
                            &nbsp;·&nbsp;<i class="fas fa-phone" style="color:#888;"></i>
                            <?= htmlspecialchars($activity['shop_phonenumber']) ?>
                            <?php endif; ?>
                        </small>
                        <?php if ($activity['shop_website'] || $activity['shop_socailmedia']): ?>
                        <div style="margin-top:4px;font-size:0.8rem;display:flex;gap:10px;">
                            <?php if ($activity['shop_website']): ?>
                            <a href="<?= htmlspecialchars($activity['shop_website']) ?>" target="_blank"
                                style="color:#2b4218;text-decoration:none;"><i class="fas fa-globe"></i> เว็บไซต์</a>
                            <?php endif; ?>
                            <?php if ($activity['shop_socailmedia']): ?>
                            <a href="<?= htmlspecialchars($activity['shop_socailmedia']) ?>" target="_blank"
                                style="color:#2b4218;text-decoration:none;"><i class="fab fa-facebook"></i> Facebook</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Badges -->
                <?php
                $cap_pct   = $max_cap > 0 ? ($remaining / $max_cap * 100) : 100;
                $cap_color = $cap_pct <= 20 ? '#c62828' : ($cap_pct <= 50 ? '#e65100' : '#2b4218');
                ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:22px;">
                    <?php if ($activity_time_label): ?>
                    <span
                        style="background:#e8f5e9;color:#1b5e20;border-radius:6px;padding:4px 11px;font-size:0.78rem;font-weight:600;border:1px solid #c8e6c9;">
                        <i class="fas fa-clock" style="color:#2b7a30;"></i>
                        <?= htmlspecialchars($activity_time_label) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($activity['duration_label']): ?>
                    <span
                        style="background:#f4f4f4;color:#444;border-radius:6px;padding:4px 11px;font-size:0.78rem;font-weight:500;">
                        <i class="fas fa-hourglass-half" style="color:#2b4218;"></i>
                        <?= htmlspecialchars($activity['duration_label']) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($activity['suitable_for']): ?>
                    <span
                        style="background:#f4f4f4;color:#444;border-radius:6px;padding:4px 11px;font-size:0.78rem;font-weight:500;">
                        <i class="fas fa-users" style="color:#2b4218;"></i>
                        <?= htmlspecialchars($activity['suitable_for']) ?>
                    </span>
                    <?php endif; ?>
                    <span id="seatBadge"
                        style="background:#f4f4f4;color:#2b4218;border-radius:6px;padding:4px 11px;font-size:0.78rem;font-weight:500;">
                        <i class="fas fa-ticket-alt"></i>
                        <span id="seatBadgeText"><?= $remaining ?>/<?= $max_cap ?> ที่ว่าง</span>
                    </span>
                    <?php foreach ($tags as $tag): ?>
                    <span style="background:#f4f4f4;color:#555;border-radius:6px;padding:4px 11px;font-size:0.78rem;">
                        #<?= htmlspecialchars($tag['tag_name_en'] ?: $tag['tag_name']) ?>
                    </span>
                    <?php endforeach; ?>
                </div>

                <!-- Overview -->
                <h2>Overview</h2>
                <p class="overview"><?= nl2br(htmlspecialchars($activity['description'])) ?></p>

                <!-- Location -->
                <h2><?= currentLang() === 'en' ? 'Location' : 'สถานที่' ?></h2>
                <div class="location-section">
                    <p style="color:#666;font-size:0.9rem;margin-bottom:12px;">
                        <i class="fas fa-map-marker-alt" style="color:#2d6a4f;"></i>
                        <?= htmlspecialchars($activity['location']) ?>
                    </p>
                    <div class="location-map">
                        <iframe src="<?= htmlspecialchars($map_src) ?>" style="border:0;width:100%;height:360px;"
                            allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>

                <!-- Reviews -->
                <h2>รีวิว <?= $total_reviews > 0 ? "($total_reviews)" : '' ?></h2>
                <div class="review-header">
                    <div class="review-rating">
                        <span class="stars"><?= $total_reviews > 0 ? stars($avg_rating) : '☆☆☆☆☆' ?></span>
                        <span><?= $total_reviews > 0 ? $avg_rating : '–' ?></span>
                    </div>
                    <?php if ($total_reviews > 0): ?>
                    <button onclick="openAllReviews()"
                        style="background:none;color:#2b4218;border:1.5px solid #2b4218;padding:7px 18px;border-radius:6px;font-size:0.85rem;font-weight:600;cursor:pointer;font-family:'Kanit',sans-serif;transition:background 0.15s,color 0.15s;"
                        onmouseover="this.style.background='#2b4218';this.style.color='#fff8cb'"
                        onmouseout="this.style.background='none';this.style.color='#2b4218'">
                        ดูรีวิวทั้งหมด
                    </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($preview_reviews)): ?>
                <p style="color:#aaa;padding:20px 0 30px;">ยังไม่มีรีวิวสำหรับกิจกรรมนี้</p>
                <?php else: ?>
                <?php foreach ($preview_reviews as $rev): ?>
                <div class="review-card">
                    <div class="review-header-card">
                        <div class="reviewer-avatar">
                            <?= avi($rev['fullname']) ?>
                        </div>
                        <div class="reviewer-info">
                            <h4>
                                <?= htmlspecialchars($rev['fullname']) ?>
                                <span
                                    style="color:#ffa500;font-weight:normal;margin-left:6px;"><?= stars((float)$rev['rating']) ?></span>
                            </h4>
                            <div class="review-date"><?= date('d/m/Y', strtotime($rev['created_at'])) ?></div>
                        </div>
                    </div>
                    <p style="font-size:13px;color:#666;line-height:1.6;margin:0;">
                        <?= htmlspecialchars($rev['comment']) ?></p>
                </div>
                <?php endforeach; ?>
                <button class="load-more" onclick="openAllReviews()">ดูรีวิวทั้งหมด</button>
                <?php endif; ?>

                <!-- All Reviews Modal -->
                <div id="allReviewsModal"
                    style="display:none;position:fixed;inset:0;z-index:9999;background:#fff;overflow-y:auto;">
                    <div style="max-width:900px;margin:0 auto;padding:32px 20px 60px;">
                        <div
                            style="display:flex;align-items:center;justify-content:space-between;margin-bottom:32px;border-bottom:2px solid #f0f0f0;padding-bottom:20px;">
                            <div>
                                <h2 style="margin:0;font-size:1.8rem;font-weight:700;color:#1a1a1a;">รีวิวทั้งหมด</h2>
                                <p style="margin:6px 0 0;color:#666;">
                                    <?= htmlspecialchars($activity['activity_name']) ?></p>
                            </div>
                            <button onclick="closeAllReviews()"
                                style="background:none;border:2px solid #ddd;border-radius:50%;width:44px;height:44px;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#333;"
                                onmouseover="this.style.background='#f5f5f5'"
                                onmouseout="this.style.background='none'">✕</button>
                        </div>
                        <?php if ($total_reviews > 0): ?>
                        <div
                            style="display:flex;gap:24px;align-items:center;background:#fafafa;border-radius:16px;padding:24px;margin-bottom:32px;flex-wrap:wrap;">
                            <div style="text-align:center;min-width:80px;">
                                <div style="font-size:3rem;font-weight:800;color:#1a1a1a;line-height:1;">
                                    <?= $avg_rating ?></div>
                                <div style="color:#f5a623;font-size:1.2rem;"><?= stars($avg_rating) ?></div>
                                <div style="color:#888;font-size:0.85rem;margin-top:4px;"><?= $total_reviews ?> รีวิว
                                </div>
                            </div>
                            <div style="flex:1;min-width:200px;">
                                <?php foreach ([5=>'star5',4=>'star4',3=>'star3',2=>'star2',1=>'star1'] as $s => $key):
                                $cnt = (int)$stats[$key];
                                $w   = barPct($cnt, $total_reviews);
                            ?>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                    <span
                                        style="width:20px;text-align:right;font-size:0.85rem;color:#555;"><?= $s ?></span>
                                    <span style="color:#f5a623;font-size:0.85rem;">★</span>
                                    <div
                                        style="flex:1;background:#e0e0e0;border-radius:4px;height:8px;overflow:hidden;">
                                        <div style="width:<?= $w ?>%;background:#f5a623;height:100%;border-radius:4px;">
                                        </div>
                                    </div>
                                    <span style="font-size:0.8rem;color:#888;width:28px;"><?= $cnt ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;">
                            <?php
                        $rv = $conn->prepare("SELECT r.rating, r.comment, r.created_at, u.fullname FROM review r JOIN user u ON r.user_id = u.user_id WHERE r.activity_id = ? ORDER BY r.created_at DESC");
                        $rv->bind_param("i", $id);
                        $rv->execute();
                        $all_revs = $rv->get_result();
                        $rv->close();
                        if ($all_revs->num_rows > 0):
                            while ($rev = $all_revs->fetch_assoc()):
                        ?>
                            <div style="background:#fff;border:1px solid #eee;border-radius:16px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);transition:transform 0.2s;"
                                onmouseover="this.style.transform='translateY(-2px)'"
                                onmouseout="this.style.transform='none'">
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                                    <div
                                        style="width:40px;height:40px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1rem;">
                                        <?= avi($rev['fullname']) ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:600;font-size:0.95rem;color:#1a1a1a;">
                                            <?= htmlspecialchars($rev['fullname']) ?></div>
                                        <div style="font-size:0.75rem;color:#999;">
                                            <?= date('d/m/Y', strtotime($rev['created_at'])) ?></div>
                                    </div>
                                </div>
                                <div style="color:#f5a623;font-size:1rem;margin-bottom:10px;">
                                    <?= stars((float)$rev['rating']) ?>
                                    <span
                                        style="color:#555;font-size:0.85rem;margin-left:4px;"><?= $rev['rating'] ?>/5</span>
                                </div>
                                <p style="font-size:0.9rem;color:#444;line-height:1.6;margin:0;">
                                    <?= htmlspecialchars($rev['comment']) ?></p>
                            </div>
                            <?php endwhile;
                        else: ?>
                            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:#999;">
                                <div style="font-size:3rem;margin-bottom:12px;">📝</div>
                                <p>ยังไม่มีรีวิวสำหรับกิจกรรมนี้</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <script>
                function openAllReviews() {
                    document.getElementById('allReviewsModal').style.display = 'block';
                    document.body.classList.add('modal-open');
                    document.getElementById('allReviewsModal').scrollTop = 0;
                }

                function closeAllReviews() {
                    document.getElementById('allReviewsModal').style.display = 'none';
                    document.body.classList.remove('modal-open');
                }
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') closeAllReviews();
                });
                </script>

            </div><!-- /content -->

            <!-- ═══ RIGHT SIDEBAR ════════════════════════════════════════════════ -->
            <div class="sidebar">
                <div class="booking-card sheet-collapsed" id="bookingCard">

                    <!-- ── Mobile: Collapsed bar (Book Now CTA) ── -->
                    <div class="booking-sheet-bar" id="sheetBar" onclick="openBookingSheet()">
                        <div>
                            <div class="sheet-price">
                                ฿<?= number_format((float)$activity['adult_price'], 0) ?>
                                <small>/ คน</small>
                            </div>
                            <div id="sheetBarTotal" style="display:none;font-size:12px;color:#555;margin-top:2px;">
                                รวม: <span id="sheetBarTotalVal">฿0</span>
                            </div>
                        </div>
                        <button class="sheet-open-btn" type="button">จองเลย!</button>
                    </div>

                    <!-- ── Form content (hidden when collapsed on mobile) ── -->
                    <div class="booking-sheet-form">

                        <!-- Mobile close row -->
                        <div class="sheet-close-row">
                            <span
                                style="font-family:'Kanit',sans-serif;font-size:15px;font-weight:700;color:#1a1a1a;">รายละเอียดการจอง</span>
                            <button type="button" class="sheet-close-btn" onclick="closeBookingSheet()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <!-- Date picker -->
                        <div class="date-selector">
                            <label>วันเดินทาง
                                <?php if ($activity_time_label): ?>
                                <span
                                    style="float:right;font-size:0.75rem;font-weight:600;color:#2b7a30;background:#e8f5e9;border-radius:5px;padding:2px 8px;border:1px solid #c8e6c9;">
                                    <i class="fas fa-clock"></i> <?= htmlspecialchars($activity_time_label) ?>
                                </span>
                                <?php endif; ?>
                            </label>
                            <div class="cal-dropdown-wrap" id="calWrap">
                                <div class="date-input-trigger" id="calTrigger">
                                    <span id="triggerText">เลือกวันที่</span>
                                    <span class="cal-icon"><i class="fas fa-calendar-alt"></i></span>
                                </div>
                                <div class="cal-dropdown" id="calDropdown">
                                    <div class="cal-header">
                                        <button class="cal-nav" id="calPrev">&#8249;</button>
                                        <span class="cal-month-year" id="calMonthYear"></span>
                                        <button class="cal-nav" id="calNext">&#8250;</button>
                                    </div>
                                    <div class="cal-grid" id="calGrid"></div>
                                </div>
                            </div>
                            <input type="hidden" id="selectedDateInput" name="travel_date" value="">
                        </div>

                        <!-- ผู้ใหญ่ -->
                        <div class="quantity-selector">
                            <label>ผู้ใหญ่
                                <small
                                    style="color:#888;font-weight:normal;">(฿<?= number_format((float)$activity['adult_price'], 0) ?>)</small>
                            </label>
                            <div class="quantity-controls">
                                <button type="button" class="quantity-btn" onclick="updateQty('adult',-1)">-</button>
                                <span class="quantity-value" id="adult-qty">0</span>
                                <button type="button" class="quantity-btn" onclick="updateQty('adult',1)">+</button>
                            </div>
                        </div>

                        <!-- เด็ก -->
                        <?php if ((float)$activity['kid_price'] > 0): ?>
                        <div class="quantity-selector">
                            <label>เด็ก
                                <small
                                    style="color:#888;font-weight:normal;">(฿<?= number_format((float)$activity['kid_price'], 0) ?>)</small>
                            </label>
                            <div class="quantity-controls">
                                <button type="button" class="quantity-btn" onclick="updateQty('child',-1)">-</button>
                                <span class="quantity-value" id="child-qty">0</span>
                                <button type="button" class="quantity-btn" onclick="updateQty('child',1)">+</button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Capacity alert -->
                        <p id="seatWarningMsg" style="display:none;font-size:0.82rem;margin:0 0 10px;font-weight:600;">
                        </p>

                        <p id="bookingStateMessage"
                            style="color:#2563eb;font-size:0.82rem;margin:0 0 10px;font-weight:600;">
                            <?php if (!$has_open_request): ?>
                            ⚠ กิจกรรมนี้ยังไม่เปิดรับจอง
                            <?php elseif (!$is_open_now): ?>
                            <?php if ($now_dt < $open_start_dt): ?>
                            ⚠ จะเปิดจองวันที่ <?= $open_start_dt->format('d/m/Y') ?>
                            <?= currentLang() === 'en' ? 'to' : 'ถึง' ?>
                            <?= $open_end_dt->format('d/m/Y') ?>
                            <?php else: ?>
                            ⚠ ช่วงเวลาจองผ่านไปแล้ว (<?= $open_start_dt->format('d/m/Y') ?> -
                            <?= $open_end_dt->format('d/m/Y') ?>)
                            <?php endif; ?>
                            <?php endif; ?>
                        </p>

                        <div class="price-row">
                            <span>ราคาต่อคน</span>
                            <span id="price-display">–</span>
                        </div>
                        <div class="price-row total">
                            <span>รวม</span>
                            <span id="total-price">฿0</span>
                        </div>

                        <button class="book-now-btn" id="bookNowBtn" onclick="submitBooking()">จองตอนนี้</button>

                    </div><!-- /booking-sheet-form -->

                </div><!-- /booking-card -->
            </div><!-- /sidebar -->

            <!-- Mobile sheet backdrop -->
            <div class="sheet-backdrop" id="sheetBackdrop" onclick="closeBookingSheet()"></div>

        </div><!-- /main-container -->

        <!-- Related Activities -->
        <div class="other-activities">
            <h2>กิจกรรมที่ใกล้เคียง</h2>
            <div class="related-activities">
                <?php foreach ($related as $r): ?>
                <?php
                    $rel_img_raw = !empty($r['activity_pic']) ? $r['activity_pic']
                                 : (!empty($r['shop_picture']) ? $r['shop_picture'] : '');
                    $rel_img = resolvePic((string)$rel_img_raw);
                ?>
                <div class="activity-card"
                    onclick="window.location.href='<?= p('booking', ['id' => $r['activity_id']]) ?>'"
                    style="cursor:pointer;">
                    <div class="activity-image<?= $rel_img ? ' has-img' : '' ?>"
                        <?= $rel_img ? "style=\"background-image:url('" . htmlspecialchars($rel_img) . "');\"" : '' ?>>
                        <?php if (!$rel_img): ?>
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="rgba(255,255,255,0.5)">
                            <path
                                d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                        </svg>
                        <?php endif; ?>
                    </div>
                    <div class="activity-info">
                        <h3><?= htmlspecialchars($r['activity_name']) ?></h3>
                        <p><?= htmlspecialchars(mb_substr($r['description'], 0, 80)) ?>...</p>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                            <small
                                style="color:#4a7c59;font-weight:600;">฿<?= number_format((float)$r['adult_price'], 0) ?>
                                / คน</small>
                            <?php if ($r['duration_label']): ?>
                            <small style="color:#999;font-size:0.75rem;"><i class="fas fa-clock"></i>
                                <?= htmlspecialchars($r['duration_label']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="footer booking-responsive-footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-left">
                        <div class="footer-logo-wrap">
                            <img src="/tkn/assets/image/logo.png" alt="เที่ยวกันนา" class="footer-logo-img">
                        </div>
                        <p class="footer-tagline">แพลตฟอร์มท่องเที่ยวเชิงเกษตรและภูมิปัญญาชาวบ้าน<br>จังหวัดชลบุรี</p>
                        <p style="font-size:0.78rem;color:#8ab49a;line-height:1.6;margin:8px 0;">
                            <i class="fas fa-map-marker-alt"></i>
                            <span style="margin-left:1px;">มหาวิทยาลัยศิลปากร วิทยาเขตสารสนเทศเพชรบุรี</span>
                        </p>
                        <div class="footer-social-icons">
                            <a href="https://facebook.com/teawkanna" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="Line"><i class="fab fa-line"></i></a>
                            <a href="https://tiktok.com/@teawkanna" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="TikTok"><i class="fab fa-tiktok"></i></a>
                            <a href="https://instagram.com/teawkanna" target="_blank" rel="noopener"
                                class="footer-icon-btn" title="Instagram"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                    <div class="footer-menu">
                        <h4 class="footer-heading"><?= $t['footer_menu'] ?? 'เมนู' ?></h4>
                        <ul>
                            <li><a href="/tkn/home"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i><?= $t['nav_home'] ?></a></li>
                            <li><a href="/tkn/activities"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i><?= $t['nav_trips'] ?></a></li>
                            <li><a href="/tkn/contact"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i><?= $t['nav_contact'] ?></a>
                            </li>
                        </ul>
                    </div>
                    <div class="footer-social">
                        <h4 class="footer-heading"><?= $t['footer_contact'] ?? 'ช่องทางการติดต่อ' ?></h4>
                        <ul>
                            <li><a href="https://facebook.com/teawkanna" target="_blank" rel="noopener"><i
                                        class="fab fa-facebook footer-contact-icon"></i>facebook.com/teawkanna</a></li>
                            <li><a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener"><i
                                        class="fab fa-line footer-contact-icon"></i>@979jehsw</a></li>
                            <li><a href="https://tiktok.com/@teawkanna" target="_blank" rel="noopener"><i
                                        class="fab fa-tiktok footer-contact-icon"></i>@teawkanna</a></li>
                            <li><a href="mailto:teawkanna@gmail.com"><i
                                        class="fas fa-envelope footer-contact-icon"></i>teawkanna@gmail.com</a></li>
                            <li><a href="tel:+66899999999"><i
                                        class="fas fa-phone footer-contact-icon"></i>089-999-9999</a></li>
                        </ul>
                    </div>
                </div>
                <div class="footer-bottom">
                    <p>© 2026 เที่ยวกันนา. สงวนลิขสิทธิ์ | <a href="/tkn/contact"
                            style="color:#8ab49a;">นโยบายความเป็นส่วนตัว</a></p>
                </div>
            </div>
        </footer>
    </div><!-- /wrapper -->

    <div class="booking-alert-overlay" id="bookingAlertPopup" role="dialog" aria-modal="true"
        aria-labelledby="bookingAlertTitle" aria-describedby="bookingAlertMessage">
        <div class="booking-alert-card">
            <button type="button" class="booking-alert-close" onclick="closeBookingPopup()" aria-label="ปิดหน้าต่าง">
                <i class="fas fa-times"></i>
            </button>
            <div class="booking-alert-icon">
                <i class="fas fa-exclamation"></i>
            </div>
            <h3 id="bookingAlertTitle">กรุณาตรวจสอบข้อมูล</h3>
            <p id="bookingAlertMessage"></p>
            <button type="button" class="booking-alert-confirm" onclick="closeBookingPopup()">ตกลง</button>
        </div>
    </div>

    <script>
    function showBookingPopup(message, title, type) {
        var popup = document.getElementById('bookingAlertPopup');
        var titleEl = document.getElementById('bookingAlertTitle');
        var messageEl = document.getElementById('bookingAlertMessage');
        var icon = popup.querySelector('.booking-alert-icon i');

        titleEl.textContent = title || 'กรุณาตรวจสอบข้อมูล';
        messageEl.textContent = String(message || '');
        popup.dataset.type = type || 'warning';
        icon.className = type === 'success' ? 'fas fa-check' :
            (type === 'info' ? 'fas fa-info' : 'fas fa-exclamation');
        popup.classList.add('open');

        window.setTimeout(function() {
            popup.querySelector('.booking-alert-confirm').focus();
        }, 50);
    }

    function closeBookingPopup() {
        document.getElementById('bookingAlertPopup').classList.remove('open');
    }

    document.getElementById('bookingAlertPopup').addEventListener('click', function(event) {
        if (event.target === this) closeBookingPopup();
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && document.getElementById('bookingAlertPopup').classList.contains('open')) {
            closeBookingPopup();
        }
    });
    </script>

    <!-- ══ Step Modal Overlay ══════════════════════════════════════════════════════ -->
    <div id="bookingModal"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:center;justify-content:center;padding:20px;">
        <div
            style="background:#fff;border-radius:20px;width:100%;max-width:960px;max-height:92vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.18);position:relative;">

            <!-- Mobile close button (outside step bar) -->
            <button onclick="closeModal()" class="modal-mobile-close" title="ปิด">
                <i class="fas fa-times"></i>
            </button>

            <!-- Step bar -->
            <div style="padding:28px 36px 0;position:relative;">
                <div style="display:flex;align-items:flex-start;gap:0;margin-bottom:0;">
                    <?php foreach([
                        ['1', 'เลือก', 'วิธีชำระ'],
                        ['2', 'ตรวจสอบ', 'รายการ'],
                        ['3', 'เลือก', 'วิธีชำระเงิน'],
                        ['4', 'เสร็จสิ้น', 'การจอง']
                    ] as $i => [$n, $line1, $line2]): ?>
                    <div style="flex:1;text-align:center;">
                        <div class="modal-step-num" id="stepNum<?=$n?>"><?=$n?></div>
                        <div class="modal-step-label"
                            style="font-family:'Kanit',sans-serif;font-size:13px;font-weight:600;color:#333;margin-top:6px;line-height:1.3;">
                            <span style="display:block;"><?=htmlspecialchars($line1)?></span>
                            <span style="display:block;"><?=htmlspecialchars($line2)?></span>
                        </div>
                    </div>
                    <?php if($i<3): ?><div
                        style="flex:1;height:3px;margin-top:20px;background:#e0e0e0;border-radius:4px;"
                        id="stepLine<?=$n?>"></div><?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div id="stepProgressBar"
                    style="height:4px;background:#e0e0e0;border-radius:4px;margin-top:16px;overflow:hidden;">
                    <div id="stepProgressFill"
                        style="height:100%;width:33%;background:#2C4219;border-radius:4px;transition:width .4s;"></div>
                </div>
            </div>

            <!-- ── STEP 1 ── -->
            <div id="modalStep1" style="padding:28px 36px 32px;">
                <div id="step1Grid" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
                    <div>
                        <div style="margin-bottom:10px;">
                            <span
                                style="font-family:'Kanit',sans-serif;font-size:14px;font-weight:700;color:#1a1a1a;">รายละเอียดการจอง</span>
                            <span style="color:#ccc;margin:0 6px;">·</span>
                            <button onclick="closeModal()"
                                style="display:inline;width:auto;margin:0;padding:0;background:none;border:none;font-family:'Kanit',sans-serif;font-size:12px;color:#aaa;cursor:pointer;font-weight:400;">แก้ไข</button>
                        </div>
                        <div style="background:#f8f8f8;border-radius:12px;padding:12px 16px;">
                            <div
                                style="font-family:'Kanit',sans-serif;font-size:14px;font-weight:700;color:#1a1a1a;margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= htmlspecialchars($activity['activity_name']) ?>
                            </div>
                            <div
                                style="font-family:'Kanit',sans-serif;font-size:12px;color:#666;display:flex;flex-wrap:wrap;gap:6px 16px;">
                                <span>📅 <span id="s1Date">-</span></span>
                                <span>📍 <?= htmlspecialchars($activity['shop_name']) ?></span>
                                <span>👥 <span id="s1People">-</span></span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3
                            style="font-family:'Kanit',sans-serif;font-size:18px;font-weight:700;color:#1a1a1a;margin:0 0 18px;">
                            เลือกโปรโมชั่น</h3>
                        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:22px;">
                            <select id="promotionSelect" onchange="applySelectedPromotion()"
                                style="width:100%;padding:11px 14px;border:1.5px solid #ddd;border-radius:10px;font-family:'Kanit',sans-serif;font-size:14px;outline:none;box-sizing:border-box;">
                                <option value="">ไม่ใช้ส่วนลด</option>
                                <?php foreach ($available_promotions as $promotion):
                                    $discountLabel = $promotion['discount_type'] === 'percent'
                                        ? rtrim(rtrim(number_format($promotion['discount_value'], 2), '0'), '.') . '%'
                                        : number_format($promotion['discount_value'], 0) . ' บาท';
                                ?>
                                <option value="<?= (int)$promotion['promotion_id'] ?>"
                                    data-type="<?=htmlspecialchars($promotion['discount_type'])?>"
                                    data-value="<?=(float)$promotion['discount_value']?>"
                                    data-min="<?=(float)$promotion['min_price']?>">
                                    <?=htmlspecialchars($promotion['title'])?> — ลด <?=$discountLabel?>
                                    <?php if ((float)$promotion['min_price'] > 0): ?>
                                    (ขั้นต่ำ <?=number_format($promotion['min_price'], 0)?> บาท)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="promotionMessage"
                                style="font-family:'Kanit',sans-serif;font-size:11px;color:#777;min-height:17px;">
                                <?=empty($available_promotions) ? 'ไม่มีโปรโมชั่นที่สามารถใช้ได้ในขณะนี้' : 'เลือกส่วนลดที่ต้องการใช้กับการจองนี้'?>
                            </div>
                        </div>
                        <h3
                            style="font-family:'Kanit',sans-serif;font-size:18px;font-weight:700;color:#1a1a1a;margin:0 0 14px;">
                            สรุปราคา</h3>
                        <div style="background:#f8f8f8;border-radius:14px;padding:18px 20px;">
                            <div
                                style="font-family:'Kanit',sans-serif;font-size:14px;font-weight:700;margin-bottom:10px;">
                                ราคา</div>
                            <div id="s1AdultLine"
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#555;margin-bottom:6px;">
                            </div>
                            <div id="s1KidLine"
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#555;margin-bottom:14px;">
                            </div>
                            <div
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#888;margin-bottom:8px;">
                                <span>tax</span><span>THB 0.00</span>
                            </div>
                            <div
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#555;margin-bottom:8px;">
                                <span>ราคาเดิม</span><span>THB <span id="s1OriginalTotal">0.00</span></span>
                            </div>
                            <div id="s1DiscountRow"
                                style="display:none;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#c2410c;margin-bottom:8px;">
                                <span>ส่วนลด <span id="s1DiscountLabel"></span></span>
                                <span>- THB <span id="s1DiscountAmount">0.00</span></span>
                            </div>
                            <div style="border-top:1px solid #e0e0e0;margin:10px 0;"></div>
                            <div
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-weight:700;font-size:15px;margin-bottom:6px;">
                                <span>ราคาสุทธิ</span><span>THB <span id="s1Total">0.00</span></span>
                            </div>
                            <div
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-weight:700;font-size:14px;color:#444;">
                                <span>จำนวนเงินที่ต้องชำระ</span><span>THB <span id="s1TotalFinal">0.00</span></span>
                            </div>
                        </div>
                        <div style="margin-bottom:16px;margin-top:20px;">
                            <label style="display:inline-block;margin-right:14px;cursor:pointer;"><input type="radio"
                                    name="payOption" value="now" checked onchange="togglePayOption('now')">
                                ชำระทันที</label>
                            <label style="display:inline-block;cursor:pointer;"><input type="radio" name="payOption"
                                    value="later" onchange="togglePayOption('later')"> ชำระภายใน 2 วัน</label>
                        </div>
                        <button onclick="handleStep1Continue()"
                            style="width:100%;margin-top:16px;padding:14px;background:#2C4219;color:#fff;border:none;border-radius:12px;font-family:'Kanit',sans-serif;font-size:16px;font-weight:700;cursor:pointer;"
                            onmouseover="this.style.opacity='.85'"
                            onmouseout="this.style.opacity='1'">ดำเนินการต่อ</button>
                    </div>
                </div>
            </div>

            <!-- ── STEP 2 ── -->
            <div id="modalStep2" style="display:none;padding:28px 36px 32px;">
                <h3
                    style="font-family:'Kanit',sans-serif;font-size:18px;font-weight:700;color:#1a1a1a;margin:0 0 18px;">
                    ยืนยันการจอง</h3>
                <div style="background:#f8f8f8;border-radius:12px;padding:18px;">
                    <div style="font-family:'Kanit',sans-serif;font-size:14px;color:#444;margin-bottom:8px;">วันที่:
                        <span id="s2Date">-</span>
                    </div>
                    <div style="font-family:'Kanit',sans-serif;font-size:14px;color:#444;margin-bottom:8px;">จำนวนคน:
                        <span id="s2People">-</span>
                    </div>
                    <div style="font-family:'Kanit',sans-serif;font-size:14px;color:#444;margin-bottom:8px;">ยอดสุทธิ:
                        THB <span id="s2Total">0.00</span></div>
                </div>
                <button onclick="goStep(3)"
                    style="width:100%;margin-top:18px;padding:14px;background:#2C4219;color:#fff;border:none;border-radius:12px;font-family:'Kanit',sans-serif;font-size:16px;font-weight:700;cursor:pointer;"
                    onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">ไปยังการชำระเงิน</button>
            </div>

            <!-- ── STEP 3 ── -->
            <div id="modalStep3" style="display:none;padding:28px 36px 32px;">
                <div id="step3Grid" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

                    <!-- LEFT: method selector + bank + confirm button -->
                    <div>
                        <h3
                            style="font-family:'Kanit',sans-serif;font-size:18px;font-weight:700;color:#1a1a1a;margin:0 0 12px;">
                            เลือกวิธีการชำระเงิน</h3>

                        <div id="paymentMethodSection"
                            style="border:1.5px solid #e0e0e0;border-radius:14px;overflow:hidden;">
                            <?php foreach([
                        'mobile' => ['💳','Mobile Banking','SCB Easy / KPlus / Krungthai'],
                        'qr'     => ['📱','QR พร้อมเพย์',   'สแกนจากทุกแอปธนาคาร'],
                        'card'   => ['🏦','บัตรเครดิต / เดบิต','Visa, Mastercard · ปลอดภัยด้วย Omise'],
                    ] as $val=>[$ico,$label,$desc]): ?>
                            <div class="pay-method-row" onclick="selectPayMethod('<?=$val?>')" id="pm_<?=$val?>"
                                style="display:flex;align-items:center;gap:10px;padding:14px 18px;cursor:pointer;border-bottom:1px solid #eee;transition:background .15s;">
                                <span style="font-size:1.3rem;flex-shrink:0;"><?=$ico?></span>
                                <div style="flex:1;">
                                    <div style="font-family:'Kanit',sans-serif;font-size:14px;font-weight:600;">
                                        <?=$label?></div>
                                    <div style="font-family:'Kanit',sans-serif;font-size:11px;color:#888;"><?=$desc?>
                                    </div>
                                </div>
                                <span class="pm-radio" id="pmr_<?=$val?>"
                                    style="width:18px;height:18px;border-radius:50%;border:2px solid #bbb;display:inline-block;flex-shrink:0;transition:all .2s;"></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Card form (แสดงเมื่อเลือก card) -->
                        <div id="cardFormSection" style="display:none;margin-top:14px;">
                            <!-- Card visual -->
                            <div
                                style="background:linear-gradient(135deg,#1D3718,#2C5A22,#4A8C3A);border-radius:14px;padding:18px 20px;color:#fff;margin-bottom:14px;position:relative;overflow:hidden;">
                                <div
                                    style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.06);border-radius:50%;">
                                </div>
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                                    <div
                                        style="width:32px;height:24px;background:linear-gradient(135deg,#D4A843,#F0C060);border-radius:4px;">
                                    </div>
                                    <span id="bkCvBrand"
                                        style="margin-left:auto;font-size:18px;font-weight:900;letter-spacing:-1px;opacity:.9;">••••</span>
                                </div>
                                <div id="bkCvNumber"
                                    style="font-family:monospace;font-size:15px;letter-spacing:3px;margin-bottom:12px;opacity:.9;">
                                    •••• •••• •••• ••••</div>
                                <div style="display:flex;justify-content:space-between;font-size:11px;opacity:.75;">
                                    <div>
                                        <div style="font-size:9px;margin-bottom:2px;">ชื่อบนบัตร</div>
                                        <div id="bkCvName">YOUR NAME</div>
                                    </div>
                                    <div>
                                        <div style="font-size:9px;margin-bottom:2px;">หมดอายุ</div>
                                        <div id="bkCvExpiry" style="font-family:monospace;font-size:13px;">MM/YY</div>
                                    </div>
                                </div>
                            </div>
                            <!-- Fields -->
                            <div style="margin-bottom:10px;">
                                <label
                                    style="display:block;font-family:'Kanit',sans-serif;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;">หมายเลขบัตร</label>
                                <div style="position:relative;">
                                    <input id="bkCardNumber" type="text" inputmode="numeric" maxlength="19"
                                        placeholder="0000 0000 0000 0000" autocomplete="cc-number"
                                        style="width:100%;padding:10px 44px 10px 12px;border:1.5px solid #ddd;border-radius:10px;font-family:'Kanit',sans-serif;font-size:14px;outline:none;">
                                    <span id="bkBrandIcon"
                                        style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:18px;">💳</span>
                                </div>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label
                                    style="display:block;font-family:'Kanit',sans-serif;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;">ชื่อบนบัตร</label>
                                <input id="bkCardName" type="text" placeholder="ชื่อ-นามสกุล (ภาษาอังกฤษ)"
                                    autocomplete="cc-name"
                                    style="width:100%;padding:10px 12px;border:1.5px solid #ddd;border-radius:10px;font-family:'Kanit',sans-serif;font-size:14px;outline:none;">
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                <div>
                                    <label
                                        style="display:block;font-family:'Kanit',sans-serif;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;">วันหมดอายุ</label>
                                    <input id="bkCardExpiry" type="text" inputmode="numeric" maxlength="5"
                                        placeholder="MM/YY" autocomplete="cc-exp"
                                        style="width:100%;padding:10px 12px;border:1.5px solid #ddd;border-radius:10px;font-family:'Kanit',sans-serif;font-size:14px;outline:none;">
                                </div>
                                <div>
                                    <label
                                        style="display:block;font-family:'Kanit',sans-serif;font-size:12px;font-weight:600;color:#555;margin-bottom:5px;">CVV</label>
                                    <input id="bkCardCvv" type="password" inputmode="numeric" maxlength="4"
                                        placeholder="•••" autocomplete="cc-csc"
                                        style="width:100%;padding:10px 12px;border:1.5px solid #ddd;border-radius:10px;font-family:'Kanit',sans-serif;font-size:14px;outline:none;">
                                </div>
                            </div>
                            <div
                                style="display:flex;align-items:flex-start;gap:8px;background:#fffbea;border:1px solid #ffe082;border-radius:8px;padding:9px 12px;font-family:'Kanit',sans-serif;font-size:11px;color:#5c4400;">
                                🧪 <span><strong>Sandbox:</strong> บัตรทดสอบ <strong>4242 4242 4242 4242</strong> /
                                    12/30 / CVV 123</span>
                            </div>
                            <div
                                style="text-align:center;margin-top:10px;font-family:'Kanit',sans-serif;font-size:11px;color:#aaa;">
                                🔒 ข้อมูลบัตรเข้ารหัสด้วย TLS ผ่าน <strong style="color:#1D3718;">Omise</strong>
                            </div>
                        </div>

                        <!-- Bank selector (hidden until mobile selected) -->
                        <div id="bankSection" style="display:none;margin-top:14px;">
                            <h4
                                style="font-family:'Kanit',sans-serif;font-size:13px;font-weight:700;margin:0 0 8px;color:#555;">
                                เลือกธนาคาร</h4>
                            <div style="border:1.5px solid #e0e0e0;border-radius:14px;overflow:hidden;">
                                <?php foreach([
                          'SCB'       => ['#4E2E7F','SCB Easy',      'ธนาคารไทยพาณิชย์'],
                          'K-Bank'    => ['#007B40','KPlus',           'ธนาคารกสิกรไทย'],
                          'Krungthai' => ['#1AB2E8','Krungthai NEXT', 'ธนาคารกรุงไทย'],
                      ] as $b=>[$color,$app,$fname]): ?>
                                <div class="bank-row" onclick="selectBank('<?=$b?>')" id="bank_<?=$b?>"
                                    style="display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;border-bottom:1px solid #eee;transition:background .15s;">
                                    <div
                                        style="width:36px;height:36px;border-radius:8px;background:<?=$color?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <span
                                            style="font-size:14px;color:#fff;font-weight:700;"><?=substr($b,0,1)?></span>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-family:'Kanit',sans-serif;font-size:13px;font-weight:600;">
                                            <?=$fname?></div>
                                        <div style="font-family:'Kanit',sans-serif;font-size:11px;color:#888;"><?=$app?>
                                        </div>
                                    </div>
                                    <span class="bank-radio" id="bankr_<?=$b?>"
                                        style="width:18px;height:18px;border-radius:50%;border:2px solid #bbb;display:inline-block;flex-shrink:0;transition:all .2s;"></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="bookingStatus3" style="font-size:12px;color:#c62828;margin-top:10px;min-height:18px;">
                        </div>

                        <button onclick="confirmBooking()" id="confirmBtn"
                            style="width:100%;margin-top:12px;padding:14px;background:#2C4219;color:#fff;border:none;border-radius:12px;font-family:'Kanit',sans-serif;font-size:16px;font-weight:700;cursor:pointer;transition:opacity .2s;"
                            onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                            ยืนยันและชำระเงิน
                        </button>
                    </div>

                    <!-- RIGHT: order summary + payment UI panel -->
                    <div>
                        <h3
                            style="font-family:'Kanit',sans-serif;font-size:15px;font-weight:700;color:#1a1a1a;margin:0 0 10px;">
                            รายการจอง</h3>
                        <div
                            style="display:flex;gap:12px;background:#f8f8f8;border-radius:12px;padding:14px;margin-bottom:12px;">
                            <img src="<?= htmlspecialchars($shop_picture_url) ?>" onerror="this.style.display='none'"
                                style="width:80px;height:64px;object-fit:cover;border-radius:8px;background:#ddd;flex-shrink:0;">
                            <div style="font-family:'Kanit',sans-serif;font-size:12px;color:#555;">
                                <div style="font-weight:700;font-size:13px;color:#1a1a1a;margin-bottom:6px;">
                                    <?= htmlspecialchars($activity['activity_name']) ?></div>
                                <div>📅 <span id="s3Date">-</span></div>
                                <div>👥 <span id="s3People">-</span></div>
                            </div>
                        </div>
                        <div style="background:#f8f8f8;border-radius:12px;padding:14px;margin-bottom:14px;">
                            <div id="s3AdultLine"
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#555;margin-bottom:6px;">
                            </div>
                            <div id="s3KidLine"
                                style="display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-size:13px;color:#555;margin-bottom:8px;">
                            </div>
                            <div
                                style="border-top:1px solid #ddd;padding-top:10px;display:flex;justify-content:space-between;font-family:'Kanit',sans-serif;font-weight:700;font-size:15px;">
                                <span>ยอดชำระ</span>
                                <span style="color:#2C4219;">THB <span id="s3Total">0.00</span></span>
                            </div>
                        </div>

                        <!-- Payment UI panel (shown after confirmBooking hits payment_process.php) -->
                        <div id="paymentUIPanel" style="display:none;">

                            <!-- QR PromptPay -->
                            <div id="qrPanel"
                                style="display:none;text-align:center;padding:16px;border:1.5px solid #c8e0c0;border-radius:14px;background:#f5faf5;margin-bottom:12px;">
                                <div
                                    style="font-family:'Kanit',sans-serif;font-size:13px;color:#555;margin-bottom:8px;">
                                    📱 สแกน QR พร้อมเพย์เพื่อชำระเงิน
                                </div>
                                <div id="qrCodeContainer"
                                    style="display:flex;justify-content:center;margin-bottom:8px;">
                                    <div id="qrCodeDiv"
                                        style="border-radius:12px;overflow:hidden;background:#fff;padding:8px;display:inline-block;">
                                    </div>
                                </div>
                                <div style="font-family:'Kanit',sans-serif;font-size:11px;color:#888;">
                                    QR หมดอายุใน <span id="qrCountdown"
                                        style="font-weight:700;color:#c62828;">5:00</span>
                                </div>
                                <div style="margin-top:6px;font-family:'Kanit',sans-serif;font-size:12px;color:#333;">
                                    ยอด <strong style="color:#2C4219;">฿<span id="qrAmount">0</span></strong>
                                    &nbsp;·&nbsp; Ref: <span id="qrRef"
                                        style="font-size:11px;color:#888;font-family:monospace;"></span>
                                </div>
                            </div>

                            <!-- Mobile Banking instruction -->
                            <div id="mobilePanel"
                                style="display:none;border:1.5px solid #e0e0e0;border-radius:14px;padding:16px;margin-bottom:12px;">
                                <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                                    <div id="bankLogoIcon"
                                        style="width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <span id="bankLogoLetter"
                                            style="font-size:1.2rem;font-weight:700;color:#fff;"></span>
                                    </div>
                                    <div style="font-family:'Kanit',sans-serif;">
                                        <div id="bankLogoName" style="font-weight:700;font-size:14px;"></div>
                                        <div style="font-size:11px;color:#888;">Mobile Banking</div>
                                    </div>
                                </div>
                                <div id="mobileInstruction"
                                    style="font-family:'Kanit',sans-serif;font-size:13px;color:#444;line-height:1.8;background:#f5faf5;border-radius:8px;padding:12px;margin-bottom:10px;">
                                </div>
                                <a id="openAppBtn" href="#" target="_blank"
                                    style="display:block;padding:10px;background:#2C4219;color:#fff;border-radius:10px;text-align:center;font-family:'Kanit',sans-serif;font-weight:600;font-size:13px;text-decoration:none;">
                                    📱 เปิดแอปธนาคาร
                                </a>
                            </div>

                            <!-- Slip Upload -->
                            <div id="slipUploadSection" style="margin-bottom:12px;">
                                <div
                                    style="font-family:'Kanit',sans-serif;font-size:13px;font-weight:600;color:#333;margin-bottom:8px;">
                                    🧾 แนบสลิปการโอนเงิน <span style="font-weight:400;color:#888;">(จำเป็น)</span>
                                </div>
                                <div id="slipDropzone" onclick="document.getElementById('slipFileInput').click()"
                                    ondragover="event.preventDefault();this.style.borderColor='#2C4219';this.style.background='#f0f7f0';"
                                    ondragleave="this.style.borderColor='#bbb';this.style.background='';"
                                    ondrop="event.preventDefault();this.style.borderColor='#bbb';this.style.background='';_handleSlipFile(event.dataTransfer.files[0]);"
                                    style="border:2px dashed #bbb;border-radius:12px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa;">
                                    <input type="file" id="slipFileInput" accept="image/*" style="display:none;"
                                        onchange="_handleSlipFile(this.files[0])">
                                    <div id="slipPlaceholder">
                                        <div style="font-size:28px;margin-bottom:6px;">🖼️</div>
                                        <div
                                            style="font-family:'Kanit',sans-serif;font-size:13px;font-weight:600;color:#2C4219;">
                                            คลิกหรือลากรูปสลิปมาวาง</div>
                                        <div
                                            style="font-family:'Kanit',sans-serif;font-size:11px;color:#aaa;margin-top:4px;">
                                            JPG, PNG ขนาดไม่เกิน 5 MB</div>
                                    </div>
                                    <div id="slipPreviewWrap" style="display:none;position:relative;">
                                        <img id="slipPreviewImg" src="" alt="สลิป"
                                            style="max-width:100%;max-height:140px;border-radius:8px;object-fit:contain;">
                                        <button onclick="_removeSlipFile(event)"
                                            style="position:absolute;top:0;right:0;background:rgba(0,0,0,.5);color:#fff;border:none;border-radius:50%;width:24px;height:24px;cursor:pointer;font-size:13px;line-height:1;">✕</button>
                                    </div>
                                </div>
                            </div>

                            <!-- Polling status -->
                            <div id="paymentPolling"
                                style="display:none;text-align:center;padding:12px;font-family:'Kanit',sans-serif;font-size:13px;">
                                <div
                                    style="display:inline-block;width:24px;height:24px;border:3px solid #e0e0e0;border-top-color:#2C4219;border-radius:50%;animation:spinPay .8s linear infinite;margin-bottom:6px;">
                                </div>
                                <div style="color:#555;">กำลังรอการยืนยัน...</div>
                                <div style="color:#aaa;font-size:11px;margin-top:2px;">แนบสลิปแล้วรอแอดมินอนุมัติ (1–5
                                    นาที)</div>
                            </div>

                            <!-- Submit slip button -->
                            <button id="submitSlipBtn" onclick="_submitSlipAndWait()"
                                style="display:none;width:100%;padding:12px;background:#2C4219;color:#fff;border:none;border-radius:12px;font-family:'Kanit',sans-serif;font-size:14px;font-weight:700;cursor:pointer;margin-top:4px;">
                                ✓ ส่งสลิปเพื่อยืนยัน
                            </button>

                        </div><!-- /paymentUIPanel -->
                    </div><!-- /RIGHT -->
                </div><!-- /grid -->
            </div><!-- /modalStep3 -->

            <!-- ── STEP 4 ── -->
            <div id="modalStep4" style="display:none;padding:60px 36px;text-align:center;">
                <div
                    style="width:90px;height:90px;border-radius:50%;background:#e8f5e9;border:3px solid #4CAF50;display:flex;align-items:center;justify-content:center;margin:0 auto 22px;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#4CAF50" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                </div>
                <h2
                    style="font-family:'Kanit',sans-serif;font-size:26px;font-weight:800;color:#1a1a1a;margin:0 0 10px;">
                    จองสำเร็จ</h2>
                <p id="s3BookingId" style="font-family:'Kanit',sans-serif;font-size:14px;color:#666;margin:0 0 10px;">
                </p>
                <p id="s3PayMethod"
                    style="font-family:'Kanit',sans-serif;font-size:13px;color:#444;background:#f4f4f4;border-radius:8px;padding:8px 18px;display:inline-block;margin:0 0 24px;">
                </p>
                <button onclick="window.location.href='/tkn/home'"
                    style="padding:12px 32px;background:#2C4219;color:#fff;border:none;border-radius:12px;font-family:'Kanit',sans-serif;font-size:15px;font-weight:700;cursor:pointer;">กลับหน้าหลัก</button>
            </div>

        </div>
    </div>

    <script>
    // ── User Dropdown ──────────────────────────────────────────────────────────────
    const navUserBtn = document.getElementById('navUserBtn');
    const navUserDropdown = document.getElementById('navUserDropdown');
    if (navUserBtn) {
        navUserBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
            navUserDropdown.classList.toggle('active');
        });
        document.addEventListener('click', function() {
            navUserBtn.classList.remove('active');
            navUserDropdown.classList.remove('active');
        });
    }

    // ── Mobile nav ─────────────────────────────────────────────────────────────────
    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
    const nav = document.querySelector('.nav');
    mobileMenuToggle.addEventListener('click', function() {
        nav.classList.toggle('active');
        const icon = this.querySelector('i');
        icon.classList.toggle('fa-bars', !nav.classList.contains('active'));
        icon.classList.toggle('fa-times', nav.classList.contains('active'));
    });
    nav.addEventListener('click', function(e) {
        if (e.target === this || e.target.classList.contains('nav-link')) {
            nav.classList.remove('active');
        }
    });

    // ── Calendar ───────────────────────────────────────────────────────────────────
    function toggleCalendar(e) {
        if (e) e.stopPropagation();
        calOpen = !calOpen;
        document.getElementById('calDropdown').classList.toggle('open', calOpen);
        document.getElementById('calTrigger').classList.toggle('open', calOpen);
        if (calOpen) renderCalendar();
    }
    // Bind global for onclick fallback and external scope
    window.toggleCalendar = toggleCalendar;

    function closeCalendar() {
        calOpen = false;
        document.getElementById('calDropdown').classList.remove('open');
        document.getElementById('calTrigger').classList.remove('open');
    }

    var calTriggerEl = document.getElementById('calTrigger');
    if (calTriggerEl) {
        calTriggerEl.addEventListener('click', toggleCalendar);
    }

    var calWrapEl = document.getElementById('calWrap');
    document.addEventListener('click', e => {
        if (calWrapEl && !calWrapEl.contains(e.target)) closeCalendar();
    });

    function renderCalendar() {
        var yr = calCurrent.getFullYear(),
            mo = calCurrent.getMonth();
        document.getElementById('calMonthYear').textContent = THAI_MONTHS[mo] + ' ' + (yr + 543);
        var grid = document.getElementById('calGrid');
        grid.innerHTML = '';
        DAY_NAMES.forEach(function(d) {
            var el = document.createElement('div');
            el.className = 'cal-day-name';
            el.textContent = d;
            grid.appendChild(el);
        });
        var firstDay = new Date(yr, mo, 1).getDay();
        var days = new Date(yr, mo + 1, 0).getDate();
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        for (var i = 0; i < firstDay; i++) {
            var el = document.createElement('div');
            el.className = 'cal-day empty';
            grid.appendChild(el);
        }
        for (var d = 1; d <= days; d++) {
            (function(day) {
                var dd = new Date(yr, mo, day);
                dd.setHours(0, 0, 0, 0);
                var el = document.createElement('div');
                el.className = 'cal-day';
                el.textContent = day;
                var openStart = openDateStart ? new Date(openDateStart + 'T00:00:00') : null;
                var openEnd = openDateEnd ? new Date(openDateEnd + 'T00:00:00') : null;
                var withinOpenWindow = true;
                if (openStart && openEnd) {
                    withinOpenWindow = dd >= openStart && dd <= openEnd;
                } else {
                    withinOpenWindow = false;
                }
                if (withinOpenWindow && openRecurringDays && Array.isArray(openRecurringDays) && openRecurringDays
                    .length > 0) {
                    var weekday = dd.getDay();
                    if (!openRecurringDays.includes(weekday)) {
                        withinOpenWindow = false;
                    }
                }

                if (dd < today || !withinOpenWindow) {
                    el.classList.add('past');
                    if (!withinOpenWindow && dd >= today) {
                        el.classList.add('disabled');
                    }
                } else {
                    if (dd.getTime() === today.getTime()) el.classList.add('today');
                    if (selectedDate && dd.getTime() === selectedDate.getTime()) el.classList.add('selected');
                    el.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectedDate = dd;
                        // อัปเดต maxCapacity ตามวันที่เลือก
                        var ds = dd.getFullYear() + '-' +
                            String(dd.getMonth() + 1).padStart(2, '0') + '-' +
                            String(dd.getDate()).padStart(2, '0');
                        maxCapacity = (ds in perDateCapacity) ? perDateCapacity[ds] : maxCapPerSession;
                        updateCapacityDisplay();
                        setTriggerText();
                        renderCalendar();
                        setTimeout(closeCalendar, 150);
                    });
                }
                grid.appendChild(el);
            })(d);
        }
    }

    function isDateSelectable(date) {
        if (!hasOpenRequest) return false;
        if (!openDateStart || !openDateEnd) return false;

        var start = new Date(openDateStart + 'T00:00:00');
        var end = new Date(openDateEnd + 'T00:00:00');
        if (date < start || date > end) return false;

        if (openRecurringDays && Array.isArray(openRecurringDays) && openRecurringDays.length > 0) {
            var w = date.getDay();
            if (!openRecurringDays.includes(w)) return false;
        }

        return true;
    }

    function updateCapacityDisplay() {
        var badge = document.getElementById('seatBadgeText');
        var warn = document.getElementById('seatWarningMsg');
        var btn = document.getElementById('bookNowBtn');
        if (!badge) return;
        var rem = maxCapacity;
        badge.textContent = rem + '/' + maxCapPerSession + ' ที่ว่าง';
        // color
        var color = rem <= 0 ? '#c62828' : (rem <= 5 ? '#e65100' : '#2b4218');
        badge.parentElement.style.color = color;
        // warning
        if (rem <= 0) {
            warn.textContent = '❌ ที่นั่งเต็มแล้ว วันที่เลือก';
            warn.style.color = '#c62828';
            warn.style.display = 'block';
            btn.disabled = true;
            btn.textContent = 'ที่นั่งเต็ม';
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        } else if (rem <= 5) {
            warn.textContent = '⚠ เหลือเพียง ' + rem + ' ที่ วันที่เลือก';
            warn.style.color = '#e65100';
            warn.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'จองตอนนี้';
            btn.style.opacity = '';
            btn.style.cursor = '';
        } else {
            warn.style.display = 'none';
            btn.disabled = false;
            btn.textContent = 'จองตอนนี้';
            btn.style.opacity = '';
            btn.style.cursor = '';
        }
    }

    function setTriggerText() {
        var trigger = document.getElementById('calTrigger');
        var span = document.getElementById('triggerText');
        var input = document.getElementById('selectedDateInput');
        if (!selectedDate) {
            span.textContent = 'เลือกวันที่';
            trigger.classList.remove('has-date');
            input.value = '';
            updateBookingStatus();
            return;
        }
        var d = selectedDate;
        span.textContent = d.getDate() + ' ' + THAI_MONTHS[d.getMonth()] + ' ' + (d.getFullYear() + 543);
        trigger.classList.add('has-date');
        input.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate())
            .padStart(2, '0');
        updateBookingStatus();
    }
    var calPrevBtn = document.getElementById('calPrev');
    if (calPrevBtn) {
        calPrevBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            calCurrent.setMonth(calCurrent.getMonth() - 1);
            renderCalendar();
        });
    }
    var calNextBtn = document.getElementById('calNext');
    if (calNextBtn) {
        calNextBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            calCurrent.setMonth(calCurrent.getMonth() + 1);
            renderCalendar();
        });
    }
    renderCalendar();

    // ── Price ──────────────────────────────────────────────────────────────────────
    function renderPrice() {
        var total = qty.adult * adultPrice + qty.child * childPrice;
        document.getElementById('price-display').textContent =
            fmt(adultPrice) + ' / ผู้ใหญ่' + (hasKid ? ', ' + fmt(childPrice) + ' / เด็ก' : '');
        document.getElementById('total-price').textContent = fmt(total);
        refreshPromotionOptions();
        if (selectedPromotionId) updateModalSummary();
    }

    function updateQty(type, delta) {
        var next = qty[type] + delta;
        if (next < 0) return;
        if (delta > 0 && (qty.adult + qty.child) >= maxCapacity && maxCapacity > 0) {
            showBookingPopup('ไม่สามารถจองเกินจำนวนที่นั่งที่เหลืออยู่ (' + maxCapacity + ' ที่)');
            return;
        }
        qty[type] = next;
        var el = document.getElementById(type + '-qty');
        if (el) el.textContent = next;
        renderPrice();
    }
    renderPrice();

    // ── Submit (เปิด modal) ────────────────────────────────────────────────────────
    function submitBooking() {
        <?php if (!isset($_SESSION['user_id'])): ?>
        if (confirm('คุณยังไม่ได้เข้าสู่ระบบ ต้องการไปหน้า Login หรือไม่?')) {
            window.location.href = '/tkn/login';
        }
        return;
        <?php endif; ?>
        if (!selectedDate) {
            showBookingPopup('กรุณาเลือกวันเดินทาง');
            return;
        }
        if (!hasOpenRequest) {
            showBookingPopup('กิจกรรมนี้ยังไม่เปิดรับจอง');
            return;
        }
        var start = new Date(openDateStart + 'T00:00:00');
        var end = new Date(openDateEnd + 'T00:00:00');
        if (selectedDate < start || selectedDate > end) {
            showBookingPopup('กรุณาเลือกวันที่ภายในช่วง ' + openDateStart + ' ' + bookingRangeConnector + ' ' +
                openDateEnd);
            return;
        }
        if (openRecurringDays && Array.isArray(openRecurringDays) && openRecurringDays.length > 0) {
            var weekday = selectedDate.getDay();
            if (!openRecurringDays.includes(weekday)) {
                showBookingPopup('กรุณาเลือกวันที่ตรงกับรอบการจัดที่กำหนด');
                return;
            }
        }
        if (qty.adult + qty.child === 0) {
            showBookingPopup('กรุณาเลือกจำนวนผู้เข้าร่วมอย่างน้อย 1 คน');
            return;
        }
        if (!isSelectedDateValid()) {
            showBookingPopup('วันที่เลือกยังไม่เปิดจองหรือไม่อยู่ในช่วงที่กำหนด');
            return;
        }
        openModal();
    }

    function isSelectedDateValid() {
        if (!selectedDate || !hasOpenRequest) return false;
        if (!openDateStart || !openDateEnd) return false;

        var start = new Date(openDateStart + 'T00:00:00');
        var end = new Date(openDateEnd + 'T00:00:00');
        if (selectedDate < start || selectedDate > end) return false;

        if (openRecurringDays && Array.isArray(openRecurringDays) && openRecurringDays.length > 0) {
            var wd = selectedDate.getDay();
            if (!openRecurringDays.includes(wd)) return false;
        }

        return true;
    }

    function updateBookingStatus() {
        var statusEl = document.getElementById('bookingStatus');
        var stateEl = document.getElementById('bookingStateMessage');
        var confirmBtn = document.getElementById('confirmBtn');
        var bookNowBtn = document.getElementById('bookNowBtn');

        function disableButtons() {
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.style.opacity = '0.55';
            }
            if (bookNowBtn) {
                bookNowBtn.disabled = true;
                bookNowBtn.style.opacity = '0.55';
                bookNowBtn.textContent = 'ยังไม่สามารถจอง';
            }
        }

        function enableButtons() {
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.style.opacity = '1';
            }
            if (bookNowBtn) {
                bookNowBtn.disabled = false;
                bookNowBtn.style.opacity = '1';
                bookNowBtn.textContent = 'จองตอนนี้';
            }
        }

        if (!hasOpenRequest) {
            if (statusEl) statusEl.textContent = 'กิจกรรมนี้ยังไม่เปิดรับจอง';
            if (stateEl) stateEl.textContent = '⚠ กิจกรรมนี้ยังไม่เปิดรับจอง';
            disableButtons();
            return;
        }

        if (!selectedDate) {
            if (statusEl) statusEl.textContent = 'เลือกวันที่ในช่วง ' + openDateStart + ' ' + bookingRangeConnector +
                ' ' + openDateEnd;
            if (stateEl) stateEl.textContent = '⚠ จะเปิดจองวันที่ ' + openDateStart + ' ' + bookingRangeConnector +
                ' ' + openDateEnd;
            disableButtons();
            return;
        }

        if (!isSelectedDateValid()) {
            if (statusEl) statusEl.textContent = 'วันที่เลือกไม่อยู่ในช่วงหรือไม่ตรงรอบการจัด';
            if (stateEl) stateEl.textContent = '⚠ วันที่เลือกไม่อยู่ในช่วงหรือไม่ตรงรอบการจัด';
            disableButtons();
            return;
        }

        if (statusEl) statusEl.textContent = 'สามารถจองได้ในวันที่เลือก';
        if (stateEl) stateEl.textContent = '⚠ เปิดจองแล้วในช่วงวันที่เลือก';
        enableButtons();
    }

    updateBookingStatus();
    togglePayOption('now');

    // ── Wishlist toggle ────────────────────────────────────────────────────────────
    function toggleWishlist() {
        if (isGuest) {
            if (confirm('คุณยังไม่ได้เข้าสู่ระบบ ต้องการไปหน้า Login หรือไม่?')) {
                window.location.href = '/tkn/login';
            }
            return;
        }
        var btn = document.getElementById('wishlistBtnInline');
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('activity_id', activityId);
        fetch('/tkn/api/wishlist_toggle.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(data) {
                if (btn) btn.disabled = false;
                if (data.status === 'added' || data.status === 'removed') {
                    wishlisted = data.wishlisted;
                    // update inline button
                    var icon = btn ? btn.querySelector('i') : null;
                    var label = btn ? btn.querySelector('span') : null;
                    if (icon) icon.className = wishlisted ? 'fas fa-heart' : 'far fa-heart';
                    if (label) label.textContent = wishlisted ? 'Wishlisted' : 'Wishlist';
                    if (btn) btn.classList.toggle('wishlisted', wishlisted);
                    // bounce
                    if (btn) {
                        btn.style.transform = 'scale(1.12)';
                        setTimeout(function() {
                            btn.style.transform = '';
                        }, 180);
                    }
                }
            })
            .catch(function() {
                if (btn) btn.disabled = false;
            });
    }

    // ── Share ─────────────────────────────────────────────────────────────────────
    function shareActivity() {
        if (navigator.share) {
            navigator.share({
                title: document.title,
                url: window.location.href
            });
        } else {
            navigator.clipboard.writeText(window.location.href)
                .then(function() {
                    showBookingPopup('คัดลอกลิงก์แล้ว!', 'สำเร็จ', 'success');
                })
                .catch(function() {
                    showBookingPopup('URL: ' + window.location.href, 'ลิงก์หน้านี้', 'info');
                });
        }
    }

    // ── Modal logic ────────────────────────────────────────────────────────────────
    var selectedPayMethod = null;
    var selectedPayOption = 'now';
    var selectedBank = null;
    var selectedPromotionId = 0;
    var selectedDiscountAmount = 0;
    var MODAL_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.',
        'ธ.ค.'
    ];

    function togglePayOption(option) {
        selectedPayOption = option;
        var methodSection = document.getElementById('paymentMethodSection');
        var bankSection = document.getElementById('bankSection');
        if (option === 'now') {
            if (methodSection) methodSection.style.display = 'block';
            // bankSection stays hidden until user picks 'mobile' via selectPayMethod
            if (bankSection) bankSection.style.display = 'none';
        } else {
            if (methodSection) methodSection.style.display = 'none';
            if (bankSection) bankSection.style.display = 'none';
            selectedPayMethod = null;
            selectedBank = null;
            document.querySelectorAll('.pm-radio, .bank-radio').forEach(function(el) {
                el.style.background = '';
                el.style.borderColor = '#bbb';
            });
            document.querySelectorAll('.pay-method-row, .bank-row').forEach(function(el) {
                el.classList.remove('selected');
                el.style.background = '';
            });
        }
        _resetPaymentUI();
    }

    function handleStep1Continue() {
        // Validate: ต้องมีจำนวนคน + วันที่ก่อนผ่าน
        if (qty.adult + qty.child === 0) {
            showBookingPopup('กรุณาเลือกจำนวนผู้เข้าร่วมอย่างน้อย 1 คน');
            return;
        }
        if (!document.getElementById('selectedDateInput').value) {
            showBookingPopup('กรุณาเลือกวันที่เดินทาง');
            return;
        }
        if (selectedPayOption === 'later') {
            // Pay later → สร้างการจองทันที (ไม่ต้องผ่าน step 3)
            confirmBooking();
            return;
        }
        // Pay now → ไป step 3 (เลือกวิธีชำระ + QR/slip)
        goStep(3);
    }

    function openModal() {
        var m = document.getElementById('bookingModal');
        m.style.display = 'flex'; // แสดง modal ก่อน
        m.classList.add('modal-open'); // CSS media query จัดการ layout
        document.body.style.overflow = 'hidden';
        goStep(1);
        refreshPromotionOptions();
        updateModalSummary();
        _resetPaymentUI();
    }

    function closeModal() {
        var m = document.getElementById('bookingModal');
        m.classList.remove('modal-open');
        m.style.display = 'none'; // ซ่อนสนิท
        document.body.style.overflow = '';
    }

    function fmtDateThai(dateStr) {
        if (!dateStr) return '-';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return '-';
        var month = d.getMonth();
        if (month < 0 || month > 11) return '-';
        return d.getDate() + ' ' + MODAL_MONTHS[month] + ' ' + (d.getFullYear() + 543);
    }

    function updateModalSummary() {
        var dateStr = document.getElementById('selectedDateInput').value;
        var adult = qty.adult,
            kid = qty.child;
        var originalTotal = adult * adultPrice + kid * childPrice;
        var discountInfo = getSelectedPromotionDiscount(originalTotal);
        selectedPromotionId = discountInfo.promotionId;
        selectedDiscountAmount = discountInfo.discount;
        var total = Math.max(0, originalTotal - selectedDiscountAmount).toFixed(2);
        var dateLabel = fmtDateThai(dateStr);
        var peopleLabel = [adult > 0 ? adult + ' ผู้ใหญ่' : '', kid > 0 ? kid + ' เด็ก' : ''].filter(Boolean).join(
            ', ') || '-';
        // Step 1
        var s1Date = document.getElementById('s1Date');
        if (s1Date) s1Date.textContent = dateLabel;
        var s1People = document.getElementById('s1People');
        if (s1People) s1People.textContent = peopleLabel;
        var s1AdultLine = document.getElementById('s1AdultLine');
        if (s1AdultLine) s1AdultLine.innerHTML = adult > 0 ? '<span>' + adultPrice + ' X ' + adult +
            ' ผู้ใหญ่</span><span>THB ' + (adult * adultPrice).toFixed(2) + '</span>' : '';
        var s1KidLine = document.getElementById('s1KidLine');
        if (s1KidLine) s1KidLine.innerHTML = kid > 0 ? '<span>' + childPrice + ' X ' + kid +
            ' เด็ก</span><span>THB ' + (kid * childPrice).toFixed(2) + '</span>' : '';
        var s1Total = document.getElementById('s1Total');
        if (s1Total) s1Total.textContent = total;
        var s1TotalFinal = document.getElementById('s1TotalFinal');
        if (s1TotalFinal) s1TotalFinal.textContent = total;
        var s1OriginalTotal = document.getElementById('s1OriginalTotal');
        if (s1OriginalTotal) s1OriginalTotal.textContent = originalTotal.toFixed(2);
        var discountRow = document.getElementById('s1DiscountRow');
        if (discountRow) discountRow.style.display = selectedDiscountAmount > 0 ? 'flex' : 'none';
        var discountAmount = document.getElementById('s1DiscountAmount');
        if (discountAmount) discountAmount.textContent = selectedDiscountAmount.toFixed(2);
        var discountLabel = document.getElementById('s1DiscountLabel');
        if (discountLabel) discountLabel.textContent = discountInfo.label ? '(' + discountInfo.label + ')' : '';
        // Step 2
        var s2Date = document.getElementById('s2Date');
        if (s2Date) s2Date.textContent = dateLabel;
        var s2People = document.getElementById('s2People');
        if (s2People) s2People.textContent = peopleLabel;
        var s2AdultLine = document.getElementById('s2AdultLine');
        if (s2AdultLine) s2AdultLine.innerHTML = adult > 0 ? '<span>' + adultPrice + ' X ' + adult +
            ' ผู้ใหญ่</span><span>THB ' + (adult * adultPrice).toFixed(2) + '</span>' : '';
        var s2KidLine = document.getElementById('s2KidLine');
        if (s2KidLine) s2KidLine.innerHTML = kid > 0 ? '<span>' + childPrice + ' X ' + kid +
            ' เด็ก</span><span>THB ' + (kid * childPrice).toFixed(2) + '</span>' : '';
        var s2Total = document.getElementById('s2Total');
        if (s2Total) s2Total.textContent = total;
        // Step 3
        var _el = function(id) {
            return document.getElementById(id);
        };
        if (_el('s3Date')) _el('s3Date').textContent = dateLabel;
        if (_el('s3People')) _el('s3People').textContent = peopleLabel;
        if (_el('s3Total')) _el('s3Total').textContent = total;
        if (_el('s3AdultLine')) _el('s3AdultLine').innerHTML = adult > 0 ?
            '<span>' + adultPrice + ' X ' + adult + ' ผู้ใหญ่</span><span>THB ' + (adult * adultPrice).toFixed(2) +
            '</span>' : '';
        if (_el('s3KidLine')) _el('s3KidLine').innerHTML = kid > 0 ?
            '<span>' + childPrice + ' X ' + kid + ' เด็ก</span><span>THB ' + (kid * childPrice).toFixed(2) + '</span>' :
            '';
    }

    function goStep(n) {
        [1, 2, 3, 4].forEach(function(i) {
            document.getElementById('modalStep' + i).style.display = i === n ? 'block' : 'none';
            var num = document.getElementById('stepNum' + i);
            if (num) num.className = 'modal-step-num' + (i < n ? ' done' : i === n ? ' active' : '');
        });
        var progress = '25%';
        if (n === 2) progress = '50%';
        if (n === 3) progress = '75%';
        if (n === 4) progress = '100%';
        document.getElementById('stepProgressFill').style.width = progress;
        updateModalSummary();
    }

    function selectPayMethod(val) {
        selectedPayMethod = val;
        document.querySelectorAll('.pay-method-row').forEach(function(r) {
            r.classList.remove('selected');
            r.style.background = '';
        });
        document.querySelectorAll('.pm-radio').forEach(function(r) {
            r.style.borderColor = '#bbb';
            r.style.background = '';
        });
        var selRow = document.getElementById('pm_' + val);
        var selRadio = document.getElementById('pmr_' + val);
        if (selRow) {
            selRow.classList.add('selected');
            selRow.style.background = '#f5faf5';
        }
        if (selRadio) {
            selRadio.style.borderColor = '#2C4219';
            selRadio.style.background = '#2C4219';
        }
        // show/hide bank section and card form
        var bankSec = document.getElementById('bankSection');
        var cardForm = document.getElementById('cardFormSection');
        if (bankSec) bankSec.style.display = val === 'mobile' ? 'block' : 'none';
        if (cardForm) cardForm.style.display = val === 'card' ? 'block' : 'none';
        // reset bank selection when method changes
        selectedBank = null;
        document.querySelectorAll('.bank-row').forEach(function(r) {
            r.classList.remove('selected');
            r.style.background = '';
        });
        document.querySelectorAll('.bank-radio').forEach(function(r) {
            r.style.borderColor = '#bbb';
            r.style.background = '';
        });
        // reset payment panel if visible
        _resetPaymentUI();
    }

    function selectBank(b) {
        selectedBank = b;
        document.querySelectorAll('.bank-row').forEach(function(r) {
            r.classList.remove('selected');
            r.style.background = '';
        });
        document.querySelectorAll('.bank-radio').forEach(function(r) {
            r.style.borderColor = '#bbb';
            r.style.background = '';
        });
        var row = document.getElementById('bank_' + b);
        var radio = document.getElementById('bankr_' + b);
        if (row) {
            row.classList.add('selected');
            row.style.background = '#f5faf5';
        }
        if (radio) {
            radio.style.borderColor = '#2C4219';
            radio.style.background = '#2C4219';
        }
    }

    function getSelectedPromotionDiscount(originalTotal) {
        var select = document.getElementById('promotionSelect');
        if (!select || !select.value) {
            return {
                promotionId: 0,
                discount: 0,
                label: ''
            };
        }
        var option = select.options[select.selectedIndex];
        var minPrice = Number(option.dataset.min || 0);
        if (originalTotal < minPrice) {
            select.value = '';
            document.getElementById('promotionMessage').textContent =
                'ยอดจองยังไม่ถึงขั้นต่ำ ' + minPrice.toLocaleString('th-TH') + ' บาท';
            return {
                promotionId: 0,
                discount: 0,
                label: ''
            };
        }
        var type = option.dataset.type;
        var value = Number(option.dataset.value || 0);
        var discount = type === 'percent' ? originalTotal * value / 100 : value;
        discount = Math.min(originalTotal, Math.max(0, discount));
        return {
            promotionId: Number(option.value),
            discount: Math.round(discount * 100) / 100,
            label: type === 'percent' ? value + '%' : value.toLocaleString('th-TH') + ' บาท'
        };
    }

    function refreshPromotionOptions() {
        var select = document.getElementById('promotionSelect');
        if (!select) return;
        var originalTotal = qty.adult * adultPrice + qty.child * childPrice;
        Array.from(select.options).forEach(function(option, index) {
            if (index === 0) return;
            var minPrice = Number(option.dataset.min || 0);
            option.disabled = originalTotal < minPrice;
        });
    }

    function applySelectedPromotion() {
        refreshPromotionOptions();
        var info = getSelectedPromotionDiscount(qty.adult * adultPrice + qty.child * childPrice);
        var message = document.getElementById('promotionMessage');
        if (message && info.promotionId) {
            message.textContent = 'ใช้ส่วนลด ' + info.label + ' ลด ' +
                info.discount.toLocaleString('th-TH', {
                    minimumFractionDigits: 2
                }) + ' บาท';
            message.style.color = '#2C4219';
        } else if (message && !message.textContent.includes('ขั้นต่ำ')) {
            message.textContent = 'ไม่ได้เลือกใช้โปรโมชั่น';
            message.style.color = '#777';
        }
        updateModalSummary();
    }

    // ── Payment state vars ──────────────────────────────────────────────────────
    var PROMPTPAY_NUMBER = '0812345678'; // ← เปลี่ยนเป็นเบอร์จริงของระบบ
    var _qrTimerInterval = null;
    var _pollingInterval = null;
    var _slipFile = null;
    var _paymentId = null;
    var _chargeId = null;
    var _bookingIdPaid = null;

    var BANK_UI_MAP = {
        'SCB': {
            color: '#4E2E7F',
            name: 'ธนาคารไทยพาณิชย์',
            app: 'SCB Easy',
            steps: ['เปิดแอป SCB Easy', 'กด "สแกน QR" หรือ "จ่ายเงิน"', 'กรอก Ref ด้านล่าง', 'ยืนยันจำนวนเงิน',
                'กด ยืนยัน'
            ]
        },
        'K-Bank': {
            color: '#007B40',
            name: 'ธนาคารกสิกรไทย',
            app: 'KPlus',
            steps: ['เปิดแอป KPlus', 'กด "โอน/จ่าย" → "พร้อมเพย์"', 'กรอก Ref ด้านล่าง', 'ตรวจจำนวนเงิน',
                'ยืนยันด้วย PIN'
            ]
        },
        'Krungthai': {
            color: '#1AB2E8',
            name: 'ธนาคารกรุงไทย',
            app: 'Krungthai NEXT',
            steps: ['เปิดแอป Krungthai NEXT', 'กด "จ่าย" → "พร้อมเพย์"', 'กรอก Ref ด้านล่าง', 'ตรวจจำนวนเงิน',
                'กด ยืนยัน'
            ]
        },
    };

    async function confirmBooking() {
        var statusEl = document.getElementById('bookingStatus3');

        // กรณี selectedPayOption ยังไม่ถูก set
        if (typeof selectedPayOption === 'undefined') {
            selectedPayOption = 'now';
        }

        // กรณี selectedPayMethod ยังไม่ถูก set จาก onclick (บาง browser/คลิกไม่เข้า)
        if (!selectedPayMethod) {
            var selectedRow = document.querySelector('.pay-method-row.selected');
            if (selectedRow) {
                selectedPayMethod = selectedRow.id.replace('pm_', '');
                console.log('Auto set selectedPayMethod from row:', selectedPayMethod);
            }
        }

        console.log('confirmBooking', {
            selectedPayOption,
            selectedPayMethod,
            selectedBank
        });

        if (selectedPayOption === 'now') {
            if (!selectedPayMethod) {
                if (statusEl) statusEl.textContent = '⚠ กรุณาเลือกวิธีการชำระเงิน';
                return;
            }
            if (selectedPayMethod === 'mobile' && !selectedBank) {
                if (statusEl) statusEl.textContent = '⚠ กรุณาเลือกธนาคาร';
                return;
            }
            // ถ้าเลือกบัตรเครดิต → ตรวจ field ก่อน
            if (selectedPayMethod === 'card') {
                var _cn = (document.getElementById('bkCardNumber')?.value || '').replace(/\s/g, '');
                var _nm = (document.getElementById('bkCardName')?.value || '').trim();
                var _ex = (document.getElementById('bkCardExpiry')?.value || '').trim();
                var _cv = (document.getElementById('bkCardCvv')?.value || '').trim();
                if (_cn.length < 15) {
                    if (statusEl) statusEl.textContent = '⚠ กรุณากรอกหมายเลขบัตรให้ครบ';
                    return;
                }
                if (!_nm) {
                    if (statusEl) statusEl.textContent = '⚠ กรุณากรอกชื่อบนบัตร';
                    return;
                }
                if (!_ex.includes('/')) {
                    if (statusEl) statusEl.textContent = '⚠ กรุณากรอกวันหมดอายุ';
                    return;
                }
                if (_cv.length < 3) {
                    if (statusEl) statusEl.textContent = '⚠ กรุณากรอก CVV';
                    return;
                }
            }
        }

        var btn = document.getElementById('confirmBtn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'กำลังบันทึก...';
        }
        if (statusEl) statusEl.textContent = '';

        // STEP 1: บันทึกการจองที่ book.php
        var fd = new FormData();
        fd.append('activity_id', activityId);
        fd.append('adult', qty.adult);
        fd.append('child', qty.child);
        fd.append('total', qty.adult * adultPrice + qty.child * childPrice);
        if (selectedPromotionId) fd.append('promotion_id', selectedPromotionId);
        fd.append('travel_date', document.getElementById('selectedDateInput').value);
        fd.append('pay_option', selectedPayOption);
        if (selectedPayOption === 'now') {
            fd.append('payment_method', selectedPayMethod);
            if (selectedPayMethod === 'mobile' && selectedBank) fd.append('bank_name', selectedBank);
        }

        var bookingId = null;
        try {
            var r = await fetch('/tkn/api/book.php', {
                method: 'POST',
                body: fd
            });
            var text = await r.text();
            var data = JSON.parse(text);
            if (data.status !== 'success') throw new Error(data.message || 'จองไม่สำเร็จ');
            bookingId = data.booking_id;
        } catch (e) {
            if (statusEl) statusEl.textContent = '⚠ ' + (e.message || 'เกิดข้อผิดพลาด');
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'ยืนยันและชำระเงิน';
            }
            return;
        }

        // Pay later → skip payment, go to step 4
        if (selectedPayOption === 'later') {
            var dueDate = new Date();
            dueDate.setDate(dueDate.getDate() + 2);
            var due = String(dueDate.getDate()).padStart(2, '0') + '/' + String(dueDate.getMonth() + 1).padStart(2,
                '0') + '/' + dueDate.getFullYear();
            document.getElementById('s3BookingId').textContent = 'หมายเลขการจอง: #' + bookingId + ' — จองเรียบร้อย';
            document.getElementById('s3PayMethod').textContent = '📅 โปรดชำระภายใน ' + due;
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'ยืนยันและชำระเงิน';
            }
            goStep(4);
            return;
        }

        // STEP 2: ชำระเงิน
        if (selectedPayMethod === 'card') {
            // ── บัตรเครดิต/เดบิต ผ่าน Omise ──────────────────────────────
            if (btn) btn.textContent = 'กำลังเข้ารหัสบัตร...';
            var _cn = document.getElementById('bkCardNumber').value.replace(/\s/g, '');
            var _nm = document.getElementById('bkCardName').value.trim();
            var _exParts = document.getElementById('bkCardExpiry').value.split('/');
            var _cv = document.getElementById('bkCardCvv').value.trim();
            var _expM = parseInt(_exParts[0]);
            var _expY = parseInt(_exParts[1]);
            if (_expY < 100) _expY += 2000;

            if (typeof Omise === 'undefined') {
                if (statusEl) statusEl.textContent = '⚠ ไม่สามารถโหลด Omise กรุณา refresh';
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'ยืนยันและชำระเงิน';
                }
                return;
            }

            Omise.setPublicKey(<?= json_encode(OMISE_PUBLIC_KEY) ?>);
            Omise.createToken('card', {
                name: _nm,
                number: _cn,
                expiration_month: _expM,
                expiration_year: _expY,
                security_code: _cv,
            }, async function(statusCode, tokenResp) {
                if (statusCode !== 200) {
                    if (statusEl) statusEl.textContent = '⚠ ' + (tokenResp.message ||
                        'ข้อมูลบัตรไม่ถูกต้อง');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'ยืนยันและชำระเงิน';
                    }
                    return;
                }
                if (btn) btn.textContent = 'กำลังชำระเงิน...';
                var ofd = new FormData();
                ofd.append('booking_id', bookingId);
                ofd.append('method', 'card');
                ofd.append('token', tokenResp.id);
                try {
                    var or = await fetch('/tkn/handlers/omise_charge.php', {
                        method: 'POST',
                        body: ofd
                    });
                    var oData = await or.json();
                    if (oData.status !== 'success') throw new Error(oData.message || 'ชำระเงินล้มเหลว');
                    if (oData.paid) {
                        _bookingIdPaid = bookingId;
                        _showStep4Success(bookingId, 'บัตรเครดิต / Omise');
                    } else {
                        throw new Error(oData.failure_message || 'ธนาคารปฏิเสธการชำระเงิน');
                    }
                } catch (e) {
                    if (statusEl) statusEl.textContent = '⚠ ' + (e.message || 'เกิดข้อผิดพลาด');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'ยืนยันและชำระเงิน';
                    }
                }
            });
            return; // รอ callback จาก Omise.js
        }

        // ── QR → Omise PromptPay | Mobile → manual slip flow ─────────────
        if (btn) btn.textContent = 'กำลังเตรียมการชำระเงิน...';
        var pHandler = selectedPayMethod === 'qr' ?
            '/tkn/handlers/omise_charge.php' :
            '/tkn/handlers/payment_process.php';
        var pfd = new FormData();
        pfd.append('booking_id', bookingId);
        pfd.append('method', selectedPayMethod === 'qr' ? 'omise_promptpay' : selectedPayMethod);
        if (selectedPayMethod === 'mobile') pfd.append('bank_name', selectedBank);

        var pData = null;
        try {
            var pr = await fetch(pHandler, {
                method: 'POST',
                body: pfd
            });
            var pText = await pr.text();
            pData = JSON.parse(pText);
            if (pData.status !== 'success') throw new Error(pData.message || 'ชำระเงินล้มเหลว');
        } catch (e) {
            if (statusEl) statusEl.textContent = '⚠ ' + (e.message || 'เกิดข้อผิดพลาด');
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'ยืนยันและชำระเงิน';
            }
            return;
        }

        _paymentId = pData.payment_id;
        _chargeId = pData.charge_id;
        _bookingIdPaid = bookingId;

        if (btn) btn.style.display = 'none';
        document.getElementById('paymentUIPanel').style.display = 'block';

        if (selectedPayMethod === 'qr') {
            _showQRPanel(pData);
        } else {
            _showMobilePanel(pData);
        }

        // โชว์ slip upload + submit slip button
        document.getElementById('slipUploadSection').style.display = 'block';
        document.getElementById('submitSlipBtn').style.display = 'block';

        // เริ่ม polling เฉพาะ QR เท่านั้น (mobile banking รอ slip แล้ว redirect ทันที)
        if (selectedPayMethod === 'qr') {
            _startPolling('payment_status.php?payment_id=' + _paymentId);
        }
    }

    // ── Show QR panel ─────────────────────────────────────────────────────────
    function _showStep4Success(bookingId, methodLabel) {
        document.getElementById('s3BookingId').textContent = 'หมายเลขการจอง: #' + bookingId + ' — ชำระเงินสำเร็จ ✅';
        document.getElementById('s3PayMethod').textContent = '💳 ชำระด้วย: ' + methodLabel;
        var btn = document.getElementById('confirmBtn');
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'ยืนยันและชำระเงิน';
        }
        goStep(4);
    }

    function _showQRPanel(pData) {
        document.getElementById('qrPanel').style.display = 'block';
        document.getElementById('mobilePanel').style.display = 'none';
        document.getElementById('qrRef').textContent = _chargeId;
        document.getElementById('qrAmount').textContent = parseFloat(pData.amount || pData.amount_thb || 0)
            .toLocaleString('th-TH');

        var container = document.getElementById('qrCodeDiv');
        container.innerHTML = '';

        // ถ้า Omise ส่ง QR image URL มา → แสดงรูปตรงๆ (sandbox จริง)
        if (pData.qr_image_url) {
            var img = document.createElement('img');
            img.src = pData.qr_image_url;
            img.style.cssText = 'width:180px;height:180px;object-fit:contain;border-radius:8px;';
            img.onerror = function() {
                _fallbackQR(container, pData);
            };
            container.appendChild(img);
        } else {
            _fallbackQR(container, pData);
        }
        _startQrCountdown(pData.qr_expires_in || 90);
    }

    function _fallbackQR(container, pData) {
        var payload = _buildPromptPayPayload(PROMPTPAY_NUMBER, pData.amount || pData.amount_thb || 0);
        var renderQR = function() {
            new QRCode(container, {
                text: payload,
                width: 180,
                height: 180,
                colorDark: '#1D3718',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        };
        if (typeof QRCode !== 'undefined') {
            renderQR();
        } else {
            var s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
            s.onload = renderQR;
            document.head.appendChild(s);
        }
    }

    // ── Show Mobile Banking panel (Omise Internet Banking) ────────────────────
    function _showMobilePanelOmise(pData) {
        document.getElementById('mobilePanel').style.display = 'block';
        document.getElementById('qrPanel').style.display = 'none';

        var bui = BANK_UI_MAP[selectedBank] || {};
        var icon = document.getElementById('bankLogoIcon');
        var letter = document.getElementById('bankLogoLetter');
        var nameEl = document.getElementById('bankLogoName');
        if (icon) icon.style.background = bui.color || '#555';
        if (letter) letter.textContent = (selectedBank || 'B').charAt(0);
        if (nameEl) nameEl.textContent = (bui.name || selectedBank) + ' · Omise Internet Banking';

        document.getElementById('mobileInstruction').innerHTML =
            '<ol style="margin:0;padding-left:18px;">' +
            '<li style="margin-bottom:4px;">กดปุ่มด้านล่างเพื่อเปิดหน้าชำระเงินของธนาคาร</li>' +
            '<li style="margin-bottom:4px;">ใน <strong>Sandbox</strong>: กด "Authorize" เพื่อจำลองการชำระ</li>' +
            '<li style="margin-bottom:4px;">ระบบจะ redirect กลับมายืนยันอัตโนมัติ</li>' +
            '<li style="margin-bottom:4px;">Ref: <strong style="font-family:monospace;">' + (pData.charge_id || '') +
            '</strong></li>' +
            '</ol>';

        var appBtn = document.getElementById('openAppBtn');
        if (pData.authorize_uri) {
            appBtn.href = pData.authorize_uri;
            appBtn.target = '_blank';
            appBtn.textContent = '🏦 เปิดหน้าชำระเงิน (Omise Sandbox)';
        } else {
            appBtn.style.display = 'none';
        }
    }

    // ── Show Mobile Banking panel (legacy fallback) ───────────────────────────
    function _showMobilePanel(pData) {
        _showMobilePanelOmise(pData);
    }

    // ── Slip file handlers ────────────────────────────────────────────────────
    function _handleSlipFile(file) {
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            showBookingPopup('ไฟล์ใหญ่เกิน 5 MB กรุณาเลือกใหม่');
            return;
        }
        _slipFile = file;
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('slipPreviewImg').src = e.target.result;
            document.getElementById('slipPreviewWrap').style.display = 'block';
            document.getElementById('slipPlaceholder').style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    function _removeSlipFile(e) {
        if (e) e.stopPropagation();
        _slipFile = null;
        document.getElementById('slipFileInput').value = '';
        document.getElementById('slipPreviewWrap').style.display = 'none';
        document.getElementById('slipPlaceholder').style.display = 'block';
    }

    async function _submitSlipAndWait() {
        if (!_slipFile) {
            showBookingPopup('กรุณาแนบสลิปการโอนเงินก่อน');
            return;
        }
        var btn = document.getElementById('submitSlipBtn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'กำลังอัปโหลด...';
        }

        var fd = new FormData();
        fd.append('payment_id', _paymentId);
        fd.append('slip', _slipFile);

        try {
            var r = await fetch('/tkn/handlers/payment_slip_upload.php', {
                method: 'POST',
                body: fd
            });

            if (r.status === 401) {
                throw new Error('session หมดอายุ กรุณา <a href="/tkn/login">เข้าสู่ระบบใหม่</a>');
            }
            if (r.status === 403) {
                throw new Error('ไม่มีสิทธิ์อัปโหลด กรุณาเข้าสู่ระบบใหม่');
            }
            if (!r.ok) {
                var bodyText = await r.text();
                console.error('payment_slip_upload.php non-OK response', r.status, r.statusText, bodyText);
                throw new Error('เกิดข้อผิดพลาด: ' + r.status + ' กรุณาลองใหม่');
            }

            var text = await r.text();
            var d;
            try {
                d = JSON.parse(text);
            } catch (jsonErr) {
                console.error('ไม่สามารถแปลง JSON ของ payment_slip_upload.php ได้:', text);
                throw new Error('ไม่สามารถแปลงข้อมูลที่ได้จากเซิร์ฟเวอร์ กรุณาลองใหม่');
            }

            if (d.status !== 'success') throw new Error(d.message || 'อัปโหลดล้มเหลว');
            // upload สำเร็จ → redirect กลับ home ทันที (สถานะจะขึ้น "รอการตรวจสอบ" ในหน้าการจอง)
            clearInterval(_pollingInterval);
            clearInterval(_qrTimerInterval);
            window.location.href = '/tkn/home';
        } catch (e) {
            console.error('_submitSlipAndWait error:', e);
            showBookingPopup('อัปโหลดสลิปล้มเหลว: ' + (e.message || 'เชื่อมต่อไม่ได้ กรุณาลองใหม่'));
            if (btn) {
                btn.disabled = false;
                btn.textContent = '✓ ส่งสลิปเพื่อยืนยัน';
            }
        }
    }

    // ── Polling รอ admin อนุมัติ ──────────────────────────────────────────────
    function _startPolling(pollUrl) {
        clearInterval(_pollingInterval);
        var count = 0;
        _pollingInterval = setInterval(async function() {
            count++;
            try {
                var sr = await fetch(pollUrl);
                var sd = await sr.json();
                if (sd.payment_status === 'Approved' || sd.booking_status === 'Paid') {
                    clearInterval(_pollingInterval);
                    clearInterval(_qrTimerInterval);
                    var methodLabels = {
                        mobile: 'Mobile Banking',
                        qr: 'QR พร้อมเพย์'
                    };
                    document.getElementById('s3BookingId').textContent = 'หมายเลขการจอง: #' +
                        _bookingIdPaid + ' — ชำระเงินสำเร็จ ✅';
                    document.getElementById('s3PayMethod').textContent = '💳 ชำระด้วย: ' + (methodLabels[
                            selectedPayMethod] || selectedPayMethod) +
                        (selectedPayMethod === 'mobile' && selectedBank ? ' (' + selectedBank + ')' : '');
                    goStep(4);
                }
            } catch (e) {}
            if (count > 120) {
                clearInterval(_pollingInterval);
                var statusEl = document.getElementById('bookingStatus3');
                if (statusEl) statusEl.textContent =
                    '⏱ ยังไม่ได้รับการยืนยัน กรุณาติดต่อเจ้าหน้าที่ (Ref: ' + _chargeId + ')';
            }
        }, 3000);
    }

    // ── QR Countdown ──────────────────────────────────────────────────────────
    function _startQrCountdown(seconds) {
        clearInterval(_qrTimerInterval);
        var remaining = seconds;

        function tick() {
            var m = Math.floor(remaining / 60);
            var s = remaining % 60;
            var el = document.getElementById('qrCountdown');
            if (el) {
                el.textContent = m + ':' + String(s).padStart(2, '0');
                if (remaining <= 60) el.style.color = '#c62828';
            }
            if (remaining <= 0) {
                clearInterval(_qrTimerInterval);
                var container = document.getElementById('qrCodeDiv');
                if (container) container.innerHTML =
                    '<div style="width:180px;height:180px;background:#fafafa;border:1px dashed #ccc;display:flex;align-items:center;justify-content:center;font-size:12px;color:#888;text-align:center;border-radius:8px;">QR หมดอายุ<br><span onclick="location.reload()" style="color:#2C4219;cursor:pointer;text-decoration:underline;font-size:11px;">รีเฟรชหน้า</span></div>';
                return;
            }
            remaining--;
        }
        tick();
        _qrTimerInterval = setInterval(tick, 1000);
    }

    // ── PromptPay EMVCo payload builder ───────────────────────────────────────
    function _buildPromptPayPayload(mobile, amount) {
        var phone = mobile.replace(/\D/g, '');
        if (phone.startsWith('66')) phone = '0' + phone.slice(2);
        if (phone.startsWith('0')) phone = '0066' + phone.slice(1);
        var aid = _tlv('00', '12') + _tlv('01', phone);
        var mInfo = _tlv('29', aid);
        var amtStr = parseFloat(amount).toFixed(2);
        var payload = _tlv('00', '01') + mInfo + _tlv('53', '764') + _tlv('54', amtStr) + _tlv('58', 'TH');
        payload += _tlv('63', _crc16(payload + '6304'));
        return payload;
    }

    function _tlv(tag, value) {
        return tag + String(value.length).padStart(2, '0') + value;
    }

    function _crc16(data) {
        var crc = 0xFFFF;
        for (var i = 0; i < data.length; i++) {
            crc ^= data.charCodeAt(i) << 8;
            for (var j = 0; j < 8; j++) crc = (crc & 0x8000) ? ((crc << 1) ^ 0x1021) : (crc << 1);
        }
        return ((crc & 0xFFFF) >>> 0).toString(16).toUpperCase().padStart(4, '0');
    }

    // ── Reset payment UI ──────────────────────────────────────────────────────
    // ── Card form live preview ──────────────────────────────────────────────
    document.getElementById('bkCardNumber')?.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '').slice(0, 16);
        this.value = v.match(/.{1,4}/g)?.join(' ') || v;
        document.getElementById('bkCvNumber').textContent = v.padEnd(16, '•').match(/.{1,4}/g).join(' ');
        var brand = v.startsWith('4') ? 'VISA' : v.match(/^5[1-5]/) ? 'MASTERCARD' : '••••';
        var icon = v.startsWith('4') ? '💳' : v.match(/^5[1-5]/) ? '🔴' : '💳';
        document.getElementById('bkCvBrand').textContent = brand;
        document.getElementById('bkBrandIcon').textContent = icon;
    });
    document.getElementById('bkCardName')?.addEventListener('input', function() {
        document.getElementById('bkCvName').textContent = this.value.toUpperCase() || 'YOUR NAME';
    });
    document.getElementById('bkCardExpiry')?.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '').slice(0, 4);
        if (v.length >= 2) v = v.slice(0, 2) + '/' + v.slice(2);
        this.value = v;
        document.getElementById('bkCvExpiry').textContent = v || 'MM/YY';
    });

    function _resetPaymentUI() {
        clearInterval(_qrTimerInterval);
        clearInterval(_pollingInterval);
        ['paymentUIPanel', 'qrPanel', 'mobilePanel', 'paymentPolling'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        var submitBtn = document.getElementById('submitSlipBtn');
        if (submitBtn) submitBtn.style.display = 'none';
        _slipFile = null;
        _paymentId = null;
        _chargeId = null;
        var inp = document.getElementById('slipFileInput');
        if (inp) inp.value = '';
        var pw = document.getElementById('slipPreviewWrap');
        if (pw) pw.style.display = 'none';
        var ph = document.getElementById('slipPlaceholder');
        if (ph) ph.style.display = 'block';
        var statusEl = document.getElementById('bookingStatus3');
        if (statusEl) statusEl.textContent = '';
        var btn = document.getElementById('confirmBtn');
        if (btn) {
            btn.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'ยืนยันและชำระเงิน';
        }
    }

    // ปิด modal เมื่อกด overlay
    document.getElementById('bookingModal').addEventListener('click', function(e) {
        // Desktop: กดนอก modal box ปิด
        // Mobile: กดพื้นที่มืดด้านบน bottom sheet ปิด (e.target === overlay itself)
        if (e.target === this) closeModal();
    });

    // ── Mobile Bottom Sheet: Book Now ─────────────────────────────────────────
    function openBookingSheet() {
        var card = document.getElementById('bookingCard');
        var backdrop = document.getElementById('sheetBackdrop');
        if (!card) return;
        card.classList.remove('sheet-collapsed');
        if (backdrop) backdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeBookingSheet() {
        var card = document.getElementById('bookingCard');
        var backdrop = document.getElementById('sheetBackdrop');
        if (!card) return;
        card.classList.add('sheet-collapsed');
        if (backdrop) backdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Update the bar total when qty changes (hook into existing updateQty)
    var _origUpdateQty = typeof updateQty === 'function' ? updateQty : null;
    document.addEventListener('DOMContentLoaded', function() {
        // Sync bar total display whenever total-price changes
        var totalEl = document.getElementById('total-price');
        var barTotalWrap = document.getElementById('sheetBarTotal');
        var barTotalVal = document.getElementById('sheetBarTotalVal');
        if (totalEl && barTotalVal) {
            var obs = new MutationObserver(function() {
                var txt = totalEl.textContent.trim();
                if (txt && txt !== '฿0') {
                    barTotalVal.textContent = txt;
                    if (barTotalWrap) barTotalWrap.style.display = 'block';
                } else {
                    if (barTotalWrap) barTotalWrap.style.display = 'none';
                }
            });
            obs.observe(totalEl, {
                childList: true,
                subtree: true,
                characterData: true
            });
        }
    });
    </script>
    <!-- LINE Floating Button -->
    <a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener" class="line-fab"
        title="ติดต่อเราผ่าน LINE">
        <span class="line-fab-icon"><i class="fab fa-line"></i></span>
        <span class="line-fab-label">LINE</span>
    </a>
    <style>
    .line-fab {
        position: fixed;
        bottom: 28px;
        right: 24px;
        z-index: 9999;
        display: flex;
        align-items: center;
        background: #06C755;
        color: #fff;
        text-decoration: none;
        border-radius: 50px;
        box-shadow: 0 4px 18px rgba(6, 199, 85, .45), 0 2px 8px rgba(0, 0, 0, .15);
        overflow: hidden;
        width: 56px;
        height: 56px;
        transition: width .35s cubic-bezier(.4, 0, .2, 1), box-shadow .2s, transform .2s;
        animation: line-fab-bounce 2.8s ease-in-out 1.2s 3;
    }

    .line-fab:hover {
        width: 138px;
        box-shadow: 0 8px 28px rgba(6, 199, 85, .55), 0 4px 12px rgba(0, 0, 0, .18);
        transform: translateY(-2px);
    }

    .line-fab-icon {
        flex-shrink: 0;
        width: 56px;
        height: 56px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.7rem;
    }

    .line-fab-label {
        white-space: nowrap;
        font-size: .92rem;
        font-weight: 700;
        letter-spacing: .04em;
        opacity: 0;
        max-width: 0;
        overflow: hidden;
        transition: opacity .2s .1s, max-width .35s cubic-bezier(.4, 0, .2, 1);
        padding-right: 0;
    }

    .line-fab:hover .line-fab-label {
        opacity: 1;
        max-width: 90px;
        padding-right: 16px;
    }

    /* Mobile: ย้าย LINE FAB ขึ้นพ้น bottom sheet bar (~80px) */
    @media (max-width: 768px) {
        .line-fab {
            bottom: 96px;
            right: 14px;
            width: 46px;
            height: 46px;
            animation: none;
        }

        .line-fab:hover {
            width: 46px;
        }

        .line-fab-icon {
            width: 46px;
            height: 46px;
            font-size: 1.4rem;
        }

        .line-fab-label {
            display: none;
        }
    }

    @keyframes line-fab-bounce {

        0%,
        100% {
            transform: translateY(0)
        }

        40% {
            transform: translateY(-8px)
        }

        60% {
            transform: translateY(-4px)
        }
    }
    </style>
</body>

</html>
