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

function get_user_statistics() {
    global $pdo;
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $stats['total_users'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as active_users FROM users WHERE is_active = 1");
    $stats['active_users'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as verified_users FROM users WHERE email_verified = 1");
    $stats['verified_users'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as unverified_users FROM users WHERE email_verified = 0");
    $stats['unverified_users'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT plan_type, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY plan_type");
    $plan_data = $stmt->fetchAll();
    $stats['plan_distribution'] = [];
    foreach ($plan_data as $row) {
        $stats['plan_distribution'][$row['plan_type']] = $row['count'];
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as users_with_forms FROM users WHERE id IN (SELECT DISTINCT user_id FROM forms WHERE is_active = 1)");
    $stats['users_with_forms'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as users_logged_in_30d FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stats['users_logged_in_30d'] = $stmt->fetchColumn();
    
    return $stats;
}

function get_form_statistics() {
    global $pdo;
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_forms FROM forms WHERE is_active = 1");
    $stats['total_forms'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as forms_with_responses FROM forms WHERE response_count > 0 AND is_active = 1");
    $stats['forms_with_responses'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as unused_forms FROM forms WHERE response_count = 0 AND is_active = 1");
    $stats['unused_forms'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT SUM(response_count) as total_responses FROM forms WHERE is_active = 1");
    $stats['total_responses'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->query("SELECT AVG(response_count) as avg_responses_per_form FROM forms WHERE is_active = 1");
    $stats['avg_responses_per_form'] = round($stmt->fetchColumn() ?: 0, 2);
    
    $stmt = $pdo->query("SELECT storage_type, COUNT(*) as count FROM forms WHERE is_active = 1 GROUP BY storage_type");
    $storage_data = $stmt->fetchAll();
    $stats['storage_distribution'] = [];
    foreach ($storage_data as $row) {
        $stats['storage_distribution'][$row['storage_type']] = $row['count'];
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as forms_created_30d FROM forms WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) AND is_active = 1");
    $stats['forms_created_30d'] = $stmt->fetchColumn();
    
    return $stats;
}

function get_daily_activity($days = 30) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_users
        FROM users 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $stmt->execute([$days]);
    $user_activity = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_forms
        FROM forms 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND is_active = 1
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $stmt->execute([$days]);
    $form_activity = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_responses
        FROM responses_free 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    $stmt->execute([$days]);
    $response_activity = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $activity = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $activity[] = [
            'date' => $date,
            'new_users' => $user_activity[$date] ?? 0,
            'new_forms' => $form_activity[$date] ?? 0,
            'new_responses' => $response_activity[$date] ?? 0
        ];
    }
    
    return $activity;
}

function get_top_forms($limit = 10) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.title,
            f.form_code,
            f.response_count,
            f.storage_type,
            f.created_at,
            u.name as user_name,
            u.email as user_email
        FROM forms f
        JOIN users u ON f.user_id = u.id
        WHERE f.is_active = 1
        ORDER BY f.response_count DESC, f.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

function get_storage_usage() {
    global $pdo;
    
    $stmt = $pdo->query("SELECT COUNT(*) as free_responses FROM responses_free");
    $free_responses = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT storage_type, COUNT(*) as form_count, SUM(response_count) as total_responses FROM forms WHERE is_active = 1 GROUP BY storage_type");
    $storage_data = $stmt->fetchAll();
    
    $usage = [
        'admin_supabase' => ['forms' => 0, 'responses' => $free_responses],
        'user_supabase' => ['forms' => 0, 'responses' => 0],
        'user_postgres' => ['forms' => 0, 'responses' => 0]
    ];
    
    foreach ($storage_data as $row) {
        $type = $row['storage_type'];
        if (isset($usage[$type])) {
            $usage[$type]['forms'] = $row['form_count'];
            if ($type !== 'admin_supabase') {
                $usage[$type]['responses'] = $row['total_responses'];
            }
        }
    }
    
    return $usage;
}

function get_recent_users($limit = 10) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            email,
            plan_type,
            is_active,
            email_verified,
            created_at,
            last_login
        FROM users
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

$user_stats = get_user_statistics();
$form_stats = get_form_statistics();
$daily_activity = get_daily_activity(30);
$top_forms = get_top_forms(10);
$storage_usage = get_storage_usage();
$recent_users = get_recent_users(10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - ArcGeek Survey Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-chart-line"></i> Analytics Dashboard
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">Admin Panel</a>
                <a class="nav-link" href="system-settings.php">Settings</a>
                <a class="nav-link" href="../dashboard/">Dashboard</a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8">
                <h2>System Analytics</h2>
                <p class="text-muted">Comprehensive overview of platform usage and performance</p>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <h3 class="text-primary"><?php echo number_format($user_stats['total_users']); ?></h3>
                        <p class="card-text">Total Users</p>
                        <small class="text-muted"><?php echo $user_stats['active_users']; ?> active</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h3 class="text-success"><?php echo number_format($form_stats['total_forms']); ?></h3>
                        <p class="card-text">Total Forms</p>
                        <small class="text-muted"><?php echo $form_stats['forms_with_responses']; ?> with responses</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h3 class="text-info"><?php echo number_format($form_stats['total_responses']); ?></h3>
                        <p class="card-text">Total Responses</p>
                        <small class="text-muted"><?php echo $form_stats['avg_responses_per_form']; ?> avg per form</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-body">
                        <h3 class="text-warning"><?php echo $user_stats['users_logged_in_30d']; ?></h3>
                        <p class="card-text">Active (30d)</p>
                        <small class="text-muted"><?php echo round(($user_stats['users_logged_in_30d'] / $user_stats['total_users']) * 100, 1); ?>% of total</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line"></i> Daily Activity (Last 30 Days)</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="activityChart" height="100"></canvas>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-trophy"></i> Top Performing Forms</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Form Title</th>
                                        <th>Code</th>
                                        <th>Responses</th>
                                        <th>Storage</th>
                                        <th>Owner</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_forms as $form): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($form['title']); ?></strong>
                                            </td>
                                            <td><code><?php echo $form['form_code']; ?></code></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $form['response_count']; ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                $storage_labels = [
                                                    'admin_supabase' => '<span class="badge bg-success">Shared</span>',
                                                    'user_supabase' => '<span class="badge bg-info">Supabase</span>',
                                                    'user_postgres' => '<span class="badge bg-warning">PostgreSQL</span>'
                                                ];
                                                echo $storage_labels[$form['storage_type']] ?? $form['storage_type'];
                                                ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($form['user_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($form['user_email']); ?></small>
                                                </div>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($form['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h6><i class="fas fa-users"></i> User Distribution</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($user_stats['plan_distribution'] as $plan => $count): ?>
                            <div class="d-flex justify-content-between mb-2">
                                <span><?php echo ucfirst($plan); ?>:</span>
                                <strong><?php echo $count; ?></strong>
                            </div>
                        <?php endforeach; ?>
                        
                        <hr>
                        
                        <div class="row text-center">
                            <div class="col-6">
                                <h6 class="text-success"><?php echo $user_stats['verified_users']; ?></h6>
                                <small class="text-muted">Verified</small>
                            </div>
                            <div class="col-6">
                                <h6 class="text-warning"><?php echo $user_stats['unverified_users']; ?></h6>
                                <small class="text-muted">Unverified</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h6><i class="fas fa-database"></i> Storage Usage</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach ($storage_usage as $type => $data): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>
                                        <?php 
                                        $type_names = [
                                            'admin_supabase' => 'Shared Database',
                                            'user_supabase' => 'User Supabase',
                                            'user_postgres' => 'User PostgreSQL'
                                        ];
                                        echo $type_names[$type];
                                        ?>
                                    </span>
                                    <small class="text-muted"><?php echo $data['forms']; ?> forms</small>
                                </div>
                                <div class="progress" style="height: 8px;">
                                    <?php 
                                    $total_responses = array_sum(array_column($storage_usage, 'responses'));
                                    $percentage = $total_responses > 0 ? ($data['responses'] / $total_responses) * 100 : 0;
                                    $color = $type === 'admin_supabase' ? 'success' : ($type === 'user_supabase' ? 'info' : 'warning');
                                    ?>
                                    <div class="progress-bar bg-<?php echo $color; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo number_format($data['responses']); ?> responses</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-clock"></i> Recent Users</h6>
                    </div>
                    <div class="card-body">
                        <?php foreach (array_slice($recent_users, 0, 5) as $user): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong><?php echo htmlspecialchars($user['name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-<?php echo $user['plan_type'] === 'free' ? 'secondary' : ($user['plan_type'] === 'basic' ? 'primary' : 'warning'); ?>">
                                        <?php echo ucfirst($user['plan_type']); ?>
                                    </span>
                                    <br><small class="text-muted"><?php echo date('M j', strtotime($user['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-exclamation-triangle"></i> System Health</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <h6 class="text-warning"><?php echo $user_stats['unverified_users']; ?></h6>
                                <small class="text-muted">Unverified Users</small>
                            </div>
                            <div class="col-6">
                                <h6 class="text-info"><?php echo $form_stats['unused_forms']; ?></h6>
                                <small class="text-muted">Unused Forms</small>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-6">
                                <h6 class="text-success"><?php echo $user_stats['users_with_forms']; ?></h6>
                                <small class="text-muted">Users with Forms</small>
                            </div>
                            <div class="col-6">
                                <h6 class="text-primary"><?php echo $form_stats['forms_created_30d']; ?></h6>
                                <small class="text-muted">Forms (30d)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6><i class="fas fa-server"></i> System Information</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
                            <li><strong>MySQL Version:</strong> <?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></li>
                            <li><strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></li>
                            <li><strong>Last Updated:</strong> <?php echo date('Y-m-d H:i:s'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const activityData = <?php echo json_encode($daily_activity); ?>;

        const activityCtx = document.getElementById('activityChart').getContext('2d');
        new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: activityData.map(d => new Date(d.date).toLocaleDateString('en-US', {month: 'short', day: 'numeric'})),
                datasets: [
                    {
                        label: 'New Users',
                        data: activityData.map(d => d.new_users),
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        tension: 0.1
                    },
                    {
                        label: 'New Forms',
                        data: activityData.map(d => d.new_forms),
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.1
                    },
                    {
                        label: 'New Responses',
                        data: activityData.map(d => d.new_responses),
                        borderColor: 'rgb(255, 159, 64)',
                        backgroundColor: 'rgba(255, 159, 64, 0.1)',
                        tension: 0.1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>