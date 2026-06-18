<?php
require_once __DIR__ . '/env.php';
/**
 * config/url.php  — URL helper สำหรับ clean URL
 *
 * include ไว้ใน db.php หรือ include แยกทุกหน้า
 * BASE_URL = path ที่ติดตั้ง (ไม่มี trailing slash)
 *   - วางที่ root domain   → define('BASE_URL', '')
 *   - วางใน subfolder /tkn → define('BASE_URL', '/tkn')
 */
defined('BASE_URL') || define('BASE_URL', rtrim((string) env('BASE_URL', '/tkn'), '/'));

/**
 * สร้าง URL จาก path + optional segment
 * url('home')          → /tkn/home
 * url('payment', 42)   → /tkn/payment/42
 */
function url(string $path = '', $segment = null): string {
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');
    $out  = $base . ($path !== '' ? '/' . $path : '');
    if ($segment !== null) $out .= '/' . $segment;
    return $out;
}

// ── Short-hand helpers ────────────────────────────────────────────────────────
function urlHome()     : string { return url('home'); }
function urlLogin()    : string { return url('login'); }
function urlRegister() : string { return url('register'); }
function urlLogout()   : string { return url('logout'); }
function urlActivities(): string { return url('activities'); }
function urlActivity(int $id)    : string { return url('activity', $id); }
function urlBook(int $id)        : string { return url('book', $id); }
function urlPayment(int $bid)    : string { return url('payment', $bid); }
function urlDashboard()  : string { return url('dashboard'); }
function urlMyShop()     : string { return url('my-shop'); }
function urlBookHistory(): string { return url('booking-history'); }
function urlProfile()    : string { return url('profile'); }
function urlProfileSetup(): string { return url('profile/setup'); }
function urlShop()       : string { return url('shop'); }
function urlContact()    : string { return url('contact'); }
function urlBilling()    : string { return url('billing'); }
function urlAdmin(string $sub = ''): string {
    return url('admin' . ($sub !== '' ? '/' . ltrim($sub, '/') : ''));
}

/**
 * แปลง path รูปภาพจาก DB ให้เป็น URL ที่ใช้ใน <img src>
 *
 * resolvePic('')                          → '' (ไม่มีรูป)
 * resolvePic('uploads/shop_pics/x.jpg')  → /tkn/handlers/uploads/shop_pics/x.jpg
 * resolvePic('https://...')              → https://...  (ไม่แตะ)
 */
function resolvePic(string $path, string $fallback = ''): string {
    if ($path === '') return $fallback;
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
    $base = rtrim(BASE_URL, '/');
    return $base . '/handlers/' . ltrim($path, '/');
}
