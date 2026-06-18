<?php
session_start();
// ---- Auth Guard ----
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header("Location: /tkn/login"); exit();
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
        'nav_logout'        => 'Logout',
        'nav_login'         => 'Login',
        'logout_confirm'    => 'Do you want to logout?',
        'lang_switch_label' => 'TH',
        'lang_switch_href'  => addLangParam('/tkn/dashboard', 'th'),
        'html_lang'         => 'en',
    ];
} else {
    // Thai
    $t = [
        'nav_home'          => 'หน้าแรก',
        'nav_trips'         => 'กิจกรรม',
        'nav_contact'       => 'ติดต่อเรา',
        'nav_logout'        => 'ออกจากระบบ',
        'nav_login'         => 'เข้าสู่ระบบ',
        'logout_confirm'    => 'คุณต้องการออกจากระบบใช่หรือไม่?',
        'lang_switch_label' => 'EN',
        'lang_switch_href'  => addLangParam('/tkn/dashboard', 'en'),
        'html_lang'         => 'th',
    ];
}

$owner_id    = $_SESSION['user_id'];
$owner_name  = $_SESSION['fullname'] ?? 'ผู้ประกอบการ';

// ---- ดึง shop ของ owner นี้ ----
$shop_stmt = $conn->prepare("SELECT shop_id, shop_name, average_rating, total_reviews FROM shop WHERE owner_id = ? LIMIT 1");
$shop_stmt->bind_param("i", $owner_id);
$shop_stmt->execute();
$shop = $shop_stmt->get_result()->fetch_assoc();
$shop_stmt->close();

$shop_id = $shop['shop_id'] ?? null;

// ---- ยอดจองใหม่ (Pending วันนี้) ----
$new_bookings = 0;
$daily_revenue = 0;
$avg_rating = 0;
$total_reviews = 0;

if ($shop_id) {
    // จองใหม่วันนี้ — นับจาก payment ที่ admin Approved วันนี้
    // (booking_date เก็บวันเดินทาง ไม่ใช่วันที่จอง ต้องใช้ payment_date แทน)
    $today = date('Y-m-d');
    $bk_stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT b.booking_id) as cnt
         FROM booking b
         JOIN activity a ON b.activity_id = a.activity_id
         JOIN payment p ON p.booking_id = b.booking_id
         WHERE a.shop_id = ? AND p.status = 'Approved' AND DATE(p.payment_date) = ?"
    );
    $bk_stmt->bind_param("is", $shop_id, $today);
    $bk_stmt->execute();
    $new_bookings = (int)($bk_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $bk_stmt->close();

    // รายได้วันนี้ — นับจาก payment ที่ Approved วันนี้ (payment.payment_date)
    $rev_stmt = $conn->prepare(
        "SELECT COALESCE(SUM(b.total_price), 0) AS total
          FROM booking b
          JOIN activity a ON b.activity_id = a.activity_id
          JOIN payment p ON p.booking_id = b.booking_id
          WHERE a.shop_id = ? AND p.status = 'Approved' AND DATE(p.payment_date) = ?"
    );
    $rev_stmt->bind_param("is", $shop_id, $today);
    $rev_stmt->execute();
    $daily_revenue = (float)($rev_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $rev_stmt->close();

    // รีวิวเฉลี่ย
    $avg_rating    = $shop['average_rating']  ?? 0;
    $total_reviews = $shop['total_reviews']   ?? 0;
    if (!$avg_rating) {
        $rv_stmt = $conn->prepare(
            "SELECT ROUND(AVG(r.rating),1) as avg_r, COUNT(*) as cnt FROM review r
             JOIN activity a ON r.activity_id = a.activity_id
             WHERE a.shop_id = ?"
        );
        $rv_stmt->bind_param("i", $shop_id);
        $rv_stmt->execute();
        $rv_row = $rv_stmt->get_result()->fetch_assoc();
        $rv_stmt->close();
        $avg_rating    = $rv_row['avg_r'] ?? 0;
        $total_reviews = $rv_row['cnt']   ?? 0;
    }

    // ---- Notifications: การจองที่ admin อนุมัติแล้ว (Paid) เท่านั้น ----
    $notif_stmt = $conn->prepare(
        "SELECT b.booking_id, u.fullname, a.activity_name, b.booking_date
         FROM booking b
         JOIN activity a ON b.activity_id = a.activity_id
         JOIN user u ON b.user_id = u.user_id
         WHERE a.shop_id = ? AND b.status = 'Paid'
         ORDER BY b.booking_date DESC LIMIT 5"
    );
    $notif_stmt->bind_param("i", $shop_id);
    $notif_stmt->execute();
    $notif_bookings = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $notif_stmt->close();

    $comp_stmt = $conn->prepare(
        "SELECT c.complaint_id, c.topic, u.fullname FROM complaint c
         JOIN user u ON c.user_id = u.user_id
         WHERE c.status = 'Pending' LIMIT 5"
    );
    $comp_stmt->execute();
    $notif_complaints = $comp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $comp_stmt->close();

    $total_notifs = count($notif_bookings) + count($notif_complaints);

    // ---- กิจกรรมที่ถูกจอง (upcoming) — เฉพาะ Paid ----
    $events_stmt = $conn->prepare(
        "SELECT a.activity_name, b.booking_date,
                SUM(b.adult_quantity + b.kid_quantity) as total_pax,
                COUNT(b.booking_id) as booking_count
         FROM booking b
         JOIN activity a ON b.activity_id = a.activity_id
         WHERE a.shop_id = ? AND b.status = 'Paid'
           AND DATE(b.booking_date) >= CURDATE()
         GROUP BY a.activity_id, DATE(b.booking_date)
         ORDER BY b.booking_date ASC LIMIT 5"
    );
    $events_stmt->bind_param("i", $shop_id);
    $events_stmt->execute();
    $upcoming_events = $events_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $events_stmt->close();

    // ---- วันที่กิจกรรมสำหรับปฏิทิน (Paid bookings ทั้งหมดตั้งแต่วันนี้ไป) ----
    $cal_stmt = $conn->prepare(
        "SELECT DISTINCT DATE(b.booking_date) AS bdate
         FROM booking b
         JOIN activity a ON b.activity_id = a.activity_id
         WHERE a.shop_id = ? AND b.status IN ('Paid','PendingReview') AND DATE(b.booking_date) >= CURDATE()
         ORDER BY bdate ASC"
    );
    $cal_stmt->bind_param("i", $shop_id);
    $cal_stmt->execute();
    $cal_rows = $cal_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cal_stmt->close();
    $calendar_dates = array_column($cal_rows, 'bdate'); // ['2026-05-09', ...]

    // ---- นับจำนวนกิจกรรม ----
    $act_count_stmt = $conn->prepare(
        "SELECT COUNT(*) as total,
                SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count
         FROM activity WHERE shop_id = ?"
    );
    $act_count_stmt->bind_param("i", $shop_id);
    $act_count_stmt->execute();
    $act_count_row   = $act_count_stmt->get_result()->fetch_assoc();
    $act_count_stmt->close();
    $total_activities  = (int)($act_count_row['total']        ?? 0);
    $active_activities = (int)($act_count_row['active_count'] ?? 0);

    // ---- กิจกรรมทั้งหมดของ shop ----
    $all_acts_stmt = $conn->prepare(
        "SELECT a.activity_id, a.activity_name, a.adult_price, a.kid_price,
                a.duration_label, a.max_capacity, a.capacity_remaining,
                a.status, a.activity_pic,
                COALESCE((
                    SELECT COUNT(DISTINCT b.booking_id)
                    FROM booking b
                    WHERE b.activity_id = a.activity_id AND b.status = 'Paid'
                ), 0) AS booking_count,
                COALESCE((
                    SELECT ROUND(AVG(r.rating), 1)
                    FROM review r
                    WHERE r.activity_id = a.activity_id
                ), 0) AS avg_rating
         FROM activity a
         WHERE a.shop_id = ?
         ORDER BY a.activity_id DESC"
    );
    $all_acts_stmt->bind_param("i", $shop_id);
    $all_acts_stmt->execute();
    $all_activities = $all_acts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $all_acts_stmt->close();

    // ---- จำนวนลูกค้า: เดือนนี้ vs เดือนที่แล้ว ----
    $thisMonth = date('Y-m');
    $lastMonth = date('Y-m', strtotime('-1 month'));

    $cust_stmt = $conn->prepare(
        "SELECT DATE_FORMAT(b.booking_date,'%Y-%m') as mon,
                COUNT(DISTINCT b.user_id) as customers
         FROM booking b
         JOIN activity a ON b.activity_id = a.activity_id
         WHERE a.shop_id = ? AND b.status IN ('Pending','Paid')
           AND DATE_FORMAT(b.booking_date,'%Y-%m') IN (?,?)
         GROUP BY mon"
    );
    $cust_stmt->bind_param("iss", $shop_id, $thisMonth, $lastMonth);
    $cust_stmt->execute();
    $cust_rows = $cust_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cust_stmt->close();

    $cust_this = 0; $cust_last = 0;
    foreach ($cust_rows as $r) {
        if ($r['mon'] === $thisMonth) $cust_this = (int)$r['customers'];
        if ($r['mon'] === $lastMonth) $cust_last = (int)$r['customers'];
    }
    $cust_diff = $cust_this - $cust_last;
    $cust_pct  = $cust_last > 0 ? round(abs($cust_diff)/$cust_last*100) : ($cust_this > 0 ? 100 : 0);

    // ---- ยอดการเข้าชม (views) รายสัปดาห์ในเดือนนี้ vs เดือนที่แล้ว ----
    // (ยังไม่มีตาราง views ใน DB — แสดง mockup 0 ไว้ก่อน)
    $visits_note = true;
}
?>
<!DOCTYPE html>
<html lang="<?= $t['html_lang'] ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ด - <?= htmlspecialchars($owner_name) ?></title>
    <link rel="stylesheet" href="/tkn/assets/css/ownerstyle.css">
    <link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notif-empty { text-align:center; padding:28px 16px; color:#888; font-size:13px; }
        .notif-empty svg { display:block; margin:0 auto 10px; opacity:.35; }
        .stat-change { font-size:12px; margin-top:4px; font-weight:500; }
        .stat-change.up   { color:#4caf50; }
        .stat-change.down { color:#e57373; }
        .stat-change.flat { color:#aaa; }
        .cust-compare { display:flex; gap:18px; margin-top:14px; }
        .cust-col { flex:1; background:rgba(255,255,255,.04); border-radius:10px; padding:14px; text-align:center; }
        .cust-col .cust-val { font-size:26px; font-weight:700; color:#F4D03F; }
        .cust-col .cust-lbl { font-size:11px; color:#aaa; margin-top:3px; }
        .cust-arrow { font-size:22px; display:flex; align-items:center; }
        .cust-arrow.up   { color:#4caf50; }
        .cust-arrow.down { color:#e57373; }
        .cust-arrow.flat { color:#aaa; }
        .visits-note { font-size:11px; color:#888; margin-top:8px; text-align:center; font-style:italic; }
        .events-empty { padding:28px; text-align:center; color:#888; font-size:13px; }
        .events-empty svg { display:block; margin:0 auto 10px; opacity:.35; }
        /* Calendar activity dot */
        .calendar-day.has-event { position:relative; }
        .calendar-day.has-event::after {
            content:'';
            position:absolute;
            bottom:3px; left:50%; transform:translateX(-50%);
            width:5px; height:5px;
            border-radius:50%;
            background:#F4D03F;
        }
    </style>
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
            <a href="/tkn/dashboard" class="nav-item active">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                <span>Dashboard</span>
            </a>
            <a href="/tkn/my-shop" class="nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                <span>Activity Management</span>
            </a>
            <a href="/tkn/booking-history" class="nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <span>Booking</span>
            </a>
            <a href="/tkn/billing" class="nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                <span>Billing</span>
            </a>
        </div>
        <div class="nav-section">
            <p class="nav-section-title">Tools</p>
            <a href="/tkn/owner-feedback" class="nav-item">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
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
            <!-- Notification Bell -->
            <div class="notification-wrapper">
                <button class="notification-btn" id="notificationBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <?php if ($total_notifs > 0): ?>
                    <span class="notification-badge"><?= $total_notifs ?></span>
                    <?php endif; ?>
                </button>

                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h3>การแจ้งเตือน</h3>
                    </div>
                    <div class="notification-list">
                        <?php if ($total_notifs === 0): ?>
                        <div class="notif-empty">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                            </svg>
                            ไม่มีการแจ้งเตือนใหม่
                        </div>
                        <?php else: ?>
                            <?php foreach ($notif_bookings as $nb): ?>
                            <div class="notification-item unread">
                                <div class="notification-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-title">การจองใหม่</p>
                                    <p class="notification-text">
                                        <?= htmlspecialchars($nb['fullname']) ?> จองกิจกรรม "<?= htmlspecialchars($nb['activity_name']) ?>"
                                    </p>
                                    <p class="notification-time"><?= date('d/m/Y H:i', strtotime($nb['booking_date'])) ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php foreach ($notif_complaints as $nc): ?>
                            <div class="notification-item unread">
                                <div class="notification-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                    </svg>
                                </div>
                                <div class="notification-content">
                                    <p class="notification-title">ข้อร้องเรียน</p>
                                    <p class="notification-text">
                                        <?= htmlspecialchars($nc['fullname']) ?>: <?= htmlspecialchars($nc['topic']) ?>
                                    </p>
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
                    <div class="user-avatar">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                    <span class="user-name">ผู้ประกอบการ<br><small><?= htmlspecialchars($owner_name) ?></small></span>
                    <svg class="dropdown-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div class="user-dropdown" id="userDropdown">
                    <a href="/tkn/shop" class="user-dropdown-item">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <span>Edit Profile</span>
                    </a>
                    <a href="/tkn/logout" class="user-dropdown-item">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Welcome Section -->
    <section class="welcome-section">
        <h1 class="welcome-title">สวัสดี, <?= htmlspecialchars($owner_name) ?>! </h1>
        <p class="welcome-text">
            ยินดีต้อนรับสู่แดชบอร์ดของ <strong><?= htmlspecialchars($shop['shop_name'] ?? 'ร้านของคุณ') ?></strong>
            — ดูสถิติ จัดการกิจกรรม และติดตามการจองของคุณได้ที่นี่
        </p>
    </section>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <!-- ยอดจองใหม่ -->
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <p class="stat-label">ยอดจองใหม่ (วันนี้)</p>
            <p class="stat-value"><?= $new_bookings ?></p>
            <?php if ($new_bookings === 0): ?>
            <p class="stat-change flat">ยังไม่มีการจองวันนี้</p>
            <?php else: ?>
            <p class="stat-change up">+<?= $new_bookings ?> รายการรอยืนยัน</p>
            <?php endif; ?>
        </div>

        <!-- รายได้ประจำวัน -->
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <p class="stat-label">รายได้วันนี้</p>
            <p class="stat-value">
                <?= number_format($daily_revenue) ?><span class="currency">฿</span>
            </p>
            <?php if ($daily_revenue == 0): ?>
            <p class="stat-change flat">รอการชำระเงิน</p>
            <?php else: ?>
            <p class="stat-change up">ยอดที่ได้รับแล้ววันนี้</p>
            <?php endif; ?>
        </div>

        <!-- รีวิวเฉลี่ย -->
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                </svg>
            </div>
            <p class="stat-label">รีวิวเฉลี่ย</p>
            <p class="stat-value">
                <?= $avg_rating > 0 ? number_format($avg_rating, 1) : '—' ?>
                <?php if ($avg_rating > 0): ?>
                <span class="stat-subtitle">จาก <?= $total_reviews ?> รีวิว</span>
                <?php endif; ?>
            </p>
            <?php if ($avg_rating == 0): ?>
            <p class="stat-change flat">ยังไม่มีรีวิว</p>
            <?php endif; ?>
        </div>

        <!-- จำนวนกิจกรรม -->
        <div class="stat-card">
            <div class="stat-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
            </div>
            <p class="stat-label">กิจกรรมทั้งหมด</p>
            <p class="stat-value"><?= $total_activities ?></p>
            <?php if ($total_activities === 0): ?>
            <p class="stat-change flat">ยังไม่มีกิจกรรม</p>
            <?php else: ?>
            <p class="stat-change <?= $active_activities > 0 ? 'up' : 'flat' ?>">
                เปิดให้จอง <?= $active_activities ?> / <?= $total_activities ?> กิจกรรม
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Calendar & Events -->
    <div class="calendar-events-section">
        <!-- Calendar (Mockup) -->
        <div class="calendar-container">
            <div class="calendar-header">
                <button class="calendar-nav-btn" id="prevMonth">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
                <h2 class="calendar-month" id="currentMonth">—</h2>
                <button class="calendar-nav-btn" id="nextMonth">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </div>
            <div class="calendar">
                <div class="calendar-weekdays">
                    <div class="weekday">อา</div><div class="weekday">จ</div><div class="weekday">อ</div>
                    <div class="weekday">พ</div><div class="weekday">พฤ</div><div class="weekday">ศ</div>
                    <div class="weekday">ส</div>
                </div>
                <div class="calendar-days" id="calendarDays"></div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:10px;justify-content:center;font-size:12px;color:#aaa;">
                <span style="width:8px;height:8px;border-radius:50%;background:#F4D03F;display:inline-block;"></span>
                <span>มีกิจกรรมที่ถูกจอง</span>
            </div>
        </div>

        <!-- Upcoming Events -->
        <div class="upcoming-events">
            <?php if (empty($upcoming_events)): ?>
            <div class="events-empty">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                ไม่มีกิจกรรมที่ถูกจองในขณะนี้
            </div>
            <?php else: ?>
                <?php foreach ($upcoming_events as $ev): ?>
                <div class="event-card">
                    <div class="event-date">
                        <span class="event-day"><?= date('d', strtotime($ev['booking_date'])) ?></span>
                        <span class="event-month"><?= date('M', strtotime($ev['booking_date'])) ?></span>
                    </div>
                    <div class="event-details">
                        <h3 class="event-title"><?= htmlspecialchars($ev['activity_name']) ?></h3>
                        <div class="event-info">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            <span><?= date('H:i', strtotime($ev['booking_date'])) ?> น.</span>
                        </div>
                        <div class="event-info">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                            <span><?= $ev['total_pax'] ?> คน (<?= $ev['booking_count'] ?> การจอง)</span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <!-- ยอดการเข้าถึง (Mockup) -->
        <div class="chart-container">
            <div class="chart-header">
                <h2 class="chart-title">ยอดการเข้าถึง</h2>
                <div class="month-selector" id="visitsMonthSelector">
                    <button class="month-selector-btn">
                        <span id="visitMonthLabel"><?= ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'][(int)date('n')-1] ?></span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="month-dropdown">
                        <?php $thaiM=['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
                        foreach($thaiM as $mi=>$mn): ?>
                        <button class="month-option" data-month="<?= $mi ?>"><?= $mn ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="chart-legend">
                <div class="legend-item"><span class="legend-dot" style="background:#F4D03F;"></span><span>ยอดเข้าชม</span></div>
                <div class="legend-item"><span class="legend-dot" style="background:#D4AF37;"></span><span>ยอดจอง</span></div>
            </div>
            <div class="bar-chart" id="visitsChart">
                <div class="chart-y-axis"><span>—</span><span>—</span><span>—</span><span>—</span><span>—</span><span>0</span></div>
                <div class="chart-bars">
                    <?php
                    $weeks = ['สัปดาห์ 1','สัปดาห์ 2','สัปดาห์ 3','สัปดาห์ 4'];
                    foreach($weeks as $w): ?>
                    <div class="bar-group">
                        <div class="bar-wrapper">
                            <div class="bar" style="height:5%;background:#F4D03F;" data-value="0"></div>
                            <div class="bar" style="height:5%;background:#D4AF37;" data-value="0"></div>
                        </div>
                        <span class="bar-label"><?= $w ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="visits-note">* ยอดเข้าชมจะเริ่มนับเมื่อมีการเพิ่มระบบติดตาม view</p>
        </div>

        <!-- จำนวนลูกค้า: เดือนนี้ vs เดือนที่แล้ว -->
        <div class="chart-container">
            <div class="chart-header">
                <h2 class="chart-title">จำนวนลูกค้า</h2>
            </div>
            <p style="font-size:12px;color:#aaa;margin-bottom:12px;">เปรียบเทียบจำนวนลูกค้าที่จองกิจกรรม</p>
            <div class="cust-compare">
                <div class="cust-col">
                    <div class="cust-val"><?= $cust_last ?></div>
                    <div class="cust-lbl">เดือนที่แล้ว<br><?= date('M Y', strtotime('-1 month')) ?></div>
                </div>
                <div class="cust-arrow <?= $cust_diff > 0 ? 'up' : ($cust_diff < 0 ? 'down' : 'flat') ?>">
                    <?php if ($cust_diff > 0): ?>▲
                    <?php elseif ($cust_diff < 0): ?>▼
                    <?php else: ?>→
                    <?php endif; ?>
                </div>
                <div class="cust-col">
                    <div class="cust-val"><?= $cust_this ?></div>
                    <div class="cust-lbl">เดือนนี้<br><?= date('M Y') ?></div>
                </div>
            </div>
            <?php if ($cust_last > 0 || $cust_this > 0): ?>
            <p class="stat-change <?= $cust_diff > 0 ? 'up' : ($cust_diff < 0 ? 'down' : 'flat') ?>" style="margin-top:14px;text-align:center;">
                <?php if ($cust_diff > 0): ?>
                    ▲ เพิ่มขึ้น <?= $cust_diff ?> คน (<?= $cust_pct ?>%) จากเดือนที่แล้ว
                <?php elseif ($cust_diff < 0): ?>
                    ▼ ลดลง <?= abs($cust_diff) ?> คน (<?= $cust_pct ?>%) จากเดือนที่แล้ว
                <?php else: ?>
                    → เท่าเดิมกับเดือนที่แล้ว
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p style="text-align:center;color:#888;font-size:13px;margin-top:14px;">ยังไม่มีข้อมูลลูกค้า</p>
            <?php endif; ?>
        </div>
    </div>
</main>

</div>

<script>
/* ============================================================
   Calendar
   ============================================================ */
let currentMonth = new Date().getMonth();
let currentYear  = new Date().getFullYear();

const thaiMonthNames = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
const monthNames     = ['January','February','March','April','May','June','July','August','September','October','November','December'];

// วันที่มีกิจกรรมถูกจอง (Paid) จาก DB
const calendarDates = new Set(<?= json_encode($calendar_dates ?? [], JSON_UNESCAPED_UNICODE) ?>);

function renderCalendar() {
    document.getElementById('currentMonth').textContent =
        thaiMonthNames[currentMonth] + ' ' + (currentYear + 543);
    const container = document.getElementById('calendarDays');
    container.innerHTML = '';
    const firstDay    = new Date(currentYear, currentMonth, 1).getDay();
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    for (let i = 0; i < firstDay; i++) {
        const e = document.createElement('div');
        e.className = 'calendar-day empty';
        container.appendChild(e);
    }
    const todayObj = new Date();
    for (let day = 1; day <= daysInMonth; day++) {
        const e = document.createElement('div');
        e.className = 'calendar-day';
        e.textContent = day;
        // แสดง dot ถ้าวันนั้นมีกิจกรรมถูกจอง
        const ds = `${currentYear}-${String(currentMonth+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
        if (calendarDates.has(ds)) e.classList.add('has-event');
        if (day === todayObj.getDate() && currentMonth === todayObj.getMonth() && currentYear === todayObj.getFullYear())
            e.classList.add('today');
        container.appendChild(e);
    }
}

document.getElementById('prevMonth').addEventListener('click', function() {
    currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; } renderCalendar();
});
document.getElementById('nextMonth').addEventListener('click', function() {
    currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; } renderCalendar();
});

/* ============================================================
   Notifications
   ============================================================ */
function initializeNotifications() {
    const btn      = document.getElementById('notificationBtn');
    const dropdown = document.getElementById('notificationDropdown');
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('active');
        document.getElementById('userDropdown').classList.remove('active');
        document.getElementById('userMenuBtn').classList.remove('active');
    });
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && e.target !== btn)
            dropdown.classList.remove('active');
    });
    // Mark as read on click
    document.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function() {
            this.classList.remove('unread');
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                const unread = document.querySelectorAll('.notification-item.unread').length;
                if (unread === 0) badge.style.display = 'none';
                else badge.textContent = unread;
            }
        });
    });
}

/* ============================================================
   User Menu
   ============================================================ */
function initializeUserMenu() {
    const btn      = document.getElementById('userMenuBtn');
    const dropdown = document.getElementById('userDropdown');
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('active');
        btn.classList.toggle('active');
        document.getElementById('notificationDropdown').classList.remove('active');
    });
    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
            dropdown.classList.remove('active');
            btn.classList.remove('active');
        }
    });
}

/* ============================================================
   Month Selectors
   ============================================================ */
function initializeMonthSelectors() {
    document.querySelectorAll('.month-selector').forEach(selector => {
        const btn      = selector.querySelector('.month-selector-btn');
        const dropdown = selector.querySelector('.month-dropdown');
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('active');
        });
        selector.querySelectorAll('.month-option').forEach(option => {
            option.addEventListener('click', function() {
                btn.querySelector('span').textContent = thaiMonthNames[parseInt(this.dataset.month)];
                dropdown.classList.remove('active');
            });
        });
        document.addEventListener('click', function(e) {
            if (!selector.contains(e.target)) dropdown.classList.remove('active');
        });
    });
}

/* ============================================================
   Mobile Menu
   ============================================================ */
function initializeMobileMenu() {
    const mobileBtn = document.getElementById('mobileMenuBtn');
    const sidebar   = document.getElementById('sidebar');
    if (mobileBtn) mobileBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        sidebar.classList.toggle('active');
    });
    document.getElementById('sidebarToggle').addEventListener('click', () => sidebar.classList.remove('active'));
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 900 && !sidebar.contains(e.target) && !mobileBtn.contains(e.target))
            sidebar.classList.remove('active');
    });
}

/* ============================================================
   Init
   ============================================================ */
document.addEventListener('DOMContentLoaded', function() {
    renderCalendar();
    initializeNotifications();
    initializeUserMenu();
    initializeMonthSelectors();
    initializeMobileMenu();

    // Scroll animation
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.style.opacity = '1';
                e.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
    document.querySelectorAll('.stat-card, .chart-container, .event-card').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
        observer.observe(el);
    });
});

window.addEventListener('resize', function() {
    if (window.innerWidth > 900)
        document.getElementById('sidebar').classList.remove('active');
});
</script>
</body>
</html>
