<?php
define('ARCGEEK_SURVEY', true);
require_once '../../config/database.php';
require_once '../../config/security.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Plugin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

authenticate_plugin_request();

$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT id, email, password, name, plan_type, supabase_url, supabase_key, postgres_host, postgres_port, postgres_db, postgres_user, postgres_pass, storage_preference FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }
    
    $config = [
        'user_id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'plan_type' => $user['plan_type'],
        'storage_preference' => $user['storage_preference'],
        'postgres' => [
            'host' => decrypt_credential($user['postgres_host']),
            'port' => $user['postgres_port'],
            'database' => decrypt_credential($user['postgres_db']),
            'username' => decrypt_credential($user['postgres_user']),
            'password' => decrypt_credential($user['postgres_pass'])
        ],
        'supabase' => [
            'url' => decrypt_credential($user['supabase_url']),
            'key' => decrypt_credential($user['supabase_key'])
        ]
    ];
    
    echo json_encode($config);
    
} catch (Exception $e) {
    error_log("Plugin config error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
?>