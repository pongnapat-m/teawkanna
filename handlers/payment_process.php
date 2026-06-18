<?php
/**
 * payment_process.php  (ฉบับอัปเดต)
 * จำลองการชำระเงิน — รองรับ Mobile Banking, QR PromptPay
 *
 * POST params:
 *   booking_id   int
 *   method       string  'mobile' | 'qr'
 *   bank_name    string  (mobile เท่านั้น: 'SCB' | 'K-Bank' | 'Krungthai')
 *
 * ── การทำงาน ──────────────────────────────────────────────────────────────────
 * 1. สร้าง charge_id จำลอง (sandbox)
 * 2. บันทึก payment record (status = Pending)
 * 3. คืน payload ให้ client:
 *    - QR  → qr_payload (EMVCo PromptPay string) + promptpay_ref
 *    - Mobile → deeplink + bank steps
 * 4. Client poll payment_status.php ทุก 3 วิ
 * 5. Sandbox: auto-approve หลัง 3 วิ (payment_status.php)
 *    Production: approve เมื่อ admin ยืนยันสลิป หรือ webhook จาก Omise/PromptPay
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

include '../db.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อน']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$booking_id = (int)($_POST['booking_id'] ?? 0);
$method     = trim($_POST['method']     ?? '');
$bank_name  = trim($_POST['bank_name']  ?? '');

// ── Validate ──────────────────────────────────────────────────────────────────
if (!$booking_id || !in_array($method, ['mobile', 'qr'])) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}
if ($method === 'mobile' && !in_array($bank_name, ['SCB', 'K-Bank', 'Krungthai'])) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกธนาคาร']);
    exit;
}

// ── Fetch booking ─────────────────────────────────────────────────────────────
$bq = $conn->prepare(
    "SELECT b.*, a.activity_id AS act_id
     FROM booking b
     JOIN activity a ON b.activity_id = a.activity_id
     WHERE b.booking_id = ? AND b.user_id = ?"
);
$bq->bind_param('ii', $booking_id, $_SESSION['user_id']);
$bq->execute();
$booking = $bq->get_result()->fetch_assoc();
$bq->close();

if (!$booking) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการจอง']);
    exit;
}
if ($booking['status'] === 'Paid') {
    echo json_encode(['status' => 'error', 'message' => 'การจองนี้ชำระเงินแล้ว']);
    exit;
}

$amount       = (float)$booking['total_price'];
$charge_id    = 'chrg_sandbox_' . bin2hex(random_bytes(8));
$charge_time  = date('Y-m-d H:i:s');

// ── Ensure charge_id column exists (migration on-the-fly) ────────────────────
$conn->query("ALTER TABLE payment ADD COLUMN IF NOT EXISTS charge_id VARCHAR(100) DEFAULT NULL");
$conn->query("ALTER TABLE payment ADD COLUMN IF NOT EXISTS admin_note TEXT DEFAULT NULL");
// แก้ default status ของ column ให้รองรับทุก status
$conn->query("ALTER TABLE payment MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Pending'");
// เพิ่ม payment_deadline ใน booking ถ้ายังไม่มี
$conn->query("ALTER TABLE booking ADD COLUMN IF NOT EXISTS payment_deadline DATETIME NULL DEFAULT NULL");

// ── Save / find payment record ────────────────────────────────────────────────
$exist_q = $conn->prepare("SELECT payment_id, charge_id FROM payment WHERE booking_id = ? LIMIT 1");
$exist_q->bind_param('i', $booking_id);
$exist_q->execute();
$existing = $exist_q->get_result()->fetch_assoc();
$exist_q->close();

$payment_id = null;

if (!$existing) {
    $pm_label = $method === 'mobile'
        ? 'mobile_banking_' . strtolower(str_replace('-', '', $bank_name))
        : 'qr_promptpay';

    $ins = $conn->prepare(
        "INSERT INTO payment (amount, payment_date, payment_method, slip_image, status, booking_id, charge_id)
         VALUES (?, ?, ?, '', 'Pending', ?, ?)"
    );
    $ins->bind_param('dsssi', $amount, $charge_time, $pm_label, $booking_id, $charge_id);
    $ins->execute();
    $payment_id = $conn->insert_id;
    $ins->close();
} else {
    $payment_id = (int)$existing['payment_id'];
    $charge_id  = $existing['charge_id'] ?? $charge_id;
    // ถ้า record มีอยู่แล้วแต่ status ว่าง ให้อัปเดตเป็น Pending
    $fix = $conn->prepare("UPDATE payment SET status = 'Pending' WHERE payment_id = ? AND (status IS NULL OR status = '')");
    $fix->bind_param('i', $payment_id);
    $fix->execute();
    $fix->close();
}

// ── Update booking method ─────────────────────────────────────────────────────
$b_val = $method === 'mobile' ? $bank_name : null;
$upd   = $conn->prepare("UPDATE booking SET payment_method = ?, bank_name = ? WHERE booking_id = ?");
$upd->bind_param('ssi', $method, $b_val, $booking_id);
$upd->execute();
$upd->close();

// ── Build response ────────────────────────────────────────────────────────────
$resp = [
    'status'      => 'success',
    'payment_id'  => $payment_id,
    'charge_id'   => $charge_id,
    'amount'      => $amount,
    'method'      => $method,
    'bank_name'   => $bank_name ?: null,
    'poll_url'    => 'payment_status.php?payment_id=' . $payment_id,
    'auto_confirm_delay' => 3,
];

if ($method === 'qr') {
    // Client จะ build EMVCo payload เองจาก promptpay_id + amount
    $resp['qr_expires_in']  = 300; // 5 นาที
    $resp['promptpay_ref']  = '0' . str_pad((string)$booking_id, 10, '0', STR_PAD_LEFT);
}

if ($method === 'mobile') {
    $deeplinks = [
        'SCB'       => 'scbeasy://payment?ref=',
        'K-Bank'    => 'kplusdeeplink://payment?ref=',
        'Krungthai' => 'krungthai://payment?ref=',
    ];
    $resp['deeplink']    = ($deeplinks[$bank_name] ?? '#') . $charge_id;
    $resp['bank_label']  = $bank_name;
    $resp['instruction'] = "เปิดแอป {$bank_name} แล้วโอนเงิน ฿" . number_format($amount, 2) . " Ref: {$charge_id}";
}

echo json_encode($resp);