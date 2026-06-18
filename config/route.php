<?php
require_once __DIR__ . '/env.php';
/**
 * config/route.php — Token-based URL obfuscation
 *
 * p('booking', ['id'=>154])   →  /tkn/p/aB9xKm3qZ2f
 * p('payment', ['booking_id'=>42])  →  /tkn/p/Xm2pQ9k
 */

define('ROUTE_KEY',    (string) env('ROUTE_KEY', 'change-this-route-key'));
define('ROUTE_CIPHER', 'aes-128-cbc');

function _routeKeyIv(): array {
    $raw = hash('sha256', ROUTE_KEY, true);
    return [substr($raw, 0, 16), substr($raw, 16, 16)];
}

/**
 * สร้าง obfuscated URL
 */
function p(string $page, array $params = []): string {
    [$key, $iv] = _routeKeyIv();
    $payload = json_encode(['pg' => $page] + $params, JSON_UNESCAPED_UNICODE);
    $enc     = openssl_encrypt($payload, ROUTE_CIPHER, $key, 0, $iv);
    $token   = rtrim(strtr(base64_encode($enc), '+/', '-_'), '=');
    return BASE_URL . '/p/' . $token;
}

/**
 * ถอดรหัส token → array หรือ null ถ้าไม่ถูกต้อง
 */
function decodeToken(string $token): ?array {
    try {
        [$key, $iv] = _routeKeyIv();
        $b64     = strtr($token, '-_', '+/');
        $pad     = 4 - (strlen($b64) % 4);
        $enc     = base64_decode($b64 . ($pad < 4 ? str_repeat('=', $pad) : ''), true);
        if ($enc === false) return null;
        $payload = openssl_decrypt($enc, ROUTE_CIPHER, $key, 0, $iv);
        if (!$payload) return null;
        $data = json_decode($payload, true);
        return is_array($data) && isset($data['pg']) ? $data : null;
    } catch (\Throwable) {
        return null;
    }
}
