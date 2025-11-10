<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/plans.php';
require_once '../config/security.php';

if (!validate_session()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user = get_user_by_id($user_id);
if (!$user) {
    header('Location: ../auth/logout.php');
    exit();
}

$lang = $user['language'];
$strings = include "../lang/{$lang}.php";

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $language = $_POST['language'] ?? 'en';
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        
        if (empty($name) || empty($email)) {
            $error = $strings['all_fields_required'];
        } elseif (!validate_email($email)) {
            $error = $strings['invalid_email'];
        } elseif (!empty($new_password) && empty($current_password)) {
            $error = $strings['current_password_required'];
        } elseif (!empty($new_password) && !password_verify($current_password, $user['password'])) {
            $error = $strings['invalid_credentials'];
        } else {
            try {
                if ($email !== $user['email']) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $user_id]);
                    if ($stmt->fetch()) {
                        $error = $strings['email_exists'];
                    }
                }
                
                if (empty($error)) {
                    if (!empty($new_password)) {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, language = ?, password = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $language, $hashed_password, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, language = ? WHERE id = ?");
                        $stmt->execute([$name, $email, $language, $user_id]);
                    }
                    
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_name'] = $name;
                    $user = get_user_by_id($user_id);
                    $message = $strings['settings_saved'];
                    
                    if ($language !== $lang) {
                        header('Location: settings.php');
                        exit();
                    }
                }
            } catch (Exception $e) {
                error_log("Profile update error: " . $e->getMessage());
                $error = $strings['server_error'];
            }
        }
    }
    
    if ($action === 'database') {
        $supabase_url = trim($_POST['supabase_url'] ?? '');
        $supabase_key = trim($_POST['supabase_key'] ?? '');
        $postgres_host = trim($_POST['postgres_host'] ?? '');
        $postgres_port = intval($_POST['postgres_port'] ?? 5432);
        $postgres_db = trim($_POST['postgres_db'] ?? '');
        $postgres_user = trim($_POST['postgres_user'] ?? '');
        $postgres_pass = $_POST['postgres_pass'] ?? '';
        $storage_preference = $_POST['storage_preference'] ?? 'supabase';
        
        $supabase_valid = false;
        $postgres_valid = false;
        $connection_warnings = [];
        
        if (!empty($supabase_url) && !empty($supabase_key)) {
            if (!validate_supabase_url($supabase_url) || !validate_supabase_key($supabase_key)) {
                $connection_warnings[] = 'Supabase: Invalid credentials format';
            } elseif (!test_supabase_connection($supabase_url, $supabase_key)) {
                $connection_warnings[] = 'Supabase: Connection failed - credentials saved but not validated';
            } else {
                $supabase_valid = true;
            }
        }
        
        if (!empty($postgres_host) && !empty($postgres_db) && !empty($postgres_user)) {
            if (!test_postgres_connection($postgres_host, $postgres_port, $postgres_db, $postgres_user, $postgres_pass)) {
                $connection_warnings[] = 'PostgreSQL: Connection failed - credentials saved but not validated';
            } else {
                $postgres_valid = true;
            }
        }
        
        try {
            $encrypted_supabase_url = encrypt_credential($supabase_url);
            $encrypted_supabase_key = encrypt_credential($supabase_key);
            $encrypted_postgres_host = encrypt_credential($postgres_host);
            $encrypted_postgres_db = encrypt_credential($postgres_db);
            $encrypted_postgres_user = encrypt_credential($postgres_user);
            $encrypted_postgres_pass = encrypt_credential($postgres_pass);
            
            $stmt = $pdo->prepare("UPDATE users SET supabase_url = ?, supabase_key = ?, postgres_host = ?, postgres_port = ?, postgres_db = ?, postgres_user = ?, postgres_pass = ?, storage_preference = ? WHERE id = ?");
            $stmt->execute([
                $encrypted_supabase_url, 
                $encrypted_supabase_key, 
                $encrypted_postgres_host, 
                $postgres_port, 
                $encrypted_postgres_db, 
                $encrypted_postgres_user, 
                $encrypted_postgres_pass, 
                $storage_preference, 
                $user_id
            ]);
            
            $user = get_user_by_id($user_id);
            
            $success_message = $strings['database_config_saved'];
            
            if ($user['plan_type'] === PLAN_FREE && ($supabase_valid || $postgres_valid)) {
                if (auto_upgrade_user($user_id)) {
                    $user = get_user_by_id($user_id);
                    $success_message .= ' - ' . $strings['auto_upgraded_to_basic'];
                }
            }
            
            if (!empty($connection_warnings)) {
                $message = $success_message . '<br><small class="text-warning"><i class="fas fa-exclamation-triangle"></i> ' . implode('<br>', $connection_warnings) . '</small>';
            } else {
                $message = $success_message;
            }
            
        } catch (Exception $e) {
            error_log("Database config error: " . $e->getMessage());
            $error = $strings['server_error'];
        }
    }
    
    if ($action === 'test_connection') {
        $type = $_POST['type'] ?? '';
        $result = false;
        $tables = [];
        
        if ($type === 'supabase') {
            $url = trim($_POST['test_supabase_url'] ?? '');
            $key = trim($_POST['test_supabase_key'] ?? '');
            if (!empty($url) && !empty($key)) {
                $result = test_supabase_connection($url, $key);
                if ($result) {
                    $tables = get_supabase_tables_list($url, $key);
                }
            }
        } elseif ($type === 'postgres') {
            $host = trim($_POST['test_postgres_host'] ?? '');
            $port = intval($_POST['test_postgres_port'] ?? 5432);
            $db = trim($_POST['test_postgres_db'] ?? '');
            $user_name = trim($_POST['test_postgres_user'] ?? '');
            $pass = $_POST['test_postgres_pass'] ?? '';
            if (!empty($host) && !empty($db) && !empty($user_name)) {
                $result = test_postgres_connection($host, $port, $db, $user_name, $pass);
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $result,
            'tables' => $tables,
            'error' => $result ? null : 'Connection failed'
        ]);
        exit();
    }
    
    if ($action === 'clear_database') {
        $db_type = $_POST['db_type'] ?? '';
        
        try {
            if ($db_type === 'supabase') {
                $stmt = $pdo->prepare("UPDATE users SET supabase_url = '', supabase_key = '' WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = 'Supabase configuration cleared';
            } elseif ($db_type === 'postgres') {
                $stmt = $pdo->prepare("UPDATE users SET postgres_host = '', postgres_port = 5432, postgres_db = '', postgres_user = '', postgres_pass = '' WHERE id = ?");
                $stmt->execute([$user_id]);
                $message = 'PostgreSQL configuration cleared';
            }
            
            $user = get_user_by_id($user_id);
        } catch (Exception $e) {
            error_log("Clear database config error: " . $e->getMessage());
            $error = $strings['server_error'];
        }
    }
}

function get_supabase_tables_list($url, $key) {
    $tables_url = rtrim($url, '/') . '/rest/v1/';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tables_url);
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
        $data = json_decode($response, true);
        if (isset($data['paths'])) {
            $tables = [];
            foreach ($data['paths'] as $path => $info) {
                if (strpos($path, '/') === 0) {
                    $table = substr($path, 1);
                    if (!empty($table) && !in_array($table, ['rpc', 'auth'])) {
                        $tables[] = $table;
                    }
                }
            }
            return array_slice($tables, 0, 10);
        }
    }
    
    return [];
}

function check_database_connections($user) {
    $status = [
        'supabase' => 'none',
        'postgres' => 'none'
    ];
    
    $supabase_url = decrypt_credential($user['supabase_url']);
    $supabase_key = decrypt_credential($user['supabase_key']);
    
    if (!empty($supabase_url) && !empty($supabase_key)) {
        if (test_supabase_connection($supabase_url, $supabase_key)) {
            $status['supabase'] = 'connected';
        } else {
            $status['supabase'] = 'configured';
        }
    }
    
    $postgres_host = decrypt_credential($user['postgres_host']);
    $postgres_db = decrypt_credential($user['postgres_db']);
    $postgres_user_name = decrypt_credential($user['postgres_user']);
    
    if (!empty($postgres_host) && !empty($postgres_db) && !empty($postgres_user_name)) {
        $postgres_pass = decrypt_credential($user['postgres_pass']);
        if (test_postgres_connection($postgres_host, $user['postgres_port'], $postgres_db, $postgres_user_name, $postgres_pass)) {
            $status['postgres'] = 'connected';
        } else {
            $status['postgres'] = 'configured';
        }
    }
    
    return $status;
}

$limits = get_plan_limits($user['plan_type']);
$usage = get_user_usage($user_id);
$db_status = check_database_connections($user);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $strings['settings']; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-map-marked-alt"></i> ArcGeek Survey
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><?php echo $strings['settings']; ?></h2>
            <a href="index.php" class="btn btn-outline-secondary"><?php echo $strings['dashboard']; ?></a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h6><i class="fas fa-info-circle"></i> <?php echo $strings['plan_info']; ?></h6>
                    </div>
                    <div class="card-body">
                        <h5 class="text-primary"><?php echo get_plan_display_name($user['plan_type'], $lang); ?></h5>
                        <ul class="list-unstyled mb-0">
                            <li><i class="fas fa-wpforms"></i> <?php echo $strings['forms']; ?>: <?php echo $usage['forms_count']; ?> / <?php echo $limits['forms_limit'] == -1 ? '∞' : $limits['forms_limit']; ?></li>
                            <li><i class="fas fa-list"></i> <?php echo $strings['max_fields']; ?>: <?php echo $limits['fields_limit']; ?></li>
                            <li><i class="fas fa-reply-all"></i> <?php echo $strings['responses_per_form']; ?>: <?php echo $limits['responses_limit']; ?></li>
                            <?php if (has_spatial_features($user['plan_type'])): ?>
                                <li><i class="fas fa-map"></i> <?php echo $strings['spatial_features']; ?>: <span class="text-success"><?php echo $strings['enabled']; ?></span></li>
                            <?php endif; ?>
                        </ul>
                        
                        <?php if ($user['plan_type'] === PLAN_FREE): ?>
                            <hr>
                            <div class="alert alert-info alert-sm">
                                <small>
                                    <i class="fas fa-arrow-up"></i> Configure your database to automatically upgrade to Basic Plan with spatial features!
                                </small>
                            </div>
                        <?php elseif (can_upgrade_plan($user['plan_type'])): ?>
                            <hr>
                            <small class="text-muted">
                                <i class="fas fa-arrow-up"></i> <?php echo $strings['upgrade_available']; ?>: <?php echo get_plan_display_name(can_upgrade_plan($user['plan_type']), $lang); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-database"></i> <?php echo $strings['database_status']; ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-2 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-<?php 
                                    echo $db_status['supabase'] === 'connected' ? 'check-circle text-success' : 
                                        ($db_status['supabase'] === 'configured' ? 'exclamation-triangle text-warning' : 'times-circle text-muted'); 
                                ?>"></i>
                                Supabase
                                <?php if ($db_status['supabase'] === 'configured'): ?>
                                    <small class="text-warning">(saved, not connected)</small>
                                <?php endif; ?>
                            </div>
                            <?php if ($db_status['supabase'] !== 'none'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="clear_database">
                                    <input type="hidden" name="db_type" value="supabase">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Clear Supabase configuration?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="mb-2 d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-<?php 
                                    echo $db_status['postgres'] === 'connected' ? 'check-circle text-success' : 
                                        ($db_status['postgres'] === 'configured' ? 'exclamation-triangle text-warning' : 'times-circle text-muted'); 
                                ?>"></i>
                                PostgreSQL
                                <?php if ($db_status['postgres'] === 'configured'): ?>
                                    <small class="text-warning">(saved, not connected)</small>
                                <?php endif; ?>
                            </div>
                            <?php if ($db_status['postgres'] !== 'none'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="clear_database">
                                    <input type="hidden" name="db_type" value="postgres">
                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Clear PostgreSQL configuration?')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($db_status['supabase'] === 'none' && $db_status['postgres'] === 'none'): ?>
                            <div class="alert alert-warning alert-sm mt-2">
                                <small>Using shared database (admin Supabase)</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profile" type="button">
                            <i class="fas fa-user"></i> <?php echo $strings['profile']; ?>
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#database" type="button">
                            <i class="fas fa-database"></i> <?php echo $strings['database_config']; ?>
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="profile">
                        <div class="card">
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="profile">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['name']; ?> *</label>
                                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['email']; ?> *</label>
                                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><?php echo $strings['language']; ?></label>
                                        <select name="language" class="form-select">
                                            <option value="en" <?php echo $user['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="es" <?php echo $user['language'] === 'es' ? 'selected' : ''; ?>>Español</option>
                                        </select>
                                    </div>

                                    <hr>
                                    <h6><?php echo $strings['change_password']; ?></h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['current_password']; ?></label>
                                                <input type="password" name="current_password" class="form-control">
                                                <small class="text-muted"><?php echo $strings['required_to_change']; ?></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['new_password']; ?></label>
                                                <input type="password" name="new_password" class="form-control">
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> <?php echo $strings['save_changes']; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="database">
                        <div class="card">
                            <div class="card-body">
                                <?php if ($user['plan_type'] === PLAN_FREE): ?>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-rocket"></i> Upgrade to Basic Automatically</h6>
                                        <p class="mb-0">Configure your own database to automatically upgrade to Basic Plan with spatial features and more forms! <a href="https://youtu.be/YJYIe8pjcrE" target="_blank" rel="noopener noreferrer">Watch tutorial video</a></p>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-info-circle"></i> Database Configuration</h6>
                                        <p class="mb-0">Configure your own Supabase or PostgreSQL database with PostGIS extension for enhanced features.</p>
                                    </div>
                                <?php endif; ?>

                                <form method="POST">
                                    <input type="hidden" name="action" value="database">
                                    
                                    <div class="mb-4">
                                        <label class="form-label"><?php echo $strings['preferred_database']; ?></label>
                                        <select name="storage_preference" class="form-select">
                                            <option value="supabase" <?php echo $user['storage_preference'] === 'supabase' ? 'selected' : ''; ?>>Supabase (<?php echo $strings['recommended']; ?>)</option>
                                            <option value="postgres" <?php echo $user['storage_preference'] === 'postgres' ? 'selected' : ''; ?>>PostgreSQL</option>
                                        </select>
                                    </div>

                                    <h6><i class="fas fa-database"></i> Supabase Configuration</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['supabase_url']; ?></label>
                                                <input type="url" name="supabase_url" class="form-control" value="<?php echo htmlspecialchars(decrypt_credential($user['supabase_url'])); ?>" placeholder="https://xxx.supabase.co">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['supabase_key']; ?></label>
                                                <input type="password" name="supabase_key" class="form-control" value="<?php echo htmlspecialchars(decrypt_credential($user['supabase_key'])); ?>" placeholder="eyJ...">
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-info btn-sm mb-4" onclick="testConnection('supabase')">
                                        <i class="fas fa-plug"></i> <?php echo $strings['test_connection']; ?>
                                    </button>

                                    <hr>
                                    <h6><i class="fas fa-server"></i> PostgreSQL Configuration</h6>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['host']; ?></label>
                                                <input type="text" name="postgres_host" class="form-control" value="<?php echo htmlspecialchars(decrypt_credential($user['postgres_host'])); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['port']; ?></label>
                                                <input type="number" name="postgres_port" class="form-control" value="<?php echo $user['postgres_port'] ?: 5432; ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['database_name']; ?></label>
                                                <input type="text" name="postgres_db" class="form-control" value="<?php echo htmlspecialchars(decrypt_credential($user['postgres_db'])); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['username']; ?></label>
                                                <input type="text" name="postgres_user" class="form-control" value="<?php echo htmlspecialchars(decrypt_credential($user['postgres_user'])); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><?php echo $strings['password']; ?></label>
                                                <input type="password" name="postgres_pass" class="form-control" value="<?php echo htmlspecialchars(decrypt_credential($user['postgres_pass'])); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-outline-info btn-sm mb-4" onclick="testConnection('postgres')">
                                        <i class="fas fa-plug"></i> <?php echo $strings['test_connection']; ?>
                                    </button>

                                    <hr>
                                    <div class="alert alert-warning">
                                        <small><i class="fas fa-info-circle"></i> Credentials are encrypted and saved regardless of connection status. Test connections to verify they work properly.</small>
                                    </div>
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save"></i> Save Configuration
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function testConnection(type) {
            const formData = new FormData();
            formData.append('action', 'test_connection');
            formData.append('type', type);
            
            if (type === 'supabase') {
                const urlField = document.querySelector('input[name="supabase_url"]');
                const keyField = document.querySelector('input[name="supabase_key"]');
                
                if (!urlField.value || !keyField.value) {
                    alert('Please enter both URL and API Key');
                    return;
                }
                
                formData.append('test_supabase_url', urlField.value);
                formData.append('test_supabase_key', keyField.value);
            } else {
                const fields = ['postgres_host', 'postgres_port', 'postgres_db', 'postgres_user', 'postgres_pass'];
                for (let field of fields) {
                    const element = document.querySelector(`input[name="${field}"]`);
                    if (element) {
                        formData.append(`test_${field}`, element.value);
                    }
                }
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Connection successful!');
                } else {
                    alert('❌ Connection failed. You can still save the credentials and configure them later.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Error testing connection. You can still save the credentials.');
            });
        }
    </script>
</body>
</html>