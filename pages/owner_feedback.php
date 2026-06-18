<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header('Location: /tkn/login');
    exit();
}
include '../db.php';

$owner_id = (int)$_SESSION['user_id'];
$owner_name = $_SESSION['fullname'] ?? 'ผู้ประกอบการ';

$shop_stmt = $conn->prepare("SELECT shop_id, shop_name FROM shop WHERE owner_id=? LIMIT 1");
$shop_stmt->bind_param('i', $owner_id);
$shop_stmt->execute();
$shop = $shop_stmt->get_result()->fetch_assoc();
$shop_stmt->close();

$shop_id = (int)($shop['shop_id'] ?? 0);
$shop_name = $shop['shop_name'] ?? 'ร้านของคุณ';
$activity_filter = max(0, (int)($_GET['activity_id'] ?? 0));
$rating_filter = (int)($_GET['rating'] ?? 0);
if ($rating_filter < 1 || $rating_filter > 5) $rating_filter = 0;

$activities = [];
$reviews = [];
$summary = ['avg_rating' => 0, 'total_reviews' => 0, 'public_reviews' => 0];
$rating_counts = array_fill(1, 5, 0);

if ($shop_id) {
    $stmt = $conn->prepare(
        "SELECT activity_id, activity_name FROM activity
         WHERE shop_id=? ORDER BY activity_name"
    );
    $stmt->bind_param('i', $shop_id);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT ROUND(AVG(r.rating),1) AS avg_rating, COUNT(*) AS total_reviews,
                SUM(CASE WHEN r.is_public=1 THEN 1 ELSE 0 END) AS public_reviews
         FROM review r JOIN activity a ON r.activity_id=a.activity_id
         WHERE a.shop_id=?"
    );
    $stmt->bind_param('i', $shop_id);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: $summary;
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT ROUND(r.rating) AS rating_value, COUNT(*) AS cnt
         FROM review r JOIN activity a ON r.activity_id=a.activity_id
         WHERE a.shop_id=? GROUP BY ROUND(r.rating)"
    );
    $stmt->bind_param('i', $shop_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $value = (int)$row['rating_value'];
        if ($value >= 1 && $value <= 5) $rating_counts[$value] = (int)$row['cnt'];
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT r.rating, r.comment, r.created_at, r.is_public,
                u.fullname, a.activity_name
         FROM review r
         JOIN activity a ON r.activity_id=a.activity_id
         JOIN user u ON r.user_id=u.user_id
         WHERE a.shop_id=?
           AND (?=0 OR a.activity_id=?)
           AND (?=0 OR ROUND(r.rating)=?)
         ORDER BY r.created_at DESC"
    );
    $stmt->bind_param(
        'iiiii',
        $shop_id,
        $activity_filter,
        $activity_filter,
        $rating_filter,
        $rating_filter
    );
    $stmt->execute();
    $reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback - <?= htmlspecialchars($shop_name) ?></title>
    <link rel="stylesheet" href="/tkn/assets/css/ownerstyle2.css">
    <link rel="stylesheet" href="/tkn/assets/css/owner-responsive.css?v=20260613-3">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
    .fb-wrap{padding:30px}.fb-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
    .fb-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
    .fb-number{font-size:28px;font-weight:700;color:#2c4a2f}.fb-label{font-size:12px;color:#7b8277}
    .fb-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:20px}
    .fb-filter label{display:flex;flex-direction:column;gap:5px;font-size:12px;color:#657061}
    .fb-filter select{min-width:210px;padding:9px 11px;border:1px solid #d9ded6;border-radius:9px;
        background:#fff;font-family:'Kanit',sans-serif}
    .fb-btn{padding:9px 15px;border:0;border-radius:9px;background:#2c4a2f;color:#fff;
        font-family:'Kanit',sans-serif;cursor:pointer}
    .review-list{display:flex;flex-direction:column;gap:12px}
    .review-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px}
    .review-head{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap}
    .review-user{font-weight:600;color:#1d3718}.review-meta{font-size:12px;color:#7b8277;margin-top:3px}
    .review-stars{color:#e4a11b;font-size:17px;letter-spacing:1px;white-space:nowrap}
    .review-comment{margin-top:13px;color:#3f493d;line-height:1.7;white-space:pre-wrap}
    .privacy{display:inline-block;margin-left:7px;padding:2px 7px;border-radius:99px;font-size:10px;
        background:#f3f4f6;color:#6b7280}
    .empty{padding:50px 20px;text-align:center;color:#8b9288;background:#fff;
        border:1px solid #e5e7eb;border-radius:14px}
    @media(max-width:760px){.fb-wrap{padding:18px}.fb-grid{grid-template-columns:1fr}.fb-filter label,.fb-filter select{width:100%}}
    </style>
</head>
<body>
<div id="wrapper">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
            <button class="sidebar-toggle" id="sidebarToggle" type="button">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <path d="M15 9l-6 6M9 9l6 6"></path>
                </svg>
            </button>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">
                <p class="nav-section-title">General</p>
                <a href="/tkn/dashboard" class="nav-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="/tkn/my-shop" class="nav-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                    </svg>
                    <span>Activity Management</span>
                </a>
                <a href="/tkn/booking-history" class="nav-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <span>Booking</span>
                </a>
                <a href="/tkn/billing" class="nav-item">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                        <line x1="2" y1="10" x2="22" y2="10"></line>
                    </svg>
                    <span>Billing</span>
                </a>
            </div>
            <div class="nav-section">
                <p class="nav-section-title">Tools</p>
                <a href="/tkn/owner-feedback" class="nav-item active">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <span>Feedback</span>
                </a>
            </div>
        </nav>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <button class="mobile-menu-btn" id="mobileMenuBtn" type="button">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <div class="header-actions">
                <div class="user-menu-wrapper">
                    <button class="user-menu-btn" id="userMenuBtn" type="button">
                        <div class="user-avatar">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <span class="user-name">
                            ผู้ประกอบการ<br><small><?= htmlspecialchars($owner_name) ?></small>
                        </span>
                        <svg class="dropdown-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </button>
                    <div class="user-dropdown" id="userDropdown">
                        <a href="/tkn/shop" class="user-dropdown-item">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <span>Edit Profile</span>
                        </a>
                        <a href="/tkn/logout" class="user-dropdown-item">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <div class="fb-wrap">
            <div class="page-header">
                <h1 class="page-title">คำแนะนำและรีวิวจากลูกค้า</h1>
                <p class="page-subtitle"><?= htmlspecialchars($shop_name) ?></p>
            </div>

            <div class="fb-grid">
                <div class="fb-card">
                    <div class="fb-number"><?= number_format((float)($summary['avg_rating'] ?? 0), 1) ?>/5</div>
                    <div class="fb-label">คะแนนเฉลี่ยจากลูกค้า</div>
                </div>
                <div class="fb-card">
                    <div class="fb-number"><?= number_format((int)($summary['total_reviews'] ?? 0)) ?></div>
                    <div class="fb-label">รีวิวทั้งหมด</div>
                </div>
                <div class="fb-card">
                    <div class="fb-number"><?= number_format((int)($summary['public_reviews'] ?? 0)) ?></div>
                    <div class="fb-label">รีวิวที่เผยแพร่สาธารณะ</div>
                </div>
            </div>

            <form class="fb-card fb-filter" method="get">
                <label>กิจกรรม
                    <select name="activity_id">
                        <option value="0">ทุกกิจกรรม</option>
                        <?php foreach ($activities as $activity): ?>
                        <option value="<?= (int)$activity['activity_id'] ?>"
                            <?= $activity_filter === (int)$activity['activity_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($activity['activity_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>คะแนน
                    <select name="rating">
                        <option value="0">ทุกคะแนน</option>
                        <?php for ($rating = 5; $rating >= 1; $rating--): ?>
                        <option value="<?= $rating ?>" <?= $rating_filter === $rating ? 'selected' : '' ?>>
                            <?= $rating ?> ดาว (<?= $rating_counts[$rating] ?>)
                        </option>
                        <?php endfor; ?>
                    </select>
                </label>
                <button class="fb-btn" type="submit">ดูรีวิว</button>
                <a href="/tkn/owner-feedback" style="font-size:12px;color:#657061;padding:9px;">ล้างตัวกรอง</a>
            </form>

            <?php if (!$reviews): ?>
            <div class="empty">ยังไม่มีรีวิวตามตัวกรองที่เลือก</div>
            <?php else: ?>
            <div class="review-list">
                <?php foreach ($reviews as $review):
                    $stars = max(1, min(5, (int)round($review['rating'])));
                ?>
                <article class="review-card">
                    <div class="review-head">
                        <div>
                            <div class="review-user">
                                <?= htmlspecialchars($review['fullname']) ?>
                                <?php if (!(int)$review['is_public']): ?>
                                <span class="privacy">ส่วนตัว</span>
                                <?php endif; ?>
                            </div>
                            <div class="review-meta">
                                <?= htmlspecialchars($review['activity_name']) ?> ·
                                <?= date('d/m/Y H:i', strtotime($review['created_at'])) ?>
                            </div>
                        </div>
                        <div class="review-stars" aria-label="<?= (float)$review['rating'] ?> ดาว">
                            <?= str_repeat('★', $stars) ?><?= str_repeat('☆', 5 - $stars) ?>
                        </div>
                    </div>
                    <div class="review-comment"><?= htmlspecialchars($review['comment']) ?></div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
const sidebar = document.getElementById('sidebar');
const userMenuButton = document.getElementById('userMenuBtn');
const userDropdown = document.getElementById('userDropdown');
document.getElementById('sidebarToggle')?.addEventListener('click', () => sidebar.classList.remove('active'));
document.getElementById('mobileMenuBtn')?.addEventListener('click', event => {
    event.stopPropagation();
    sidebar.classList.toggle('active');
});
userMenuButton?.addEventListener('click', event => {
    event.stopPropagation();
    userDropdown.classList.toggle('active');
});
document.addEventListener('click', () => userDropdown?.classList.remove('active'));
</script>
</body>
</html>
