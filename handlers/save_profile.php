<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/env.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบ session กรุณาเข้าสู่ระบบใหม่']);
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    $data = $_POST;
}

$fullname  = trim($data['fullname']  ?? '');
$username  = trim($data['username']  ?? '');
$phone     = trim($data['phone']     ?? '');
$email     = trim($data['email']     ?? '');
$interests = $data['interests']      ?? [];  // array — เก็บใน session / localStorage ฝั่ง client

// ── Validation ───────────────────────────────────────────────────────────
if (!$fullname || !$username || !$phone || !$email) {
    echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง']);
    exit;
}
if (!preg_match('/^[0-9]{9,15}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'รูปแบบเบอร์โทรไม่ถูกต้อง']);
    exit;
}
if (empty($interests)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเลือกความสนใจอย่างน้อย 1 อย่าง']);
    exit;
}

// ── Connect DB ───────────────────────────────────────────────────────────
try {
    $dbHost = (string) env('DB_HOST', env('MYSQLHOST', '127.0.0.1'));
    $dbPort = (int) env('DB_PORT', env('MYSQLPORT', 3306));
    $dbName = (string) env('DB_NAME', env('MYSQLDATABASE', 'teawkanna'));
    $dbUser = (string) env('DB_USER', env('MYSQLUSER', 'root'));
    $dbPass = (string) env('DB_PASS', env('MYSQLPASSWORD', ''));

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort,
        $dbName
    );
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    error_log('Profile database connection failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ']);
    exit;
}

// ── ตรวจ username ซ้ำ (ยกเว้น user ปัจจุบัน) ─────────────────────────────
$stmt = $pdo->prepare('SELECT user_id FROM user WHERE username = ? AND user_id != ?');
$stmt->execute([$username, $user_id]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว']);
    exit;
}

// ── UPDATE user ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare('
    UPDATE user
    SET fullname    = ?,
        username    = ?,
        phonenumber = ?,
        email       = ?
    WHERE user_id   = ?
');
$stmt->execute([$fullname, $username, $phone, $email, $user_id]);

// interests ไม่มีคอลัมน์ใน DB → เก็บใน session เพื่อส่งกลับ client เก็บ localStorage
$_SESSION['interests'] = $interests;
$_SESSION['fullname']  = $fullname;
$_SESSION['username']  = $username;

echo json_encode([
    'success'   => true,
    'message'   => 'บันทึกข้อมูลเรียบร้อย',
    'user'      => [
        'user_id'   => $user_id,
        'fullname'  => $fullname,
        'username'  => $username,
        'phone'     => $phone,
        'email'     => $email,
        'interests' => $interests,
    ],
]);
