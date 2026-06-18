<?php
/**
 * payment_page.php
 * หน้าชำระเงิน — รองรับ QR PromptPay + Mobile Banking
 * เรียกใช้: payment_page.php?booking_id=<int>
 *
 * Dependencies: db.php, payment_process.php, payment_status.php, payment_slip_upload.php
 * Libraries (CDN):
 *   - qrcode.js  (สร้าง QR code ฝั่ง client)
 *   - Twilio SMS  (แจ้งเตือนผ่าน payment_notify.php)
 */

if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(0);
// ป้องกัน browser cache เอาหน้านี้ไว้ (JS state เปลี่ยนทุก session)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
include '../db.php';
include '../config/omise_config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /tkn/login');
    exit;
}

$booking_id = (int)($_GET['booking_id'] ?? 0);
if (!$booking_id) { die('ไม่พบข้อมูลการจอง'); }

// ── ดึง booking + activity ────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT b.*, a.activity_name, a.adult_price, a.kid_price,
           s.shop_name, s.shop_picture,
           u.fullname, u.phonenumber AS phone
    FROM booking b
    JOIN activity a ON b.activity_id = a.activity_id
    JOIN shop s     ON a.shop_id = s.shop_id
    JOIN user u     ON b.user_id = u.user_id
    WHERE b.booking_id = ? AND b.user_id = ?
");
$stmt->bind_param('ii', $booking_id, $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) { die('ไม่พบข้อมูลการจอง หรือคุณไม่มีสิทธิ์เข้าถึง'); }
if ($booking['status'] === 'Paid') {
    header('Location: /tkn/booking-history?paid=1');
    exit;
}

// ── ตรวจสอบ pending payment ที่มีอยู่แล้ว (กรณี user ออกจากหน้าแล้วกลับมา) ────
$ep_stmt = $conn->prepare("
    SELECT payment_id, charge_id, payment_method, status
    FROM payment
    WHERE booking_id = ? AND status = 'Pending'
    ORDER BY payment_id DESC LIMIT 1
");
$ep_stmt->bind_param('i', $booking_id);
$ep_stmt->execute();
$existing_payment = $ep_stmt->get_result()->fetch_assoc();
$ep_stmt->close();

// map payment_method string กลับเป็น method + bank
$restored_method = null;
$restored_bank   = null;
if ($existing_payment) {
    $pm = $existing_payment['payment_method'] ?? '';
    if ($pm === 'qr_promptpay') {
        $restored_method = 'qr';
    } elseif (str_starts_with($pm, 'mobile_banking_')) {
        $restored_method = 'mobile';
        // ดึง bank จาก booking ก่อน (บันทึกไว้ตอน payment_process.php)
        $restored_bank = $booking['bank_name'] ?? null;
        // fallback: parse จาก payment_method string
        if (!$restored_bank) {
            $bank_map = ['scb' => 'SCB', 'kbank' => 'K-Bank', 'krungthai' => 'Krungthai'];
            $suffix = str_replace('mobile_banking_', '', $pm);
            $restored_bank = $bank_map[$suffix] ?? strtoupper($suffix);
        }
    }
}

$amount       = number_format((float)$booking['total_price'], 2);
$amount_raw   = (float)$booking['total_price'];
$activity_img = htmlspecialchars($booking['shop_picture'] ?? '');
$activity_name= htmlspecialchars($booking['activity_name'] ?? '');
$shop_name    = htmlspecialchars($booking['shop_name'] ?? '');
$travel_date  = $booking['travel_date'] ?? $booking['booking_date'] ?? '-';
$adult_qty    = (int)($booking['adult_quantity'] ?? $booking['adult_qty'] ?? 0);
$kid_qty      = (int)($booking['kid_quantity']   ?? $booking['kid_qty']  ?? 0);
$user_phone   = $booking['phone'] ?? '';

// ── PromptPay account (เปลี่ยนเป็นเลขจริง) ────────────────────────────────────
$promptpay_id = '0812345678'; // ← เปลี่ยนเป็นเบอร์/เลขประจำตัวนิติบุคคลจริง
$promptpay_name = 'บริษัท เที่ยวกันนะ จำกัด';
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ชำระเงิน — #<?= $booking_id ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;600&display=swap"
        rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.omise.co/omise.js"></script>

    <style>
    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    :root {
        --green-deep: #1D3718;
        --green-mid: #2C5A22;
        --green-light: #4A8C3A;
        --green-pale: #EAF3E6;
        --green-border: #C8E0C0;
        --gold: #D4A843;
        --gold-pale: #FDF6E3;
        --red-soft: #E53935;
        --gray-1: #F7F7F7;
        --gray-2: #EEEEEE;
        --gray-3: #BDBDBD;
        --gray-4: #757575;
        --text-main: #1A1A1A;
        --text-sub: #555;
        --radius-lg: 16px;
        --radius-md: 10px;
        --shadow-card: 0 2px 20px rgba(0, 0, 0, .08);
        --font: 'Kanit', sans-serif;
        --mono: 'IBM Plex Mono', monospace;
    }

    body {
        font-family: var(--font);
        background: #F0F4EE;
        min-height: 100vh;
        padding: 0;
        color: var(--text-main);
    }

    /* ── Top bar ── */
    .topbar {
        background: var(--green-deep);
        padding: 8px 0;
        position: sticky;
        top: 0;
        z-index: 1000;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .topbar-inner {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 24px;
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .topbar-logo {
        display: flex;
        align-items: center;
        text-decoration: none;
        flex-shrink: 0;
    }

    .topbar-logo img {
        height: 48px;
        width: auto;
        object-fit: contain;
        display: block;
    }

    .topbar-step {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: rgba(255, 255, 255, .55);
        font-family: 'Kanit', sans-serif;
    }

    .topbar-step span {
        white-space: nowrap;
    }

    .topbar-step span.active {
        color: #fff8cb;
        font-weight: 600;
    }

    .topbar-step .step-sep {
        opacity: 0.4;
        font-size: 10px;
    }

    @media (max-width: 600px) {
        .topbar-step span:not(.active):not(.step-sep) {
            display: none;
        }

        .topbar-step .step-sep {
            display: none;
        }

        .topbar-step span.active::before {
            content: "ขั้นตอน: ";
        }
    }

    /* ── Layout ── */
    .page-wrap {
        max-width: 900px;
        margin: 0 auto;
        padding: 28px 16px 60px;
    }

    .pay-grid {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 700px) {
        .pay-grid {
            grid-template-columns: 1fr;
        }
    }

    /* ── Card ── */
    .card {
        background: #fff;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-card);
        overflow: hidden;
    }

    .card-header {
        padding: 18px 24px 14px;
        border-bottom: 1.5px solid var(--gray-2);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .card-header h2 {
        font-size: 16px;
        font-weight: 700;
        color: var(--green-deep);
    }

    .card-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: var(--green-pale);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
    }

    .card-body {
        padding: 20px 24px;
    }

    /* ── Method selector ── */
    .method-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .method-item {
        border: 2px solid var(--gray-2);
        border-radius: var(--radius-md);
        padding: 14px 16px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 14px;
        transition: border-color .2s, background .2s, transform .1s;
        user-select: none;
    }

    .method-item:hover {
        border-color: var(--green-mid);
        background: var(--green-pale);
    }

    .method-item.selected {
        border-color: var(--green-mid);
        background: var(--green-pale);
    }

    .method-item.selected .radio-dot::after {
        opacity: 1;
        transform: scale(1);
    }

    .method-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }

    .method-label {
        flex: 1;
    }

    .method-label strong {
        display: block;
        font-size: 14px;
        font-weight: 600;
    }

    .method-label span {
        font-size: 12px;
        color: var(--gray-4);
    }

    .radio-dot {
        width: 20px;
        height: 20px;
        border: 2px solid var(--gray-3);
        border-radius: 50%;
        flex-shrink: 0;
        position: relative;
        transition: border-color .2s;
    }

    .method-item.selected .radio-dot {
        border-color: var(--green-mid);
    }

    .radio-dot::after {
        content: '';
        position: absolute;
        inset: 3px;
        background: var(--green-mid);
        border-radius: 50%;
        opacity: 0;
        transform: scale(0);
        transition: opacity .2s, transform .2s;
    }

    /* ── Bank selector ── */
    .bank-list {
        margin-top: 14px;
        border: 1.5px solid var(--gray-2);
        border-radius: var(--radius-md);
        overflow: hidden;
        display: none;
    }

    .bank-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        cursor: pointer;
        border-bottom: 1px solid var(--gray-2);
        transition: background .15s;
    }

    .bank-item:last-child {
        border-bottom: none;
    }

    .bank-item:hover {
        background: var(--gray-1);
    }

    .bank-item.selected {
        background: var(--green-pale);
    }

    .bank-item.selected .radio-dot {
        border-color: var(--green-mid);
    }

    .bank-item.selected .radio-dot::after {
        opacity: 1;
        transform: scale(1);
    }

    .bank-logo {
        width: 38px;
        height: 38px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        color: #fff;
        font-size: 16px;
        flex-shrink: 0;
    }

    /* ── QR Panel ── */
    .qr-panel {
        background: var(--green-pale);
        border: 1.5px solid var(--green-border);
        border-radius: var(--radius-lg);
        padding: 20px;
        text-align: center;
        display: none;
    }

    .qr-frame {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 12px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, .12);
        margin: 12px 0;
    }

    .qr-amount {
        font-size: 28px;
        font-weight: 700;
        color: var(--green-deep);
        margin: 8px 0 4px;
    }

    .qr-account {
        font-size: 12px;
        color: var(--gray-4);
    }

    .qr-timer {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff;
        border-radius: 20px;
        padding: 6px 14px;
        font-size: 13px;
        color: var(--red-soft);
        font-weight: 600;
        margin-top: 12px;
        border: 1.5px solid #FFCDD2;
    }

    /* ── Mobile Panel ── */
    .mobile-panel {
        display: none;
        border: 1.5px solid var(--gray-2);
        border-radius: var(--radius-lg);
        overflow: hidden;
    }

    .mobile-bank-header {
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        border-bottom: 1px solid var(--gray-2);
    }

    .mobile-steps {
        padding: 16px 18px;
        font-size: 13px;
        line-height: 1.8;
    }

    .mobile-steps ol {
        padding-left: 18px;
    }

    .mobile-steps li {
        margin-bottom: 6px;
    }

    .mobile-steps .ref-chip {
        display: inline-block;
        background: var(--gray-1);
        border: 1px solid var(--gray-2);
        border-radius: 6px;
        padding: 2px 8px;
        font-family: var(--mono);
        font-size: 12px;
        color: var(--green-deep);
        font-weight: 600;
    }

    .open-app-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin: 4px 18px 16px;
        padding: 12px;
        background: var(--green-mid);
        color: #fff;
        border-radius: var(--radius-md);
        font-weight: 700;
        font-size: 14px;
        text-decoration: none;
        transition: background .2s;
    }

    .open-app-btn:hover {
        background: var(--green-deep);
    }

    /* ── Slip upload ── */
    .slip-section {
        margin-top: 20px;
        border: 2px dashed var(--gray-3);
        border-radius: var(--radius-md);
        padding: 20px;
        text-align: center;
        cursor: pointer;
        transition: border-color .2s, background .2s;
    }

    .slip-section:hover,
    .slip-section.dragover {
        border-color: var(--green-mid);
        background: var(--green-pale);
    }

    .slip-section input[type=file] {
        display: none;
    }

    .slip-preview {
        display: none;
        margin-top: 12px;
        border-radius: 8px;
        overflow: hidden;
        position: relative;
    }

    .slip-preview img {
        width: 100%;
        max-height: 200px;
        object-fit: cover;
        border-radius: 8px;
    }

    .slip-remove {
        position: absolute;
        top: 6px;
        right: 6px;
        background: rgba(0, 0, 0, .5);
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 26px;
        height: 26px;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* ── Order summary card ── */
    .order-img {
        width: 100%;
        height: 140px;
        object-fit: cover;
    }

    .order-details {
        padding: 18px 20px;
    }

    .order-name {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 12px;
    }

    .order-row {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        color: var(--text-sub);
        margin-bottom: 6px;
    }

    .order-divider {
        border: none;
        border-top: 1.5px solid var(--gray-2);
        margin: 12px 0;
    }

    .order-total-row {
        display: flex;
        justify-content: space-between;
        font-size: 17px;
        font-weight: 700;
        color: var(--green-deep);
    }

    .badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        background: #FFF3CD;
        color: #856404;
        border: 1px solid #FFDCA6;
    }

    /* ── Pay button ── */
    .pay-btn {
        width: 100%;
        padding: 15px;
        background: var(--green-mid);
        color: #fff;
        border: none;
        border-radius: var(--radius-md);
        font-family: var(--font);
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        margin-top: 16px;
        transition: background .2s, transform .1s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .pay-btn:hover:not(:disabled) {
        background: var(--green-deep);
    }

    .pay-btn:active:not(:disabled) {
        transform: scale(.98);
    }

    .pay-btn:disabled {
        opacity: .6;
        cursor: default;
    }

    /* ── Status feedback ── */
    .status-box {
        margin-top: 14px;
        padding: 12px 16px;
        border-radius: var(--radius-md);
        font-size: 13px;
        display: none;
    }

    .status-box.pending {
        background: #FFF8E1;
        border: 1px solid #FFE082;
        color: #6D4C00;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .status-box.success {
        background: #E8F5E9;
        border: 1px solid var(--green-border);
        color: var(--green-deep);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .status-box.error {
        background: #FFEBEE;
        border: 1px solid #FFCDD2;
        color: #B71C1C;
    }

    .spinner {
        width: 20px;
        height: 20px;
        border: 2.5px solid rgba(0, 0, 0, .15);
        border-top-color: currentColor;
        border-radius: 50%;
        animation: spin .7s linear infinite;
        flex-shrink: 0;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* ── Success overlay ── */
    .success-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(255, 255, 255, .95);
        z-index: 999;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 16px;
        text-align: center;
        padding: 32px;
    }

    .success-overlay.show {
        display: flex;
    }

    .check-circle {
        width: 80px;
        height: 80px;
        background: var(--green-mid);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
        color: #fff;
        animation: pop .4s cubic-bezier(.36, .07, .19, .97);
    }

    @keyframes pop {
        0% {
            transform: scale(0);
        }

        70% {
            transform: scale(1.15);
        }

        100% {
            transform: scale(1);
        }
    }

    .success-overlay h2 {
        font-size: 24px;
        font-weight: 700;
        color: var(--green-deep);
    }

    .success-overlay p {
        font-size: 14px;
        color: var(--gray-4);
    }

    .go-history {
        padding: 12px 32px;
        background: var(--green-mid);
        color: #fff;
        border: none;
        border-radius: var(--radius-md);
        font-family: var(--font);
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        transition: background .2s;
    }

    .go-history:hover {
        background: var(--green-deep);
    }

    /* ── Section title ── */
    .section-title {
        font-size: 13px;
        font-weight: 600;
        color: var(--gray-4);
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 14px;
    }

    /* ── Tooltip / Info chip ── */
    .info-chip {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        background: var(--gold-pale);
        border: 1px solid #F0D898;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 12px;
        color: #5C4400;
        margin-top: 12px;
        line-height: 1.6;
    }

    /* ── Secure notice ── */
    .secure-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 11px;
        color: var(--gray-3);
        margin-top: 16px;
    }

    /* ── Omise Card Form ── */
    .card-visual {
        background: linear-gradient(135deg, #1D3718 0%, #2C5A22 60%, #4A8C3A 100%);
        border-radius: 16px;
        padding: 22px 24px;
        color: #fff;
        margin-bottom: 20px;
        position: relative;
        overflow: hidden;
        min-height: 130px;
    }

    .card-visual::before {
        content: '';
        position: absolute;
        top: -30px;
        right: -30px;
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, .06);
        border-radius: 50%;
    }

    .card-visual::after {
        content: '';
        position: absolute;
        bottom: -50px;
        right: 30px;
        width: 180px;
        height: 180px;
        background: rgba(255, 255, 255, .04);
        border-radius: 50%;
    }

    .card-visual-chips {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 18px;
    }

    .card-chip {
        width: 36px;
        height: 28px;
        background: linear-gradient(135deg, #D4A843, #F0C060);
        border-radius: 5px;
    }

    .card-brand-display {
        margin-left: auto;
        font-size: 22px;
        font-weight: 900;
        letter-spacing: -1px;
        opacity: 0.9;
    }

    .card-visual-number {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 17px;
        letter-spacing: 3px;
        margin-bottom: 14px;
        opacity: 0.9;
    }

    .card-visual-bottom {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        opacity: 0.75;
    }

    .card-visual-bottom div:last-child {
        font-family: 'IBM Plex Mono', monospace;
        font-size: 13px;
        opacity: 0.9;
    }

    .cf-label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--gray-4);
        margin-bottom: 6px;
        letter-spacing: .03em;
    }

    .cf-input {
        width: 100%;
        padding: 11px 14px;
        border: 1.5px solid var(--gray-2);
        border-radius: var(--radius-md);
        font-family: var(--font);
        font-size: 14px;
        color: var(--text-main);
        background: #fff;
        transition: border-color .2s, box-shadow .2s;
        outline: none;
    }

    .cf-input:focus {
        border-color: var(--green-mid);
        box-shadow: 0 0 0 3px rgba(44, 90, 34, .1);
    }

    .cf-input.error {
        border-color: var(--red-soft);
    }

    .cf-input.success {
        border-color: #4CAF50;
    }

    .cf-row {
        margin-bottom: 14px;
    }

    .cf-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .cf-number-wrap {
        position: relative;
    }

    .cf-number-wrap .cf-input {
        padding-right: 48px;
        font-family: 'IBM Plex Mono', monospace;
        letter-spacing: 1px;
    }

    .cf-brand-badge {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 18px;
        pointer-events: none;
    }

    .card-panel-inner {
        display: none;
    }

    .card-panel-inner.active {
        display: block;
    }

    .omise-badge {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 11px;
        color: var(--gray-4);
        margin-top: 14px;
    }

    .omise-badge img {
        height: 18px;
        opacity: .7;
    }
    </style>
</head>

<body>

    <!-- ── Top bar ─────────────────────────────────────────────────────────────── -->
    <div class="topbar">
        <div class="topbar-inner">
            <a href="/tkn/home" class="topbar-logo">
                <img src="/tkn/assets/image/logo.png" alt="เที่ยวกันนะ">
            </a>
            <div class="topbar-step">
                <span>เลือกกิจกรรม</span>
                <span class="step-sep">›</span>
                <span>จอง</span>
                <span class="step-sep">›</span>
                <span class="active">ชำระเงิน</span>
                <span class="step-sep">›</span>
                <span>เสร็จสิ้น</span>
            </div>
        </div>
    </div>

    <!-- ── Page ────────────────────────────────────────────────────────────────── -->
    <div class="page-wrap">
        <h1 style="font-size:22px;font-weight:700;color:var(--green-deep);margin-bottom:20px;">
            ชำระเงิน
        </h1>

        <!-- ── Banner แจ้งเตือนเมื่อมีข้อมูลการชำระค้างอยู่ ── -->
        <?php if ($existing_payment && $restored_method): ?>
        <div id="restoreBanner" style="
    display:flex; align-items:center; gap:12px;
    background:#FFF8E1; border:1.5px solid #FFE082; border-radius:var(--radius-md);
    padding:14px 18px; margin-bottom:18px; font-size:13px; color:#5C4400;
  ">
            <span style="font-size:22px;">🔄</span>
            <div>
                <div style="font-weight:700; margin-bottom:2px;">พบข้อมูลการชำระเงินที่ยังค้างอยู่</div>
                <div>เราได้กู้คืนการชำระเงินก่อนหน้าให้แล้ว — แนบสลิปแล้วกด <strong>ส่งสลิปยืนยัน</strong> ได้เลย</div>
            </div>
            <button onclick="document.getElementById('restoreBanner').style.display='none'"
                style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;color:#999;flex-shrink:0;">✕</button>
        </div>
        <?php endif; ?>

        <div class="pay-grid">

            <!-- ═══ LEFT: Payment method ════════════════════════════════════════════ -->
            <div>

                <!-- Method card -->
                <div class="card" style="margin-bottom:20px;">
                    <div class="card-header">
                        <div class="card-icon">💳</div>
                        <h2>เลือกช่องทางชำระเงิน</h2>
                    </div>
                    <div class="card-body">
                        <div class="method-list">

                            <!-- QR PromptPay -->
                            <div class="method-item" id="method_qr" onclick="selectMethod('qr')">
                                <div class="method-icon" style="background:#EDE7F6;">📱</div>
                                <div class="method-label">
                                    <strong>QR พร้อมเพย์</strong>
                                    <span>สแกนจ่ายจากทุกแอปธนาคาร · รวดเร็วภายใน 5 วิ</span>
                                </div>
                                <div class="radio-dot"></div>
                            </div>

                            <!-- Mobile Banking -->
                            <div class="method-item" id="method_mobile" onclick="selectMethod('mobile')">
                                <div class="method-icon" style="background:#E3F2FD;">🏦</div>
                                <div class="method-label">
                                    <strong>Mobile Banking</strong>
                                    <span>SCB Easy, KPlus, Krungthai NEXT</span>
                                </div>
                                <div class="radio-dot"></div>
                            </div>

                            <!-- Credit/Debit Card (Omise) -->
                            <div class="method-item" id="method_card" onclick="selectMethod('card')">
                                <div class="method-icon" style="background:#FFF8E7;">💳</div>
                                <div class="method-label">
                                    <strong>บัตรเครดิต / เดบิต</strong>
                                    <span>Visa, Mastercard · ปลอดภัยด้วย Omise</span>
                                </div>
                                <div class="radio-dot"></div>
                            </div>

                        </div>

                        <!-- Bank selector (mobile only) -->
                        <div class="bank-list" id="bankList">
                            <?php
            $banks = [
                'SCB'       => ['#4E2E7F','SCB Easy',        'ธนาคารไทยพาณิชย์',   'scbeasy://payment?ref='],
                'K-Bank'    => ['#007B40','KPlus',            'ธนาคารกสิกรไทย',     'kplusdeeplink://payment?ref='],
                'Krungthai' => ['#1AB2E8','Krungthai NEXT',  'ธนาคารกรุงไทย',      'krungthai://payment?ref='],
            ];
            foreach ($banks as $key => [$color, $app, $fullname, $link]): ?>
                            <div class="bank-item" id="bank_<?= $key ?>" onclick="selectBank('<?= $key ?>')">
                                <div class="bank-logo" style="background:<?= $color ?>;">
                                    <?= substr($key, 0, 1) ?>
                                </div>
                                <div style="flex:1;">
                                    <div style="font-size:13px;font-weight:600;"><?= $fullname ?></div>
                                    <div style="font-size:11px;color:var(--gray-4);"><?= $app ?></div>
                                </div>
                                <div class="radio-dot"></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>

                <!-- ── Payment UI panel (แสดงหลัง initPayment) ── -->
                <div id="paymentUI" style="display:none;">

                    <!-- QR Panel -->
                    <div class="qr-panel card" id="qrPanel">
                        <div class="card-header">
                            <div class="card-icon">📱</div>
                            <h2>สแกน QR เพื่อชำระ</h2>
                        </div>
                        <div class="card-body" style="text-align:center;">
                            <div style="font-size:13px;color:var(--gray-4);margin-bottom:8px;">
                                ใช้แอปธนาคารสแกน QR Code ด้านล่าง
                            </div>
                            <div class="qr-frame" id="qrContainer">
                                <!-- QR inject here -->
                                <div id="qrCode"></div>
                            </div>
                            <div class="qr-amount">฿<span id="qrAmountText"><?= $amount ?></span></div>
                            <div class="qr-account">
                                พร้อมเพย์ <?= $promptpay_id ?> · <?= $promptpay_name ?>
                            </div>
                            <div class="qr-timer">
                                ⏱ หมดอายุใน <span id="qrTimerDisplay">05:00</span>
                            </div>
                            <div style="margin-top:10px;font-size:11px;color:var(--gray-3);font-family:var(--mono);">
                                Ref: <span id="qrRefDisplay">—</span>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile Banking Panel -->
                    <div class="mobile-panel card" id="mobilePanel" style="display:none;">
                        <div class="mobile-bank-header" id="mobileBankHeader">
                            <div class="bank-logo" id="mobileBankLogo" style="width:44px;height:44px;font-size:18px;">B
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:14px;" id="mobileBankName">—</div>
                                <div style="font-size:11px;color:var(--gray-4);">Mobile Banking</div>
                            </div>
                        </div>
                        <div class="mobile-steps">
                            <ol id="mobileBankSteps"></ol>
                        </div>
                        <a class="open-app-btn" id="openAppBtn" href="#" target="_blank">
                            📱 เปิดแอปธนาคาร
                        </a>
                    </div>

                    <!-- Credit Card Panel (Omise) -->
                    <div class="card card-panel-inner" id="cardPanel">
                        <div class="card-header">
                            <div class="card-icon">💳</div>
                            <h2>ข้อมูลบัตรเครดิต / เดบิต</h2>
                        </div>
                        <div class="card-body">
                            <!-- Card visual preview -->
                            <div class="card-visual">
                                <div class="card-visual-chips">
                                    <div class="card-chip"></div>
                                    <div class="card-brand-display" id="cvBrand">••••</div>
                                </div>
                                <div class="card-visual-number" id="cvNumber">•••• •••• •••• ••••</div>
                                <div class="card-visual-bottom">
                                    <div>
                                        <div style="font-size:9px;opacity:.6;margin-bottom:2px;">ชื่อบนบัตร</div>
                                        <div id="cvName">YOUR NAME</div>
                                    </div>
                                    <div>
                                        <div style="font-size:9px;opacity:.6;margin-bottom:2px;">วันหมดอายุ</div>
                                        <div id="cvExpiry">MM/YY</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card form fields -->
                            <div class="cf-row">
                                <label class="cf-label" for="cardNumber">หมายเลขบัตร</label>
                                <div class="cf-number-wrap">
                                    <input class="cf-input" type="text" id="cardNumber" inputmode="numeric"
                                        maxlength="19" placeholder="0000 0000 0000 0000" autocomplete="cc-number">
                                    <span class="cf-brand-badge" id="cfBrandBadge">💳</span>
                                </div>
                            </div>
                            <div class="cf-row">
                                <label class="cf-label" for="cardName">ชื่อบนบัตร</label>
                                <input class="cf-input" type="text" id="cardName"
                                    placeholder="ชื่อ-นามสกุล (ภาษาอังกฤษ)" autocomplete="cc-name">
                            </div>
                            <div class="cf-grid">
                                <div class="cf-row">
                                    <label class="cf-label" for="cardExpiry">วันหมดอายุ</label>
                                    <input class="cf-input" type="text" id="cardExpiry" inputmode="numeric"
                                        maxlength="5" placeholder="MM/YY" autocomplete="cc-exp">
                                </div>
                                <div class="cf-row">
                                    <label class="cf-label" for="cardCvv">CVV</label>
                                    <input class="cf-input" type="password" id="cardCvv" inputmode="numeric"
                                        maxlength="4" placeholder="•••" autocomplete="cc-csc">
                                </div>
                            </div>

                            <!-- Test card hint (sandbox) -->
                            <div class="info-chip">
                                🧪 <div><strong>Sandbox:</strong> ใช้บัตร <strong>4242 4242 4242 4242</strong> ·
                                    วันหมดอายุ 12/30 · CVV 123 สำหรับทดสอบ</div>
                            </div>

                            <div class="omise-badge">
                                🔒 ชำระเงินผ่าน <strong style="color:#1D3718;margin-left:2px;">Omise</strong> ·
                                ข้อมูลบัตรถูกเข้ารหัสด้วย TLS
                            </div>
                        </div>
                    </div>

                    <!-- ── Slip upload ───────────────────────────────────────────────── -->
                    <div class="card" style="margin-top:20px;">
                        <div class="card-header">
                            <div class="card-icon">🧾</div>
                            <h2>แนบสลิปการโอนเงิน</h2>
                        </div>
                        <div class="card-body">
                            <div class="slip-section" id="slipDropzone"
                                onclick="document.getElementById('slipInput').click()">
                                <input type="file" id="slipInput" accept="image/*"
                                    onchange="handleSlipFile(this.files[0])">
                                <div id="slipPlaceholder">
                                    <div style="font-size:36px;margin-bottom:8px;">🖼️</div>
                                    <div style="font-size:14px;font-weight:600;color:var(--green-mid);">
                                        คลิกหรือลากไฟล์มาวาง</div>
                                    <div style="font-size:12px;color:var(--gray-4);margin-top:4px;">JPG, PNG ·
                                        ขนาดไม่เกิน 5 MB</div>
                                </div>
                                <div class="slip-preview" id="slipPreview">
                                    <img id="slipPreviewImg" src="" alt="สลิป">
                                    <button class="slip-remove" onclick="removeSlip(event)">✕</button>
                                </div>
                            </div>
                            <div class="info-chip">
                                ℹ️ หลังแนบสลิป กดปุ่มด้านล่างเพื่อส่งให้ทีมงานตรวจสอบ
                            </div>
                        </div>
                    </div>

                    <!-- ปุ่มส่งสลิป (แสดงหลังจาก QR/Mobile panel โหลด) -->
                    <button class="pay-btn" id="submitSlipBtn" style="display:none;margin-top:16px;"
                        onclick="submitSlipAndRedirect()">
                        📤 ส่งสลิปยืนยันการชำระ
                    </button>

                </div>

                <!-- ── Confirm button ─────────────────────────────────────────────── -->
                <button class="pay-btn" id="payBtn" onclick="handlePay()">
                    <span>✓</span> ยืนยันและดำเนินการชำระเงิน
                </button>

                <!-- Status messages -->
                <div class="status-box" id="statusPending" style="display:none;">
                    <div class="spinner"></div>
                    <div>
                        <div style="font-weight:600;">กำลังรอการยืนยันการชำระเงิน...</div>
                        <div id="statusSubtext" style="font-size:12px;margin-top:2px;">
                            ระบบจะยืนยันอัตโนมัติหลังได้รับสลิป</div>
                    </div>
                </div>
                <div class="status-box error" id="statusError"></div>

                <!-- Secure notice -->
                <div class="secure-row">
                    🔒 การชำระเงินของคุณปลอดภัยด้วย SSL
                </div>

            </div>

            <!-- ═══ RIGHT: Order summary ══════════════════════════════════════════ -->
            <div>
                <div class="card">
                    <?php if ($activity_img): ?>
                    <img class="order-img" src="<?= $activity_img ?>" alt="<?= $activity_name ?>"
                        onerror="this.style.display='none'">
                    <?php endif; ?>
                    <div class="order-details">
                        <div class="order-name"><?= $activity_name ?></div>
                        <div style="font-size:12px;color:var(--gray-4);margin-bottom:14px;"><?= $shop_name ?></div>

                        <div class="order-row">
                            <span>📅 วันที่เดินทาง</span>
                            <span><?= htmlspecialchars($travel_date) ?></span>
                        </div>
                        <?php if ($adult_qty > 0): ?>
                        <div class="order-row">
                            <span>👤 ผู้ใหญ่ × <?= $adult_qty ?></span>
                            <span>฿<?= number_format(($booking['adult_price'] ?? 0) * $adult_qty, 2) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($kid_qty > 0): ?>
                        <div class="order-row">
                            <span>👶 เด็ก × <?= $kid_qty ?></span>
                            <span>฿<?= number_format(($booking['kid_price'] ?? 0) * $kid_qty, 2) ?></span>
                        </div>
                        <?php endif; ?>

                        <hr class="order-divider">
                        <div class="order-total-row">
                            <span>ยอดชำระทั้งหมด</span>
                            <span>฿<?= $amount ?></span>
                        </div>

                        <div style="margin-top:12px;">
                            <span class="badge">⏳ รอชำระเงิน</span>
                            <span style="font-size:11px;color:var(--gray-4);margin-left:8px;">
                                #<?= $booking_id ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Help card -->
                <div
                    style="margin-top:16px;padding:16px;background:#fff;border-radius:var(--radius-lg);font-size:12px;color:var(--gray-4);box-shadow:var(--shadow-card);">
                    <div style="font-weight:700;color:var(--text-main);margin-bottom:8px;">ต้องการความช่วยเหลือ?</div>
                    <div style="margin-bottom:6px;">📞 02-xxx-xxxx (จ–ศ 9:00–18:00)</div>
                    <div>✉️ support@teawkanna.com</div>
                </div>

            </div>

        </div>
    </div>

    <!-- ── Success overlay ──────────────────────────────────────────────────────── -->
    <div class="success-overlay" id="successOverlay">
        <div class="check-circle">✓</div>
        <h2>ชำระเงินสำเร็จ!</h2>
        <p>การจองหมายเลข #<?= $booking_id ?> ได้รับการยืนยันแล้ว<br>
            ระบบได้ส่ง SMS แจ้งยืนยันไปที่ <?= htmlspecialchars($user_phone) ?></p>
        <p style="font-size:12px;">ขอบคุณที่เลือกใช้บริการ เที่ยวกันนะ 🌿</p>
        <a class="go-history" href="/tkn/home">กลับหน้าหลัก</a>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════════
     JavaScript
════════════════════════════════════════════════════════════════════════════ -->
    <script>
    // ── Config ─────────────────────────────────────────────────────────────────
    const BOOKING_ID = <?= $booking_id ?>;
    const AMOUNT = <?= $amount_raw ?>;
    const USER_PHONE = <?= json_encode($user_phone) ?>;
    const PROMPTPAY_ID = <?= json_encode($promptpay_id) ?>;
    const OMISE_PKEY = <?= json_encode(OMISE_PUBLIC_KEY) ?>;

    // ── Existing payment (restore state เมื่อ user กลับมาที่หน้านี้) ─────────────
    const EXISTING_PAYMENT = <?= $existing_payment
    ? json_encode([
        'payment_id' => (int)$existing_payment['payment_id'],
        'charge_id'  => $existing_payment['charge_id'],
        'method'     => $restored_method,
        'bank'       => $restored_bank,
    ])
    : 'null' ?>;

    // ── Init Omise.js ────────────────────────────────────────────────────────────
    if (typeof Omise !== 'undefined') {
        Omise.setPublicKey(OMISE_PKEY);
    }

    const BANK_UI = {
        SCB: {
            color: '#4E2E7F',
            app: 'SCB Easy',
            name: 'ธนาคารไทยพาณิชย์',
            deeplink: 'scbeasy://payment?ref='
        },
        'K-Bank': {
            color: '#007B40',
            app: 'KPlus',
            name: 'ธนาคารกสิกรไทย',
            deeplink: 'kplusdeeplink://payment?ref='
        },
        Krungthai: {
            color: '#1AB2E8',
            app: 'Krungthai NEXT',
            name: 'ธนาคารกรุงไทย',
            deeplink: 'krungthai://payment?ref='
        },
    };

    let selectedMethod = null;
    let selectedBank = null;
    let slipFile = null;
    let paymentId = null;
    let chargeId = null;
    let pollingTimer = null;
    let qrTimer = null;
    let qrSecondsLeft = 300;
    let initiated = false;

    // ── Method / Bank select ───────────────────────────────────────────────────
    function selectMethod(m) {
        selectedMethod = m;
        ['qr', 'mobile', 'card'].forEach(id => {
            document.getElementById('method_' + id)?.classList.toggle('selected', id === m);
        });
        document.getElementById('bankList').style.display = m === 'mobile' ? 'block' : 'none';
        // card panel
        document.getElementById('cardPanel').classList.toggle('active', m === 'card');
        // slip section — ซ่อนเมื่อชำระด้วยบัตร (ไม่ต้องแนบสลิป)
        const slipCard = document.getElementById('slipDropzone')?.closest('.card');
        if (slipCard) slipCard.style.display = m === 'card' ? 'none' : 'block';
        if (m === 'qr' || m === 'card') {
            selectedBank = null;
            clearBankSelection();
        }
    }

    function selectBank(b) {
        selectedBank = b;
        document.querySelectorAll('.bank-item').forEach(el => el.classList.remove('selected'));
        document.getElementById('bank_' + b).classList.add('selected');
    }

    function clearBankSelection() {
        document.querySelectorAll('.bank-item').forEach(el => el.classList.remove('selected'));
    }

    // ── Slip file handling ─────────────────────────────────────────────────────
    function handleSlipFile(file) {
        if (!file) return;
        if (file.size > 5 * 1024 * 1024) {
            showError('ไฟล์ใหญ่เกิน 5 MB กรุณาเลือกไฟล์ใหม่');
            return;
        }
        slipFile = file;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('slipPreviewImg').src = e.target.result;
            document.getElementById('slipPreview').style.display = 'block';
            document.getElementById('slipPlaceholder').style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    function removeSlip(e) {
        e.stopPropagation();
        slipFile = null;
        document.getElementById('slipInput').value = '';
        document.getElementById('slipPreview').style.display = 'none';
        document.getElementById('slipPlaceholder').style.display = 'block';
    }

    // ── Drag & drop ────────────────────────────────────────────────────────────
    const dropzone = document.getElementById('slipDropzone');
    dropzone.addEventListener('dragover', e => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (e.dataTransfer.files[0]) handleSlipFile(e.dataTransfer.files[0]);
    });

    // ── Handle Pay button ──────────────────────────────────────────────────────
    async function handlePay() {
        if (!selectedMethod) {
            showError('กรุณาเลือกช่องทางการชำระเงิน');
            return;
        }
        if (selectedMethod === 'mobile' && !selectedBank) {
            showError('กรุณาเลือกธนาคาร');
            return;
        }
        if (selectedMethod === 'card') {
            handleCardPay();
            return;
        }

        const btn = document.getElementById('payBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner" style="border-top-color:#fff;"></div> กำลังดำเนินการ...';
        hideError();

        try {
            const fd = new FormData();
            fd.append('booking_id', BOOKING_ID);
            fd.append('method', selectedMethod);
            if (selectedMethod === 'mobile') fd.append('bank_name', selectedBank);

            const resp = await fetch('/tkn/handlers/payment_process.php', {
                method: 'POST',
                body: fd
            });
            const data = await resp.json();
            if (data.status !== 'success') throw new Error(data.message || 'ชำระเงินล้มเหลว');

            paymentId = data.payment_id;
            chargeId = data.charge_id;

            document.getElementById('paymentUI').style.display = 'block';
            document.getElementById('payBtn').style.display = 'none';
            document.getElementById('submitSlipBtn').style.display = 'block';
            initiated = true;

            if (selectedMethod === 'qr') {
                showQRPanel(data);
                startPolling(data.poll_url || ('payment_status.php?payment_id=' + paymentId));
            } else {
                showMobilePanel(data);
            }

        } catch (err) {
            showError(err.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
            const btn2 = document.getElementById('payBtn');
            btn2.disabled = false;
            btn2.innerHTML = '<span>✓</span> ยืนยันและดำเนินการชำระเงิน';
        }
    }

    // ── Card payment via Omise ─────────────────────────────────────────────────
    function handleCardPay() {
        const number = document.getElementById('cardNumber').value.replace(/\s/g, '');
        const name = document.getElementById('cardName').value.trim();
        const expiry = document.getElementById('cardExpiry').value;
        const cvv = document.getElementById('cardCvv').value.trim();

        if (!number || number.length < 15) {
            showError('กรุณากรอกหมายเลขบัตรให้ครบถ้วน');
            return;
        }
        if (!name) {
            showError('กรุณากรอกชื่อบนบัตร');
            return;
        }
        if (!expiry || !expiry.includes('/')) {
            showError('กรุณากรอกวันหมดอายุ (MM/YY)');
            return;
        }
        if (!cvv || cvv.length < 3) {
            showError('กรุณากรอก CVV');
            return;
        }

        const [expM, expY] = expiry.split('/');
        const fullYear = parseInt(expY) < 100 ? 2000 + parseInt(expY) : parseInt(expY);

        const btn = document.getElementById('payBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="spinner" style="border-top-color:#fff;"></div> กำลังเข้ารหัสบัตร...';
        hideError();

        if (typeof Omise === 'undefined') {
            showError('ไม่สามารถโหลด Omise.js กรุณา refresh หน้า');
            resetPayBtn();
            return;
        }

        Omise.setPublicKey(OMISE_PKEY);
        Omise.createToken('card', {
            name: name,
            number: number,
            expiration_month: parseInt(expM),
            expiration_year: fullYear,
            security_code: cvv,
        }, async function(statusCode, response) {
            if (statusCode !== 200) {
                const errMsg = response.message || 'ข้อมูลบัตรไม่ถูกต้อง';
                showError('❌ ' + errMsg);
                resetPayBtn();
                return;
            }

            const token = response.id;
            btn.innerHTML = '<div class="spinner" style="border-top-color:#fff;"></div> กำลังชำระเงิน...';

            try {
                const fd = new FormData();
                fd.append('booking_id', BOOKING_ID);
                fd.append('method', 'card');
                fd.append('token', token);

                const resp = await fetch('/tkn/handlers/omise_charge.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await resp.json();

                if (data.status !== 'success') throw new Error(data.message || 'ชำระเงินล้มเหลว');

                paymentId = data.payment_id;
                chargeId = data.charge_id;

                if (data.paid) {
                    // ชำระสำเร็จทันที
                    clearInterval(pollingTimer);
                    showSuccess();
                } else if (data.charge_status === 'failed') {
                    throw new Error(data.failure_message || 'ธนาคารปฏิเสธการชำระเงิน');
                } else {
                    // pending — poll
                    document.getElementById('paymentUI').style.display = 'block';
                    document.getElementById('payBtn').style.display = 'none';
                    document.getElementById('submitSlipBtn').style.display = 'none';
                    startPolling('../pages/payment_status.php?payment_id=' + paymentId);
                }
            } catch (err) {
                showError(err.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่');
                resetPayBtn();
            }
        });
    }

    function resetPayBtn() {
        const btn = document.getElementById('payBtn');
        btn.disabled = false;
        btn.innerHTML = '<span>✓</span> ยืนยันและดำเนินการชำระเงิน';
    }

    // ── QR Panel ───────────────────────────────────────────────────────────────
    function showQRPanel(data) {
        document.getElementById('qrPanel').style.display = 'block';
        document.getElementById('mobilePanel').style.display = 'none';
        document.getElementById('qrRefDisplay').textContent = data.charge_id;

        // สร้าง QR Code จาก PromptPay payload
        const qrEl = document.getElementById('qrCode');
        qrEl.innerHTML = '';
        new QRCode(qrEl, {
            text: buildPromptPayPayload(PROMPTPAY_ID, AMOUNT),
            width: 200,
            height: 200,
            colorDark: '#1D3718',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M,
        });

        startQRTimer(data.qr_expires_in || 300);
    }

    // ── PromptPay EMV payload builder ─────────────────────────────────────────
    // Standard PromptPay QR (EMVCo format สำหรับ Mobile Number)
    function buildPromptPayPayload(mobileNumber, amount) {
        // ลบ +66 prefix ถ้ามี, normalize เป็น 0XXXXXXXXX
        let phone = mobileNumber.replace(/\D/g, '');
        if (phone.startsWith('66')) phone = '0' + phone.slice(2);
        // Pad เป็น 13 หลัก (format PromptPay: 00 + 66 + phone[1:])
        const accountId = '0066' + phone.slice(1);
        const aid = tlv('00', '12') + tlv('01', accountId);
        const merchantInfo = tlv('29', aid);
        const amtStr = amount.toFixed(2);
        const amtField = tlv('54', amtStr);

        let payload =
            tlv('00', '01') + // Payload format
            merchantInfo + // Merchant info
            tlv('53', '764') + // Currency (THB)
            amtField + // Amount
            tlv('58', 'TH'); // Country

        payload += tlv('63', crc16(payload + '6304'));
        return payload;
    }

    function tlv(tag, value) {
        const len = String(value.length).padStart(2, '0');
        return tag + len + value;
    }

    function crc16(data) {
        let crc = 0xFFFF;
        for (let i = 0; i < data.length; i++) {
            crc ^= data.charCodeAt(i) << 8;
            for (let j = 0; j < 8; j++) {
                crc = (crc & 0x8000) ? ((crc << 1) ^ 0x1021) : (crc << 1);
            }
        }
        return ((crc & 0xFFFF) >>> 0).toString(16).toUpperCase().padStart(4, '0');
    }

    // ── QR Timer ───────────────────────────────────────────────────────────────
    function startQRTimer(seconds) {
        qrSecondsLeft = seconds;
        clearInterval(qrTimer);
        updateQRTimerDisplay();
        qrTimer = setInterval(() => {
            qrSecondsLeft--;
            updateQRTimerDisplay();
            if (qrSecondsLeft <= 0) {
                clearInterval(qrTimer);
                document.getElementById('qrTimerDisplay').textContent = 'หมดอายุ';
                document.getElementById('qrTimerDisplay').style.color = '#B71C1C';
                // refresh QR
                document.getElementById('qrCode').innerHTML = '';
                const note = document.createElement('div');
                note.style.cssText = 'padding:20px;font-size:13px;color:#B71C1C;';
                note.innerHTML =
                    'QR หมดอายุแล้ว<br><button onclick="location.reload()" style="margin-top:8px;padding:6px 16px;background:#2C5A22;color:#fff;border:none;border-radius:6px;cursor:pointer;">รีเฟรช</button>';
                document.getElementById('qrCode').appendChild(note);
            }
        }, 1000);
    }

    function updateQRTimerDisplay() {
        const m = String(Math.floor(qrSecondsLeft / 60)).padStart(2, '0');
        const s = String(qrSecondsLeft % 60).padStart(2, '0');
        const el = document.getElementById('qrTimerDisplay');
        if (el) el.textContent = m + ':' + s;
        if (el && qrSecondsLeft <= 60) el.style.color = '#B71C1C';
    }

    // ── Mobile Banking Panel ───────────────────────────────────────────────────
    function showMobilePanel(data) {
        document.getElementById('mobilePanel').style.display = 'block';
        document.getElementById('qrPanel').style.display = 'none';

        const bui = BANK_UI[selectedBank] || {};
        const logoEl = document.getElementById('mobileBankLogo');
        logoEl.style.background = bui.color || '#555';
        logoEl.textContent = (selectedBank || 'B').charAt(0);
        document.getElementById('mobileBankName').textContent = (bui.name || selectedBank) + ' · ' + (bui.app || '');

        const steps = [
            'เปิดแอป <strong>' + (bui.app || selectedBank) + '</strong>',
            'เลือกเมนู <strong>โอนเงิน / ชำระเงิน</strong>',
            'กรอกจำนวน <strong>฿' + AMOUNT.toLocaleString('th-TH', {
                minimumFractionDigits: 2
            }) + '</strong>',
            'ใส่รหัสอ้างอิง <span class="ref-chip">' + data.charge_id + '</span>',
            'ยืนยันและชำระเงิน แล้วกลับมาแนบสลิป',
        ];
        document.getElementById('mobileBankSteps').innerHTML =
            steps.map(s => '<li>' + s + '</li>').join('');

        const deeplink = (bui.deeplink || '#') + data.charge_id;
        document.getElementById('openAppBtn').href = deeplink;
    }

    // ── Slip upload (manual confirm) ───────────────────────────────────────────
    async function uploadSlip() {
        if (!slipFile || !paymentId) return {
            ok: false,
            msg: 'ไม่พบไฟล์หรือ payment ID'
        };
        const fd = new FormData();
        fd.append('payment_id', paymentId);
        fd.append('slip', slipFile);
        try {
            const resp = await fetch('/tkn/handlers/payment_slip_upload.php', {
                method: 'POST',
                body: fd
            });
            let data;
            try {
                data = await resp.json();
            } catch (e) {
                const text = await resp.text().catch(() => '');
                return {
                    ok: false,
                    msg: 'Server ตอบกลับไม่ถูกต้อง: ' + text.slice(0, 100)
                };
            }
            return {
                ok: data.status === 'success',
                msg: data.message || ''
            };
        } catch (e) {
            return {
                ok: false,
                msg: 'เชื่อมต่อ server ไม่ได้: ' + e.message
            };
        }
    }

    // ── Polling ────────────────────────────────────────────────────────────────
    function startPolling(pollUrl) {
        // ไม่ auto-upload slip แล้ว — ใช้ปุ่ม "ส่งสลิปยืนยัน" แทน
        let count = 0;
        pollingTimer = setInterval(async () => {
            count++;
            try {
                const resp = await fetch(pollUrl);
                const d = await resp.json();
                if (d.payment_status === 'Approved' || d.payment_status === 'Paid' || d.booking_status ===
                    'Paid') {
                    clearInterval(pollingTimer);
                    clearInterval(qrTimer);
                    showSuccess();
                }
            } catch (e) {}
            if (count > 120) {
                clearInterval(pollingTimer);
            }
        }, 3000);
    }

    // ── Submit slip → redirect (ปุ่มส่งสลิปยืนยัน) ──────────────────────────────
    async function submitSlipAndRedirect() {
        if (!slipFile) {
            showError('กรุณาแนบสลิปการโอนเงินก่อน');
            return;
        }
        if (!paymentId) {
            showError('ยังไม่ได้เลือกช่องทางชำระเงิน กรุณากดปุ่ม "ยืนยันและดำเนินการ" ก่อน');
            return;
        }

        // หยุด polling ทันทีที่กดส่งสลิป ทั้ง QR และ Mobile
        clearInterval(pollingTimer);
        clearInterval(qrTimer);

        const btn = document.getElementById('submitSlipBtn');
        btn.disabled = true;
        btn.innerHTML =
            '<div class="spinner" style="border-top-color:#fff;width:16px;height:16px;border-width:2.5px;display:inline-block;"></div> กำลังส่ง...';

        const result = await uploadSlip();
        if (result.ok) {
            // redirect ทันที — สถานะในหน้าการจองจะขึ้น "รอการตรวจสอบ"
            window.location.href = '/tkn/home';
        } else {
            showError('ส่งสลิปไม่สำเร็จ: ' + (result.msg || 'กรุณาลองใหม่'));
            btn.disabled = false;
            btn.innerHTML = '📤 ส่งสลิปยืนยันการชำระ';
        }
    }

    // ── UI helpers ─────────────────────────────────────────────────────────────
    function showPending() {
        document.getElementById('statusPending').style.display = 'flex';
        document.getElementById('statusError').style.display = 'none';
    }

    function hidePending() {
        document.getElementById('statusPending').style.display = 'none';
    }

    // ── Card Form Live Interactions ────────────────────────────────────────────
    const cardNumberEl = document.getElementById('cardNumber');
    const cardNameEl = document.getElementById('cardName');
    const cardExpiryEl = document.getElementById('cardExpiry');

    function detectCardBrand(number) {
        if (/^4/.test(number)) return {
            label: 'VISA',
            icon: '💳'
        };
        if (/^5[1-5]/.test(number)) return {
            label: 'MASTERCARD',
            icon: '🔴'
        };
        if (/^3[47]/.test(number)) return {
            label: 'AMEX',
            icon: '💠'
        };
        if (/^6/.test(number)) return {
            label: 'DISCOVER',
            icon: '🟠'
        };
        return {
            label: '••••',
            icon: '💳'
        };
    }

    if (cardNumberEl) {
        cardNumberEl.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '').slice(0, 16);
            this.value = v.match(/.{1,4}/g)?.join(' ') || v;
            const display = v.padEnd(16, '•').match(/.{1,4}/g).join(' ');
            document.getElementById('cvNumber').textContent = display;
            const brand = detectCardBrand(v);
            document.getElementById('cvBrand').textContent = brand.label;
            document.getElementById('cfBrandBadge').textContent = brand.icon;
        });
    }
    if (cardNameEl) {
        cardNameEl.addEventListener('input', function() {
            document.getElementById('cvName').textContent = this.value.toUpperCase() || 'YOUR NAME';
        });
    }
    if (cardExpiryEl) {
        cardExpiryEl.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '').slice(0, 4);
            if (v.length >= 2) v = v.slice(0, 2) + '/' + v.slice(2);
            this.value = v;
            document.getElementById('cvExpiry').textContent = v || 'MM/YY';
        });
    }

    function showError(msg) {
        const el = document.getElementById('statusError');
        el.textContent = '⚠️ ' + msg;
        el.style.display = 'block';
    }

    function hideError() {
        document.getElementById('statusError').style.display = 'none';
    }

    function showSuccess() {
        hidePending();
        document.getElementById('successOverlay').classList.add('show');
    }

    // ── Restore state เมื่อ user กลับมาที่หน้านี้หลังออกไปกลางคัน ─────────────────
    function restorePaymentState() {
        if (!EXISTING_PAYMENT || !EXISTING_PAYMENT.method) return;

        paymentId = EXISTING_PAYMENT.payment_id;
        chargeId = EXISTING_PAYMENT.charge_id;
        selectedMethod = EXISTING_PAYMENT.method;
        selectedBank = EXISTING_PAYMENT.bank || null;
        initiated = true;

        // highlight method
        selectMethod(selectedMethod);
        if (selectedBank) selectBank(selectedBank);

        // แสดง payment UI / ซ่อนปุ่ม payBtn
        document.getElementById('paymentUI').style.display = 'block';
        document.getElementById('payBtn').style.display = 'none';
        document.getElementById('submitSlipBtn').style.display = 'block';

        if (selectedMethod === 'qr') {
            showQRPanel({
                charge_id: chargeId,
                qr_expires_in: 300,
            });
        } else if (selectedMethod === 'mobile' && selectedBank) {
            showMobilePanel({
                charge_id: chargeId,
            });
        }
    }

    // เรียก restore อัตโนมัติเมื่อหน้าโหลด
    document.addEventListener('DOMContentLoaded', restorePaymentState);
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