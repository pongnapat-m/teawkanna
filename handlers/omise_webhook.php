<?php
/**
 * omise_webhook.php
 * รับ event จาก Omise เมื่อการชำระเงินสำเร็จหรือล้มเหลว
 *
 * ตั้งค่าที่ Omise Dashboard:
 *   Settings → Webhooks → Add Endpoint
 *   URL: https://yourdomain.com/tkn/handlers/omise_webhook.php
 *   Events: charge.complete
 *
 * ทำงาน:
 *   1. ตรวจสอบ HMAC signature (ถ้าตั้ง OMISE_WEBHOOK_SECRET)
 *   2. รับ event type = 'charge.complete'
 *   3. อัปเดตสถานะ payment + booking ใน DB
 */

error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

include '../db.php';
include '../config/omise_config.php';

// ── อ่าน raw body ─────────────────────────────────────────────────────────────
$raw_body = file_get_contents('php://input');
if (!$raw_body) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty body']);
    exit;
}

// ── ตรวจ HMAC signature (ถ้ามี secret) ───────────────────────────────────────
if (OMISE_WEBHOOK_SECRET !== '') {
    $sig_header = $_SERVER['HTTP_OMISE_SIGNATURE'] ?? '';
    $expected   = hash_hmac('sha256', $raw_body, OMISE_WEBHOOK_SECRET);
    if (!hash_equals($expected, $sig_header)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
        exit;
    }
}

$event = json_decode($raw_body, true);
if (!$event || !isset($event['key'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid event']);
    exit;
}

$event_key = $event['key'];          // e.g. 'charge.complete'
$data      = $event['data'] ?? [];   // charge object

// ── รองรับเฉพาะ charge.complete ───────────────────────────────────────────────
if ($event_key !== 'charge.complete') {
    echo json_encode(['status' => 'ok', 'message' => 'Event ignored: ' . $event_key]);
    exit;
}

$charge_id     = $data['id']     ?? '';
$charge_status = $data['status'] ?? '';
$metadata      = $data['metadata'] ?? [];
$booking_id    = (int)($metadata['booking_id'] ?? 0);

if (!$charge_id || !$booking_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing charge_id or booking_id']);
    exit;
}

// ── Map status ────────────────────────────────────────────────────────────────
$payment_status = match($charge_status) {
    'successful' => 'Approved',
    'failed'     => 'Rejected',
    default      => 'Pending',
};

// ── Update payment record ─────────────────────────────────────────────────────
$upd_payment = $conn->prepare(
    "UPDATE payment SET status = ?, payment_date = NOW()
     WHERE charge_id = ? OR booking_id = ?
     LIMIT 1"
);
$upd_payment->bind_param('ssi', $payment_status, $charge_id, $booking_id);
$upd_payment->execute();
$upd_payment->close();

// ── Update booking status ─────────────────────────────────────────────────────
if ($payment_status === 'Approved') {
    $upd_booking = $conn->prepare("UPDATE booking SET status = 'Paid' WHERE booking_id = ?");
    $upd_booking->bind_param('i', $booking_id);
    $upd_booking->execute();
    $upd_booking->close();
}

// Log
$log_msg = date('[Y-m-d H:i:s]') . " Webhook: {$event_key} | charge={$charge_id} | booking={$booking_id} | status={$payment_status}\n";
file_put_contents(__DIR__ . '/omise_webhook.log', $log_msg, FILE_APPEND);

http_response_code(200);
echo json_encode(['status' => 'ok', 'processed' => $event_key]);
