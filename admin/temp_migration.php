<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied. Admin only.");
}
include '../db.php';

$queries = [
    "ALTER TABLE `owner` ADD INDEX `idx_owner_status` (`status`)",
    "ALTER TABLE `activity_open_request` ADD INDEX `idx_aor_status` (`status`)",
    "ALTER TABLE `activity_open_request` ADD INDEX `idx_aor_activity` (`activity_id`)",
    "ALTER TABLE `activity_open_request` ADD INDEX `idx_aor_shop` (`shop_id`)",
    "ALTER TABLE `activity_open_request` ADD INDEX `idx_aor_owner` (`owner_id`)",
    "ALTER TABLE `booking` ADD INDEX `idx_booking_status` (`status`)",
    "ALTER TABLE `booking` ADD INDEX `idx_booking_user` (`user_id`)",
    "ALTER TABLE `booking` ADD INDEX `idx_booking_activity` (`activity_id`)",
    "ALTER TABLE `booking` ADD INDEX `idx_booking_date` (`booking_date`)",
    "ALTER TABLE `payment` ADD INDEX `idx_payment_status` (`status`)",
    "ALTER TABLE `payment` ADD INDEX `idx_payment_booking` (`booking_id`)",
    "ALTER TABLE `payment` ADD INDEX `idx_payment_date` (`payment_date`)",
    "ALTER TABLE `review` ADD INDEX `idx_review_user` (`user_id`)",
    "ALTER TABLE `review` ADD INDEX `idx_review_activity` (`activity_id`)",
    "ALTER TABLE `review` ADD INDEX `idx_review_created` (`created_at`)",
    "ALTER TABLE `contact_message` ADD INDEX `idx_cm_is_read` (`is_read`)"
];

echo "<h2>Running migrations...</h2>";
foreach ($queries as $q) {
    if ($conn->query($q)) {
        echo "<span style='color:green;'>Success</span>: $q<br>";
    } else {
        echo "<span style='color:red;'>Info/Error</span>: $q (" . htmlspecialchars($conn->error) . ")<br>";
    }
}
echo "<h3>Done</h3>";
?>
