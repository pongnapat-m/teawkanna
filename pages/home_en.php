<?php
require_once __DIR__ . '/../config/env.php';
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

include '../db.php';
if (currentLang() === 'th') {
    header('Location: /tkn/home?lang=th');
    exit;
}

// ===== English Messages =====
$t = [
    'lang'              => 'en',
    'title'             => 'Teawkanna - Travel Beyond Tourism',
    'nav_home'          => 'Home',
    'nav_trips'         => 'Activities',
    'nav_contact'       => 'Contact Us',
    'nav_passport'      => 'Passport',
    'nav_logout'        => 'Logout',
    'nav_login'         => 'Login',
    'nav_logout_confirm'=> 'Are you sure you want to logout?',
    'lang_switch_label' => 'TH',
    'lang_switch_href'  => '/tkn/home?lang=th',
    'hero_title'        => 'Travel Beyond Tourism',
    'hero_sub'          => 'Learn, Create, and Grow with the Community',
    'ab_title'          => 'Teawkanna — From Tourist to Creator',
    'ab_label'          => 'About Us',
    'ab_col1'           => 'We believe that good tourism is not just about traveling, but learning and actively participating with local communities. Whether you are a beginner or a long-time nature lover.',
    'ab_col2'           => 'Teawkanna is a platform connecting tourists with farmers, artisans, and communities through diverse activities and workshops to create meaningful experiences.',
    'stat1_num'         => '500+',
    'stat1_label'       => 'Tourists Joined',
    'stat2_num'         => '50+',
    'stat2_label'       => 'Agricultural Workshops',
    'feat1_btn'         => 'AI Personalized Travel Planning',
    'feat1_desc'        => 'Smart travel planning that curates agricultural tourism routes tailored to your interests and schedule for the most perfect relaxation experience.',
    'feat2_btn'         => 'Book Agricultural Workshops',
    'feat2_desc'        => 'Experience community lifestyle through diverse workshop activities, including organic farming and traditional handicrafts, with convenient online booking and instant confirmation.',
    'feat3_btn'         => 'Seasonal Calendar & Harvesting',
    'feat3_desc'        => 'Never miss important moments with activity calendars and harvest seasons, helping you plan to experience the freshest produce at the right time.',
    'feat4_btn'         => 'Organic Vegetable Farms',
    'feat4_desc'        => 'Explore quality organic vegetable farms and orchards in the area to learn natural planting processes, with activities to pick fresh produce directly from the plants.',
    'feat5_btn'         => 'Handicrafts & Traditional Arts',
    'feat5_desc'        => 'Immerse in local wisdom through exquisite handicraft and traditional art workshops that inspire and connect you to the identity of Chonburi communities.',
    'act_title'         => 'Featured Activities',
    'act_empty'         => 'No activities available yet',
    'act_priceper'      => 'THB',
    'review_title'      => 'Reviews from Tourists',
    'review_write'      => 'Write a Review',
    'review_all'        => 'View All Reviews ›',
    'review_label'      => 'Reviews',
    'review_nodata'     => 'No reviews yet',
    'modal_title'       => 'All Reviews',
    'modal_sub'         => 'From tourists who have participated in activities',
    'modal_rating_lbl'  => 'Average Rating',
    'write_title'       => 'Write a Review',
    'write_activity'    => 'Activity Name (ID)',
    'write_comment'     => 'Review details...',
    'write_cancel'      => 'Cancel',
    'write_submit'      => 'Submit Review',
    'write_login_warn'  => 'Please login before writing a review',
    'write_success'     => 'Review submitted successfully!',
    'write_error'       => 'An error occurred, please try again',
    'footer_menu'       => 'Menu',
    'footer_contact'    => 'Contact Channels',
    'footer_copy'       => '2025 Teawkanna. All rights reserved.',
    'logout_confirm'    => 'Are you sure you want to logout?',
];

// Fetch 3 latest featured activities
$act_sql = "SELECT activity.activity_id, activity.activity_name, activity.description, activity.adult_price,
                   activity.activity_pic,
                   shop.shop_name, shop.district,
                   (SELECT AVG(rating) FROM review WHERE review.activity_id = activity.activity_id) as avg_rating
            FROM activity
            JOIN shop ON activity.shop_id = shop.shop_id
            WHERE activity.status = 'Active'
            ORDER BY activity.activity_id DESC
            LIMIT 3";
$act_result = mysqli_query($conn, $act_sql);
$featured_activities = [];
while ($row = mysqli_fetch_assoc($act_result)) {
    $featured_activities[] = $row;
}

// Fetch latest 12 reviews for slider
$slider_sql = "SELECT r.review_id, r.rating, r.comment, r.created_at,
                      u.fullname, u.profile_pic, a.activity_name, a.activity_pic
               FROM review r
               JOIN user u ON r.user_id = u.user_id
               JOIN activity a ON r.activity_id = a.activity_id
               ORDER BY r.created_at DESC
               LIMIT 12";
$slider_result = mysqli_query($conn, $slider_sql);
$slider_reviews = [];
while ($row = mysqli_fetch_assoc($slider_result)) {
    $slider_reviews[] = $row;
}

// Fetch all reviews for modal with average calculation
$all_rev_sql = "SELECT r.review_id, r.rating, r.comment, r.created_at,
                       u.fullname, u.profile_pic, a.activity_name
                FROM review r
                JOIN user u ON r.user_id = u.user_id
                JOIN activity a ON r.activity_id = a.activity_id
                ORDER BY r.created_at DESC";
$all_rev_result = mysqli_query($conn, $all_rev_sql);
$all_reviews = [];
while ($row = mysqli_fetch_assoc($all_rev_result)) {
    $all_reviews[] = $row;
}

function homeReviewAvatar(?string $profilePic): string {
    if (!$profilePic) return '';
    if (preg_match('#^https?://#', $profilePic) || str_starts_with($profilePic, '/tkn/')) {
        return $profilePic;
    }
    return '/tkn/handlers/' . ltrim($profilePic, '/');
}

// คำนวณ avg rating และ distribution
$avg_rating = 0;
$rating_dist = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
if (!empty($all_reviews)) {
    $sum = 0;
    foreach ($all_reviews as $r) {
        $sum += $r['rating'];
        $star = max(1, min(5, round($r['rating'])));
        $rating_dist[$star]++;
    }
    $avg_rating = $sum / count($all_reviews);
}
$total_reviews = count($all_reviews);
// Convert distribution to percentage
$dist_pct = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
foreach ($rating_dist as $s => $cnt) {
    $dist_pct[$s] = $total_reviews > 0 ? round($cnt / $total_reviews * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t['title'] ?></title>
    <?php
        $cssPath = __DIR__ . '/css/style.css';
        $cssVer = file_exists($cssPath) ? filemtime($cssPath) : time();
    ?>
    <link rel="stylesheet" href="/tkn/assets/css/style.css?v=<?= $cssVer ?>">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-nav.css">
    <link rel="stylesheet" href="/tkn/assets/css/responsive-footer.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div id="wrapper">

        <!-- Header -->
        <header class="header">
            <div class="container">
                <div class="logo"><img src="/tkn/assets/image/logo.png" alt="Teawkanna"></div>
                <nav class="nav">
                    <a href="/tkn/home" class="nav-link"><?= $t['nav_home'] ?></a>
                    <a href="/tkn/activities" class="nav-link"><?= $t['nav_trips'] ?></a>
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
                                <i class="fas fa-user"></i> Profile
                            </a>
                            <a href="/tkn/profile?tab=passport" class="nav-dropdown-item">
                                <i class="fas fa-passport"></i> <?= $t['nav_passport'] ?>
                            </a>
                            <a href="/tkn/logout" class="nav-dropdown-item">
                                <i class="fas fa-sign-out-alt"></i> Logout
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

        <!-- Hero Section -->
        <section class="hero-section">
            <div class="hero-inner">
                <div class="hero-content">
                    <span class="hero-badge">Agricultural Tourism</span>
                    <h1 class="hero-title"><?= $t['hero_title'] ?></h1>
                    <p class="hero-subtitle"><?= $t['hero_sub'] ?></p>
                    <a href="/tkn/activities" class="hero-cta">
                        Explore Activities <span class="hero-cta-arrow">→</span>
                    </a>
                </div>
                <div class="hero-mission-card">
                    <div class="hero-mission-dot"></div>
                    <div class="hero-mission-title"><?= $t['ab_label'] ?></div>
                    <p class="hero-mission-text"><?= $t['ab_col1'] ?></p>
                    <a href="#about" class="hero-mission-link">Learn More →</a>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section class="ab" id="about">
            <div class="container">
                    <div class="ab-right">
                        <h2 class="ab-headline"><?= $t['ab_title'] ?></h2>
                        <p class="ab-sub"><?= $t['ab_col1'] ?></p>
                        <a href="/tkn/activities" class="ab-learn-btn">Learn More</a>
                    </div>
                <div class="stats-grid">
                    <div class="stat-card-photo stat-card-photo--1"></div>
                    <div class="stat-card-num">
                        <div class="stat-card-num__arrow">↗</div>
                        <div class="stat-number"><?= $t['stat1_num'] ?></div>
                        <div class="stat-label"><?= $t['stat1_label'] ?></div>
                        <p class="stat-desc"><?= $t['ab_col2'] ?></p>
                    </div>
                    <div class="stat-card-photo stat-card-photo--2"></div>
                    <div class="stat-card-num stat-card-num--green">
                        <div class="stat-card-num__arrow">↗</div>
                        <div class="stat-number"><?= $t['stat2_num'] ?></div>
                        <div class="stat-label"><?= $t['stat2_label'] ?></div>
                    </div>
                </div>
            </div>
        </section>
        <!-- Activities Section -->
        <section class="activities-section">
            <div class="container">
                <h2 class="section-title"><?= $t['act_title'] ?></h2>
                <div class="activities-grid">
                    <?php if (empty($featured_activities)): ?>
                    <p style="color:#999;grid-column:1/-1;text-align:center;"><?= $t['act_empty'] ?></p>
                    <?php else: ?>
                    <?php foreach ($featured_activities as $act):
                        $act_thumb = '';
                        if (!empty($act['activity_pic'])) {
                            $tp = $act['activity_pic'];
                            if (preg_match('#^https?://#', $tp)) {
                                $act_thumb = $tp;
                            } elseif (preg_match('#^uploads/activity_pics/#', $tp)) {
                                $act_thumb = '/tkn/handlers/' . $tp;
                            } else {
                                $act_thumb = $tp;
                            }
                        }
                        $act_thumb = $act_thumb ?: '/tkn/assets/image/garden1.jpg';
                    ?>
                    <div class="activity-card"
                        onclick="window.location.href='<?= p('booking', ['id' => $act['activity_id']]) ?>'"
                        style="cursor:pointer;">
                        <div class="activity-image">
                            <img src="<?= htmlspecialchars($act_thumb) ?>"
                                alt="<?= htmlspecialchars($act['activity_name']) ?>"
                                style="width:100%;height:100%;object-fit:cover;border-radius:12px;"
                                onerror="this.src='/tkn/assets/image/garden1.jpg';">
                        </div>
                        <div class="activity-info">
                            <h3><?= htmlspecialchars($act['activity_name']) ?></h3>
                            <p><?= htmlspecialchars(mb_substr($act['description'], 0, 80)) ?>...</p>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                                <small style="color:#666;"><i class="fas fa-map-marker-alt"></i>
                                    <?= htmlspecialchars($act['district']) ?></small>
                                <strong
                                    style="color:#2d6a4f;"><?= $t['act_priceper'] ?><?= number_format($act['adult_price'], 0) ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Image Banner -->
        <section class="image-banner"></section>
        <!-- Features Dropdown Section -->
        <section class="dropdown-section">
            <div class="dropdown-left">
                <?php
            $feats = [
                [$t['feat1_btn'], $t['feat1_desc']],
                [$t['feat2_btn'], $t['feat2_desc']],
                [$t['feat3_btn'], $t['feat3_desc']],
                [$t['feat4_btn'], $t['feat4_desc']],
                [$t['feat5_btn'], $t['feat5_desc']],
            ];
            foreach ($feats as $f): ?>
                <div class="dropdown-item">
                    <button class="dropdown-btn"><?= $f[0] ?></button>
                    <div class="dropdown-info">
                        <p><?= $f[1] ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="dropdown-right">
                <div class="dropdown-image">
                    <img src="/tkn/assets/image/garden1.jpg" alt="ภาพตัวอย่าง">
                </div>
            </div>
        </section>




        <!-- ===== Review Section (ดึงจาก DB) ===== -->
        <section class="review-section">
            <div class="container">
                <div class="review-header">
                    <h2 class="rw-section-title"><?= $t['review_title'] ?></h2>
                    <div class="review-controls">
                        <?php if (isset($_SESSION['user_id'])): ?>
                        <button class="review-btn" id="openWriteReviewBtn"><?= $t['review_write'] ?></button>
                        <?php else: ?>
                        <button class="review-btn"
                            onclick="alert('<?= $t['write_login_warn'] ?>')"><?= $t['review_write'] ?></button>
                        <?php endif; ?>
                        <button class="all-reviews-btn" onclick="openAllReviews()"><?= $t['review_all'] ?></button>
                    </div>
                </div>

                <div class="review-slider">
                    <div class="review-grid">
                        <?php if (!empty($slider_reviews)): ?>
                        <?php foreach ($slider_reviews as $rev):
                            $stars_full  = floor($rev['rating']);
                            $stars_empty = 5 - $stars_full;
                            $rev_thumb = '';
                            if (!empty($rev['activity_pic'])) {
                                $tp = $rev['activity_pic'];
                                if (preg_match('#^https?://#', $tp)) {
                                    $rev_thumb = $tp;
                                } elseif (preg_match('#^uploads/activity_pics/#', $tp)) {
                                    $rev_thumb = '/tkn/handlers/' . $tp;
                                } else {
                                    $rev_thumb = $tp;
                                }
                            }
                            $rev_thumb = $rev_thumb ?: '/tkn/assets/image/garden1.jpg';
                        ?>
                        <div class="review-card">
                            <div class="review-image">
                                <img src="<?= htmlspecialchars($rev_thumb) ?>"
                                    alt="<?= htmlspecialchars($rev['activity_name']) ?>"
                                    style="width:100%;height:100%;object-fit:cover;"
                                    onerror="this.src='/tkn/assets/image/garden1.jpg';">
                            </div>
                            <div class="review-content">
                                <div class="review-label"><?= htmlspecialchars($rev['activity_name']) ?></div>
                                <div class="review-stars">
                                    <?php for ($i = 0; $i < $stars_full;  $i++): ?><i
                                        class="fas fa-star"></i><?php endfor; ?>
                                    <?php for ($i = 0; $i < $stars_empty; $i++): ?><i
                                        class="far fa-star"></i><?php endfor; ?>
                                </div>
                                <p><?= htmlspecialchars(mb_substr($rev['comment'], 0, 100)) ?><?= mb_strlen($rev['comment']) > 100 ? '...' : '' ?>
                                </p>
                                <div class="review-footer">
                                    <?php $review_avatar = homeReviewAvatar($rev['profile_pic'] ?? ''); ?>
                                    <span class="review-avatar">
                                        <?php if ($review_avatar): ?>
                                        <img src="<?= htmlspecialchars($review_avatar) ?>" alt=""
                                            onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                                        <span class="review-avatar-fallback" style="display:none;">
                                            <?= htmlspecialchars(mb_strtoupper(mb_substr($rev['fullname'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="review-avatar-fallback">
                                            <?= htmlspecialchars(mb_strtoupper(mb_substr($rev['fullname'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                        </span>
                                        <?php endif; ?>
                                    </span>
                                    <span><?= htmlspecialchars($rev['fullname']) ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="review-card">
                            <div class="review-image"></div>
                            <div class="review-content">
                                <div class="review-label"><?= $t['review_label'] ?></div>
                                <p style="color:#999;"><?= $t['review_nodata'] ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (count($slider_reviews) > 3): ?>
                <div class="pagination">
                    <?php $dots = ceil(count($slider_reviews) / 3); for ($d = 0; $d < $dots; $d++): ?>
                    <span class="dot <?= $d === 0 ? 'active' : '' ?>"></span>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Footer -->
        <footer class="footer">
            <div class="container">
                <div class="footer-content">
                    <div class="footer-left">
                        <h2 class="footer-logo">Logo</h2>
                        <p>Teawkanna — Agricultural Tourism and Community Lifestyle</p>
                    </div>
                    <div class="footer-menu">
                        <h4><?= $t['footer_menu'] ?></h4>
                        <ul>
                            <li><a href="/tkn/home"><?= $t['nav_home'] ?></a></li>
                            <li><a href="/tkn/activities"><?= $t['nav_trips'] ?></a></li>
                            <li><a href="#"><?= $t['nav_contact'] ?></a></li>
                        </ul>
                    </div>
                    <div class="footer-social">
                        <h4><?= $t['footer_contact'] ?></h4>
                        <ul>
                            <li><i class="fab fa-facebook"></i> เฟซบุ๊กเพจ</li>
                            <li><i class="fab fa-line"></i> ไลน์ออฟฟิเซียล</li>
                            <li><i class="fab fa-tiktok"></i> ทิกต๊อกเพจ</li>
                            <li><i class="fas fa-envelope"></i> <a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="9eeafbffe9f5fff0f0ffdef9f3fff7f2b0fdf1f3">[email&#160;protected]</a></li>
                            <li><i class="fas fa-phone"></i> 099-999-9999</li>
                        </ul>
                    </div>
                </div>
                <div class="footer-bottom">
                    <p><?= $t['footer_copy'] ?></p>
                </div>
            </div>
        </footer>
    </div>

    <!-- ===== All Reviews Modal (Fetched from DB) ===== -->
    <div id="allReviewsModal" style="display:none;position:fixed;inset:0;z-index:9999;background:#fff;overflow-y:auto;">
        <div style="max-width:900px;margin:0 auto;padding:32px 20px 60px;">
            <div
                style="display:flex;align-items:center;justify-content:space-between;margin-bottom:32px;border-bottom:2px solid #f0f0f0;padding-bottom:20px;">
                <div>
                    <h2 style="margin:0;font-size:1.8rem;font-weight:700;color:#1a1a1a;"><?= $t['modal_title'] ?></h2>
                    <p style="margin:6px 0 0;color:#666;"><?= $t['modal_sub'] ?></p>
                </div>
                <button onclick="closeAllReviews()"
                    style="background:none;border:2px solid #ddd;border-radius:50%;width:44px;height:44px;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#333;transition:all 0.2s;"
                    onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='none'">✕</button>
            </div>

            <!-- Summary Bar (คำนวณจาก DB จริง) -->
            <div
                style="display:flex;gap:24px;align-items:center;background:#fafafa;border-radius:16px;padding:24px;margin-bottom:32px;flex-wrap:wrap;">
                <div style="text-align:center;min-width:80px;">
                    <div style="font-size:3rem;font-weight:800;color:#1a1a1a;line-height:1;">
                        <?= $total_reviews > 0 ? number_format($avg_rating, 1) : '-' ?></div>
                    <div style="color:#f5a623;font-size:1.2rem;">
                        <?php
                    $avg_f = floor($avg_rating);
                    for ($i = 1; $i <= 5; $i++) {
                        if ($i <= $avg_f) echo '★';
                        elseif ($i - 0.5 <= $avg_rating) echo '½';
                        else echo '☆';
                    }
                    ?>
                    </div>
                    <div style="color:#888;font-size:0.85rem;margin-top:4px;"><?= $total_reviews ?>
                        <?= $t['modal_rating_lbl'] ?></div>
                </div>
                <div style="flex:1;min-width:200px;">
                    <?php foreach ($dist_pct as $star => $pct): ?>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <span style="width:20px;text-align:right;font-size:0.85rem;color:#555;"><?= $star ?></span>
                        <span style="color:#f5a623;font-size:0.85rem;">★</span>
                        <div style="flex:1;background:#e0e0e0;border-radius:4px;height:8px;overflow:hidden;">
                            <div style="width:<?= $pct ?>%;background:#f5a623;height:100%;border-radius:4px;"></div>
                        </div>
                        <span style="font-size:0.8rem;color:#888;width:30px;"><?= $pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Review Cards Grid -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;">
                <?php if (!empty($all_reviews)): ?>
                <?php foreach ($all_reviews as $rev):
                    $sf = floor($rev['rating']); $se = 5 - $sf;
                ?>
                <div style="background:#fff;border:1px solid #eee;border-radius:16px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);transition:transform 0.2s;"
                    onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='none'">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                        <?php $review_avatar = homeReviewAvatar($rev['profile_pic'] ?? ''); ?>
                        <span class="review-avatar review-avatar-large">
                            <?php if ($review_avatar): ?>
                            <img src="<?= htmlspecialchars($review_avatar) ?>" alt=""
                                onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <span class="review-avatar-fallback" style="display:none;">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($rev['fullname'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                            </span>
                            <?php else: ?>
                            <span class="review-avatar-fallback">
                                <?= htmlspecialchars(mb_strtoupper(mb_substr($rev['fullname'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                            </span>
                            <?php endif; ?>
                        </span>
                        <div>
                            <div style="font-weight:600;font-size:0.95rem;color:#1a1a1a;">
                                <?= htmlspecialchars($rev['fullname']) ?></div>
                            <div style="font-size:0.75rem;color:#999;">
                                <?= date('d/m/Y', strtotime($rev['created_at'])) ?></div>
                        </div>
                    </div>
                    <div style="color:#f5a623;font-size:1rem;margin-bottom:8px;">
                        <?= str_repeat('★', $sf) ?><?= str_repeat('☆', $se) ?>
                        <span
                            style="color:#555;font-size:0.85rem;margin-left:4px;"><?= number_format($rev['rating'], 1) ?>/5</span>
                    </div>
                    <div
                        style="font-size:0.8rem;color:#4a7c59;background:#f0f7f2;border-radius:8px;padding:4px 10px;display:inline-block;margin-bottom:10px;">
                        <?= htmlspecialchars($rev['activity_name']) ?>
                    </div>
                    <p style="font-size:0.9rem;color:#444;line-height:1.6;margin:0;">
                        <?= htmlspecialchars($rev['comment']) ?></p>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:#999;">
                    <div style="font-size:3rem;margin-bottom:12px;">📝</div>
                    <p><?= $t['review_nodata'] ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== Write Review Modal (Saves to DB) ===== -->
    <?php if (isset($_SESSION['user_id'])): ?>
    <div class="review-modal" id="writeReviewModal">
        <div class="modal-content">
            <h3><?= $t['write_title'] ?></h3>
            <select id="writeActivityId" class="input input-bordered w-full"
                style="width:100%;padding:10px;margin-bottom:10px;border:1px solid #ddd;border-radius:8px;">
                <option value="">-- <?= $t['write_activity'] ?> --</option>
                <?php
            $acts_q = mysqli_query($conn, "SELECT activity_id, activity_name FROM activity WHERE status='Active' ORDER BY activity_name");
            while ($a = mysqli_fetch_assoc($acts_q)):
            ?>
                <option value="<?= $a['activity_id'] ?>"><?= htmlspecialchars($a['activity_name']) ?></option>
                <?php endwhile; ?>
            </select>
            <textarea id="writeComment" placeholder="<?= $t['write_comment'] ?>"
                style="width:100%;height:100px;padding:10px;border:1px solid #ddd;border-radius:8px;margin-bottom:10px;resize:vertical;font-family:inherit;"></textarea>
            <div class="star-rating" id="starRating">
                <i class="far fa-star" data-star="1"></i>
                <i class="far fa-star" data-star="2"></i>
                <i class="far fa-star" data-star="3"></i>
                <i class="far fa-star" data-star="4"></i>
                <i class="far fa-star" data-star="5"></i>
            </div>
            <div class="modal-actions">
                <button class="cancel-btn" id="cancelWriteReview"><?= $t['write_cancel'] ?></button>
                <button class="submit-btn" id="submitWriteReview"><?= $t['write_submit'] ?></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script><script>
    // ===== Dropdown =====
    document.querySelectorAll(".dropdown-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const info = btn.nextElementSibling;
            const isActive = btn.classList.contains("active");
            document.querySelectorAll(".dropdown-info").forEach(i => i.style.display = "none");
            document.querySelectorAll(".dropdown-btn").forEach(b => b.classList.remove("active"));
            if (!isActive) {
                btn.classList.add("active");
                info.style.display = "block";
            }
        });
    });

    // ===== Review Slider Dots =====
    const dots = document.querySelectorAll('.dot');
    const reviewGrid = document.querySelector('.review-grid');
    dots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            reviewGrid.style.transform = `translateX(-${index * 100}%)`;
            dots.forEach(d => d.classList.remove('active'));
            dot.classList.add('active');
        });
    });

    // ===== All Reviews Modal =====
    function openAllReviews() {
        document.getElementById('allReviewsModal').style.display = 'block';
        document.body.classList.add('modal-open');
        document.getElementById('allReviewsModal').scrollTop = 0;
    }

    function closeAllReviews() {
        document.getElementById('allReviewsModal').style.display = 'none';
        document.body.classList.remove('modal-open');
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeAllReviews();
            closeWriteReview();
        }
    });

    // ===== Write Review Modal =====
    <?php if (isset($_SESSION['user_id'])): ?>
    let writeRating = 0;
    const writeStars = document.querySelectorAll('#starRating i');

    document.getElementById('openWriteReviewBtn')?.addEventListener('click', () => {
        document.getElementById('writeReviewModal').classList.add('active');
    });
    document.getElementById('cancelWriteReview')?.addEventListener('click', closeWriteReview);

    function closeWriteReview() {
        const m = document.getElementById('writeReviewModal');
        if (m) m.classList.remove('active');
    }

    writeStars.forEach(star => {
        star.addEventListener('click', () => {
            writeRating = parseInt(star.dataset.star);
            writeStars.forEach(s => {
                s.classList.toggle('fas', parseInt(s.dataset.star) <= writeRating);
                s.classList.toggle('far', parseInt(s.dataset.star) > writeRating);
            });
        });
    });

    document.getElementById('submitWriteReview')?.addEventListener('click', () => {
        const actId = document.getElementById('writeActivityId').value;
        const comment = document.getElementById('writeComment').value.trim();
        if (!actId || !comment || writeRating === 0) {
            alert('Please fill in all fields (Activity, Review, and Rating)');
            return;
        }
        const fd = new FormData();
        fd.append('activity_id', actId);
        fd.append('comment', comment);
        fd.append('rating', writeRating);

        fetch('/tkn/handlers/review_submit.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('<?= $t['write_success'] ?>');
                    closeWriteReview();
                    location.reload();
                } else {
                    alert('<?= $t['write_error'] ?>: ' + (data.message || ''));
                }
            })
            .catch(() => alert('<?= $t['write_error'] ?>'));
    });
    <?php endif; ?>

    // ===== User Dropdown =====
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

    // ===== Mobile Menu =====
    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
    const nav = document.querySelector('.nav');
    if (mobileMenuToggle && nav) {
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
    }
    </script>
</body>

</html>
