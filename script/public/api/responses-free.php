<?php
define('ARCGEEK_SURVEY', true);
require_once '../../config/database.php';
require_once '../../config/security.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Plugin-Token');

authenticate_plugin_request();

$user_id = $_GET['user_id'] ?? '';
$limit = min(intval($_GET['limit'] ?? 1000), 5000);
$offset = intval($_GET['offset'] ?? 0);

if (empty($user_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT rf.unique_display_id, rf.data_json, rf.latitude, rf.longitude, rf.accuracy, rf.created_at, f.title, f.form_code
        FROM responses_free rf
        JOIN forms f ON rf.form_id = f.id
        WHERE f.user_id = ? AND f.is_active = 1
        ORDER BY rf.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user_id, $limit, $offset]);
    
    $responses = [];
    while ($row = $stmt->fetch()) {
        $data = json_decode($row['data_json'], true);
        $responses[] = [
            'unique_display_id' => $row['unique_display_id'],
            'form_title' => $row['title'],
            'form_code' => $row['form_code'],
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'accuracy' => $row['accuracy'],
            'created_at' => $row['created_at'],
            'data' => $data
        ];
    }
    
    echo json_encode($responses);
    
} catch (Exception $e) {
    error_log("Plugin responses error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>