<?php
/* admin_nav.php — include ใน admin pages ทุกหน้า
   ต้องการ: $conn, $admin_name, $current_page
*/

// นับ badge notifications
// นับ badge notifications
$nav_stats_q = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM owner WHERE status='Pending') AS pend_owners,
        (SELECT COUNT(*) FROM activity_open_request WHERE status='Pending') AS pend_acts,
        (SELECT COUNT(*) FROM booking WHERE status='Pending') AS pend_bookings,
        (SELECT COUNT(*) FROM payment WHERE status = 'PendingReview' AND (payment_method NOT LIKE '%omise%' OR payment_method IS NULL)) AS pend_payments,
        (SELECT COUNT(*) FROM contact_message WHERE is_read = 0) AS unread_msgs
")->fetch_assoc();

$nav_pend_owners   = (int)($nav_stats_q['pend_owners'] ?? 0);
$nav_pend_acts     = (int)($nav_stats_q['pend_acts'] ?? 0);
$nav_pend_bookings = (int)($nav_stats_q['pend_bookings'] ?? 0);
$nav_pend_payments = (int)($nav_stats_q['pend_payments'] ?? 0);
$nav_unread_msgs   = (int)($nav_stats_q['unread_msgs'] ?? 0);
$nav_total_notifs   = $nav_pend_owners + $nav_pend_acts + $nav_pend_bookings + $nav_pend_payments;

function nav_item(string $href, string $label, string $page, string $current, int $badge = 0, string $icon = ''): void {
    $active = $current === $page ? ' active' : '';
    echo "<a class=\"sb-item{$active}\" href=\"{$href}\">{$icon} {$label}";
    if ($badge > 0) echo "<span class=\"sb-badge\">{$badge}</span>";
    echo "</a>\n";
}
?>
<aside class="sidebar">
    <div class="sb-logo">
        <img src="/tkn/assets/image/logo.png" alt="Teawkanna">
        <div style="font-size:10px;color:var(--text3);letter-spacing:.5px;margin-top:3px">Admin Panel</div>
        <button class="admin-sidebar-close" id="adminSidebarClose" type="button" aria-label="Close menu">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>

    <div class="sb-section">General</div>

    <!-- Dashboard -->
    <a class="sb-item <?= $current_page === 'dashboard' ? 'active' : '' ?>" href="/tkn/admin">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7" />
            <rect x="14" y="3" width="7" height="7" />
            <rect x="14" y="14" width="7" height="7" />
            <rect x="3" y="14" width="7" height="7" />
        </svg>
        DashBoard
    </a>

    <!-- User Management -->
    <a class="sb-item <?= $current_page === 'users' ? 'active' : '' ?>" href="/tkn/admin/users">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
            <circle cx="12" cy="7" r="4" />
        </svg>
        User Management
    </a>

    <!-- Community Management -->
    <a class="sb-item <?= $current_page === 'community' ? 'active' : '' ?>" href="/tkn/admin/community">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
        Communities Management
        <?php if ($nav_pend_owners > 0): ?>
        <span class="sb-badge"><?= $nav_pend_owners ?></span>
        <?php endif; ?>
    </a>

    <!-- Activity Management -->
    <a class="sb-item <?= $current_page === 'activities' ? 'active' : '' ?>" href="/tkn/admin/activities">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M12 20h9" />
            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
        </svg>
        Activities Management
        <?php if ($nav_pend_acts > 0): ?>
        <span class="sb-badge"><?= $nav_pend_acts ?></span>
        <?php endif; ?>
    </a>

    <!-- Payment -->
    <a class="sb-item <?= $current_page === 'payment' ? 'active' : '' ?>" href="/tkn/admin/payments">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="2" y="5" width="20" height="14" rx="2" />
            <line x1="2" y1="10" x2="22" y2="10" />
        </svg>
        Payments
        <?php if ($nav_pend_payments > 0): ?>
        <span class="sb-badge"><?= $nav_pend_payments ?></span>
        <?php endif; ?>
    </a>

    <!-- Contact Messages -->
    <a class="sb-item <?= $current_page === 'contact' ? 'active' : '' ?>" href="/tkn/admin/contact">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
            <polyline points="22,6 12,13 2,6" />
        </svg>
        Contact Messages
        <?php if ($nav_unread_msgs > 0): ?>
        <span class="sb-badge nav-msg-badge"><?= $nav_unread_msgs ?></span>
        <?php endif; ?>
    </a>

    <!-- Reports -->
    <a class="sb-item <?= $current_page === 'reports' ? 'active' : '' ?>" href="/tkn/admin/reports">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <line x1="18" y1="20" x2="18" y2="10" />
            <line x1="12" y1="20" x2="12" y2="4" />
            <line x1="6" y1="20" x2="6" y2="14" />
        </svg>
        Reports &amp; Feedback
    </a>

</aside>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
