<?php
define('ARCGEEK_SURVEY', true);
require_once '../config/database.php';
require_once '../config/security.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Plugin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

authenticate_plugin_request();

$user_plan = $_GET['plan'] ?? 'free';

function get_system_setting($key, $default = '') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        error_log("Error getting system setting: " . $e->getMessage());
        return $default;
    }
}

try {
    $message_enabled = get_system_setting('plugin_message_enabled', 0);
    
    if (!$message_enabled) {
        echo json_encode([
            'enabled' => false,
            'message' => null
        ]);
        exit();
    }
    
    $message_show_to = get_system_setting('plugin_message_show_to', 'all');
    
    if ($message_show_to !== 'all' && $message_show_to !== $user_plan) {
        echo json_encode([
            'enabled' => false,
            'message' => null
        ]);
        exit();
    }
    
    $message = [
        'enabled' => true,
        'type' => get_system_setting('plugin_message_type', 'info'),
        'title' => get_system_setting('plugin_message_title', ''),
        'content' => get_system_setting('plugin_message_content', ''),
        'dismissible' => (bool)get_system_setting('plugin_message_dismissible', 1),
        'show_to' => $message_show_to,
        'timestamp' => time()
    ];
    
    if (empty($message['title']) || empty($message['content'])) {
        echo json_encode([
            'enabled' => false,
            'message' => null
        ]);
        exit();
    }
    
    echo json_encode([
        'enabled' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    error_log("Plugin message API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>