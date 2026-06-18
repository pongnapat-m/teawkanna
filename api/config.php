<?php
// api/config.php
require_once dirname(__DIR__) . '/config/env.php';
// Database configuration (edit these values for your environment)
// Recommended: keep credentials out of version control and use environment vars in production.
$dbHost = (string) env('DB_HOST', '127.0.0.1');
$dbPort = (string) env('DB_PORT', '3306');
$dbName = (string) env('DB_NAME', 'teawkanna');
$dbUser = (string) env('DB_USER', 'root');
$dbPass = (string) env('DB_PASS', '');

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // For security, don't leak DB details in production. Return JSON error for development.
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    error_log('API database connection failed: ' . $e->getMessage());
    $response = ['error' => 'Database connection failed'];
    if (APP_DEBUG) {
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// Helper: simple JSON response helper
function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

?>
