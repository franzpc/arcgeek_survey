<?php
define('ARCGEEK_SURVEY', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

function log_cleanup($message) {
    $log_file = __DIR__ . '/cleanup.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    echo "[$timestamp] $message\n";
}

function get_system_setting($key, $default = '') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        log_cleanup("Error getting setting $key: " . $e->getMessage());
        return $default;
    }
}

function cleanup_unverified_users() {
    global $pdo;
    
    try {
        $days = get_system_setting('cleanup_unverified_days', 7);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $stmt = $pdo->prepare("SELECT id, email, name, created_at FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 100");
            $stmt->execute([$days]);
            $users = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 100");
            $stmt->execute([$days]);
            $deleted = $stmt->rowCount();
            
            foreach ($users as $user) {
                log_cleanup("Deleted unverified user: {$user['email']} (ID: {$user['id']}, Created: {$user['created_at']})");
            }
            
            log_cleanup("Cleanup unverified users: $deleted deleted (of $count candidates)");
        } else {
            log_cleanup("Cleanup unverified users: No users to delete");
        }
        
        return $count;
    } catch (Exception $e) {
        log_cleanup("Error in cleanup_unverified_users: " . $e->getMessage());
        return 0;
    }
}

function cleanup_inactive_users() {
    global $pdo;
    
    try {
        $days = get_system_setting('cleanup_inactive_users_days', 365);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL ? DAY) AND email != 'franzpc@gmail.com'");
        $stmt->execute([$days]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $stmt = $pdo->prepare("SELECT id, email, name, last_login FROM users WHERE last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL ? DAY) AND email != 'franzpc@gmail.com' LIMIT 50");
            $stmt->execute([$days]);
            $users = $stmt->fetchAll();
            
            $pdo->beginTransaction();
            
            foreach ($users as $user) {
                $stmt = $pdo->prepare("UPDATE forms SET is_active = 0, deleted_at = NOW() WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $forms_affected = $stmt->rowCount();
                
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                log_cleanup("Deleted inactive user: {$user['email']} (ID: {$user['id']}, Last login: {$user['last_login']}, Forms affected: $forms_affected)");
            }
            
            $pdo->commit();
            $deleted = count($users);
            log_cleanup("Cleanup inactive users: $deleted deleted (of $count candidates)");
        } else {
            log_cleanup("Cleanup inactive users: No users to delete");
        }
        
        return $count;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_cleanup("Error in cleanup_inactive_users: " . $e->getMessage());
        return 0;
    }
}

function cleanup_unused_forms() {
    global $pdo;
    
    try {
        $days = get_system_setting('cleanup_unused_forms_days', 180);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_active = 1");
        $stmt->execute([$days]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $stmt = $pdo->prepare("SELECT id, title, form_code, created_at, user_id FROM forms WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_active = 1 LIMIT 100");
            $stmt->execute([$days]);
            $forms = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("UPDATE forms SET is_active = 0, deleted_at = NOW() WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY) AND is_active = 1 LIMIT 100");
            $stmt->execute([$days]);
            $archived = $stmt->rowCount();
            
            foreach ($forms as $form) {
                log_cleanup("Archived unused form: '{$form['title']}' (Code: {$form['form_code']}, ID: {$form['id']}, Created: {$form['created_at']})");
            }
            
            log_cleanup("Cleanup unused forms: $archived archived (of $count candidates)");
        } else {
            log_cleanup("Cleanup unused forms: No forms to archive");
        }
        
        return $count;
    } catch (Exception $e) {
        log_cleanup("Error in cleanup_unused_forms: " . $e->getMessage());
        return 0;
    }
}

function cleanup_old_tokens() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("DELETE FROM password_resets WHERE expires_at < NOW()");
        $deleted_resets = $stmt->rowCount();
        
        $stmt = $pdo->query("DELETE FROM email_verifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
        $deleted_verifications = $stmt->rowCount();
        
        $stmt = $pdo->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $deleted_attempts = $stmt->rowCount();
        
        $stmt = $pdo->query("DELETE FROM password_reset_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $deleted_reset_attempts = $stmt->rowCount();
        
        if ($deleted_resets > 0 || $deleted_verifications > 0 || $deleted_attempts > 0 || $deleted_reset_attempts > 0) {
            log_cleanup("Token cleanup: $deleted_resets password resets, $deleted_verifications email verifications, $deleted_attempts login attempts, $deleted_reset_attempts reset attempts");
        }
        
        return $deleted_resets + $deleted_verifications + $deleted_attempts + $deleted_reset_attempts;
    } catch (Exception $e) {
        log_cleanup("Error in cleanup_old_tokens: " . $e->getMessage());
        return 0;
    }
}

function main() {
    global $pdo;
    
    log_cleanup("=== ArcGeek Survey Cleanup Started ===");
    
    $auto_cleanup_enabled = get_system_setting('auto_cleanup_enabled', 0);
    
    if (!$auto_cleanup_enabled) {
        log_cleanup("Auto cleanup is disabled. Exiting.");
        return;
    }
    
    try {
        $start_time = microtime(true);
        
        $unverified_count = cleanup_unverified_users();
        $inactive_count = cleanup_inactive_users();
        $forms_count = cleanup_unused_forms();
        $tokens_count = cleanup_old_tokens();
        
        $total_cleaned = $unverified_count + $inactive_count + $forms_count + $tokens_count;
        $execution_time = round(microtime(true) - $start_time, 2);
        
        log_cleanup("=== Cleanup Summary ===");
        log_cleanup("Unverified users: $unverified_count");
        log_cleanup("Inactive users: $inactive_count");
        log_cleanup("Unused forms: $forms_count");
        log_cleanup("Old tokens: $tokens_count");
        log_cleanup("Total items cleaned: $total_cleaned");
        log_cleanup("Execution time: {$execution_time}s");
        log_cleanup("=== Cleanup Completed ===");
        
        if ($total_cleaned > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO system_logs (log_type, message, details) VALUES (?, ?, ?)");
                $details = json_encode([
                    'unverified_users' => $unverified_count,
                    'inactive_users' => $inactive_count,
                    'unused_forms' => $forms_count,
                    'old_tokens' => $tokens_count,
                    'execution_time' => $execution_time
                ]);
                $stmt->execute(['cleanup', "Automatic cleanup completed: $total_cleaned items", $details]);
            } catch (Exception $e) {
                log_cleanup("Error logging to database: " . $e->getMessage());
            }
        }
        
    } catch (Exception $e) {
        log_cleanup("Fatal error during cleanup: " . $e->getMessage());
        
        try {
            $stmt = $pdo->prepare("INSERT INTO system_logs (log_type, message, details) VALUES (?, ?, ?)");
            $stmt->execute(['error', 'Cleanup script failed', $e->getMessage()]);
        } catch (Exception $log_error) {
            log_cleanup("Error logging to database: " . $log_error->getMessage());
        }
    }
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    if (!defined('ARCGEEK_SURVEY')) {
        die('Access denied');
    }
    
    session_start();
    if (!isset($_SESSION['user_id'])) {
        die('Authentication required');
    }
    
    $user = get_user_by_id($_SESSION['user_id']);
    if (!$user || ($user['role'] !== 'admin' && $user['email'] !== 'franzpc@gmail.com')) {
        die('Admin access required');
    }
    
    header('Content-Type: text/plain');
    main();
}
?>