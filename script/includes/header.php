<?php
// Global Header Component for ArcGeek Survey
// Include this file at the top of your pages after starting session

if (!defined('ARCGEEK_SURVEY')) {
    die('Direct access not permitted');
}

// Get site configuration
$site_config = get_site_config();
$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Determine current language
$lang = $_GET['lang'] ?? ($_SESSION['lang'] ?? 'en');
if (!in_array($lang, ['en', 'es'])) $lang = 'en';

// Get user info if logged in
$is_logged_in = isset($_SESSION['user_id']);
$user_name = '';
if ($is_logged_in) {
    $user = get_user_by_id($_SESSION['user_id']);
    $user_name = $user['name'] ?? $user['email'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($site_config['name']); ?> - Professional georeferenced surveys">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?><?php echo htmlspecialchars($site_config['name']); ?></title>

    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <!-- Custom Styles -->
    <style>
        .navbar-brand img {
            max-height: 40px;
            margin-right: 10px;
        }
        @media (max-width: 768px) {
            .navbar-nav {
                background: rgba(0,0,0,0.05);
                padding: 10px;
                border-radius: 5px;
                margin-top: 10px;
            }
            .navbar-brand {
                font-size: 0.9rem;
            }
        }
    </style>

    <?php if (isset($additional_head_content)) echo $additional_head_content; ?>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark <?php echo isset($navbar_class) ? $navbar_class : 'bg-primary'; ?> shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="<?php echo $is_logged_in ? '/survey/dashboard/' : '/survey/'; ?>">
                <?php if (!empty($site_config['logo_url'])): ?>
                    <img src="<?php echo htmlspecialchars($site_config['logo_url']); ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-map-marked-alt me-2"></i>
                <?php endif; ?>
                <span class="d-none d-md-inline"><?php echo htmlspecialchars($site_config['name']); ?></span>
                <span class="d-inline d-md-none">ArcGeek</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if ($is_logged_in): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'index' || $current_page === 'dashboard' ? 'active' : ''; ?>" href="/survey/dashboard/">
                                <i class="fas fa-home"></i> <span class="d-none d-lg-inline">Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'forms' ? 'active' : ''; ?>" href="/survey/dashboard/forms.php">
                                <i class="fas fa-wpforms"></i> <span class="d-none d-lg-inline">Forms</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'settings' ? 'active' : ''; ?>" href="/survey/dashboard/settings.php">
                                <i class="fas fa-cog"></i> <span class="d-none d-lg-inline">Settings</span>
                            </a>
                        </li>
                        <?php if ($user['role'] === 'admin' || $user['email'] === 'franzpc@gmail.com'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-warning" href="/survey/admin/">
                                    <i class="fas fa-shield-alt"></i> <span class="d-none d-lg-inline">Admin</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($user_name); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/survey/auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="https://github.com/franzpc/arcgeek_survey" target="_blank">
                                <i class="fab fa-github"></i> GitHub
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'login' ? 'active' : ''; ?>" href="/survey/auth/login.php?lang=<?php echo $lang; ?>">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'register' ? 'active' : ''; ?>" href="/survey/auth/register.php?lang=<?php echo $lang; ?>">
                                <i class="fas fa-user-plus"></i> Register
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Language Selector -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-globe"></i> <?php echo strtoupper($lang); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item <?php echo $lang === 'en' ? 'active' : ''; ?>" href="?lang=en">English</a></li>
                            <li><a class="dropdown-item <?php echo $lang === 'es' ? 'active' : ''; ?>" href="?lang=es">Español</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="<?php echo isset($container_class) ? $container_class : 'container'; ?> <?php echo isset($no_margin) ? '' : 'mt-4 mb-4'; ?>">
