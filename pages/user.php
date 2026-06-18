<?php
require_once __DIR__ . '/../config/env.php';
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /tkn/login');
    exit;
}

include '../db.php';

// ===== ตรวจสอบภาษา =====
$lang = $_SESSION['lang'] ?? 'th';
$isEnglish = ($lang === 'en');

// ===== ข้อความ =====
if ($isEnglish) {
    // English
    $t = [
        'nav_home'          => 'Home',
        'nav_trips'         => 'Activities',
        'nav_contact'       => 'Contact',
        'nav_passport'      => 'Passport',
        'nav_logout'        => 'Logout',
        'nav_login'         => 'Login',
        'logout_confirm'    => 'Do you want to logout?',
        'lang_switch_label' => 'TH',
        'lang_switch_href'  => addLangParam('/tkn/profile', 'th'),
        'html_lang'         => 'en',
    ];
} else {
    // Thai
    $t = [
        'nav_home'          => 'หน้าแรก',
        'nav_trips'         => 'กิจกรรม',
        'nav_contact'       => 'ติดต่อเรา',
        'nav_passport'      => 'พาสปอร์ต',
        'nav_logout'        => 'ออกจากระบบ',
        'nav_login'         => 'เข้าสู่ระบบ',
        'logout_confirm'    => 'คุณต้องการออกจากระบบใช่หรือไม่?',
        'lang_switch_label' => 'EN',
        'lang_switch_href'  => addLangParam('/tkn/profile', 'en'),
        'html_lang'         => 'th',
    ];
}

$uid = (int)$_SESSION['user_id'];
$flash_booking_success = $_SESSION['booking_success'] ?? null;
if (isset($_SESSION['booking_success'])) {
    unset($_SESSION['booking_success']);
}

// ── User info ────────────────────────────────────────────────────────────────
$u = $conn->prepare("SELECT user_id, username, fullname, email, phonenumber, profile_pic FROM `user` WHERE user_id = ?");
if (!$u) die("DB prepare error: " . $conn->error);
$u->bind_param("i", $uid);
$u->execute();
$userData = $u->get_result()->fetch_assoc();
$u->close();
$user = $userData;

if (!$user) {
    session_destroy();
    header('Location: /tkn/login');
    exit;
}

// ── Bookings (รายการจอง) ─────────────────────────────────────────────────────
$bookingCols = 'b.booking_id, b.booking_date, b.total_price, b.status, b.adult_quantity, b.kid_quantity, a.activity_id, a.activity_name, a.duration_label, a.adult_price, a.kid_price, a.activity_pic, s.shop_name, s.shop_picture, s.location, s.district, s.province';
$check = $conn->query("SHOW COLUMNS FROM `booking` LIKE 'payment_deadline'");
if ($check && $check->num_rows > 0) {
    $bookingCols = 'b.booking_id, b.booking_date, b.total_price, b.status, b.payment_deadline, b.adult_quantity, b.kid_quantity, a.activity_id, a.activity_name, a.duration_label, a.adult_price, a.kid_price, a.activity_pic, s.shop_name, s.shop_picture, s.location, s.district, s.province';
}

$bq = $conn->prepare(
    "SELECT {$bookingCols},
            EXISTS(
                SELECT 1 FROM review rv
                WHERE rv.user_id = b.user_id AND rv.activity_id = b.activity_id
            ) AS has_reviewed,
            (SELECT r.note FROM activity_open_request r
             WHERE r.new_activity_id = a.activity_id AND r.status = 'Approved'
             LIMIT 1) AS open_note,
            (SELECT p.admin_note FROM payment p
             WHERE p.booking_id = b.booking_id AND p.status = 'Rejected'
             ORDER BY p.payment_id DESC LIMIT 1) AS admin_note
     FROM booking b
     JOIN activity a ON b.activity_id = a.activity_id
     JOIN shop s ON a.shop_id = s.shop_id
     WHERE b.user_id = ?
     ORDER BY b.booking_date DESC"
);
if ($bq) {
    $bq->bind_param("i", $uid);
    $bq->execute();
    $bookings = $bq->get_result()->fetch_all(MYSQLI_ASSOC);
    $bq->close();
} else {
    $bookings = [];
}


// ── Passport: stamps earned from completed activities ────────────────────────
$pq = $conn->prepare("
    SELECT sc.shop_category_id,
           sc.category_name,
           sc.category_name_en,
           COUNT(ap.passport_id) AS stamp_count,
           COALESCE(SUM(ap.points_earned), 0) AS points_earned,
           MIN(ap.earned_at) AS first_earned_at,
           MAX(ap.earned_at) AS last_earned_at
    FROM   activity_passport ap
    JOIN   activity a  ON ap.activity_id = a.activity_id
    JOIN   shop     s  ON a.shop_id     = s.shop_id
    JOIN   shop_category sc ON s.shop_category_id = sc.shop_category_id
    WHERE  ap.user_id = ?
    GROUP  BY sc.shop_category_id, sc.category_name, sc.category_name_en
    ORDER  BY sc.shop_category_id
");
$pq->bind_param("i", $uid);
$pq->execute();
$earned_cats = $pq->get_result()->fetch_all(MYSQLI_ASSOC);
$pq->close();

// All categories (for empty slots)
$all_cats = $conn->query("SELECT shop_category_id, category_name, category_name_en FROM shop_category ORDER BY shop_category_id")->fetch_all(MYSQLI_ASSOC);
$earned_ids = array_column($earned_cats, 'shop_category_id');
$earned_by_category = [];
$total_stamps = 0;
foreach ($earned_cats as $earned_cat) {
    $category_id = (int)$earned_cat['shop_category_id'];
    $stamp_count = (int)$earned_cat['stamp_count'];
    $earned_by_category[$category_id] = $earned_cat;
    $total_stamps += $stamp_count;
}
$repeat_reward_target = 3;

// ── Wishlist (จาก wishlist table จริง) ───────────────────────────────────────
$wq = $conn->prepare("
    SELECT w.wishlist_id, w.created_at,
           a.activity_id, a.activity_name, a.description,
           a.adult_price, a.kid_price, a.duration_label, a.suitable_for,
           a.capacity_remaining,
           s.shop_name, s.shop_picture, s.district, s.province
    FROM   wishlist w
    JOIN   activity a ON w.activity_id = a.activity_id
    JOIN   shop     s ON a.shop_id     = s.shop_id
    WHERE  w.user_id = ?
    ORDER  BY w.created_at DESC
");
$wq->bind_param("i", $uid);
$wq->execute();
$wishlists = $wq->get_result()->fetch_all(MYSQLI_ASSOC);
$wq->close();

// ── Active tab ───────────────────────────────────────────────────────────────
$tab = in_array($_GET['tab'] ?? '', ['wishlist','passport']) ? $_GET['tab'] : 'bookings';

// ── One-time migration: expand booking.status enum + fix empty-status rows ───
$conn->query("ALTER TABLE booking MODIFY COLUMN status ENUM('Pending','PendingReview','Paid','Completed','Cancel','Rejected') NOT NULL DEFAULT 'Pending'");
$conn->query("UPDATE booking SET status='PendingReview' WHERE status='' AND booking_id IN (SELECT b2.booking_id FROM (SELECT p.booking_id FROM payment p WHERE p.slip_image IS NOT NULL AND p.slip_image != '') b2)");

// ── CSS version ───────────────────────────────────────────────────────────────
$userCssPath = dirname(__DIR__) . '/assets/css/user.css';
$cssVer = file_exists($userCssPath) ? filemtime($userCssPath) : time();
$responsiveNavCssPath = dirname(__DIR__) . '/assets/css/responsive-nav.css';
$responsiveNavCssVer = file_exists($responsiveNavCssPath) ? filemtime($responsiveNavCssPath) : time();
$userResponsiveCssPath = dirname(__DIR__) . '/assets/css/user-responsive.css';
$responsiveCssVer = file_exists($userResponsiveCssPath) ? filemtime($userResponsiveCssPath) : time();

// ── Resolve profile_pic path (stored relative to handlers/, rendered from pages/) ──
function avatarSrc(?string $pic): string {
    if (empty($pic)) return '';
    // already an absolute URL or already has ../handlers prefix
    if (str_starts_with($pic, 'http') || str_starts_with($pic, '/tkn/handlers/')) return htmlspecialchars($pic);
    // strip leading slash just in case
    $pic = ltrim($pic, '/');
    return htmlspecialchars('/tkn/handlers/' . $pic);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function parseActivityTime(?string $note): string {
    if (!$note) return '';
    // จับ HH:MM - HH:MM จาก note เช่น "จัดซ้ำ : วันเสาร์ 09:00 - 13:00"
    if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/u', $note, $m)) {
        return $m[1] . ' – ' . $m[2] . ' น.';
    }
    return '';
}

function thaiDate(string $date): string {
    $months = ['', 'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $d = date_create($date);
    if (!$d) return $date;
    $day = (int)date_format($d, 'j');
    $mo  = (int)date_format($d, 'n');
    $yr  = (int)date_format($d, 'Y') + 543;
    return "$day {$months[$mo]} $yr";
}
function statusBadge(string $s, $deadline = null): string {
    $now = new DateTime('now');
    $overdue = false;
    if ($s === 'Pending' && $deadline) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $deadline);
        if ($dt && $now > $dt) $overdue = true;
    }

    $map = [
        'Pending'       => ['label' => 'รอชำระเงิน' . ($overdue ? ' (เลยกำหนด)' : ''), 'dot' => '#f59e0b', 'color' => '#92400e', 'bg' => '#fef3c7', 'border' => '#fde68a'],
        'PendingReview' => ['label' => 'รอการอนุมัติ',      'dot' => '#3b82f6', 'color' => '#1e40af', 'bg' => '#dbeafe', 'border' => '#93c5fd'],
        'Paid'          => ['label' => 'ชำระเสร็จสิ้น',     'dot' => '#22c55e', 'color' => '#15803d', 'bg' => '#dcfce7', 'border' => '#86efac'],
        'Completed'     => ['label' => 'กิจกรรมเสร็จสิ้น',  'dot' => '#a855f7', 'color' => '#6b21a8', 'bg' => '#f3e8ff', 'border' => '#d8b4fe'],
        'Cancel'        => ['label' => 'ยกเลิก',             'dot' => '#ef4444', 'color' => '#991b1b', 'bg' => '#fee2e2', 'border' => '#fca5a5'],
        'Rejected'      => ['label' => 'สลิปถูกปฏิเสธ',     'dot' => '#ef4444', 'color' => '#991b1b', 'bg' => '#fee2e2', 'border' => '#fca5a5'],
    ];
    $i     = $map[$s] ?? ['label' => ($s ?: 'ไม่ทราบสถานะ'), 'dot' => '#9ca3af', 'color' => '#374151', 'bg' => '#f3f4f6', 'border' => '#d1d5db'];
    $label = htmlspecialchars($i['label'], ENT_QUOTES, 'UTF-8');
    return "<span style='display:inline-block;background:{$i['bg']};color:{$i['color']};border:1.5px solid {$i['border']};border-radius:20px;padding:2px 12px;font-size:13px;font-weight:700;font-family:Kanit,sans-serif;line-height:1.8;'>{$label}</span>";
}

// Category icon emoji map
$cat_icons = [
    1  => '🌾', 2  => '🚜', 3  => '🌿', 4  => '🎋',
    5  => '🌱', 6  => '🏘️', 7  => '🌻', 8  => '☕',
    9  => '🍃', 10 => '🏕️', 11 => '💧',
];

$unlocked_count = count($earned_ids);
$category_total = count($all_cats);
$remaining_categories = max(0, $category_total - $unlocked_count);
$progress_percent = $category_total > 0 ? round(($unlocked_count / $category_total) * 100, 1) : 0;
$explorer_level = max(1, intdiv(max(1, $total_stamps) - 1, 3) + 1);
$first_stamp = null;
foreach ($earned_cats as $earned_cat) {
    if ($first_stamp === null || strtotime($earned_cat['first_earned_at']) < strtotime($first_stamp['first_earned_at'])) {
        $first_stamp = $earned_cat;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $t['html_lang'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ – <?= htmlspecialchars($user['fullname']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/tkn/assets/css/user.css?v=<?= $cssVer ?>">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-nav.css?v=<?= $responsiveNavCssVer ?>">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-footer.css">
    <link rel="stylesheet" href="/tkn/assets/css/user-responsive.css?v=<?= $responsiveCssVer ?>">
</head>

<body>
    <div id="wrapper">

        <!-- Header -->
        <header class="header">
            <div class="container">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <nav class="nav">
                    <a href="<?= addLangParam('/tkn/home') ?>" class="nav-link"><?= $t['nav_home'] ?></a>
                    <a href="<?= addLangParam('/tkn/activities') ?>" class="nav-link"><?= $t['nav_trips'] ?></a>
                    <a href="<?= addLangParam('/tkn/contact') ?>" class="nav-link"><?= $t['nav_contact'] ?></a>
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
                            <a href="<?= addLangParam('/tkn/profile') ?>" class="nav-dropdown-item <?= $tab !== 'passport' ? 'active' : '' ?>">
                                <i class="fas fa-user"></i> <?= $isEnglish ? 'Profile' : 'โปรไฟล์' ?>
                            </a>
                            <a href="<?= addLangParam('/tkn/profile?tab=passport') ?>" class="nav-dropdown-item <?= $tab === 'passport' ? 'active' : '' ?>">
                                <i class="fas fa-passport"></i> <?= $t['nav_passport'] ?>
                            </a>
                            <a href="/tkn/logout" class="nav-dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                            </a>
                        </div>
                    </div>
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

        <!-- ── Main ────────────────────────────────────────────────────────────────── -->
        <main class="main-wrap">

            <?php if ($flash_booking_success): ?>
            <div
                style="background:#e8f6ef;border:1px solid #39a56e;color:#0b5a36;margin:14px;padding:14px;border-radius:10px;">
                <strong>สำเร็จ:</strong> <?= htmlspecialchars($flash_booking_success) ?>
            </div>
            <?php endif; ?>

            <!-- Profile ID Card -->
            <div class="id-card">
                <!-- แถบสีบนสุด -->
                <div class="id-card-header">
                    <span class="id-card-brand">TEAWKANNA</span>
                    <span class="id-card-title">IDENTIFICATION CARD</span>
                </div>

                <div class="id-card-body">
                    <!-- รูปโปรไฟล์ -->
                    <div class="id-avatar<?= !empty($user['profile_pic']) ? ' id-avatar-img' : '' ?>">
                        <?php if (!empty($user['profile_pic'])): ?>
                        <img src="<?= avatarSrc($user['profile_pic']) ?>"
                            alt="<?= htmlspecialchars($user['fullname']) ?>"
                            onerror="this.parentElement.classList.remove('id-avatar-img');this.replaceWith(document.createTextNode('<?= mb_strtoupper(mb_substr($user['fullname'],0,1,'UTF-8'),'UTF-8') ?>'));">
                        <?php else: ?>
                        <?= mb_strtoupper(mb_substr($user['fullname'], 0, 1, 'UTF-8'), 'UTF-8') ?>
                        <?php endif; ?>
                    </div>

                    <!-- ข้อมูล -->
                    <div class="id-fields">
                        <div class="id-field">
                            <span class="id-field-label">NAME</span>
                            <span class="id-field-value id-field-name"><?= htmlspecialchars($user['fullname']) ?></span>
                        </div>
                        <div class="id-field-row">
                            <div class="id-field">
                                <span class="id-field-label">USERNAME</span>
                                <span class="id-field-value"><?= htmlspecialchars($user['username']) ?></span>
                            </div>
                            <div class="id-field">
                                <span class="id-field-label">STAMPS</span>
                                <span class="id-field-value"><?= $total_stamps ?> ดวง</span>
                            </div>
                        </div>
                        <div class="id-field">
                            <span class="id-field-label">EMAIL</span>
                            <span class="id-field-value"><?= htmlspecialchars($user['email']) ?></span>
                        </div>
                        <div class="id-field">
                            <span class="id-field-label">PHONE</span>
                            <span class="id-field-value"><?= htmlspecialchars($user['phonenumber']) ?></span>
                        </div>

                        <!-- ปุ่มแก้ไข -->
                        <a href="/tkn/profile/setup" class="id-edit-btn">
                            <i class="fas fa-pen"></i> แก้ไข
                        </a>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="tab-bar">
                    <a href="?tab=bookings" class="tab-item <?= $tab === 'bookings'  ? 'active' : '' ?>">รายการจอง</a>
                    <a href="?tab=wishlist" class="tab-item <?= $tab === 'wishlist'  ? 'active' : '' ?>">WISHLIST</a>
                </div>

                <!-- ── TAB: รายการจอง ─────────────────────────────────────────────────── -->
                <?php if ($tab === 'bookings'): ?>
                <div class="tab-content">
                    <?php if (empty($bookings)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🗓️</div>
                        <p>ยังไม่มีรายการจอง</p>
                        <a href="/tkn/activities" class="cta-btn">ค้นหากิจกรรม</a>
                    </div>
                    <?php else: ?>
                    <div class="booking-grid">
                        <?php foreach ($bookings as $b):
                        $deadline  = isset($b['payment_deadline']) ? $b['payment_deadline'] : null;
                        $isOverdue = false;
                        if ($deadline) {
                            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $deadline);
                            $isOverdue = $dt && (new DateTime('now')) > $dt;
                        }
                        $imgSrc = htmlspecialchars($b['activity_pic'] ?: ($b['shop_picture'] ?? ''));
                        // แก้ path สำหรับรูปที่ upload ผ่านระบบใหม่ (handlers/uploads/activity_pics/)
                        $rawImg = $b['activity_pic'] ?: ($b['shop_picture'] ?? '');
                        if (!empty($rawImg) && !preg_match('#^https?://#', $rawImg) && preg_match('#^uploads/activity_pics/#', $rawImg)) {
                            $imgSrc = htmlspecialchars('/tkn/handlers/' . $rawImg);
                        }
                        // JSON payload สำหรับ receipt modal
                        $receiptData = json_encode([
                            'booking_id'     => (int)$b['booking_id'],
                            'activity_name'  => $b['activity_name'],
                            'shop_name'      => $b['shop_name'],
                            'district'       => $b['district'] ?? '',
                            'booking_date'   => thaiDate($b['booking_date']),
                            'activity_time'  => parseActivityTime($b['open_note'] ?? null),
                            'duration_label' => (isset($b['duration_label']) && $b['duration_label'] !== '' && $b['duration_label'] !== '0') ? $b['duration_label'] : '',
                            'adult_qty'      => (int)$b['adult_quantity'],
                            'kid_qty'        => (int)$b['kid_quantity'],
                            'adult_price'    => (float)($b['adult_price'] ?? 0),
                            'kid_price'      => (float)($b['kid_price'] ?? 0),
                            'total_price'    => (float)$b['total_price'],
                            'status'         => $b['status'],
                            'admin_note'     => $b['admin_note'] ?? '',
                            'img'            => $b['activity_pic'] ?: ($b['shop_picture'] ?? ''),
                        ], JSON_UNESCAPED_UNICODE);
                    ?>
                        <div class="bk-card">
                            <!-- รูป (background-image แบบเดียวกับ wishlist) -->
                            <div class="bk-img<?= $imgSrc ? '' : ' bk-img-empty' ?>"
                                <?= $imgSrc ? "style=\"background-image:url('" . $imgSrc . "');background-size:cover;background-position:center;\"" : '' ?>>
                                <?php if (!$imgSrc): ?>
                                <svg viewBox="0 0 24 24" style="width:44px;height:44px;fill:#a5d6a7;opacity:.6;">
                                    <path
                                        d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                                </svg>
                                <?php endif; ?>
                            </div>
                            <!-- ข้อมูล -->
                            <div class="bk-body">
                                <div class="bk-status-badge"><?= statusBadge($b['status'], $deadline) ?></div>
                                <h4 class="bk-name"><?= htmlspecialchars($b['activity_name']) ?></h4>
                                <div class="bk-meta">
                                    <span><i
                                            class="fas fa-store"></i><?= htmlspecialchars($b['shop_name']) ?><?= $b['district'] ? ', '.$b['district'] : '' ?></span>
                                    <span><i class="fas fa-calendar-alt"></i><?= thaiDate($b['booking_date']) ?></span>
                                    <span><i
                                            class="fas fa-users"></i><?= $b['adult_quantity'] > 0 ? $b['adult_quantity'].' ผู้ใหญ่' : '' ?><?= $b['kid_quantity'] > 0 ? ($b['adult_quantity'] > 0 ? ' · ' : '').$b['kid_quantity'].' เด็ก' : '' ?></span>
                                </div>
                                <div class="bk-price">฿<?= number_format($b['total_price'], 0) ?></div>
                            </div>
                            <!-- action bar -->
                            <div class="bk-actions">
                                <button class="bk-btn bk-btn-outline"
                                    onclick='openReceiptModal(<?= htmlspecialchars($receiptData, ENT_QUOTES) ?>)'>
                                    <i class="fas fa-receipt"></i> รายละเอียด
                                </button>
                                <?php if ($b['status'] === 'Pending' && !$isOverdue): ?>
                                <a class="bk-btn bk-btn-pay"
                                    href="<?= p('payment', ['booking_id' => (int)$b['booking_id']]) ?>">
                                    <i class="fas fa-credit-card"></i> ชำระเงิน
                                </a>
                                <?php elseif ($b['status'] === 'Rejected'): ?>
                                <a class="bk-btn bk-btn-pay"
                                    href="<?= p('payment', ['booking_id' => (int)$b['booking_id']]) ?>"
                                    style="background:#B71C1C;">
                                    <i class="fas fa-redo"></i> ส่งสลิปใหม่
                                </a>
                                <?php elseif ($b['status'] === 'Completed' && empty($b['has_reviewed'])): ?>
                                <button class="bk-btn bk-btn-review"
                                    onclick="openReviewModal(<?= (int)$b['booking_id'] ?>, '<?= htmlspecialchars(addslashes($b['activity_name'])) ?>')">
                                    <i class="fas fa-star"></i> รีวิว
                                </button>
                                <?php elseif ($b['status'] === 'Completed'): ?>
                                <button class="bk-btn bk-btn-reviewed" type="button" disabled>
                                    <i class="fas fa-check-circle"></i> รีวิวแล้ว
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div><!-- /booking-grid -->
                    <?php endif; ?>
                </div>

                <!-- ── TAB: WISHLIST ─────────────────────────────────────────────────────── -->
                <?php elseif ($tab === 'wishlist'): ?>
                <div class="tab-content">
                    <?php if (empty($wishlists)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">💚</div>
                        <p>ยังไม่มีกิจกรรมในวิชลิสต์<br><small style="font-size:0.82rem;">กดไอคอนหัวใจ ❤
                                บนหน้ากิจกรรมเพื่อบันทึก</small></p>
                        <a href="/tkn/activities" class="cta-btn">ค้นหากิจกรรม</a>
                    </div>
                    <?php else: ?>
                    <div class="wishlist-grid">
                        <?php foreach ($wishlists as $w): ?>
                        <div class="wishlist-card" id="wcard-<?= $w['activity_id'] ?>">
                            <!-- รูปภาพ -->
                            <div class="wishlist-img"
                                style="<?= $w['shop_picture'] ? "background-image:url('" . htmlspecialchars($w['shop_picture']) . "')" : '' ?>">
                                <?php if (!$w['shop_picture']): ?>
                                <svg viewBox="0 0 24 24" fill="#555">
                                    <path
                                        d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                                </svg>
                                <?php endif; ?>
                                <!-- ปุ่มลบ wishlist -->
                                <button class="wl-remove-btn" onclick="removeWishlist(<?= $w['activity_id'] ?>, this)"
                                    title="ลบออกจาก Wishlist">
                                    <i class="fas fa-heart"></i>
                                </button>
                            </div>
                            <!-- ข้อมูล -->
                            <div class="wishlist-info">
                                <h3>
                                    <a href="<?= p('booking', ['id' => $w['activity_id']]) ?>">
                                        <?= htmlspecialchars($w['activity_name']) ?>
                                    </a>
                                </h3>
                                <div class="wl-meta">
                                    <?php if ($w['duration_label']): ?>
                                    <?php
                                        $wdl = $w['duration_label'];
                                        $wdl_map = [
                                            '1 Hour'   => '1 ชม.',
                                            '1 Hours'  => '1 ชม.',
                                            '2 Hours'  => '2 ชม.',
                                            '3 Hours'  => '3 ชม.',
                                            '4 Hours'  => '4 ชม.',
                                            '5 Hours'  => '5 ชม.',
                                            '6 Hours'  => '6 ชม.',
                                            'Half Day' => 'ครึ่งวัน',
                                            'Full Day' => 'เต็มวัน',
                                        ];
                                        if (isset($wdl_map[$wdl])) {
                                            $wdl_display = $wdl_map[$wdl];
                                        } elseif (is_numeric($wdl) && (int)$wdl > 0) {
                                            $wdl_display = (int)$wdl . ' ชม.';
                                        } else {
                                            $wdl_display = $wdl;
                                        }
                                    ?>
                                    <span><i class="fas fa-clock"></i>
                                        <?= htmlspecialchars($wdl_display) ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($w['shop_name']) ?>
                                        <?php if ($w['district']): ?>,
                                        <?= htmlspecialchars($w['district']) ?><?php endif; ?>
                                    </span>
                                    <?php if ($w['suitable_for']): ?>
                                    <span><i class="fas fa-users"></i>
                                        <?= htmlspecialchars($w['suitable_for']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="wl-footer">
                                    <span class="wl-price">฿<?= number_format((float)$w['adult_price'], 0) ?> /
                                        คน</span>
                                    <a href="<?= p('booking', ['id' => $w['activity_id']]) ?>"
                                        class="wl-book-btn">จองเลย</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ── TAB: PASSPORT ─────────────────────────────────────────────────────── -->
                <?php elseif ($tab === 'passport'): ?>
                <div class="tab-content passport-content">

                    <?php
        // spread 0 = cover (ซ้าย) + catalog (ขวา)
        // spread 1+ = หน้าละ 6 แสตมป์ (3×2 grid) → 12 ดวงต่อ spread
        // 11 ดวงพอดีใน 1 stamp spread (ซ้าย 6 + ขวา 5)
        $stamps_per_page   = 6;
        $stamps_per_spread = $stamps_per_page * 2; // 12
        $stamp_spreads = array_chunk($all_cats, $stamps_per_spread);
        $total_spreads = 1 + count($stamp_spreads);
        ?>

                    <!-- Toolbar: color picker + export -->
                    <div class="passport-toolbar">
                        <div class="passport-color-bar">
                            <span class="pcb-label">สีเล่มพาสปอร์ต</span>
                            <div class="pcb-swatches">
                                <button class="pcb-swatch active" data-color="#7a9e7e" style="background:#7a9e7e;"
                                    title="เขียวเซจ"></button>
                                <button class="pcb-swatch" data-color="#7e9ec2" style="background:#7e9ec2;"
                                    title="ฟ้าสตีล"></button>
                                <button class="pcb-swatch" data-color="#c28e8e" style="background:#c28e8e;"
                                    title="ชมพูโอลด์โรส"></button>
                                <button class="pcb-swatch" data-color="#b5a07a" style="background:#b5a07a;"
                                    title="แทนทราย"></button>
                                <button class="pcb-swatch" data-color="#a08ec2" style="background:#a08ec2;"
                                    title="ม่วงลาเวนเดอร์"></button>
                                <button class="pcb-swatch" data-color="#7eaeb5" style="background:#7eaeb5;"
                                    title="เขียวน้ำ"></button>
                                <button class="pcb-swatch" data-color="#a0a0a0" style="background:#a0a0a0;"
                                    title="เทาเงิน"></button>
                            </div>
                        </div>
                        <button class="pcb-export-btn" id="btnExportJpg">
                            <i class="fas fa-image"></i> Export as JPG
                        </button>
                    </div>

                    <!-- Book shell -->
                    <div class="flipbook-shell" id="passportShell">

                        <!-- Prev / Next arrows -->
                        <button class="flipbook-arrow arrow-prev" id="btnPrev" onclick="flipbookNav(-1)"
                            disabled>&#8249;</button>
                        <button class="flipbook-arrow arrow-next" id="btnNext" onclick="flipbookNav(1)">&#8250;</button>

                        <!-- Book container with 3D perspective -->
                        <div class="flipbook" id="flipbook">

                            <!-- ── Spread 0: Cover + Catalog ── -->
                            <div class="book-spread active" data-spread="0">
                                <!-- Left: Cover -->
                                <div class="book-page page-left">
                                    <div class="page-inner">
                                        <div class="cover-content">
                                            <div class="cover-passport-label">PASSPORT</div>
                                            <div class="cover-avatar-wrap">
                                                <?php if (!empty($user['profile_pic'])): ?>
                                                <img src="<?= avatarSrc($user['profile_pic']) ?>" alt="avatar"
                                                    class="cover-avatar-img">
                                                <?php else: ?>
                                                <div class="cover-avatar-initial">
                                                    <?= mb_strtoupper(mb_substr($user['fullname'],0,1,'UTF-8'),'UTF-8') ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="cover-name"><?= htmlspecialchars($user['fullname']) ?></div>
                                            <div class="cover-username">@<?= htmlspecialchars($user['username']) ?>
                                            </div>
                                            <div class="cover-stamp-badge">
                                                <span class="csb-num"><?= $total_stamps ?></span>
                                                <span class="csb-label">แสตมป์สะสม</span>
                                            </div>
                                            <div class="cover-sub">
                                                ปลดล็อกแล้ว <?= count($earned_ids) ?> จาก <?= count($all_cats) ?> หมวดหมู่
                                            </div>
                                        </div>
                                        <div class="page-number">1</div>
                                    </div>
                                </div>
                                <!-- Right: Catalog -->
                                <div class="book-page page-right">
                                    <div class="page-inner">
                                        <div class="catalog-title">แสตมป์ทั้งหมด</div>
                                        <div class="stamp-catalog-grid">
                                            <?php foreach ($all_cats as $cat):
                                            $category_id = (int)$cat['shop_category_id'];
                                            $stamp_count = (int)($earned_by_category[$category_id]['stamp_count'] ?? 0);
                                            $earned = $stamp_count > 0; ?>
                                            <div class="catalog-item <?= $earned ? 'cat-earned' : 'cat-empty' ?>">
                                                <span
                                                    class="catalog-icon"><?= $cat_icons[$cat['shop_category_id']] ?? '🌿' ?></span>
                                                <span
                                                    class="catalog-name"><?= htmlspecialchars($cat['category_name']) ?></span>
                                                <?php if ($earned): ?>
                                                <span class="catalog-count"><?= $stamp_count ?> ดวง</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="page-number">2</div>
                                    </div>
                                </div>
                            </div>

                            <!-- ── Stamp spreads ── -->
                            <?php foreach ($stamp_spreads as $si => $spread):
                            $spreadIndex  = $si + 1;
                            $left_stamps  = array_slice($spread, 0, 6);
                            $right_stamps = array_slice($spread, 6);
                        ?>
                            <div class="book-spread" data-spread="<?= $spreadIndex ?>">
                                <!-- Left page: stamps 1-6 -->
                                <div class="book-page page-left">
                                    <div class="page-inner">
                                        <div class="page-stamp-grid">
                                            <?php foreach ($left_stamps as $cat):
                                            $category_id = (int)$cat['shop_category_id'];
                                            $stamp_count = (int)($earned_by_category[$category_id]['stamp_count'] ?? 0);
                                            $earned = $stamp_count > 0;
                                            $loyalty_level = intdiv($stamp_count, $repeat_reward_target);
                                            $next_reward_remaining = $repeat_reward_target - ($stamp_count % $repeat_reward_target);
                                            if ($next_reward_remaining === $repeat_reward_target && $stamp_count > 0) {
                                                $next_reward_remaining = $repeat_reward_target;
                                            } ?>
                                            <div class="stamp <?= $earned ? 'earned' : 'empty' ?>">
                                                <div class="stamp-inner">
                                                    <?php if ($earned): ?>
                                                    <span class="stamp-count-badge"><?= $stamp_count ?> ดวง</span>
                                                    <span
                                                        class="stamp-icon"><?= $cat_icons[$cat['shop_category_id']] ?? '🌿' ?></span>
                                                    <span
                                                        class="stamp-name"><?= htmlspecialchars($cat['category_name']) ?></span>
                                                    <?php if ($loyalty_level > 0): ?>
                                                    <span class="stamp-loyalty">ลูกค้าประจำ ระดับ <?= $loyalty_level ?></span>
                                                    <?php else: ?>
                                                    <span class="stamp-progress">อีก <?= $next_reward_remaining ?> ดวง ครบเงื่อนไข</span>
                                                    <?php endif; ?>
                                                    <?php else: ?>
                                                    <span class="stamp-empty-icon">?</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="page-number"><?= $spreadIndex * 2 + 1 ?></div>
                                    </div>
                                </div>
                                <!-- Right page: stamps 7-12 -->
                                <div class="book-page page-right">
                                    <div class="page-inner">
                                        <div class="page-stamp-grid">
                                            <?php foreach ($right_stamps as $cat):
                                            $category_id = (int)$cat['shop_category_id'];
                                            $stamp_count = (int)($earned_by_category[$category_id]['stamp_count'] ?? 0);
                                            $earned = $stamp_count > 0;
                                            $loyalty_level = intdiv($stamp_count, $repeat_reward_target);
                                            $next_reward_remaining = $repeat_reward_target - ($stamp_count % $repeat_reward_target);
                                            if ($next_reward_remaining === $repeat_reward_target && $stamp_count > 0) {
                                                $next_reward_remaining = $repeat_reward_target;
                                            } ?>
                                            <div class="stamp <?= $earned ? 'earned' : 'empty' ?>">
                                                <div class="stamp-inner">
                                                    <?php if ($earned): ?>
                                                    <span class="stamp-count-badge"><?= $stamp_count ?> ดวง</span>
                                                    <span
                                                        class="stamp-icon"><?= $cat_icons[$cat['shop_category_id']] ?? '🌿' ?></span>
                                                    <span
                                                        class="stamp-name"><?= htmlspecialchars($cat['category_name']) ?></span>
                                                    <?php if ($loyalty_level > 0): ?>
                                                    <span class="stamp-loyalty">ลูกค้าประจำ ระดับ <?= $loyalty_level ?></span>
                                                    <?php else: ?>
                                                    <span class="stamp-progress">อีก <?= $next_reward_remaining ?> ดวง ครบเงื่อนไข</span>
                                                    <?php endif; ?>
                                                    <?php else: ?>
                                                    <span class="stamp-empty-icon">?</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="page-number"><?= $spreadIndex * 2 + 2 ?></div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                        </div><!-- /flipbook -->

                        <!-- Page indicator -->
                        <div class="flipbook-indicator">
                            <span id="spreadCurrent">1</span> / <span id="spreadTotal"><?= $total_spreads ?></span>
                        </div>

                    </div><!-- /flipbook-shell -->

                    <!-- Dedicated portrait artwork used only for JPG export -->
                    <div class="passport-export-poster" id="passportExportPoster" aria-hidden="true">
                        <div class="pep-paper">
                            <div class="pep-postmarks">
                                <div class="pep-postmark-round">
                                    <div class="pep-postmark-ring">
                                        <span class="pep-postmark-top">TEAWKANNA</span>
                                        <span class="pep-postmark-leaf">❧</span>
                                        <span class="pep-postmark-center">เที่ยว<br>ชุมชน</span>
                                        <span class="pep-postmark-date">CHONBURI · TH</span>
                                    </div>
                                </div>
                                <div class="pep-postmark-lines">
                                    <span></span><span></span><span></span><span></span>
                                </div>
                                <div class="pep-postmark-badge">
                                    <span class="pep-postmark-cup">♨</span>
                                    <strong>FARM &amp; CAFE</strong>
                                    <small>LOCAL EXPERIENCE</small>
                                </div>
                            </div>

                            <header class="pep-header">
                                <div class="pep-kicker">COMMUNITY</div>
                                <div class="pep-title">PASSPORT</div>
                                <div class="pep-subtitle">สมุดสะสมแสตมป์ชุมชน</div>
                            </header>

                            <section class="pep-hero">
                                <div class="pep-polaroid">
                                    <?php if (!empty($user['profile_pic'])): ?>
                                    <div class="pep-photo"
                                        style="background-image:url('<?= avatarSrc($user['profile_pic']) ?>')">
                                    </div>
                                    <?php else: ?>
                                    <div class="pep-photo">
                                        <div class="pep-photo-initial">
                                            <?= mb_strtoupper(mb_substr($user['fullname'], 0, 1, 'UTF-8'), 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="pep-person-name"><?= htmlspecialchars($user['fullname']) ?></div>
                                    <div class="pep-person-user">@<?= htmlspecialchars($user['username']) ?></div>
                                </div>

                                <div class="pep-summary">
                                    <div class="pep-summary-label">แสตมป์ที่สะสม</div>
                                    <div class="pep-summary-count">
                                        <strong><?= $total_stamps ?></strong>
                                        <span>จาก <?= $category_total ?><br>หมวดหมู่</span>
                                    </div>
                                    <div class="pep-summary-line"></div>
                                    <div class="pep-level">
                                        <span>ระดับนักสำรวจ</span>
                                        <strong><?= $explorer_level ?></strong>
                                    </div>
                                </div>
                            </section>

                            <section class="pep-progress-section">
                                <div class="pep-first-stamp">
                                    <div class="pep-section-label">แสตมป์แรกที่ได้รับ</div>
                                    <?php if ($first_stamp): ?>
                                    <div class="pep-first-row">
                                        <div class="pep-first-icon">
                                            <?= $cat_icons[(int)$first_stamp['shop_category_id']] ?? '🌿' ?>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($first_stamp['category_name']) ?></strong>
                                            <span>เริ่มต้นการเดินทาง<br>ในชุมชนแล้ว</span>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="pep-first-empty">ออกเดินทางเพื่อรับแสตมป์ดวงแรก</div>
                                    <?php endif; ?>
                                </div>

                                <div class="pep-progress">
                                    <div class="pep-section-label">ความคืบหน้า</div>
                                    <div class="pep-progress-count">
                                        <strong><?= $unlocked_count ?> / <?= $category_total ?></strong>
                                        <span>หมวดหมู่</span>
                                    </div>
                                    <div class="pep-progress-track">
                                        <span style="width:<?= $progress_percent ?>%"></span>
                                    </div>
                                    <div class="pep-progress-note">
                                        <?= $remaining_categories > 0
                                            ? "อีก {$remaining_categories} หมวดหมู่ รอให้คุณไปค้นพบ!"
                                            : 'เยี่ยมมาก! คุณปลดล็อกครบทุกหมวดหมู่แล้ว' ?>
                                    </div>
                                </div>
                            </section>

                            <section class="pep-stamps-section">
                                <div class="pep-section-label">แสตมป์สะสม</div>
                                <div class="pep-stamp-grid">
                                    <?php foreach ($all_cats as $cat):
                                        $category_id = (int)$cat['shop_category_id'];
                                        $stamp_count = (int)($earned_by_category[$category_id]['stamp_count'] ?? 0);
                                        $is_earned = $stamp_count > 0;
                                    ?>
                                    <div class="pep-stamp-item <?= $is_earned ? 'is-earned' : 'is-locked' ?>">
                                        <div class="pep-stamp-circle">
                                            <span class="pep-stamp-emoji"><?= $cat_icons[$category_id] ?? '🌿' ?></span>
                                            <span class="pep-stamp-state">
                                                <i class="fas <?= $is_earned ? 'fa-check' : 'fa-lock' ?>"></i>
                                            </span>
                                            <?php if ($stamp_count > 1): ?>
                                            <span class="pep-stamp-times">×<?= $stamp_count ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="pep-stamp-name"><?= htmlspecialchars($cat['category_name']) ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>

                            <footer class="pep-footer">
                                <div>PROPERTY OF<br><strong>TEAWKANNA</strong></div>
                                <div class="pep-footer-quote">KEEP EXPLORING,<br>KEEP GROWING. ❧</div>
                            </footer>
                        </div>
                    </div>

                    <script>
                    var currentSpread = 0;
                    var totalSpreads = <?= $total_spreads ?>;
                    var isFlipping = false;
                    // mobile single-page: track ว่ากำลังแสดงหน้าขวาอยู่ไหม
                    var mobileShowingRight = false;

                    function isMobile() {
                        return window.innerWidth <= 900;
                    }

                    function updateIndicator() {
                        if (isMobile()) {
                            // mobile: แต่ละ spread = 2 "หน้า" → แสดงเป็น sub-page
                            var totalPages = totalSpreads * 2;
                            var currentPage = currentSpread * 2 + (mobileShowingRight ? 2 : 1);
                            document.getElementById('spreadCurrent').textContent = currentPage;
                            document.getElementById('spreadTotal').textContent = totalPages;
                        } else {
                            document.getElementById('spreadCurrent').textContent = currentSpread + 1;
                            document.getElementById('spreadTotal').textContent = totalSpreads;
                        }
                    }

                    function updateArrows() {
                        var spreads = document.querySelectorAll('.book-spread');
                        var currEl = spreads[currentSpread];
                        if (isMobile()) {
                            var atFirst = currentSpread === 0 && !mobileShowingRight;
                            var atLast = currentSpread === totalSpreads - 1 && mobileShowingRight;
                            document.getElementById('btnPrev').disabled = atFirst;
                            document.getElementById('btnNext').disabled = atLast;
                        } else {
                            document.getElementById('btnPrev').disabled = currentSpread === 0;
                            document.getElementById('btnNext').disabled = currentSpread === totalSpreads - 1;
                        }
                    }

                    function flipbookNav(dir) {
                        if (isFlipping) return;

                        // ── Mobile: ทีละ 1 หน้า ──────────────────────────────
                        if (isMobile()) {
                            var spreads = document.querySelectorAll('.book-spread');
                            var currEl = spreads[currentSpread];

                            if (dir > 0) {
                                // กด next
                                if (!mobileShowingRight) {
                                    // ซ้าย → ขวา (ใน spread เดิม)
                                    mobileShowingRight = true;
                                    currEl.classList.add('show-right');
                                } else {
                                    // ขวา → spread ถัดไป หน้าซ้าย
                                    if (currentSpread >= totalSpreads - 1) return;
                                    currEl.classList.remove('active', 'show-right');
                                    currentSpread++;
                                    mobileShowingRight = false;
                                    spreads[currentSpread].classList.add('active');
                                    spreads[currentSpread].classList.remove('show-right');
                                }
                            } else {
                                // กด prev
                                if (mobileShowingRight) {
                                    // ขวา → ซ้าย (ใน spread เดิม)
                                    mobileShowingRight = false;
                                    currEl.classList.remove('show-right');
                                } else {
                                    // ซ้าย → spread ก่อนหน้า หน้าขวา
                                    if (currentSpread <= 0) return;
                                    currEl.classList.remove('active');
                                    currentSpread--;
                                    mobileShowingRight = true;
                                    spreads[currentSpread].classList.add('active', 'show-right');
                                }
                            }
                            updateIndicator();
                            updateArrows();
                            return;
                        }

                        // ── Desktop: flip animation เดิม ─────────────────────
                        var nextIndex = currentSpread + dir;
                        if (nextIndex < 0 || nextIndex >= totalSpreads) return;

                        isFlipping = true;

                        var spreads = document.querySelectorAll('.book-spread');
                        var flipbook = document.getElementById('flipbook');
                        var currEl = spreads[currentSpread];
                        var nextEl = spreads[nextIndex];

                        nextEl.style.cssText = 'display:flex;position:absolute;top:0;left:0;width:100%;z-index:1;';
                        currEl.style.position = 'relative';
                        currEl.style.zIndex = '2';

                        var frontSrc = dir > 0 ?
                            currEl.querySelector('.page-right') :
                            currEl.querySelector('.page-left');
                        var backSrc = dir > 0 ?
                            nextEl.querySelector('.page-left') :
                            nextEl.querySelector('.page-right');

                        var leaf = document.createElement('div');
                        leaf.className = 'flip-leaf ' + (dir > 0 ? 'dir-forward' : 'dir-backward');

                        var front = document.createElement('div');
                        front.className = 'flip-face front-face';
                        front.appendChild(frontSrc.cloneNode(true));

                        var back = document.createElement('div');
                        back.className = 'flip-face back-face';
                        var backClone = backSrc.cloneNode(true);
                        backClone.style.transform = 'scaleX(-1)';
                        back.appendChild(backClone);

                        leaf.appendChild(front);
                        leaf.appendChild(back);
                        flipbook.appendChild(leaf);

                        leaf.getBoundingClientRect();
                        requestAnimationFrame(function() {
                            leaf.style.transform = dir > 0 ? 'rotateY(-180deg)' : 'rotateY(180deg)';
                        });

                        setTimeout(function() {
                            currEl.classList.remove('active');
                            currEl.style.cssText = '';
                            currentSpread = nextIndex;
                            nextEl.classList.add('active');
                            nextEl.style.cssText = '';
                            updateIndicator();
                            updateArrows();
                        }, 280);

                        setTimeout(function() {
                            leaf.remove();
                            isFlipping = false;
                        }, 580);
                    }

                    // init arrows/indicator
                    updateArrows();
                    updateIndicator();
                    </script>

                    <script>
                    // ── Color picker ───────────────────────────────────────────
                    (function() {
                        var shell = document.getElementById('passportShell');
                        var swatches = document.querySelectorAll('.pcb-swatch');
                        var STORAGE_KEY = 'passport_color';

                        function applyColor(hex) {
                            shell.style.setProperty('--passport-color', hex);
                            document.querySelectorAll('.book-spread[data-spread="0"] .page-left').forEach(function(
                                el) {
                                el.style.background = 'linear-gradient(135deg, ' + hex + ' 0%, ' + hex +
                                    'cc 100%)';
                            });
                            try {
                                localStorage.setItem(STORAGE_KEY, hex);
                            } catch (e) {}
                        }

                        swatches.forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                swatches.forEach(function(b) {
                                    b.classList.remove('active');
                                });
                                btn.classList.add('active');
                                applyColor(btn.dataset.color);
                            });
                        });

                        var saved;
                        try {
                            saved = localStorage.getItem(STORAGE_KEY);
                        } catch (e) {}
                        if (saved) {
                            swatches.forEach(function(b) {
                                b.classList.remove('active');
                            });
                            var match = document.querySelector('.pcb-swatch[data-color="' + saved + '"]');
                            if (match) match.classList.add('active');
                            applyColor(saved);
                        } else {
                            applyColor('#7a9e7e');
                        }
                    })();

                    // ── Export dedicated portrait passport artwork as JPG ─────
                    document.getElementById('btnExportJpg').addEventListener('click', function() {
                        var btn = this;
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังสร้าง...';

                        function resetButton() {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="fas fa-image"></i> Export as JPG';
                        }

                        function loadHtml2Canvas() {
                            return new Promise(function(resolve, reject) {
                                if (window.html2canvas) {
                                    resolve();
                                    return;
                                }
                                var src =
                                    'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                                var existing = document.querySelector('script[src="' + src + '"]');
                                if (existing) {
                                    existing.addEventListener('load', resolve, {
                                        once: true
                                    });
                                    existing.addEventListener('error', reject, {
                                        once: true
                                    });
                                    return;
                                }
                                var script = document.createElement('script');
                                script.src = src;
                                script.onload = resolve;
                                script.onerror = reject;
                                document.head.appendChild(script);
                            });
                        }

                        loadHtml2Canvas().then(function() {
                            var poster = document.getElementById('passportExportPoster');
                            if (!poster) throw new Error('ไม่พบโปสเตอร์พาสปอร์ต');

                            var fontsReady = document.fonts && document.fonts.ready ?
                                document.fonts.ready : Promise.resolve();

                            return fontsReady.then(function() {
                                return window.html2canvas(poster, {
                                    width: 1080,
                                    height: 1620,
                                    scale: 1.5,
                                    useCORS: true,
                                    allowTaint: false,
                                    backgroundColor: '#0b481f',
                                    logging: false
                                });
                            }).then(function(canvas) {
                                var link = document.createElement('a');
                                link.download = 'community-passport-<?= preg_replace('/[^a-zA-Z0-9_-]+/', '-', $user['username']) ?>.jpg';
                                link.href = canvas.toDataURL('image/jpeg', 0.95);
                                document.body.appendChild(link);
                                link.click();
                                link.remove();
                            });
                        }).catch(function(error) {
                            console.error('Passport JPG export failed:', error);
                            alert('ไม่สามารถสร้างไฟล์ JPG ได้ กรุณาลองใหม่อีกครั้ง');
                        }).finally(function() {
                            resetButton();
                        });
                    });
                    </script>

                </div>
                <?php endif; ?>

        </main>

        <!-- ── Footer ──────────────────────────────────────────────────────────────── -->
        <footer class="footer">
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
                        <h4 class="footer-heading">เมนู</h4>
                        <ul>
                            <li><a href="<?= addLangParam('/tkn/home') ?>"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i>หน้าแรก</a></li>
                            <li><a href="<?= addLangParam('/tkn/activities') ?>"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i>กิจกรรม</a></li>
                            <li><a href="<?= addLangParam('/tkn/contact') ?>"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i>ติดต่อเรา</a></li>
                        </ul>
                    </div>
                    <div class="footer-social">
                        <h4 class="footer-heading">ช่องทางการติดต่อ</h4>
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
                    <p>© 2026 เที่ยวกันนา. สงวนลิขสิทธิ์ | <a href="<?= addLangParam('/tkn/contact') ?>"
                            style="color:#8ab49a;">นโยบายความเป็นส่วนตัว</a></p>
                </div>
            </div>
        </footer>

    </div><!-- /wrapper -->

    <a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener"
        class="responsive-footer-line-fab" title="ติดต่อเราผ่าน LINE">
        <i class="fab fa-line"></i>
    </a>

    <script>
    // User Dropdown
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

    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
    const nav = document.querySelector('.nav');
    if (mobileMenuToggle && nav) {
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
    }

    // ── Remove from wishlist (user profile page) ──────────────────────────────
    function removeWishlist(activityId, btn) {
        btn.disabled = true;
        const fd = new FormData();
        fd.append('activity_id', activityId);
        fetch('/tkn/api/wishlist_toggle.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'removed') {
                    const card = document.getElementById('wcard-' + activityId);
                    card.style.transition = 'opacity 0.3s, transform 0.3s';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        card.remove();
                    }, 300);
                }
            })
            .catch(() => {
                btn.disabled = false;
            });
    }
    // ── Review Modal ──────────────────────────────────────────────────────────
    let _reviewBookingId = null;

    function openReviewModal(bookingId, activityName) {
        _reviewBookingId = bookingId;
        document.getElementById('reviewModalTitle').textContent = '⭐ รีวิว — ' + activityName;
        document.getElementById('reviewComment').value = '';
        document.getElementById('reviewPublicToggle').checked = true;
        setStarRating(0);
        document.getElementById('reviewResult').textContent = '';
        document.getElementById('reviewSubmitBtn').disabled = false;
        document.getElementById('reviewSubmitBtn').textContent = 'ส่งรีวิว';
        document.getElementById('reviewModal').classList.add('open');
    }

    function closeReviewModal() {
        document.getElementById('reviewModal').classList.remove('open');
        _reviewBookingId = null;
    }

    let _selectedRating = 0;

    function setStarRating(val) {
        _selectedRating = val;
        document.querySelectorAll('.rv-star').forEach((s, i) => {
            s.classList.toggle('active', i < val);
        });
        document.getElementById('ratingHidden').value = val;
    }

    document.getElementById('reviewModal').addEventListener('click', function(e) {
        if (e.target === this) closeReviewModal();
    });

    async function submitReview() {
        if (!_selectedRating) {
            document.getElementById('reviewResult').textContent = 'กรุณาเลือกคะแนน';
            document.getElementById('reviewResult').style.color = '#c62828';
            return;
        }
        const btn = document.getElementById('reviewSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'กำลังส่ง...';

        const fd = new FormData();
        fd.append('booking_id', _reviewBookingId);
        fd.append('rating', _selectedRating);
        fd.append('comment', document.getElementById('reviewComment').value.trim());
        fd.append('is_public', document.getElementById('reviewPublicToggle').checked ? '1' : '0');

        try {
            const r = await fetch('/tkn/handlers/review_submit.php', {
                method: 'POST',
                body: fd
            });
            const d = await r.json();
            const el = document.getElementById('reviewResult');
            if (d.ok) {
                el.textContent = '✓ ' + d.msg;
                el.style.color = '#2d6a4f';
                setTimeout(() => window.location.reload(), 900);
            } else {
                el.textContent = '✗ ' + (d.msg || 'เกิดข้อผิดพลาด');
                el.style.color = '#c62828';
                btn.disabled = false;
                btn.textContent = 'ส่งรีวิว';
            }
        } catch (e) {
            document.getElementById('reviewResult').textContent = '✗ เชื่อมต่อไม่ได้';
            document.getElementById('reviewResult').style.color = '#c62828';
            btn.disabled = false;
            btn.textContent = 'ส่งรีวิว';
        }
    }
    </script>

    <!-- ── Receipt Modal ──────────────────────────────────────────────────────── -->
    <div id="receiptModal"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:20px;">
        <div
            style="background:#fff;border-radius:20px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.22);position:relative;overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">
            <!-- Header -->
            <div style="background:var(--green,#2b4218);padding:18px 22px 14px;flex-shrink:0;">
                <button onclick="closeReceiptModal()"
                    style="position:absolute;top:12px;right:14px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:17px;cursor:pointer;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;line-height:1;">✕</button>
                <div
                    style="font-family:'Kanit',sans-serif;font-size:11px;font-weight:700;letter-spacing:2px;color:rgba(255,248,203,.65);text-transform:uppercase;margin-bottom:3px;">
                    ใบยืนยันการจอง</div>
                <div id="rcptActivityName"
                    style="font-family:'Kanit',sans-serif;font-size:17px;font-weight:800;color:#fff;line-height:1.3;padding-right:30px;">
                </div>
            </div>
            <!-- Body (scrollable) -->
            <div style="overflow-y:auto;flex:1;padding:20px 22px;">
                <!-- Activity image -->
                <div id="rcptImgWrap"
                    style="border-radius:10px;overflow:hidden;margin-bottom:16px;height:140px;background:#e8f5e9 center/cover no-repeat;display:flex;align-items:center;justify-content:center;">
                    <div id="rcptImgPlaceholder"
                        style="display:flex;flex-direction:column;align-items:center;gap:6px;color:#a5d6a7;">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="#a5d6a7">
                            <path
                                d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                        </svg>
                    </div>
                </div>
                <!-- Info rows -->
                <div style="display:flex;flex-direction:column;gap:1px;margin-bottom:16px;">
                    <div class="rcpt-row">
                        <span class="rcpt-label"><i class="fas fa-hashtag"></i> เลขการจอง</span>
                        <span id="rcptBookingId" class="rcpt-val rcpt-mono"></span>
                    </div>
                    <div class="rcpt-row">
                        <span class="rcpt-label"><i class="fas fa-store"></i> ร้าน</span>
                        <span id="rcptShop" class="rcpt-val"></span>
                    </div>
                    <div class="rcpt-row">
                        <span class="rcpt-label"><i class="fas fa-map-marker-alt"></i> พื้นที่</span>
                        <span id="rcptDistrict" class="rcpt-val"></span>
                    </div>
                    <div class="rcpt-row">
                        <span class="rcpt-label"><i class="fas fa-calendar-alt"></i> วันที่จอง</span>
                        <span id="rcptDate" class="rcpt-val"></span>
                    </div>
                    <div class="rcpt-row" id="rcptTimeRow">
                        <span class="rcpt-label"><i class="fas fa-clock"></i> เวลา</span>
                        <span id="rcptTime" class="rcpt-val"></span>
                    </div>
                    <div class="rcpt-row" id="rcptDurationRow">
                        <span class="rcpt-label"><i class="fas fa-hourglass-half"></i> ระยะเวลา</span>
                        <span id="rcptDuration" class="rcpt-val"></span>
                    </div>
                </div>
                <!-- Price breakdown -->
                <div style="background:#f7f5f0;border-radius:12px;padding:14px 16px;margin-bottom:14px;">
                    <div
                        style="font-family:'Kanit',sans-serif;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#888;margin-bottom:10px;">
                        รายละเอียดราคา</div>
                    <div id="rcptAdultRow"
                        style="display:none;justify-content:space-between;align-items:center;margin-bottom:6px;font-family:'Kanit',sans-serif;font-size:13px;color:#555;">
                        <span id="rcptAdultLabel"></span>
                        <span id="rcptAdultAmt" style="font-weight:600;color:#1a1a1a;"></span>
                    </div>
                    <div id="rcptKidRow"
                        style="display:none;justify-content:space-between;align-items:center;margin-bottom:6px;font-family:'Kanit',sans-serif;font-size:13px;color:#555;">
                        <span id="rcptKidLabel"></span>
                        <span id="rcptKidAmt" style="font-weight:600;color:#1a1a1a;"></span>
                    </div>
                    <div style="border-top:1px dashed #ddd;margin:8px 0;"></div>
                    <div
                        style="display:flex;justify-content:space-between;align-items:center;font-family:'Kanit',sans-serif;">
                        <span style="font-size:14px;font-weight:700;color:#1a1a1a;">รวม</span>
                        <span id="rcptTotal" style="font-size:18px;font-weight:800;color:#2d6a4f;"></span>
                    </div>
                </div>
                <!-- Status -->
                <div style="display:flex;justify-content:center;">
                    <div id="rcptStatusBadge"></div>
                </div>
            </div>
        </div>
    </div>

    <style>
    .rcpt-row {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
        gap: 12px;
    }

    .rcpt-label {
        font-family: 'Kanit', sans-serif;
        font-size: 12px;
        font-weight: 600;
        color: #9CA3AF;
        letter-spacing: .4px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .rcpt-label i {
        width: 14px;
        text-align: center;
        color: #2d6a4f;
    }

    .rcpt-val {
        font-family: 'Kanit', sans-serif;
        font-size: 13px;
        font-weight: 600;
        color: #1F2937;
        text-align: right;
    }

    .rcpt-mono {
        font-size: 12px;
        color: #6B7280;
    }
    </style>

    <script>
    const _statusMap = {
        'Pending': {
            label: 'รอชำระเงิน',
            icon: '⏳',
            color: '#b45309',
            bg: '#fffbeb',
            border: '#fde68a'
        },
        'PendingReview': {
            label: 'รอการอนุมัติ',
            icon: '🔍',
            color: '#1d4ed8',
            bg: '#eff6ff',
            border: '#bfdbfe'
        },
        'Paid': {
            label: 'ชำระเสร็จสิ้น',
            icon: '✅',
            color: '#166534',
            bg: '#f0fdf4',
            border: '#bbf7d0'
        },
        'Completed': {
            label: 'กิจกรรมเสร็จสิ้น',
            icon: '🎉',
            color: '#5b21b6',
            bg: '#faf5ff',
            border: '#ddd6fe'
        },
        'Cancel': {
            label: 'ยกเลิก',
            icon: '✕',
            color: '#991b1b',
            bg: '#fff1f2',
            border: '#fecaca'
        },
        'Rejected': {
            label: 'สลิปถูกปฏิเสธ',
            icon: '✕',
            color: '#991b1b',
            bg: '#fff1f2',
            border: '#fecaca'
        },
    };

    function openReceiptModal(data) {
        // populate header
        document.getElementById('rcptActivityName').textContent = data.activity_name || '';

        // image — use background-image (same approach as wishlist cards)
        const imgWrap = document.getElementById('rcptImgWrap');
        const ph = document.getElementById('rcptImgPlaceholder');
        if (data.img) {
            imgWrap.style.backgroundImage = "url('" + data.img.replace(/'/g, "\\'") + "')";
            imgWrap.style.backgroundSize = 'cover';
            imgWrap.style.backgroundPosition = 'center';
            ph.style.display = 'none';
        } else {
            imgWrap.style.backgroundImage = 'none';
            ph.style.display = 'flex';
        }

        // info rows
        document.getElementById('rcptBookingId').textContent = '#' + String(data.booking_id).padStart(6, '0');
        document.getElementById('rcptShop').textContent = data.shop_name || '—';
        document.getElementById('rcptDistrict').textContent = data.district || '—';
        document.getElementById('rcptDate').textContent = data.booking_date || '—';

        const timeRow = document.getElementById('rcptTimeRow');
        if (data.activity_time) {
            document.getElementById('rcptTime').textContent = data.activity_time;
            timeRow.style.display = 'flex';
        } else {
            timeRow.style.display = 'none';
        }

        const durRow = document.getElementById('rcptDurationRow');
        if (data.duration_label && data.duration_label !== '0') {
            document.getElementById('rcptDuration').textContent = data.duration_label;
            durRow.style.display = 'flex';
        } else {
            durRow.style.display = 'none';
        }

        // price breakdown
        const adultRow = document.getElementById('rcptAdultRow');
        const kidRow = document.getElementById('rcptKidRow');
        if (data.adult_qty > 0) {
            document.getElementById('rcptAdultLabel').textContent = 'ผู้ใหญ่ ' + data.adult_qty + ' คน × ฿' + Number(
                data.adult_price).toLocaleString();
            document.getElementById('rcptAdultAmt').textContent = '฿' + (data.adult_qty * data.adult_price)
                .toLocaleString();
            adultRow.style.display = 'flex';
        } else {
            adultRow.style.display = 'none';
        }

        if (data.kid_qty > 0) {
            document.getElementById('rcptKidLabel').textContent = 'เด็ก ' + data.kid_qty + ' คน × ฿' + Number(data
                .kid_price).toLocaleString();
            document.getElementById('rcptKidAmt').textContent = '฿' + (data.kid_qty * data.kid_price).toLocaleString();
            kidRow.style.display = 'flex';
        } else {
            kidRow.style.display = 'none';
        }

        document.getElementById('rcptTotal').textContent = '฿' + Number(data.total_price).toLocaleString();

        // status banner
        const st = _statusMap[data.status] || {
            label: data.status,
            icon: '',
            color: '#555',
            bg: '#f5f5f5',
            border: '#e5e5e5'
        };
        var statusHtml = '<div style="display:flex;align-items:center;gap:8px;padding:10px 20px;background:' + st.bg +
            ';border:1.5px solid ' + st.border + ';border-radius:12px;font-family:Kanit,sans-serif;">' +
            '<span style="font-size:1.1rem;line-height:1;">' + st.icon + '</span>' +
            '<span style="font-size:0.92rem;font-weight:700;color:' + st.color + ';">' + st.label + '</span>' +
            '</div>';
        // แสดงเหตุผลที่ถูกปฏิเสธ (ถ้ามี)
        if (data.status === 'Rejected' && data.admin_note) {
            statusHtml +=
                '<div style="margin-top:8px;padding:10px 14px;background:#fff5f5;border:1.5px solid #fca5a5;border-radius:10px;font-family:Kanit,sans-serif;font-size:12px;color:#991b1b;">' +
                '<span style="font-weight:700;">เหตุผล: </span>' + data.admin_note + '</div>';
        }
        document.getElementById('rcptStatusBadge').innerHTML = statusHtml;

        // show modal
        document.getElementById('receiptModal').style.display = 'flex';
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').style.display = 'none';
    }

    document.getElementById('receiptModal').addEventListener('click', function(e) {
        if (e.target === this) closeReceiptModal();
    });
    </script>

    <!-- ── Review Modal ───────────────────────────────────────────────────────── -->
    <div id="reviewModal"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9900;align-items:center;justify-content:center;padding:20px;">
        <div
            style="background:#fff;border-radius:20px;padding:28px 24px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;">
            <button onclick="closeReviewModal()"
                style="position:absolute;top:14px;right:14px;background:none;border:none;font-size:20px;cursor:pointer;color:#888;line-height:1;">✕</button>
            <h3 id="reviewModalTitle"
                style="font-family:'Kanit',sans-serif;font-size:17px;font-weight:700;color:#1F2937;margin-bottom:20px;padding-right:24px;">
            </h3>

            <!-- Stars -->
            <div style="margin-bottom:16px;">
                <div
                    style="font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:#9CA3AF;margin-bottom:8px;font-family:'Kanit',sans-serif;">
                    คะแนน</div>
                <div style="display:flex;gap:6px;">
                    <?php for ($star = 1; $star <= 5; $star++): ?>
                    <span class="rv-star" data-val="<?= $star ?>" onclick="setStarRating(<?= $star ?>)"
                        onmouseover="hoverStars(<?= $star ?>)" onmouseout="unhoverStars()"
                        style="font-size:32px;cursor:pointer;color:#D1D5DB;transition:color .15s;user-select:none;">★</span>
                    <?php endfor; ?>
                </div>
                <input type="hidden" id="ratingHidden" value="0">
            </div>

            <!-- Comment -->
            <div style="margin-bottom:16px;">
                <label
                    style="display:block;font-size:11px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;color:#9CA3AF;margin-bottom:7px;font-family:'Kanit',sans-serif;">ความคิดเห็น
                    (ไม่บังคับ)</label>
                <textarea id="reviewComment" placeholder="เล่าประสบการณ์ของคุณ..." rows="3"
                    style="width:100%;padding:10px 13px;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:10px;font-family:'Kanit',sans-serif;font-size:14px;outline:none;resize:vertical;box-sizing:border-box;color:#1F2937;"
                    onfocus="this.style.borderColor='#2C5A22'" onblur="this.style.borderColor='#E5E7EB'"></textarea>
            </div>

            <!-- Public toggle -->
            <div style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                    <div style="position:relative;width:40px;height:22px;">
                        <input type="checkbox" id="reviewPublicToggle" checked
                            style="position:absolute;opacity:0;width:100%;height:100%;cursor:pointer;margin:0;z-index:1;"
                            onchange="updateToggleVisual(this)">
                        <div id="toggleTrack"
                            style="width:40px;height:22px;border-radius:999px;background:#2C5A22;transition:background .2s;">
                        </div>
                        <div id="toggleThumb"
                            style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .2s;">
                        </div>
                    </div>
                    <span style="font-size:13px;color:#374151;font-family:'Kanit',sans-serif;">
                        <span id="toggleLabel">แสดงรีวิวแบบสาธารณะ</span>
                    </span>
                </label>
            </div>

            <div id="reviewResult"
                style="font-size:13px;margin-bottom:10px;min-height:18px;font-family:'Kanit',sans-serif;"></div>

            <button id="reviewSubmitBtn" onclick="submitReview()"
                style="width:100%;padding:11px;background:#5b21b6;color:#fff;border:none;border-radius:12px;font-family:'Kanit',sans-serif;font-size:15px;font-weight:600;cursor:pointer;transition:background .2s;"
                onmouseover="this.style.background='#4c1d95'" onmouseout="this.style.background='#5b21b6'">
                ส่งรีวิว
            </button>
        </div>
    </div>

    <script>
    // Star hover helpers (must be outside DOMContentLoaded to be accessible inline)
    function hoverStars(val) {
        document.querySelectorAll('.rv-star').forEach((s, i) => {
            s.style.color = i < val ? '#F59E0B' : '#D1D5DB';
        });
    }

    function unhoverStars() {
        const sel = parseInt(document.getElementById('ratingHidden').value) || 0;
        document.querySelectorAll('.rv-star').forEach((s, i) => {
            s.style.color = i < sel ? '#F59E0B' : '#D1D5DB';
        });
    }
    // override setStarRating to also update color
    const _origSetStarRating = window.setStarRating;

    function setStarRating(val) {
        _selectedRating = val;
        document.getElementById('ratingHidden').value = val;
        document.querySelectorAll('.rv-star').forEach((s, i) => {
            s.classList.toggle('active', i < val);
            s.style.color = i < val ? '#F59E0B' : '#D1D5DB';
        });
    }

    function updateToggleVisual(cb) {
        const track = document.getElementById('toggleTrack');
        const thumb = document.getElementById('toggleThumb');
        const label = document.getElementById('toggleLabel');
        if (cb.checked) {
            track.style.background = '#2C5A22';
            thumb.style.left = '3px';
            label.textContent = 'แสดงรีวิวแบบสาธารณะ';
        } else {
            track.style.background = '#9CA3AF';
            thumb.style.left = '21px';
            label.textContent = 'รีวิวแบบส่วนตัว (เฉพาะคุณเห็น)';
        }
    }
    // make modal use flex when open
    document.getElementById('reviewModal').addEventListener('transitionend', function() {});
    const _rvModal = document.getElementById('reviewModal');
    const _rvObserver = new MutationObserver(() => {
        _rvModal.style.display = _rvModal.classList.contains('open') ? 'flex' : 'none';
    });
    _rvObserver.observe(_rvModal, {
        attributes: true,
        attributeFilter: ['class']
    });
    </script>
</body>

</html>
