<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 3600);

$plugin_token_cache = null;
$plugin_token_cache_time = 0;
$plugin_token_cache_duration = 1800;

function get_current_plugin_token() {
    global $plugin_token_cache, $plugin_token_cache_time, $plugin_token_cache_duration;
    
    if ($plugin_token_cache && (time() - $plugin_token_cache_time) < $plugin_token_cache_duration) {
        return $plugin_token_cache;
    }
    
    $admin_config = get_admin_supabase_config();
    if (empty($admin_config['url']) || empty($admin_config['key'])) {
        error_log("Admin Supabase not configured for token retrieval");
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($admin_config['url'], '/') . '/rest/v1/sys_auth_configs?is_active=eq.true&order=created_at.desc&limit=1');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: {$admin_config['key']}", 
        "Authorization: Bearer {$admin_config['key']}"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        if (!empty($data) && isset($data[0]['country_code'])) {
            $plugin_token_cache = $data[0]['country_code'];
            $plugin_token_cache_time = time();
            return $plugin_token_cache;
        }
    }
    
    error_log("Failed to retrieve plugin token from Supabase. HTTP: $http_code");
    return false;
}

function validate_plugin_token($token) {
    $current_token = get_current_plugin_token();
    return $current_token && hash_equals($current_token, $token);
}

function authenticate_plugin_request() {
    $token = $_SERVER['HTTP_X_PLUGIN_TOKEN'] ?? '';
    
    if (!validate_plugin_token($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid plugin token']);
        exit();
    }
}

function validate_session() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['last_activity'])) {
        return false;
    }
    
    if (time() - $_SESSION['last_activity'] > 3600) {
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

function get_client_ip() {
    $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    return '0.0.0.0';
}

function sanitize_input($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_recaptcha($response, $action = 'submit') {
    $secret_key = '6Lec8YIrAAAAACU9v1xZgNSn0lTEp8EWfLmwTQfw';
    
    $data = [
        'secret' => $secret_key,
        'response' => $response,
        'remoteip' => get_client_ip()
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $result = json_decode($response, true);
        $score = $result['score'] ?? 0;
        $success = $result['success'] ?? false;
        $action_match = ($result['action'] ?? '') === $action;
        
        return $success && $action_match && $score >= 0.5;
    }
    
    return false;
}

function generate_form_code() {
    return 'FORM-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function generate_verification_token() {
    return bin2hex(random_bytes(32));
}

function log_login_attempt($email, $ip, $success) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$email, $ip, $success]);
}

function check_login_attempts($email, $ip) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts 
                          WHERE (email = ? OR ip_address = ?) 
                          AND success = 0 
                          AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$email, $ip]);
    
    return $stmt->fetchColumn() < 5;
}

function check_registration_attempts($ip) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users 
                          WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute();
    
    return $stmt->fetchColumn() < 3;
}

function validate_supabase_url($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    $parsed = parse_url($url);
    return isset($parsed['host']) && strpos($parsed['host'], '.supabase.') !== false;
}

function validate_supabase_key($key) {
    return !empty($key) && strlen($key) > 50;
}

function test_supabase_connection($url, $key) {
    $headers = [
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($url, '/') . '/rest/v1/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

function test_postgres_connection($host, $port, $db, $user, $pass) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function generate_table_name($form_title) {
    $clean = preg_replace('/[^a-zA-Z0-9]/', '_', strtolower($form_title));
    $clean = substr($clean, 0, 15);
    $clean = trim($clean, '_');
    
    $counter = sprintf("%05d", rand(1, 99999));
    return 'survey_arcgeek_' . $counter;
}

function create_supabase_table($url, $key, $table_name, $fields) {
    $columns = [
        'id SERIAL PRIMARY KEY',
        'unique_display_id VARCHAR(20)',
        'latitude DECIMAL(10,8)',
        'longitude DECIMAL(11,8)',
        'gps_accuracy DECIMAL(10,2)',
        'geom GEOMETRY(POINT, 4326)',
        'created_at TIMESTAMP DEFAULT NOW()'
    ];
    
    foreach ($fields as $field) {
        $type = match($field['type']) {
            'number' => 'DECIMAL(10,2)',
            'textarea' => 'TEXT',
            'date' => 'DATE',
            default => 'VARCHAR(255)'
        };
        $columns[] = "{$field['name']} {$type}";
    }
    
    $sql = "CREATE TABLE {$table_name} (" . implode(', ', $columns) . ")";
    
    return execute_supabase_sql($url, $key, $sql);
}

function create_postgres_table($host, $port, $db, $user, $pass, $table_name, $fields) {
    try {
        $dsn = "pgsql:host=$host;port=$port;dbname=$db";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $columns = [
            'id SERIAL PRIMARY KEY',
            'unique_display_id VARCHAR(20)',
            'latitude DECIMAL(10,8)',
            'longitude DECIMAL(11,8)',
            'gps_accuracy DECIMAL(10,2)',
            'geom GEOMETRY(POINT, 4326)',
            'created_at TIMESTAMP DEFAULT NOW()'
        ];
        
        foreach ($fields as $field) {
            $type = match($field['type']) {
                'number' => 'DECIMAL(10,2)',
                'textarea' => 'TEXT',
                'date' => 'DATE',
                default => 'VARCHAR(255)'
            };
            $columns[] = "{$field['name']} {$type}";
        }
        
        $sql = "CREATE TABLE {$table_name} (" . implode(', ', $columns) . ")";
        $pdo->exec($sql);
        
        return true;
    } catch (Exception $e) {
        error_log("PostgreSQL table creation error: " . $e->getMessage());
        return false;
    }
}

function execute_supabase_sql($url, $key, $sql) {
    $payload = json_encode(['query' => $sql]);
    
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($url, '/') . '/rest/v1/rpc/exec');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

function send_verification_email($email, $name, $token, $lang = 'en') {
    $subject = $lang === 'es' ? 'Verificar tu cuenta - ArcGeek Survey' : 'Verify your account - ArcGeek Survey';
    $verify_url = "https://" . $_SERVER['HTTP_HOST'] . "/survey/auth/verify.php?token=" . $token;
    
    $message = $lang === 'es' ? 
        "Hola $name,\n\nGracias por registrarte en ArcGeek Survey.\n\nPor favor verifica tu cuenta haciendo clic en el siguiente enlace:\n$verify_url\n\nEste enlace expira en 24 horas.\n\nSaludos,\nEquipo ArcGeek Survey" :
        "Hello $name,\n\nThank you for registering with ArcGeek Survey.\n\nPlease verify your account by clicking the following link:\n$verify_url\n\nThis link expires in 24 hours.\n\nBest regards,\nArcGeek Survey Team";
    
    $headers = [
        'From: noreply@' . $_SERVER['HTTP_HOST'],
        'Reply-To: noreply@' . $_SERVER['HTTP_HOST'],
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    return mail($email, $subject, $message, implode("\r\n", $headers));
}

function validate_table_name($table_name) {
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,63}$/', $table_name);
}

function validate_field_name($field_name) {
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,30}$/', $field_name);
}

function validate_coordinates($latitude, $longitude) {
    return (
        is_numeric($latitude) && 
        is_numeric($longitude) && 
        $latitude >= -90 && $latitude <= 90 && 
        $longitude >= -180 && $longitude <= 180
    );
}

function clean_expired_sessions() {
    global $pdo;
    
    if (rand(1, 100) <= 5) {
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $pdo->exec("DELETE FROM email_verifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
}

function get_plugin_user_auth($email, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, email, password, name, plan_type, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user || !$user['is_active'] || !password_verify($password, $user['password'])) {
            return false;
        }
        
        return [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'plan_type' => $user['plan_type']
        ];
        
    } catch (Exception $e) {
        error_log("Plugin auth error: " . $e->getMessage());
        return false;
    }
}

function log_plugin_access($user_id, $action, $ip = null) {
    global $pdo;
    
    try {
        $ip = $ip ?: get_client_ip();
        $stmt = $pdo->prepare("INSERT INTO plugin_access_log (user_id, action, ip_address, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$user_id, $action, $ip]);
    } catch (Exception $e) {
        error_log("Plugin log error: " . $e->getMessage());
    }
}

clean_expired_sessions();
?>