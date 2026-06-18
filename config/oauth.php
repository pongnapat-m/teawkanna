<?php
require_once __DIR__ . '/env.php';
// ============================================================
//  config/oauth.php  —  OAuth Credentials
//  แก้ไขค่าด้านล่างให้ตรงกับ App ของคุณ
// ============================================================

// ── Google OAuth ─────────────────────────────────────────────
define('GOOGLE_CLIENT_ID',     (string) env('GOOGLE_CLIENT_ID', ''));
define('GOOGLE_CLIENT_SECRET', (string) env('GOOGLE_CLIENT_SECRET', ''));

// Redirect URI ที่ใช้จริงในโปรเจกต์นี้
// ถ้าไม่ได้ตั้งค่าไว้เอง จะสร้างจาก host ปัจจุบันและ path ของไฟล์ pages
$defaultScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$defaultHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath      = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
if (substr($basePath, -6) === '/pages') {
    $basePath = substr($basePath, 0, -6) ?: '';
}
$defaultBaseUrl = $defaultScheme . '://' . $defaultHost . ($basePath ?: '');

define('GOOGLE_REDIRECT_URI', (string) env(
    'GOOGLE_REDIRECT_URI',
    $defaultBaseUrl . '/pages/google_callback.php'
));

// ── Facebook OAuth ───────────────────────────────────────────
define('FB_APP_ID',       (string) env('FB_APP_ID', ''));
define('FB_APP_SECRET',   (string) env('FB_APP_SECRET', ''));
define('FB_REDIRECT_URI', (string) env(
    'FB_REDIRECT_URI',
    $defaultBaseUrl . '/pages/facebook_callback.php'
));

// — LINE Messaging API 
define('LINE_CHANNEL_ACCESS_TOKEN', (string) env('LINE_CHANNEL_ACCESS_TOKEN', ''));
define('LINE_CHANNEL_SECRET',       (string) env('LINE_CHANNEL_SECRET', ''));
