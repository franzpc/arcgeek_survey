<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

$db_config = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'dbname' => 'u220080920_arcgeek_survey',
    'username' => 'u220080920_arcgeek_survey',
    'password' => 'Arcgeek2025_Survey17',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
];

define('ENCRYPTION_KEY', 'ArcGeek2025_Secret_Key_For_Database_Credentials_Encryption');

try {
    $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
    $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], $db_config['options']);
} catch (PDOException $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed");
}

function encrypt_credential($data) {
    if (empty($data)) return '';
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt_credential($encrypted_data) {
    if (empty($encrypted_data)) return '';
    $data = base64_decode($encrypted_data);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}

function get_admin_supabase_config() {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM admin_config WHERE config_key IN ('admin_supabase_url', 'admin_supabase_key')");
    $stmt->execute();
    $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    return [
        'url' => decrypt_credential($configs['admin_supabase_url'] ?? ''),
        'key' => decrypt_credential($configs['admin_supabase_key'] ?? '')
    ];
}

function get_connection_config($form_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT f.storage_type, f.table_name, 
               u.supabase_url, u.supabase_key,
               u.postgres_host, u.postgres_port, u.postgres_db, 
               u.postgres_user, u.postgres_pass
        FROM forms f 
        JOIN users u ON f.user_id = u.id 
        WHERE f.id = ?
    ");
    $stmt->execute([$form_id]);
    $config = $stmt->fetch();
    
    if ($config) {
        $config['supabase_url'] = decrypt_credential($config['supabase_url']);
        $config['supabase_key'] = decrypt_credential($config['supabase_key']);
        $config['postgres_host'] = decrypt_credential($config['postgres_host']);
        $config['postgres_db'] = decrypt_credential($config['postgres_db']);
        $config['postgres_user'] = decrypt_credential($config['postgres_user']);
        $config['postgres_pass'] = decrypt_credential($config['postgres_pass']);
    }
    
    return $config;
}

function has_database_config($user) {
    $has_supabase = !empty(decrypt_credential($user['supabase_url'])) && !empty(decrypt_credential($user['supabase_key']));
    $has_postgres = !empty(decrypt_credential($user['postgres_host'])) && !empty(decrypt_credential($user['postgres_db'])) && !empty(decrypt_credential($user['postgres_user']));
    
    return $has_supabase || $has_postgres;
}

function validate_table_exists($user, $table_name) {
    $storage_pref = $user['storage_preference'];
    $has_postgres = !empty(decrypt_credential($user['postgres_host']));
    
    if ($storage_pref === 'postgres' && $has_postgres) {
        return validate_postgres_table_exists($user, $table_name);
    } else {
        return validate_supabase_table_exists($user, $table_name);
    }
}

function validate_supabase_table_exists($user, $table_name) {
    $url = decrypt_credential($user['supabase_url']);
    $key = decrypt_credential($user['supabase_key']);
    
    if (empty($url) || empty($key)) {
        return false;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($url, '/') . '/rest/v1/' . $table_name . '?select=id&limit=1');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: {$key}", 
        "Authorization: Bearer {$key}"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 200;
}

function get_supabase_tables($user) {
    $url = decrypt_credential($user['supabase_url']);
    $key = decrypt_credential($user['supabase_key']);
    
    if (empty($url) || empty($key)) {
        return ['error' => 'Supabase configuration missing'];
    }
    
    $table_url = rtrim($url, '/') . '/rest/v1/?select=table_name';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $table_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: {$key}", 
        "Authorization: Bearer {$key}"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $tables = json_decode($response, true);
        return is_array($tables) ? $tables : [];
    }
    
    return ['error' => 'Failed to get tables'];
}

function validate_postgres_table_exists($user, $table_name) {
    try {
        $host = decrypt_credential($user['postgres_host']);
        $port = $user['postgres_port'];
        $db = decrypt_credential($user['postgres_db']);
        $username = decrypt_credential($user['postgres_user']);
        $password = decrypt_credential($user['postgres_pass']);
        
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
        $pg_pdo = new PDO($dsn, $username, $password);
        $pg_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pg_pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = ? LIMIT 1");
        $stmt->execute([$table_name]);
        return $stmt->fetch() !== false;
        
    } catch (Exception $e) {
        error_log("PostgreSQL table validation error: " . $e->getMessage());
        return false;
    }
}

function save_response($form_id, $data, $latitude = null, $longitude = null, $accuracy = null, $ip_address = null) {
    $config = get_connection_config($form_id);
    if (!$config) return false;
    
    $unique_id = generate_unique_id($form_id);
    
    switch ($config['storage_type']) {
        case 'admin_supabase':
            return save_response_admin_db($form_id, $unique_id, $data, $latitude, $longitude, $accuracy, $ip_address);
            
        case 'user_supabase':
            return save_response_supabase($config, $unique_id, $data, $latitude, $longitude, $accuracy);
            
        case 'user_postgres':
            return save_response_postgres($config, $unique_id, $data, $latitude, $longitude, $accuracy);
            
        default:
            return false;
    }
}

function save_response_admin_db($form_id, $unique_id, $data, $latitude, $longitude, $accuracy, $ip_address) {
    global $pdo;
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO responses_free (form_id, unique_display_id, data_json, latitude, longitude, accuracy, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $result = $stmt->execute([$form_id, $unique_id, json_encode($data), $latitude, $longitude, $accuracy, $ip_address]);
        
        if ($result) {
            $stmt = $pdo->prepare("UPDATE forms SET response_count = response_count + 1 WHERE id = ?");
            $stmt->execute([$form_id]);
        }
        
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error saving to admin DB: " . $e->getMessage());
        return false;
    }
}

function save_response_supabase($config, $unique_id, $data, $latitude, $longitude, $accuracy) {
    global $pdo;
    
    $url = rtrim($config['supabase_url'], '/') . '/rest/v1/' . $config['table_name'];
    
    $payload = array_merge($data, [
        'unique_display_id' => $unique_id,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'gps_accuracy' => $accuracy
    ]);
    
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $config['supabase_key'],
        'Authorization: Bearer ' . $config['supabase_key']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        error_log("Supabase curl error: " . $curl_error);
        return false;
    }
    
    if ($http_code === 201) {
        try {
            $stmt = $pdo->prepare("UPDATE forms SET response_count = response_count + 1 WHERE table_name = ?");
            $stmt->execute([$config['table_name']]);
        } catch (Exception $e) {
            error_log("Error updating response count: " . $e->getMessage());
        }
        return true;
    }
    
    error_log("Supabase response error: HTTP $http_code - $response");
    return false;
}

function save_response_postgres($config, $unique_id, $data, $latitude, $longitude, $accuracy) {
    try {
        $dsn = "pgsql:host={$config['postgres_host']};port={$config['postgres_port']};dbname={$config['postgres_db']}";
        $pg_pdo = new PDO($dsn, $config['postgres_user'], $config['postgres_pass']);
        $pg_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $config['table_name']);
        
        $columns = ['unique_display_id', 'latitude', 'longitude', 'gps_accuracy'];
        $values = [$unique_id, $latitude, $longitude, $accuracy];
        
        foreach ($data as $key => $value) {
            $clean_key = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            $columns[] = $clean_key;
            $values[] = $value;
        }
        
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        $columns_str = implode(',', $columns);
        
        $stmt = $pg_pdo->prepare("INSERT INTO $table_name ($columns_str) VALUES ($placeholders)");
        $result = $stmt->execute($values);
        
        if ($result) {
            global $pdo;
            $stmt = $pdo->prepare("UPDATE forms SET response_count = response_count + 1 WHERE table_name = ?");
            $stmt->execute([$config['table_name']]);
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Error saving to PostgreSQL: " . $e->getMessage());
        return false;
    }
}

function get_responses($form_id, $limit = 100, $offset = 0) {
    $config = get_connection_config($form_id);
    if (!$config) return [];
    
    switch ($config['storage_type']) {
        case 'admin_supabase':
            return get_responses_admin_db($form_id, $limit, $offset);
            
        case 'user_supabase':
            return get_responses_supabase($config, $limit, $offset);
            
        case 'user_postgres':
            return get_responses_postgres($config, $limit, $offset);
            
        default:
            return [];
    }
}

function get_responses_admin_db($form_id, $limit, $offset) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT unique_display_id, data_json, latitude, longitude, accuracy, created_at 
        FROM responses_free 
        WHERE form_id = ? 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$form_id, $limit, $offset]);
    
    $responses = [];
    while ($row = $stmt->fetch()) {
        $data = json_decode($row['data_json'], true);
        $data['unique_display_id'] = $row['unique_display_id'];
        $data['latitude'] = $row['latitude'];
        $data['longitude'] = $row['longitude'];
        $data['gps_accuracy'] = $row['accuracy'];
        $data['created_at'] = $row['created_at'];
        $responses[] = $data;
    }
    
    return $responses;
}

function get_responses_supabase($config, $limit, $offset) {
    $url = rtrim($config['supabase_url'], '/') . '/rest/v1/' . $config['table_name'] . 
           "?select=*&order=created_at.desc&limit=$limit&offset=$offset";
    
    $headers = [
        'apikey: ' . $config['supabase_key'],
        'Authorization: Bearer ' . $config['supabase_key']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true) ?: [];
    }
    
    return [];
}

function get_responses_postgres($config, $limit, $offset) {
    try {
        $dsn = "pgsql:host={$config['postgres_host']};port={$config['postgres_port']};dbname={$config['postgres_db']}";
        $pg_pdo = new PDO($dsn, $config['postgres_user'], $config['postgres_pass']);
        
        $table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $config['table_name']);
        
        $stmt = $pg_pdo->prepare("SELECT * FROM $table_name ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error getting PostgreSQL responses: " . $e->getMessage());
        return [];
    }
}

function generate_unique_id($form_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT response_count FROM forms WHERE id = ?");
        $stmt->execute([$form_id]);
        $form = $stmt->fetch();
        
        $next_num = ($form['response_count'] ?? 0) + 1;
        return 'AS_' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
    } catch (Exception $e) {
        error_log("Error generating unique ID: " . $e->getMessage());
        return 'AS_' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
}

function get_user_by_id($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function get_form_by_code($form_code) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE form_code = ? AND is_active = 1");
    $stmt->execute([$form_code]);
    return $stmt->fetch();
}

function get_user_config_for_plugin($email, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, email, password, name, plan_type, supabase_url, supabase_key, postgres_host, postgres_port, postgres_db, postgres_user, postgres_pass, storage_preference FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        
        return [
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
        
    } catch (Exception $e) {
        error_log("Plugin config error: " . $e->getMessage());
        return false;
    }
}

function validate_plugin_credentials($email, $password) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        return $user && password_verify($password, $user['password']);
        
    } catch (Exception $e) {
        return false;
    }
}

function get_user_database_config($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT postgres_host, postgres_port, postgres_db, postgres_user, postgres_pass, supabase_url, supabase_key, storage_preference FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) return null;
        
        return [
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
            ],
            'storage_preference' => $user['storage_preference']
        ];
        
    } catch (Exception $e) {
        error_log("Database config error: " . $e->getMessage());
        return null;
    }
}
?>