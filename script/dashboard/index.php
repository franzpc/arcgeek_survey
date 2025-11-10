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

$user_id = $_SESSION['user_id'];
$user = get_user_by_id($user_id);
if (!$user) {
    header('Location: ../auth/logout.php');
    exit();
}

$lang = $user['language'];
$strings = include "../lang/{$lang}.php";

$limits = get_plan_limits($user['plan_type']);
$usage = get_user_usage($user_id);

$stmt = $pdo->prepare("SELECT * FROM forms WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$forms = $stmt->fetchAll();

$total_responses = 0;
foreach ($forms as $form) {
    $total_responses += $form['response_count'];
}

$is_admin = $user['email'] === 'franzpc@gmail.com';
if ($is_admin && $user['role'] !== 'admin') {
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$user_id]);
    $user['role'] = 'admin';
}

$can_create = validate_form_creation($user_id, $user['plan_type']);
$has_config = has_database_config($user);
$storage_type = get_storage_type($user);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $strings['dashboard']; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-map-marked-alt"></i> ArcGeek Survey
            </a>
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($user['name']); ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="settings.php"><?php echo $strings['settings']; ?></a></li>
                        <?php if ($is_admin): ?>
                            <li><a class="dropdown-item" href="../admin/"><?php echo $strings['admin_panel']; ?></a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="../auth/logout.php" class="d-inline">
                                <button type="submit" class="dropdown-item"><?php echo $strings['logout']; ?></button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8">
                <h2><?php echo $strings['dashboard']; ?></h2>
                <p class="text-muted"><?php echo $strings['manage_forms']; ?></p>
            </div>
            <div class="col-md-4 text-end">
                <?php if ($can_create): ?>
                    <a href="forms.php?action=create" class="btn btn-primary">
                        <i class="fas fa-plus"></i> <?php echo $strings['new_form']; ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-primary"><?php echo $usage['forms_count']; ?> / <?php echo $limits['forms_limit'] == -1 ? '∞' : $limits['forms_limit']; ?></h5>
                        <p class="card-text"><?php echo $strings['forms']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-success"><?php echo $total_responses; ?></h5>
                        <p class="card-text"><?php echo $strings['responses']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-warning"><?php echo ucfirst($user['plan_type']); ?></h5>
                        <p class="card-text"><?php echo $strings['plan']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title text-info"><?php echo $limits['fields_limit']; ?></h5>
                        <p class="card-text"><?php echo $strings['max_fields']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($user['plan_type'] === PLAN_FREE && !$has_config): ?>
            <div class="alert alert-info">
                <h5><i class="fas fa-rocket"></i> Upgrade to Basic Automatically!</h5>
                <p>Configure your own Supabase or PostgreSQL database to automatically upgrade to Basic Plan with more forms, spatial features, and unlimited storage!</p>
                <a href="settings.php" class="btn btn-info">Configure Database & Upgrade</a>
            </div>
        <?php endif; ?>

        <?php if ($storage_type === 'admin_supabase' && $user['plan_type'] !== PLAN_FREE): ?>
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Using Shared Database</h6>
                <p class="mb-0">You're using our shared database. <a href="settings.php">Configure your own database</a> to enable advanced spatial features.</p>
            </div>
        <?php endif; ?>

        <?php if (!$can_create): ?>
            <div class="alert alert-warning">
                <h5><i class="fas fa-exclamation-triangle"></i> <?php echo $strings['limit_reached']; ?></h5>
                <p><?php echo $strings['upgrade_to_create_more']; ?></p>
                <?php if (can_upgrade_plan($user['plan_type'])): ?>
                    <a href="settings.php" class="btn btn-warning"><?php echo $strings['upgrade_plan']; ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-list"></i> <?php echo $strings['my_forms']; ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($forms)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-clipboard-list text-muted" style="font-size: 3rem;"></i>
                        <h5 class="mt-3"><?php echo $strings['no_forms_yet']; ?></h5>
                        <p class="text-muted"><?php echo $strings['create_first_form']; ?></p>
                        <?php if ($can_create): ?>
                            <a href="forms.php?action=create" class="btn btn-primary">
                                <i class="fas fa-plus"></i> <?php echo $strings['create_form']; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo $strings['title']; ?></th>
                                    <th><?php echo $strings['code']; ?></th>
                                    <th><?php echo $strings['responses']; ?></th>
                                    <th><?php echo $strings['storage']; ?></th>
                                    <th><?php echo $strings['created']; ?></th>
                                    <th><?php echo $strings['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($forms as $form): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($form['title']); ?></strong>
                                            <?php if ($form['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($form['description'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($form['form_code']); ?></code></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $form['response_count']; ?></span>
                                            <small class="text-muted">/ <?php echo $form['max_responses']; ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $storage_labels = [
                                                'admin_supabase' => $strings['shared_db'] ?? 'Shared DB',
                                                'user_supabase' => $strings['your_supabase'] ?? 'Your Supabase',
                                                'user_postgres' => $strings['your_postgres'] ?? 'Your PostgreSQL'
                                            ];
                                            echo $storage_labels[$form['storage_type']] ?? $form['storage_type'];
                                            ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($form['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view-data.php?id=<?php echo $form['id']; ?>" class="btn btn-outline-primary" title="<?php echo $strings['view_data']; ?>">
                                                    <i class="fas fa-chart-bar"></i>
                                                </a>
                                                <a href="forms.php?action=edit&id=<?php echo $form['id']; ?>" class="btn btn-outline-secondary" title="<?php echo $strings['edit']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="../public/collect.php?code=<?php echo $form['form_code']; ?>" class="btn btn-outline-success" title="<?php echo $strings['collect']; ?>" target="_blank">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>