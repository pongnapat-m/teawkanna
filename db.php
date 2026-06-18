<?php
// Prevent port 8080 from being accessed externally on Railway production
if (isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'up.railway.app:8080')) {
    $clean_host = str_replace(':8080', '', $_SERVER['HTTP_HOST']);
    header("Location: https://" . $clean_host . $_SERVER['REQUEST_URI'], true, 301);
    exit();
}

require_once __DIR__ . '/config/env.php';

$host = (string) env('DB_HOST', env('MYSQLHOST', '127.0.0.1'));
$port = (int) env('DB_PORT', env('MYSQLPORT', 3306));
$user = (string) env('DB_USER', env('MYSQLUSER', 'root'));
$pass = (string) env('DB_PASS', env('MYSQLPASSWORD', ''));
$db   = (string) env('DB_NAME', env('MYSQLDATABASE', 'teawkanna'));

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    error_log('Database connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    die(APP_DEBUG ? 'Database connection failed: ' . mysqli_connect_error() : 'ระบบฐานข้อมูลขัดข้อง');
}

mysqli_set_charset($conn, "utf8mb4");

// โหลด URL helper อัตโนมัติ
$_url_helper = __DIR__ . '/config/url.php';
if (file_exists($_url_helper)) require_once $_url_helper;

// โหลด route helper (token obfuscation)
$_route_helper = __DIR__ . '/config/route.php';
if (file_exists($_route_helper)) require_once $_route_helper;

// ════ ระบบจัดการภาษา ════
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['lang'])) $_SESSION['lang'] = 'th';

// ตรวจสอบ lang parameter จาก URL
if (isset($_GET['lang']) && in_array($_GET['lang'], ['th', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

// ฟังก์ชันสำหรับเพิ่ม lang parameter ให้ URL
if (!function_exists('addLangParam')) {
    function addLangParam($url, $lang = null) {
        if ($lang === null) {
            $lang = $_SESSION['lang'] ?? 'th';
        }

        if ($url === '') {
            $url = $_SERVER['REQUEST_URI'] ?? url('home');
        }

        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        $query = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $query);
        $query['lang'] = in_array($lang, ['th', 'en'], true) ? $lang : 'th';

        return $path . '?' . http_build_query($query)
             . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}

if (!function_exists('currentLang')) {
    function currentLang(): string {
        return ($_SESSION['lang'] ?? 'th') === 'en' ? 'en' : 'th';
    }
}

require_once __DIR__ . '/config/i18n.php';

// Translate only regular user-facing pages. Owner/admin pages are excluded.
if (!defined('TKN_I18N_BUFFER')) {
    define('TKN_I18N_BUFFER', true);
    ob_start(function ($output) {
        if (stripos($output, '</body>') === false || !tknIsUserPage()) {
            return $output;
        }

        $lang = currentLang();
        $output = preg_replace(
            '/<html(\s[^>]*)?\slang=(["\']).*?\2/i',
            '<html$1 lang="' . $lang . '"',
            $output,
            1
        );
        $i18nCss = url('assets/css/i18n.css');
        $assets = '<link rel="stylesheet" href="' . htmlspecialchars($i18nCss, ENT_QUOTES, 'UTF-8') . '">';
        if (stripos($output, $i18nCss) === false) {
            $output = preg_replace('/<\/head>/i', $assets . '</head>', $output, 1);
        }

        // Pages with an existing navbar language button keep it. Other user
        // pages receive the compact shared switcher.
        if (stripos($output, 'lang-switch-btn') === false
            && stripos($output, 'tkn-global-lang-switch') === false) {
            $output = preg_replace(
                '/<\/body>/i',
                tknLanguageSwitcherHtml($lang) . '</body>',
                $output,
                1
            );
        }

        return tknTranslateUi($output, $lang);
    });
}
