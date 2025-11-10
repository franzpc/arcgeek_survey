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

$user = get_user_by_id($_SESSION['user_id']);
if (!$user || ($user['role'] !== 'admin' && $user['email'] !== 'franzpc@gmail.com')) {
    header('Location: ../dashboard/');
    exit();
}

if ($user['email'] === 'franzpc@gmail.com' && $user['role'] !== 'admin') {
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user['role'] = 'admin';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_cleanup_settings':
            $unverified_days = intval($_POST['unverified_cleanup_days'] ?? 7);
            $inactive_user_days = intval($_POST['inactive_user_days'] ?? 365);
            $unused_form_days = intval($_POST['unused_form_days'] ?? 180);
            $auto_cleanup_enabled = isset($_POST['auto_cleanup_enabled']) ? 1 : 0;
            
            if ($unverified_days < 1 || $unverified_days > 365) {
                $error = 'Unverified cleanup days must be between 1 and 365';
            } elseif ($inactive_user_days < 30 || $inactive_user_days > 1825) {
                $error = 'Inactive user days must be between 30 and 1825 (5 years)';
            } elseif ($unused_form_days < 30 || $unused_form_days > 1095) {
                $error = 'Unused form days must be between 30 and 1095 (3 years)';
            } else {
                try {
                    $settings = [
                        ['cleanup_unverified_days', $unverified_days],
                        ['cleanup_inactive_users_days', $inactive_user_days],
                        ['cleanup_unused_forms_days', $unused_form_days],
                        ['auto_cleanup_enabled', $auto_cleanup_enabled]
                    ];
                    
                    foreach ($settings as [$key, $value]) {
                        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                        $stmt->execute([$key, $value]);
                    }
                    
                    $message = 'Cleanup settings updated successfully';
                } catch (Exception $e) {
                    error_log("System settings error: " . $e->getMessage());
                    $error = 'Error updating settings';
                }
            }
            break;
            
        case 'run_cleanup_now':
            $cleanup_type = $_POST['cleanup_type'] ?? '';
            
            switch ($cleanup_type) {
                case 'unverified':
                    $count = cleanup_unverified_users();
                    $message = "Cleaned up $count unverified users";
                    break;
                    
                case 'inactive':
                    $count = cleanup_inactive_users();
                    $message = "Cleaned up $count inactive users";
                    break;
                    
                case 'forms':
                    $count = cleanup_unused_forms();
                    $message = "Cleaned up $count unused forms";
                    break;
                    
                case 'all':
                    $unverified = cleanup_unverified_users();
                    $inactive = cleanup_inactive_users();
                    $forms = cleanup_unused_forms();
                    $total = $unverified + $inactive + $forms;
                    $message = "Full cleanup completed: $unverified unverified users, $inactive inactive users, $forms unused forms (Total: $total)";
                    break;
                    
                default:
                    $error = 'Invalid cleanup type';
            }
            break;
            
        case 'update_email_settings':
            $smtp_enabled = isset($_POST['smtp_enabled']) ? 1 : 0;
            $smtp_host = trim($_POST['smtp_host'] ?? '');
            $smtp_port = intval($_POST['smtp_port'] ?? 587);
            $smtp_username = trim($_POST['smtp_username'] ?? '');
            $smtp_password = $_POST['smtp_password'] ?? '';
            $from_email = trim($_POST['from_email'] ?? '');
            $from_name = trim($_POST['from_name'] ?? 'ArcGeek Survey');
            
            try {
                $email_settings = [
                    ['smtp_enabled', $smtp_enabled],
                    ['smtp_host', $smtp_host],
                    ['smtp_port', $smtp_port],
                    ['smtp_username', $smtp_username],
                    ['smtp_password', encrypt_credential($smtp_password)],
                    ['from_email', $from_email],
                    ['from_name', $from_name]
                ];
                
                foreach ($email_settings as [$key, $value]) {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                    $stmt->execute([$key, $value]);
                }
                
                $message = 'Email settings updated successfully';
            } catch (Exception $e) {
                error_log("Email settings error: " . $e->getMessage());
                $error = 'Error updating email settings';
            }
            break;
            
        case 'update_plugin_message':
            $plugin_message_enabled = isset($_POST['plugin_message_enabled']) ? 1 : 0;
            $plugin_message_type = $_POST['plugin_message_type'] ?? 'info';
            $plugin_message_title = trim($_POST['plugin_message_title'] ?? '');
            $plugin_message_content = trim($_POST['plugin_message_content'] ?? '');
            $plugin_message_dismissible = isset($_POST['plugin_message_dismissible']) ? 1 : 0;
            $plugin_message_show_to = $_POST['plugin_message_show_to'] ?? 'all';
            
            if ($plugin_message_enabled && (empty($plugin_message_title) || empty($plugin_message_content))) {
                $error = 'Message title and content are required when enabled';
            } else {
                try {
                    $message_settings = [
                        ['plugin_message_enabled', $plugin_message_enabled],
                        ['plugin_message_type', $plugin_message_type],
                        ['plugin_message_title', $plugin_message_title],
                        ['plugin_message_content', $plugin_message_content],
                        ['plugin_message_dismissible', $plugin_message_dismissible],
                        ['plugin_message_show_to', $plugin_message_show_to]
                    ];
                    
                    foreach ($message_settings as [$key, $value]) {
                        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
                        $stmt->execute([$key, $value]);
                    }
                    
                    $message = 'Plugin message settings updated successfully';
                } catch (Exception $e) {
                    error_log("Plugin message settings error: " . $e->getMessage());
                    $error = 'Error updating plugin message settings';
                }
            }
            break;
    }
}

function get_system_setting($key, $default = '') {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetchColumn();
    
    return $result !== false ? $result : $default;
}

function cleanup_unverified_users() {
    global $pdo;
    
    $days = get_system_setting('cleanup_unverified_days', 7);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
    }
    
    return $count;
}

function cleanup_inactive_users() {
    global $pdo;
    
    $days = get_system_setting('cleanup_inactive_users_days', 365);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL ? DAY) AND email != 'franzpc@gmail.com'");
    $stmt->execute([$days]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $stmt = $pdo->prepare("DELETE FROM users WHERE last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL ? DAY) AND email != 'franzpc@gmail.com'");
        $stmt->execute([$days]);
    }
    
    return $count;
}

function cleanup_unused_forms() {
    global $pdo;
    
    $days = get_system_setting('cleanup_unused_forms_days', 180);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $stmt = $pdo->prepare("UPDATE forms SET is_active = 0, deleted_at = NOW() WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
    }
    
    return $count;
}

$cleanup_settings = [
    'unverified_days' => get_system_setting('cleanup_unverified_days', 7),
    'inactive_user_days' => get_system_setting('cleanup_inactive_users_days', 365),
    'unused_form_days' => get_system_setting('cleanup_unused_forms_days', 180),
    'auto_cleanup_enabled' => get_system_setting('auto_cleanup_enabled', 0)
];

$email_settings = [
    'smtp_enabled' => get_system_setting('smtp_enabled', 0),
    'smtp_host' => get_system_setting('smtp_host', ''),
    'smtp_port' => get_system_setting('smtp_port', 587),
    'smtp_username' => get_system_setting('smtp_username', ''),
    'smtp_password' => decrypt_credential(get_system_setting('smtp_password', '')),
    'from_email' => get_system_setting('from_email', ''),
    'from_name' => get_system_setting('from_name', 'ArcGeek Survey')
];

$plugin_message_settings = [
    'enabled' => get_system_setting('plugin_message_enabled', 0),
    'type' => get_system_setting('plugin_message_type', 'info'),
    'title' => get_system_setting('plugin_message_title', ''),
    'content' => get_system_setting('plugin_message_content', ''),
    'dismissible' => get_system_setting('plugin_message_dismissible', 1),
    'show_to' => get_system_setting('plugin_message_show_to', 'all')
];

$cleanup_preview = [
    'unverified_users' => get_cleanup_preview('unverified'),
    'inactive_users' => get_cleanup_preview('inactive'),
    'unused_forms' => get_cleanup_preview('forms')
];

function get_cleanup_preview($type) {
    global $pdo;
    
    switch ($type) {
        case 'unverified':
            $days = get_system_setting('cleanup_unverified_days', 7);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$days]);
            return $stmt->fetchColumn();
            
        case 'inactive':
            $days = get_system_setting('cleanup_inactive_users_days', 365);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL ? DAY) AND email != 'franzpc@gmail.com'");
            $stmt->execute([$days]);
            return $stmt->fetchColumn();
            
        case 'forms':
            $days = get_system_setting('cleanup_unused_forms_days', 180);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$days]);
            return $stmt->fetchColumn();
            
        default:
            return 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - ArcGeek Survey Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-cogs"></i> System Settings
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">Admin Panel</a>
                <a class="nav-link" href="analytics.php">Analytics</a>
                <a class="nav-link" href="../dashboard/">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8">
                <h2>System Administration Settings</h2>
                <p class="text-muted">Configure automatic cleanup and system maintenance</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-broom"></i> Automatic Cleanup Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_cleanup_settings">
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auto_cleanup_enabled" id="autoCleanup" <?php echo $cleanup_settings['auto_cleanup_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="autoCleanup">
                                        <strong>Enable Automatic Cleanup</strong>
                                    </label>
                                    <div class="form-text">When enabled, cleanup will run automatically via cron job</div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Unverified Users Cleanup</label>
                                        <div class="input-group">
                                            <input type="number" name="unverified_cleanup_days" class="form-control" value="<?php echo $cleanup_settings['unverified_days']; ?>" min="1" max="365" required>
                                            <span class="input-group-text">days</span>
                                        </div>
                                        <div class="form-text">Delete unverified accounts after X days</div>
                                        <small class="text-warning">Current candidates: <?php echo $cleanup_preview['unverified_users']; ?> users</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Inactive Users Cleanup</label>
                                        <div class="input-group">
                                            <input type="number" name="inactive_user_days" class="form-control" value="<?php echo $cleanup_settings['inactive_user_days']; ?>" min="30" max="1825" required>
                                            <span class="input-group-text">days</span>
                                        </div>
                                        <div class="form-text">Delete users inactive for X days</div>
                                        <small class="text-warning">Current candidates: <?php echo $cleanup_preview['inactive_users']; ?> users</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="form-label">Unused Forms Cleanup</label>
                                        <div class="input-group">
                                            <input type="number" name="unused_form_days" class="form-control" value="<?php echo $cleanup_settings['unused_form_days']; ?>" min="30" max="1095" required>
                                            <span class="input-group-text">days</span>
                                        </div>
                                        <div class="form-text">Archive forms with 0 responses after X days</div>
                                        <small class="text-warning">Current candidates: <?php echo $cleanup_preview['unused_forms']; ?> forms</small>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Cleanup Settings
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-play-circle"></i> Manual Cleanup Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Warning:</strong> These actions cannot be undone. Make sure you have backups before proceeding.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Clean up unverified users? This action cannot be undone.')">
                                    <input type="hidden" name="action" value="run_cleanup_now">
                                    <input type="hidden" name="cleanup_type" value="unverified">
                                    <button type="submit" class="btn btn-outline-warning mb-2 w-100">
                                        <i class="fas fa-user-times"></i> Clean Unverified Users (<?php echo $cleanup_preview['unverified_users']; ?>)
                                    </button>
                                </form>

                                <form method="POST" class="d-inline" onsubmit="return confirm('Clean up inactive users? This action cannot be undone.')">
                                    <input type="hidden" name="action" value="run_cleanup_now">
                                    <input type="hidden" name="cleanup_type" value="inactive">
                                    <button type="submit" class="btn btn-outline-warning mb-2 w-100">
                                        <i class="fas fa-user-clock"></i> Clean Inactive Users (<?php echo $cleanup_preview['inactive_users']; ?>)
                                    </button>
                                </form>
                            </div>
                            
                            <div class="col-md-6">
                                <form method="POST" class="d-inline" onsubmit="return confirm('Clean up unused forms? This action cannot be undone.')">
                                    <input type="hidden" name="action" value="run_cleanup_now">
                                    <input type="hidden" name="cleanup_type" value="forms">
                                    <button type="submit" class="btn btn-outline-warning mb-2 w-100">
                                        <i class="fas fa-file-times"></i> Clean Unused Forms (<?php echo $cleanup_preview['unused_forms']; ?>)
                                    </button>
                                </form>

                                <form method="POST" class="d-inline" onsubmit="return confirm('Run full cleanup? This will clean ALL categories and cannot be undone.')">
                                    <input type="hidden" name="action" value="run_cleanup_now">
                                    <input type="hidden" name="cleanup_type" value="all">
                                    <button type="submit" class="btn btn-danger mb-2 w-100">
                                        <i class="fas fa-broom"></i> Full Cleanup
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-plugin"></i> Plugin Message Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Configure a custom message that will be displayed to users in the QGIS plugin's main tab.
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="update_plugin_message">
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="plugin_message_enabled" id="pluginMessageEnabled" <?php echo $plugin_message_settings['enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="pluginMessageEnabled">
                                        <strong>Enable Plugin Message</strong>
                                    </label>
                                    <div class="form-text">Show custom message in QGIS plugin</div>
                                </div>
                            </div>

                            <div id="pluginMessageSettings" style="<?php echo $plugin_message_settings['enabled'] ? '' : 'display: none;'; ?>">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Message Type</label>
                                            <select name="plugin_message_type" class="form-select">
                                                <option value="info" <?php echo $plugin_message_settings['type'] === 'info' ? 'selected' : ''; ?>>Information (Blue)</option>
                                                <option value="success" <?php echo $plugin_message_settings['type'] === 'success' ? 'selected' : ''; ?>>Success (Green)</option>
                                                <option value="warning" <?php echo $plugin_message_settings['type'] === 'warning' ? 'selected' : ''; ?>>Warning (Yellow)</option>
                                                <option value="error" <?php echo $plugin_message_settings['type'] === 'error' ? 'selected' : ''; ?>>Error (Red)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Show Message To</label>
                                            <select name="plugin_message_show_to" class="form-select">
                                                <option value="all" <?php echo $plugin_message_settings['show_to'] === 'all' ? 'selected' : ''; ?>>All Users</option>
                                                <option value="free" <?php echo $plugin_message_settings['show_to'] === 'free' ? 'selected' : ''; ?>>Free Plan Users Only</option>
                                                <option value="basic" <?php echo $plugin_message_settings['show_to'] === 'basic' ? 'selected' : ''; ?>>Basic Plan Users Only</option>
                                                <option value="premium" <?php echo $plugin_message_settings['show_to'] === 'premium' ? 'selected' : ''; ?>>Premium Plan Users Only</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Message Title</label>
                                    <input type="text" name="plugin_message_title" class="form-control" value="<?php echo htmlspecialchars($plugin_message_settings['title']); ?>" placeholder="Important Announcement" maxlength="100">
                                    <div class="form-text">Maximum 100 characters</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Message Content</label>
                                    <textarea name="plugin_message_content" class="form-control" rows="4" placeholder="Your message content here..." maxlength="500"><?php echo htmlspecialchars($plugin_message_settings['content']); ?></textarea>
                                    <div class="form-text">Maximum 500 characters. Supports basic HTML tags: &lt;b&gt;, &lt;i&gt;, &lt;u&gt;, &lt;br&gt;, &lt;a&gt;</div>
                                </div>

                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="plugin_message_dismissible" id="pluginMessageDismissible" <?php echo $plugin_message_settings['dismissible'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="pluginMessageDismissible">
                                            Allow users to dismiss the message
                                        </label>
                                        <div class="form-text">If unchecked, message will always be visible</div>
                                    </div>
                                </div>

                                <?php if ($plugin_message_settings['enabled']): ?>
                                    <div class="alert alert-secondary">
                                        <h6><i class="fas fa-eye"></i> Preview:</h6>
                                        <div class="alert alert-<?php echo $plugin_message_settings['type']; ?> mb-0">
                                            <strong><?php echo htmlspecialchars($plugin_message_settings['title']); ?></strong>
                                            <?php if ($plugin_message_settings['dismissible']): ?>
                                                <button type="button" class="btn-close float-end" disabled></button>
                                            <?php endif; ?>
                                            <div class="mt-2"><?php echo nl2br(htmlspecialchars($plugin_message_settings['content'])); ?></div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-save"></i> Update Plugin Message
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h5><i class="fas fa-envelope"></i> Email Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_email_settings">
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="smtp_enabled" id="smtpEnabled" <?php echo $email_settings['smtp_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="smtpEnabled">
                                        <strong>Enable SMTP Email</strong>
                                    </label>
                                    <div class="form-text">Use custom SMTP server instead of PHP mail()</div>
                                </div>
                            </div>

                            <div id="smtpSettings" style="<?php echo $email_settings['smtp_enabled'] ? '' : 'display: none;'; ?>">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label class="form-label">SMTP Host</label>
                                            <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_host']); ?>" placeholder="smtp.gmail.com">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">SMTP Port</label>
                                            <input type="number" name="smtp_port" class="form-control" value="<?php echo $email_settings['smtp_port']; ?>" placeholder="587">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">SMTP Username</label>
                                            <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_username']); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">SMTP Password</label>
                                            <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($email_settings['smtp_password']); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">From Email</label>
                                            <input type="email" name="from_email" class="form-control" value="<?php echo htmlspecialchars($email_settings['from_email']); ?>" placeholder="noreply@yourdomain.com">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">From Name</label>
                                            <input type="text" name="from_name" class="form-control" value="<?php echo htmlspecialchars($email_settings['from_name']); ?>" placeholder="ArcGeek Survey">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Update Email Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-info-circle"></i> Cleanup Information</h6>
                    </div>
                    <div class="card-body">
                        <h6>Unverified Users</h6>
                        <p class="small text-muted">Users who registered but never verified their email address. These accounts cannot login and serve no purpose.</p>

                        <h6>Inactive Users</h6>
                        <p class="small text-muted">Users who haven't logged in for the specified period. Their forms and data will also be removed.</p>

                        <h6>Unused Forms</h6>
                        <p class="small text-muted">Forms that were created but never received any responses. These are marked as inactive rather than deleted.</p>

                        <hr>

                        <h6>Cron Job Setup</h6>
                        <p class="small text-muted">To enable automatic cleanup, add this cron job:</p>
                        <code class="small">0 2 * * * php <?php echo realpath(__DIR__ . '/cleanup-cron.php'); ?></code>
                        <p class="small text-muted mt-2">This runs daily at 2 AM.</p>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h6><i class="fas fa-shield-alt"></i> Safety Notes</h6>
                    </div>
                    <div class="card-body">
                        <ul class="small mb-0">
                            <li>Admin accounts (franzpc@gmail.com) are never deleted</li>
                            <li>Forms with responses are never auto-deleted</li>
                            <li>Cleanup actions are logged in system logs</li>
                            <li>Database backups recommended before manual cleanup</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('smtpEnabled').addEventListener('change', function() {
            const smtpSettings = document.getElementById('smtpSettings');
            smtpSettings.style.display = this.checked ? 'block' : 'none';
        });

        document.getElementById('pluginMessageEnabled').addEventListener('change', function() {
            const pluginMessageSettings = document.getElementById('pluginMessageSettings');
            pluginMessageSettings.style.display = this.checked ? 'block' : 'none';
        });

        const messageTitle = document.querySelector('input[name="plugin_message_title"]');
        const messageContent = document.querySelector('textarea[name="plugin_message_content"]');
        
        if (messageTitle && messageContent) {
            [messageTitle, messageContent].forEach(element => {
                element.addEventListener('input', function() {
                    const remaining = this.maxLength - this.value.length;
                    const helpText = this.nextElementSibling;
                    if (helpText && helpText.classList.contains('form-text')) {
                        const originalText = helpText.textContent.split(' - ')[0];
                        helpText.textContent = originalText + ` - ${remaining} characters remaining`;
                        
                        if (remaining < 50) {
                            helpText.style.color = '#dc3545';
                        } else if (remaining < 100) {
                            helpText.style.color = '#fd7e14';
                        } else {
                            helpText.style.color = '#6c757d';
                        }
                    }
                });
            });
        }
    </script>
</body>
</html>