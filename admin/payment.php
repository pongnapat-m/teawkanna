<?php
require_once __DIR__ . '/../config/env.php';
ob_start();
session_start();
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
include '../db.php';

// ── Helper: แปลง slip_image path ให้ถูกต้องเสมอ ────────────────────────────
// DB เก็บ path แบบ 'uploads/slips/...' (relative จาก handlers/)
// ใช้ resolvePic() จาก config/url.php เพื่อให้แน่ใจว่าแปลง path ตรงกับระบบรูปส่วนอื่นๆ
function slipUrl(string $path): string {
    if (empty($path)) return '';
    if (function_exists('resolvePic')) {
        return resolvePic($path);
    }
    // Fallback logic
    if (str_starts_with($path, 'http')) return $path;
    return '/tkn/handlers/' . ltrim($path, '/');
}

// ── Auth check ───────────────────────────────────────────────────────────────
// แก้ไข: ใช้ array_key_exists แทน isset เพื่อรองรับ admin_id = 0
if (!array_key_exists('user_id', $_SESSION) || $_SESSION['role'] !== 'admin') {
    header('Location: /tkn/login');
    exit;
}

$admin_name   = $_SESSION['fullname'] ?? 'Admin';
$current_page = 'payment';
$page_title   = 'Payments';

// ── Monthly revenue AJAX (GET) ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'monthly_stats') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $ym = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $view = $_GET['view'] ?? 'both';
    if (!in_array($view, ['shop', 'activity', 'both'], true)) $view = 'both';
    $shop_id = max(0, (int)($_GET['shop_id'] ?? 0));
    $activity_id = max(0, (int)($_GET['activity_id'] ?? 0));

    $filterSql = ' AND (? = 0 OR s.shop_id = ?)
                   AND (? = 0 OR a.activity_id = ?)';

    $mq = $conn->prepare(
        "SELECT COUNT(p.payment_id) AS cnt, COALESCE(SUM(p.amount), 0) AS total
         FROM payment p
         JOIN booking b ON p.booking_id = b.booking_id
         JOIN activity a ON b.activity_id = a.activity_id
         JOIN shop s ON a.shop_id = s.shop_id
         WHERE p.status = 'Approved'
           AND DATE_FORMAT(p.payment_date, '%Y-%m') = ? {$filterSql}"
    );
    $mq->bind_param('siiii', $ym, $shop_id, $shop_id, $activity_id, $activity_id);
    $mq->execute();
    $mrow = $mq->get_result()->fetch_assoc();
    $mq->close();

    $groupConfig = [
        'shop' => [
            'select' => 's.shop_id, s.shop_name, NULL AS activity_id, NULL AS activity_name',
            'group' => 's.shop_id, s.shop_name',
        ],
        'activity' => [
            'select' => 'NULL AS shop_id, NULL AS shop_name, a.activity_id, a.activity_name',
            'group' => 'a.activity_id, a.activity_name',
        ],
        'both' => [
            'select' => 's.shop_id, s.shop_name, a.activity_id, a.activity_name',
            'group' => 's.shop_id, s.shop_name, a.activity_id, a.activity_name',
        ],
    ];
    $group = $groupConfig[$view];
    $tq = $conn->prepare(
        "SELECT {$group['select']}, COUNT(p.payment_id) AS cnt, SUM(p.amount) AS total
         FROM payment p
         JOIN booking b  ON p.booking_id  = b.booking_id
         JOIN activity a ON b.activity_id = a.activity_id
         JOIN shop s ON a.shop_id = s.shop_id
         WHERE p.status = 'Approved'
           AND DATE_FORMAT(p.payment_date,'%Y-%m') = ? {$filterSql}
         GROUP BY {$group['group']}
         ORDER BY total DESC, cnt DESC
         LIMIT 50"
    );
    $tq->bind_param('siiii', $ym, $shop_id, $shop_id, $activity_id, $activity_id);
    $tq->execute();
    $top = $tq->get_result()->fetch_all(MYSQLI_ASSOC);
    $tq->close();

    $shops = $conn->query(
        "SELECT shop_id, shop_name FROM shop ORDER BY shop_name ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $aq = $conn->prepare(
        "SELECT a.activity_id, a.activity_name, a.shop_id, s.shop_name
         FROM activity a
         JOIN shop s ON a.shop_id = s.shop_id
         WHERE (? = 0 OR a.shop_id = ?)
         ORDER BY s.shop_name ASC, a.activity_name ASC"
    );
    $aq->bind_param('ii', $shop_id, $shop_id);
    $aq->execute();
    $activities = $aq->get_result()->fetch_all(MYSQLI_ASSOC);
    $aq->close();

    echo json_encode([
        'ok'         => true,
        'month'      => $ym,
        'view'       => $view,
        'count'      => (int)($mrow['cnt'] ?? 0),
        'total'      => (float)($mrow['total'] ?? 0),
        'top'        => array_slice($top, 0, 5),
        'details'    => $top,
        'shops'      => $shops,
        'activities' => $activities,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Handle approve / reject (POST) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ล้าง buffer ทั้งหมดก่อน — ป้องกัน HTML หรือ whitespace ปน JSON
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action     = $_POST['action']     ?? '';
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if (!$payment_id || !in_array($action, ['approve','reject'])) {
        echo json_encode(['status'=>'error','message'=>'Invalid request']);
        exit;
    }

    $pq = $conn->prepare("SELECT p.*, b.booking_id, b.activity_id, b.user_id FROM payment p JOIN booking b ON p.booking_id=b.booking_id WHERE p.payment_id=?");
    $pq->bind_param('i', $payment_id);
    $pq->execute();
    $pay = $pq->get_result()->fetch_assoc();
    $pq->close();

    if (!$pay) { echo json_encode(['status'=>'error','message'=>'ไม่พบ payment']); exit; }

    if ($action === 'approve') {
        $upd1 = $conn->prepare("UPDATE payment SET status='Approved', admin_note=? WHERE payment_id=?");
        $upd1->bind_param('si', $note, $payment_id);
        $upd1->execute(); $upd1->close();

        $bid = (int)$pay['booking_id'];
        $conn->query("UPDATE booking SET status='Paid' WHERE booking_id={$bid}");

        // หัก capacity_remaining ของ activity
        $bk2 = $conn->prepare("SELECT activity_id, adult_quantity + kid_quantity AS pax FROM booking WHERE booking_id = ?");
        $bk2->bind_param("i", $bid);
        $bk2->execute();
        $brow = $bk2->get_result()->fetch_assoc();
        $bk2->close();
        if ($brow) {
            $cap = $conn->prepare("UPDATE activity SET capacity_remaining = capacity_remaining - ? WHERE activity_id = ?");
            $cap->bind_param("ii", $brow['pax'], $brow['activity_id']);
            $cap->execute(); $cap->close();
        }

        $uid = (int)$pay['user_id'];
        $aid = (int)$pay['activity_id'];
        $chk = $conn->prepare("SELECT passport_id FROM activity_passport WHERE user_id=? AND booking_id=?");
        $chk->bind_param('ii', $uid, $bid);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $pts = $conn->prepare("INSERT INTO activity_passport (user_id, activity_id, booking_id, points_earned) VALUES (?,?,?,10)");
            $pts->bind_param('iii', $uid, $aid, $bid);
            $pts->execute(); $pts->close();
            $conn->query("UPDATE user SET point=point+10 WHERE user_id={$uid}");
        }
        $chk->close();
        echo json_encode(['status'=>'success','message'=>'อนุมัติสำเร็จ']);

    } else {
        $upd2 = $conn->prepare("UPDATE payment SET status='Rejected', admin_note=? WHERE payment_id=?");
        $upd2->bind_param('si', $note, $payment_id);
        $upd2->execute(); $upd2->close();
        // คืน booking status กลับเป็น Pending ให้ user รู้ว่าถูกปฏิเสธและต้องชำระใหม่
        $bid = (int)$pay['booking_id'];
        $conn->query("UPDATE booking SET status='Rejected' WHERE booking_id={$bid}");
        echo json_encode(['status'=>'success','message'=>'ปฏิเสธสำเร็จ']);
    }
    exit;
}

// ── Fetch pending payments (รอ admin review — ไม่รวม Omise auto-process) ──────
$result1 = $conn->query("
    SELECT p.payment_id, p.amount, p.payment_date, p.payment_method,
           p.slip_image, p.status, p.charge_id,
           b.booking_id, b.travel_date, b.adult_quantity, b.kid_quantity,
           a.activity_name,
           u.fullname, u.email, u.phonenumber as phone
    FROM payment p
    JOIN booking  b ON p.booking_id  = b.booking_id
    JOIN activity a ON b.activity_id = a.activity_id
    JOIN user     u ON b.user_id     = u.user_id
    WHERE p.status IN ('Pending','PendingReview')
    ORDER BY p.payment_date DESC
");
if (!$result1) die("Query error (pending): " . $conn->error);
$rows = $result1->fetch_all(MYSQLI_ASSOC);

// ── Fetch Omise pending (รอ webhook — แสดงแยก ไม่ต้อง approve มือ) ──────────
$result_omise = $conn->query("
    SELECT p.payment_id, p.amount, p.payment_date, p.payment_method,
           p.charge_id, p.status,
           b.booking_id, a.activity_name, u.fullname
    FROM payment p
    JOIN booking  b ON p.booking_id  = b.booking_id
    JOIN activity a ON b.activity_id = a.activity_id
    JOIN user     u ON b.user_id     = u.user_id
    WHERE p.status IN ('Pending','PendingReview')
    AND p.payment_method LIKE '%omise%'
    ORDER BY p.payment_date DESC
");
$omise_pending = $result_omise ? $result_omise->fetch_all(MYSQLI_ASSOC) : [];

$result2 = $conn->query("
    SELECT p.payment_id, p.amount, p.payment_date, p.payment_method,
           p.slip_image, p.status, p.charge_id, p.admin_note,
           b.booking_id, a.activity_name, u.fullname
    FROM payment p
    JOIN booking  b ON p.booking_id  = b.booking_id
    JOIN activity a ON b.activity_id = a.activity_id
    JOIN user     u ON b.user_id     = u.user_id
    WHERE p.status IN ('Approved','Rejected')
    ORDER BY p.payment_date DESC
    LIMIT 50
");
if (!$result2) die("Query error (history): " . $conn->error);
$approved = $result2->fetch_all(MYSQLI_ASSOC);

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar" style="
      position:sticky;top:0;z-index:100;
      display:flex;align-items:center;justify-content:space-between;
      padding:0 2rem;
      background:rgba(250,248,243,0.92);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border-bottom:1px solid var(--border-light);">
        <div class="topbar-left">
            <h1 class="page-title">💳 อนุมัติการชำระเงิน</h1>
        </div>
        <div class="topbar-right">
            <div class="user-menu-wrapper">
                <button class="user-menu-btn">
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
                <div class="user-dropdown">
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

    <!-- Page body -->
    <div class="page-body">

        <!-- ── สรุปรายได้รายเดือน ── -->
        <div class="section-card" style="padding:0;overflow:hidden;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px 14px;border-bottom:1px solid var(--border-light);">
                <div style="display:flex;align-items:center;gap:10px;">
                    <svg width="18" height="18" fill="none" stroke="var(--accent)" stroke-width="2" viewBox="0 0 24 24">
                        <line x1="12" y1="1" x2="12" y2="23" />
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                    </svg>
                    <span style="font-size:15px;font-weight:700;color:var(--text1);">สรุปรายได้</span>
                </div>
                <!-- Month navigator -->
                <div style="display:flex;align-items:center;gap:8px;">
                    <button id="prevMonthBtn"
                        style="width:32px;height:32px;border-radius:8px;border:1.5px solid var(--border-light);background:var(--bg);color:var(--text1);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:.15s;"
                        onmouseover="this.style.background='var(--border-light)'"
                        onmouseout="this.style.background='var(--bg)'">‹</button>
                    <span id="monthNavLabel"
                        style="font-family:'Kanit',sans-serif;font-size:14px;font-weight:600;min-width:110px;text-align:center;color:var(--text1);"></span>
                    <button id="nextMonthBtn"
                        style="width:32px;height:32px;border-radius:8px;border:1.5px solid var(--border-light);background:var(--bg);color:var(--text1);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:.15s;"
                        onmouseover="this.style.background='var(--border-light)'"
                        onmouseout="this.style.background='var(--bg)'">›</button>
                </div>
            </div>

            <div
                style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px 24px;background:rgba(44,74,47,.035);border-bottom:1px solid var(--border-light);">
                <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:var(--text3);">
                    มุมมอง
                    <select id="revenueView"
                        style="min-width:180px;padding:8px 10px;border:1.5px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text1);font-family:'Kanit',sans-serif;">
                        <option value="both">ร้านค้า + กิจกรรม</option>
                        <option value="shop">ร้านค้า</option>
                        <option value="activity">กิจกรรม</option>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:var(--text3);">
                    ร้านค้า
                    <select id="revenueShop"
                        style="min-width:190px;padding:8px 10px;border:1.5px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text1);font-family:'Kanit',sans-serif;">
                        <option value="0">ทุกร้านค้า</option>
                    </select>
                </label>
                <label style="display:flex;flex-direction:column;gap:5px;font-size:11px;color:var(--text3);">
                    กิจกรรม
                    <select id="revenueActivity"
                        style="min-width:220px;padding:8px 10px;border:1.5px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text1);font-family:'Kanit',sans-serif;">
                        <option value="0">ทุกกิจกรรม</option>
                    </select>
                </label>
                <button type="button" id="resetRevenueFilters"
                    style="padding:8px 14px;border:1.5px solid var(--border-light);border-radius:8px;background:var(--bg);color:var(--text2);cursor:pointer;font-family:'Kanit',sans-serif;">
                    ล้างตัวกรอง
                </button>
            </div>

            <!-- ตัวเลขหลัก -->
            <div style="display:flex;align-items:stretch;gap:0;flex-wrap:wrap;">
                <!-- รายได้รวม -->
                <div style="flex:1;min-width:180px;padding:22px 28px;border-right:1px solid var(--border-light);">
                    <div
                        style="font-size:12px;color:var(--text3);margin-bottom:6px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;">
                        รายได้รวม</div>
                    <div id="revTotal"
                        style="font-size:32px;font-weight:800;color:var(--accent);font-family:'Kanit',sans-serif;line-height:1;">
                        ฿0</div>
                    <div id="revCount" style="font-size:12px;color:var(--text3);margin-top:6px;">0 รายการที่อนุมัติ
                    </div>
                </div>
                <!-- Top กิจกรรม -->
                <div style="flex:2;min-width:260px;padding:18px 24px;">
                    <div
                        style="font-size:12px;color:var(--text3);margin-bottom:10px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;">
                        <span id="revRankingTitle">รายได้แยกตามร้านค้าและกิจกรรม</span></div>
                    <div id="revTopList" style="display:flex;flex-direction:column;gap:7px;"></div>
                    <div id="revEmpty" style="display:none;font-size:13px;color:var(--text3);padding:10px 0;">
                        ยังไม่มีข้อมูลเดือนนี้</div>
                </div>
            </div>

            <div style="padding:0 24px 22px;">
                <div style="font-size:13px;font-weight:700;color:var(--text1);margin:4px 0 10px;">
                    รายละเอียดรายได้
                </div>
                <div style="overflow-x:auto;border:1px solid var(--border-light);border-radius:10px;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:rgba(44,74,47,.05);color:var(--text2);text-align:left;">
                                <th id="revDetailPrimaryHead" style="padding:10px 12px;">ร้านค้า</th>
                                <th id="revDetailSecondaryHead" style="padding:10px 12px;">กิจกรรม</th>
                                <th style="padding:10px 12px;text-align:right;">รายการ</th>
                                <th style="padding:10px 12px;text-align:right;">รายได้</th>
                                <th style="padding:10px 12px;text-align:right;">สัดส่วน</th>
                            </tr>
                        </thead>
                        <tbody id="revDetailBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Omise รอ webhook ── -->



        <!-- ── รอตรวจสอบ (สลิป manual) ── -->
        <div class="section-card">
            <div class="section-head">
                <div class="section-head-title">
                    🔔 รอตรวจสอบ
                    <?php if (count($rows)): ?>
                    <span class="count-badge"
                        style="background:rgba(229,57,53,.15);color:#E53935;"><?= count($rows) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($rows)): ?>
            <div style="padding:40px;text-align:center;color:var(--text3);font-size:14px;">
                ✅ ไม่มีรายการรอตรวจสอบ
            </div>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;padding:20px;">
                <?php foreach ($rows as $p): ?>
                <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;"
                    id="card_<?= $p['payment_id'] ?>">
                    <div
                        style="padding:14px 18px;border-bottom:1.5px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div style="font-size:13px;font-weight:700;color:#1D3718;">Booking #<?= $p['booking_id'] ?>
                            </div>
                            <div style="font-size:11px;color:#888;margin-top:2px;">
                                <?= htmlspecialchars($p['activity_name']) ?></div>
                        </div>
                        <?php
            $chipStyle = $p['status'] === 'PendingReview'
              ? 'background:#E3F2FD;color:#0D47A1;border:1px solid #90CAF9;'
              : 'background:#FFF8E1;color:#856404;border:1px solid #FFDCA6;';
          ?>
                        <span
                            style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;<?= $chipStyle ?>">
                            <?= $p['status'] === 'PendingReview' ? '🧾 มีสลิป' : '⏳ รอสลิป' ?>
                        </span>
                    </div>
                    <div style="padding:14px 18px;">
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>ลูกค้า</span><strong
                                style="color:#1a1a1a;"><?= htmlspecialchars($p['fullname']) ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>โทร</span><strong
                                style="color:#1a1a1a;"><?= htmlspecialchars($p['phone'] ?? '-') ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>วันเดินทาง</span><strong style="color:#1a1a1a;"><?= $p['travel_date'] ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>จำนวน</span>
                            <strong style="color:#1a1a1a;"><?= ($p['adult_quantity']??0) ?>A
                                <?= ($p['kid_quantity']??0) > 0 ? '/ '.($p['kid_quantity']).'K' : '' ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>ยอดชำระ</span><strong
                                style="color:#1D3718;font-size:15px;">฿<?= number_format($p['amount'],2) ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>วิธีชำระ</span><strong
                                style="color:#1a1a1a;"><?= htmlspecialchars($p['payment_method'] ?? '-') ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>Ref</span><strong
                                style="font-family:monospace;font-size:11px;color:#1a1a1a;"><?= htmlspecialchars($p['charge_id'] ?? '-') ?></strong>
                        </div>
                        <div
                            style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:6px;">
                            <span>เวลา</span><strong style="color:#1a1a1a;"><?= $p['payment_date'] ?></strong>
                        </div>

                        <?php if ($p['slip_image']): ?>
                        <img style="width:100%;max-height:180px;object-fit:contain;border-radius:8px;border:1px solid #eee;cursor:zoom-in;margin:10px 0;background:#fafafa;"
                            src="<?= htmlspecialchars(slipUrl($p['slip_image'])) ?>" alt="สลิป"
                            onclick="openLightbox(this.src)">
                        <?php else: ?>
                        <div
                            style="padding:12px;border:2px dashed #ddd;border-radius:8px;text-align:center;font-size:12px;color:#aaa;margin:10px 0;">
                            ยังไม่ได้แนบสลิป</div>
                        <?php endif; ?>

                        <textarea id="note_<?= $p['payment_id'] ?>" rows="2" placeholder="หมายเหตุ (ถ้ามี)"
                            style="width:100%;margin-top:8px;padding:8px 10px;border:1.5px solid #ddd;border-radius:8px;font-family:inherit;font-size:12px;resize:none;outline:none;"></textarea>
                        <div style="display:flex;gap:8px;margin-top:12px;">
                            <button
                                style="padding:9px 16px;border:none;border-radius:8px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;flex:1;background:#2C5A22;color:#fff;"
                                onclick="payAction('approve', <?= $p['payment_id'] ?>)">✓ อนุมัติ</button>
                            <button
                                style="padding:9px 16px;border-radius:8px;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;flex:1;background:#fff;color:#B71C1C;border:1.5px solid #FFCDD2;"
                                onclick="payAction('reject', <?= $p['payment_id'] ?>)">✕ ปฏิเสธ</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div><!-- end card grid -->
            <?php endif; ?>
        </div><!-- end section-card -->

        <!-- ── ประวัติ ── -->
        <div class="section-card" style="margin-top:16px;">
            <div class="section-head">
                <div class="section-head-title">
                    📋 ประวัติล่าสุด (50 รายการ)
                </div>
            </div>
            <?php if (empty($approved)): ?>
            <div style="padding:30px;text-align:center;color:var(--text3);font-size:14px;">ยังไม่มีรายการ</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Booking</th>
                            <th>ลูกค้า</th>
                            <th>กิจกรรม</th>
                            <th>ยอด</th>
                            <th>วิธีชำระ</th>
                            <th>สถานะ</th>
                            <th>สลิป</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved as $p):
            $chipStyle = $p['status'] === 'Approved'
              ? 'background:#E8F5E9;color:#1B5E20;border:1px solid #A5D6A7;'
              : 'background:#FFEBEE;color:#B71C1C;border:1px solid #FFCDD2;';
          ?>
                        <tr>
                            <td>#<?= $p['payment_id'] ?></td>
                            <td>#<?= $p['booking_id'] ?></td>
                            <td><?= htmlspecialchars($p['fullname']) ?></td>
                            <td style="font-size:11px;"><?= htmlspecialchars($p['activity_name']) ?></td>
                            <td>฿<?= number_format($p['amount'],2) ?></td>
                            <td style="font-size:11px;"><?= htmlspecialchars($p['payment_method']??'-') ?></td>
                            <td>
                                <span
                                    style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;<?= $chipStyle ?>"><?= $p['status'] ?></span>
                            </td>
                            <td>
                                <?php if ($p['slip_image']): ?>
                                <a style="color:var(--green);text-decoration:underline;font-size:11px;"
                                    href="<?= htmlspecialchars(slipUrl($p['slip_image'])) ?>" target="_blank">ดูสลิป</a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td style="font-size:11px;color:var(--text3);">
                                <?= htmlspecialchars($p['admin_note']??'-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div><!-- overflow-x:auto -->
            <?php endif; ?>
        </div><!-- end section-card history -->

    </div><!-- /page-body -->
</div><!-- end .main -->

<!-- ── Lightbox ── -->
<div id="slipLightbox" onclick="closeLightbox()"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;">
    <button onclick="event.stopPropagation();closeLightbox()"
        style="position:absolute;top:20px;right:24px;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:24px;cursor:pointer;border-radius:50%;width:40px;height:40px;">✕</button>
    <img id="lightboxImg" src="" alt="สลิป"
        style="max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain;">
</div>

<!-- confirmModal ใช้ #confirmOverlay จาก admin_footer.php แทน -->

<!-- ══ REJECT REASON MODAL ══ -->
<div id="rejectReasonOverlay"
    style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center;">
    <div
        style="background:#fff;border-radius:16px;padding:28px 28px 24px;width:min(440px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.2);font-family:'Kanit',sans-serif;">
        <h3 style="margin:0 0 6px;font-size:17px;font-weight:700;color:#B71C1C;">✕ ปฏิเสธการชำระเงิน</h3>
        <p id="rejectPaymentLabel" style="margin:0 0 18px;font-size:13px;color:#666;"></p>

        <div style="margin-bottom:16px;">
            <div style="font-size:13px;font-weight:600;color:#333;margin-bottom:10px;">เหตุผลในการปฏิเสธ <span
                    style="color:#B71C1C">*</span></div>
            <div id="rejectReasonList" style="display:flex;flex-direction:column;gap:8px;">
                <?php
                $reject_reasons = [
                    'สลิปไม่ชัดเจน / อ่านไม่ออก',
                    'ยอดเงินไม่ตรงกับที่ระบุ',
                    'สลิปซ้ำหรือถูกใช้ไปแล้ว',
                    'ชื่อผู้โอนไม่ตรง',
                    'เลขที่บัญชีปลายทางไม่ถูกต้อง',
                    'สลิปหมดอายุ / วันที่ไม่ตรง',
                    'อื่นๆ (ระบุด้านล่าง)',
                ];
                foreach ($reject_reasons as $i => $r): ?>
                <label
                    style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid #eee;border-radius:10px;cursor:pointer;transition:border-color .15s,background .15s;"
                    class="reason-item">
                    <input type="radio" name="rejectReason" value="<?= htmlspecialchars($r) ?>"
                        style="accent-color:#B71C1C;width:16px;height:16px;flex-shrink:0;"
                        <?= $i === 0 ? 'checked' : '' ?>>
                    <span style="font-size:13px;color:#333;"><?= htmlspecialchars($r) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="rejectCustomNoteWrap" style="display:none;margin-bottom:16px;">
            <textarea id="rejectCustomNote" rows="2" placeholder="ระบุเหตุผล..."
                style="width:100%;padding:10px 12px;border:1.5px solid #ddd;border-radius:10px;font-family:inherit;font-size:13px;resize:none;outline:none;box-sizing:border-box;"></textarea>
        </div>

        <div style="display:flex;gap:10px;margin-top:4px;">
            <button onclick="closeRejectModal()"
                style="flex:1;padding:10px;border:1.5px solid #ddd;border-radius:10px;background:#fff;font-family:inherit;font-size:13px;font-weight:600;cursor:pointer;color:#555;">ยกเลิก</button>
            <button onclick="confirmReject()"
                style="flex:1;padding:10px;border:none;border-radius:10px;background:#B71C1C;color:#fff;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;">✕
                ยืนยันปฏิเสธ</button>
        </div>
    </div>
</div>

<script>
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('slipLightbox').style.display = 'flex';
}

function closeLightbox() {
    document.getElementById('slipLightbox').style.display = 'none';
}

// ใช้ระบบ confirm ของ admin_footer.php โดยตรง
// เปลี่ยนชื่อเป็น payAction เพื่อไม่ชนกับ doAction ของ footer
var _rejectPaymentId = null;

function closeRejectModal() {
    document.getElementById('rejectReasonOverlay').style.display = 'none';
    _rejectPaymentId = null;
}

function openRejectModal(paymentId) {
    _rejectPaymentId = paymentId;
    document.getElementById('rejectPaymentLabel').textContent = 'Payment #' + paymentId;
    // reset radio to first option
    var radios = document.querySelectorAll('input[name="rejectReason"]');
    if (radios.length) radios[0].checked = true;
    document.getElementById('rejectCustomNoteWrap').style.display = 'none';
    document.getElementById('rejectCustomNote').value = '';
    // highlight selected
    updateReasonHighlight();
    document.getElementById('rejectReasonOverlay').style.display = 'flex';
}

function updateReasonHighlight() {
    document.querySelectorAll('.reason-item').forEach(function(el) {
        var radio = el.querySelector('input[type="radio"]');
        el.style.borderColor = radio.checked ? '#B71C1C' : '#eee';
        el.style.background = radio.checked ? '#FFF5F5' : '#fff';
    });
    var selected = document.querySelector('input[name="rejectReason"]:checked');
    var isCustom = selected && selected.value.startsWith('อื่นๆ');
    document.getElementById('rejectCustomNoteWrap').style.display = isCustom ? 'block' : 'none';
}

// เพิ่ม event listener ให้ radio buttons
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="rejectReason"]').forEach(function(r) {
        r.addEventListener('change', updateReasonHighlight);
    });
    // ปิด modal เมื่อคลิก overlay
    document.getElementById('rejectReasonOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeRejectModal();
    });
});

async function confirmReject() {
    if (!_rejectPaymentId) return;
    var selected = document.querySelector('input[name="rejectReason"]:checked');
    if (!selected) {
        showToast('กรุณาเลือกเหตุผลในการปฏิเสธ', 'var(--red)');
        return;
    }
    var reason = selected.value;
    if (reason.startsWith('อื่นๆ')) {
        var custom = document.getElementById('rejectCustomNote').value.trim();
        if (!custom) {
            showToast('กรุณาระบุเหตุผล', 'var(--red)');
            document.getElementById('rejectCustomNote').focus();
            return;
        }
        reason = 'อื่นๆ: ' + custom;
    }
    // snapshot ก่อน closeRejectModal() จะ null _rejectPaymentId
    var pid = _rejectPaymentId;
    var cardNote = (document.getElementById('note_' + pid) || {}).value || '';
    var fullNote = reason + (cardNote ? ' | ' + cardNote : '');

    closeRejectModal();
    await submitPayAction('reject', pid, fullNote);
}

async function payAction(action, paymentId) {
    var isApprove = action === 'approve';

    if (!isApprove) {
        // เปิด reject reason modal แทน
        openRejectModal(paymentId);
        return;
    }

    // approve: ใช้ confirmOverlay เดิม
    var overlay = document.getElementById('confirmOverlay');
    document.getElementById('cfTitle').textContent = 'อนุมัติการชำระเงิน';
    document.getElementById('cfMsg').textContent = 'Payment #' + paymentId;
    var btn = document.getElementById('cfBtn');
    btn.className = 'cbtn cbtn-confirm-ok';
    btn.textContent = '✓ อนุมัติ';
    overlay.classList.add('open');

    // รอการตัดสินใจ
    var confirmed = await new Promise(function(resolve) {
        function onOk() {
            cleanup();
            resolve(true);
        }

        function onCancel() {
            cleanup();
            resolve(false);
        }

        function onBg(e) {
            if (e.target === overlay) {
                cleanup();
                resolve(false);
            }
        }

        function cleanup() {
            btn.removeEventListener('click', onOk);
            document.querySelector('#confirmOverlay .cbtn-cancel').removeEventListener('click', onCancel);
            overlay.removeEventListener('click', onBg);
            overlay.classList.remove('open');
        }
        btn.addEventListener('click', onOk);
        document.querySelector('#confirmOverlay .cbtn-cancel').addEventListener('click', onCancel);
        overlay.addEventListener('click', onBg);
    });

    if (!confirmed) return;
    var note = (document.getElementById('note_' + paymentId) || {}).value || '';
    await submitPayAction('approve', paymentId, note);
}

async function submitPayAction(action, paymentId, note) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('payment_id', paymentId);
    fd.append('note', note);

    try {
        var r = await fetch(location.pathname, {
            method: 'POST',
            body: fd
        });
        var text = await r.text();
        var d;
        try {
            d = JSON.parse(text);
        } catch (e) {
            showToast('✗ Server ตอบกลับผิดปกติ', 'var(--red)');
            return;
        }

        if (d.status === 'success') {
            var card = document.getElementById('card_' + paymentId);
            if (card) {
                card.style.transition = 'opacity .3s';
                card.style.opacity = '0';
                setTimeout(function() {
                    card.remove();
                }, 320);
            }
            showToast('✓ ' + d.message, 'var(--green)');
        } else {
            showToast('✗ ' + d.message, 'var(--red)');
        }
    } catch (e) {
        showToast('✗ เชื่อมต่อไม่ได้', 'var(--red)');
    }
}

// Auto-refresh ทุก 30 วิ
setTimeout(function() {
    location.reload();
}, 30000);

/* ════════════════════════════════════════════════
   Monthly Revenue Widget
   ════════════════════════════════════════════════ */
(function() {
    const thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];

    let curYear = new Date().getFullYear();
    let curMonth = new Date().getMonth() + 1;
    const viewSelect = document.getElementById('revenueView');
    const shopSelect = document.getElementById('revenueShop');
    const activitySelect = document.getElementById('revenueActivity');

    function ymStr(y, m) {
        return y + '-' + String(m).padStart(2, '0');
    }

    function labelStr(y, m) {
        return thaiMonths[m - 1] + ' ' + (y + 543);
    }

    function rowLabel(row, view) {
        if (view === 'shop') return row.shop_name || '-';
        if (view === 'activity') return row.activity_name || '-';
        return (row.shop_name || '-') + ' / ' + (row.activity_name || '-');
    }

    function replaceOptions(select, rows, valueKey, labelBuilder, firstLabel) {
        const selected = select.value;
        select.replaceChildren();
        const first = document.createElement('option');
        first.value = '0';
        first.textContent = firstLabel;
        select.appendChild(first);
        rows.forEach(row => {
            const option = document.createElement('option');
            option.value = String(row[valueKey]);
            option.textContent = labelBuilder(row);
            select.appendChild(option);
        });
        if ([...select.options].some(option => option.value === selected)) {
            select.value = selected;
        }
    }

    function renderRanking(rows, view) {
        const list = document.getElementById('revTopList');
        const empty = document.getElementById('revEmpty');
        list.replaceChildren();
        document.getElementById('revRankingTitle').textContent = {
            shop: 'ร้านค้าที่สร้างรายได้สูงสุด',
            activity: 'กิจกรรมที่สร้างรายได้สูงสุด',
            both: 'รายได้แยกตามร้านค้าและกิจกรรม'
        }[view];

        if (!rows.length) {
            empty.style.display = 'block';
            return;
        }
        empty.style.display = 'none';
        const maxVal = Math.max(...rows.map(row => Number(row.total)));
        rows.forEach(item => {
            const pct = maxVal > 0 ? Math.round(Number(item.total) / maxVal * 100) : 0;
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;font-size:12px;';

            const label = document.createElement('span');
            label.style.cssText =
                'flex:1;color:var(--text1);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:190px;';
            label.textContent = rowLabel(item, view);
            label.title = label.textContent;

            const track = document.createElement('div');
            track.style.cssText =
                'flex:2;height:6px;background:var(--border-light);border-radius:99px;overflow:hidden;';
            const fill = document.createElement('div');
            fill.style.cssText =
                `height:100%;width:${pct}%;background:var(--accent);border-radius:99px;transition:width .4s;`;
            track.appendChild(fill);

            const amount = document.createElement('span');
            amount.style.cssText =
                'color:var(--accent);font-weight:700;white-space:nowrap;min-width:72px;text-align:right;';
            amount.textContent = '฿' + Number(item.total).toLocaleString('th-TH', {
                maximumFractionDigits: 0
            });
            row.append(label, track, amount);
            list.appendChild(row);
        });
    }

    function renderDetails(rows, view, total) {
        const body = document.getElementById('revDetailBody');
        const primaryHead = document.getElementById('revDetailPrimaryHead');
        const secondaryHead = document.getElementById('revDetailSecondaryHead');
        body.replaceChildren();
        primaryHead.textContent = view === 'activity' ? 'กิจกรรม' : 'ร้านค้า';
        secondaryHead.style.display = view === 'both' ? '' : 'none';

        if (!rows.length) {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = view === 'both' ? 5 : 4;
            td.textContent = 'ยังไม่มีข้อมูลตามตัวกรองที่เลือก';
            td.style.cssText = 'padding:18px;text-align:center;color:var(--text3);';
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }

        rows.forEach(item => {
            const tr = document.createElement('tr');
            tr.style.borderTop = '1px solid var(--border-light)';
            const primary = document.createElement('td');
            primary.style.cssText = 'padding:10px 12px;color:var(--text1);font-weight:600;';
            primary.textContent = view === 'activity' ? item.activity_name : item.shop_name;
            tr.appendChild(primary);

            if (view === 'both') {
                const secondary = document.createElement('td');
                secondary.style.cssText = 'padding:10px 12px;color:var(--text2);';
                secondary.textContent = item.activity_name || '-';
                tr.appendChild(secondary);
            }

            const count = document.createElement('td');
            count.style.cssText = 'padding:10px 12px;text-align:right;color:var(--text2);';
            count.textContent = Number(item.cnt).toLocaleString('th-TH');
            const amount = document.createElement('td');
            amount.style.cssText = 'padding:10px 12px;text-align:right;color:var(--accent);font-weight:700;';
            amount.textContent = '฿' + Number(item.total).toLocaleString('th-TH', {
                maximumFractionDigits: 0
            });
            const share = document.createElement('td');
            share.style.cssText = 'padding:10px 12px;text-align:right;color:var(--text2);';
            share.textContent = (total > 0 ? Number(item.total) / total * 100 : 0).toLocaleString('th-TH', {
                maximumFractionDigits: 1
            }) + '%';
            tr.append(count, amount, share);
            body.appendChild(tr);
        });
    }

    async function loadStats(ym) {
        try {
            const params = new URLSearchParams({
                action: 'monthly_stats',
                month: ym,
                view: viewSelect.value,
                shop_id: shopSelect.value,
                activity_id: activitySelect.value
            });
            const r = await fetch('/tkn/admin/payments?' + params.toString());
            const d = await r.json();
            if (!d.ok) return;

            document.getElementById('monthNavLabel').textContent = labelStr(
                parseInt(ym.split('-')[0]), parseInt(ym.split('-')[1])
            );
            document.getElementById('revTotal').textContent =
                '฿' + Number(d.total).toLocaleString('th-TH', {
                    maximumFractionDigits: 0
                });
            document.getElementById('revCount').textContent =
                d.count + ' รายการที่อนุมัติ';

            replaceOptions(shopSelect, d.shops || [], 'shop_id', row => row.shop_name, 'ทุกร้านค้า');
            replaceOptions(
                activitySelect,
                d.activities || [],
                'activity_id',
                row => shopSelect.value === '0' ? `${row.shop_name} / ${row.activity_name}` : row.activity_name,
                'ทุกกิจกรรม'
            );
            renderRanking(d.top || [], d.view);
            renderDetails(d.details || [], d.view, Number(d.total));

            const now = new Date();
            const isCurrentMonth = (parseInt(ym.split('-')[0]) === now.getFullYear() &&
                parseInt(ym.split('-')[1]) === now.getMonth() + 1);
            document.getElementById('nextMonthBtn').disabled = isCurrentMonth;
            document.getElementById('nextMonthBtn').style.opacity = isCurrentMonth ? '.35' : '1';
        } catch (e) {
            console.error('monthly_stats error', e);
        }
    }

    document.getElementById('prevMonthBtn').addEventListener('click', function() {
        curMonth--;
        if (curMonth < 1) {
            curMonth = 12;
            curYear--;
        }
        loadStats(ymStr(curYear, curMonth));
    });

    document.getElementById('nextMonthBtn').addEventListener('click', function() {
        const now = new Date();
        if (curYear > now.getFullYear() || (curYear === now.getFullYear() && curMonth >= now.getMonth() +
                1)) return;
        curMonth++;
        if (curMonth > 12) {
            curMonth = 1;
            curYear++;
        }
        loadStats(ymStr(curYear, curMonth));
    });

    viewSelect.addEventListener('change', () => loadStats(ymStr(curYear, curMonth)));
    shopSelect.addEventListener('change', function() {
        activitySelect.value = '0';
        loadStats(ymStr(curYear, curMonth));
    });
    activitySelect.addEventListener('change', () => loadStats(ymStr(curYear, curMonth)));
    document.getElementById('resetRevenueFilters').addEventListener('click', function() {
        viewSelect.value = 'both';
        shopSelect.value = '0';
        activitySelect.value = '0';
        loadStats(ymStr(curYear, curMonth));
    });

    loadStats(ymStr(curYear, curMonth));
})();
</script>

<?php include 'footer.php'; ?>
