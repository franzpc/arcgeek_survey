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

$error = '';
$show_captcha = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    $ip = get_client_ip();
    
    if (empty($email) || empty($password)) {
        $error = $strings['email_password_required'];
    } elseif (!check_login_attempts($email, $ip)) {
        $error = $strings['too_many_attempts'];
        $show_captcha = true;
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip_address = ?) AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$email, $ip]);
        $failed_attempts = $stmt->fetchColumn();
        
        if ($failed_attempts >= 2) {
            $show_captcha = true;
            if (empty($recaptcha_response) || !validate_recaptcha_v3($recaptcha_response, 'login')) {
                $error = $strings['invalid_captcha'];
            }
        }
        
        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("SELECT id, email, password, name, language, is_active, email_verified FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password']) && $user['is_active']) {
                    if (!$user['email_verified']) {
                        $error = $strings['email_not_verified'];
                    } else {
                        session_regenerate_id(true);
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['last_activity'] = time();
                        
                        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        
                        log_login_attempt($email, $ip, true);
                        
                        header('Location: ../dashboard/');
                        exit();
                    }
                } else {
                    $error = $strings['invalid_credentials'];
                    log_login_attempt($email, $ip, false);
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = $strings['server_error'];
            }
        }
    }
}

if (!$show_captcha) {
    $ip = get_client_ip();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip_address = ?) AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([$_POST['email'] ?? '', $ip]);
    $failed_attempts = $stmt->fetchColumn();
    $show_captcha = $failed_attempts >= 2;
}

function validate_recaptcha_v3($response, $action = 'login') {
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
    <title><?php echo $strings['login']; ?> - ArcGeek Survey</title>
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
                        <div class="auth-card-header">
                            <h3><i class="fas fa-sign-in-alt"></i> <?php echo $strings['login']; ?></h3>
                            <p><?php echo $strings['access_your_account'] ?? 'Access your account'; ?></p>
                        </div>
                        <div class="auth-card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" id="loginForm">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-envelope"></i> <?php echo $strings['email']; ?></label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['password']; ?></label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>

                                <?php if ($show_captcha): ?>
                                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                                    <div class="security-notice">
                                        <i class="fas fa-shield-alt"></i> Security verification enabled
                                    </div>
                                <?php endif; ?>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-sign-in-alt"></i> <?php echo $strings['login']; ?>
                                    </button>
                                </div>
                            </form>

                            <div class="divider"></div>

                            <div class="text-links">
                                <p><?php echo $strings['no_account']; ?></p>
                                <a href="register.php?lang=<?php echo $lang; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-user-plus"></i> <?php echo $strings['create_account']; ?>
                                </a>
                            </div>

                            <div class="text-links">
                                <a href="forgot-password.php?lang=<?php echo $lang; ?>">
                                    <i class="fas fa-key"></i> <?php echo $strings['forgot_password']; ?>?
                                </a>
                            </div>

                            <div class="features-list">
                                <h6><?php echo $strings['features']; ?>:</h6>
                                <ul>
                                    <li><i class="fas fa-check"></i> <?php echo $strings['mobile_capture']; ?></li>
                                    <li><i class="fas fa-check"></i> <?php echo $strings['database_integration']; ?></li>
                                    <li><i class="fas fa-check"></i> <?php echo $strings['free_plan_available']; ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($show_captcha): ?>
    <script>
        grecaptcha.ready(function() {
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.execute('6Lec8YIrAAAAAGIp5N1aNkt5cL4_2nw7I_bboJLQ', {action: 'login'}).then(function(token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.getElementById('loginForm').submit();
                });
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>