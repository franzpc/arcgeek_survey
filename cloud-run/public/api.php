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

$action = $_GET['action'] ?? '';

// ------------------------------------------------------------------
// Action: get_config
// Called by QGIS plugin to authenticate and retrieve DB credentials
// ------------------------------------------------------------------
if ($action === 'get_config') {
    authenticate_plugin_request();

    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

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

// ------------------------------------------------------------------
// Action: stats (admin only)
// ------------------------------------------------------------------
if ($action === 'stats' && ($_GET['admin'] ?? '') === '1') {
    $api_key = $_GET['api_key'] ?? '';
    if (!empty(ADMIN_EMAIL) && hash_equals(hash('sha256', ADMIN_EMAIL), $api_key)) {
        echo json_encode([
            'stats'           => get_form_stats(),
            'recent_activity' => get_recent_activity(),
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
    }
    exit();
}

// ------------------------------------------------------------------
// Default: retrieve form + responses (used by QGIS plugin layers)
// ------------------------------------------------------------------
$form_code = trim($_GET['form_code'] ?? '');
$api_key   = trim($_GET['api_key']   ?? '');

if (empty($form_code)) {
    http_response_code(400);
    echo json_encode(['error' => 'Form code required']);
    exit();
}

try {
    $form = get_form_by_code($form_code);

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    if (!empty($api_key) && !validate_api_access_proxy((int) $form['id'], $api_key)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit();
    }

    $form_summary = [
        'title'          => $form['title'],
        'description'    => $form['description'],
        'form_code'      => $form['form_code'],
        'response_count' => $form['response_count'],
    ];

    if ($form['storage_type'] === 'admin_supabase') {
        // Responses stored in Hostinger MySQL — fetched via proxy
        $raw       = get_responses_admin_db((int) $form['id']);
        $responses = array_map(function ($r) {
            $data = json_decode($r['data_json'] ?? '{}', true) ?: [];
            return [
                'id'         => $r['unique_display_id'],
                'latitude'   => $r['latitude'],
                'longitude'  => $r['longitude'],
                'accuracy'   => $r['accuracy'],
                'created_at' => $r['created_at'],
                'data'       => $data,
            ];
        }, $raw);

        echo json_encode(['form' => $form_summary, 'responses' => $responses]);

    } else {
        // Responses stored in user's own Supabase or PostgreSQL
        $config = get_connection_config((int) $form['id']);
        if (!$config) {
            http_response_code(500);
            echo json_encode(['error' => 'Database configuration not found']);
            exit();
        }

        $responses = ($config['storage_type'] === 'user_supabase')
            ? fetch_supabase_responses($config, $form['table_name'])
            : fetch_postgres_responses($config, $form['table_name']);

        echo json_encode(['form' => $form_summary, 'responses' => $responses ?: []]);
    }

} catch (Exception $e) {
    error_log("API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}

// ------------------------------------------------------------------
// Helpers — direct connections to user's own external databases
// (Supabase and PostgreSQL are called from Cloud Run, not proxied)
// ------------------------------------------------------------------
function fetch_supabase_responses(array $config, string $table_name): array {
    $url = rtrim($config['supabase_url'], '/') . '/rest/v1/' . $table_name;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_HTTPHEADER     => [
            "apikey: {$config['supabase_key']}",
            "Authorization: Bearer {$config['supabase_key']}",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,   // SSL enabled — Cloud Run has valid CA bundle
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($http_code === 200) ? (json_decode($response, true) ?: []) : [];
}

function fetch_postgres_responses(array $config, string $table_name): array {
    try {
        $dsn  = "pgsql:host={$config['postgres_host']};port={$config['postgres_port']};dbname={$config['postgres_db']}";
        $pg   = new PDO($dsn, $config['postgres_user'], $config['postgres_pass']);
        $pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
        $stmt  = $pg->prepare("SELECT * FROM {$table} ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log("PostgreSQL query error: " . $e->getMessage());
        return [];
    }
}
