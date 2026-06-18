<?php
// ============================================================
//  auth/facebook_callback.php
//  รับ callback จาก Facebook OAuth และ login / สมัครสมาชิกอัตโนมัติ
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../config/oauth.php';
require_once '../db.php';

// ── 1. ตรวจสอบ error หรือ code ──────────────────────────────
if (isset($_GET['error'])) {
    header('Location: /tkn/register?oauth_error=facebook');
    exit();
}
if (empty($_GET['code'])) {
    header('Location: /tkn/register');
    exit();
}

// ── 2. แลก code → access_token ──────────────────────────────
$tokenUrl = 'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query([
    'client_id'     => FB_APP_ID,
    'client_secret' => FB_APP_SECRET,
    'redirect_uri'  => FB_REDIRECT_URI,
    'code'          => $_GET['code'],
]);
$tokenRes = httpGet($tokenUrl);

if (empty($tokenRes['access_token'])) {
    header('Location: /tkn/register?oauth_error=facebook_token');
    exit();
}

// ── 3. ดึงข้อมูล user จาก Facebook Graph API ────────────────
$profileUrl = 'https://graph.facebook.com/me?' . http_build_query([
    'fields'       => 'id,name,email,picture',
    'access_token' => $tokenRes['access_token'],
]);
$profile = httpGet($profileUrl);

$fb_id    = $profile['id']             ?? null;
$email    = $profile['email']          ?? null;
$fullname = $profile['name']           ?? 'Facebook User';

if (!$fb_id) {
    header('Location: /tkn/register?oauth_error=facebook_profile');
    exit();
}

// ── 4. ค้นหาหรือสร้าง user ──────────────────────────────────
// 4a. มี facebook_id อยู่แล้ว?
$stmt = $conn->prepare("SELECT user_id, username, fullname FROM `user` WHERE facebook_id = ? LIMIT 1");
$stmt->bind_param("s", $fb_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user && $email) {
    // 4b. มี email นี้แต่ยังไม่ได้เชื่อม Facebook?
    $stmt = $conn->prepare("SELECT user_id, username, fullname FROM `user` WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        // เชื่อม facebook_id กับ account เดิม
        $stmt = $conn->prepare("UPDATE `user` SET facebook_id = ? WHERE user_id = ?");
        $stmt->bind_param("si", $fb_id, $user['user_id']);
        $stmt->execute();
        $stmt->close();
    }
}

if (!$user) {
    // 4c. สร้าง account ใหม่
    // Facebook อาจไม่ส่ง email มา → ใช้ fb_id แทน
    $fakeEmail = $email ?: ($fb_id . '@facebook.invalid');
    $username  = generateUsername($conn, $fakeEmail);
    $stmt = $conn->prepare(
        "INSERT INTO `user` (username, password, fullname, email, phonenumber, point, facebook_id)
         VALUES (?, '', ?, ?, '', 0, ?)"
    );
    $emailStore = $email ?? '';
    $stmt->bind_param("ssss", $username, $fullname, $emailStore, $fb_id);
    $stmt->execute();
    $user_id = $stmt->insert_id;
    $stmt->close();

    $user = ['user_id' => $user_id, 'username' => $username, 'fullname' => $fullname];
}

// ── 5. ตั้ง session แล้ว redirect ───────────────────────────
$_SESSION['user_id']  = $user['user_id'];
$_SESSION['username'] = $user['username'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role']     = 'user';

header('Location: /tkn/home');
exit();


// ── Helper functions ─────────────────────────────────────────
function httpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
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