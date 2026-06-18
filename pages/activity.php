<?php
require_once __DIR__ . '/../config/env.php';
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

if (session_status() === PHP_SESSION_NONE) session_start();
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
        'lang_switch_href'  => addLangParam('/tkn/activities', 'th'),
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
        'lang_switch_href'  => addLangParam('/tkn/activities', 'en'),
        'html_lang'         => 'th',
    ];
}


function parseRecurringFromNote($note) {
    if (!$note || !is_string($note)) return null;
    if (!preg_match('/จัดซ้ำ\s*[:：]\s*([^\]]+)/iu', $note, $m)) return null;

    $content = trim($m[1]);
    // รูปแบบตัวอย่าง: "เสาร์ 09:00-17:00" หรือ "จันทร์,พุธ 09:00-17:00"
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

$search_district  = ''; // ลบออก — ไม่ใช้กรองอำเภอแล้ว
$search_tags      = isset($_GET['tags']) ? (array)$_GET['tags'] : [];
$search_tags      = array_values(array_filter(array_map('intval', $search_tags)));
$search_duration  = $_GET['duration']  ?? '';
$search_suitable  = $_GET['suitable']  ?? '';
$search_sort      = $_GET['sort']      ?? '';   // 'price_asc' | ''
$search_open_only = !empty($_GET['open_only']);  // true = เฉพาะที่เปิดรับจองอยู่
$search_keyword      = trim($_GET['keyword']      ?? '');
$search_keyword_type = $_GET['keyword_type']      ?? 'activity'; // 'activity' | 'shop'
$search_mode         = $_GET['search_mode']        ?? ($search_keyword !== '' ? 'keyword' : 'filter'); // 'filter' | 'keyword'

// ── Tags dropdown ─────────────────────────────────────────────────────────────
$tags_result = $conn->query("SELECT tag_id, tag_name FROM tag ORDER BY tag_id");
$all_tags = $tags_result->fetch_all(MYSQLI_ASSOC);

// ── Main query ────────────────────────────────────────────────────────────────
$sql = "SELECT DISTINCT
    activity.*,
    shop.shop_name,
    shop.district,
    (SELECT AVG(rating) FROM review WHERE review.activity_id = activity.activity_id) AS avg_rating,
    (SELECT COUNT(*)    FROM review WHERE review.activity_id = activity.activity_id) AS review_count
FROM activity
JOIN shop ON activity.shop_id = shop.shop_id
LEFT JOIN activity_tag ON activity.activity_id = activity_tag.activity_id
LEFT JOIN tag          ON activity_tag.tag_id  = tag.tag_id
WHERE activity.status = 'Active'";

$conditions = [];
$params = [];
$types  = '';

if (!empty($search_tags)) {
    $placeholders = implode(',', array_fill(0, count($search_tags), '?'));
    $conditions[] = "activity_tag.tag_id IN ($placeholders)";
    foreach ($search_tags as $tid) { $params[] = $tid; $types .= 'i'; }
}
if (!empty($search_duration)) { $conditions[] = "activity.duration_label = ?";             $params[] = $search_duration;      $types .= 's'; }
if (!empty($search_suitable)) { $conditions[] = "FIND_IN_SET(?, activity.suitable_for)>0"; $params[] = $search_suitable;      $types .= 's'; }
if ($search_open_only) {
    // รวมทั้งที่เปิดจองอยู่ตอนนี้ และที่ยังไม่ถึงวันเปิดแต่มีกำหนดการแล้ว (upcoming)
    // ใช้ CURDATE() ไม่ใช่ NOW() เพื่อไม่ให้กรองออกเพราะเวลาในวันเดียวกัน
    $conditions[] = "EXISTS (
        SELECT 1 FROM activity_open_request aor
        WHERE aor.new_activity_id = activity.activity_id
          AND aor.status = 'Approved'
          AND DATE(aor.requested_end_date) >= CURDATE()
    )";
}
if (!empty($search_keyword)) {
    $kw = '%' . $search_keyword . '%';
    if ($search_keyword_type === 'shop') {
        $conditions[] = "(shop.shop_name LIKE ? OR REPLACE(LOWER(shop.shop_name), ' ', '') LIKE REPLACE(LOWER(?), ' ', ''))";
        $params[] = $kw;
        $params[] = $kw;
        $types .= 'ss';
    } else {
        $conditions[] = "(activity.activity_name LIKE ? OR REPLACE(LOWER(activity.activity_name), ' ', '') LIKE REPLACE(LOWER(?), ' ', ''))";
        $params[] = $kw;
        $params[] = $kw;
        $types .= 'ss';
    }
}

if (!empty($conditions)) $sql .= " AND " . implode(" AND ", $conditions);

// ── Pagination ─────────────────────────────────────────────────────────────
$per_page    = 10;
$current_page = max(1, (int)($_GET['page'] ?? 1));

// COUNT total rows
$count_sql  = "SELECT COUNT(DISTINCT activity.activity_id) AS total FROM activity JOIN shop ON activity.shop_id = shop.shop_id LEFT JOIN activity_tag ON activity.activity_id = activity_tag.activity_id LEFT JOIN tag ON activity_tag.tag_id = tag.tag_id WHERE activity.status = 'Active'";
if (!empty($conditions)) $count_sql .= " AND " . implode(" AND ", $conditions);
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_rows  = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page - 1) * $per_page;

$order_by = match($search_sort) {
    'price_asc'  => "activity.adult_price ASC",
    'price_desc' => "activity.adult_price DESC",
    default      => "activity.activity_id DESC",
};
$sql .= " ORDER BY $order_by LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$paginated_params = $params;
$paginated_types  = $types . 'ii';
$paginated_params[] = $per_page;
$paginated_params[] = $offset;
$stmt->bind_param($paginated_types, ...$paginated_params);
$stmt->execute();
$result = $stmt->get_result();

$has_search = !empty($search_tags) || !empty($search_duration) ||
              !empty($search_suitable) || $search_open_only || !empty($search_sort) ||
              !empty($search_keyword);

$active_filter_count = count($search_tags)
    + (int)(!empty($search_duration))
    + (int)(!empty($search_suitable))
    + (int)$search_open_only
    + (int)(!empty($search_sort) && $search_sort !== '');
$filter_open = $active_filter_count > 0;

// ── ดึงข้อมูล open_request ที่ Approved ทั้งหมด (เก็บวันที่เปิด-ปิดด้วย) ─────────
// $open_map[activity_id] = ['start' => datetime, 'end' => datetime, 'eff_end_dt' => effective end including time_end]
$open_map = [];
$oq = $conn->query(
    "SELECT new_activity_id,
            requested_start_date,
            requested_end_date,
            note
     FROM activity_open_request
     WHERE status = 'Approved' AND new_activity_id IS NOT NULL
     ORDER BY requested_start_date ASC"
);

// Helper: คำนวณ effective_end_dt โดยรวม time_end จาก note เข้าไปในวันสุดท้าย
function effectiveEndDt(DateTime $end_dt, ?array $recurring): DateTime {
    $eff = clone $end_dt;
    $time_end_str = '';
    if (!empty($recurring['time_end'])) {
        $time_end_str = $recurring['time_end'];
    } else {
        $t = $end_dt->format('H:i');
        if ($t !== '00:00') $time_end_str = $t;
    }
    if ($time_end_str) {
        list($h, $m) = explode(':', $time_end_str);
        $eff->setTime((int)$h, (int)$m, 0);
    } else {
        $eff->setTime(23, 59, 59);
    }
    return $eff;
}

if ($oq) {
    while ($or = $oq->fetch_assoc()) {
        $nid = (int)$or['new_activity_id'];
        $start = new DateTime($or['requested_start_date']);
        $end = new DateTime($or['requested_end_date']);
        $recurring_parsed = parseRecurringFromNote($or['note'] ?? '');
        $eff_end = effectiveEndDt($end, $recurring_parsed);

        // ถ้ามีหลาย request ต่อ activity: เลือกที่กำลังเปิดตอนนี้ > ใกล้สุดในอนาคต > ล่าสุดในอดีต
        if (!isset($open_map[$nid])) {
            $open_map[$nid] = [
                'start' => $or['requested_start_date'],
                'end'   => $or['requested_end_date'],
                'start_dt' => $start,
                'end_dt' => $end,
                'eff_end_dt' => $eff_end,
                'recurring' => $recurring_parsed,
            ];
            continue;
        }

        $current = $open_map[$nid];
        $currentStart = $current['start_dt'];
        $currentEnd = $current['eff_end_dt'];

        $now = new DateTime();
        $curr_active = $now >= $currentStart && $now <= $currentEnd;
        $new_active = $now >= $start && $now <= $eff_end;

        if ($new_active && !$curr_active) {
            // เปลี่ยนเป็นรอบปัจจุบัน
            $open_map[$nid] = [
                'start' => $or['requested_start_date'],
                'end'   => $or['requested_end_date'],
                'start_dt' => $start,
                'end_dt' => $end,
                'eff_end_dt' => $eff_end,
                'recurring' => $recurring_parsed,
            ];
            continue;
        }

        if ($curr_active && !$new_active) {
            continue;
        }

        // ถ้าทั้งคู่ไม่ใช่รอบปัจจุบัน ให้เลือกรอบที่ใกล้สุดอนาคต
        if ($start >= $now && $currentStart >= $now) {
            if ($start < $currentStart) {
                $open_map[$nid] = [
                    'start' => $or['requested_start_date'],
                    'end'   => $or['requested_end_date'],
                    'start_dt' => $start,
                    'end_dt' => $end,
                    'eff_end_dt' => $eff_end,
                    'recurring' => $recurring_parsed,
                ];
            }
            continue;
        }

        // ถ้าทั้งคู่ผ่านไปแล้ว เลือกล่าสุด (end ใหม่กว่า)
        if ($start < $now && $currentStart < $now) {
            if ($eff_end > $currentEnd) {
                $open_map[$nid] = [
                    'start' => $or['requested_start_date'],
                    'end'   => $or['requested_end_date'],
                    'start_dt' => $start,
                    'end_dt' => $end,
                    'eff_end_dt' => $eff_end,
                    'recurring' => $recurring_parsed,
                ];
            }
        }
    }
}
$now = new DateTime();

// ── Wishlist: ดึง activity_id ที่ user บันทึกไว้ ───────────────────────────────
$wishlisted_ids = [];
if (isset($_SESSION['user_id'])) {
    $wq = $conn->prepare("SELECT activity_id FROM wishlist WHERE user_id = ?");
    $wq->bind_param("i", $_SESSION['user_id']);
    $wq->execute();
    $wr = $wq->get_result();
    while ($row = $wr->fetch_assoc()) {
        $wishlisted_ids[] = (int)$row['activity_id'];
    }
    $wq->close();
}

$cssPath = __DIR__ . '/css/activity.css';
$cssVer  = file_exists($cssPath) ? filemtime($cssPath) : time();
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ค้นหากิจกรรม - Teawkanna</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/tkn/assets/css/style.css">
    <link rel="stylesheet" href="/tkn/assets/css/activity.css?v=<?= $cssVer ?>">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-nav.css">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-footer.css">
    <style>
    /* ── ชื่อร้าน ── */
    .activity-shop-name {
        font-size: 0.82rem;
        color: #6b7280;
        margin: 2px 0 4px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .activity-shop-name i {
        color: #9ca3af;
        font-size: 0.75rem;
    }

    /* ── กิจกรรมยังไม่เปิด ── */
    .activity-closed {
        opacity: 0.75;
        filter: grayscale(40%);
    }

    .activity-closed:hover {
        opacity: 0.88;
    }

    /* ── Banner บนรูป ── */
    .activity-thumbnail {
        position: relative;
        overflow: hidden;
    }

    .closed-banner {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(75, 85, 99, 0.88);
        color: #fff;
        text-align: center;
        font-size: 0.82rem;
        font-weight: 600;
        padding: 6px 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        letter-spacing: 0.03em;
    }

    .open-banner {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(21, 128, 61, 0.88);
        color: #fff;
        text-align: center;
        font-size: 0.82rem;
        font-weight: 600;
        padding: 6px 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        letter-spacing: 0.03em;
    }

    /* ── ข้อมูลช่วงวันที่จอง ── */
    .booking-date-info {
        font-size: 0.82rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    .booking-open {
        color: #16a34a;
    }

    .booking-upcoming {
        color: #2563eb;
    }

    /* ── ป้ายยังไม่เปิด ── */
    .not-open-label {
        font-size: 0.82rem;
        color: #9ca3af;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
    }

    /* ── footer flex ── */
    .activity-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }

    /* ── tag checkbox group ── */
    .tags-checkbox-group {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
    }

    .tag-checkbox-item {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 13px;
        border: 1.5px solid #d4dbc8;
        border-radius: 20px;
        cursor: pointer;
        font-size: 13px;
        color: #4a5a38;
        background: #f0f4eb;
        transition: all 0.15s;
        user-select: none;
        font-family: 'Kanit', sans-serif;
    }

    .tag-checkbox-item:hover {
        border-color: #2b4218;
        color: #2b4218;
        background: #e4edda;
    }

    .tag-checkbox-item.checked {
        background: #2b4218;
        border-color: #2b4218;
        color: #fff8cb;
        font-weight: 500;
    }

    .tag-checkbox-item input[type="checkbox"] {
        display: none;
    }
    </style>
    <link rel="stylesheet" href="/tkn/assets/css/activity-responsive.css">
</head>

<body>
    <div id="wrapper">

        <!-- Header -->
        <header class="header">
            <div class="container">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <nav class="nav">
                    <a href="/tkn/home" class="nav-link"><?= $t['nav_home'] ?></a>
                    <a href="/tkn/activities" class="nav-link active"><?= $t['nav_trips'] ?></a>
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

        <!-- Search Section -->
        <div class="search-section">
            <div class="container">
                <div class="search-container">
                    <h2 class="search-title">🌿 ค้นหากิจกรรมและเวิร์คช็อป</h2>
                    <p class="search-subtitle">พิมพ์ชื่อที่ต้องการ หรือเปิดตัวกรองเพื่อค้นหาตามเงื่อนไข</p>

                    <form method="GET" action="/tkn/activities" id="mainSearchForm">

                        <!-- ── Search + Filter bar (same row) ── -->
                        <div class="main-search-bar">
                            <button type="button" class="filter-toggle-btn <?= $filter_open ? 'open' : '' ?>"
                                id="filterToggleBtn" onclick="toggleFilterPanel()">
                                <i class="fas fa-sliders-h"></i>
                                ตัวกรอง
                                <?php if ($active_filter_count > 0): ?>
                                <span class="filter-badge"><?= $active_filter_count ?></span>
                                <?php endif; ?>
                                <i class="fas fa-chevron-down filter-chevron" id="filterChevron"></i>
                            </button>
                            <div class="bar-divider"></div>
                            <i class="fas fa-search bar-icon"></i>
                            <input type="text" name="keyword" class="main-search-input"
                                placeholder="ค้นหากิจกรรม หรือชื่อร้านค้า..." autocomplete="off"
                                value="<?= htmlspecialchars($search_keyword) ?>">
                            <button type="submit" class="bar-search-btn">
                                <i class="fas fa-search"></i> ค้นหา
                            </button>
                        </div>

                        <!-- ── Clear row (ถ้ามี filter อยู่) ── -->
                        <?php if ($has_search): ?>
                        <div style="margin-top:8px;">
                            <button type="button" class="clear-filters-btn"
                                onclick="window.location.href='/tkn/activities'">
                                <i class="fas fa-times"></i> ล้างทั้งหมด
                            </button>
                        </div>
                        <?php endif; ?>

                        <!-- ── Collapsible filter panel ── -->
                        <div class="filter-panel" id="filterPanel" <?= $filter_open ? '' : 'style="display:none;"' ?>>
                            <div class="filter-divider"></div>

                            <!-- Tags (หมวดหมู่) — อยู่บนสุด, กดแล้ว submit ทันที -->
                            <div class="filter-tags-row">
                                <label class="filter-label">
                                    <i class="fas fa-list"></i> หมวดหมู่
                                    <small>(เลือกได้หลายอัน)</small>
                                </label>
                                <div class="tags-checkbox-group">
                                    <?php foreach ($all_tags as $tag): ?>
                                    <label
                                        class="tag-checkbox-item <?= in_array((int)$tag['tag_id'], $search_tags) ? 'checked' : '' ?>">
                                        <input type="checkbox" name="tags[]" value="<?= $tag['tag_id'] ?>"
                                            <?= in_array((int)$tag['tag_id'], $search_tags) ? 'checked' : '' ?>
                                            onchange="this.closest('.tag-checkbox-item').classList.toggle('checked', this.checked); document.getElementById('mainSearchForm').submit();">
                                        <span><?= htmlspecialchars($tag['tag_name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="filter-grid" style="margin-top:14px;">

                                <div class="filter-group">
                                    <label class="filter-label">
                                        <i class="fas fa-clock"></i> ระยะเวลา
                                    </label>
                                    <select name="duration" class="filter-select" onchange="this.form.submit()">
                                        <option value="">ทั้งหมด</option>
                                        <option value="1 Hour" <?= $search_duration=='1 Hour'   ? 'selected':'' ?>>1
                                            ชั่วโมง</option>
                                        <option value="2 Hours" <?= $search_duration=='2 Hours'  ? 'selected':'' ?>>1-2
                                            ชั่วโมง</option>
                                        <option value="Half Day" <?= $search_duration=='Half Day' ? 'selected':'' ?>>
                                            ครึ่งวัน (3-4 ชม.)</option>
                                        <option value="Full Day" <?= $search_duration=='Full Day' ? 'selected':'' ?>>
                                            เต็มวัน</option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">
                                        <i class="fas fa-users"></i> เหมาะสำหรับ
                                    </label>
                                    <select name="suitable" class="filter-select" onchange="this.form.submit()">
                                        <option value="">ทั้งหมด</option>
                                        <option value="Kids" <?= $search_suitable=='Kids'    ?'selected':''?>>เด็ก (4-12
                                            ปี)</option>
                                        <option value="Adults" <?= $search_suitable=='Adults'  ?'selected':''?>>
                                            วัยรุ่น/ผู้ใหญ่</option>
                                        <option value="Seniors" <?= $search_suitable=='Seniors' ?'selected':''?>>
                                            ผู้สูงอายุ</option>
                                        <option value="Family" <?= $search_suitable=='Family'  ?'selected':''?>>ครอบครัว
                                        </option>
                                        <option value="Couples" <?= $search_suitable=='Couples' ?'selected':''?>>คู่รัก
                                        </option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label class="filter-label">
                                        <i class="fas fa-sort-amount-up"></i> เรียงตามราคา
                                    </label>
                                    <select name="sort" class="filter-select" onchange="this.form.submit()">
                                        <option value="">ล่าสุดก่อน</option>
                                        <option value="price_asc" <?= $search_sort==='price_asc' ? 'selected':'' ?>>
                                            ราคาต่ำ → สูง</option>
                                        <option value="price_desc" <?= $search_sort==='price_desc' ? 'selected':'' ?>>
                                            ราคาสูง → ต่ำ</option>
                                    </select>
                                </div>

                            </div>

                            <!-- Checkbox: เปิดรับจองเท่านั้น -->
                            <div style="margin-top:14px;">
                                <label class="tag-checkbox-item <?= $search_open_only ? 'checked' : '' ?>"
                                    style="display:inline-flex;">
                                    <input type="checkbox" name="open_only" value="1"
                                        <?= $search_open_only ? 'checked' : '' ?>
                                        onchange="this.closest('.tag-checkbox-item').classList.toggle('checked', this.checked); document.getElementById('mainSearchForm').submit();">
                                    <span><i class="fas fa-calendar-check" style="margin-right:4px;"></i>
                                        มีกำหนดการจองแล้ว (เปิดจองแล้ว / กำลังจะเปิด)</span>
                                </label>
                            </div>

                        </div><!-- /filter-panel -->

                    </form>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div class="results-section">
            <div class="container">
                <h2 class="results-header">
                    <?php if ($has_search): ?>
                    <?= $total_rows > 0 ? "ผลการค้นหา: พบ {$total_rows} กิจกรรม" : 'ไม่พบกิจกรรมที่ตรงกับเงื่อนไขการค้นหา' ?>
                    <?php else: ?>
                    กิจกรรมทั้งหมด (<?= $total_rows ?> กิจกรรม)
                    <?php endif; ?>
                </h2>

                <?php if ($total_rows > 0): ?>
                <div class="activities-list">
                    <?php while ($activity = $result->fetch_assoc()):
                    $aid       = (int)$activity['activity_id'];
                    $wl        = in_array($aid, $wishlisted_ids);
                    $rating    = (float)($activity['avg_rating'] ?? 0);
                    $rev_count = (int)($activity['review_count'] ?? 0);
                ?>
                    <?php
                    // คำนวณสถานะการเปิดรับจอง
                    $req         = $open_map[$aid] ?? null;
                    $has_req     = $req !== null;
                    $start_dt    = $has_req ? new DateTime($req['start']) : null;
                    $end_dt      = $has_req ? new DateTime($req['end'])   : null;
                    $eff_end_dt  = $has_req ? ($req['eff_end_dt'] ?? $end_dt) : null; // รวม time_end แล้ว
                    $is_within_range = $has_req && $now >= $start_dt && $now <= $eff_end_dt;
                    $recurring = $has_req ? ($req['recurring'] ?? null) : null;
                    $is_bookable = $is_within_range;

                    if ($is_bookable && !empty($recurring['days'])) {
                        $today_w = (int)$now->format('w');
                        $is_bookable = in_array($today_w, $recurring['days']);
                    }

                    // upcoming = มี request แต่ยังไม่ถึงวันเปิด
                    $is_upcoming = $has_req && $now < $start_dt;
                    // expired = เคยเปิดแต่หมดแล้ว (รวม time_end ของกิจกรรม)
                    $is_expired  = $has_req && $now > $eff_end_dt;
                    // กำหนด URL สำหรับหน้า booking (token-based, ซ่อน id)
                    $activity_url = p('booking', ['id' => $aid]);
                    ?>
                    <div class="activity-item <?= (!$has_req || $is_expired) ? 'activity-closed' : '' ?>"
                        <?= ($has_req && !$is_expired) ? "onclick=\"window.location.href='" . htmlspecialchars($activity_url) . "'\"" : '' ?>>

                        <!-- รูปภาพจาก activity_pic -->
                        <?php
                        $thumb_pic = '';
                        if (!empty($activity['activity_pic'])) {
                            $tp = $activity['activity_pic'];
                            if (preg_match('#^https?://#', $tp)) {
                                $thumb_pic = $tp;
                            } elseif (preg_match('#^uploads/activity_pics/#', $tp)) {
                                $thumb_pic = '/tkn/handlers/' . $tp;
                            } else {
                                $thumb_pic = $tp;
                            }
                        }
                        ?>
                        <div class="activity-thumbnail<?= $thumb_pic ? '' : ' activity-thumbnail-empty' ?>"
                            <?= $thumb_pic ? "style=\"background-image:url('" . htmlspecialchars($thumb_pic) . "');background-size:cover;background-position:center;\"" : '' ?>>
                            <?php if (!$thumb_pic): ?>
                            <svg viewBox="0 0 48 48" width="48" height="48" fill="none">
                                <rect width="48" height="48" rx="8" fill="rgba(255,255,255,0.25)" />
                                <path
                                    d="M8 36 L8 12 Q8 10 10 10 L38 10 Q40 10 40 12 L40 36 Q40 38 38 38 L10 38 Q8 38 8 36Z"
                                    fill="rgba(255,255,255,0.3)" />
                                <circle cx="18" cy="20" r="4" fill="rgba(255,255,255,0.6)" />
                                <path d="M8 32 L18 22 L26 30 L32 24 L40 32" stroke="rgba(255,255,255,0.7)"
                                    stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <?php endif; ?>
                            <?php if ($is_bookable): ?>
                            <div class="open-banner"><i class="fas fa-circle" style="font-size:0.55rem"></i>
                                เปิดรับจองแล้ว</div>
                            <?php elseif ($is_within_range && !empty($recurring['days'])): ?>
                            <div class="closed-banner"><i class="fas fa-calendar-alt"></i> จัดเฉพาะ <?= implode(',', array_map(function($d){
                                        $map=['0'=>'อาทิตย์','1'=>'จันทร์','2'=>'อังคาร','3'=>'พุธ','4'=>'พฤหัส','5'=>'ศุกร์','6'=>'เสาร์'];
                                        return $map[$d] ?? $d;
                                    }, $recurring['days'])) ?> (<?= (new DateTime($req['start']))->format('d/m/Y') ?> -
                                <?= (new DateTime($req['end']))->format('d/m/Y') ?>)</div>
                            <?php elseif ($is_upcoming): ?>
                            <div class="closed-banner"><i class="fas fa-calendar-alt"></i> เปิดจอง
                                <?= (new DateTime($req['start']))->format('d/m/Y') ?></div>
                            <?php elseif ($is_expired): ?>
                            <div class="closed-banner"><i class="fas fa-calendar-times"></i> ปิดรับจองแล้ว</div>
                            <?php else: ?>
                            <div class="closed-banner"><i class="fas fa-clock"></i> ยังไม่เปิดรับจอง</div>
                            <?php endif; ?>
                        </div>

                        <div class="activity-details">
                            <div>
                                <div class="activity-header">
                                    <div>
                                        <h3 class="activity-name"><?= htmlspecialchars($activity['activity_name']) ?>
                                        </h3>
                                        <div class="activity-shop-name">
                                            <i class="fas fa-store"></i>
                                            <?= htmlspecialchars($activity['shop_name']) ?>
                                        </div>
                                        <div class="activity-rating">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i
                                                class="<?= $i <= floor($rating) ? 'fas' : ($i - 0.5 <= $rating ? 'fas fa-star-half-alt' : 'far') ?> fa-star"></i>
                                            <?php endfor; ?>
                                            <span><?= number_format($rating, 1) ?> (<?= $rev_count ?>+)</span>
                                        </div>
                                    </div>

                                    <!-- Wishlist button — เชื่อมกับ DB จริง -->
                                    <button class="wl-card-btn <?= $wl ? 'wl-active' : '' ?>" data-id="<?= $aid ?>"
                                        onclick="event.stopPropagation(); toggleWishlist(this)">
                                        <i class="<?= $wl ? 'fas' : 'far' ?> fa-heart"></i>
                                    </button>
                                </div>

                                <p class="activity-description"><?= htmlspecialchars($activity['description']) ?></p>

                                <div class="activity-meta">
                                    <?php if ($activity['duration_label']): ?>
                                    <?php
                                        $dl = $activity['duration_label'];
                                        $dl_map = [
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
                                        if (isset($dl_map[$dl])) {
                                            $dl_display = $dl_map[$dl];
                                        } elseif (is_numeric($dl) && (int)$dl > 0) {
                                            // fallback: ค่าตัวเลขล้วน เช่น "1", "2", "3"
                                            $dl_display = (int)$dl . ' ชม.';
                                        } else {
                                            $dl_display = $dl;
                                        }
                                    ?>
                                    <span><i class="fas fa-clock"></i>
                                        <?= htmlspecialchars($dl_display) ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-map-marker-alt"></i>
                                        <?= htmlspecialchars($activity['district']) ?></span>
                                </div>
                            </div>

                            <div class="activity-footer">
                                <div class="activity-price">
                                    THB <?= number_format($activity['adult_price'], 0) ?>
                                    <span class="activity-price-label">/คน</span>
                                </div>
                                <?php if ($is_bookable): ?>
                                <div class="booking-date-info booking-open">
                                    <i class="fas fa-calendar-check"></i>
                                    ถึง <?= $end_dt->format('d/m/Y') ?>
                                </div>
                                <?php elseif ($is_upcoming): ?>
                                <div class="booking-date-info booking-upcoming">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?= $start_dt->format('d/m/Y') ?> – <?= $end_dt->format('d/m/Y') ?>
                                </div>
                                <?php elseif ($is_expired): ?>
                                <span class="not-open-label"><i class="fas fa-calendar-times"></i> หมดช่วงจอง</span>
                                <?php else: ?>
                                <span class="not-open-label"><i class="fas fa-ban"></i> ยังไม่เปิดรับจอง</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <?php
                    $query_params = $_GET;
                    unset($query_params['page']);
                    $base_query = http_build_query($query_params);
                    $base_url   = BASE_URL . '/activities?' . ($base_query ? $base_query . '&' : '');
                ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                    <a href="<?= $base_url ?>page=<?= $current_page - 1 ?>" class="page-btn">&lsaquo;</a>
                    <?php else: ?>
                    <span class="page-btn disabled">&lsaquo;</span>
                    <?php endif; ?>

                    <?php
                    $start = max(1, $current_page - 2);
                    $end   = min($total_pages, $current_page + 2);
                    if ($start > 1): ?><a href="<?= $base_url ?>page=1" class="page-btn">1</a><?php
                        if ($start > 2): ?><span class="page-dots">…</span><?php endif;
                    endif;
                    for ($p = $start; $p <= $end; $p++): ?>
                    <a href="<?= $base_url ?>page=<?= $p ?>"
                        class="page-btn <?= $p === $current_page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor;
                    if ($end < $total_pages):
                        if ($end < $total_pages - 1): ?><span class="page-dots">…</span><?php endif; ?>
                    <a href="<?= $base_url ?>page=<?= $total_pages ?>" class="page-btn"><?= $total_pages ?></a>
                    <?php endif; ?>

                    <?php if ($current_page < $total_pages): ?>
                    <a href="<?= $base_url ?>page=<?= $current_page + 1 ?>" class="page-btn">&rsaquo;</a>
                    <?php else: ?>
                    <span class="page-btn disabled">&rsaquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-sad-tear"></i>
                    <h3>ไม่พบกิจกรรมที่ตรงกับเงื่อนไข</h3>
                    <p>ลองปรับเงื่อนไขการค้นหาใหม่อีกครั้ง</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <footer class="footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-left">
                        <div class="footer-logo-wrap">
                            <img src="/tkn/assets/image/logo.png" alt="เที่ยวกันนา" class="footer-logo-img">
                        </div>
                        <p class="footer-tagline">แพลตฟอร์มท่องเที่ยวเชิงเกษตรและภูมิปัญญาชาวบ้าน<br>จังหวัดชลบุรี</p>
                        <p style="font-size:0.78rem;color:#8ab49a;line-height:1.6;margin:8px 0;">
                            <i class="fas fa-map-marker-alt" style="margin-right:5px;"></i>
                            ทะเบียนพาณิชย์เลขที่ 20-X-XXXX-XXXXX<br>
                            <span style="margin-left:16px;">มหาวิทยาลัยศิลปากร วิทยาเขตสารสนเทศเพชรบุรี</span>
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
                            <li><a href="/tkn/home"><i class="fas fa-chevron-right footer-li-arrow"></i>หน้าแรก</a></li>
                            <li><a href="/tkn/activities"><i
                                        class="fas fa-chevron-right footer-li-arrow"></i>กิจกรรม</a></li>
                            <li><a href="/tkn/contact"><i class="fas fa-chevron-right footer-li-arrow"></i>ติดต่อเรา</a>
                            </li>
                        </ul>
                    </div>
                    <div class="footer-social">
                        <h4 class="footer-heading">ช่องทางการติดต่อ</h4>
                        <ul>
                            <li><a href="https://facebook.com/teawkanna" target="_blank" rel="noopener"
                                    style="color:inherit;text-decoration:none;"><i
                                        class="fab fa-facebook footer-contact-icon"></i> facebook.com/teawkanna</a></li>
                            <li><a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener"
                                    style="color:inherit;text-decoration:none;"><i
                                        class="fab fa-line footer-contact-icon"></i> @979jehsw</a></li>
                            <li><a href="https://tiktok.com/@teawkanna" target="_blank" rel="noopener"
                                    style="color:inherit;text-decoration:none;"><i
                                        class="fab fa-tiktok footer-contact-icon"></i> @teawkanna</a></li>
                            <li><a href="mailto:teawkanna@gmail.com" style="color:inherit;text-decoration:none;"><i
                                        class="fas fa-envelope footer-contact-icon"></i> teawkanna@gmail.com</a></li>
                            <li><a href="tel:+66899999999" style="color:inherit;text-decoration:none;"><i
                                        class="fas fa-phone footer-contact-icon"></i> 089-999-9999</a></li>
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

    <script>
    // Inject wishlisted IDs from PHP
    var wishlistedIds = <?= json_encode($wishlisted_ids) ?>;
    var isLoggedIn = <?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>;

    // Filter panel toggle
    function toggleFilterPanel() {
        var panel = document.getElementById('filterPanel');
        var btn = document.getElementById('filterToggleBtn');
        var isOpen = panel.style.display !== 'none';

        if (isOpen) {
            panel.style.opacity = '0';
            setTimeout(function() {
                panel.style.display = 'none';
            }, 180);
            btn.classList.remove('open');
        } else {
            panel.style.display = 'block';
            panel.style.opacity = '0';
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    panel.style.opacity = '1';
                });
            });
            btn.classList.add('open');
        }
    }

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

    // Mobile menu
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

    // Wishlist toggle — เชื่อม DB จริง
    function toggleWishlist(btn) {
        if (!isLoggedIn) {
            if (confirm('คุณยังไม่ได้เข้าสู่ระบบ ต้องการไปหน้า Login หรือไม่?')) {
                window.location.href = '/tkn/login';
            }
            return;
        }

        var activityId = parseInt(btn.dataset.id);
        btn.disabled = true;

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
                btn.disabled = false;
                if (data.status === 'added') {
                    btn.querySelector('i').className = 'fas fa-heart';
                    btn.classList.add('wl-active');
                    wishlistedIds.push(activityId);
                } else if (data.status === 'removed') {
                    btn.querySelector('i').className = 'far fa-heart';
                    btn.classList.remove('wl-active');
                    wishlistedIds = wishlistedIds.filter(function(id) {
                        return id !== activityId;
                    });
                }
            })
            .catch(function() {
                btn.disabled = false;
            });
    }
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
