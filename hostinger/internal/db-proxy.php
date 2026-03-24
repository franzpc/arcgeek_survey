<?php
// ============================================================
// ArcGeek Survey — Hostinger MySQL proxy
// Called exclusively by Cloud Run api.php over HTTPS.
// Direct browser access is blocked by .htaccess.
// ============================================================

// Secrets are NOT in Git. Upload secrets.php manually to Hostinger.
require_once __DIR__ . '/secrets.php';

// -----------------------------------------------------------------
// Authentication — bearer token must match PROXY_SECRET
// -----------------------------------------------------------------
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing authorization']);
    exit();
}
if (!hash_equals(PROXY_SECRET, $m[1])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid proxy secret']);
    exit();
}

// -----------------------------------------------------------------
// Parse JSON body
// -----------------------------------------------------------------
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$params = $body['params']  ?? [];

// -----------------------------------------------------------------
// DB connection (lazy — only opened when needed)
// -----------------------------------------------------------------
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

header('Content-Type: application/json');

// -----------------------------------------------------------------
// Route actions
// -----------------------------------------------------------------
try {
    switch ($action) {

        // ----------------------------------------------------------
        // auth_user — validate plugin login, return DB config
        // ----------------------------------------------------------
        case 'auth_user': {
            $email    = trim($params['email']    ?? '');
            $password = trim($params['password'] ?? '');

            if (empty($email) || empty($password)) {
                echo json_encode(['error' => 'Missing credentials']);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT u.id, u.email, u.password_hash, u.plan,
                        uc.storage_type, uc.supabase_url, uc.supabase_key,
                        uc.postgres_host, uc.postgres_port, uc.postgres_db,
                        uc.postgres_user, uc.postgres_pass
                 FROM users u
                 LEFT JOIN user_connections uc ON uc.user_id = u.id
                 WHERE u.email = ? AND u.is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                echo json_encode(['data' => false]);
                exit();
            }

            // Return only what the plugin needs
            $data = [
                'user_id'      => $user['id'],
                'email'        => $user['email'],
                'plan'         => $user['plan'],
                'storage_type' => $user['storage_type'],
            ];
            echo json_encode(['data' => $data]);
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
            $form = $stmt->fetch() ?: null;
            echo json_encode(['data' => $form]);
            break;
        }

        // ----------------------------------------------------------
        // get_connection_config — encrypted credentials for a form
        // ----------------------------------------------------------
        case 'get_connection_config': {
            $form_id = (int)($params['form_id'] ?? 0);
            if ($form_id <= 0) {
                echo json_encode(['data' => null]);
                exit();
            }

            $pdo  = get_pdo();
            $stmt = $pdo->prepare(
                "SELECT uc.storage_type,
                        uc.supabase_url, uc.supabase_key,
                        uc.postgres_host, uc.postgres_port, uc.postgres_db,
                        uc.postgres_user, uc.postgres_pass,
                        f.table_name
                 FROM user_connections uc
                 INNER JOIN forms f ON f.user_id = uc.user_id
                 WHERE f.id = ?
                 LIMIT 1"
            );
            $stmt->execute([$form_id]);
            $config = $stmt->fetch() ?: null;
            echo json_encode(['data' => $config]);
            break;
        }

        // ----------------------------------------------------------
        // get_responses_free — admin_supabase storage (MySQL rows)
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
                 FROM responses
                 WHERE form_id = ?
                 ORDER BY created_at DESC"
            );
            $stmt->execute([$form_id]);
            echo json_encode(['data' => $stmt->fetchAll()]);
            break;
        }

        // ----------------------------------------------------------
        // validate_api_key — check api_key for a form
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
                "SELECT api_key FROM forms WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$form_id]);
            $row = $stmt->fetch();

            $valid = $row && hash_equals($row['api_key'], $api_key);
            echo json_encode(['valid' => $valid]);
            break;
        }

        // ----------------------------------------------------------
        // get_stats — admin dashboard stats
        // ----------------------------------------------------------
        case 'get_stats': {
            $pdo = get_pdo();

            $stats_stmt = $pdo->query(
                "SELECT
                    (SELECT COUNT(*) FROM users  WHERE is_active = 1) AS total_users,
                    (SELECT COUNT(*) FROM forms  WHERE is_active = 1) AS total_forms,
                    (SELECT COALESCE(SUM(response_count),0) FROM forms WHERE is_active = 1) AS total_responses"
            );
            $stats = $stats_stmt->fetch();

            $activity_stmt = $pdo->query(
                "SELECT f.title, f.form_code, f.response_count, f.updated_at,
                        u.email AS owner_email
                 FROM forms f
                 INNER JOIN users u ON u.id = f.user_id
                 WHERE f.is_active = 1
                 ORDER BY f.updated_at DESC
                 LIMIT 10"
            );

            echo json_encode([
                'stats'           => $stats,
                'recent_activity' => $activity_stmt->fetchAll(),
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }

} catch (Exception $e) {
    error_log('db-proxy error [' . $action . ']: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Proxy internal error']);
}
