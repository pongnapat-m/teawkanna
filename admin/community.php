<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$admin_name   = $_SESSION['fullname'] ?? 'Admin';
$page_title   = 'Communities Management';
$current_page = 'community';

/* ── ตรวจสอบ columns ที่มีอยู่จริงในตาราง shop ─────────── */
$shop_cols_q   = $conn->query("SHOW COLUMNS FROM shop");
$shop_cols     = [];
if ($shop_cols_q) {
    while ($col = $shop_cols_q->fetch_assoc()) $shop_cols[] = $col['Field'];
}
$has_shop_desc = in_array('shop_description', $shop_cols);

/* ── ตรวจสอบ columns ในตาราง owner ────────────────────── */
$owner_cols_q  = $conn->query("SHOW COLUMNS FROM owner");
$owner_cols    = [];
if ($owner_cols_q) {
    while ($col = $owner_cols_q->fetch_assoc()) $owner_cols[] = $col['Field'];
}
$has_bank_name    = in_array('Bank_name',    $owner_cols);
$has_bank_account = in_array('Bank_account', $owner_cols);
$has_username_ow  = in_array('username',     $owner_cols);

/* ── สร้าง SELECT แบบ dynamic ──────────────────────────── */
$shop_desc_sel   = $has_shop_desc    ? 's.shop_description,'       : "'' AS shop_description,";
$bank_name_sel   = $has_bank_name    ? 'o.Bank_name,'              : "'' AS Bank_name,";
$bank_acc_sel    = $has_bank_account ? 'o.Bank_account,'           : "'' AS Bank_account,";
$username_ow_sel = $has_username_ow  ? 'o.username AS owner_uname' : "'' AS owner_uname";

/* ── ร้านค้า/ชุมชนทั้งหมด ──────────────────────────────── */
$all_shops_q = $conn->query("
    SELECT s.shop_id, s.shop_name, {$shop_desc_sel}
           o.owner_id, o.owner_fullname, o.owner_email,
           o.owner_phonenumber, {$bank_name_sel} {$bank_acc_sel} o.status,
           {$username_ow_sel},
           (SELECT COUNT(*) FROM activity a WHERE a.shop_id = s.shop_id AND a.status='Active') AS active_acts,
           (SELECT COUNT(*) FROM activity a WHERE a.shop_id = s.shop_id) AS total_acts
    FROM shop s
    JOIN owner o ON s.owner_id = o.owner_id
    ORDER BY s.shop_id DESC
");

if (!$all_shops_q) {
    // fallback: query แบบ minimal ถ้า JOIN ยังผิด
    $all_shops_q = $conn->query("SELECT shop_id, shop_name, '' AS shop_description FROM shop ORDER BY shop_id DESC");
    $all_shops   = $all_shops_q ? $all_shops_q->fetch_all(MYSQLI_ASSOC) : [];
} else {
    $all_shops = $all_shops_q->fetch_all(MYSQLI_ASSOC);
}

/* ── Owners รอการอนุมัติ (JOIN shop เพื่อดูชื่อร้านที่กรอกมา) ── */
$username_sel    = $has_username_ow  ? 'o.username,' : "'' AS username,";
$bank_name_sel2  = $has_bank_name    ? 'o.Bank_name,' : "'' AS Bank_name,";
$bank_acc_sel2   = $has_bank_account ? 'o.Bank_account,' : "'' AS Bank_account,";

$pend_owners_q = $conn->query("
    SELECT o.owner_id, {$username_sel} o.owner_fullname, o.owner_email,
           o.owner_phonenumber, {$bank_name_sel2} {$bank_acc_sel2} o.status,
           s.shop_id, s.shop_name, s.location, s.district, s.province, s.shop_picture
    FROM owner o
    LEFT JOIN shop s ON s.owner_id = o.owner_id
    WHERE o.status = 'Pending'
    ORDER BY o.owner_id DESC
");
$pend_owners = $pend_owners_q ? $pend_owners_q->fetch_all(MYSQLI_ASSOC) : [];

$total_shops  = count($all_shops);
$total_pend   = count($pend_owners);

/* ── Pagination ─────────────────────────────────────────── */
$limit       = 10;
$shop_page   = max(1, (int)($_GET['shop_page'] ?? 1));
$shop_pages  = max(1, (int)ceil($total_shops / $limit));
$shop_page   = min($shop_page, $shop_pages);
$shop_offset = ($shop_page - 1) * $limit;
$shops_page  = array_slice($all_shops, $shop_offset, $limit);

$pend_page   = max(1, (int)($_GET['pend_page'] ?? 1));
$pend_pages  = max(1, (int)ceil($total_pend / $limit));
$pend_page   = min($pend_page, $pend_pages);
$pend_offset = ($pend_page - 1) * $limit;
$pend_page_rows = array_slice($pend_owners, $pend_offset, $limit);

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title">Communities Management</h1>
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

    <!-- ══ ส่วนที่ 1: ร้านค้า/ชุมชนทั้งหมด ══ -->
    <div class="section-card">
        <div class="section-head">
            <div class="section-head-title">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                    <polyline points="9 22 9 12 15 12 15 22" />
                </svg>
                ร้านค้า / ชุมชนทั้งหมด
                <span class="count-badge"><?= $total_shops ?></span>
            </div>
        </div>
        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>ชื่อร้าน / ชุมชน</th>
                    <th>เจ้าของ</th>
                    <th>ติดต่อ</th>
                    <th>บัญชีธนาคาร</th>
                    <th>กิจกรรม</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($all_shops)): ?>
                <tr>
                    <td colspan="7" class="tbl-empty">ยังไม่มีร้านค้าในระบบ</td>
                </tr>
                <?php else: foreach ($shops_page as $shop): ?>
                <tr>
                    <td style="font-family:var(--mono);color:var(--text3)"><?= $shop['shop_id'] ?></td>
                    <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($shop['shop_name']) ?></div>
                        <?php if (!empty($shop['shop_description'])): ?>
                        <div
                            style="font-size:11px;color:var(--text3);max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= htmlspecialchars(mb_substr($shop['shop_description'], 0, 60)) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;color:var(--text)">
                            <?= htmlspecialchars($shop['owner_fullname'] ?? '') ?></div>
                        <?php if (!empty($shop['owner_uname'])): ?>
                        <div style="font-size:11px;color:var(--text3)">@<?= htmlspecialchars($shop['owner_uname']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:12px;color:var(--text2)">
                            <?= htmlspecialchars($shop['owner_email'] ?? '') ?></div>
                        <div style="font-size:11px;color:var(--text3)"><?= $shop['owner_phonenumber'] ?? '' ?></div>
                    </td>
                    <td>
                        <div style="font-size:12px;color:var(--text2)">
                            <?= htmlspecialchars($shop['Bank_name'] ?? '—') ?></div>
                        <div style="font-size:11px;font-family:var(--mono);color:var(--text3)">
                            <?= htmlspecialchars($shop['Bank_account'] ?? '') ?></div>
                    </td>
                    <td>
                        <span class="badge badge-approved"><?= $shop['active_acts'] ?> Active</span>
                        <?php if ($shop['total_acts'] > $shop['active_acts']): ?>
                        <span class="badge badge-inactive" style="margin-top:4px"><?= $shop['total_acts'] ?> รวม</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $s = $shop['status'];
                        $cls = $s === 'Approved' ? 'badge-approved' : ($s === 'Pending' ? 'badge-pending' : 'badge-rejected');
                        $lbl = $s === 'Approved' ? '✓ อนุมัติแล้ว' : ($s === 'Pending' ? '⏳ รออนุมัติ' : '✕ ปฏิเสธ');
                        ?>
                        <span class="badge <?= $cls ?>"><?= $lbl ?></span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($shop_pages > 1): ?>
        <div class="pager">
            <span class="pager-info">
                แสดง <?= $shop_offset + 1 ?>–<?= min($shop_offset + $limit, $total_shops) ?>
                จาก <?= number_format($total_shops) ?> ร้านค้า
            </span>
            <?php
            $base = '?pend_page=' . $pend_page;
            echo '<a class="' . ($shop_page <= 1 ? 'pager-disabled' : '') . '" href="' . $base . '&shop_page=' . ($shop_page - 1) . '">‹</a>';
            $st = max(1, $shop_page - 2); $en = min($shop_pages, $shop_page + 2);
            if ($st > 1) echo '<span>…</span>';
            for ($i = $st; $i <= $en; $i++) echo '<a class="' . ($i === $shop_page ? 'pager-active' : '') . '" href="' . $base . '&shop_page=' . $i . '">' . $i . '</a>';
            if ($en < $shop_pages) echo '<span>…</span>';
            echo '<a class="' . ($shop_page >= $shop_pages ? 'pager-disabled' : '') . '" href="' . $base . '&shop_page=' . ($shop_page + 1) . '">›</a>';
            ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ ส่วนที่ 2: รออนุมัติผู้ประกอบการใหม่ ══ -->
    <div class="section-card">
        <div class="section-head">
            <div class="section-head-title">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                ผู้ประกอบการใหม่ รออนุมัติ
                <span class="count-badge"
                    style="background:rgba(245,158,11,.15);color:var(--amber)"><?= $total_pend ?></span>
            </div>
        </div>
        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>ผู้ประกอบการ</th>
                    <th>ข้อมูลร้าน</th>
                    <th>อีเมล / โทร</th>
                    <th>บัญชีธนาคาร</th>
                    <th>สถานะ</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pend_owners)): ?>
                <tr>
                    <td colspan="7" class="tbl-empty">✅ ไม่มีผู้ประกอบการรออนุมัติ</td>
                </tr>
                <?php else: foreach ($pend_page_rows as $o): ?>
                <?php
                    // รูปร้าน
                    $sp = resolvePic($o['shop_picture'] ?? '');
                    // ที่อยู่ร้าน
                    $addr_parts = array_filter([$o['district']??'', $o['province']??'']);
                    $addr_short = implode(', ', $addr_parts);
                ?>
                <tr id="owner-row-<?= $o['owner_id'] ?>">
                    <td style="font-family:var(--mono);color:var(--text3)"><?= $o['owner_id'] ?></td>
                    <td>
                        <div style="font-weight:600;color:var(--text)"><?= htmlspecialchars($o['owner_fullname']) ?></div>
                        <div style="font-size:11px;font-family:var(--mono);color:var(--text3)">
                            @<?= htmlspecialchars($o['username']) ?></div>
                    </td>
                    <td>
                        <?php if (!empty($o['shop_name'])): ?>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if ($sp): ?>
                            <img src="<?= htmlspecialchars($sp) ?>" alt=""
                                 style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1.5px solid var(--border);flex-shrink:0;"
                                 onerror="this.style.display='none'">
                            <?php else: ?>
                            <div style="width:44px;height:44px;border-radius:8px;background:var(--surface2);
                                        display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">🏡</div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight:600;color:var(--text);font-size:13px">
                                    <?= htmlspecialchars($o['shop_name']) ?></div>
                                <?php if ($addr_short): ?>
                                <div style="font-size:11px;color:var(--text3)">📍 <?= htmlspecialchars($addr_short) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($o['location'])): ?>
                                <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($o['location']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--text3);font-size:12px">— ยังไม่ได้กรอกข้อมูลร้าน</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:12px;color:var(--text2)"><?= htmlspecialchars($o['owner_email']) ?></div>
                        <div style="font-size:11px;color:var(--text3)"><?= $o['owner_phonenumber'] ?></div>
                    </td>
                    <td>
                        <div style="font-size:12px;color:var(--text2)"><?= htmlspecialchars($o['Bank_name'] ?? '—') ?>
                        </div>
                        <div style="font-size:11px;font-family:var(--mono);color:var(--text3)">
                            <?= htmlspecialchars($o['Bank_account'] ?? '') ?></div>
                    </td>
                    <td>
                        <span class="badge badge-inactive" id="owner-badge-<?= $o['owner_id'] ?>">⏳ รออนุมัติ</span>
                    </td>
                    <td>
                        <div class="act-btns">
                            <button class="btn btn-approve"
                                onclick="confirm_action('approve_owner',<?= $o['owner_id'] ?>,'owner','อนุมัติ','อนุมัติผู้ประกอบการ: <?= addslashes($o['owner_fullname']) ?>?')">
                                ✓ อนุมัติ
                            </button>
                            <button class="btn btn-reject"
                                onclick="confirm_action('reject_owner',<?= $o['owner_id'] ?>,'owner','ปฏิเสธ','ปฏิเสธ: <?= addslashes($o['owner_fullname']) ?>?')">
                                ✕ ปฏิเสธ
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($pend_pages > 1): ?>
        <div class="pager">
            <span class="pager-info">
                แสดง <?= $pend_offset + 1 ?>–<?= min($pend_offset + $limit, $total_pend) ?>
                จาก <?= number_format($total_pend) ?> รายการ
            </span>
            <?php
            $base2 = '?shop_page=' . $shop_page;
            echo '<a class="' . ($pend_page <= 1 ? 'pager-disabled' : '') . '" href="' . $base2 . '&pend_page=' . ($pend_page - 1) . '">‹</a>';
            $st2 = max(1, $pend_page - 2); $en2 = min($pend_pages, $pend_page + 2);
            if ($st2 > 1) echo '<span>…</span>';
            for ($i = $st2; $i <= $en2; $i++) echo '<a class="' . ($i === $pend_page ? 'pager-active' : '') . '" href="' . $base2 . '&pend_page=' . $i . '">' . $i . '</a>';
            if ($en2 < $pend_pages) echo '<span>…</span>';
            echo '<a class="' . ($pend_page >= $pend_pages ? 'pager-disabled' : '') . '" href="' . $base2 . '&pend_page=' . ($pend_page + 1) . '">›</a>';
            ?>
        </div>
        <?php endif; ?>    </div>

    </div><!-- /page-body -->
</div><!-- /main -->

<?php include 'footer.php'; ?>
