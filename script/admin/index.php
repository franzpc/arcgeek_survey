<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/plans.php';
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

if ($user['email'] === 'franzpc@gmail.com' && $user['role'] !== 'admin') {
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user['role'] = 'admin';
}

$lang = $user['language'];
$strings = include "../lang/{$lang}.php";

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $target_user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    
    switch ($action) {
        case 'update_plan':
            $new_plan = $_POST['plan_type'] ?? '';
            if ($target_user_id && in_array($new_plan, ['free', 'basic', 'premium'])) {
                $stmt = $pdo->prepare("UPDATE users SET plan_type = ? WHERE id = ?");
                if ($stmt->execute([$new_plan, $target_user_id])) {
                    $message = 'Plan updated successfully';
                } else {
                    $error = 'Error updating plan';
                }
            }
            break;
            
        case 'toggle_status':
            if ($target_user_id) {
                $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $target_email = $stmt->fetchColumn();
                
                if ($target_email !== 'franzpc@gmail.com') {
                    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    if ($stmt->execute([$target_user_id])) {
                        $message = 'User status updated';
                    } else {
                        $error = 'Error updating status';
                    }
                } else {
                    $error = 'Cannot modify admin user status';
                }
            }
            break;
            
        case 'delete_user':
            if ($target_user_id) {
                $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
                $stmt->execute([$target_user_id]);
                $target_email = $stmt->fetchColumn();
                
                if ($target_email !== 'franzpc@gmail.com') {
                    try {
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("UPDATE forms SET is_active = 0, deleted_at = NOW() WHERE user_id = ?");
                        $stmt->execute([$target_user_id]);
                        
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        if ($stmt->execute([$target_user_id])) {
                            $pdo->commit();
                            $message = 'User deleted successfully';
                        } else {
                            $pdo->rollBack();
                            $error = 'Error deleting user';
                        }
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Error: ' . $e->getMessage();
                    }
                } else {
                    $error = 'Cannot delete admin user';
                }
            }
            break;
            
        case 'send_verification':
            if ($target_user_id) {
                try {
                    $stmt = $pdo->prepare("SELECT name, email, language FROM users WHERE id = ? AND email_verified = 0");
                    $stmt->execute([$target_user_id]);
                    $target_user = $stmt->fetch();
                    
                    if ($target_user) {
                        $verification_token = generate_verification_token();
                        
                        $stmt = $pdo->prepare("INSERT INTO email_verifications (user_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW()");
                        $stmt->execute([$target_user_id, $verification_token]);
                        
                        if (send_verification_email($target_user['email'], $target_user['name'], $verification_token, $target_user['language'])) {
                            $message = 'Verification email sent successfully';
                        } else {
                            $error = 'Failed to send verification email';
                        }
                    } else {
                        $error = 'User not found or already verified';
                    }
                } catch (Exception $e) {
                    $error = 'Error sending verification: ' . $e->getMessage();
                }
            }
            break;
    }
}

$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$total_users = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
$active_users = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email_verified = 1");
$verified_users = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE is_active = 1");
$total_forms = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(response_count) FROM forms WHERE is_active = 1");
$total_responses = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$active_30d = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT plan_type, COUNT(*) as count FROM users GROUP BY plan_type");
$plan_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $pdo->query("SELECT storage_type, COUNT(*) as count FROM forms WHERE is_active = 1 GROUP BY storage_type");
$storage_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$where_clauses = [];
$params = [];

if ($search) {
    $where_clauses[] = "(name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter === 'unverified') {
    $where_clauses[] = "email_verified = 0";
} elseif ($filter === 'inactive') {
    $where_clauses[] = "is_active = 0";
} elseif ($filter === 'no_login') {
    $where_clauses[] = "last_login IS NULL";
}

$where_clause = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$stmt = $pdo->prepare("SELECT * FROM users $where_clause ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$limit, $offset]));
$users = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where_clause");
$stmt->execute($params);
$total_items = $stmt->fetchColumn();
$total_pages = ceil($total_items / $limit);

function get_cleanup_candidates() {
    global $pdo;
    
    $candidates = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE email_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $candidates['unverified'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL 365 DAY) AND email != 'franzpc@gmail.com'");
    $candidates['inactive'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE response_count = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 180 DAY) AND is_active = 1");
    $candidates['unused_forms'] = $stmt->fetchColumn();
    
    return $candidates;
}

$cleanup_candidates = get_cleanup_candidates();

$page_title = $strings['admin_panel'];
$navbar_class = "bg-danger";
include '../includes/header.php';
?>
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

        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <h4 class="text-primary"><?php echo number_format($total_users); ?></h4>
                        <p class="card-text small">Total Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h4 class="text-success"><?php echo number_format($active_users); ?></h4>
                        <p class="card-text small">Active Users</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h4 class="text-info"><?php echo number_format($verified_users); ?></h4>
                        <p class="card-text small">Verified</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h4 class="text-warning"><?php echo number_format($total_forms); ?></h4>
                        <p class="card-text small">Total Forms</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-secondary">
                    <div class="card-body">
                        <h4 class="text-secondary"><?php echo number_format($total_responses); ?></h4>
                        <p class="card-text small">Responses</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center border-dark">
                    <div class="card-body">
                        <h4 class="text-dark"><?php echo number_format($active_30d); ?></h4>
                        <p class="card-text small">Active 30d</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-chart-pie"></i> Plan Distribution</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($plan_stats as $plan => $count): ?>
                            <div class="d-flex justify-content-between">
                                <span><?php echo ucfirst($plan); ?>:</span>
                                <strong><?php echo $count; ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-database"></i> Storage Distribution</h6>
                    </div>
                    <div class="card-body">
                        <?php 
                        $storage_labels = [
                            'admin_supabase' => 'Shared DB',
                            'user_supabase' => 'User Supabase',
                            'user_postgres' => 'User PostgreSQL'
                        ];
                        foreach ($storage_stats as $storage => $count): 
                        ?>
                            <div class="d-flex justify-content-between">
                                <span><?php echo $storage_labels[$storage] ?? $storage; ?>:</span>
                                <strong><?php echo $count; ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-exclamation-triangle"></i> Cleanup Candidates</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <span>Unverified (7d+):</span>
                            <strong class="text-warning"><?php echo $cleanup_candidates['unverified']; ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Inactive (365d+):</span>
                            <strong class="text-danger"><?php echo $cleanup_candidates['inactive']; ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Unused Forms (180d+):</span>
                            <strong class="text-info"><?php echo $cleanup_candidates['unused_forms']; ?></strong>
                        </div>
                        <hr>
                        <div class="text-center">
                            <a href="system-settings.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-cogs"></i> Manage Cleanup
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <h5><i class="fas fa-users"></i> User Management</h5>
                    </div>
                    <div class="col-md-6">
                        <form method="GET" class="d-flex">
                            <input type="text" name="search" class="form-control me-2" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                            <select name="filter" class="form-select me-2" style="width: auto;">
                                <option value="">All Users</option>
                                <option value="unverified" <?php echo $filter === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
                                <option value="inactive" <?php echo $filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="no_login" <?php echo $filter === 'no_login' ? 'selected' : ''; ?>>Never Logged In</option>
                            </select>
                            <button type="submit" class="btn btn-outline-primary">Filter</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Verification</th>
                                <th>Forms</th>
                                <th>Last Login</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE user_id = ? AND is_active = 1");
                                $stmt->execute([$u['id']]);
                                $user_forms = $stmt->fetchColumn();
                                
                                $stmt = $pdo->prepare("SELECT SUM(response_count) FROM forms WHERE user_id = ?");
                                $stmt->execute([$u['id']]);
                                $user_responses = $stmt->fetchColumn() ?: 0;
                                ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($u['name']); ?></strong>
                                            <?php if ($u['role'] === 'admin'): ?>
                                                <span class="badge bg-danger ms-1">ADMIN</span>
                                            <?php endif; ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($u['email']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($u['email'] !== 'franzpc@gmail.com'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="update_plan">
                                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                <select name="plan_type" class="form-select form-select-sm" onchange="this.form.submit()">
                                                    <option value="free" <?php echo $u['plan_type'] === 'free' ? 'selected' : ''; ?>>Free</option>
                                                    <option value="basic" <?php echo $u['plan_type'] === 'basic' ? 'selected' : ''; ?>>Basic</option>
                                                    <option value="premium" <?php echo $u['plan_type'] === 'premium' ? 'selected' : ''; ?>>Premium</option>
                                                </select>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($u['email_verified']): ?>
                                            <span class="badge bg-success">Verified</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unverified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo $user_forms; ?></strong> forms
                                        <br><small class="text-muted"><?php echo $user_responses; ?> responses</small>
                                    </td>
                                    <td>
                                        <?php if ($u['last_login']): ?>
                                            <span title="<?php echo $u['last_login']; ?>">
                                                <?php echo date('M j, Y', strtotime($u['last_login'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($u['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if (!$u['email_verified']): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="send_verification">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-info" title="Send Verification Email">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($u['email'] !== 'franzpc@gmail.com'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-<?php echo $u['is_active'] ? 'warning' : 'success'; ?>" title="Toggle Status">
                                                        <i class="fas fa-<?php echo $u['is_active'] ? 'ban' : 'check'; ?>"></i>
                                                    </button>
                                                </form>
                                                
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete user and all their data? This action cannot be undone.')">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="Delete User">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= min($total_pages, 10); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($total_pages > 10): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $filter ? '&filter=' . urlencode($filter) : ''; ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    
                    <div class="text-center">
                        <small class="text-muted">
                            Showing <?php echo number_format($offset + 1); ?>-<?php echo number_format(min($offset + $limit, $total_items)); ?> 
                            of <?php echo number_format($total_items); ?> users
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>