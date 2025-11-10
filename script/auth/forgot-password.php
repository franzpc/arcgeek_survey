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

$message = '';
$error = '';
$show_captcha = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    $ip = get_client_ip();
    
    if (empty($email)) {
        $error = $strings['email_required'];
    } elseif (!validate_email($email)) {
        $error = $strings['invalid_email'];
    } elseif (!check_password_reset_attempts($email, $ip)) {
        $error = $strings['too_many_attempts'];
        $show_captcha = true;
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_attempts WHERE (email = ? OR ip_address = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$email, $ip]);
        $failed_attempts = $stmt->fetchColumn();
        
        if ($failed_attempts >= 2) {
            $show_captcha = true;
            if (empty($recaptcha_response) || !validate_recaptcha_v3($recaptcha_response, 'forgot_password')) {
                $error = $strings['invalid_captcha'];
            }
        }
        
        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("SELECT id, name, language, is_active, email_verified FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && $user['is_active'] && $user['email_verified']) {
                    $reset_token = generate_reset_token();
                    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, email, expires_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at), created_at = NOW()");
                    $stmt->execute([$user['id'], $reset_token, $email, $expires_at]);
                    
                    if (send_password_reset_email($email, $user['name'], $reset_token, $user['language'])) {
                        $message = $strings['reset_email_sent'];
                        log_password_reset_attempt($email, $ip, true);
                    } else {
                        $error = $strings['email_send_failed'];
                        log_password_reset_attempt($email, $ip, false);
                    }
                } else {
                    log_password_reset_attempt($email, $ip, false);
                    $message = $strings['reset_email_sent'];
                }
                
            } catch (PDOException $e) {
                error_log("Password reset error: " . $e->getMessage());
                $error = $strings['server_error'];
                log_password_reset_attempt($email, $ip, false);
            }
        }
    }
}

if (!$show_captcha) {
    $ip = get_client_ip();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_attempts WHERE (email = ? OR ip_address = ?) AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$_POST['email'] ?? '', $ip]);
    $failed_attempts = $stmt->fetchColumn();
    $show_captcha = $failed_attempts >= 2;
}

function generate_reset_token() {
    return bin2hex(random_bytes(32));
}

function log_password_reset_attempt($email, $ip, $success) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO password_reset_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$email, $ip, $success]);
}

function check_password_reset_attempts($email, $ip) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_attempts 
                          WHERE (email = ? OR ip_address = ?) 
                          AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$email, $ip]);
    
    return $stmt->fetchColumn() < 5;
}

function send_password_reset_email($email, $name, $token, $lang = 'en') {
    $subject = $lang === 'es' ? 'Restablecer contraseña - ArcGeek Survey' : 'Reset your password - ArcGeek Survey';
    $reset_url = "https://" . $_SERVER['HTTP_HOST'] . "/survey/auth/reset-password.php?token=" . $token;
    
    $message = $lang === 'es' ? 
        "Hola $name,\n\nHas solicitado restablecer tu contraseña en ArcGeek Survey.\n\nHaz clic en el siguiente enlace para crear una nueva contraseña:\n$reset_url\n\nEste enlace expira en 1 hora.\n\nSi no solicitaste este cambio, ignora este email.\n\nSaludos,\nEquipo ArcGeek Survey" :
        "Hello $name,\n\nYou have requested to reset your password for ArcGeek Survey.\n\nClick the following link to create a new password:\n$reset_url\n\nThis link expires in 1 hour.\n\nIf you didn't request this change, please ignore this email.\n\nBest regards,\nArcGeek Survey Team";
    
    $headers = [
        'From: noreply@' . $_SERVER['HTTP_HOST'],
        'Reply-To: noreply@' . $_SERVER['HTTP_HOST'],
        'Content-Type: text/plain; charset=UTF-8'
    ];
    
    return mail($email, $subject, $message, implode("\r\n", $headers));
}

function validate_recaptcha_v3($response, $action = 'forgot_password') {
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
    <title><?php echo $strings['forgot_password']; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../css/styles.css" rel="stylesheet">
    <?php if ($show_captcha): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=6Lec8YIrAAAAAGIp5N1aNkt5cL4_2nw7I_bboJLQ"></script>
    <?php endif; ?>
</head>
<body>
    <div class="header-brand">
        <div class="header-content">
            <h1><i class="fas fa-map-marked-alt"></i> ArcGeek Survey</h1>
            <div class="header-lang">
                <a href="?lang=en" class="<?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
                <a href="?lang=es" class="<?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
            </div>
        </div>
    </div>

    <div class="auth-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-6">
                    <div class="auth-card fade-in">
                        <div class="auth-card-header" style="background-color: #ffc107; color: #212529;">
                            <h3><i class="fas fa-key"></i> <?php echo $strings['forgot_password']; ?></h3>
                            <p><?php echo $strings['reset_password_subtitle'] ?? 'Reset your password'; ?></p>
                        </div>
                        <div class="auth-card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($message): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                                    <div class="mt-3">
                                        <a href="login.php?lang=<?php echo $lang; ?>" class="btn btn-primary">
                                            <i class="fas fa-arrow-left"></i> <?php echo $strings['back_to_login']; ?>
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> <?php echo $strings['forgot_password_instructions'] ?? 'Enter your email to receive reset instructions'; ?>
                                </div>

                                <form method="POST" id="forgotPasswordForm">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-envelope"></i> <?php echo $strings['email']; ?></label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="<?php echo $strings['enter_email'] ?? 'Enter your email'; ?>" required>
                                    </div>

                                    <?php if ($show_captcha): ?>
                                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                                        <div class="security-notice">
                                            <i class="fas fa-shield-alt"></i> <?php echo $strings['security_verification_enabled'] ?? 'Security verification enabled'; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-warning btn-lg">
                                            <i class="fas fa-paper-plane"></i> <?php echo $strings['send_reset_link'] ?? 'Send Reset Link'; ?>
                                        </button>
                                    </div>
                                </form>

                                <div class="divider"></div>

                                <div class="text-links">
                                    <a href="login.php?lang=<?php echo $lang; ?>">
                                        <i class="fas fa-arrow-left"></i> <?php echo $strings['back_to_login']; ?>
                                    </a>
                                </div>

                                <div class="text-links">
                                    <p><?php echo $strings['no_account'] ?? "Don't have an account?"; ?></p>
                                    <a href="register.php?lang=<?php echo $lang; ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-user-plus"></i> <?php echo $strings['create_account']; ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($show_captcha): ?>
    <script>
        grecaptcha.ready(function() {
            document.getElementById('forgotPasswordForm').addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.execute('6Lec8YIrAAAAAGIp5N1aNkt5cL4_2nw7I_bboJLQ', {action: 'forgot_password'}).then(function(token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.getElementById('forgotPasswordForm').submit();
                });
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>