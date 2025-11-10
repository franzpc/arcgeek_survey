<?php
define('ARCGEEK_SURVEY', true);
require_once '../../config/database.php';
require_once '../../config/plans.php';
require_once '../../config/security.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Plugin-Token');

authenticate_plugin_request();

$input = json_decode(file_get_contents('php://input'), true);
$user_id = $input['user_id'] ?? '';
$form_id = $input['form_id'] ?? '';

if (empty($user_id) || empty($form_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID and Form ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$form_id, $user_id]);
    $form = $stmt->fetch();
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("UPDATE forms SET is_active = 0, deleted_at = NOW() WHERE id = ? AND user_id = ?");
    $deleted = $stmt->execute([$form_id, $user_id]);
    
    if ($deleted) {
        update_user_usage($user_id, -1, 0);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Form deleted successfully',
            'form_id' => $form_id
        ]);
    } else {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete form']);
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Plugin delete form error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>