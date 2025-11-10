<?php
define('ARCGEEK_SURVEY', true);
require_once '../../config/database.php';
require_once '../../config/plans.php';
require_once '../../config/security.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Plugin-Token');

$plugin_token = $_SERVER['HTTP_X_PLUGIN_TOKEN'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// Get token from database (with fallback to hardcoded for backwards compatibility)
$valid_token = get_plugin_auth_token();

if ($plugin_token !== $valid_token) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid plugin token']);
    exit();
}

$user_id = $input['user_id'] ?? '';
$title = $input['title'] ?? '';
$description = $input['description'] ?? '';
$fields = $input['fields'] ?? [];
$table_name = $input['table_name'] ?? '';

if (empty($user_id) || empty($title) || empty($fields)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit();
}

try {
    $user = get_user_by_id($user_id);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit();
    }
    
    if (!validate_form_creation($user_id, $user['plan_type'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Form limit reached']);
        exit();
    }
    
    if (!validate_field_count($user['plan_type'], count($fields))) {
        $limits = get_plan_limits($user['plan_type']);
        http_response_code(403);
        echo json_encode(['error' => 'Max fields: ' . $limits['fields_limit']]);
        exit();
    }
    
    $form_code = generate_form_code();
    $max_responses = get_max_responses_for_plan($user['plan_type']);
    $storage_type = get_storage_type($user);
    
    if ($storage_type === 'admin_supabase') {
        $table_name = 'responses_free';
    }
    
    $stmt = $pdo->prepare("INSERT INTO forms (user_id, title, description, form_code, fields_config, storage_type, table_name, max_responses) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$user_id, $title, $description, $form_code, json_encode($fields), $storage_type, $table_name, $max_responses])) {
        $form_id = $pdo->lastInsertId();
        update_user_usage($user_id, 1, 0);
        
        $base_url = "https://" . $_SERVER['HTTP_HOST'] . "/survey";
        
        echo json_encode([
            'success' => true,
            'form_id' => $form_id,
            'form_code' => $form_code,
            'collection_url' => $base_url . "/public/collect.php?code=" . $form_code,
            'storage_type' => $storage_type,
            'table_name' => $table_name
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create form']);
    }
    
} catch (Exception $e) {
    error_log("Plugin create form error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>