<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/env.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit;
}

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์ที่อัปโหลด']);
    exit;
}

$file     = $_FILES['avatar'];
$maxSize  = 5 * 1024 * 1024; // 5 MB
$allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

// ── Validate ─────────────────────────────────────────────────────────────
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'ไฟล์ใหญ่เกิน 5MB']);
    exit;
}

// ตรวจ MIME จากเนื้อไฟล์จริง ไม่ใช่จาก client
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'รองรับเฉพาะ JPG, PNG, WEBP, GIF']);
    exit;
}

// ── บันทึกไฟล์ ────────────────────────────────────────────────────────────
$ext      = match($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    default      => 'jpg',
};

$uploadDir = __DIR__ . '/uploads/avatars/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $filename;
$dbPath   = 'uploads/avatars/' . $filename;   // path เก็บใน DB

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'message' => 'อัปโหลดไฟล์ไม่สำเร็จ']);
    exit;
}

// ── UPDATE DB ─────────────────────────────────────────────────────────────
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'teawkanna')
    );
    $pdo  = new PDO($dsn, (string) env('DB_USER', 'root'), (string) env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // ลบรูปเก่าออกจาก disk ถ้ามี
    $stmt = $pdo->prepare('SELECT profile_pic FROM user WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $oldPic = $stmt->fetchColumn();
    if ($oldPic && file_exists(__DIR__ . '/' . $oldPic)) {
        @unlink(__DIR__ . '/' . $oldPic);
    }

    $stmt = $pdo->prepare('UPDATE user SET profile_pic = ? WHERE user_id = ?');
    $stmt->execute([$dbPath, (int)$_SESSION['user_id']]);

} catch (PDOException $e) {
    error_log('Avatar upload database error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'บันทึกข้อมูลไม่สำเร็จ']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'อัปโหลดสำเร็จ',
    'url'     => $dbPath,
]);
