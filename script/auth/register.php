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
    } elseif (empty($recaptcha_response) || !validate_recaptcha_v3($recaptcha_response, 'register')) {
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

function validate_recaptcha_v3($response, $action = 'register') {
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
    <title><?php echo $strings['register']; ?> - ArcGeek Survey</title>
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
                            <h3><i class="fas fa-user-plus"></i> <?php echo $strings['register']; ?></h3>
                            <p><?php echo $strings['create_your_account'] ?? 'Create your account'; ?></p>
                        </div>
                        <div class="auth-card-body">
                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($success): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                                    <div class="mt-3">
                                        <a href="login.php?lang=<?php echo $lang; ?>" class="btn btn-primary"><?php echo $strings['login_now']; ?></a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <form method="POST" id="registerForm">
                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-user"></i> <?php echo $strings['name']; ?></label>
                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-envelope"></i> <?php echo $strings['email']; ?></label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label"><i class="fas fa-globe"></i> <?php echo $strings['language']; ?></label>
                                        <select name="language" class="form-select">
                                            <option value="en" <?php echo $lang === 'en' ? 'selected' : ''; ?>>English</option>
                                            <option value="es" <?php echo $lang === 'es' ? 'selected' : ''; ?>>Español</option>
                                        </select>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['password']; ?></label>
                                                <input type="password" name="password" class="form-control" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label"><i class="fas fa-lock"></i> <?php echo $strings['confirm_password']; ?></label>
                                                <input type="password" name="confirm_password" class="form-control" required>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="plan-info">
                                        <h6><?php echo $strings['free_plan']; ?></h6>
                                        <ul>
                                            <li>1 <?php echo $strings['form']; ?></li>
                                            <li>5 <?php echo $strings['fields']; ?></li>
                                            <li>40 <?php echo $strings['responses']; ?></li>
                                        </ul>
                                    </div>

                                    <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="fas fa-user-plus"></i> <?php echo $strings['create_account']; ?>
                                        </button>
                                    </div>
                                </form>

                                <div class="text-links">
                                    <a href="login.php?lang=<?php echo $lang; ?>"><?php echo $strings['have_account']; ?></a>
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
            document.getElementById('registerForm').addEventListener('submit', function(e) {
                e.preventDefault();
                grecaptcha.execute('6Lec8YIrAAAAAGIp5N1aNkt5cL4_2nw7I_bboJLQ', {action: 'register'}).then(function(token) {
                    document.getElementById('g-recaptcha-response').value = token;
                    document.getElementById('registerForm').submit();
                });
            });
        });
    </script>
</body>
</html>