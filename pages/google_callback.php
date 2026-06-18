<?php
// ============================================================
//  auth/google_callback.php
//  รับ callback จาก Google OAuth และ login / สมัครสมาชิกอัตโนมัติ
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/oauth.php';
require_once '../db.php';

// ── 1. ตรวจสอบ error หรือ code ──────────────────────────────
if (isset($_GET['error'])) {
    header('Location: /tkn/register?oauth_error=google');
    exit();
}
if (empty($_GET['code'])) {
    header('Location: /tkn/register');
    exit();
}

// ── 2. แลก code → access_token ──────────────────────────────
$tokenRes = httpPost('https://oauth2.googleapis.com/token', [
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (empty($tokenRes['access_token'])) {
    header('Location: /tkn/register?oauth_error=google_token');
    exit();
}

// ── 3. ดึงข้อมูล user จาก Google ────────────────────────────
$profile = httpGet(
    'https://www.googleapis.com/oauth2/v3/userinfo',
    $tokenRes['access_token']
);

$google_id = $profile['sub']            ?? null;
$email     = $profile['email']          ?? null;
$fullname  = $profile['name']           ?? 'Google User';
$picture   = $profile['picture']        ?? null;

if (!$google_id || !$email) {
    header('Location: /tkn/register?oauth_error=google_profile');
    exit();
}

// ── 4. ค้นหาหรือสร้าง user ──────────────────────────────────
// 4a. มี google_id อยู่แล้ว?
$stmt = $conn->prepare("SELECT user_id, username, fullname FROM `user` WHERE google_id = ? LIMIT 1");
$stmt->bind_param("s", $google_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // 4b. มี email นี้แต่ยังไม่ได้เชื่อม Google?
    $stmt = $conn->prepare("SELECT user_id, username, fullname FROM `user` WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // เชื่อม google_id กับ account เดิม
        $stmt = $conn->prepare("UPDATE `user` SET google_id = ? WHERE user_id = ?");
        $stmt->bind_param("si", $google_id, $user['user_id']);
        $stmt->execute();
        $stmt->close();
    } else {
        // 4c. สร้าง account ใหม่
        $username = generateUsername($conn, $email);
        $stmt = $conn->prepare(
            "INSERT INTO `user` (username, password, fullname, email, phonenumber, point, google_id)
             VALUES (?, '', ?, ?, '', 0, ?)"
        );
        $stmt->bind_param("ssss", $username, $fullname, $email, $google_id);
        $stmt->execute();
        $user_id = $stmt->insert_id;
        $stmt->close();

        $user = ['user_id' => $user_id, 'username' => $username, 'fullname' => $fullname];
    }
}

// ── 5. ตั้ง session แล้ว redirect ───────────────────────────
$_SESSION['user_id']  = $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role']     = 'user';

header('Location: /tkn/home');
exit();


// ── Helper functions ─────────────────────────────────────────
function httpPost(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function httpGet(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}

function generateUsername(mysqli $conn, string $email): string {
    $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
    $base = $base ?: 'user';
    $username = $base;
    $i = 1;
    while (true) {
        $st = $conn->prepare("SELECT user_id FROM `user` WHERE username = ? LIMIT 1");
        $st->bind_param("s", $username);
        $st->execute();
        $st->store_result();
        $exists = $st->num_rows > 0;
        $st->close();
        if (!$exists) return $username;
        $username = $base . $i++;
    }
}