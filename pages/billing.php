<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$owner_id   = $_SESSION['user_id'];
$owner_name = $_SESSION['fullname'] ?? 'ผู้ประกอบการ';

/* ── ดึง shop + owner bank ── */
$sh = $conn->prepare("SELECT s.shop_id, s.shop_name, o.Bank_account, o.Bank_name, o.owner_fullname
                      FROM shop s JOIN owner o ON s.owner_id = o.owner_id
                      WHERE s.owner_id = ? LIMIT 1");
$sh->bind_param("i", $owner_id); $sh->execute();
$shop_row  = $sh->get_result()->fetch_assoc(); $sh->close();
$shop_id   = $shop_row['shop_id']   ?? null;
$shop_name = $shop_row['shop_name'] ?? 'ร้านของคุณ';
$bank_acc  = $shop_row['Bank_account'] ?? '';
$bank_name = $shop_row['Bank_name']    ?? '';

/* ── Notifications ── */
$total_notifs = 0; $notif_bookings = []; $notif_complaints = [];
if ($shop_id) {
    $nb = $conn->prepare("SELECT b.booking_id,u.fullname,a.activity_name,b.booking_date FROM booking b JOIN activity a ON b.activity_id=a.activity_id JOIN user u ON b.user_id=u.user_id WHERE a.shop_id=? AND b.status='Pending' ORDER BY b.booking_date DESC LIMIT 5");
    $nb->bind_param("i",$shop_id); $nb->execute();
    $notif_bookings = $nb->get_result()->fetch_all(MYSQLI_ASSOC); $nb->close();
    $nc = $conn->prepare("SELECT c.complaint_id,c.topic,u.fullname FROM complaint c JOIN user u ON c.user_id=u.user_id WHERE c.status='Pending' LIMIT 5");
    $nc->execute(); $notif_complaints = $nc->get_result()->fetch_all(MYSQLI_ASSOC); $nc->close();
    $total_notifs = count($notif_bookings) + count($notif_complaints);
}

/* ── Month filter ── */
$now          = new DateTime();
$filter_year  = (int)($_GET['year']  ?? $now->format('Y'));
$filter_month = (int)($_GET['month'] ?? $now->format('n'));
$month_dt     = DateTime::createFromFormat('Y-n', "$filter_year-$filter_month");
$month_label  = $month_dt->format('F') . ' ' . ($filter_year + 543);

/* ── รายได้เดือนนี้: ยึดรายการชำระเงินที่แอดมินอนุมัติแล้ว ── */
$COMMISSION_RATE = 0.07;

$rev = ['total' => 0, 'commission' => 0, 'net' => 0, 'prev_total' => 0];
if ($shop_id) {
    /* เดือนปัจจุบัน */
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(p.amount),0) AS total
         FROM payment p
         JOIN booking b ON p.booking_id=b.booking_id
         JOIN activity a ON b.activity_id=a.activity_id
         WHERE a.shop_id=? AND p.status='Approved'
           AND YEAR(p.payment_date)=? AND MONTH(p.payment_date)=?"
    );
    $stmt->bind_param("iii",$shop_id,$filter_year,$filter_month);
    $stmt->execute();
    $rev['total'] = (float)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    $rev['commission'] = round($rev['total'] * $COMMISSION_RATE);
    $rev['net']        = $rev['total'] - $rev['commission'];

    /* เดือนก่อน */
    $prev = (clone $month_dt)->modify('-1 month');
    $py = (int)$prev->format('Y'); $pm = (int)$prev->format('n');
    $stmt2 = $conn->prepare(
        "SELECT COALESCE(SUM(p.amount),0) AS total
         FROM payment p
         JOIN booking b ON p.booking_id=b.booking_id
         JOIN activity a ON b.activity_id=a.activity_id
         WHERE a.shop_id=? AND p.status='Approved'
           AND YEAR(p.payment_date)=? AND MONTH(p.payment_date)=?"
    );
    $stmt2->bind_param("iii",$shop_id,$py,$pm);
    $stmt2->execute();
    $rev['prev_total'] = (float)$stmt2->get_result()->fetch_assoc()['total'];
    $stmt2->close();
}
$growth = ($rev['prev_total'] > 0)
    ? round((($rev['total'] - $rev['prev_total']) / $rev['prev_total']) * 100)
    : ($rev['total'] > 0 ? 100 : 0);

/* ── ค่าโฆษณา (mock — ไม่มีตารางจริง ใช้ค่าตายตัว) ── */
$ad_spend = 548; // บาท (placeholder จากรูป)
$ad_web   = round($ad_spend * 0.65);
$ad_other = $ad_spend - $ad_web;
$ad_pct   = ($rev['total'] > 0) ? min(100, round(($ad_spend / $rev['total']) * 100)) : 0;

/* ── ประวัติธุรกรรม (pagination) ── */
$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$txns     = [];
$total_txns = 0;

if ($shop_id) {
    /* นับทั้งหมด */
    $cnt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM payment p
         JOIN booking b ON p.booking_id=b.booking_id
         JOIN activity a ON b.activity_id=a.activity_id
         WHERE a.shop_id=? AND p.status='Approved'
           AND YEAR(p.payment_date)=? AND MONTH(p.payment_date)=?"
    );
    $cnt->bind_param("iii",$shop_id,$filter_year,$filter_month);
    $cnt->execute();
    $total_txns = (int)$cnt->get_result()->fetch_assoc()['c'];
    $cnt->close();

    /* ดึง rows */
    $txq = $conn->prepare(
        "SELECT b.booking_id, p.payment_date AS booking_date, p.amount AS total_price,
                b.payment_method, b.bank_name,
                u.fullname AS user_name, a.activity_name
         FROM payment p
         JOIN booking b ON p.booking_id = b.booking_id
         JOIN activity a ON b.activity_id = a.activity_id
         JOIN user u     ON b.user_id = u.user_id
         WHERE a.shop_id=? AND p.status='Approved'
           AND YEAR(p.payment_date)=? AND MONTH(p.payment_date)=?
         ORDER BY p.payment_date DESC
         LIMIT ? OFFSET ?"
    );
    $txq->bind_param("iiiii",$shop_id,$filter_year,$filter_month,$per_page,$offset);
    $txq->execute();
    $txns = $txq->get_result()->fetch_all(MYSQLI_ASSOC);
    $txq->close();
}
$total_pages = max(1, ceil($total_txns / $per_page));

/* ── Month nav helpers ── */
function monthUrl($y,$m,$extra=''){
    $p = "?year=$y&month=$m$extra";
    return htmlspecialchars($p);
}
$prev_month = (clone $month_dt)->modify('-1 month');
$next_month = (clone $month_dt)->modify('+1 month');

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
    <title>Billing — <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="/tkn/assets/css/ownerstyle2.css">
    <link rel="stylesheet" href="/tkn/assets/css/billing.css">
    <link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
    <div id="wrapper">

        <!-- ══ Sidebar ══ -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <button class="sidebar-toggle" id="sidebarToggle">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M15 9l-6 6M9 9l6 6" />
                    </svg>
                </button>
            </div>
            <nav class="sidebar-nav">
                <div class="nav-section">
                    <p class="nav-section-title">General</p>
                    <a href="/tkn/dashboard" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="3" width="7" height="7" />
                            <rect x="14" y="3" width="7" height="7" />
                            <rect x="14" y="14" width="7" height="7" />
                            <rect x="3" y="14" width="7" height="7" />
                        </svg>
                        <span>Dashboard</span>
                    </a>
                    <a href="/tkn/my-shop" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M12 20h9" />
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
                        </svg>
                        <span>Activity Management</span>
                    </a>
                    <a href="/tkn/booking-history" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                        <span>Booking</span>
                    </a>
                    <a href="/tkn/billing" class="nav-item active">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="2" y="5" width="20" height="14" rx="2" />
                            <line x1="2" y1="10" x2="22" y2="10" />
                        </svg>
                        <span>Billing</span>
                    </a>
                </div>
                <div class="nav-section">
                    <p class="nav-section-title">Tools</p>
                    <a href="/tkn/owner-feedback" class="nav-item">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                        </svg>
                        <span>Feedback</span>
                    </a>
                </div>
            </nav>
        </aside>

        <!-- ══ Main ══ -->
        <main class="main-content">

            <!-- Header -->
            <header class="top-header">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="3" y1="12" x2="21" y2="12" />
                        <line x1="3" y1="6" x2="21" y2="6" />
                        <line x1="3" y1="18" x2="21" y2="18" />
                    </svg>
                </button>
                <div class="header-actions">
                    <div class="notification-wrapper">
                        <button class="notification-btn" id="notificationBtn">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
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
                                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                                    </svg>
                                    ไม่มีการแจ้งเตือนใหม่
                                </div>
                                <?php else: ?>
                                <?php foreach($notif_bookings as $nb): ?>
                                <div class="notification-item unread">
                                    <div class="notification-icon"><svg width="20" height="20" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                            <line x1="16" y1="2" x2="16" y2="6" />
                                            <line x1="8" y1="2" x2="8" y2="6" />
                                            <line x1="3" y1="10" x2="21" y2="10" />
                                        </svg></div>
                                    <div class="notification-content">
                                        <p class="notification-title">การจองใหม่</p>
                                        <p class="notification-text"><?=htmlspecialchars($nb['fullname'])?> จองกิจกรรม
                                            "<?=htmlspecialchars($nb['activity_name'])?>"</p>
                                        <p class="notification-time">
                                            <?=date('d/m/Y H:i',strtotime($nb['booking_date']))?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php foreach($notif_complaints as $nc): ?>
                                <div class="notification-item unread">
                                    <div class="notification-icon"><svg width="20" height="20" viewBox="0 0 24 24"
                                            fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                                        </svg></div>
                                    <div class="notification-content">
                                        <p class="notification-title">ข้อร้องเรียน</p>
                                        <p class="notification-text"><?=htmlspecialchars($nc['fullname'])?>:
                                            <?=htmlspecialchars($nc['topic'])?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <button class="notification-view-all">ดูทั้งหมด</button>
                        </div>
                    </div>
                    <div class="user-menu-wrapper">
                        <button class="user-menu-btn" id="userMenuBtn">
                            <div class="user-avatar"><svg width="24" height="24" viewBox="0 0 24 24"
                                    fill="currentColor">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                    <circle cx="12" cy="7" r="4" />
                                </svg></div>
                            <span
                                class="user-name">ผู้ประกอบการ<br><small><?=htmlspecialchars($owner_name)?></small></span>
                            <svg class="dropdown-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </button>
                        <div class="user-dropdown" id="userDropdown">
                            <a href="#" class="user-dropdown-item"><svg width="18" height="18" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                                    <circle cx="12" cy="7" r="4" />
                                </svg><span>Edit Profile</span></a>
                            <a href="/tkn/logout" class="user-dropdown-item"><svg width="18" height="18"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                    <polyline points="16 17 21 12 16 7" />
                                    <line x1="21" y1="12" x2="9" y2="12" />
                                </svg><span>Logout</span></a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Header -->
            <div class="page-header"
                style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h1 class="page-title">รายได้และการเงิน</h1>
                    <p class="page-subtitle"><?=htmlspecialchars($shop_name)?></p>
                </div>
                <!-- Month nav -->
                <div class="bl-month-nav">
                    <a href="<?=monthUrl($prev_month->format('Y'),(int)$prev_month->format('n'))?>"
                        class="bl-month-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="15 18 9 12 15 6" />
                        </svg>
                    </a>
                    <span class="bl-month-label">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                        เดือน<?=$month_label?>
                    </span>
                    <?php if($month_dt < $now): ?>
                    <a href="<?=monthUrl($next_month->format('Y'),(int)$next_month->format('n'))?>"
                        class="bl-month-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="9 18 15 12 9 6" />
                        </svg>
                    </a>
                    <?php else: ?>
                    <span class="bl-month-btn disabled"></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══ Top Grid: Summary + Revenue ══ -->
            <div class="bl-top-grid">

                <!-- LEFT: สรุปยอดเงิน -->
                <div class="bl-card bl-summary-card">
                    <div class="bl-card-head">
                        <span class="bl-card-title">สรุปยอดเงิน</span>
                        <span class="bl-currency-badge">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                                <circle cx="12" cy="12" r="10" />
                            </svg>
                            THB
                        </span>
                    </div>

                    <div class="bl-net-amount">
                        <?= number_format($rev['net']) ?><span class="bl-currency-sym">฿</span>
                    </div>
                    <?php if($growth !== 0): ?>
                    <div class="bl-growth <?= $growth >= 0 ? 'positive' : 'negative' ?>">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <?php if($growth >= 0): ?>
                            <polyline points="18 15 12 9 6 15" />
                            <?php else: ?>
                            <polyline points="6 9 12 15 18 9" />
                            <?php endif; ?>
                        </svg>
                        <?= abs($growth) ?>% Than Last Month
                    </div>
                    <?php endif; ?>

                    <!-- Actions -->
                    <div class="bl-actions">
                        <button class="bl-action-btn" onclick="showWithdrawInfo()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <polyline points="17 11 21 7 17 3" />
                                <line x1="21" y1="7" x2="9" y2="7" />
                                <path d="M3 21v-7a4 4 0 0 1 4-4h14" />
                            </svg>
                            ถอนเงิน
                        </button>
                        <button class="bl-action-btn" onclick="showCommissionInfo()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <polyline points="7 11 3 7 7 3" />
                                <line x1="3" y1="7" x2="15" y2="7" />
                                <path d="M21 21v-7a4 4 0 0 0-4-4H3" />
                            </svg>
                            คำนวณค่าคอมมิชชัน
                        </button>
                    </div>

                    <!-- Bank Accounts -->
                    <div class="bl-wallet-section">
                        <div class="bl-wallet-header">
                            <span class="bl-wallet-title">Wallet</span>
                            <span class="bl-wallet-count">Total <?= $bank_acc ? 1 : 0 ?></span>
                        </div>
                        <div class="bl-bank-list">
                            <?php if($bank_acc): ?>
                            <div class="bl-bank-item">
                                <div class="bl-bank-dot" style="background:<?= bankColor($bank_name) ?>"></div>
                                <div>
                                    <div class="bl-bank-name"><?= htmlspecialchars($bank_name) ?></div>
                                    <div class="bl-bank-acc">เลขที่บัญชี <?= maskAccount($bank_acc) ?></div>
                                    <div class="bl-bank-holder">ชื่อบัญชี :
                                        <?= htmlspecialchars($shop_row['owner_fullname'] ?? $owner_name) ?></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <p
                                style="font-family:'Kanit',sans-serif;font-size:13px;color:var(--text-light);padding:8px 0;">
                                ยังไม่มีข้อมูลธนาคาร</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: รายได้ทั้งหมด -->
                <div class="bl-card bl-revenue-card">
                    <div class="bl-card-head">
                        <span class="bl-card-title">รายได้ทั้งหมด</span>
                    </div>

                    <div class="bl-rev-grid">
                        <div class="bl-rev-box">
                            <div class="bl-rev-label">รายรับทั้งหมด<br><small>** ยอดก่อนหักค่าคอมมิชชัน</small></div>
                            <div class="bl-rev-amount"><?= number_format($rev['total']) ?>฿</div>
                        </div>
                        <div class="bl-rev-box">
                            <div class="bl-rev-label">ค่าคอมมิชชัน<br><small>** ค่าคอมมิชชัน
                                    <?= ($COMMISSION_RATE*100) ?>% - อื่นๆ</small></div>
                            <div class="bl-rev-amount commission"><?= number_format($rev['commission']) ?>฿</div>
                        </div>
                    </div>

                    <!-- Ad spend section -->
                    <div class="bl-ad-section">
                        <div class="bl-ad-head">
                            <span class="bl-ad-title">ค่าใช้จ่ายในการโฆษณา</span>
                            <span class="bl-ad-amount"><?= number_format($ad_spend) ?> บาท</span>
                        </div>
                        <!-- Progress bar -->
                        <div class="bl-progress-wrap">
                            <div class="bl-progress-bar">
                                <div class="bl-progress-fill web" style="width:<?= $ad_pct ?>%"></div>
                            </div>
                        </div>
                        <div class="bl-ad-legend">
                            <span class="bl-legend-dot web"></span>โฆษณาในเว็บไซต์
                            <span class="bl-legend-dot other" style="margin-left:14px"></span>อื่น ๆ
                        </div>
                    </div>

                    <!-- Net income highlight -->
                    <div class="bl-net-box">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <line x1="12" y1="1" x2="12" y2="23" />
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                        </svg>
                        <div>
                            <div class="bl-net-label">รายได้สุทธิ (หักค่าคอมมิชชัน)</div>
                            <div class="bl-net-val"><?= number_format($rev['net']) ?> ฿</div>
                        </div>
                    </div>
                </div>

            </div><!-- /.bl-top-grid -->

            <!-- ══ Transaction History ══ -->
            <div class="bl-card bl-txn-card">
                <div class="bl-txn-header">
                    <div class="bl-txn-title-wrap">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                            <polyline points="14 2 14 8 20 8" />
                            <line x1="16" y1="13" x2="8" y2="13" />
                            <line x1="16" y1="17" x2="8" y2="17" />
                        </svg>
                        <span>ประวัติการทำธุรกรรม</span>
                    </div>
                    <span class="bl-txn-count"><?= $total_txns ?> รายการ</span>
                </div>

                <?php if(empty($txns)): ?>
                <div class="bl-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                    </svg>
                    <p>ยังไม่มีธุรกรรมในเดือนนี้</p>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="bl-table">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>เวลา</th>
                                <th>รายการ</th>
                                <th style="text-align:right">ยอดเงิน</th>
                                <th>วิธีชำระ</th>
                                <th>รายละเอียด</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($txns as $tx): ?>
                            <tr>
                                <td class="bl-date"><?= date('d-m-Y', strtotime($tx['booking_date'])) ?></td>
                                <td class="bl-time"><?= date('H:i', strtotime($tx['booking_date'])) ?></td>
                                <td>
                                    <span class="bl-txn-type">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <polyline points="18 15 12 9 6 15" />
                                        </svg>
                                        รับเงินโอน
                                    </span>
                                </td>
                                <td style="text-align:right">
                                    <span class="bl-amount-positive">+<?= number_format($tx['total_price']) ?></span>
                                </td>
                                <td>
                                    <?php
                        $pm_labels = ['mobile'=>'Mobile Banking','qr'=>'QR พร้อมเพย์','card'=>'บัตรเครดิต/เดบิต'];
                        $pm_label  = $pm_labels[$tx['payment_method']] ?? '—';
                        if ($tx['payment_method'] === 'mobile' && !empty($tx['bank_name']))
                            $pm_label .= '<br><small style="color:#888;">' . htmlspecialchars($tx['bank_name']) . '</small>';
                        echo $pm_label;
                        ?>
                                </td>
                                <td class="bl-txn-detail">
                                    <?= htmlspecialchars($tx['user_name']) ?> —
                                    <?= htmlspecialchars($tx['activity_name']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if($total_pages > 1): ?>
                <div class="bl-pagination">
                    <?php for($i=1;$i<=$total_pages;$i++): ?>
                    <a href="<?=monthUrl($filter_year,$filter_month,"&page=$i")?>"
                        class="bl-page-btn <?= $i===$page ? 'active' : '' ?>"><?=$i?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <script>
    /* Sidebar / dropdowns */
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (sidebarToggle) sidebarToggle.addEventListener('click', () => {
        sidebar.classList.remove('active', 'open');
    });
    if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', e => {
        e.stopPropagation();
        sidebar.classList.toggle('active');
    });

    document.addEventListener('DOMContentLoaded', function() {
        const nb = document.getElementById('notificationBtn');
        const nd = document.getElementById('notificationDropdown');
        const ub = document.getElementById('userMenuBtn');
        const ud = document.getElementById('userDropdown');
        if (nb) nb.addEventListener('click', e => {
            e.stopPropagation();
            nd.classList.toggle('active');
            ud.classList.remove('active');
        });
        if (ub) ub.addEventListener('click', e => {
            e.stopPropagation();
            ud.classList.toggle('active');
            nd.classList.remove('active');
        });
        document.addEventListener('click', () => {
            nd.classList.remove('active');
            ud.classList.remove('active');
        });
    });

    function showWithdrawInfo() {
        Swal.fire({
            icon: 'info',
            title: 'ถอนเงิน',
            html: `<p style="font-family:Kanit,sans-serif;font-size:14px;color:#555">
            ยอดสุทธิที่ถอนได้: <strong style="color:#2C4A2F;font-size:18px"><?= number_format($rev['net']) ?> ฿</strong><br><br>
            โอนไปยังบัญชี <strong><?= htmlspecialchars($bank_name) ?></strong><br>
            <?= maskAccount($bank_acc) ?>
        </p>`,
            confirmButtonText: 'รับทราบ',
            confirmButtonColor: '#2C4A2F',
        });
    }

    function showCommissionInfo() {
        Swal.fire({
            icon: 'info',
            title: 'ค่าคอมมิชชัน',
            html: `<p style="font-family:Kanit,sans-serif;font-size:14px;color:#555;line-height:1.9">
            รายรับทั้งหมด: <strong><?= number_format($rev['total']) ?> ฿</strong><br>
            อัตราค่าคอมมิชชัน: <strong>7%</strong><br>
            ค่าคอมมิชชัน: <strong style="color:#DC2626"><?= number_format($rev['commission']) ?> ฿</strong><br>
            <hr style="margin:10px 0;border-color:#eee">
            รายได้สุทธิ: <strong style="color:#2C4A2F;font-size:16px"><?= number_format($rev['net']) ?> ฿</strong>
        </p>`,
            confirmButtonText: 'ตกลง',
            confirmButtonColor: '#2C4A2F',
        });
    }
    </script>
</body>

</html>
<?php
/* ── Helper functions ── */
function maskAccount($acc) {
    if(!$acc) return '-';
    $len = strlen($acc);
    if($len <= 4) return $acc;
    return 'XXX-X-' . substr($acc, -5, 4) . '-X';
}
function bankColor($name) {
    $colors = [
        'กสิกรไทย'    => '#1BA345',
        'กรุงไทย'     => '#00AEEF',
        'ไทยพาณิชย์'  => '#4E2683',
        'กรุงศรีอยุธยา'=> '#FDB813',
        'กรุงเทพ'     => '#1E4598',
    ];
    foreach($colors as $k=>$v) {
        if(str_contains($name, $k)) return $v;
    }
    return '#2C4A2F';
}
?>
