<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$admin_name   = $_SESSION['fullname'] ?? 'Admin';
$page_title   = 'Reports & Feedback';
$current_page = 'reports';

/* ── Sort parameter ─────────────────────────────────────── */
$allowed_sorts = ['newest','oldest','highest','lowest'];
$sort = in_array($_GET['sort'] ?? '', $allowed_sorts) ? $_GET['sort'] : 'newest';
$sort_sql = match($sort) {
    'oldest'  => 'r.created_at ASC',
    'highest' => 'r.rating DESC, r.created_at DESC',
    'lowest'  => 'r.rating ASC, r.created_at DESC',
    default   => 'r.created_at DESC',
};

/* ── is_public safe-check ───────────────────────────────── */
$_has_pub    = $conn->query("SHOW COLUMNS FROM `review` LIKE 'is_public'");
$_has_pub_col = ($_has_pub && $_has_pub->num_rows > 0);
$pub_select  = $_has_pub_col ? ', r.is_public' : ', NULL AS is_public';

/* ── Reviews summary ────────────────────────────────────── */
$avg_rating    = $conn->query("SELECT ROUND(AVG(rating),1) FROM review")->fetch_row()[0] ?? 0;
$total_reviews = (int)($conn->query("SELECT COUNT(*) FROM review")->fetch_row()[0] ?? 0);

/* ── Reviews with pagination ────────────────────────────── */
$rv_limit = 15;
$rv_page  = max(1, (int)($_GET['rv_page'] ?? 1));
$rv_pages = max(1, (int)ceil($total_reviews / $rv_limit));
$rv_page  = min($rv_page, $rv_pages);
$rv_off   = ($rv_page - 1) * $rv_limit;

$reviews_q = $conn->query("
    SELECT r.rating, r.comment, r.created_at {$pub_select},
           u.fullname, a.activity_name, s.shop_name
    FROM review r
    JOIN user u ON r.user_id = u.user_id
    JOIN activity a ON r.activity_id = a.activity_id
    JOIN shop s ON a.shop_id = s.shop_id
    ORDER BY {$sort_sql}
    LIMIT {$rv_limit} OFFSET {$rv_off}
");
$reviews = $reviews_q ? $reviews_q->fetch_all(MYSQLI_ASSOC) : [];

/* helper – preserve sort param in pagination links */
function pgLink(int $p, string $sort): string {
    return '?sort=' . urlencode($sort) . '&rv_page=' . $p;
}

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title">Reports &amp; Feedback</h1>
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
    <div class="page-body reports-page">

    <!-- Reviews section -->
    <div class="section-card reports-card">

        <!-- Summary bar -->
        <div class="reports-summary" style="display:flex;align-items:center;gap:20px;padding:0 0 18px 0;border-bottom:1px solid var(--border);margin-bottom:18px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="20" height="20" fill="none" stroke="var(--accent)" stroke-width="2" viewBox="0 0 24 24">
                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                </svg>
                <span style="font-size:22px;font-weight:700;color:var(--accent)"><?= $avg_rating ?: '—' ?></span>
                <span style="font-size:13px;color:var(--text3)">คะแนนเฉลี่ย</span>
            </div>
            <div style="width:1px;height:28px;background:var(--border)"></div>
            <div style="font-size:14px;color:var(--text2)">
                <strong style="color:var(--text)"><?= number_format($total_reviews) ?></strong> รีวิวทั้งหมด
            </div>

            <!-- Sort buttons pushed to right -->
            <div class="reports-sort" style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
                <?php
                $sorts = [
                    'newest'  => 'ใหม่สุด',
                    'oldest'  => 'เก่าสุด',
                    'highest' => 'คะแนนเยอะสุด',
                    'lowest'  => 'คะแนนน้อยสุด',
                ];
                foreach ($sorts as $key => $label):
                    $active = ($sort === $key);
                ?>
                <a href="?sort=<?= $key ?>"
                   style="padding:6px 14px;border-radius:20px;font-size:13px;font-family:'Kanit',sans-serif;text-decoration:none;border:1.5px solid <?= $active ? 'var(--green)' : 'var(--border)' ?>;background:<?= $active ? 'var(--green)' : 'transparent' ?>;color:<?= $active ? '#fff' : 'var(--text2)' ?>;transition:all .2s;">
                    <?= $label ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Reviews table -->
        <table class="tbl reports-table">
            <thead>
                <tr>
                    <th>ผู้รีวิว</th>
                    <th>กิจกรรม / ร้าน</th>
                    <th>Rating</th>
                    <th>ความคิดเห็น</th>
                    <?php if ($_has_pub_col): ?><th>สถานะ</th><?php endif; ?>
                    <th>วันที่</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reviews)): ?>
                <tr>
                    <td colspan="<?= $_has_pub_col ? 6 : 5 ?>" class="tbl-empty">ยังไม่มีรีวิวในระบบ</td>
                </tr>
                <?php else: foreach ($reviews as $rv): ?>
                <tr>
                    <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($rv['fullname']) ?></td>
                    <td>
                        <div style="color:var(--text2)"><?= htmlspecialchars($rv['activity_name']) ?></div>
                        <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($rv['shop_name']) ?></div>
                    </td>
                    <td>
                        <?php
                        $stars = '';
                        for ($i = 1; $i <= 5; $i++) {
                            $stars .= $i <= $rv['rating'] ? '⭐' : '☆';
                        }
                        ?>
                        <span style="white-space:nowrap"><?= $stars ?> <strong><?= $rv['rating'] ?></strong></span>
                    </td>
                    <td style="color:var(--text2);max-width:260px;font-size:12px">
                        <?= htmlspecialchars(mb_substr($rv['comment'] ?? '', 0, 100)) ?><?= mb_strlen($rv['comment'] ?? '') > 100 ? '…' : '' ?>
                    </td>
                    <?php if ($_has_pub_col): ?>
                    <td>
                        <?php if ($rv['is_public']): ?>
                            <span class="badge badge-approved">สาธารณะ</span>
                        <?php else: ?>
                            <span class="badge badge-pending">ปิดอยู่</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="font-family:var(--mono);font-size:11px;color:var(--text3)">
                        <?= date('d/m/Y', strtotime($rv['created_at'])) ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($rv_pages > 1): ?>
        <div class="pager">
            <span class="pager-info">
                แสดง <?= $rv_off + 1 ?>–<?= min($rv_off + $rv_limit, $total_reviews) ?>
                จาก <?= number_format($total_reviews) ?> รีวิว
            </span>
            <?php
            echo '<a class="' . ($rv_page <= 1 ? 'pager-disabled' : '') . '" href="' . pgLink($rv_page - 1, $sort) . '">‹</a>';
            $st = max(1, $rv_page - 2); $en = min($rv_pages, $rv_page + 2);
            if ($st > 1) echo '<span>…</span>';
            for ($i = $st; $i <= $en; $i++) echo '<a class="' . ($i === $rv_page ? 'pager-active' : '') . '" href="' . pgLink($i, $sort) . '">' . $i . '</a>';
            if ($en < $rv_pages) echo '<span>…</span>';
            echo '<a class="' . ($rv_page >= $rv_pages ? 'pager-disabled' : '') . '" href="' . pgLink($rv_page + 1, $sort) . '">›</a>';
            ?>
        </div>
        <?php endif; ?>

    </div><!-- /section-card -->

    </div><!-- /page-body -->
</div><!-- /main -->

<?php include 'footer.php'; ?>
