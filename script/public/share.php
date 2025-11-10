<?php
define('ARCGEEK_SURVEY', true);
require_once '../config/database.php';
require_once '../config/security.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$form_code = $_GET['code'] ?? $_POST['form_code'] ?? '';
$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en', 'es'])) $lang = 'en';
$strings = include "../lang/{$lang}.php";

$form = null;
$error = '';
$responses = [];
$fields_config = [];
$stats = [
    'total' => 0,
    'with_coords' => 0,
    'avg_accuracy' => 0,
    'date_range' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'find_form') {
        $form_code = trim($_POST['form_code'] ?? '');
        if (empty($form_code)) {
            $error = $strings['form_code_enter'];
        } else {
            $form = get_form_by_code($form_code);
            if (!$form) {
                $error = $strings['invalid_form_code'];
            }
        }
    }
} elseif (!empty($form_code)) {
    try {
        $form = get_form_by_code($form_code);
    } catch (Exception $e) {
        error_log("Form lookup error: " . $e->getMessage());
        $error = $strings['server_error'];
    }
}

if ($form) {
    $fields_config = json_decode($form['fields_config'], true);
    $responses = get_responses($form['id'], 1000, 0);
    $stats = calculate_stats($responses);
}

function calculate_stats($responses) {
    $stats = [
        'total' => count($responses),
        'with_coords' => 0,
        'avg_accuracy' => 0,
        'date_range' => ''
    ];
    
    $valid_coords = array_filter($responses, function($r) {
        return !empty($r['latitude']) && !empty($r['longitude']);
    });
    
    $stats['with_coords'] = count($valid_coords);
    
    if ($stats['with_coords'] > 0) {
        $accuracies = array_filter(array_map(function($r) {
            return floatval($r['gps_accuracy'] ?? 0);
        }, $valid_coords));
        
        if (!empty($accuracies)) {
            $stats['avg_accuracy'] = array_sum($accuracies) / count($accuracies);
        }
        
        $dates = array_filter(array_map(function($r) {
            return $r['created_at'] ?? '';
        }, $responses));
        
        if (!empty($dates)) {
            sort($dates);
            $first_date = reset($dates);
            $last_date = end($dates);
            
            if ($first_date === $last_date) {
                $stats['date_range'] = date('M j, Y', strtotime($first_date));
            } else {
                $stats['date_range'] = date('M j', strtotime($first_date)) . ' - ' . date('M j, Y', strtotime($last_date));
            }
        }
    }
    
    return $stats;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $form ? htmlspecialchars($form['title']) . ' - Results' : $strings['search_form']; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <link href="../css/styles.css" rel="stylesheet">
    <style>
        .clickable-row { 
            cursor: pointer; 
            transition: background-color 0.2s;
        }
        .clickable-row:hover { 
            background-color: #f8f9fa; 
        }
        .selected-row { 
            background-color: #e3f2fd !important; 
        }
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .response-popup {
            max-width: 300px;
        }
        .response-popup .popup-title {
            font-weight: bold;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }
        .response-popup .popup-field {
            margin-bottom: 5px;
            font-size: 12px;
        }
        .response-popup .popup-label {
            font-weight: 600;
            color: #666;
        }
        #map {
            height: 500px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .share-link-section {
            background: rgba(255,255,255,0.95);
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 15px;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .copy-link-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
            font-size: 14px;
            padding: 6px 12px;
        }
        .copy-link-btn:hover {
            background: #e9ecef;
            color: #495057;
        }
        .share-input-group {
            border-radius: 6px;
            overflow: hidden;
        }
        .share-input-group input {
            border-right: none;
            font-size: 13px;
            background: #f8f9fa;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-map-marked-alt"></i> ArcGeek Survey
            </a>
            <div class="navbar-nav ms-auto">
                <a href="?lang=en&code=<?php echo urlencode($form_code); ?>" class="nav-link <?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
                <a href="?lang=es&code=<?php echo urlencode($form_code); ?>" class="nav-link <?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
                <a href="../dashboard/forms.php?action=create" class="nav-link">
                    <i class="fas fa-plus-circle"></i> <?php echo $lang === 'es' ? 'Crear Formulario' : 'Create Form'; ?>
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$form): ?>
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white text-center">
                            <h4><i class="fas fa-share-alt"></i> <?php echo $lang === 'es' ? 'Ver Resultados del Formulario' : 'View Form Results'; ?></h4>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="find_form">
                                <div class="mb-3">
                                    <label class="form-label"><?php echo $strings['form_code_enter']; ?></label>
                                    <input type="text" name="form_code" class="form-control form-control-lg text-center" 
                                           placeholder="FORM-2024-XXXX" value="<?php echo htmlspecialchars($form_code); ?>" 
                                           style="font-family: monospace; letter-spacing: 2px;" required>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-eye"></i> <?php echo $lang === 'es' ? 'Ver Resultados' : 'View Results'; ?>
                                    </button>
                                </div>
                            </form>
                            
                            <div class="mt-4 text-center">
                                <small class="text-muted">
                                    <?php echo $lang === 'es' ? 'Ingresa el código del formulario para ver los resultados públicos' : 'Enter the form code to view public results'; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><?php echo htmlspecialchars($form['title']); ?></h2>
                    <?php if ($form['description']): ?>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($form['description']); ?></p>
                    <?php endif; ?>
                    <small class="text-muted">
                        <?php echo $strings['code']; ?>: <code><?php echo $form['form_code']; ?></code>
                    </small>
                </div>
                <div>
                    <button class="btn btn-outline-secondary" onclick="changeForm()">
                        <i class="fas fa-exchange-alt"></i> <?php echo $lang === 'es' ? 'Cambiar Formulario' : 'Change Form'; ?>
                    </button>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stat-card text-center">
                        <div class="card-body">
                            <h4 class="text-primary mb-1"><?php echo $stats['total']; ?></h4>
                            <p class="card-text mb-0 small"><?php echo $strings['responses']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card text-center">
                        <div class="card-body">
                            <h4 class="text-success mb-1"><?php echo $stats['with_coords']; ?></h4>
                            <p class="card-text mb-0 small"><?php echo $lang === 'es' ? 'Con Ubicación' : 'With Location'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card text-center">
                        <div class="card-body">
                            <h4 class="text-info mb-1"><?php echo $stats['avg_accuracy'] > 0 ? '±' . round($stats['avg_accuracy']) . 'm' : 'N/A'; ?></h4>
                            <p class="card-text mb-0 small"><?php echo $lang === 'es' ? 'Precisión Prom.' : 'Avg. Accuracy'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stat-card text-center">
                        <div class="card-body">
                            <h4 class="text-warning mb-1"><?php echo count($fields_config); ?></h4>
                            <p class="card-text mb-0 small"><?php echo $strings['fields']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (empty($responses)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-inbox text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3"><?php echo $strings['no_data']; ?></h4>
                        <p class="text-muted"><?php echo $lang === 'es' ? 'No hay respuestas disponibles para mostrar' : 'No responses available to display'; ?></p>
                        <div class="mt-3">
                            <a href="collect.php?code=<?php echo $form['form_code']; ?>" class="btn btn-primary">
                                <i class="fas fa-plus-circle"></i> <?php echo $lang === 'es' ? 'Añadir Primera Respuesta' : 'Add First Response'; ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-map"></i> <?php echo $strings['location']; ?></h5>
                                <small class="text-muted"><?php echo $stats['date_range']; ?></small>
                            </div>
                            <div class="card-body p-0">
                                <div id="map"></div>
                                <div class="share-link-section">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <small class="text-muted">
                                                <i class="fas fa-share-alt"></i> <?php echo $lang === 'es' ? 'Compartir resultados:' : 'Share results:'; ?>
                                            </small>
                                        </div>
                                        <div class="flex-grow-1 mx-3">
                                            <div class="input-group share-input-group">
                                                <input type="text" class="form-control" id="shareUrl" value="<?php echo $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; ?>" readonly>
                                                <button class="btn copy-link-btn" onclick="copyShareLink()">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-list"></i> <?php echo $strings['recent_responses']; ?></h6>
                            </div>
                            <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                                <?php foreach (array_slice($responses, 0, 20) as $index => $response): ?>
                                    <div class="clickable-row p-3 border-bottom" data-lat="<?php echo $response['latitude'] ?? ''; ?>" 
                                         data-lng="<?php echo $response['longitude'] ?? ''; ?>" 
                                         data-index="<?php echo $index; ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong class="small">ID: <?php echo htmlspecialchars($response['unique_display_id'] ?? $response['id'] ?? ''); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock"></i> <?php echo date('M j, g:i A', strtotime($response['created_at'] ?? '')); ?>
                                                </small>
                                            </div>
                                            <?php if (!empty($response['latitude']) && !empty($response['longitude'])): ?>
                                                <div class="text-end">
                                                    <small class="text-muted">
                                                        <i class="fas fa-map-marker-alt text-primary"></i>
                                                        <?php echo round(floatval($response['gps_accuracy'] ?? 0)); ?>m
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if (!empty($fields_config)): ?>
                                            <div class="mt-2">
                                                <?php foreach (array_slice($fields_config, 0, 2) as $field): ?>
                                                    <?php $value = $response[$field['name']] ?? ''; ?>
                                                    <?php if (!empty($value)): ?>
                                                        <div class="small text-truncate">
                                                            <span class="text-muted"><?php echo htmlspecialchars($field['label']); ?>:</span>
                                                            <span><?php echo htmlspecialchars(substr($value, 0, 30)); ?><?php echo strlen($value) > 30 ? '...' : ''; ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (count($responses) > 20): ?>
                                    <div class="p-3 text-center">
                                        <small class="text-muted">
                                            <?php echo $lang === 'es' ? 'Mostrando las 20 respuestas más recientes' : 'Showing 20 most recent responses'; ?>
                                            <br>
                                            <?php echo $lang === 'es' ? 'Total:' : 'Total:'; ?> <?php echo count($responses); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map = null;
        let markers = [];
        let selectedMarker = null;

        const config = {
            hasForm: <?php echo $form ? 'true' : 'false'; ?>,
            responses: <?php echo json_encode($responses); ?>,
            fieldsConfig: <?php echo json_encode($fields_config); ?>,
            strings: <?php echo json_encode($strings); ?>,
            lang: <?php echo json_encode($lang); ?>
        };

        document.addEventListener('DOMContentLoaded', function() {
            if (config.hasForm && config.responses.length > 0) {
                initializeMap();
                setupRowClickHandlers();
            }
        });

        function initializeMap() {
            const validResponses = config.responses.filter(r => r.latitude && r.longitude);
            
            if (validResponses.length === 0) return;

            const bounds = L.latLngBounds();
            validResponses.forEach(response => {
                bounds.extend([parseFloat(response.latitude), parseFloat(response.longitude)]);
            });

            const center = bounds.getCenter();
            map = L.map('map').setView([center.lat, center.lng], 10);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            validResponses.forEach((response, index) => {
                const lat = parseFloat(response.latitude);
                const lng = parseFloat(response.longitude);
                
                const popupContent = createPopupContent(response);
                
                const customIcon = L.divIcon({
                    className: 'custom-marker',
                    html: `<div style="
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        width: 24px;
                        height: 24px;
                        border-radius: 50%;
                        border: 3px solid white;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-size: 10px;
                        font-weight: bold;
                    ">${index + 1}</div>`,
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                });
                
                const marker = L.marker([lat, lng], { icon: customIcon })
                    .bindPopup(popupContent, { className: 'response-popup' })
                    .addTo(map);
                
                marker.responseIndex = index;
                markers.push(marker);
                
                marker.on('click', function() {
                    selectResponse(index);
                });
            });

            if (validResponses.length > 1) {
                map.fitBounds(bounds, { padding: [20, 20] });
            } else {
                map.setZoom(16);
            }
        }

        function createPopupContent(response) {
            let content = `<div class="popup-title">ID: ${response.unique_display_id || response.id || 'N/A'}</div>`;
            
            if (config.fieldsConfig && config.fieldsConfig.length > 0) {
                config.fieldsConfig.slice(0, 3).forEach(field => {
                    const value = response[field.name] || '';
                    if (value) {
                        content += `<div class="popup-field">
                            <span class="popup-label">${field.label}:</span>
                            <span>${value.length > 50 ? value.substring(0, 50) + '...' : value}</span>
                        </div>`;
                    }
                });
            }
            
            if (response.gps_accuracy) {
                content += `<div class="popup-field">
                    <span class="popup-label">${config.lang === 'es' ? 'Precisión' : 'Accuracy'}:</span>
                    <span>±${Math.round(response.gps_accuracy)}m</span>
                </div>`;
            }
            
            if (response.created_at) {
                const date = new Date(response.created_at);
                content += `<div class="popup-field">
                    <span class="popup-label">${config.lang === 'es' ? 'Fecha' : 'Date'}:</span>
                    <span>${date.toLocaleDateString()} ${date.toLocaleTimeString()}</span>
                </div>`;
            }
            
            return content;
        }

        function setupRowClickHandlers() {
            document.querySelectorAll('.clickable-row').forEach(row => {
                row.addEventListener('click', function() {
                    const lat = this.dataset.lat;
                    const lng = this.dataset.lng;
                    const index = parseInt(this.dataset.index);
                    
                    if (lat && lng && map) {
                        map.setView([parseFloat(lat), parseFloat(lng)], 16);
                        
                        if (markers[index]) {
                            markers[index].openPopup();
                        }
                        
                        selectResponse(index);
                    }
                });
            });
        }

        function selectResponse(index) {
            document.querySelectorAll('.clickable-row').forEach(row => {
                row.classList.remove('selected-row');
            });
            
            const targetRow = document.querySelector(`[data-index="${index}"]`);
            if (targetRow) {
                targetRow.classList.add('selected-row');
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            if (selectedMarker) {
                const originalIcon = L.divIcon({
                    className: 'custom-marker',
                    html: `<div style="
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        width: 24px;
                        height: 24px;
                        border-radius: 50%;
                        border: 3px solid white;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-size: 10px;
                        font-weight: bold;
                    ">${selectedMarker.responseIndex + 1}</div>`,
                    iconSize: [30, 30],
                    iconAnchor: [15, 15]
                });
                selectedMarker.setIcon(originalIcon);
            }
            
            if (markers[index]) {
                const selectedIcon = L.divIcon({
                    className: 'custom-marker',
                    html: `<div style="
                        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
                        width: 28px;
                        height: 28px;
                        border-radius: 50%;
                        border: 3px solid white;
                        box-shadow: 0 3px 12px rgba(255,107,107,0.4);
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-size: 11px;
                        font-weight: bold;
                        animation: pulse 2s infinite;
                    ">${index + 1}</div>
                    <style>
                        @keyframes pulse {
                            0% { transform: scale(1); }
                            50% { transform: scale(1.1); }
                            100% { transform: scale(1); }
                        }
                    </style>`,
                    iconSize: [34, 34],
                    iconAnchor: [17, 17]
                });
                
                markers[index].setIcon(selectedIcon);
                selectedMarker = markers[index];
            }
        }

        function copyShareLink() {
            const shareUrl = document.getElementById('shareUrl');
            const fullUrl = 'https://' + shareUrl.value;
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(fullUrl).then(() => {
                    showCopySuccess();
                });
            } else {
                shareUrl.select();
                document.execCommand('copy');
                showCopySuccess();
            }
        }

        function showCopySuccess() {
            const buttons = document.querySelectorAll('.copy-link-btn');
            buttons.forEach(btn => {
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> ' + (config.lang === 'es' ? 'Copiado' : 'Copied');
                btn.classList.add('btn-success');
                btn.classList.remove('copy-link-btn');
                
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.classList.remove('btn-success');
                    btn.classList.add('copy-link-btn');
                }, 2000);
            });
        }

        function changeForm() {
            window.location.href = window.location.pathname + '?lang=' + config.lang;
        }
    </script>
</body>
</html>