<?php
define('ARCGEEK_SURVEY', true);
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard/');
    exit();
}

$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'es'])) $lang = 'en';
$strings = include "lang/{$lang}.php";
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ArcGeek Survey - <?php echo $strings['georeferenced_surveys']; ?></title>
    <meta name="description" content="QGIS Plugin for professional georeferenced surveys with GPS data collection and PostgreSQL integration">
    <meta name="keywords" content="QGIS plugin, georeferenced surveys, GPS, PostGIS, PostgreSQL, spatial data, mobile surveys">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
            padding: 4rem 0;
        }
        .hero-icon {
            font-size: 4rem;
            opacity: 0.9;
            margin-bottom: 1rem;
        }
        .feature-icon {
            font-size: 3rem;
            color: #0d6efd;
            margin-bottom: 1rem;
        }
        .step-number {
            width: 60px;
            height: 60px;
            font-size: 1.5rem;
            font-weight: bold;
        }
        .plugin-badge {
            background: linear-gradient(45deg, #28a745, #20c997);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            display: inline-block;
        }
        .compatibility-list {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 2rem 0;
        }
        .tech-stack {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 2rem;
        }
        .version-badge {
            background-color: #6f42c1;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .github-stats {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            text-align: center;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold d-flex align-items-center" href="#">
                <i class="fas fa-puzzle-piece me-2"></i>
                <span>ArcGeek Survey</span>
                <span class="version-badge ms-2">v1.0.0</span>
            </a>
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <a href="https://github.com/franzpc/arcgeek_survey" target="_blank" class="nav-link me-3">
                    <i class="fab fa-github"></i>
                </a>
                <a href="?lang=en" class="nav-link <?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
                <a href="?lang=es" class="nav-link <?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="plugin-badge">
                        <i class="fas fa-plug me-1"></i> <?php echo $strings['qgis_plugin'] ?? 'QGIS Plugin'; ?>
                    </div>
                    <h1 class="display-4 fw-bold mb-4">
                        <?php echo $strings['georeferenced_surveys']; ?>
                    </h1>
                    <p class="lead mb-4">
                        <?php echo $strings['hero_description'] ?? 'Professional spatial data collection plugin for QGIS with mobile GPS integration, PostgreSQL/PostGIS support, and real-time survey management.'; ?>
                    </p>
                    <div class="d-flex flex-wrap gap-3 mb-4">
                        <a href="auth/register.php?lang=<?php echo $lang; ?>" class="btn btn-light btn-lg px-4">
                            <i class="fas fa-user-plus"></i> <?php echo $strings['create_account']; ?>
                        </a>
                        <a href="auth/login.php?lang=<?php echo $lang; ?>" class="btn btn-outline-light btn-lg px-4">
                            <i class="fas fa-sign-in-alt"></i> <?php echo $strings['login']; ?>
                        </a>
                    </div>
                    <div class="github-stats">
                        <small class="text-muted">
                            <i class="fab fa-github me-1"></i>
                            <?php echo $strings['open_source_compatible'] ?? 'Open Source • Compatible with QGIS 3.4+ & 4.x Ready'; ?>
                        </small>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <div class="hero-icon">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <div class="row g-2 mt-3">
                        <div class="col-4">
                            <div class="bg-light bg-opacity-25 rounded p-2">
                                <i class="fas fa-mobile-alt fa-2x text-light"></i>
                                <div class="small mt-1"><?php echo $strings['mobile'] ?? 'Mobile'; ?></div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light bg-opacity-25 rounded p-2">
                                <i class="fas fa-database fa-2x text-light"></i>
                                <div class="small mt-1">PostGIS</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="bg-light bg-opacity-25 rounded p-2">
                                <i class="fas fa-map fa-2x text-light"></i>
                                <div class="small mt-1">QGIS</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Features -->
    <section class="py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <i class="fas fa-satellite-dish feature-icon"></i>
                            <h5 class="fw-bold"><?php echo $strings['gps_precision'] ?? 'GPS Precision'; ?></h5>
                            <p class="text-muted">
                                <?php echo $strings['gps_description'] ?? 'Automatic location capture with high-precision GPS coordinates for accurate spatial data collection in the field.'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <i class="fas fa-database feature-icon"></i>
                            <h5 class="fw-bold"><?php echo $strings['database_integration'] ?? 'Database Integration'; ?></h5>
                            <p class="text-muted">
                                <?php echo $strings['database_description'] ?? 'Seamless PostgreSQL/PostGIS and Supabase integration with real-time data synchronization and secure storage.'; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 border-0 shadow-sm text-center">
                        <div class="card-body">
                            <i class="fas fa-puzzle-piece feature-icon"></i>
                            <h5 class="fw-bold"><?php echo $strings['qgis_plugin_feature'] ?? 'QGIS Plugin'; ?></h5>
                            <p class="text-muted">
                                <?php echo $strings['qgis_description'] ?? 'Direct integration with QGIS for immediate data visualization and advanced spatial analysis capabilities.'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row">
                <div class="col-12 text-center mb-5">
                    <h2 class="fw-bold"><?php echo $strings['how_it_works'] ?? 'How ArcGeek Survey Works'; ?></h2>
                    <p class="text-muted"><?php echo $strings['workflow_description'] ?? 'Simple workflow for professional spatial data collection'; ?></p>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-md-3 text-center">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center step-number mb-3">
                        <span>1</span>
                    </div>
                    <h5 class="fw-bold"><?php echo $strings['step_design'] ?? 'Design'; ?></h5>
                    <p class="text-muted">
                        <?php echo $strings['step_design_description'] ?? 'Create custom survey forms in QGIS with various field types and validation rules'; ?>
                    </p>
                </div>
                
                <div class="col-md-3 text-center">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center step-number mb-3">
                        <span>2</span>
                    </div>
                    <h5 class="fw-bold"><?php echo $strings['step_deploy'] ?? 'Deploy'; ?></h5>
                    <p class="text-muted">
                        <?php echo $strings['step_deploy_description'] ?? 'Publish surveys to mobile-friendly web interface with GPS integration'; ?>
                    </p>
                </div>
                
                <div class="col-md-3 text-center">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center step-number mb-3">
                        <span>3</span>
                    </div>
                    <h5 class="fw-bold"><?php echo $strings['step_collect'] ?? 'Collect'; ?></h5>
                    <p class="text-muted">
                        <?php echo $strings['step_collect_description'] ?? 'Gather field data with automatic GPS coordinates and real-time validation'; ?>
                    </p>
                </div>
                
                <div class="col-md-3 text-center">
                    <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center step-number mb-3">
                        <span>4</span>
                    </div>
                    <h5 class="fw-bold"><?php echo $strings['step_analyze'] ?? 'Analyze'; ?></h5>
                    <p class="text-muted">
                        <?php echo $strings['step_analyze_description'] ?? 'Visualize and analyze spatial data directly in QGIS environment'; ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Technical Details -->
    <section class="py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="tech-stack text-center">
                        <h3 class="fw-bold mb-4"><?php echo $strings['tech_compatibility'] ?? 'Technical Stack & Compatibility'; ?></h3>
                        
                        <div class="compatibility-list">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <h6 class="fw-bold text-primary">
                                        <i class="fas fa-map me-2"></i><?php echo $strings['qgis_compatibility'] ?? 'QGIS Compatibility'; ?>
                                    </h6>
                                    <ul class="list-unstyled text-muted">
                                        <li><i class="fas fa-check text-success me-2"></i><?php echo $strings['qgis_versions'] ?? 'QGIS 3.4 - 3.99'; ?></li>
                                        <li><i class="fas fa-check text-success me-2"></i><?php echo $strings['qgis_4_ready'] ?? 'QGIS 4.x Ready'; ?></li>
                                        <li><i class="fas fa-check text-success me-2"></i><?php echo $strings['qt_support'] ?? 'Qt5 & Qt6 Support'; ?></li>
                                        <li><i class="fas fa-check text-success me-2"></i><?php echo $strings['cross_platform'] ?? 'Cross-platform'; ?></li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-bold text-primary">
                                        <i class="fas fa-server me-2"></i><?php echo $strings['database_support'] ?? 'Database Support'; ?>
                                    </h6>
                                    <ul class="list-unstyled text-muted">
                                        <li><i class="fas fa-check text-success me-2"></i>PostgreSQL/PostGIS</li>
                                        <li><i class="fas fa-check text-success me-2"></i>Supabase</li>
                                        <li><i class="fas fa-check text-success me-2"></i><?php echo $strings['realtime_sync'] ?? 'Real-time sync'; ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>


                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section class="py-5 bg-primary text-white">
        <div class="container text-center">
            <h3 class="fw-bold mb-3"><?php echo $strings['ready_to_start'] ?? 'Ready to Start Collecting Spatial Data?'; ?></h3>
            <p class="lead mb-4">
                <?php echo $strings['join_professionals'] ?? 'Join professionals using ArcGeek Survey for their georeferenced data collection needs'; ?>
            </p>
            <div class="d-flex justify-content-center gap-3 flex-wrap">
                <a href="auth/register.php?lang=<?php echo $lang; ?>" class="btn btn-light btn-lg px-4">
                    <i class="fas fa-rocket me-2"></i><?php echo $strings['get_started'] ?? 'Get Started Now'; ?>
                </a>
                <a href="https://github.com/franzpc/arcgeek_survey" target="_blank" class="btn btn-outline-light btn-lg px-4">
                    <i class="fab fa-github me-2"></i><?php echo $strings['view_github'] ?? 'View on GitHub'; ?>
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-light py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-puzzle-piece text-primary me-2"></i>
                        <strong>ArcGeek Survey</strong>
                        <span class="version-badge ms-2"><?php echo $strings['qgis_plugin_version'] ?? 'QGIS Plugin v1.0.0'; ?></span>
                    </div>
                    <p class="text-muted small mb-0">
                        <?php echo $strings['footer_description'] ?? 'Professional georeferenced surveys with QGIS integration'; ?>
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="d-flex justify-content-md-end gap-3">
                        <a href="https://github.com/franzpc/arcgeek_survey" class="text-light">
                            <i class="fab fa-github fa-lg"></i>
                        </a>
                        <a href="https://acolita.com/survey" class="text-light">
                            <i class="fas fa-globe fa-lg"></i>
                        </a>
                        <a href="mailto:soporte@arcgeek.com" class="text-light">
                            <i class="fas fa-envelope fa-lg"></i>
                        </a>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted"><?php echo $strings['copyright'] ?? '© 2024 ArcGeek. Open Source Project'; ?></small>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Simple fade-in animation
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.card, .hero-section, .tech-stack');
            elements.forEach(el => el.classList.add('fade-in'));
        });
    </script>
</body>
</html>