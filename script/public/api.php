<?php
define('ARCGEEK_SURVEY', true);
require_once '../config/database.php';
require_once '../config/security.php';
require_once '../config/plans.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Plugin-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'get_config') {
    authenticate_plugin_request();
    
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        exit();
    }
    
    $config = get_user_config_for_plugin($email, $password);
    
    if ($config) {
        echo json_encode($config);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
    }
    exit();
}

if ($action === 'stats' && $_GET['admin'] === '1') {
    $api_key = $_GET['api_key'] ?? '';
    if ($api_key === hash('sha256', 'franzpc@gmail.com')) {
        echo json_encode([
            'stats' => get_form_stats(),
            'recent_activity' => get_recent_activity()
        ]);
        exit();
    }
}

$form_code = $_GET['form_code'] ?? '';
$api_key = $_GET['api_key'] ?? '';

if (empty($form_code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Form code required']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE form_code = ? AND is_active = 1");
    $stmt->execute([$form_code]);
    $form = $stmt->fetch();
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }
    
    if (!empty($api_key) && !validate_api_access($form, $api_key)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit();
    }
    
    if ($form['storage_type'] === 'admin_supabase') {
        $stmt = $pdo->prepare("SELECT * FROM responses_free WHERE form_id = ? ORDER BY created_at DESC");
        $stmt->execute([$form['id']]);
        $responses = $stmt->fetchAll();
        
        $formatted_responses = [];
        foreach ($responses as $response) {
            $data = json_decode($response['data_json'], true);
            $formatted_responses[] = [
                'id' => $response['unique_display_id'],
                'latitude' => $response['latitude'],
                'longitude' => $response['longitude'],
                'accuracy' => $response['accuracy'],
                'created_at' => $response['created_at'],
                'data' => $data
            ];
        }
        
        echo json_encode([
            'form' => [
                'title' => $form['title'],
                'description' => $form['description'],
                'form_code' => $form['form_code'],
                'response_count' => $form['response_count']
            ],
            'responses' => $formatted_responses
        ]);
        
    } else {
        $config = get_connection_config($form['id']);
        if (!$config) {
            http_response_code(500);
            echo json_encode(['error' => 'Database configuration not found']);
            exit();
        }
        
        if ($config['storage_type'] === 'user_supabase') {
            $responses = get_supabase_responses($config, $form['table_name']);
        } else {
            $responses = get_postgres_responses($config, $form['table_name']);
        }
        
        echo json_encode([
            'form' => [
                'title' => $form['title'],
                'description' => $form['description'],
                'form_code' => $form['form_code'],
                'response_count' => $form['response_count']
            ],
            'responses' => $responses ?: []
        ]);
    }
    
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

function validate_api_access($form, $api_key) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT u.email FROM users u JOIN forms f ON u.id = f.user_id WHERE f.id = ?");
    $stmt->execute([$form['id']]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return false;
    }
    
    $expected_key = hash('sha256', $user['email'] . $form['form_code']);
    return hash_equals($expected_key, $api_key);
}

function get_form_stats() {
    global $pdo;
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_forms FROM forms WHERE is_active = 1");
    $stats['total_forms'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT SUM(response_count) as total_responses FROM forms WHERE is_active = 1");
    $stats['total_responses'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) as active_users FROM forms WHERE is_active = 1");
    $stats['active_users'] = $stmt->fetchColumn();
    
    return $stats;
}

function get_recent_activity() {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT f.title, f.form_code, f.response_count, f.created_at, u.name as user_name
        FROM forms f 
        JOIN users u ON f.user_id = u.id 
        WHERE f.is_active = 1 
        ORDER BY f.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    
    return $stmt->fetchAll();
}

function get_supabase_responses($config, $table_name) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($config['supabase_url'], '/') . '/rest/v1/' . $table_name);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: {$config['supabase_key']}", 
        "Authorization: Bearer {$config['supabase_key']}"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    }
    
    return false;
}

function get_postgres_responses($config, $table_name) {
    try {
        $dsn = "pgsql:host={$config['postgres_host']};port={$config['postgres_port']};dbname={$config['postgres_db']}";
        $pg_pdo = new PDO($dsn, $config['postgres_user'], $config['postgres_pass']);
        
        $stmt = $pg_pdo->prepare("SELECT * FROM " . $table_name . " ORDER BY created_at DESC");
        $stmt->execute();
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("PostgreSQL query error: " . $e->getMessage());
        return false;
    }
}
?>