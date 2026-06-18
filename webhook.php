<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/env.php';

define('SUPABASE_URL', (string) env('SUPABASE_URL', ''));
define('SUPABASE_KEY', (string) env('SUPABASE_KEY', ''));

require_once __DIR__ . '/db.php';

$body   = json_decode(file_get_contents('php://input'), true);
$intent = $body['queryResult']['intent']['displayName'] ?? '';
$params = $body['queryResult']['parameters'] ?? [];
$reply  = '';

if (APP_DEBUG) {
    error_log(
        "Dialogflow intent: {$intent}; params: " .
        json_encode($params, JSON_UNESCAPED_UNICODE)
    );
}

switch ($intent) {

    case 'Ask_Activity':
        $name = trim($params['activity_name'] ?? '');
        if (empty($name)) { $reply = "กรุณาระบุชื่อกิจกรรมที่ต้องการค้นหาค่ะ"; break; }

        $searchName = '%' . $name . '%';
        $stmt = $conn->prepare("
            SELECT a.activity_name, a.description, a.adult_price, a.kid_price,
                   a.duration_label, a.suitable_for, s.shop_name,
                   (
                       SELECT COUNT(*) FROM activity ar
                       WHERE ar.shop_id = a.shop_id
                         AND ar.activity_name = a.activity_name
                         AND ar.status = 'Active'
                         AND ar.start_date <= NOW()
                         AND ar.end_date >= NOW()
                         AND ar.end_date != '0000-00-00 00:00:00'
                   ) AS open_rounds
            FROM activity a
            JOIN shop s ON a.shop_id = s.shop_id
            WHERE a.activity_name LIKE ? AND a.status = 'Active'
            LIMIT 1
        ");
        $stmt->bind_param("s", $searchName);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$a) { $reply = "ไม่พบกิจกรรม \"$name\" ค่ะ ลองค้นหาชื่ออื่นได้เลยนะคะ"; break; }

        $roundStatus = $a['open_rounds'] > 0 ? "🟢 เปิดรอบอยู่" : "🔴 ไม่มีรอบเปิด";
        $prices = [];
        if ($a['adult_price'] > 0) $prices[] = "ผู้ใหญ่ " . number_format($a['adult_price'], 0) . " บาท";
        if ($a['kid_price']   > 0) $prices[] = "เด็ก "    . number_format($a['kid_price'],   0) . " บาท";

        $reply  = "📌 {$a['activity_name']}\n";
        $reply .= "ร้าน: {$a['shop_name']}\n\n";
        $reply .= "{$a['description']}\n\n";
        $reply .= "ระยะเวลา: " . ($a['duration_label'] ?: 'ไม่ระบุ') . "\n";
        $reply .= "เหมาะสำหรับ: {$a['suitable_for']}\n";
        $reply .= "ราคา: " . implode(' | ', $prices) . "\n";
        $reply .= "$roundStatus\n\n";
        $reply .= "สนใจจองทักแอดมินหรือจองผ่านเว็บ Tiew Kan Na ได้เลยค่ะ";
        break;

    case 'Recommend_Activity':
        $tagInput   = strtolower(trim($params['activity_tag'] ?? ''));
        $groupInput = strtolower(trim($params['group_type']   ?? ''));

        $tagMap = [
            'ธรรมชาติ'    => 'ธรรมชาติ', 'nature'      => 'ธรรมชาติ',
            'ลงมือทำ'     => 'ลงมือทำ',  'hands-on'    => 'ลงมือทำ', 'workshop' => 'ลงมือทำ',
            'เกษตร'       => 'เกษตร',    'agriculture' => 'เกษตร',
            'การเรียนรู้' => 'การเรียนรู้', 'learning'  => 'การเรียนรู้',
            'วัฒนธรรม'   => 'วัฒนธรรม', 'culture'     => 'วัฒนธรรม',
            'ชุมชน'       => 'ชุมชน',    'community'   => 'ชุมชน',
            'ผจญภัย'      => 'ผจญภัย',   'adventure'   => 'ผจญภัย',
            'เด็ก'        => 'เด็ก',     'kids'        => 'เด็ก',
            'ผู้ใหญ่'     => 'ผู้ใหญ่',  'adults'      => 'ผู้ใหญ่',
        ];

        $groupMap = [
            'ครอบครัว'   => 'Family',  'family'  => 'Family',
            'คู่รัก'     => 'Couples', 'couples' => 'Couples', 'คู่' => 'Couples',
            'ผู้ใหญ่'    => 'Adults',  'adults'  => 'Adults',
            'เด็ก'       => 'Kids',    'kids'    => 'Kids', 'เด็กๆ' => 'Kids',
            'ผู้สูงอายุ' => 'Seniors', 'seniors' => 'Seniors', 'สูงอายุ' => 'Seniors',
        ];

        $matchedTags = [];
        foreach (explode(' ', $tagInput) as $word) {
            $word = trim($word);
            if ($word !== '' && isset($tagMap[$word]) && !in_array($tagMap[$word], $matchedTags)) {
                $matchedTags[] = $tagMap[$word];
            }
        }

        $suitable = $groupMap[$groupInput] ?? '';

        file_put_contents(__DIR__ . '/webhook_debug.txt',
            "  tagInput: $tagInput => matchedTags: " . implode(', ', $matchedTags) . "\n" .
            "  groupInput: $groupInput => suitable: $suitable\n---\n",
            FILE_APPEND
        );

        if (empty($matchedTags) || empty($suitable)) {
            $reply = "ขออภัยค่ะ ไม่เข้าใจคำตอบ ลองพิมพ์ใหม่นะคะ\n"
                   . "ประเภท: ธรรมชาติ / ลงมือทำ / เกษตร / การเรียนรู้ / วัฒนธรรม / ชุมชน / ผจญภัย / เด็ก\n"
                   . "กลุ่ม: ครอบครัว / คู่รัก / ผู้ใหญ่ / เด็กๆ / ผู้สูงอายุ";
            break;
        }

        $placeholders = implode(',', array_fill(0, count($matchedTags), '?'));
        $types        = str_repeat('s', count($matchedTags) + 1);
        $bindValues   = array_merge($matchedTags, [$suitable]);

        $sql = "
            SELECT a.activity_id, a.activity_name, a.adult_price, a.kid_price,
                   a.duration_label, s.shop_name,
                   COUNT(DISTINCT t.tag_id) AS matched_tags,
                   (
                       SELECT COUNT(*) FROM activity ar
                       WHERE ar.shop_id = a.shop_id
                         AND ar.activity_name = a.activity_name
                         AND ar.status = 'Active'
                         AND ar.start_date <= NOW()
                         AND ar.end_date >= NOW()
                         AND ar.end_date != '0000-00-00 00:00:00'
                   ) AS open_rounds
            FROM activity a
            JOIN activity_tag at2 ON a.activity_id = at2.activity_id
            JOIN tag t ON at2.tag_id = t.tag_id AND t.tag_name IN ($placeholders)
            JOIN shop s ON a.shop_id = s.shop_id
            WHERE FIND_IN_SET(?, a.suitable_for) AND a.status = 'Active'
            GROUP BY a.activity_id
            ORDER BY matched_tags DESC, open_rounds DESC
            LIMIT 3
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$bindValues);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $tagLabel = implode(' + ', $matchedTags);

        if (empty($rows)) {
            $reply = "ยังไม่มีกิจกรรม \"$tagLabel\" สำหรับ \"$groupInput\" ในขณะนี้ค่ะ\nลองเลือกประเภทอื่นได้เลยนะคะ";
            break;
        }

        $reply = "กิจกรรมแนะนำสำหรับ $groupInput ($tagLabel)\n\n";
        foreach ($rows as $i => $a) {
            $roundStatus = $a['open_rounds'] > 0 ? "🟢 เปิดรอบอยู่" : "🔴 ไม่มีรอบเปิด";
            $prices = [];
            if ($a['adult_price'] > 0) $prices[] = "ผู้ใหญ่ " . number_format($a['adult_price'], 0) . " บาท";
            if ($a['kid_price']   > 0) $prices[] = "เด็ก "    . number_format($a['kid_price'],   0) . " บาท";

            $reply .= ($i + 1) . ". {$a['activity_name']}\n";
            $reply .= "   ร้าน: {$a['shop_name']}\n";
            $reply .= "   " . ($a['duration_label'] ?: 'ไม่ระบุ') . " | " . implode(' | ', $prices) . "\n";
            $reply .= "   $roundStatus\n\n";
        }
        $reply .= "สนใจจองทักแอดมินหรือจองผ่านเว็บ Tiew Kan Na ได้เลยค่ะ";
        break;

    case 'Check_Booking':
        $phone = preg_replace('/\D/', '', $params['phone_number'] ?? '');
        if (empty($phone)) { $reply = "กรุณาระบุเบอร์โทรศัพท์ค่ะ"; break; }

        $stmt = $conn->prepare("SELECT user_id, fullname FROM user WHERE phonenumber = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $reply = "ไม่พบข้อมูลสมาชิกจากเบอร์ $phone ค่ะ\nกรุณาตรวจสอบว่าเบอร์ตรงกับที่สมัครสมาชิกไว้นะคะ";
            break;
        }

        $uid  = $user['user_id'];
        $name = $user['fullname'];

        $stmt2 = $conn->prepare("
            SELECT b.booking_date, b.total_price, b.status,
                   b.adult_quantity, b.kid_quantity,
                   a.activity_name, s.shop_name
            FROM booking b
            JOIN activity a ON b.activity_id = a.activity_id
            JOIN shop s ON a.shop_id = s.shop_id
            WHERE b.user_id = ?
            ORDER BY b.booking_date DESC
            LIMIT 5
        ");
        $stmt2->bind_param("i", $uid);
        $stmt2->execute();
        $bookings = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();

        if (empty($bookings)) {
            $reply = "สวัสดีคุณ $name ค่ะ ยังไม่พบประวัติการจองในระบบค่ะ";
            break;
        }

        $statusMap = [
            'Pending'       => 'รอชำระเงิน',
            'PendingReview' => 'รอตรวจสอบสลิป',
            'Paid'          => 'ชำระแล้ว',
            'Completed'     => 'เข้าร่วมแล้ว',
            'Cancel'        => 'ยกเลิก',
        ];

        $reply = "การจองของคุณ $name ค่ะ\n\n";
        foreach ($bookings as $i => $b) {
            $st  = $statusMap[$b['status']] ?? $b['status'];
            $pax = [];
            if ($b['adult_quantity'] > 0) $pax[] = "ผู้ใหญ่ {$b['adult_quantity']} คน";
            if ($b['kid_quantity']   > 0) $pax[] = "เด็ก {$b['kid_quantity']} คน";

            $reply .= ($i + 1) . ". {$b['activity_name']}\n";
            $reply .= "   ร้าน: {$b['shop_name']}\n";
            $reply .= "   วันที่: " . substr($b['booking_date'], 0, 10) . "\n";
            $reply .= "   " . implode(' | ', $pax) . "\n";
            $reply .= "   " . number_format($b['total_price'], 0) . " บาท | $st\n\n";
        }
        break;

    case 'Check_Stamp':
        $phone = preg_replace('/\D/', '', $params['phone_number'] ?? '');
        if (empty($phone)) { $reply = "กรุณาระบุเบอร์โทรศัพท์ค่ะ"; break; }

        $stmt = $conn->prepare("SELECT user_id, fullname, point FROM user WHERE phonenumber = ? LIMIT 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $reply = "ไม่พบข้อมูลสมาชิกจากเบอร์ $phone ค่ะ\nกรุณาตรวจสอบเบอร์ที่สมัครสมาชิกไว้นะคะ";
            break;
        }

        $uid   = $user['user_id'];
        $name  = $user['fullname'];
        $point = (int)$user['point'];

        $stmt2 = $conn->prepare("
            SELECT ap.points_earned, ap.earned_at, a.activity_name
            FROM activity_passport ap
            JOIN activity a ON ap.activity_id = a.activity_id
            WHERE ap.user_id = ?
            ORDER BY ap.earned_at DESC
        ");
        $stmt2->bind_param("i", $uid);
        $stmt2->execute();
        $stamps = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();

        $totalStamps   = count($stamps);
        $stampsPerCard = 5;
        $filled        = $totalStamps % $stampsPerCard;
        $needed        = $stampsPerCard - $filled;
        $fullCards     = (int)floor($totalStamps / $stampsPerCard);

        $stampVisual = str_repeat('🟡', $filled) . str_repeat('⚪', $stampsPerCard - $filled);

        $reply  = "Passport ของคุณ $name ค่ะ\n\n";
        $reply .= "คะแนนสะสม: $point แต้ม\n";
        $reply .= "เข้าร่วมกิจกรรมแล้ว: $totalStamps ครั้ง\n\n";
        $reply .= "การ์ดแสตมป์: $stampVisual\n";

        if ($totalStamps === 0) {
            $reply .= "ยังไม่มีแสตมป์ค่ะ\n";
        } elseif ($filled === 0) {
            $reply .= "ครบ $stampsPerCard ดวงแล้ว!\n";
        } else {
            $reply .= "$filled/$stampsPerCard ดวง ขาดอีก $needed ดวง\n";
        }

        if ($fullCards > 0) {
            $reply .= "\nมีสิทธิ์แลกส่วนลด $fullCards สิทธิ์\nแจ้งแอดมินเพื่อใช้สิทธิ์ได้เลยค่ะ\n";
        }

        if ($totalStamps > 0) {
            $reply .= "\nกิจกรรมล่าสุด:\n";
            foreach (array_slice($stamps, 0, 3) as $s) {
                $reply .= "- {$s['activity_name']} (+{$s['points_earned']} แต้ม) " . substr($s['earned_at'], 0, 10) . "\n";
            }
        }

        $reply .= "\nสะสมครบ $stampsPerCard ดวง แลกส่วนลด 10% ได้เลยค่ะ";
        break;

    case 'Check_Promotion':
        $today = date('Y-m-d');

        $stmt = $conn->prepare("
            SELECT title, description, discount_type, discount_value, min_price, end_date
            FROM promotion
            WHERE status = 'Active' AND start_date <= ? AND end_date >= ?
            ORDER BY promotion_id ASC
        ");
        $stmt->bind_param("ss", $today, $today);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($rows)) {
            $reply = "ขณะนี้ยังไม่มีโปรโมชั่นพิเศษค่ะ ติดตามได้ที่เว็บ Tiew Kan Na นะคะ";
            break;
        }

        $reply = "โปรโมชั่นที่ใช้งานได้ตอนนี้ค่ะ\n\n";
        foreach ($rows as $i => $p) {
            $disc = $p['discount_type'] === 'percent'
                ? "ลด {$p['discount_value']}%"
                : "ลด " . number_format($p['discount_value'], 0) . " บาท";

            $reply .= ($i + 1) . ". {$p['title']}\n";
            $reply .= "   {$p['description']}\n";
            $reply .= "   $disc";
            if ($p['min_price'] > 0)
                $reply .= " (ขั้นต่ำ " . number_format($p['min_price'], 0) . " บาท)";
            $reply .= "\n   ใช้ได้ถึง " . $p['end_date'] . "\n\n";
        }
        break;

    case 'Contact_Admin':
        $reply  = "ติดต่อสอบถามได้เลยค่ะ\n\n";
        $reply .= "📱 Line: @teawkanna\n";
        $reply .= "🌐 เว็บไซต์: tiewkanna.com\n\n";
        $reply .= "แอดมินจะรีบตอบกลับโดยเร็วที่สุดค่ะ";
        break;

    default:
        $reply = "ขออภัยค่ะ ไม่เข้าใจคำถามนี้\nลองถามใหม่หรือเลือกเมนูด้านล่างได้เลยนะคะ";
}

echo json_encode(['fulfillmentText' => $reply], JSON_UNESCAPED_UNICODE);

function supabaseGet($path) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, SUPABASE_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true) ?? [];
}
