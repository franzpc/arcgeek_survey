<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/');
    exit();
}

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'es'])) $lang = 'en';
$strings = include "../lang/{$lang}.php";

$token = $_GET['token'] ?? '';
$message = '';
$error = '';
$success = false;
$user_data = null;

if (empty($token)) {
    $error = $strings['invalid_reset_token'];
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT pr.user_id, pr.email, pr.expires_at, u.name, u.language, u.is_active, u.email_verified
            FROM password_resets pr 
            JOIN users u ON pr.user_id = u.id 
            WHERE pr.token = ? AND pr.used = 0
        ");
        $stmt->execute([$token]);
        $reset_data = $stmt->fetch();
        
        if (!$reset_data) {
            $error = $strings['invalid_reset_token'];
        } elseif (!$reset_data['is_active'] || !$reset_data['email_verified']) {
            $error = $strings['account_not_active'];
        } elseif (strtotime($reset_data['expires_at']) < time()) {
            $error = $strings['reset_token_expired'];
        } else {
            $user_data = $reset_data;
            $lang = $reset_data['language'];
            $strings = include "../lang/{$lang}.php";
        }
        
    } catch (Exception $e) {
        error_log("Reset token validation error: " . $e->getMessage());
        $error = $strings['server_error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_data) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = $strings['all_fields_required'];
    } elseif ($new_password !== $confirm_password) {
        $error = $strings['passwords_no_match'];
    } elseif (strlen($new_password) < 6) {
        $error = $strings['password_min_6'];
    } elseif (empty($recaptcha_response) || !validate_recaptcha_v3($recaptcha_response, 'reset_password')) {
        $error = $strings['invalid_captcha'];
    } else {
        try {
            $pdo->beginTransaction();
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_data['user_id']]);
            
            $stmt = $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE token = ?");
            $stmt->execute([$token]);
            
            $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND token != ?");
            $stmt->execute([$user_data['user_id'], $token]);
            
            $pdo->commit();
            
            $success = true;
            $message = $strings['password_reset_success'];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Password reset error: " . $e->getMessage());
            $error = $strings['server_error'];
        }
    }
}

function validate_recaptcha_v3($response, $action = 'reset_password') {
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
    
    $response_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $result = json_decode($response_data, true);
        $score = $result['score'] ?? 0;
        $success = $result['success'] ?? false;
        $action_match = ($result['action'] ?? '') === $action;
        
        return $success && $action_match && $score >= 0.5;
    }
    
    return false;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $strings['reset_password']; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../css/styles.css" rel="stylesheet">
    <script src="https://www.google.com/recaptcha/api.js?render=6Lec8YIrAAAAAGIp5N1aNkt5cL4_2nw7I_bboJLQ"></script>
</head>
<body>
    <div class="header-brand">
        <div class="header-content">
            <h1><i class="fas fa-map-marked-alt"></i> ArcGeek Survey</h1>
            <div class="header-lang">
                <a href="?lang=en&token=<?php echo urlencode($token); ?>" class="<?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
                <a href="?lang=es&token=<?php echo urlencode($token); ?>" class="<?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
            </div>
        </div>
    </div>

    <div class="auth-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="auth-card fade-in">
                        <div class="auth-card-header" style="background-color: #198754; color: white;">
                            <h3><i class="fas fa-lock"></i> <?php echo $strings['reset_password']; ?></h3>
                            <p><?php echo $strings['create_new_password'] ?? 'Create a new password'; ?></p>
                        </div>
                        <div class="auth-card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                                </div>
                                <div class="text-center">
                                    <a href="forgot-password.php?lang=<?php echo $lang; ?>" class="btn btn-warning">
                                        <i class="fas fa-redo"></i> <?php echo $strings['request_new_reset'] ?? 'Request New Reset'; ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if ($success): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                                </div>
                                <div class="text-center">
                                    <div class="verification-icon">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <h4><?php echo $strings['password_changed'] ?? 'Password Changed'; ?></h4>
                                    <p class="text-muted"><?php echo $strings['you_can_now_login'] ?? 'You can now login with your new password'; ?></p>
                                    <a href="login.php?lang=<?php echo $lang; ?>" class="btn btn-primary btn-lg">
                                        <i class="fas fa-sign-in-alt"></i> <?php echo $strings['login_now']; ?>
                                    </a>
                                </div>
                            <?php elseif ($user_data): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-user"></i> <?php echo $strings['resetting_password_for'] ?? 'Resetting password for'; ?>: <strong><?php echo htmlspecialchars($user_data['name']); ?></strong> (<?php echo htmlspecialchars($user_data['email']); ?>)
                                </div>

                                <form method="POST" id="resetPasswordForm">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['new_password'] ?? 'New Password'; ?> *</label>
                                        <input type="password" name="new_password" class="form-control" minlength="6" required>
                                        <div class="form-text"><?php echo $strings['password_min_6']; ?></div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['confirm_password']; ?> *</label>
                                        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                                    </div>

                                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-save"></i> <?php echo $strings['update_password'] ?? 'Update Password'; ?>
                                        </button>
                                    </div>
                                </form>

                                <div class="text-links">
                                    <a href="login.php?lang=<?php echo $lang; ?>">
                                        <i class="fas fa-arrow-left"></i> <?php echo $strings['back_to_login']; ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        grecaptcha.ready(function() {
            document.getElementById('resetPasswordForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.execute('6Lec8YIrAAAAAGIp5N1aNkt5cL4_2nw7I_bboJLQ', {action: 'reset_password'}).then(function(token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.getElementById('resetPasswordForm').submit();
                });
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.querySelector('input[name="new_password"]');
            const confirmPassword = document.querySelector('input[name="confirm_password"]');
            
            if (newPassword && confirmPassword) {
                confirmPassword.addEventListener('input', function() {
                    if (this.value && newPassword.value !== this.value) {
                        this.setCustomValidity('<?php echo $strings['passwords_no_match']; ?>');
                    } else {
                        this.setCustomValidity('');
                    }
                });
            }
        });
    </script>
</body>
</html>