<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /tkn/login"); exit();
}
include '../db.php';

$admin_name   = $_SESSION['fullname'] ?? 'Admin';
$page_title   = 'Contact Messages';
$current_page = 'contact';

/* ── Mark as read (AJAX) ───────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $id = (int)$_POST['mark_read'];
    $conn->query("UPDATE contact_message SET is_read = 1 WHERE id = $id");
    echo json_encode(['ok' => true]);
    exit();
}

/* ── Delete ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $conn->query("DELETE FROM contact_message WHERE id = $id");
    header("Location: /tkn/admin/contact?deleted=1");
    exit();
}

/* ── Filter ────────────────────────────────────────────── */
$filter = $_GET['filter'] ?? 'all'; // all | unread | read
$search = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 10;

$where_parts = [];
if ($filter === 'unread') $where_parts[] = "is_read = 0";
if ($filter === 'read')   $where_parts[] = "is_read = 1";
if ($search !== '') {
    $like = '%' . $conn->real_escape_string($search) . '%';
    $where_parts[] = "(firstname LIKE '$like' OR lastname LIKE '$like' OR email LIKE '$like' OR subject LIKE '$like')";
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$total_filtered = (int)$conn->query("SELECT COUNT(*) FROM contact_message $where_sql")->fetch_row()[0];
$total_pages    = max(1, (int)ceil($total_filtered / $limit));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $limit;

$messages = $conn->query("
    SELECT * FROM contact_message
    $where_sql
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

$total_all    = (int)($conn->query("SELECT COUNT(*) FROM contact_message")->fetch_row()[0] ?? 0);
$total_unread = (int)($conn->query("SELECT COUNT(*) FROM contact_message WHERE is_read = 0")->fetch_row()[0] ?? 0);

include 'head.php';
include 'nav.php';
?>

<div class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1 class="page-title">Contact Messages</h1>
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

    <?php if (isset($_GET['deleted'])): ?>
    <div style="padding:12px 16px;background:#d1fae5;color:#065f46;border-radius:8px;font-size:13px;">
        ✅ ลบข้อความเรียบร้อยแล้ว
    </div>
    <?php endif; ?>

    <!-- Stats Bar -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <div class="section-card" style="flex:1;min-width:160px;padding:16px 20px;">
            <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;">ข้อความทั้งหมด</div>
            <div style="font-size:28px;font-weight:700;margin-top:4px;"><?= $total_all ?></div>
        </div>
        <div class="section-card" style="flex:1;min-width:160px;padding:16px 20px;border-left:3px solid #f59e0b;">
            <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;">ยังไม่ได้อ่าน</div>
            <div style="font-size:28px;font-weight:700;margin-top:4px;color:#f59e0b;"><?= $total_unread ?></div>
        </div>
        <div class="section-card" style="flex:1;min-width:160px;padding:16px 20px;border-left:3px solid #22c55e;">
            <div style="font-size:11px;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;">อ่านแล้ว</div>
            <div style="font-size:28px;font-weight:700;margin-top:4px;color:#22c55e;"><?= $total_all - $total_unread ?></div>
        </div>
    </div>

    <!-- Main Card -->
    <div class="section-card">
        <div class="section-head">
            <div class="section-head-title">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                </svg>
                ข้อความจากลูกค้า
                <?php if ($total_unread > 0): ?>
                <span class="count-badge" style="background:#f59e0b;"><?= $total_unread ?> ใหม่</span>
                <?php endif; ?>
            </div>

            <!-- Filter + Search -->
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <!-- Filter tabs -->
                <div style="display:flex;gap:4px;background:var(--surface2);border-radius:8px;padding:3px;">
                    <?php foreach (['all'=>'ทั้งหมด','unread'=>'ยังไม่อ่าน','read'=>'อ่านแล้ว'] as $k=>$v): ?>
                    <a href="?filter=<?= $k ?>&q=<?= urlencode($search) ?>"
                       style="padding:4px 12px;border-radius:6px;font-size:12px;text-decoration:none;
                              color:<?= $filter===$k ? '#fff' : 'var(--text2)' ?>;
                              background:<?= $filter===$k ? 'var(--accent)' : 'transparent' ?>;">
                        <?= $v ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <!-- Search -->
                <form method="GET" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                        placeholder="ค้นหา ชื่อ / อีเมล / หัวข้อ..."
                        style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;
                               padding:6px 12px;color:var(--text);font-size:13px;width:220px;outline:none;">
                    <button type="submit" class="btn btn-view" style="font-size:12px;">🔍</button>
                    <?php if ($search): ?>
                    <a href="?filter=<?= $filter ?>" class="btn btn-view" style="font-size:12px;">✕</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (empty($messages)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text3);">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin-bottom:12px;opacity:.4;">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            <p style="font-size:14px;">ไม่มีข้อความ</p>
        </div>
        <?php else: ?>

        <!-- Message List -->
        <div style="display:flex;flex-direction:column;gap:0;">
            <?php foreach ($messages as $msg): ?>
            <?php $unread = !$msg['is_read']; ?>
            <div class="msg-row <?= $unread ? 'msg-unread' : '' ?>"
                 data-id="<?= $msg['id'] ?>"
                 style="display:flex;align-items:flex-start;gap:16px;padding:16px;
                        border-bottom:1px solid var(--border);cursor:pointer;
                        background:<?= $unread ? 'rgba(245,158,11,.07)' : 'transparent' ?>;
                        transition:background .15s;"
                 onclick="toggleMsg(this)">

                <!-- Unread dot -->
                <div style="padding-top:4px;flex-shrink:0;">
                    <div class="unread-dot" style="width:8px;height:8px;border-radius:50%;
                         background:<?= $unread ? '#f59e0b' : 'transparent' ?>;
                         margin-top:2px;"></div>
                </div>

                <!-- Avatar -->
                <div style="flex-shrink:0;width:40px;height:40px;border-radius:50%;
                            background:var(--accent);display:flex;align-items:center;
                            justify-content:center;font-weight:600;font-size:16px;color:#fff;">
                    <?= mb_strtoupper(mb_substr($msg['firstname'], 0, 1)) ?>
                </div>

                <!-- Content -->
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-weight:<?= $unread ? '600' : '400' ?>;font-size:14px;">
                            <?= htmlspecialchars($msg['firstname'] . ' ' . $msg['lastname']) ?>
                        </span>
                        <span style="font-size:12px;color:var(--text3);">
                            <?= htmlspecialchars($msg['email']) ?>
                        </span>
                        <?php if ($unread): ?>
                        <span style="font-size:10px;background:#f59e0b;color:#fff;padding:1px 7px;border-radius:10px;font-weight:600;">NEW</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:13px;font-weight:<?= $unread ? '600' : '400' ?>;margin:2px 0;">
                        📌 <?= htmlspecialchars($msg['subject']) ?>
                    </div>
                    <div class="msg-preview" style="font-size:12px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:600px;">
                        <?= htmlspecialchars($msg['message']) ?>
                    </div>
                    <!-- Expanded message -->
                    <div class="msg-body" style="display:none;margin-top:12px;padding:12px;
                         background:var(--surface2);border-radius:8px;font-size:13px;
                         color:var(--text2);line-height:1.7;white-space:pre-wrap;">
                        <?= htmlspecialchars($msg['message']) ?>
                    </div>
                </div>

                <!-- Right: date + actions -->
                <div style="flex-shrink:0;text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
                    <div style="font-size:11px;color:var(--text3);">
                        <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                    </div>
                    <div style="display:flex;gap:6px;" onclick="event.stopPropagation()">
                        <?php if ($unread): ?>
                        <button class="btn btn-view" style="font-size:11px;padding:4px 10px;"
                            onclick="markRead(<?= $msg['id'] ?>, this)">✓ อ่านแล้ว</button>
                        <?php endif; ?>
                        <a href="mailto:<?= htmlspecialchars($msg['email']) ?>?subject=Re: <?= urlencode($msg['subject']) ?>"
                           class="btn btn-view" style="font-size:11px;padding:4px 10px;">✉ ตอบกลับ</a>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('ลบข้อความนี้?')">
                            <input type="hidden" name="delete_id" value="<?= $msg['id'] ?>">
                            <button type="submit" class="btn btn-reject" style="font-size:11px;padding:4px 10px;">🗑 ลบ</button>
                        </form>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($total_pages > 1 || $total_filtered > 0): ?>
        <div class="pager">
            <span class="pager-info">
                แสดง <?= $total_filtered > 0 ? $offset + 1 : 0 ?>–<?= min($offset + $limit, $total_filtered) ?>
                จาก <?= number_format($total_filtered) ?> ข้อความ
            </span>
            <?php
            $base = '?filter=' . urlencode($filter) . '&q=' . urlencode($search);
            echo '<a class="' . ($page <= 1 ? 'pager-disabled' : '') . '" href="' . $base . '&page=' . ($page - 1) . '">‹</a>';
            $st = max(1, $page - 2); $en = min($total_pages, $page + 2);
            if ($st > 1) echo '<span>…</span>';
            for ($i = $st; $i <= $en; $i++) echo '<a class="' . ($i === $page ? 'pager-active' : '') . '" href="' . $base . '&page=' . $i . '">' . $i . '</a>';
            if ($en < $total_pages) echo '<span>…</span>';
            echo '<a class="' . ($page >= $total_pages ? 'pager-disabled' : '') . '" href="' . $base . '&page=' . ($page + 1) . '">›</a>';
            ?>
        </div>
        <?php endif; ?>
    </div>

    </div><!-- /page-body -->
</div><!-- /.main -->

<script>
// Toggle expand/collapse message body
function toggleMsg(row) {
    const body    = row.querySelector('.msg-body');
    const preview = row.querySelector('.msg-preview');
    const isOpen  = body.style.display === 'block';
    body.style.display    = isOpen ? 'none' : 'block';
    preview.style.display = isOpen ? 'block' : 'none';

    // Auto mark as read on expand
    if (!isOpen && row.classList.contains('msg-unread')) {
        const id = row.dataset.id;
        markRead(id, null);
        row.classList.remove('msg-unread');
        row.style.background = 'transparent';
        const dot = row.querySelector('.unread-dot');
        if (dot) dot.style.background = 'transparent';
    }
}

// AJAX mark as read
function markRead(id, btn) {
    fetch('/tkn/admin/contact', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'mark_read=' + id
    }).then(() => {
        // Remove NEW badge
        const row = document.querySelector('[data-id="' + id + '"]');
        if (row) {
            row.querySelectorAll('.sb-badge,[style*="f59e0b"]').forEach(el => {
                if (el.textContent.trim() === 'NEW') el.remove();
            });
            if (btn) btn.remove();
        }
        // Update unread count in sidebar (if visible)
        const badge = document.querySelector('.nav-msg-badge');
        if (badge) {
            const n = parseInt(badge.textContent) - 1;
            if (n <= 0) badge.remove();
            else badge.textContent = n;
        }
    });
}
</script>
<?php include 'footer.php'; ?>
