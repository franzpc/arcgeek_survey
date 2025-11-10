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

$lang = $user['language'];
$strings = include "../lang/{$lang}.php";

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_admin_supabase') {
        $admin_url = trim($_POST['admin_supabase_url'] ?? '');
        $admin_key = trim($_POST['admin_supabase_key'] ?? '');
        
        if (empty($admin_url) || empty($admin_key)) {
            $error = 'URL and API Key are required';
        } elseif (!validate_supabase_url($admin_url) || !validate_supabase_key($admin_key)) {
            $error = 'Invalid Supabase credentials';
        } elseif (!test_supabase_connection($admin_url, $admin_key)) {
            $error = 'Connection test failed';
        } else {
            try {
                $encrypted_url = encrypt_credential($admin_url);
                $encrypted_key = encrypt_credential($admin_key);
                
                $stmt = $pdo->prepare("UPDATE admin_config SET config_value = ? WHERE config_key = ?");
                $stmt->execute([$encrypted_url, 'admin_supabase_url']);
                $stmt->execute([$encrypted_key, 'admin_supabase_key']);
                
                $message = 'Admin Supabase configuration updated successfully';
            } catch (Exception $e) {
                error_log("Admin config error: " . $e->getMessage());
                $error = 'Error updating configuration';
            }
        }
    }
    
    if ($action === 'test_admin_connection') {
        $url = trim($_POST['test_url'] ?? '');
        $key = trim($_POST['test_key'] ?? '');
        
        if (!empty($url) && !empty($key)) {
            $result = test_supabase_connection($url, $key);
            header('Content-Type: application/json');
            echo json_encode(['success' => $result]);
            exit();
        }
    }
    
    if ($action === 'create_free_table') {
        $table_name = trim($_POST['table_name'] ?? '');
        $fields_json = $_POST['fields'] ?? '[]';
        
        if (empty($table_name)) {
            $error = 'Table name is required';
        } elseif (!validate_table_name($table_name)) {
            $error = 'Invalid table name format';
        } else {
            $fields = json_decode($fields_json, true);
            if (!$fields) {
                $fields = [
                    ['name' => 'participant_name', 'type' => 'text'],
                    ['name' => 'email', 'type' => 'email'],
                    ['name' => 'comments', 'type' => 'textarea']
                ];
            }
            
            $admin_config = get_admin_supabase_config();
            if (empty($admin_config['url']) || empty($admin_config['key'])) {
                $error = 'Admin Supabase not configured';
            } else {
                $created = create_supabase_table($admin_config['url'], $admin_config['key'], $table_name, $fields);
                if ($created) {
                    $message = "Table '$table_name' created successfully";
                } else {
                    $error = "Failed to create table '$table_name'";
                }
            }
        }
    }
}

$admin_config = get_admin_supabase_config();

$stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE storage_type = 'admin_supabase'");
$free_forms_count = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM responses_free");
$free_responses_count = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE plan_type = 'free'");
$free_users_count = $stmt->fetchColumn();

$page_title = "Admin Configuration";
$navbar_class = "bg-danger";
include '../includes/header.php';
?>

<div class="row">
    <div class="col-md-8">
        <h2><i class="fas fa-cog"></i> System Configuration</h2>
        <p class="text-muted">Configure shared Supabase for FREE plan users</p>
    </div>
</div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-info"><?php echo $free_users_count; ?></h5>
                        <p class="card-text">Free Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-primary"><?php echo $free_forms_count; ?></h5>
                        <p class="card-text">Free Forms</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-success"><?php echo $free_responses_count; ?></h5>
                        <p class="card-text">Free Responses</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="<?php echo !empty($admin_config['url']) ? 'text-success' : 'text-warning'; ?>">
                            <i class="fas fa-<?php echo !empty($admin_config['url']) ? 'check' : 'exclamation-triangle'; ?>"></i>
                        </h5>
                        <p class="card-text">Supabase Status</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-database"></i> Admin Supabase Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle"></i> Important</h6>
                            <p class="mb-0">This Supabase instance will store all FREE plan user data. Make sure it has sufficient storage and bandwidth.</p>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="update_admin_supabase">
                            
                            <div class="mb-3">
                                <label class="form-label">Supabase Project URL</label>
                                <input type="url" name="admin_supabase_url" class="form-control" 
                                       value="<?php echo htmlspecialchars($admin_config['url']); ?>" 
                                       placeholder="https://your-project.supabase.co" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Supabase API Key (anon/public)</label>
                                <input type="password" name="admin_supabase_key" class="form-control" 
                                       value="<?php echo htmlspecialchars($admin_config['key']); ?>" 
                                       placeholder="Your anon/public API key" required>
                            </div>

                            <div class="mb-3">
                                <button type="button" class="btn btn-outline-info" onclick="testAdminConnection()">
                                    <i class="fas fa-plug"></i> Test Connection
                                </button>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Configuration
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h6>Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($admin_config['url'])): ?>
                            <div class="mb-3">
                                <label class="form-label">Create Test Table</label>
                                <form method="POST">
                                    <input type="hidden" name="action" value="create_free_table">
                                    <div class="input-group">
                                        <input type="text" name="table_name" class="form-control" 
                                               placeholder="test_table_<?php echo date('md'); ?>" required>
                                        <button type="submit" class="btn btn-outline-primary">Create</button>
                                    </div>
                                </form>
                            </div>

                            <div class="mb-3">
                                <a href="<?php echo $admin_config['url']; ?>" target="_blank" class="btn btn-outline-success w-100">
                                    <i class="fas fa-external-link-alt"></i> Open Supabase Dashboard
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <small>Configure Supabase first to enable quick actions</small>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <a href="../public/api.php?action=stats&admin=1&api_key=<?php echo hash('sha256', 'franzpc@gmail.com'); ?>" 
                               target="_blank" class="btn btn-outline-info w-100">
                                <i class="fas fa-chart-bar"></i> API Stats
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h6>System Info</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small">
                            <li><strong>PHP:</strong> <?php echo PHP_VERSION; ?></li>
                            <li><strong>MySQL:</strong> <?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></li>
                            <li><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></li>
                            <li><strong>Disk Space:</strong> <?php echo disk_free_space('.') ? round(disk_free_space('.') / 1024 / 1024 / 1024, 2) . ' GB' : 'Unknown'; ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

<?php
$additional_footer_scripts = '
<script>
    function testAdminConnection() {
        const url = document.querySelector(\'input[name="admin_supabase_url"]\').value;
        const key = document.querySelector(\'input[name="admin_supabase_key"]\').value;

        if (!url || !key) {
            alert(\'Please enter both URL and API Key\');
            return;
        }

        const formData = new FormData();
        formData.append(\'action\', \'test_admin_connection\');
        formData.append(\'test_url\', url);
        formData.append(\'test_key\', key);

        fetch(\'\', {
            method: \'POST\',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(\'✅ Connection successful!\');
            } else {
                alert(\'❌ Connection failed. Check your credentials.\');
            }
        })
        .catch(() => {
            alert(\'❌ Error testing connection\');
        });
    }
</script>
';

include '../includes/footer.php';
?>