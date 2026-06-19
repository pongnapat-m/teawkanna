<?php
/**
 * payment_slip_upload.php
 * รับสลิปการโอนเงิน → บันทึก → ส่ง SMS แจ้งเตือน Admin ด้วย Twilio
 *
 * POST params:
 * payment_id  int
 * slip        file (image/*)
 *
 * ── Twilio Config ────────────────────────────────────────────────────────────
 * ต้องติดตั้ง Twilio PHP SDK ก่อน:
 * composer require twilio/sdk
 * หรือ ใช้ REST API โดยตรง (ไม่ต้อง composer) — ไฟล์นี้ใช้ REST API โดยตรง
 * ────────────────────────────────────────────────────────────────────────────
 */

// Prevent any output before JSON response
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
header('Content-Type: application/json; charset=utf-8');

include '../db.php';

// ══════════════════════════════════════════════════════════════════════════════
//  ⚙️  CONFIG — แก้ไขตามระบบจริง
// ══════════════════════════════════════════════════════════════════════════════

// Twilio credentials (ดูจาก console.twilio.com)
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'); // ← ใส่จริง
define('TWILIO_AUTH_TOKEN',  'your_auth_token_here');               // ← ใส่จริง
define('TWILIO_FROM_NUMBER', '+15551234567');                       // ← Twilio number
define('ADMIN_PHONE',        '+66812345678');                       // ← เบอร์ admin ไทย

// Upload dir (แก้ไขให้เป็น Absolute Path เพื่อป้องกันปัญหา chdir ของ Apache)
define('SLIP_UPLOAD_DIR', '/var/www/html/tkn/handlers/uploads/slips/');
define('SLIP_URL_BASE',   'uploads/slips/');

// Max file size (5 MB)
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

// ══════════════════════════════════════════════════════════════════════════════

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$payment_id = (int)($_POST['payment_id'] ?? 0);
if (!$payment_id) {
    echo json_encode(['status' => 'error', 'message' => 'payment_id ไม่ถูกต้อง']);
    exit;
}

if (empty($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
    $err_map = [
        UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกิน upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกิน MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'อัปโหลดไม่สมบูรณ์',
        UPLOAD_ERR_NO_FILE    => 'ไม่พบไฟล์',
        UPLOAD_ERR_NO_TMP_DIR => 'ไม่มี tmp dir',
        UPLOAD_ERR_CANT_WRITE => 'เขียนไฟล์ล้มเหลว',
    ];
    $code = $_FILES['slip']['error'] ?? UPLOAD_ERR_NO_FILE;

    echo json_encode(['status' => 'error', 'message' => $err_map[$code] ?? 'อัปโหลดผิดพลาด']);
    exit;
}

// หา absolute path ของ upload directory โดยอิงจาก __DIR__ ปัจจุบันของไฟล์
$upload_dir = dirname(__DIR__) . '/uploads/slips/';

// [แก้ไขจุดที่ 1] ย้ายตำแหน่งเซฟ debug.log เข้าไปในโฟลเดอร์ slips ที่ได้รับสิทธิ์เขียนเขียนได้
file_put_contents($upload_dir . 'debug.log', date('Y-m-d H:i:s') . " - File validation passed\n", FILE_APPEND);

// ── Verify payment belongs to this user ──────────────────────────────────────
$pq = $conn->prepare(
    "SELECT p.*, b.user_id, b.total_price, b.status AS booking_status,
            b.booking_id, a.activity_name, u.fullname, u.phonenumber as phone
     FROM payment p
     JOIN booking b  ON p.booking_id = b.booking_id
     JOIN activity a ON b.activity_id = a.activity_id
     JOIN user u     ON b.user_id = u.user_id
     WHERE p.payment_id = ? AND b.user_id = ?"
);
$pq->bind_param('ii', $payment_id, $_SESSION['user_id']);
$pq->execute();
$payment = $pq->get_result()->fetch_assoc();
$pq->close();

if (!$payment) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
    exit;
}
if ($payment['booking_status'] === 'Paid') {
    echo json_encode(['status' => 'error', 'message' => 'ชำระเงินไปแล้ว']);
    exit;
}

// ── Validate file type ────────────────────────────────────────────────────────
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $_FILES['slip']['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed_types)) {
    echo json_encode(['status' => 'error', 'message' => 'รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, GIF, WebP)']);
    exit;
}

if ($_FILES['slip']['size'] > MAX_FILE_SIZE) {
    echo json_encode(['status' => 'error', 'message' => 'ไฟล์ใหญ่เกิน 5 MB']);
    exit;
}

// ── Save file ─────────────────────────────────────────────────────────────────
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

$ext      = pathinfo($_FILES['slip']['name'], PATHINFO_EXTENSION) ?: 'jpg';
$filename = 'slip_' . $payment_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$filepath = $upload_dir . $filename;
$file_url = SLIP_URL_BASE . $filename;

if (!move_uploaded_file($_FILES['slip']['tmp_name'], $filepath)) {
    $err = error_get_last();
    echo json_encode([
        'status' => 'error', 
        'message' => 'บันทึกไฟล์ล้มเหลว', 
        'debug' => [
            'filepath' => $filepath,
            'is_dir' => is_dir($upload_dir),
            'is_writable' => is_writable($upload_dir),
            'php_error' => $err['message'] ?? 'No PHP error'
        ]
    ]);
    exit;
}

// ── Update payment record ─────────────────────────────────────────────────────
$upd = $conn->prepare(
    "UPDATE payment SET slip_image = ?, status = 'PendingReview' WHERE payment_id = ?"
);
$upd->bind_param('si', $file_url, $payment_id);
$upd->execute();
$upd->close();

// ── Expand booking.status enum to include PendingReview + Rejected (migration) ──
$conn->query("ALTER TABLE booking MODIFY COLUMN status ENUM('Pending','PendingReview','Paid','Completed','Cancel','Rejected') NOT NULL DEFAULT 'Pending'");

// ── Update booking status → PendingReview ─────────────────────────────────────
$booking_id = (int)$payment['booking_id'];
$upd_b = $conn->prepare("UPDATE booking SET status='PendingReview' WHERE booking_id=?");
$upd_b->bind_param('i', $booking_id);
$upd_b->execute();
$upd_b->close();

// ── Fix existing bookings stuck with empty status ─────────────────────────────
$conn->query("UPDATE booking SET status='PendingReview' WHERE status='' AND booking_id IN (SELECT booking_id FROM payment WHERE slip_image IS NOT NULL AND slip_image != '')");

// [แก้ไขจุดที่ 4] ย้ายตำแหน่งเซฟ debug.log เข้าไปในโฟลเดอร์ slips เช่นกัน
file_put_contents($upload_dir . 'debug.log', date('Y-m-d H:i:s') . " - Payment updated\n", FILE_APPEND);

// ── Send Twilio SMS notifications ─────────────────────────────────────────────
$booking_id    = (int)$payment['booking_id'];
$amount        = number_format((float)$payment['total_price'], 2);
$customer_name = $payment['fullname'];
$activity_name = $payment['activity_name'];
$customer_phone= $payment['phone'];
$charge_id     = $payment['charge_id'] ?? 'N/A';

// 1) SMS ถึง Admin แจ้งมีสลิปใหม่
$admin_msg = "[เที่ยวกันนะ] 🧾 มีสลิปใหม่!\n"
           . "Booking #{$booking_id} | ฿{$amount}\n"
           . "กิจกรรม: {$activity_name}\n"
           . "ลูกค้า: {$customer_name} ({$customer_phone})\n"
           . "Ref: {$charge_id}\n"
           . "กรุณายืนยันที่ admin/payments.php";

// sendTwilioSMS(ADMIN_PHONE, $admin_msg); // Temporarily disabled

// 2) SMS ถึงลูกค้ายืนยันรับสลิปแล้ว
if ($customer_phone) {
    $normalized = normalizeTHPhone($customer_phone);
    if ($normalized) {
        $customer_msg = "[เที่ยวกันนะ] ได้รับสลิปของคุณแล้ว 🌿\n"
                      . "การจอง #{$booking_id} ({$activity_name})\n"
                      . "ยอด ฿{$amount}\n"
                      . "ระบบจะยืนยันภายใน 5-15 นาที ขอบคุณครับ/ค่ะ";
        // sendTwilioSMS($normalized, $customer_msg); // Temporarily disabled
    }
}

// ── Response ──────────────────────────────────────────────────────────────────
echo json_encode([
    'status'     => 'success',
    'message'    => 'อัปโหลดสลิปสำเร็จ รอการยืนยันจากทีมงาน',
    'payment_id' => $payment_id,
    'slip_url'   => $file_url,
]);

// ══════════════════════════════════════════════════════════════════════════════
//  Helper functions
// ══════════════════════════════════════════════════════════════════════════════

/**
 * ส่ง SMS ผ่าน Twilio REST API
 */
function sendTwilioSMS(string $to, string $body): bool {
    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';
    $data = http_build_query([
        'To'   => $to,
        'From' => TWILIO_FROM_NUMBER,
        'Body' => $body,
    ]);

    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => [
                'Authorization: Basic ' . base64_encode(TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN),
                'Content-Type: application/x-www-form-urlencoded',
                'Content-Length: ' . strlen($data),
            ],
            'content' => $data,
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ];

    $ctx    = stream_context_create($opts);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) return false;

    $resp = json_decode($result, true);
    return isset($resp['sid']);
}

/**
 * Normalize เบอร์โทรไทยเป็น +66XXXXXXXXX สำหรับ Twilio
 */
function normalizeTHPhone(string $phone): ?string {
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 10 && $phone[0] === '0') {
        return '+66' . substr($phone, 1);
    }
    if (strlen($phone) === 11 && substr($phone, 0, 2) === '66') {
        return '+' . $phone;
    }
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '+66') {
        return $phone;
    }
    return null;
}