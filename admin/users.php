<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$admin_name   = $_SESSION['fullname'] ?? 'Admin';
$page_title   = 'User Management';
$current_page = 'users';

/* ── Search / Filter ───────────────────────────────────── */
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;
$offset = ($page - 1) * $limit;

$where  = '';
if ($search !== '') {
    $like   = '%' . $conn->real_escape_string($search) . '%';
    $where  = "WHERE fullname LIKE '$like' OR username LIKE '$like' OR email LIKE '$like'";
}

$total_filtered = (int)$conn->query("SELECT COUNT(*) FROM user $where")->fetch_row()[0];
$total_pages    = max(1, (int)ceil($total_filtered / $limit));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $limit;

$all_users = $conn->query("
    SELECT user_id, username, fullname, email, phonenumber, point
    FROM user $where
    ORDER BY user_id DESC
    LIMIT $limit OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

$total_users = $conn->query("SELECT COUNT(*) FROM user")->fetch_row()[0] ?? 0;

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title">User Management</h1>
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

    <!-- Content -->
    <div class="section-card">
        <div class="section-head">
            <div class="section-head-title">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                    <circle cx="12" cy="7" r="4" />
                </svg>
                ผู้ใช้งานทั้งหมด
                <span class="count-badge"><?= $total_users ?></span>
            </div>
            <!-- Search bar -->
            <form method="GET" style="display:flex;gap:8px;align-items:center">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                    placeholder="ค้นหา ชื่อ / username / อีเมล..." style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;
                           padding:6px 12px;color:var(--text);font-size:13px;width:240px;outline:none;">
                <button type="submit" class="btn btn-view" style="font-size:12px">🔍 ค้นหา</button>
                <?php if ($search): ?>
                <a href="/tkn/admin/users" class="btn btn-view" style="font-size:12px">✕ ล้าง</a>
                <?php endif; ?>
            </form>
        </div>

        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>Username</th>
                    <th>อีเมล</th>
                    <th>เบอร์โทร</th>
                    <th>Point</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($all_users)): ?>
                <tr>
                    <td colspan="6" class="tbl-empty">ไม่พบข้อมูลผู้ใช้</td>
                </tr>
                <?php else: foreach ($all_users as $u): ?>
                <tr>
                    <td style="font-family:var(--mono);color:var(--text3)"><?= $u['user_id'] ?></td>
                    <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($u['fullname']) ?></td>
                    <td style="font-family:var(--mono);font-size:12px;color:var(--text2)">
                        @<?= htmlspecialchars($u['username']) ?></td>
                    <td style="color:var(--text2)"><?= htmlspecialchars($u['email']) ?></td>
                    <td style="font-family:var(--mono);font-size:12px;color:var(--text2)"><?= $u['phonenumber'] ?></td>
                    <td><span class="badge badge-active"><?= $u['point'] ?> pt</span></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1 || $total_filtered > 0): ?>
        <div class="pager">
            <span class="pager-info">
                แสดง <?= $offset + 1 ?>–<?= min($offset + $limit, $total_filtered) ?>
                จาก <?= number_format($total_filtered) ?> รายการ
            </span>
            <?php
            $base = '?q=' . urlencode($search);
            echo '<a class="' . ($page <= 1 ? 'pager-disabled' : '') . '" href="' . $base . '&page=' . ($page - 1) . '">‹</a>';
            $start = max(1, $page - 2); $end = min($total_pages, $page + 2);
            if ($start > 1) echo '<span>…</span>';
            for ($i = $start; $i <= $end; $i++):
                $cls = ($i === $page) ? 'pager-active' : '';
                echo "<a class=\"$cls\" href=\"$base&page=$i\">$i</a>";
            endfor;
            if ($end < $total_pages) echo '<span>…</span>';
            echo '<a class="' . ($page >= $total_pages ? 'pager-disabled' : '') . '" href="' . $base . '&page=' . ($page + 1) . '">›</a>';
            ?>
        </div>
        <?php endif; ?>
    </div>

    </div><!-- /page-body -->
</div><!-- /main -->

<?php include 'footer.php'; ?>