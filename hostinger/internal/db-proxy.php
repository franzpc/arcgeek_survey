<?php
// ============================================================
// ArcGeek Survey — Hostinger MySQL proxy
// Called exclusively by Cloud Run over HTTPS.
// Direct browser access is prevented by .htaccess + bearer token.
// ============================================================
require_once __DIR__ . '/secrets.php';

// -----------------------------------------------------------------
// Bearer-token authentication
// -----------------------------------------------------------------
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m) || !hash_equals(PROXY_SECRET, $m[1])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// -----------------------------------------------------------------
// Parse request
// -----------------------------------------------------------------
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$params = $body['params']  ?? [];

header('Content-Type: application/json');

// -----------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function decrypt_val(string $encrypted): string {
    if (empty($encrypted)) return '';
    $data      = base64_decode($encrypted);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv        = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv) ?: '';
}

// -----------------------------------------------------------------
// Route actions
// -----------------------------------------------------------------
try {
    switch ($action) {

        // ----------------------------------------------------------
        // auth_user — validate plugin login, return full DB config
        // Returns same structure as original get_user_config_for_plugin()
        // ----------------------------------------------------------
        case 'auth_user': {
            $email    = trim($params['email']    ?? '');
            $password = trim($params['password'] ?? '');

            if (empty($email) || empty($password)) {
                echo json_encode(['data' => false]);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT id, email, password, name, plan_type, storage_preference,
                        supabase_url, supabase_key,
                        postgres_host, postgres_port, postgres_db, postgres_user, postgres_pass
                 FROM users
                 WHERE email = ? AND is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                echo json_encode(['data' => false]);
                exit();
            }

            echo json_encode(['data' => [
                'user_id'            => $user['id'],
                'email'              => $user['email'],
                'name'               => $user['name'],
                'plan_type'          => $user['plan_type'],
                'storage_preference' => $user['storage_preference'],
                'postgres' => [
                    'host'     => decrypt_val($user['postgres_host']),
                    'port'     => $user['postgres_port'],
                    'database' => decrypt_val($user['postgres_db']),
                    'username' => decrypt_val($user['postgres_user']),
                    'password' => decrypt_val($user['postgres_pass']),
                ],
                'supabase' => [
                    'url' => decrypt_val($user['supabase_url']),
                    'key' => decrypt_val($user['supabase_key']),
                ],
            ]]);
            break;
        }

        // ----------------------------------------------------------
        // get_form — fetch form metadata by form_code
        // ----------------------------------------------------------
        case 'get_form': {
            $form_code = trim($params['form_code'] ?? '');
            if (empty($form_code)) {
                echo json_encode(['data' => null]);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT id, title, description, form_code, storage_type,
                        table_name, response_count, user_id
                 FROM forms
                 WHERE form_code = ? AND is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$form_code]);
            echo json_encode(['data' => $stmt->fetch() ?: null]);
            break;
        }

        // ----------------------------------------------------------
        // get_connection_config — decrypted DB config for a form
        // ----------------------------------------------------------
        case 'get_connection_config': {
            $form_id = (int)($params['form_id'] ?? 0);
            if ($form_id <= 0) {
                echo json_encode(['data' => null]);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT f.storage_type, f.table_name,
                        u.supabase_url, u.supabase_key,
                        u.postgres_host, u.postgres_port,
                        u.postgres_db, u.postgres_user, u.postgres_pass
                 FROM forms f
                 JOIN users u ON u.id = f.user_id
                 WHERE f.id = ?
                 LIMIT 1"
            );
            $stmt->execute([$form_id]);
            $row = $stmt->fetch();

            if (!$row) {
                echo json_encode(['data' => null]);
                exit();
            }

            // Decrypt before sending — Cloud Run never needs ENCRYPTION_KEY
            $row['supabase_url']   = decrypt_val($row['supabase_url']);
            $row['supabase_key']   = decrypt_val($row['supabase_key']);
            $row['postgres_host']  = decrypt_val($row['postgres_host']);
            $row['postgres_db']    = decrypt_val($row['postgres_db']);
            $row['postgres_user']  = decrypt_val($row['postgres_user']);
            $row['postgres_pass']  = decrypt_val($row['postgres_pass']);

            echo json_encode(['data' => $row]);
            break;
        }

        // ----------------------------------------------------------
        // get_responses_free — admin_supabase storage rows
        // ----------------------------------------------------------
        case 'get_responses_free': {
            $form_id = (int)($params['form_id'] ?? 0);
            if ($form_id <= 0) {
                echo json_encode(['data' => []]);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT unique_display_id, latitude, longitude, accuracy,
                        created_at, data_json
                 FROM responses_free
                 WHERE form_id = ?
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$form_id]);
            echo json_encode(['data' => $stmt->fetchAll()]);
            break;
        }

        // ----------------------------------------------------------
        // validate_api_key — api_key = sha256(user_email . form_code)
        // ----------------------------------------------------------
        case 'validate_api_key': {
            $form_id = (int)($params['form_id'] ?? 0);
            $api_key = trim($params['api_key']  ?? '');

            if ($form_id <= 0 || empty($api_key)) {
                echo json_encode(['valid' => false]);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT u.email, f.form_code
                 FROM users u
                 JOIN forms f ON f.user_id = u.id
                 WHERE f.id = ? AND f.is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$form_id]);
            $row = $stmt->fetch();

            if (!$row) {
                echo json_encode(['valid' => false]);
                exit();
            }

            $expected = hash('sha256', $row['email'] . $row['form_code']);
            echo json_encode(['valid' => hash_equals($expected, $api_key)]);
            break;
        }

        // ----------------------------------------------------------
        // get_plugin_token — fetch live token from Supabase
        // Reads admin Supabase credentials from MySQL admin_config,
        // then calls Supabase sys_auth_configs. Same logic as Hostinger
        // security.php — so token rotation is always transparent.
        // ----------------------------------------------------------
        case 'get_plugin_token': {
            $pdo  = get_pdo();
            $stmt = $pdo->query(
                "SELECT config_key, config_value FROM admin_config
                 WHERE config_key IN ('admin_supabase_url','admin_supabase_key')"
            );
            $configs = [];
            foreach ($stmt->fetchAll() as $row) {
                $configs[$row['config_key']] = $row['config_value'];
            }

            $url = decrypt_val($configs['admin_supabase_url'] ?? '');
            $key = decrypt_val($configs['admin_supabase_key'] ?? '');

            if (empty($url) || empty($key)) {
                echo json_encode(['token' => null]);
                exit();
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => rtrim($url, '/') . '/rest/v1/sys_auth_configs?is_active=eq.true&order=created_at.desc&limit=1',
                CURLOPT_HTTPHEADER     => ["apikey: $key", "Authorization: Bearer $key"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp      = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $token = null;
            if ($http_code === 200) {
                $data  = json_decode($resp, true);
                $token = (!empty($data) && isset($data[0]['country_code'])) ? $data[0]['country_code'] : null;
            }

            echo json_encode(['token' => $token]);
            break;
        }

        // ----------------------------------------------------------
        // get_stats — admin dashboard
        // ----------------------------------------------------------
        case 'get_stats': {
            $pdo = get_pdo();

            $stats_stmt = $pdo->query(
                "SELECT
                    (SELECT COUNT(*) FROM users WHERE is_active = 1)              AS total_users,
                    (SELECT COUNT(*) FROM forms WHERE is_active = 1)              AS total_forms,
                    (SELECT COALESCE(SUM(response_count),0) FROM forms WHERE is_active = 1) AS total_responses"
            );

            $activity_stmt = $pdo->query(
                "SELECT f.title, f.form_code, f.response_count, f.created_at,
                        u.name AS user_name
                 FROM forms f
                 JOIN users u ON u.id = f.user_id
                 WHERE f.is_active = 1
                 ORDER BY f.created_at DESC
                 LIMIT 10"
            );

            echo json_encode([
                'stats'           => $stats_stmt->fetch(),
                'recent_activity' => $activity_stmt->fetchAll(),
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (Exception $e) {
    error_log('db-proxy [' . $action . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Proxy error']);
}
