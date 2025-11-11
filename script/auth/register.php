<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/plans.php';
require_once '../config/security.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard/');
    exit();
}

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'es'])) $lang = 'en';
$strings = include "../lang/{$lang}.php";

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $language = $_POST['language'] ?? $lang;
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    $ip = get_client_ip();

    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = $strings['all_fields_required'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = $strings['invalid_email'];
    } elseif ($password !== $confirm_password) {
        $error = $strings['passwords_no_match'];
    } elseif (strlen($password) < 6) {
        $error = $strings['password_min_6'];
    } elseif (!check_registration_attempts($ip)) {
        $error = $strings['too_many_attempts'];
    } elseif (empty($recaptcha_response) || !validate_recaptcha($recaptcha_response, 'register')) {
        $error = $strings['invalid_captcha'];
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = $strings['email_exists'];
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $verification_token = generate_verification_token();

                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, language, plan_type, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 0, 0)");
                if ($stmt->execute([$name, $email, $hashed_password, $language, PLAN_FREE])) {
                    $user_id = $pdo->lastInsertId();

                    $stmt = $pdo->prepare("INSERT INTO email_verifications (user_id, token) VALUES (?, ?)");
                    $stmt->execute([$user_id, $verification_token]);

                    if (send_verification_email($email, $name, $verification_token, $language)) {
                        $success = $strings['registration_success_verify'];
                    } else {
                        $success = $strings['registration_success'];
                    }
                } else {
                    $error = $strings['registration_error'];
                }
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = $strings['server_error'];
        }
    }
}

$recaptcha_config = get_recaptcha_config();
$page_title = $strings['register'];
$navbar_class = "bg-success";
$no_margin = true;

if ($recaptcha_config['enabled'] && !empty($recaptcha_config['site_key'])) {
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
    max-width: 500px;
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
            <div class="col-md-7 col-lg-6">
                <div class="auth-card">
                    <div class="auth-card-header">
                        <i class="fas fa-user-plus"></i>
                        <h2><?php echo $strings['register']; ?></h2>
                        <p class="text-muted"><?php echo $strings['create_account']; ?></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="registerForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-user"></i> <?php echo $strings['name']; ?></label>
                                <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-envelope"></i> <?php echo $strings['email']; ?></label>
                                <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['password']; ?></label>
                                <input type="password" name="password" class="form-control" required minlength="6">
                                <small class="text-muted"><?php echo $strings['password_min_6']; ?></small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['confirm_password']; ?></label>
                                <input type="password" name="confirm_password" class="form-control" required minlength="6">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-language"></i> <?php echo $strings['language']; ?></label>
                            <select name="language" class="form-select">
                                <option value="en" <?php echo $lang === 'en' ? 'selected' : ''; ?>>English</option>
                                <option value="es" <?php echo $lang === 'es' ? 'selected' : ''; ?>>Español</option>
                            </select>
                        </div>

                        <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-user-plus"></i> <?php echo $strings['register']; ?>
                        </button>
                    </form>

                    <hr>

                    <div class="text-center">
                        <p class="mb-2"><?php echo $strings['already_have_account']; ?></p>
                        <a href="login.php?lang=<?php echo $lang; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-sign-in-alt"></i> <?php echo $strings['login']; ?>
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
if ($recaptcha_config['enabled'] && !empty($recaptcha_config['site_key'])) {
    $additional_footer_scripts = "
    <script>
        grecaptcha.ready(function() {
            document.getElementById('registerForm').addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.execute('" . htmlspecialchars($recaptcha_config['site_key']) . "', {action: 'register'}).then(function(token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.getElementById('registerForm').submit();
                });
            });
        });
    </script>
    ";
}

include '../includes/footer.php';
?>
