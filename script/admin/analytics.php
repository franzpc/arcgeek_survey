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

if ($user['email'] === 'franzpc@gmail.com' && $user['role'] !== 'admin') {
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user['role'] = 'admin';
}

// Get statistics
$stats = [];

// Users stats
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
$stats['total_users'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE plan_type = 'free'");
$stats['free_users'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE plan_type IN ('basic', 'premium')");
$stats['paid_users'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stats['new_users_30d'] = $stmt->fetchColumn();

// Forms stats
$stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE is_active = 1");
$stats['total_forms'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT SUM(response_count) FROM forms");
$stats['total_responses'] = $stmt->fetchColumn() ?? 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stats['new_forms_30d'] = $stmt->fetchColumn();

// Storage stats
$stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE storage_type = 'admin_supabase'");
$stats['free_plan_forms'] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM forms WHERE storage_type IN ('user_supabase', 'user_postgres')");
$stats['premium_forms'] = $stmt->fetchColumn();

// Recent activity
$stmt = $pdo->query("SELECT u.name, u.email, u.plan_type, u.created_at
                     FROM users u
                     ORDER BY u.created_at DESC
                     LIMIT 10");
$recent_users = $stmt->fetchAll();

$stmt = $pdo->query("SELECT f.title, f.response_count, f.created_at, u.name as user_name, u.email as user_email
                     FROM forms f
                     JOIN users u ON f.user_id = u.id
                     ORDER BY f.created_at DESC
                     LIMIT 10");
$recent_forms = $stmt->fetchAll();

// Daily stats for chart
$stmt = $pdo->query("SELECT DATE(created_at) as date, COUNT(*) as count
                     FROM users
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY DATE(created_at)
                     ORDER BY date ASC");
$daily_users = $stmt->fetchAll();

$stmt = $pdo->query("SELECT DATE(created_at) as date, COUNT(*) as count
                     FROM forms
                     WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY DATE(created_at)
                     ORDER BY date ASC");
$daily_forms = $stmt->fetchAll();

// Page configuration
$page_title = "Analytics Dashboard";
$navbar_class = "bg-danger";

// Include header
include '../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="fas fa-chart-line"></i> Analytics Dashboard</h2>
        <p class="text-muted">System-wide statistics and insights</p>
    </div>
</div>

<!-- Key Metrics -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body text-center">
                <i class="fas fa-users fa-2x text-primary mb-2"></i>
                <h3 class="mb-0"><?php echo number_format($stats['total_users']); ?></h3>
                <p class="text-muted mb-0">Total Users</p>
                <small class="text-success">
                    <i class="fas fa-arrow-up"></i> <?php echo $stats['new_users_30d']; ?> this month
                </small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <i class="fas fa-wpforms fa-2x text-success mb-2"></i>
                <h3 class="mb-0"><?php echo number_format($stats['total_forms']); ?></h3>
                <p class="text-muted mb-0">Active Forms</p>
                <small class="text-success">
                    <i class="fas fa-arrow-up"></i> <?php echo $stats['new_forms_30d']; ?> this month
                </small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <i class="fas fa-comments fa-2x text-info mb-2"></i>
                <h3 class="mb-0"><?php echo number_format($stats['total_responses']); ?></h3>
                <p class="text-muted mb-0">Total Responses</p>
                <small class="text-muted">All time</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <i class="fas fa-crown fa-2x text-warning mb-2"></i>
                <h3 class="mb-0"><?php echo number_format($stats['paid_users']); ?></h3>
                <p class="text-muted mb-0">Premium Users</p>
                <small class="text-muted"><?php echo round(($stats['paid_users'] / max($stats['total_users'], 1)) * 100, 1); ?>% of total</small>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-chart-area"></i> User Growth (Last 30 Days)</h5>
            </div>
            <div class="card-body">
                <canvas id="usersChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-chart-bar"></i> Form Creation (Last 30 Days)</h5>
            </div>
            <div class="card-body">
                <canvas id="formsChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Plan Distribution -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-chart-pie"></i> User Distribution by Plan</h5>
            </div>
            <div class="card-body">
                <canvas id="planChart" height="250"></canvas>
                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Free Users:</span>
                        <strong><?php echo number_format($stats['free_users']); ?> (<?php echo round(($stats['free_users'] / max($stats['total_users'], 1)) * 100, 1); ?>%)</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Premium Users:</span>
                        <strong><?php echo number_format($stats['paid_users']); ?> (<?php echo round(($stats['paid_users'] / max($stats['total_users'], 1)) * 100, 1); ?>%)</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-database"></i> Storage Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="storageChart" height="250"></canvas>
                <div class="mt-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Free Plan Storage:</span>
                        <strong><?php echo number_format($stats['free_plan_forms']); ?> forms</strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>User Storage:</span>
                        <strong><?php echo number_format($stats['premium_forms']); ?> forms</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-user-plus"></i> Recent User Registrations</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Plan</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['name']); ?></td>
                                <td><small><?php echo htmlspecialchars($u['email']); ?></small></td>
                                <td>
                                    <span class="badge bg-<?php echo $u['plan_type'] === 'free' ? 'secondary' : 'success'; ?>">
                                        <?php echo ucfirst($u['plan_type']); ?>
                                    </span>
                                </td>
                                <td><small><?php echo date('M d', strtotime($u['created_at'])); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-plus-circle"></i> Recently Created Forms</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>User</th>
                                <th>Responses</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_forms as $f): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($f['title']); ?></td>
                                <td><small><?php echo htmlspecialchars($f['user_name']); ?></small></td>
                                <td><span class="badge bg-info"><?php echo $f['response_count']; ?></span></td>
                                <td><small><?php echo date('M d', strtotime($f['created_at'])); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Additional footer scripts for charts
$additional_footer_scripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Users Chart
const usersData = ' . json_encode($daily_users) . ';
const usersChart = new Chart(document.getElementById("usersChart"), {
    type: "line",
    data: {
        labels: usersData.map(d => new Date(d.date).toLocaleDateString("en-US", {month: "short", day: "numeric"})),
        datasets: [{
            label: "New Users",
            data: usersData.map(d => d.count),
            borderColor: "#0d6efd",
            backgroundColor: "rgba(13, 110, 253, 0.1)",
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {display: false}
        }
    }
});

// Forms Chart
const formsData = ' . json_encode($daily_forms) . ';
const formsChart = new Chart(document.getElementById("formsChart"), {
    type: "bar",
    data: {
        labels: formsData.map(d => new Date(d.date).toLocaleDateString("en-US", {month: "short", day: "numeric"})),
        datasets: [{
            label: "New Forms",
            data: formsData.map(d => d.count),
            backgroundColor: "#198754",
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {display: false}
        }
    }
});

// Plan Distribution Chart
const planChart = new Chart(document.getElementById("planChart"), {
    type: "doughnut",
    data: {
        labels: ["Free Users", "Premium Users"],
        datasets: [{
            data: [' . $stats['free_users'] . ', ' . $stats['paid_users'] . '],
            backgroundColor: ["#6c757d", "#ffc107"],
        }]
    }
});

// Storage Distribution Chart
const storageChart = new Chart(document.getElementById("storageChart"), {
    type: "doughnut",
    data: {
        labels: ["Free Plan Storage", "User Storage"],
        datasets: [{
            data: [' . $stats['free_plan_forms'] . ', ' . $stats['premium_forms'] . '],
            backgroundColor: ["#0dcaf0", "#0d6efd"],
        }]
    }
});
</script>
';

include '../includes/footer.php';
?>
