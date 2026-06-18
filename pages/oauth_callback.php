<?php
/**
 * oauth_callback.php
 * รับ callback จาก Google / Facebook แล้ว login หรือสร้าง account ให้อัตโนมัติ
 */
if (session_status() === PHP_SESSION_NONE) session_start();
error_reporting(0);

require_once '../db.php';
require_once '../config/oauth.php';

$provider = $_GET['provider'] ?? '';
$code     = $_GET['code']     ?? '';
$error    = $_GET['error']    ?? '';

function oauth_fail($msg) {
    echo "<script>
        if(window.opener){ window.opener.location.href='/tkn/login?oauth_error=".urlencode($msg)."'; window.close(); }
        else { window.location.href='/tkn/login?oauth_error=".urlencode($msg)."'; }
    </script>";
    exit;
}

function oauth_fetch($url, $params = null, $headers = []) {
    $ch = curl_init();
    if ($params !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($params) ? http_build_query($params) : $params);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

if ($error) oauth_fail('ยกเลิกการเข้าสู่ระบบ');
if (!$code) oauth_fail('ไม่ได้รับ authorization code');

// ─── GOOGLE ──────────────────────────────────────────────
if ($provider === 'google') {
    // 1. แลก code → access token
    $token = oauth_fetch('https://oauth2.googleapis.com/token', [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
    if (empty($token['access_token'])) oauth_fail('Google token error');

    // 2. ดึงข้อมูล user
    $user = oauth_fetch('https://www.googleapis.com/oauth2/v2/userinfo', null, [
        'Authorization: Bearer ' . $token['access_token'],
    ]);
    if (empty($user['email'])) oauth_fail('ไม่สามารถดึงข้อมูลจาก Google ได้');

    $email    = $user['email'];
    $fullname = $user['name']    ?? $email;
    $oauth_id = $user['id']      ?? '';
    $avatar   = $user['picture'] ?? '';
    $provider_label = 'google';
}

// ─── FACEBOOK ────────────────────────────────────────────
elseif ($provider === 'facebook') {
    // 1. แลก code → access token
    $token = oauth_fetch(
        'https://graph.facebook.com/v18.0/oauth/access_token?' . http_build_query([
            'client_id'     => FB_APP_ID,
            'client_secret' => FB_APP_SECRET,
            'redirect_uri'  => FB_REDIRECT_URI,
            'code'          => $code,
        ])
    );
    if (empty($token['access_token'])) oauth_fail('Facebook token error');

    // 2. ดึงข้อมูล user
    $user = oauth_fetch(
        'https://graph.facebook.com/me?fields=id,name,email,picture.width(200)&access_token=' . $token['access_token']
    );
    if (empty($user['id'])) oauth_fail('ไม่สามารถดึงข้อมูลจาก Facebook ได้');

    $email    = $user['email']  ?? '';
    $fullname = $user['name']   ?? 'Facebook User';
    $oauth_id = $user['id'];
    $avatar   = $user['picture']['data']['url'] ?? '';
    $provider_label = 'facebook';
}

else {
    oauth_fail('provider ไม่รู้จัก');
}

// ─── ค้นหาหรือสร้าง user ─────────────────────────────────
// เพิ่ม columns ถ้ายังไม่มี
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS oauth_provider VARCHAR(20) DEFAULT NULL");
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS oauth_id VARCHAR(100) DEFAULT NULL");
$conn->query("ALTER TABLE `user` ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(500) DEFAULT NULL");

// หา user จาก oauth_id ก่อน
$row = null;
$sq = $conn->prepare("SELECT user_id, username, fullname FROM `user` WHERE oauth_provider=? AND oauth_id=? LIMIT 1");
$sq->bind_param('ss', $provider_label, $oauth_id);
$sq->execute();
$row = $sq->get_result()->fetch_assoc();
$sq->close();

// ถ้าไม่เจอ หาจาก email
if (!$row && $email) {
    $sq2 = $conn->prepare("SELECT user_id, username, fullname FROM `user` WHERE email=? LIMIT 1");
    $sq2->bind_param('s', $email);
    $sq2->execute();
    $row = $sq2->get_result()->fetch_assoc();
    $sq2->close();

    // อัปเดต oauth info
    if ($row) {
        $upd = $conn->prepare("UPDATE `user` SET oauth_provider=?, oauth_id=?, avatar_url=? WHERE user_id=?");
        $upd->bind_param('sssi', $provider_label, $oauth_id, $avatar, $row['user_id']);
        $upd->execute();
        $upd->close();
    }
}

// ถ้ายังไม่เจอ → สร้าง account ใหม่
if (!$row) {
    $username = $provider_label . '_' . substr($oauth_id, 0, 8);
    $pw_hash  = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $email_v  = $email ?: '';

    $ins = $conn->prepare(
        "INSERT INTO `user` (username, password, fullname, email, phonenumber, point, oauth_provider, oauth_id, avatar_url)
         VALUES (?,?,?,?,?,0,?,?,?)"
    );
    $ins->bind_param('ssssssss', $username, $pw_hash, $fullname, $email_v, '', $provider_label, $oauth_id, $avatar);
    $ins->execute();
    $uid = $conn->insert_id;
    $ins->close();

    $row = ['user_id' => $uid, 'username' => $username, 'fullname' => $fullname];
}

// ─── Set session & redirect ───────────────────────────────
$_SESSION['user_id']  = $row['user_id'];
$_SESSION['username'] = $row['username'];
$_SESSION['fullname'] = $row['fullname'];
$_SESSION['role']     = 'user';

$fn = htmlspecialchars($row['fullname'], ENT_QUOTES);
echo "<!DOCTYPE html><html><head>
<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
</head><body>
<script>
Swal.fire({ icon:'success', title:'เข้าสู่ระบบสำเร็จ', text:'ยินดีต้อนรับ {$fn}',
    showConfirmButton:false, timer:1400
}).then(()=>{ window.location.href='/tkn/home'; });
</script></body></html>";
