<?php

function ensurePromotionSchema(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS promotion_usage (
            usage_id INT NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            promotion_id INT NOT NULL,
            booking_id INT NOT NULL,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (usage_id),
            UNIQUE KEY unique_user_promotion (user_id, promotion_id),
            UNIQUE KEY unique_booking_promotion (booking_id, promotion_id),
            KEY promotion_id (promotion_id),
            KEY booking_id (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $columns = [
        'original_price' => "ALTER TABLE booking ADD COLUMN original_price DECIMAL(10,2) NULL AFTER total_price",
        'discount_amount' => "ALTER TABLE booking ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER original_price",
        'promotion_id' => "ALTER TABLE booking ADD COLUMN promotion_id INT NULL AFTER discount_amount",
    ];
    foreach ($columns as $name => $sql) {
        $check = $conn->query("SHOW COLUMNS FROM booking LIKE '{$name}'");
        if ($check && $check->num_rows === 0) $conn->query($sql);
    }
}

function calculatePromotionDiscount(array $promotion, float $originalPrice): float {
    if ($promotion['discount_type'] === 'percent') {
        $discount = $originalPrice * ((float)$promotion['discount_value'] / 100);
    } else {
        $discount = (float)$promotion['discount_value'];
    }
    return round(min($originalPrice, max(0, $discount)), 2);
}

