<?php
// api/get_activity.php
// Returns JSON details for a single activity_id
require_once __DIR__ . '/config.php';

// Allow same-origin requests; adjust CORS if you call from other domains.
header('Access-Control-Allow-Origin: *');

$activityId = isset($_GET['activity_id']) ? trim($_GET['activity_id']) : null;
if (empty($activityId) || !ctype_digit($activityId)) {
    jsonResponse(['error' => 'invalid_activity_id'], 400);
}

try {
    $stmt = $pdo->prepare('SELECT * FROM activity WHERE activity_id = :id LIMIT 1');
    $stmt->execute([':id' => $activityId]);
    $activity = $stmt->fetch();

    if (!$activity) {
        jsonResponse(['error' => 'not_found'], 404);
    }

    // Normalize/convert numeric fields to numbers
    $activity['adult_price'] = isset($activity['adult_price']) ? (int)$activity['adult_price'] : 0;
    $activity['kid_price'] = isset($activity['kid_price']) ? (int)$activity['kid_price'] : 0;

    jsonResponse($activity);
} catch (Exception $e) {
    jsonResponse(['error' => 'query_failed', 'message' => $e->getMessage()], 500);
}

?>
