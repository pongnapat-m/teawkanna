<?php
require_once __DIR__ . '/config/env.php';

define('LINE_CHANNEL_SECRET',       (string) env('LINE_CHANNEL_SECRET', ''));
define('LINE_CHANNEL_ACCESS_TOKEN', (string) env('LINE_CHANNEL_ACCESS_TOKEN', ''));

$input  = file_get_contents('php://input');
$events = json_decode($input, true)['events'] ?? [];

foreach ($events as $event) {
    if ($event['type'] !== 'message' || $event['message']['type'] !== 'text') continue;

    $user_message = $event['message']['text'];
    $reply_token  = $event['replyToken'];
    $user_id      = $event['source']['userId'] ?? 'default_user';

    $reply_text = queryDialogflow($user_message, $user_id);
    replyToLine($reply_token, $reply_text);
}

// ✅ แก้จุดที่ 1: รับ $user_id เป็น parameter ที่ 2
function queryDialogflow($message, $user_id) {
    $project_id   = (string) env('DIALOGFLOW_PROJECT_ID', '');
    $session_id   = 'line-' . $user_id; // ✅ แก้จุดที่ 2: ใช้ user_id แทน uniqid()
    $access_token = getGoogleAccessToken();

    if (APP_DEBUG) {
        error_log("LINE message session: " . $session_id);
    }

    if (!$access_token) return "ขออภัยค่ะ ระบบขัดข้องชั่วคราว";

    $url = "https://dialogflow.googleapis.com/v2/projects/{$project_id}/agent/sessions/{$session_id}:detectIntent";

    $payload = json_encode([
        "queryInput" => [
            "text" => [
                "text"         => $message,
                "languageCode" => "th"
            ]
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $access_token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        error_log('Dialogflow request failed: ' . $curl_err);
    }

    $data = json_decode($response, true);
    return $data['queryResult']['fulfillmentText'] ?? "ขออภัยค่ะ ไม่เข้าใจคำถามนี้";
}

function getGoogleAccessToken() {
    $key_file = (string) env(
        'GOOGLE_APPLICATION_CREDENTIALS',
        __DIR__ . '/service_account.json'
    );
    if (!file_exists($key_file)) return null;

    $key_data  = json_decode(file_get_contents($key_file), true);
    $now       = time();
    $exp       = $now + 3600;

    $header    = base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim     = base64url(json_encode([
        'iss'   => $key_data['client_email'],
        'scope' => 'https://www.googleapis.com/auth/dialogflow',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $exp
    ]));

    $sig_input = $header . '.' . $claim;
    openssl_sign($sig_input, $signature, $key_data['private_key'], 'SHA256');
    $jwt = $sig_input . '.' . base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $res = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode($res, true);
    return $token_data['access_token'] ?? null;
}

function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function replyToLine($reply_token, $text) {
    $data = json_encode([
        "replyToken" => $reply_token,
        "messages"   => [["type" => "text", "text" => $text]]
    ]);

    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_exec($ch);
    curl_close($ch);
}
?>
