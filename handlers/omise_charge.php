<?php
/**
 * omise_charge.php
 * สร้าง charge ผ่าน Omise API — รองรับบัตรเครดิต/เดบิต และ PromptPay
 *
 * POST params:
 *   booking_id   int     — หมายเลขการจอง
 *   method       string  — 'card' | 'omise_promptpay'
 *   token        string  — card token จาก Omise.js (เฉพาะ method=card)
 *
 * Response JSON:
 *   status          'success' | 'error'
 *   payment_id      int
 *   charge_id       string   (chrg_xxx)
 *   charge_status   string   ('successful' | 'pending' | 'failed')
 *   paid            bool     (card เท่านั้น)
 *   qr_image_url    string   (omise_promptpay เท่านั้น)
 *   qr_expires_in   int      seconds
 *   poll_url        string
 *   message         string   (error message)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

include '../db.php';
include '../config/omise_config.php';

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
$token      = trim($_POST['token']      ?? '');

// bank mapping: key ของเรา → Omise source type
const BANK_SOURCE_MAP = [
    'SCB'       => 'internet_banking_scb',
    'K-Bank'    => 'internet_banking_bay',   // sandbox fallback
    'Krungthai' => 'internet_banking_ktb',
];

$bank_key = trim($_POST['bank_name'] ?? '');

if (!$booking_id || !in_array($method, ['card', 'omise_promptpay', 'omise_banking'])) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}
if ($method === 'card' && !$token) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token บัตร กรุณาลองใหม่']);
    exit;
}
if ($method === 'omise_banking' && !isset(BANK_SOURCE_MAP[$bank_key])) {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเลือกธนาคาร']);
    exit;
}

// ── Fetch booking ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT b.booking_id, b.total_price, b.status, b.user_id
     FROM booking b
     WHERE b.booking_id = ? AND b.user_id = ?"
);
$stmt->bind_param('ii', $booking_id, $_SESSION['user_id']);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการจอง']);
    exit;
}
if ($booking['status'] === 'Paid') {
    echo json_encode(['status' => 'error', 'message' => 'การจองนี้ชำระเงินแล้ว']);
    exit;
}

$amount_thb    = (float)$booking['total_price'];
$amount_satang = (int)round($amount_thb * 100); // Omise ใช้หน่วยสตางค์

// ── cURL helper ───────────────────────────────────────────────────────────────
function omise_request(string $method, string $endpoint, array $data = []): array {
    $url = OMISE_API_BASE . $endpoint;
    $ch  = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => OMISE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Omise-Version: ' . OMISE_API_VERSION,
        ],
        CURLOPT_SSL_VERIFYPEER => false, // localhost XAMPP
        CURLOPT_TIMEOUT        => 30,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $opts);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['code' => 0, 'body' => ['message' => 'cURL error: ' . $curl_err]];
    }
    return ['code' => $http_code, 'body' => json_decode($response, true) ?? []];
}

// ── Build charge payload ───────────────────────────────────────────────────────
$payload = [
    'amount'     => $amount_satang,
    'currency'   => OMISE_CURRENCY,
    'description' => 'Booking #' . $booking_id . ' - เที่ยวกันนา',
    'metadata'   => [
        'booking_id' => $booking_id,
        'user_id'    => $_SESSION['user_id'],
    ],
];

if ($method === 'card') {
    $payload['card']    = $token;
    $payload['capture'] = true;
} elseif ($method === 'omise_promptpay') {
    $payload['source'] = ['type' => 'promptpay'];
} elseif ($method === 'omise_banking') {
    $payload['source']     = ['type' => BANK_SOURCE_MAP[$bank_key]];
    $payload['return_uri'] = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                           . '://' . $_SERVER['HTTP_HOST']
                           . '/tkn/pages/payment_status_return.php?booking_id=' . $booking_id;
}

// ── Call Omise API ────────────────────────────────────────────────────────────
$result = omise_request('POST', '/charges', $payload);
$charge = $result['body'];

if ($result['code'] !== 200 || empty($charge['id'])) {
    $err = $charge['message'] ?? ($charge['code'] ?? 'ไม่สามารถสร้าง charge ได้ กรุณาลองใหม่');
    // map Omise error codes to Thai messages
    $err_map = [
        'invalid_card'           => 'ข้อมูลบัตรไม่ถูกต้อง',
        'expired_card'           => 'บัตรหมดอายุแล้ว',
        'failed_fraud_check'     => 'บัตรถูกปฏิเสธ (fraud check)',
        'payment_rejected'       => 'ธนาคารปฏิเสธการชำระเงิน',
        'insufficient_fund'      => 'วงเงินในบัตรไม่เพียงพอ',
        'stolen_or_lost_card'    => 'บัตรถูกแจ้งหาย/ถูกขโมย',
    ];
    $code_key = $charge['code'] ?? '';
    $friendly = $err_map[$code_key] ?? $err;
    echo json_encode(['status' => 'error', 'message' => $friendly, 'omise_code' => $code_key]);
    exit;
}

$charge_id     = $charge['id'];
$charge_status = $charge['status']; // 'successful' | 'pending' | 'failed'

// ── Map Omise status → internal status ───────────────────────────────────────
$payment_status = match($charge_status) {
    'successful' => 'Approved',
    'failed'     => 'Rejected',
    default      => 'Pending',
};
$pm_label = match($method) {
    'card'           => 'credit_card_omise',
    'omise_promptpay'=> 'promptpay_omise',
    'omise_banking'  => 'internet_banking_omise_' . strtolower(str_replace('-','',$bank_key)),
    default          => $method,
};

// ── Ensure schema columns exist (compat: no IF NOT EXISTS for older MySQL) ────
$col_check = $conn->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment' AND COLUMN_NAME='charge_id' LIMIT 1");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE payment ADD COLUMN charge_id VARCHAR(100) DEFAULT NULL");
}

// ── Save/update payment record ────────────────────────────────────────────────
$exist_q = $conn->prepare("SELECT payment_id FROM payment WHERE booking_id = ? LIMIT 1");
$exist_q->bind_param('i', $booking_id);
$exist_q->execute();
$existing = $exist_q->get_result()->fetch_assoc();
$exist_q->close();

$now        = date('Y-m-d H:i:s');
$payment_id = null;

if ($existing) {
    $payment_id = (int)$existing['payment_id'];
    $upd = $conn->prepare(
        "UPDATE payment SET status = ?, charge_id = ?, payment_method = ?, payment_date = ? WHERE payment_id = ?"
    );
    $upd->bind_param('ssssi', $payment_status, $charge_id, $pm_label, $now, $payment_id);
    $upd->execute();
    $upd->close();
} else {
    $ins = $conn->prepare(
        "INSERT INTO payment (amount, payment_date, payment_method, slip_image, status, booking_id, charge_id)
         VALUES (?, ?, ?, '', ?, ?, ?)"
    );
    $ins->bind_param('dssssi', $amount_thb, $now, $pm_label, $payment_status, $booking_id, $charge_id);
    $ins->execute();
    $payment_id = $conn->insert_id;
    $ins->close();
}

// ── Auto-update booking status ────────────────────────────────────────────────
if ($payment_status === 'Approved') {
    $conn->query("UPDATE booking SET status = 'Paid' WHERE booking_id = $booking_id");
}

// ── Build response ────────────────────────────────────────────────────────────
$resp = [
    'status'         => 'success',
    'payment_id'     => $payment_id,
    'charge_id'      => $charge_id,
    'charge_status'  => $charge_status,
    'payment_status' => $payment_status,
    'method'         => $method,
    'poll_url'       => '../pages/payment_status.php?payment_id=' . $payment_id,
];

// Card: immediate pass/fail
if ($method === 'card') {
    $resp['paid']            = ($charge_status === 'successful');
    $resp['failure_message'] = $charge['failure_message'] ?? null;
    $resp['authorized']      = $charge['authorized'] ?? false;
}

// PromptPay: QR image URL จาก Omise
if ($method === 'omise_promptpay') {
    $qr_uri = $charge['source']['scannable_code']['image']['download_uri'] ?? null;
    $resp['qr_image_url']  = $qr_uri;
    $resp['qr_expires_in'] = 90;
    $resp['amount_thb']    = $amount_thb;
}

// Internet Banking: authorize_uri (redirect ไปหน้าธนาคาร sandbox)
if ($method === 'omise_banking') {
    $resp['authorize_uri'] = $charge['authorize_uri'] ?? null;
    $resp['bank_name']     = $bank_key;
}

echo json_encode($resp);