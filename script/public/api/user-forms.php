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

if (empty($user_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, title, description, form_code, response_count, max_responses, storage_type, table_name, created_at FROM forms WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $forms = $stmt->fetchAll();
    
    $base_url = "https://" . $_SERVER['HTTP_HOST'] . "/survey";
    
    $response = [];
    foreach ($forms as $form) {
        $response[] = [
            'id' => $form['id'],
            'title' => $form['title'],
            'description' => $form['description'],
            'form_code' => $form['form_code'],
            'response_count' => $form['response_count'],
            'max_responses' => $form['max_responses'],
            'storage_type' => $form['storage_type'],
            'table_name' => $form['table_name'],
            'created_at' => $form['created_at'],
            'collection_url' => $base_url . "/public/collect.php?code=" . $form['form_code']
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Plugin forms error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>