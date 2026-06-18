<?php
/**
 * booking_receipt.php
 * ใบเสร็จการจอง — สไตล์ Shopee/ช้อปปี้
 * เรียกใช้: booking_receipt.php?booking_id=<int>
 */

if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(0);
include '../db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /tkn/login');
    exit;
}

$booking_id = (int)($_GET['booking_id'] ?? 0);
if (!$booking_id) { die('ไม่พบหมายเลขการจอง'); }

// ── ดึงข้อมูลการจองพร้อม activity, shop, user ────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        b.booking_id, b.booking_date, b.travel_date, b.status,
        b.adult_quantity, b.kid_quantity,
        b.booked_adult_price, b.booked_kid_price,
        b.total_price, b.original_price, b.discount_amount, b.promotion_id,
        b.payment_method, b.bank_name, p.title AS promotion_title,
        a.activity_id, a.activity_name, a.description, a.activity_pic,
        a.end_date AS activity_end_date, a.status AS activity_status,
        a.duration_label,
        s.shop_name, s.district,
        u.fullname, u.email
    FROM booking b
    JOIN activity a ON b.activity_id = a.activity_id
    JOIN shop s     ON a.shop_id = s.shop_id
    JOIN user u     ON b.user_id = u.user_id
    LEFT JOIN promotion p ON b.promotion_id = p.promotion_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->bind_param('ii', $booking_id, $_SESSION['user_id']);
$stmt->execute();
$bk = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bk) {
    die('ไม่พบข้อมูลการจอง หรือคุณไม่มีสิทธิ์เข้าถึง');
}

// ── ตรวจว่ากิจกรรมผ่านไปแล้วหรือยัง ─────────────────────────────────────────
$now          = new DateTime();
$activity_end = $bk['activity_end_date'] ? new DateTime($bk['activity_end_date']) : null;
$is_completed = ($bk['activity_status'] === 'Completed')
             || ($activity_end && $now > $activity_end)
             || ($bk['status'] === 'Completed');

// ── ตรวจว่า user รีวิว activity นี้แล้วหรือยัง ─────────────────────────────────
$already_reviewed = false;
if ($is_completed) {
    $rq = $conn->prepare("SELECT review_id FROM review WHERE user_id = ? AND activity_id = ? LIMIT 1");
    $rq->bind_param('ii', $_SESSION['user_id'], $bk['activity_id']);
    $rq->execute();
    $already_reviewed = (bool)$rq->get_result()->fetch_assoc();
    $rq->close();
}

// ── ดึง payment record ────────────────────────────────────────────────────────
$pq = $conn->prepare("SELECT payment_method, payment_date, slip_image FROM payment WHERE booking_id = ? ORDER BY payment_date DESC LIMIT 1");
$pq->bind_param('i', $booking_id);
$pq->execute();
$payment = $pq->get_result()->fetch_assoc();
$pq->close();

// ── label ต่าง ๆ ──────────────────────────────────────────────────────────────
$status_labels = [
    'Pending'   => ['label' => 'รอชำระเงิน',   'color' => '#d97706', 'bg' => '#fef3c7'],
    'Paid'      => ['label' => 'ชำระเงินแล้ว', 'color' => '#15803d', 'bg' => '#dcfce7'],
    'Completed' => ['label' => 'เสร็จสิ้น',    'color' => '#1d4ed8', 'bg' => '#dbeafe'],
    'Cancel'    => ['label' => 'ยกเลิก',        'color' => '#dc2626', 'bg' => '#fee2e2'],
];
$st = $status_labels[$bk['status']] ?? ['label' => $bk['status'], 'color' => '#555', 'bg' => '#f3f4f6'];

$method_labels = [
    'qr'     => 'QR พร้อมเพย์',
    'mobile' => 'Mobile Banking',
    'card'   => 'บัตรเครดิต/เดบิต',
];
$method_text = $method_labels[$bk['payment_method']] ?? ($bk['payment_method'] ?? '-');
if ($bk['payment_method'] === 'mobile' && $bk['bank_name']) {
    $method_text .= ' (' . $bk['bank_name'] . ')';
}

// ── format วันที่ ─────────────────────────────────────────────────────────────
function fmt_date($d) {
    if (!$d) return '-';
    $dt = new DateTime($d);
    $months_th = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return $dt->format('j') . ' ' . $months_th[(int)$dt->format('n')] . ' ' . ($dt->format('Y') + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ใบเสร็จการจอง #<?= $booking_id ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green:     #2b4218;
    --green-mid: #4a7c38;
    --green-pale:#f0f7ea;
    --border:    #e5e7eb;
    --text:      #1a1a1a;
    --sub:       #6b7280;
    --radius:    14px;
    --font:      'Kanit', sans-serif;
}

body {
    font-family: var(--font);
    background: #f3f4f6;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 32px 16px 60px;
    color: var(--text);
}

/* ── Receipt card ── */
.receipt {
    width: 100%;
    max-width: 520px;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 4px 32px rgba(0,0,0,.1);
    overflow: hidden;
    position: relative;
}

/* ── Header strip ── */
.receipt-header {
    background: var(--green);
    padding: 22px 28px 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 14px;
}
.receipt-icon {
    width: 44px; height: 44px;
    background: rgba(255,255,255,.15);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.receipt-header-text { flex: 1; }
.receipt-header-text h2 {
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 2px;
}
.receipt-header-text span {
    font-size: 12px;
    opacity: 0.7;
    font-weight: 300;
}
.close-btn {
    width: 34px; height: 34px;
    background: rgba(255,255,255,.15);
    border: none;
    border-radius: 50%;
    color: #fff;
    font-size: 16px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    transition: background .2s;
    text-decoration: none;
}
.close-btn:hover { background: rgba(255,255,255,.28); }

/* ── Activity image ── */
.receipt-img {
    width: 100%; height: 180px;
    object-fit: cover;
    display: block;
}

/* ── Body ── */
.receipt-body { padding: 24px 28px; }

/* ── Booking ID + status ── */
.booking-id-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 8px;
}
.booking-id-label {
    font-size: 12px;
    color: var(--sub);
    margin-bottom: 2px;
}
.booking-id-num {
    font-size: 22px;
    font-weight: 700;
    color: var(--green);
    letter-spacing: 0.02em;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

/* ── Activity info ── */
.activity-name {
    font-size: 17px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 4px;
}
.activity-shop {
    font-size: 13px;
    color: var(--sub);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ── Divider ── */
.divider {
    border: none;
    border-top: 1.5px dashed var(--border);
    margin: 18px 0;
    position: relative;
}
.divider::before, .divider::after {
    content: '';
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 18px; height: 18px;
    background: #f3f4f6;
    border-radius: 50%;
}
.divider::before { left: -37px; }
.divider::after  { right: -37px; }

/* ── Info rows ── */
.info-section { margin-bottom: 20px; }
.info-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--sub);
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 10px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
    margin-bottom: 9px;
    gap: 8px;
}
.info-row .key { color: var(--sub); flex-shrink: 0; }
.info-row .val { font-weight: 500; text-align: right; }

/* ── Total ── */
.total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 0 0;
    border-top: 2px solid var(--border);
    margin-top: 4px;
}
.total-row .key { font-size: 15px; font-weight: 600; }
.total-row .val { font-size: 22px; font-weight: 700; color: var(--green); }

/* ── Footer actions ── */
.receipt-footer {
    padding: 20px 28px 28px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 13px 20px;
    border-radius: 12px;
    font-family: var(--font);
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all .2s;
    border: none;
    width: 100%;
}
.btn-review {
    background: var(--green);
    color: #fff;
}
.btn-review:hover { background: #1e3010; }
.btn-close {
    background: #f3f4f6;
    color: var(--sub);
    font-weight: 500;
}
.btn-close:hover { background: #e5e7eb; color: var(--text); }

/* ── Already reviewed note ── */
.reviewed-note {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--green-pale);
    border: 1px solid #c6deb8;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px;
    color: var(--green);
    font-weight: 500;
}

/* ── Write review modal ── */
.review-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 999;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.review-overlay.active { display: flex; }
.review-modal {
    background: #fff;
    border-radius: 18px;
    padding: 28px 24px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 8px 40px rgba(0,0,0,.18);
}
.review-modal h3 {
    font-size: 18px;
    font-weight: 700;
    color: var(--green);
    margin-bottom: 18px;
}
.star-row {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    font-size: 32px;
    color: #d1d5db;
    cursor: pointer;
}
.star-row i.active { color: #f59e0b; }
.review-textarea {
    width: 100%;
    height: 110px;
    padding: 12px;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    font-family: var(--font);
    font-size: 14px;
    resize: vertical;
    margin-bottom: 16px;
    outline: none;
    transition: border-color .2s;
}
.review-textarea:focus { border-color: var(--green-mid); }
.review-actions {
    display: flex;
    gap: 10px;
}
.btn-cancel-review {
    flex: 1;
    padding: 11px;
    border-radius: 10px;
    border: 1.5px solid var(--border);
    background: #fff;
    font-family: var(--font);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    color: var(--sub);
    transition: all .2s;
}
.btn-cancel-review:hover { background: #f3f4f6; }
.btn-submit-review {
    flex: 2;
    padding: 11px;
    border-radius: 10px;
    border: none;
    background: var(--green);
    color: #fff;
    font-family: var(--font);
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s;
}
.btn-submit-review:hover { background: #1e3010; }
.btn-submit-review:disabled { opacity: .6; cursor: default; }

@media (max-width: 480px) {
    body { padding: 0; align-items: flex-start; }
    .receipt { border-radius: 0; min-height: 100vh; }
}
</style>
</head>
<body>

<div class="receipt">

    <!-- Header -->
    <div class="receipt-header">
        <div class="receipt-icon">🧾</div>
        <div class="receipt-header-text">
            <h2>ใบยืนยันการจอง</h2>
            <span>เที่ยวกันนา · Teawkanna</span>
        </div>
        <a href="javascript:history.back()" class="close-btn" title="ปิด">✕</a>
    </div>

    <!-- Activity image -->
    <?php if ($bk['activity_pic']): ?>
    <img class="receipt-img"
         src="<?= htmlspecialchars($bk['activity_pic']) ?>"
         alt="<?= htmlspecialchars($bk['activity_name']) ?>"
         onerror="this.style.display='none'">
    <?php endif; ?>

    <!-- Body -->
    <div class="receipt-body">

        <!-- Booking ID + Status -->
        <div class="booking-id-row">
            <div>
                <div class="booking-id-label">หมายเลขการจอง</div>
                <div class="booking-id-num">#<?= str_pad($booking_id, 6, '0', STR_PAD_LEFT) ?></div>
            </div>
            <div class="status-badge"
                 style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;">
                <?= $st['label'] ?>
            </div>
        </div>

        <!-- Activity name & shop -->
        <div class="activity-name"><?= htmlspecialchars($bk['activity_name']) ?></div>
        <div class="activity-shop">
            <i class="fas fa-store" style="font-size:12px;"></i>
            <?= htmlspecialchars($bk['shop_name']) ?>
            <?php if ($bk['district']): ?>
            &nbsp;·&nbsp; <i class="fas fa-map-marker-alt" style="font-size:12px;"></i>
            <?= htmlspecialchars($bk['district']) ?>
            <?php endif; ?>
        </div>

        <hr class="divider">

        <!-- Details -->
        <div class="info-section">
            <div class="info-label">รายละเอียดการจอง</div>

            <div class="info-row">
                <span class="key"><i class="fas fa-calendar-alt" style="width:16px;"></i> วันที่จอง</span>
                <span class="val"><?= fmt_date($bk['booking_date']) ?></span>
            </div>

            <?php if ($bk['travel_date']): ?>
            <div class="info-row">
                <span class="key"><i class="fas fa-hiking" style="width:16px;"></i> วันที่เดินทาง</span>
                <span class="val"><?= fmt_date($bk['travel_date']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($bk['duration_label']): ?>
            <div class="info-row">
                <span class="key"><i class="fas fa-clock" style="width:16px;"></i> ระยะเวลา</span>
                <span class="val"><?= htmlspecialchars($bk['duration_label']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Pricing -->
        <div class="info-section">
            <div class="info-label">รายการค่าใช้จ่าย</div>

            <?php if ($bk['adult_quantity'] > 0): ?>
            <div class="info-row">
                <span class="key">ผู้ใหญ่ × <?= $bk['adult_quantity'] ?></span>
                <span class="val">฿<?= number_format((float)$bk['booked_adult_price'] * $bk['adult_quantity'], 2) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($bk['kid_quantity'] > 0): ?>
            <div class="info-row">
                <span class="key">เด็ก × <?= $bk['kid_quantity'] ?></span>
                <span class="val">฿<?= number_format((float)$bk['booked_kid_price'] * $bk['kid_quantity'], 2) ?></span>
            </div>
            <?php endif; ?>

            <?php if ((float)$bk['discount_amount'] > 0): ?>
            <div class="info-row">
                <span class="key">ราคาเดิม</span>
                <span class="val">฿<?= number_format((float)($bk['original_price'] ?? 0), 2) ?></span>
            </div>
            <div class="info-row" style="color:#c2410c;">
                <span class="key">
                    ส่วนลด<?= $bk['promotion_title'] ? ' · '.htmlspecialchars($bk['promotion_title']) : '' ?>
                </span>
                <span class="val">-฿<?= number_format((float)$bk['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>

            <div class="total-row">
                <span class="key"><?= (float)$bk['discount_amount'] > 0 ? 'ราคาสุทธิ' : 'ยอดชำระทั้งหมด' ?></span>
                <span class="val">฿<?= number_format((float)$bk['total_price'], 2) ?></span>
            </div>
        </div>

        <!-- Payment method -->
        <?php if ($bk['payment_method']): ?>
        <hr class="divider">
        <div class="info-section" style="margin-bottom:0;">
            <div class="info-label">การชำระเงิน</div>
            <div class="info-row">
                <span class="key"><i class="fas fa-credit-card" style="width:16px;"></i> ช่องทาง</span>
                <span class="val"><?= htmlspecialchars($method_text) ?></span>
            </div>
            <?php if ($payment && $payment['payment_date']): ?>
            <div class="info-row">
                <span class="key"><i class="fas fa-check-circle" style="width:16px;color:#15803d;"></i> วันที่ชำระ</span>
                <span class="val"><?= fmt_date($payment['payment_date']) ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Footer actions -->
    <div class="receipt-footer">

        <?php if ($is_completed): ?>
            <?php if ($already_reviewed): ?>
            <!-- รีวิวแล้ว -->
            <div class="reviewed-note">
                <i class="fas fa-star" style="color:#f59e0b;"></i>
                คุณได้รีวิวกิจกรรมนี้แล้ว ขอบคุณที่ให้ความเห็น!
            </div>
            <?php else: ?>
            <!-- ยังไม่รีวิว -->
            <button class="btn btn-review" onclick="openReview()">
                <i class="fas fa-star"></i> เขียนรีวิวกิจกรรมนี้
            </button>
            <?php endif; ?>
        <?php endif; ?>

        <a href="javascript:history.back()" class="btn btn-close">
            <i class="fas fa-times"></i> ปิด
        </a>

    </div>
</div>

<!-- ── Write Review Modal ─────────────────────────────────────────────────── -->
<div class="review-overlay" id="reviewOverlay">
    <div class="review-modal">
        <h3>⭐ เขียนรีวิวกิจกรรม</h3>
        <div style="font-size:13px;color:#6b7280;margin-bottom:14px;">
            <?= htmlspecialchars($bk['activity_name']) ?>
        </div>

        <!-- Star rating -->
        <div class="star-row" id="starRow">
            <i class="fas fa-star" data-val="1"></i>
            <i class="fas fa-star" data-val="2"></i>
            <i class="fas fa-star" data-val="3"></i>
            <i class="fas fa-star" data-val="4"></i>
            <i class="fas fa-star" data-val="5"></i>
        </div>

        <textarea class="review-textarea" id="reviewComment"
                  placeholder="เล่าประสบการณ์ของคุณ..."></textarea>

        <div class="review-actions">
            <button class="btn-cancel-review" onclick="closeReview()">ยกเลิก</button>
            <button class="btn-submit-review" id="submitReviewBtn" onclick="submitReview()">
                <i class="fas fa-paper-plane"></i> ส่งรีวิว
            </button>
        </div>
    </div>
</div>

<script>
const ACTIVITY_ID = <?= (int)$bk['activity_id'] ?>;
let selectedRating = 0;

// ── Star rating ────────────────────────────────────────────────────────────
const stars = document.querySelectorAll('#starRow i');
stars.forEach(star => {
    star.addEventListener('mouseover', () => highlightStars(+star.dataset.val));
    star.addEventListener('mouseout',  () => highlightStars(selectedRating));
    star.addEventListener('click', () => {
        selectedRating = +star.dataset.val;
        highlightStars(selectedRating);
    });
});

function highlightStars(n) {
    stars.forEach(s => s.classList.toggle('active', +s.dataset.val <= n));
}

// ── Modal open / close ─────────────────────────────────────────────────────
function openReview() {
    document.getElementById('reviewOverlay').classList.add('active');
}
function closeReview() {
    document.getElementById('reviewOverlay').classList.remove('active');
}
document.getElementById('reviewOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeReview();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReview(); });

// ── Submit review ──────────────────────────────────────────────────────────
async function submitReview() {
    if (selectedRating === 0) { alert('กรุณาให้คะแนนดาวก่อน'); return; }
    const comment = document.getElementById('reviewComment').value.trim();
    if (!comment) { alert('กรุณาเขียนรีวิวก่อนส่ง'); return; }

    const btn = document.getElementById('submitReviewBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังส่ง...';

    const fd = new FormData();
    fd.append('activity_id', ACTIVITY_ID);
    fd.append('rating', selectedRating);
    fd.append('comment', comment);

    try {
        const res  = await fetch('/tkn/handlers/review_submit.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
            closeReview();
            // แสดงข้อความขอบคุณแทนปุ่มรีวิว
            document.querySelector('.receipt-footer').innerHTML = `
                <div class="reviewed-note">
                    <i class="fas fa-star" style="color:#f59e0b;"></i>
                    ส่งรีวิวเรียบร้อยแล้ว ขอบคุณที่ให้ความเห็น!
                </div>
                <a href="javascript:history.back()" class="btn btn-close">
                    <i class="fas fa-times"></i> ปิด
                </a>`;
        } else {
            alert('เกิดข้อผิดพลาด: ' + (data.message || 'กรุณาลองใหม่'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> ส่งรีวิว';
        }
    } catch(e) {
        alert('เกิดข้อผิดพลาด กรุณาลองใหม่');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> ส่งรีวิว';
    }
}
</script>
<!-- LINE Floating Button -->
<a href="https://line.me/R/ti/p/@979jehsw" target="_blank" rel="noopener" class="line-fab" title="ติดต่อเราผ่าน LINE">
  <span class="line-fab-icon"><i class="fab fa-line"></i></span>
  <span class="line-fab-label">LINE</span>
</a>
<style>
.line-fab{position:fixed;bottom:28px;right:24px;z-index:9999;display:flex;align-items:center;background:#06C755;color:#fff;text-decoration:none;border-radius:50px;box-shadow:0 4px 18px rgba(6,199,85,.45),0 2px 8px rgba(0,0,0,.15);overflow:hidden;width:56px;height:56px;transition:width .35s cubic-bezier(.4,0,.2,1),box-shadow .2s,transform .2s;animation:line-fab-bounce 2.8s ease-in-out 1.2s 3;}
.line-fab:hover{width:138px;box-shadow:0 8px 28px rgba(6,199,85,.55),0 4px 12px rgba(0,0,0,.18);transform:translateY(-2px);}
.line-fab-icon{flex-shrink:0;width:56px;height:56px;display:flex;align-items:center;justify-content:center;font-size:1.7rem;}
.line-fab-label{white-space:nowrap;font-size:.92rem;font-weight:700;letter-spacing:.04em;opacity:0;max-width:0;overflow:hidden;transition:opacity .2s .1s,max-width .35s cubic-bezier(.4,0,.2,1);padding-right:0;}
.line-fab:hover .line-fab-label{opacity:1;max-width:90px;padding-right:16px;}
@keyframes line-fab-bounce{0%,100%{transform:translateY(0)}40%{transform:translateY(-8px)}60%{transform:translateY(-4px)}}
</style>
</body>
</html>
