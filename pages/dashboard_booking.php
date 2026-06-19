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

/* ── Notifications ── */
$total_notifs = 0; $notif_bookings = []; $notif_paid = []; $notif_complaints = [];
if ($shop_id) {
    $nb = $conn->prepare("SELECT b.booking_id,u.fullname,a.activity_name,b.booking_date FROM booking b JOIN activity a ON b.activity_id=a.activity_id JOIN user u ON b.user_id=u.user_id WHERE a.shop_id=? AND b.status='Pending' ORDER BY b.booking_date DESC LIMIT 5");
    $nb->bind_param("i",$shop_id); $nb->execute();
    $notif_bookings = $nb->get_result()->fetch_all(MYSQLI_ASSOC); $nb->close();

    $np = $conn->prepare("SELECT b.booking_id,u.fullname,a.activity_name,b.booking_date FROM booking b JOIN activity a ON b.activity_id=a.activity_id JOIN user u ON b.user_id=u.user_id WHERE a.shop_id=? AND b.status='Paid' AND b.booking_date >= NOW() - INTERVAL 1 DAY ORDER BY b.booking_date DESC LIMIT 5");
    $np->bind_param("i",$shop_id); $np->execute();
    $notif_paid = $np->get_result()->fetch_all(MYSQLI_ASSOC); $np->close();

    $nc = $conn->prepare("SELECT c.complaint_id,c.topic,u.fullname FROM complaint c JOIN user u ON c.user_id=u.user_id WHERE c.status='Pending' LIMIT 5");
    $notif_complaints = [];
    if ($nc) {
        $nc->execute(); $notif_complaints = $nc->get_result()->fetch_all(MYSQLI_ASSOC); $nc->close();
    }
    $total_notifs = count($notif_bookings) + count($notif_paid) + count($notif_complaints);
}

/* ── Tab / Filter ── */
$tab    = $_GET['tab']    ?? 'all';
$search = trim($_GET['search'] ?? '');
$bk_limit = 10;
$bk_page  = max(1, (int)($_GET['page'] ?? 1));

$allowed_tabs = ['all','Paid','Completed','Cancel'];
if (!in_array($tab, $allowed_tabs)) $tab = 'all';

/* ── ดึง bookings — เฉพาะที่ admin อนุมัติแล้ว (Paid) หรือ Cancel ── */
$bookings = [];
$counts   = ['all'=>0,'Paid'=>0,'Completed'=>0,'Cancel'=>0];
$bk_total_filtered = 0;
$bk_pages = 1;

if ($shop_id) {
    /* count เฉพาะ Paid และ Cancel */
    $cq = $conn->prepare("SELECT b.status, COUNT(*) as cnt FROM booking b JOIN activity a ON b.activity_id=a.activity_id WHERE a.shop_id=? AND b.status IN ('Paid','Completed','Cancel') GROUP BY b.status");
    $cq->bind_param("i",$shop_id); $cq->execute();
    $crows = $cq->get_result()->fetch_all(MYSQLI_ASSOC); $cq->close();
    foreach($crows as $cr) {
        $counts[$cr['status']] = (int)$cr['cnt'];
        $counts['all'] += (int)$cr['cnt'];
    }

    /* count for pagination */
    $where_status_c = ($tab === 'all') ? "AND b.status IN ('Paid','Completed','Cancel')" : "AND b.status = '$tab'";
    $where_search_c = $search ? "AND (a.activity_name LIKE '%" . $conn->real_escape_string($search) . "%' OR u.fullname LIKE '%" . $conn->real_escape_string($search) . "%' OR b.booking_id LIKE '%" . $conn->real_escape_string($search) . "%')" : "";
    $cnt_q = $conn->prepare("SELECT COUNT(*) FROM booking b JOIN activity a ON b.activity_id=a.activity_id JOIN user u ON b.user_id=u.user_id WHERE a.shop_id=? $where_status_c $where_search_c");
    $cnt_q->bind_param("i", $shop_id); $cnt_q->execute();
    $bk_total_filtered = (int)$cnt_q->get_result()->fetch_row()[0]; $cnt_q->close();
    $bk_pages = max(1, (int)ceil($bk_total_filtered / $bk_limit));
    $bk_page  = min($bk_page, $bk_pages);
    $bk_off   = ($bk_page - 1) * $bk_limit;

    /* ดึง rows */
    $where_status = ($tab === 'all') ? "AND b.status IN ('Paid','Completed','Cancel')" : "AND b.status = ?";
    $where_search = $search ? "AND (a.activity_name LIKE ? OR u.fullname LIKE ? OR b.booking_id LIKE ?)" : "";

    $sql = "SELECT b.booking_id, a.activity_name, u.fullname AS user_name,
                   b.booking_date, (b.adult_quantity + b.kid_quantity) AS num_people, b.adult_quantity, b.kid_quantity, b.status, b.total_price, b.booking_date AS created_at,
                   b.payment_method, b.bank_name,
                   a.activity_id, a.max_capacity AS capacity_total, a.capacity_remaining
            FROM booking b
            JOIN activity a ON b.activity_id = a.activity_id
            JOIN user u ON b.user_id = u.user_id
            WHERE a.shop_id = ? $where_status $where_search
            ORDER BY b.booking_date DESC
            LIMIT $bk_limit OFFSET $bk_off";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die(json_encode(['error' => $conn->error, 'sql' => $sql]));
    }

    $types = "i";
    $params = [$shop_id];
    if ($tab !== 'all')  { $types .= "s"; $params[] = $tab; }
    if ($search) {
        $types .= "sss";
        $like = "%$search%";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* ── Status label/color helpers ── */
function statusLabel($s) {
    return match($s) {
        'Pending'   => 'รอยืนยัน',
        'Paid'      => 'ชำระแล้ว',
        'Completed' => 'เสร็จสิ้น',
        'Cancel'    => 'ยกเลิก',
        default     => $s
    };
}
function statusClass($s) {
    return match($s) {
        'Pending'   => 'badge-pending',
        'Paid'      => 'badge-confirmed',
        'Completed' => 'badge-completed',
        'Cancel'    => 'badge-cancelled',
        default     => ''
    };
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking — <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="/tkn/assets/css/ownerstyle2.css">
    <link rel="stylesheet" href="/tkn/assets/css/dashboard_booking.css">
    <link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <style>
    /* ── Completed status badge & tab ── */
    .badge-completed {
        background: rgba(21, 101, 192, .12);
        border: 1px solid rgba(21, 101, 192, .35);
        color: #1565C0;
    }

    .tab-completed {
        color: #1565C0;
    }

    .tab-completed.active-tab {
        border-bottom-color: #1565C0 !important;
        color: #1565C0 !important;
    }
    </style>
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
                    <a href="/tkn/booking-history" class="nav-item active">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                            <line x1="16" y1="2" x2="16" y2="6" />
                            <line x1="8" y1="2" x2="8" y2="6" />
                            <line x1="3" y1="10" x2="21" y2="10" />
                        </svg>
                        <span>Booking</span>
                    </a>
                    <a href="/tkn/billing" class="nav-item">
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
                    <!-- Notification -->
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
                                <?php foreach($notif_paid as $np): ?>
                                <div class="notification-item unread">
                                    <div class="notification-icon"><svg width="20" height="20" viewBox="0 0 24 24"
                                            fill="none" stroke="#10B981" stroke-width="2">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg></div>
                                    <div class="notification-content">
                                        <p class="notification-title" style="color:#10B981">&#x2705; อนุมัติการจองแล้ว
                                        </p>
                                        <p class="notification-text"><?=htmlspecialchars($np['fullname'])?> &#x2014;
                                            "<?=htmlspecialchars($np['activity_name'])?>"</p>
                                        <p class="notification-time">
                                            <?=date('d/m/Y H:i',strtotime($np['booking_date']))?></p>
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
                    <!-- User Menu -->
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
            <div class="page-header">
                <h1 class="page-title">รายการจองกิจกรรม</h1>
                <p class="page-subtitle"><?=htmlspecialchars($shop_name)?></p>
            </div>

            <!-- ══ Toolbar: Search + Tabs ══ -->
            <div class="bk-toolbar">
                <form method="get" class="bk-search-wrap" style="margin:0">
                    <input type="hidden" name="tab" value="<?=htmlspecialchars($tab)?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>
                    <input class="bk-search" type="text" name="search" placeholder="ค้นหาการจอง..."
                        value="<?=htmlspecialchars($search)?>">
                </form>

                <div class="bk-tabs">
                    <?php
            $tabs_cfg = [
                'all'    => ['label'=>'ทั้งหมด',  'cls'=>''],
                'Paid'      => ['label'=>'ชำระแล้ว', 'cls'=>'tab-confirmed'],
                'Completed' => ['label'=>'เสร็จสิ้น',  'cls'=>'tab-completed'],
                'Cancel'    => ['label'=>'ยกเลิก',    'cls'=>'tab-cancelled'],
            ];
            foreach($tabs_cfg as $key => $cfg):
                $active = ($tab === $key) ? 'active' : '';
                $cnt    = $counts[$key] ?? 0;
            ?>
                    <a href="?tab=<?=$key?><?=$search?'&search='.urlencode($search):''?>"
                        class="bk-tab <?=$cfg['cls']?> <?=$active?>">
                        <?=$cfg['label']?>
                        <span class="tab-cnt"><?=$cnt?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ══ Table ══ -->
            <div class="bk-table-wrap">
                <div class="bk-summary">
                    การจอง<?= $tab==='all' ? 'ทั้งหมด' : statusLabel($tab) ?>
                    <span><?= number_format($bk_total_filtered) ?> รายการ</span>
                </div>

                <?php if(empty($bookings)): ?>
                <div class="bk-empty">
                    <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    <p>ไม่พบรายการจอง</p>
                    <small><?=$search ? "ลองเปลี่ยนคำค้นหา" : "ยังไม่มีการจองในหมวดนี้"?></small>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="bk-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ชื่อกิจกรรม</th>
                                <th>ชื่อ - นามสกุล</th>
                                <th>วัน / เวลา</th>
                                <th style="text-align:center">จำนวนคน</th>
                                <th>ราคารวม</th>
                                <th>วิธีชำระ</th>
                                <th>สถานะ</th>
                                <th style="text-align:center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($bookings as $bk): ?>
                            <tr>
                                <td><span class="bid">#<?=$bk['booking_id']?></span></td>
                                <td>
                                    <div class="act-name"><?=htmlspecialchars($bk['activity_name'])?></div>
                                </td>
                                <td>
                                    <div class="user-name-cell"><?=htmlspecialchars($bk['user_name'])?></div>
                                </td>
                                <td class="date-cell"><?=date('d/m/y, H:i น.',strtotime($bk['booking_date']))?></td>
                                <td class="people-cell"><?=$bk['num_people']?></td>
                                <td class="price-cell">฿<?=number_format($bk['total_price'])?></td>
                                <td>
                                    <?php
                        $pm_map = ['mobile'=>'&#128241; Mobile','qr'=>'&#128242; QR','card'=>'&#128179; Card'];
                        $pm_lbl = $pm_map[$bk['payment_method']] ?? '&#8212;';
                        echo $pm_lbl;
                        if ($bk['payment_method']==="mobile" && !empty($bk['bank_name'])):
                        ?><br><small
                                        style="color:var(--text-light)"><?=htmlspecialchars($bk['bank_name'])?></small><?php endif; ?>
                                </td>
                                <td>
                                    <span
                                        class="badge <?=statusClass($bk['status'])?>"><?=statusLabel($bk['status'])?></span>
                                </td>
                                <td style="text-align:center">
                                    <?php
                        // มีคนจองอยู่จริง = capacity_remaining < max_capacity
                        $has_joined = ($bk['capacity_remaining'] < $bk['capacity_total']);
                        // แสดงปุ่มจัดการเฉพาะ: Paid (มีคนจอง) หรือ Paid (ยกเลิกได้เสมอ)
                        if ($bk['status'] === 'Paid'):
                        ?>
                                    <button class="mbtn mbtn-sm" onclick="openStatusModal(
                                <?=$bk['booking_id']?>,
                                '<?=addslashes($bk['status'])?>',
                                '<?=addslashes($bk['activity_name'])?>',
                                '<?=addslashes($bk['user_name'])?>',
                                <?=$has_joined ? 'true' : 'false'?>
                            )">
                                        ⚙️ จัดการ
                                    </button>
                                    <?php else: ?>
                                    <span style="color:var(--text-light);font-size:13px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($bk_pages > 1): ?>
            <div style="display:flex;align-items:center;gap:4px;padding:14px 16px;border-top:1px solid var(--border-color,#e5e7eb);flex-wrap:wrap;">
                <span style="font-size:12px;color:var(--text-light,#9ca3af);margin-right:auto;">
                    แสดง <?= $bk_off + 1 ?>–<?= min($bk_off + $bk_limit, $bk_total_filtered) ?>
                    จาก <?= number_format($bk_total_filtered) ?> รายการ
                </span>
                <?php
                $bk_base = '?tab=' . urlencode($tab) . ($search ? '&search=' . urlencode($search) : '');
                $pg_style = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:8px;font-size:13px;text-decoration:none;border:1px solid var(--border-color,#e5e7eb);color:var(--text-light,#6b7280);background:#fff;transition:background .15s;';
                $pg_active = $pg_style . 'background:var(--primary,#2C4A2F);color:#fff;border-color:var(--primary,#2C4A2F);font-weight:600;';
                $pg_disabled = $pg_style . 'opacity:.35;pointer-events:none;';
                echo '<a style="' . ($bk_page <= 1 ? $pg_disabled : $pg_style) . '" href="' . $bk_base . '&page=' . ($bk_page - 1) . '">‹</a>';
                $st = max(1, $bk_page - 2); $en = min($bk_pages, $bk_page + 2);
                if ($st > 1) echo '<span style="' . $pg_style . '">…</span>';
                for ($i = $st; $i <= $en; $i++) echo '<a style="' . ($i === $bk_page ? $pg_active : $pg_style) . '" href="' . $bk_base . '&page=' . $i . '">' . $i . '</a>';
                if ($en < $bk_pages) echo '<span style="' . $pg_style . '">…</span>';
                echo '<a style="' . ($bk_page >= $bk_pages ? $pg_disabled : $pg_style) . '" href="' . $bk_base . '&page=' . ($bk_page + 1) . '">›</a>';
                ?>
            </div>
            <?php endif; ?>

        </main><!-- /.main-content -->
    </div><!-- /#wrapper -->

    <!-- ══ Status Modal ══ -->
    <div class="modal-overlay" id="statusModalOverlay">
        <div class="modal" style="max-width:460px">
            <button class="modal-close" onclick="closeStatusModal()">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18" />
                    <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
            </button>
            <h2>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                    <line x1="16" y1="2" x2="16" y2="6" />
                    <line x1="8" y1="2" x2="8" y2="6" />
                    <line x1="3" y1="10" x2="21" y2="10" />
                </svg>
                อัปเดตสถานะการจอง
            </h2>

            <div id="modalBookingInfo"
                style="background:var(--bg-cream);border-radius:12px;padding:14px 18px;margin-bottom:22px;font-family:'Kanit',sans-serif;font-size:14px;color:var(--text-dark);">
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:4px;">
                    <strong id="modalBid" style="color:var(--primary-dark)"></strong>
                    <span id="modalCurrentBadge"></span>
                </div>
                <div id="modalActName" style="font-weight:600;margin-bottom:2px;"></div>
                <div id="modalUserName" style="color:var(--text-light);font-size:13px;"></div>
            </div>

            <p style="font-family:'Kanit',sans-serif;font-size:13px;color:var(--text-light);margin-bottom:14px;">
                เลือกสถานะใหม่:</p>

            <div id="statusOptions" style="display:flex;flex-direction:column;gap:10px;"></div>

            <div class="modal-footer">
                <button class="mbtn mbtn-cancel" onclick="closeStatusModal()">ยกเลิก</button>
            </div>
        </div>
    </div>

    <script>
    let currentBid = null;

    const STATUS_TRANSITIONS = {
        'Pending': ['Cancel'],
        'Paid': ['Completed', 'Cancel'],
        'Completed': [],
        'Cancel': [],
    };

    const STATUS_CFG = {
        'Pending': {
            label: 'รอยืนยัน',
            cls: 'badge-pending',
            icon: '⏳'
        },
        'Paid': {
            label: 'ชำระแล้ว',
            cls: 'badge-confirmed',
            icon: '✅'
        },
        'Completed': {
            label: 'เสร็จสิ้น',
            cls: 'badge-completed',
            icon: '🏁'
        },
        'Cancel': {
            label: 'ยกเลิก',
            cls: 'badge-cancelled',
            icon: '❌'
        },
    };

    const ACTION_CFG = {
        'Completed': {
            btnClass: 'mbtn-confirm',
            btnStyle: 'background:#1565C0',
            text: '🏁 ยืนยันเสร็จสิ้นกิจกรรม',
            confirmMsg: 'ยืนยันว่ากิจกรรมเสร็จสิ้นแล้ว? จำนวนคนเข้าร่วมจะถูก Reset กลับเป็น 0 และกิจกรรมยังคง Active รับจองรอบใหม่ได้'
        },
        'Cancel': {
            btnClass: 'mbtn-confirm',
            btnStyle: 'background:#DC2626',
            text: '❌ ยกเลิกการจอง',
            confirmMsg: 'ยกเลิกการจองนี้หรือไม่? จะคืน capacity ให้กิจกรรม'
        },
    };

    function openStatusModal(bid, currentStatus, actName, userName, hasJoined) {
        currentBid = bid;

        document.getElementById('modalBid').textContent = '#' + bid;
        document.getElementById('modalActName').textContent = actName;
        document.getElementById('modalUserName').textContent = '👤 ' + userName;

        const cfg = STATUS_CFG[currentStatus];
        document.getElementById('modalCurrentBadge').innerHTML =
            `<span class="badge ${cfg.cls}" style="font-size:11px;padding:3px 10px">${cfg.icon} ${cfg.label}</span>`;

        // กรอง Completed ออกถ้าไม่มีคนจอง (capacity ยังเต็ม)
        let transitions = STATUS_TRANSITIONS[currentStatus] || [];
        if (!hasJoined) {
            transitions = transitions.filter(s => s !== 'Completed');
        }

        const container = document.getElementById('statusOptions');
        container.innerHTML = '';

        if (transitions.length === 0) {
            container.innerHTML =
                '<p style="font-family:Kanit,sans-serif;color:var(--text-light);font-size:14px;text-align:center;padding:10px 0;">สถานะนี้ไม่สามารถเปลี่ยนได้แล้ว</p>';
        } else {
            transitions.forEach(ns => {
                const ac = ACTION_CFG[ns];
                const btn = document.createElement('button');
                btn.className = `mbtn ${ac.btnClass}`;
                btn.style = ac.btnStyle +
                    ';width:100%;justify-content:center;display:flex;align-items:center;gap:8px;padding:13px 20px;font-size:15px;';
                btn.innerHTML = ac.text;
                btn.onclick = () => confirmStatusChange(bid, ns, ac.confirmMsg);
                container.appendChild(btn);
            });
        }

        document.getElementById('statusModalOverlay').classList.add('open');
    }

    function closeStatusModal() {
        document.getElementById('statusModalOverlay').classList.remove('open');
        currentBid = null;
    }

    async function confirmStatusChange(bid, newStatus, msg) {
        closeStatusModal();

        const result = await Swal.fire({
            icon: newStatus === 'Cancel' ? 'warning' : newStatus === 'Completed' ? 'success' : 'question',
            title: msg,
            html: `<span style="font-family:Kanit,sans-serif;font-size:14px;color:#666">การจอง #${bid}</span>`,
            showCancelButton: true,
            confirmButtonText: STATUS_CFG[newStatus].label,
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: newStatus === 'Cancel' ? '#DC2626' : newStatus === 'Completed' ? '#1565C0' :
                '#2C4A2F',
            reverseButtons: true,
            fontFamily: 'Kanit, sans-serif',
        });

        if (!result.isConfirmed) return;

        try {
            const fd = new FormData();
            fd.append('action', 'update_status');
            fd.append('booking_id', bid);
            fd.append('new_status', newStatus);

            const r = await fetch('/tkn/handlers/booking_handle.php', {
                method: 'POST',
                body: fd
            });
            const text = await r.text();
            let d;
            try {
                d = JSON.parse(text);
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Server Error',
                    html: `<pre style="font-size:11px;text-align:left;max-height:180px;overflow:auto">${text.substring(0,600)}</pre>`
                });
                return;
            }

            if (d.ok) {
                await Swal.fire({
                    icon: 'success',
                    title: 'อัปเดตสำเร็จ',
                    text: `เปลี่ยนสถานะเป็น "${STATUS_CFG[newStatus].label}" แล้ว`,
                    showConfirmButton: false,
                    timer: 1400,
                });
                location.reload();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'เกิดข้อผิดพลาด',
                    text: d.msg || 'ไม่ทราบสาเหตุ'
                });
            }
        } catch (e) {
            console.error(e);
            Swal.fire({
                icon: 'error',
                title: 'เชื่อมต่อไม่ได้',
                text: e.message
            });
        }
    }

    // close modal on overlay click
    document.getElementById('statusModalOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeStatusModal();
    });

    // Sidebar / Notification / User Menu (same as dashboard2)
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
    </script>
</body>

</html>
