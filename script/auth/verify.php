<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/plans.php';
require_once '../config/security.php';

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'es'])) $lang = 'en';
$strings = include "../lang/{$lang}.php";

$token = $_GET['token'] ?? '';
$message = '';
$error = '';
$success = false;
$debug_info = '';

if (empty($token)) {
    $error = $strings['invalid_verification_token'] ?? 'Invalid verification token';
} else {
    try {
        // Debug: Check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_verifications'");
        if (!$stmt->fetch()) {
            $error = "Email verifications table does not exist. Please run: CREATE TABLE email_verifications (id int(11) NOT NULL AUTO_INCREMENT, user_id int(11) NOT NULL, token varchar(64) NOT NULL, verified tinyint(1) DEFAULT 0, created_at timestamp DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY unique_token (token), KEY idx_user_id (user_id), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE);";
        } else {
            // Check if token exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_verifications WHERE token = ?");
            $stmt->execute([$token]);
            $token_exists = $stmt->fetchColumn();
            
            if ($token_exists == 0) {
                $error = "Token not found in database. Token: " . htmlspecialchars($token);
            } else {
                $stmt = $pdo->prepare("
                    SELECT ev.user_id, u.name, u.email, u.language, ev.verified, ev.created_at
                    FROM email_verifications ev 
                    JOIN users u ON ev.user_id = u.id 
                    WHERE ev.token = ?
                ");
                $stmt->execute([$token]);
                $verification = $stmt->fetch();
                
                if (!$verification) {
                    $error = "Verification record not found or user deleted";
                } elseif ($verification['verified'] == 1) {
                    $error = "Email already verified";
                } elseif (strtotime($verification['created_at']) < strtotime('-24 hours')) {
                    $error = "Verification token expired (older than 24 hours)";
                } else {
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("UPDATE users SET is_active = 1, email_verified = 1 WHERE id = ?");
                    $stmt->execute([$verification['user_id']]);
                    
                    $stmt = $pdo->prepare("UPDATE email_verifications SET verified = 1 WHERE token = ?");
                    $stmt->execute([$token]);
                    
                    // Initialize user usage
                    $stmt = $pdo->prepare("INSERT INTO user_usage (user_id, forms_count, responses_count) VALUES (?, 0, 0) ON DUPLICATE KEY UPDATE user_id = user_id");
                    $stmt->execute([$verification['user_id']]);
                    
                    $pdo->commit();
                    
                    $message = $strings['email_verified_success'] ?? 'Email verified successfully! Your account is now active.';
                    $success = true;
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Email verification error: " . $e->getMessage());
        $error = "Database error: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'resend') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email) || !validate_email($email)) {
        $error = $strings['invalid_email'] ?? 'Invalid email';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, language FROM users WHERE email = ? AND email_verified = 0");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                $new_token = generate_verification_token();
                
                $stmt = $pdo->prepare("UPDATE email_verifications SET token = ?, created_at = NOW() WHERE user_id = ?");
                $stmt->execute([$new_token, $user['id']]);
                
                if (send_verification_email($email, $user['name'], $new_token, $user['language'])) {
                    $message = $strings['verification_email_resent'] ?? 'Verification email has been resent';
                } else {
                    $error = $strings['email_send_failed'] ?? 'Failed to send email';
                }
            } else {
                $error = $strings['email_not_found_or_verified'] ?? 'Email not found or already verified';
            }
        } catch (Exception $e) {
            error_log("Resend verification error: " . $e->getMessage());
            $error = $strings['server_error'] ?? 'Server error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $strings['verify_email'] ?? 'Verify Email'; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white text-center">
                        <h3><i class="fas fa-envelope-open"></i> <?php echo $strings['verify_email'] ?? 'Verify Email'; ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="text-end mb-3">
                            <a href="?lang=en&token=<?php echo urlencode($token); ?>" class="btn btn-sm btn-outline-secondary <?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
                            <a href="?lang=es&token=<?php echo urlencode($token); ?>" class="btn btn-sm btn-outline-secondary <?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $success ? 'success' : 'info'; ?>">
                                <i class="fas fa-<?php echo $success ? 'check-circle' : 'info-circle'; ?>"></i> <?php echo $message; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="text-center">
                                <div class="mb-4">
                                    <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                                </div>
                                <h4><?php echo $strings['welcome_to_arcgeek'] ?? 'Welcome to ArcGeek Survey!'; ?></h4>
                                <p class="text-muted"><?php echo $strings['account_activated'] ?? 'Your account has been successfully activated'; ?></p>
                                <a href="login.php?lang=<?php echo $lang; ?>" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt"></i> <?php echo $strings['login_now'] ?? 'Login Now'; ?>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center mb-4">
                                <i class="fas fa-envelope text-muted" style="font-size: 3rem;"></i>
                                <h5 class="mt-3"><?php echo $strings['verification_needed'] ?? 'Email verification needed'; ?></h5>
                                <p class="text-muted"><?php echo $strings['check_email_for_link'] ?? 'Please check your email and click the verification link'; ?></p>
                            </div>

                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6><?php echo $strings['didnt_receive_email'] ?? 'Didn\'t receive the email?'; ?></h6>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="resend">
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo $strings['email'] ?? 'Email'; ?></label>
                                            <input type="email" name="email" class="form-control" placeholder="<?php echo $strings['enter_email'] ?? 'Enter your email address'; ?>" required>
                                        </div>
                                        <button type="submit" class="btn btn-outline-primary">
                                            <i class="fas fa-paper-plane"></i> <?php echo $strings['resend_verification'] ?? 'Resend Verification'; ?>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="text-center mt-3">
                                <a href="login.php?lang=<?php echo $lang; ?>" class="text-muted">
                                    <i class="fas fa-arrow-left"></i> <?php echo $strings['back_to_login'] ?? 'Back to Login'; ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>