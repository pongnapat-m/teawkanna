<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$admin_name  = $_SESSION['fullname'] ?? 'Admin';
$page_title  = 'Dashboard';
$current_page = 'dashboard';

/* ── Stats ─────────────────────────────────────────────── */
$stats = [];
$thisYM = date('Y-m');
$stats_q = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM user) AS users,
        (SELECT COUNT(*) FROM owner) AS owners,
        (SELECT COUNT(*) FROM activity) AS activities,
        (SELECT COUNT(*) FROM payment WHERE status='PendingReview') AS bookings,
        (SELECT COALESCE(SUM(amount),0) FROM payment WHERE status='Approved' AND DATE_FORMAT(payment_date,'%Y-%m')='{$thisYM}') AS monthly_revenue,
        (SELECT COUNT(*) FROM review) AS reviews,
        (SELECT COUNT(*) FROM activity_open_request WHERE status='Pending') AS activity_open_request
")->fetch_assoc();

$stats['users']      = (int)($stats_q['users'] ?? 0);
$stats['owners']     = (int)($stats_q['owners'] ?? 0);
$stats['activities'] = (int)($stats_q['activities'] ?? 0);
$stats['bookings']   = (int)($stats_q['bookings'] ?? 0);
$stats['monthly_revenue'] = (float)($stats_q['monthly_revenue'] ?? 0);
$stats['reviews']    = (int)($stats_q['reviews'] ?? 0);
$stats['activity_open_request'] = (int)($stats_q['activity_open_request'] ?? 0);

/* ── Pending (for notifications) ───────────────────────── */
$pend_owners_q = $conn->query("SELECT owner_id,owner_fullname FROM owner WHERE status='Pending' ORDER BY owner_id DESC LIMIT 10");
$pend_owners   = $pend_owners_q->fetch_all(MYSQLI_ASSOC);


$pend_act_q = $conn->query("SELECT r.request_id, a.activity_id, a.activity_name, s.shop_name FROM activity_open_request r JOIN activity a ON r.activity_id=a.activity_id JOIN shop s ON r.shop_id=s.shop_id WHERE r.status='Pending' ORDER BY r.requested_at DESC LIMIT 10");
$pend_activities = $pend_act_q->fetch_all(MYSQLI_ASSOC);

$pend_book_q = $conn->query("
    SELECT b.booking_id,b.total_price,u.fullname AS user_name,a.activity_name
    FROM booking b
    JOIN user u ON b.user_id=u.user_id
    JOIN activity a ON b.activity_id=a.activity_id
    WHERE b.status='Pending'
    AND NOT EXISTS (
        SELECT 1 FROM payment p
        WHERE p.booking_id = b.booking_id
        AND p.status != 'Rejected'
    )
    ORDER BY b.booking_date DESC LIMIT 10");
$pend_bookings = $pend_book_q->fetch_all(MYSQLI_ASSOC);

$pend_pay_q = $conn->query("
    SELECT p.payment_id,p.amount,u.fullname AS user_name
    FROM payment p
    JOIN booking b ON p.booking_id=b.booking_id
    JOIN user u ON b.user_id=u.user_id
    WHERE p.status='PendingReview'
    AND (p.payment_method NOT LIKE '%omise%' OR p.payment_method IS NULL)
    ORDER BY p.payment_date DESC LIMIT 10");
$pend_payments = $pend_pay_q->fetch_all(MYSQLI_ASSOC);

$total_notifs = count($pend_owners) + count($pend_activities) + count($pend_bookings) + count($pend_payments);

/* ── Charts ─────────────────────────────────────────────── */
$month_start = date('Y-m-01');
$uw_q = $conn->query("SELECT WEEK(booking_date,1) AS wk, COUNT(DISTINCT user_id) AS cnt FROM booking WHERE booking_date >= '$month_start' GROUP BY wk ORDER BY wk LIMIT 5");
$user_weeks = $uw_q->fetch_all(MYSQLI_ASSOC);

$sw_q = $conn->query("SELECT WEEK(b.booking_date,1) AS wk, COUNT(DISTINCT s.shop_id) AS cnt FROM booking b JOIN activity a ON b.activity_id=a.activity_id JOIN shop s ON a.shop_id=s.shop_id WHERE b.booking_date >= '$month_start' GROUP BY wk ORDER BY wk LIMIT 5");
$shop_weeks = $sw_q->fetch_all(MYSQLI_ASSOC);

$avg_users_day = $stats['users'] > 0 ? round($stats['users'] / 30) : 0;

/* ── รีวิวล่าสุด (ทั้ง public + private) ───────────────── */
// ตรวจว่ามีคอลัมน์ is_public
$col_chk = $conn->query("SHOW COLUMNS FROM `review` LIKE 'is_public'");
$has_is_public = ($col_chk && $col_chk->num_rows > 0);
$is_pub_sel = $has_is_public ? 'r.is_public' : '1 AS is_public';
$recent_reviews_q = $conn->query(
    "SELECT r.review_id, r.rating, r.comment, r.created_at,
            u.fullname, a.activity_name, {$is_pub_sel}
     FROM review r
     JOIN user u  ON r.user_id     = u.user_id
     JOIN activity a ON r.activity_id = a.activity_id
     ORDER BY r.created_at DESC LIMIT 20"
);
$recent_reviews = $recent_reviews_q ? $recent_reviews_q->fetch_all(MYSQLI_ASSOC) : [];

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title">Dashboard</h1>
        </div>
        <div class="topbar-right">
            <div class="user-menu-wrapper" id="userMenuWrapper">
                <button class="user-menu-btn" id="userMenuBtn">
                    <div class="user-avatar">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </div>
                    <span class="user-name">แอดมิน<br><small><?= htmlspecialchars($admin_name) ?></small></span>
                    <svg class="dropdown-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </button>
                <div class="user-dropdown" id="userDropdown">
                    <a href="#" class="user-dropdown-item">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                        <span>Edit Profile</span>
                    </a>
                    <a href="/tkn/logout" class="user-dropdown-item">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                            <polyline points="16 17 21 12 16 7" />
                            <line x1="21" y1="12" x2="9" y2="12" />
                        </svg>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Page body (padded content area below topbar) ── -->
    <div class="page-body">

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(232,255,71,.1)">
                    <svg width="20" height="20" fill="none" stroke="var(--accent)" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                </div>
                <div class="stat-label">ผู้เข้าใช้งาน</div>
                <div class="stat-val">
                    <?= $stats['users'] >= 1000 ? round($stats['users']/1000,1).'k' : $stats['users'] ?>
                </div>
                <a class="stat-arrow" href="/tkn/admin/users">ดูทั้งหมด <svg width="12" height="12" fill="none"
                        stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="9 18 15 12 9 6" />
                    </svg></a>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(96,165,250,.1)">
                    <svg width="20" height="20" fill="none" stroke="var(--blue)" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                        <polyline points="9 22 9 12 15 12 15 22" />
                    </svg>
                </div>
                <div class="stat-label">ร้านค้า / ชุมชน</div>
                <div class="stat-val"><?= $stats['owners'] ?></div>
                <a class="stat-arrow" href="/tkn/admin/community">ดูทั้งหมด <svg width="12" height="12" fill="none"
                        stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="9 18 15 12 9 6" />
                    </svg></a>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(251,191,36,.1)">
                    <svg width="20" height="20" fill="none" stroke="var(--amber)" stroke-width="2" viewBox="0 0 24 24">
                        <line x1="12" y1="1" x2="12" y2="23"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
                <div class="stat-label">รายได้เดือน<?= ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][(int)date('n')-1] ?></div>
                <div class="stat-val" style="font-size:1.5rem;">
                    <?php
                        $rev = $stats['monthly_revenue'];
                        if ($rev >= 1000000) echo round($rev/1000000,1).'M';
                        elseif ($rev >= 1000) echo round($rev/1000,1).'K';
                        else echo number_format($rev, 0);
                    ?>
                    <span style="font-size:.85rem;font-weight:500;opacity:.7;">฿</span>
                </div>
                <a class="stat-arrow" href="/tkn/admin/payments">ดูรายละเอียด <svg width="12" height="12" fill="none"
                        stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="9 18 15 12 9 6" />
                    </svg></a>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:rgba(251,146,60,.1)">
                    <svg width="20" height="20" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                </div>
                <div class="stat-label">รีวิวทั้งหมด</div>
                <div class="stat-val"><?= $stats['reviews'] ?></div>
                <a class="stat-arrow" href="/tkn/admin/reports">ดูรีวิว <svg width="12" height="12" fill="none"
                        stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <polyline points="9 18 15 12 9 6" />
                    </svg></a>
            </div>
        </div>

        <!-- Notifications -->
        <div class="notif-panel">
            <div class="notif-panel-title">
                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                แจ้งเตือนเหตุการณ์สำคัญ
                <?php if ($total_notifs > 0): ?>
                <span class="notif-badge-big"><?= $total_notifs ?></span>
                <?php endif; ?>
            </div>
            <div class="notif-list">
                <?php if ($total_notifs === 0): ?>
                <div class="notif-empty">✅ ไม่มีการแจ้งเตือนใหม่</div>
                <?php endif; ?>
                <?php foreach ($pend_owners as $o): ?>
                <div class="notif-item">
                    <div class="notif-dot-type" style="background:var(--blue)"></div>
                    <div class="notif-item-text"><b><?= htmlspecialchars($o['owner_fullname']) ?></b>
                        สมัครเป็นผู้ประกอบการ
                        รอการอนุมัติ</div>
                    <a class="btn btn-view" href="/tkn/admin/community" style="font-size:11px">ดู</a>
                </div>
                <?php endforeach; ?>
                <?php foreach ($pend_activities as $a): ?>
                <div class="notif-item">
                    <div class="notif-dot-type" style="background:var(--green)"></div>
                    <div class="notif-item-text"><b><?= htmlspecialchars($a['activity_name']) ?></b>
                        (<?= htmlspecialchars($a['shop_name']) ?>) รอการอนุมัติ</div>
                    <a class="btn btn-view" href="/tkn/admin/activities" style="font-size:11px">ดู</a>
                </div>
                <?php endforeach; ?>
                <?php foreach ($pend_bookings as $b): ?>
                <div class="notif-item">
                    <div class="notif-dot-type" style="background:var(--amber)"></div>
                    <div class="notif-item-text"><b><?= htmlspecialchars($b['user_name']) ?></b> จอง
                        "<?= htmlspecialchars($b['activity_name']) ?>" — ฿<?= number_format($b['total_price']) ?></div>
                    <a class="btn btn-view" href="/tkn/admin/payments" style="font-size:11px">ดู</a>
                </div>
                <?php endforeach; ?>
                <?php foreach ($pend_payments as $p): ?>
                <div class="notif-item">
                    <div class="notif-dot-type" style="background:var(--red)"></div>
                    <div class="notif-item-text"><b><?= htmlspecialchars($p['user_name']) ?></b> แนบสลิปชำระเงิน
                        ฿<?= number_format($p['amount']) ?> รอตรวจสอบ</div>
                    <a class="btn btn-view" href="/tkn/admin/payments" style="font-size:11px">ดู</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-row">
            <?php
        $week_labels = ['สัปดาห์ 1','สัปดาห์ 2','สัปดาห์ 3','สัปดาห์ 4'];
        $u_data = [0,0,0,0]; $s_data = [0,0,0,0];
        foreach ($user_weeks as $i => $row) if ($i < 4) $u_data[$i] = (int)$row['cnt'];
        foreach ($shop_weeks as $i => $row) if ($i < 4) $s_data[$i] = (int)$row['cnt'];
        $u_max = max(array_merge($u_data, [1]));
        $s_max = max(array_merge($s_data, [1]));
        ?>
            <div class="chart-card">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">ผู้เข้าใช้งานเฉลี่ย</div>
                        <div class="chart-subtitle"><?= number_format($avg_users_day) ?><span>คนต่อวัน</span></div>
                    </div>
                    <span
                        class="month-tag"><?= ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][(int)date('n')-1] ?></span>
                </div>
                <div style="display:flex;gap:0">
                    <div class="y-labels">
                        <?php $step = max(1, $u_max/5); for ($v = round($u_max); $v >= 0; $v -= round($step)): ?>
                        <div><?= $v ?></div>
                        <?php endfor; ?>
                    </div>
                    <div class="bar-chart-wrap" style="flex:1">
                        <?php foreach ($week_labels as $i => $wl): $pct = $u_max > 0 ? round($u_data[$i]/$u_max*100) : 5; ?>
                        <div class="bar-col">
                            <div class="bar-track">
                                <div class="bar-fill" style="height:<?= $pct ?>%"></div>
                            </div>
                            <div class="bar-label"><?= $wl ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="chart-card">
                <div class="chart-header">
                    <div>
                        <div class="chart-title">ชุมชนเข้าร่วม</div>
                        <div class="chart-subtitle"><?= $stats['owners'] ?><span>ชุมชนต่อเดือน</span></div>
                    </div>
                    <span
                        class="month-tag"><?= ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][(int)date('n')-1] ?></span>
                </div>
                <div style="display:flex;gap:0">
                    <div class="y-labels">
                        <?php $step2 = max(1, $s_max/5); for ($v = round($s_max); $v >= 0; $v -= round($step2)): ?>
                        <div><?= $v ?></div>
                        <?php endfor; ?>
                    </div>
                    <div class="bar-chart-wrap" style="flex:1">
                        <?php foreach ($week_labels as $i => $wl): $pct = $s_max > 0 ? round($s_data[$i]/$s_max*100) : 5; ?>
                        <div class="bar-col">
                            <div class="bar-track">
                                <div class="bar-fill bar-fill2" style="height:<?= $pct ?>%"></div>
                            </div>
                            <div class="bar-label"><?= $wl ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /page-body -->

    </div><!-- /main -->

    <?php include 'footer.php'; ?>