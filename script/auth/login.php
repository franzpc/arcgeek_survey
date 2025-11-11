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
            if (empty($recaptcha_response) || !validate_recaptcha($recaptcha_response, 'login')) {
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

$recaptcha_config = get_recaptcha_config();
$page_title = $strings['login'];
$navbar_class = "bg-success";
$no_margin = true;

if ($show_captcha && $recaptcha_config['enabled'] && !empty($recaptcha_config['site_key'])) {
    $additional_head_content = '<script src="https://www.google.com/recaptcha/api.js?render=' . htmlspecialchars($recaptcha_config['site_key']) . '"></script>';
}

include '../includes/header.php';
?>

<style>
.auth-container {
    min-height: calc(100vh - 200px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 0;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.auth-card {
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    padding: 40px;
    max-width: 450px;
    width: 100%;
}
.auth-card-header {
    text-align: center;
    margin-bottom: 30px;
}
.auth-card-header i {
    font-size: 3rem;
    color: #667eea;
    margin-bottom: 15px;
}
.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 12px;
    font-weight: 600;
}
.btn-primary:hover {
    opacity: 0.9;
}
</style>

<div class="auth-container">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="auth-card">
                    <div class="auth-card-header">
                        <i class="fas fa-sign-in-alt"></i>
                        <h2><?php echo $strings['login']; ?></h2>
                        <p class="text-muted"><?php echo $strings['enter_credentials']; ?></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="loginForm">
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-envelope"></i> <?php echo $strings['email']; ?></label>
                            <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['password']; ?></label>
                            <input type="password" name="password" class="form-control" required>
                        </div>

                        <?php if ($show_captcha): ?>
                            <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-sign-in-alt"></i> <?php echo $strings['login']; ?>
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <a href="forgot-password.php?lang=<?php echo $lang; ?>" class="text-decoration-none">
                            <i class="fas fa-question-circle"></i> <?php echo $strings['forgot_password']; ?>
                        </a>
                    </div>

                    <hr>

                    <div class="text-center">
                        <p class="mb-2"><?php echo $strings['no_account']; ?></p>
                        <a href="register.php?lang=<?php echo $lang; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-user-plus"></i> <?php echo $strings['register']; ?>
                        </a>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="https://github.com/franzpc/arcgeek_survey" target="_blank" class="text-white text-decoration-none">
                        <i class="fab fa-github"></i> Open Source Project
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
if ($show_captcha && $recaptcha_config['enabled'] && !empty($recaptcha_config['site_key'])) {
    $additional_footer_scripts = "
    <script>
        grecaptcha.ready(function() {
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.execute('" . htmlspecialchars($recaptcha_config['site_key']) . "', {action: 'login'}).then(function(token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.getElementById('loginForm').submit();
                });
            });
        });
    </script>
    ";
}

include '../includes/footer.php';
?>
