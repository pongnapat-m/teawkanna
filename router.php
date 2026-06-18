<?php
/**
 * router.php — รับ token จาก /tkn/p/{token} แล้ว route ไปหน้าที่ถูกต้อง
 */

define('TKN_ROOT', __DIR__);

require_once TKN_ROOT . '/db.php';
require_once TKN_ROOT . '/config/route.php';

$token = $_GET['t'] ?? '';

if (!$token) {
    header('Location: ' . BASE_URL . '/activities');
    exit;
}

$data = decodeToken($token);

if (!$data || !isset($data['pg'])) {
    http_response_code(404);
    chdir(TKN_ROOT . '/pages');
    include TKN_ROOT . '/pages/home.php';
    exit;
}

// ── inject params เข้า $_GET เหมือน request ปกติ ──────────────────────────
$page = $data['pg'];
unset($data['pg']);
foreach ($data as $k => $v) {
    $_GET[$k] = $v;
}

// ── map page name → file ────────────────────────────────────────────────────
$pageMap = [
    'home'          => 'pages/home.php',
    'home_en'       => 'pages/home_en.php',
    'activities'    => 'pages/activity.php',
    'booking'       => 'pages/booking.php',
    'payment'       => 'pages/payment_page.php',
    'receipt'       => 'pages/booking_receipt.php',
    'booking_hist'  => 'pages/dashboard_booking.php',
    'profile'       => 'pages/user.php',
    'profile_setup' => 'pages/profile-setup.php',
    'owner_setup'   => 'pages/owner_setup.php',
    'dashboard'     => 'pages/dashboard.php',
    'my_shop'       => 'pages/dashboard2.php',
    'shop'          => 'pages/shop_profile.php',
    'contact'       => 'pages/contact.php',
    'billing'       => 'pages/billing.php',
    'owner_feedback'=> 'pages/owner_feedback.php',
    'community'     => 'pages/community.php',
    'admin'         => 'admin/dashboard.php',
    'admin_act'     => 'admin/activities.php',
    'admin_pay'     => 'admin/payment.php',
    'admin_users'   => 'admin/users.php',
    'admin_comm'    => 'admin/community.php',
    'admin_contact' => 'admin/contact.php',
    'admin_reports' => 'admin/reports.php',
];

$file = $pageMap[$page] ?? null;

if (!$file || !file_exists(TKN_ROOT . '/' . $file)) {
    http_response_code(404);
    chdir(TKN_ROOT . '/pages');
    include TKN_ROOT . '/pages/home.php';
    exit;
}

// ── เปลี่ยน CWD ไปที่โฟลเดอร์ของหน้านั้น ──────────────────────────────────
// เพื่อให้ include('../db.php') และ include('../config/...') ภายในทุกหน้าทำงานได้
// db.php starts buffering before the token is decoded. Mark the resolved
// destination so user pages reached through /p/{token} are translated too.
if (!defined('TKN_ROUTED_USER_PAGE')) {
    define(
        'TKN_ROUTED_USER_PAGE',
        in_array(basename($file), tknUserPageNames(), true)
    );
}

chdir(TKN_ROOT . '/' . dirname($file));

include TKN_ROOT . '/' . $file;
