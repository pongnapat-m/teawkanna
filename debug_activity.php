<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config/route.php';
require_once __DIR__ . '/db.php';

$token = 'Skltb2sySUoyM2pJYkRzY3l4b0p3TDd3L0VNTmpwYjk2andlT2xmT3BiUT0';
$decoded = decodeToken($token);
echo "DECODED TOKEN:\n";
print_r($decoded);

if ($decoded && isset($decoded['id'])) {
    $id = (int)$decoded['id'];
    echo "\nACTIVITY ID: " . $id . "\n";
    
    // Query activity
    $stmt = $conn->prepare("SELECT activity_id, activity_name, max_capacity, status FROM activity WHERE activity_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $act = $stmt->get_result()->fetch_assoc();
    echo "\nACTIVITY DETAILS:\n";
    print_r($act);
    $stmt->close();
    
    // Query open requests
    $stmt = $conn->prepare("SELECT * FROM activity_open_request WHERE new_activity_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $reqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo "\nOPEN REQUESTS:\n";
    print_r($reqs);
    $stmt->close();
    
    // Query bookings
    $stmt = $conn->prepare("SELECT booking_id, booking_date, status, adult_quantity, kid_quantity FROM booking WHERE activity_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo "\nBOOKINGS:\n";
    print_r($bookings);
    $stmt->close();
}
