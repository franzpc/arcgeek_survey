<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_recaptcha':
            $enabled = isset($_POST['recaptcha_enabled']) ? '1' : '0';
            $site_key = trim($_POST['recaptcha_site_key'] ?? '');
            $secret_key = trim($_POST['recaptcha_secret_key'] ?? '');

            if ($enabled === '1' && (empty($site_key) || empty($secret_key))) {
                $error = 'Site key and secret key are required when reCAPTCHA is enabled';
            } else {
                try {
                    set_admin_setting('recaptcha_enabled', $enabled);
                    set_admin_setting('recaptcha_site_key', $site_key);
                    set_admin_setting('recaptcha_secret_key', $secret_key);
                    $message = 'reCAPTCHA configuration updated successfully';
                } catch (Exception $e) {
                    error_log("reCAPTCHA config error: " . $e->getMessage());
                    $error = 'Error updating reCAPTCHA configuration';
                }
            }
            break;

        case 'update_plugin_token':
            $token = trim($_POST['plugin_token'] ?? '');

            if (empty($token)) {
                $error = 'Plugin authentication token is required';
            } elseif (strlen($token) < 32) {
                $error = 'Token must be at least 32 characters long';
            } else {
                try {
                    set_admin_setting('plugin_auth_token', $token);
                    $message = 'Plugin authentication token updated successfully. Update your plugin configuration!';
                } catch (Exception $e) {
                    error_log("Plugin token config error: " . $e->getMessage());
                    $error = 'Error updating plugin token';
                }
            }
            break;

        case 'generate_new_token':
            $new_token = 'ArcGeek_' . bin2hex(random_bytes(32)) . '_' . time();
            try {
                set_admin_setting('plugin_auth_token', $new_token);
                $message = 'New plugin token generated successfully!';
            } catch (Exception $e) {
                error_log("Token generation error: " . $e->getMessage());
                $error = 'Error generating new token';
            }
            break;

        case 'update_site_settings':
            $site_name = trim($_POST['site_name'] ?? 'ArcGeek Survey');
            $logo_url = trim($_POST['logo_url'] ?? '');
            $footer_text = trim($_POST['footer_text'] ?? '');
            $support_email = trim($_POST['support_email'] ?? '');

            try {
                set_system_setting('site_name', $site_name);
                set_system_setting('site_logo_url', $logo_url);
                set_system_setting('site_footer_text', $footer_text);
                set_system_setting('site_support_email', $support_email);
                $message = 'Site settings updated successfully';
            } catch (Exception $e) {
                error_log("Site settings error: " . $e->getMessage());
                $error = 'Error updating site settings';
            }
            break;
    }
}

$recaptcha_config = get_recaptcha_config();
$current_token = get_plugin_auth_token();
$site_config = get_site_config();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Configuration - ArcGeek Survey Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .token-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            word-break: break-all;
        }
        .copy-button {
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-shield-alt"></i> Security Configuration
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">Admin Panel</a>
                <a class="nav-link" href="config.php">Supabase Config</a>
                <a class="nav-link" href="system-settings.php">System Settings</a>
                <a class="nav-link" href="../dashboard/">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <h2>Security & API Configuration</h2>
                <p class="text-muted">Manage authentication tokens, reCAPTCHA, and site settings</p>
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
            <!-- Plugin Authentication Token -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-key"></i> Plugin Authentication Token</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> This token is used by the QGIS plugin to authenticate API requests.
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><strong>Current Token:</strong></label>
                            <div class="token-display" id="currentToken">
                                <?php echo htmlspecialchars($current_token); ?>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary mt-2 copy-button" onclick="copyToken()">
                                <i class="fas fa-copy"></i> Copy to Clipboard
                            </button>
                        </div>

                        <hr>

                        <form method="POST" class="mb-3">
                            <input type="hidden" name="action" value="generate_new_token">
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Generating a new token will invalidate the current one. All plugins using the old token will stop working.
                            </div>
                            <button type="submit" class="btn btn-warning" onclick="return confirm('Generate a new token? This will invalidate the current token and require plugin reconfiguration.')">
                                <i class="fas fa-sync-alt"></i> Generate New Token
                            </button>
                        </form>

                        <hr>

                        <form method="POST">
                            <input type="hidden" name="action" value="update_plugin_token">
                            <div class="mb-3">
                                <label class="form-label">Custom Token</label>
                                <input type="text" name="plugin_token" class="form-control"
                                       placeholder="Enter custom token (min 32 characters)"
                                       minlength="32">
                                <div class="form-text">Or set your own custom token (advanced)</div>
                            </div>
                            <button type="submit" class="btn btn-secondary">
                                <i class="fas fa-save"></i> Set Custom Token
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- reCAPTCHA Configuration -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-robot"></i> reCAPTCHA v3 Configuration</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_recaptcha">

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="recaptcha_enabled"
                                           id="recaptchaEnabled" <?php echo $recaptcha_config['enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="recaptchaEnabled">
                                        <strong>Enable reCAPTCHA Protection</strong>
                                    </label>
                                    <div class="form-text">Protect registration and login forms from bots</div>
                                </div>
                            </div>

                            <div id="recaptchaSettings" style="<?php echo $recaptcha_config['enabled'] ? '' : 'display: none;'; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Site Key (Public)</label>
                                    <input type="text" name="recaptcha_site_key" class="form-control"
                                           value="<?php echo htmlspecialchars($recaptcha_config['site_key']); ?>"
                                           placeholder="6Lc...">
                                    <div class="form-text">Visible in the HTML</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Secret Key (Private)</label>
                                    <input type="password" name="recaptcha_secret_key" class="form-control"
                                           value="<?php echo htmlspecialchars($recaptcha_config['secret_key']); ?>"
                                           placeholder="6Lc...">
                                    <div class="form-text">Keep this secret! Never expose in frontend</div>
                                </div>

                                <div class="alert alert-secondary">
                                    <small>
                                        <i class="fas fa-external-link-alt"></i>
                                        Get your keys from <a href="https://www.google.com/recaptcha/admin" target="_blank">Google reCAPTCHA Admin</a>
                                        <br>Choose reCAPTCHA v3 when creating your site.
                                    </small>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Update reCAPTCHA Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Site Settings -->
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-globe"></i> Site Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_site_settings">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Site Name</label>
                                        <input type="text" name="site_name" class="form-control"
                                               value="<?php echo htmlspecialchars($site_config['name']); ?>"
                                               placeholder="ArcGeek Survey">
                                        <div class="form-text">Displayed in header and titles</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Support Email</label>
                                        <input type="email" name="support_email" class="form-control"
                                               value="<?php echo htmlspecialchars($site_config['support_email']); ?>"
                                               placeholder="soporte@arcgeek.com">
                                        <div class="form-text">Contact email for users</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Logo URL (Optional)</label>
                                        <input type="url" name="logo_url" class="form-control"
                                               value="<?php echo htmlspecialchars($site_config['logo_url']); ?>"
                                               placeholder="https://example.com/logo.png">
                                        <div class="form-text">Full URL to your logo image</div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Footer Text</label>
                                        <input type="text" name="footer_text" class="form-control"
                                               value="<?php echo htmlspecialchars($site_config['footer_text']); ?>"
                                               placeholder="© 2024 ArcGeek. Open Source Project">
                                        <div class="form-text">Displayed in page footer</div>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-save"></i> Update Site Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-code"></i> Integration Instructions</h6>
                    </div>
                    <div class="card-body">
                        <h6>Plugin Configuration (api_client.py)</h6>
                        <p class="small text-muted">The plugin will automatically retrieve the token from the server. No manual configuration needed.</p>

                        <h6 class="mt-3">Security Best Practices</h6>
                        <ul class="small">
                            <li>Rotate the plugin token periodically</li>
                            <li>Never share the reCAPTCHA secret key</li>
                            <li>Monitor failed authentication attempts in logs</li>
                            <li>Keep tokens in database encrypted</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('recaptchaEnabled').addEventListener('change', function() {
            const settings = document.getElementById('recaptchaSettings');
            settings.style.display = this.checked ? 'block' : 'none';
        });

        function copyToken() {
            const tokenText = document.getElementById('currentToken').textContent.trim();
            navigator.clipboard.writeText(tokenText).then(() => {
                alert('✅ Token copied to clipboard!');
            }).catch(() => {
                alert('❌ Failed to copy token');
            });
        }
    </script>
</body>
</html>
