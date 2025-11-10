<?php
define('ARCGEEK_SURVEY', true);
session_start();
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
$success = '';
$user_responses = [];
$response_count = 0;
$max_responses = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
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
        
        if ($action === 'submit_response') {
            $form_code = trim($_POST['form_code'] ?? '');
            $form = get_form_by_code($form_code);
            
            if (!$form) {
                $error = $strings['invalid_form_code'];
            } else {
                if ($form['response_count'] >= $form['max_responses']) {
                    $error = $strings['form_limit_reached'];
                } else {
                    $fields_config = json_decode($form['fields_config'], true);
                    $response_data = [];
                    $validation_errors = [];
                    
                    foreach ($fields_config as $field) {
                        $value = '';
                        
                        if ($field['type'] === 'checkbox') {
                            $checkbox_values = $_POST[$field['name']] ?? [];
                            if (is_array($checkbox_values)) {
                                $value = implode(', ', array_filter($checkbox_values));
                            }
                        } else {
                            $value = trim($_POST[$field['name']] ?? '');
                        }
                        
                        if ($field['required'] && empty($value)) {
                            $validation_errors[] = $field['label'] . ' ' . $strings['required'];
                            continue;
                        }
                        
                        if (!empty($value)) {
                            switch ($field['type']) {
                                case 'email':
                                    if (!validate_email($value)) {
                                        $validation_errors[] = $field['label'] . ': ' . $strings['invalid_email'];
                                    }
                                    break;
                                case 'url':
                                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                                        $validation_errors[] = $field['label'] . ': Invalid URL';
                                    }
                                    break;
                                case 'number':
                                    if (!is_numeric($value)) {
                                        $validation_errors[] = $field['label'] . ': Invalid number';
                                    }
                                    break;
                            }
                            
                            if (in_array($field['type'], ['select', 'radio']) && !empty($field['options'])) {
                                if (!in_array($value, $field['options'])) {
                                    $validation_errors[] = $field['label'] . ': Invalid option selected';
                                }
                            }
                            
                            if ($field['type'] === 'checkbox' && !empty($field['options'])) {
                                $selected_values = explode(', ', $value);
                                foreach ($selected_values as $selected) {
                                    if (!in_array(trim($selected), $field['options'])) {
                                        $validation_errors[] = $field['label'] . ': Invalid option selected';
                                        break;
                                    }
                                }
                            }
                        }
                        
                        $response_data[$field['name']] = $value;
                    }
                    
                    $latitude = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
                    $longitude = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
                    $accuracy = filter_var($_POST['accuracy'] ?? '', FILTER_VALIDATE_FLOAT);
                    
                    if ($latitude === false || $longitude === false) {
                        $validation_errors[] = $strings['location_required'];
                    }
                    
                    if (empty($validation_errors)) {
                        $ip_address = get_client_ip();
                        $saved = save_response($form['id'], $response_data, $latitude, $longitude, $accuracy, $ip_address);
                        
                        if ($saved) {
                            $success = $strings['response_saved'];
                            $response_data = [];
                            $_POST = [];
                            $form = get_form_by_code($form_code);
                        } else {
                            $error = $strings['error_saving'];
                        }
                    } else {
                        $error = implode('<br>', $validation_errors);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Collection error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        $error = $strings['server_error'];
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
    $user_responses = get_user_recent_responses($form['id'], get_client_ip(), 5);
    $response_count = $form['response_count'];
    $max_responses = $form['max_responses'];
}

function get_user_recent_responses($form_id, $ip_address, $limit = 5) {
    $responses = get_responses($form_id, $limit, 0);
    
    $user_responses = [];
    foreach ($responses as $response) {
        $user_responses[] = [
            'id' => $response['unique_display_id'] ?? $response['id'] ?? 'N/A',
            'data' => $response,
            'created_at' => $response['created_at'] ?? date('Y-m-d H:i:s'),
            'latitude' => $response['latitude'] ?? null,
            'longitude' => $response['longitude'] ?? null,
            'accuracy' => $response['gps_accuracy'] ?? $response['accuracy'] ?? null
        ];
    }
    
    return array_reverse($user_responses);
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $form ? htmlspecialchars($form['title']) : $strings['form_code_enter']; ?> - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <link href="../css/styles.css" rel="stylesheet">
    <style>
        .gps-indicator { 
            width: 12px; 
            height: 12px; 
            border-radius: 50%; 
            animation: pulse 2s infinite; 
            display: inline-block;
            margin-right: 8px;
        }
        .gps-excellent { background: #22c55e; }
        .gps-good { background: #3b82f6; }
        .gps-poor { background: #ef4444; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .response-card { 
            border-left: 4px solid #3b82f6; 
            background: #f8fafc; 
            margin-bottom: 0.5rem;
        }
        .location-info { 
            background: #f1f5f9; 
            border-radius: 6px; 
            padding: 0.75rem; 
            margin-top: 0.5rem; 
        }
        .progress-bar-container {
            background: #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            height: 8px;
        }
        .progress-bar-fill {
            background: linear-gradient(90deg, #3b82f6, #22c55e);
            height: 100%;
            transition: width 0.3s ease;
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
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <?php if ($form): ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <div class="d-flex align-items-center">
                                    <span class="gps-indicator" id="gpsIndicator"></span>
                                    <small id="gpsText"><?php echo $strings['getting_location']; ?></small>
                                </div>
                                <div class="location-info mt-2" id="locationInfo" style="display: none;">
                                    <div class="text-center">
                                        <small class="text-muted">
                                            <span id="coordsDisplay">--</span><br>
                                            <span id="accuracyDisplay">--</span>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-center">
                                <small class="text-muted"><?php echo $strings['responses']; ?></small><br>
                                <strong class="text-primary"><?php echo $response_count; ?> / <?php echo $max_responses; ?></strong>
                                <div class="progress-bar-container mt-1">
                                    <div class="progress-bar-fill" style="width: <?php echo $max_responses > 0 ? ($response_count / $max_responses * 100) : 0; ?>%"></div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <button class="btn btn-outline-primary btn-sm" onclick="toggleMap()" id="mapToggle" style="display: none;">
                                    <i class="fas fa-map"></i> Map
                                </button>
                            </div>
                        </div>
                        <div id="miniMap" style="height: 200px; margin-top: 15px; display: none; border-radius: 8px;"></div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        <div class="mt-2">
                            <button class="btn btn-success btn-sm" onclick="resetForm()"><?php echo $strings['submit_response']; ?></button>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$form): ?>
                    <div class="card">
                        <div class="card-header bg-primary text-white text-center">
                            <h4><i class="fas fa-search"></i> <?php echo $strings['search_form']; ?></h4>
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
                                        <i class="fas fa-search"></i> <?php echo $strings['search_form']; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-1"><?php echo htmlspecialchars($form['title']); ?></h4>
                                    <?php if ($form['description']): ?>
                                        <p class="mb-0 opacity-75"><?php echo htmlspecialchars($form['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <button class="btn btn-outline-light btn-sm" onclick="changeForm()">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            
                            <?php if (!empty($user_responses)): ?>
                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6><i class="fas fa-history"></i> <?php echo $strings['recent_responses']; ?></h6>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-secondary" onclick="prevResponse()" id="prevBtn">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                            <span class="btn btn-outline-secondary" id="responseInfo">1 / <?php echo count($user_responses); ?></span>
                                            <button class="btn btn-outline-secondary" onclick="nextResponse()" id="nextBtn">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div id="responseViewer" class="border rounded p-3 mb-3" style="background: #f8f9fa;"></div>
                                    
                                    <div class="text-center">
                                        <button class="btn btn-success" onclick="showNewResponseForm()">
                                            <i class="fas fa-plus"></i> <?php echo $strings['new_response']; ?>
                                        </button>
                                    </div>
                                </div>
                                
                                <div id="newResponseSection" style="display: none;">
                            <?php else: ?>
                                <div id="newResponseSection">
                            <?php endif; ?>

                            <form method="POST" id="responseForm">
                                <input type="hidden" name="action" value="submit_response">
                                <input type="hidden" name="form_code" value="<?php echo htmlspecialchars($form_code); ?>">
                                <input type="hidden" name="latitude" id="latitude">
                                <input type="hidden" name="longitude" id="longitude">
                                <input type="hidden" name="accuracy" id="accuracy">

                                <?php foreach ($fields_config as $field): ?>
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <?php echo htmlspecialchars($field['label']); ?>
                                            <?php if ($field['required']): ?>
                                                <span class="text-danger">*</span>
                                            <?php endif; ?>
                                        </label>
                                        
                                        <?php if ($field['type'] === 'textarea'): ?>
                                            <textarea name="<?php echo htmlspecialchars($field['name']); ?>" 
                                                      class="form-control" rows="4"
                                                      <?php echo $field['required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($_POST[$field['name']] ?? ''); ?></textarea>
                                        
                                        <?php elseif ($field['type'] === 'select'): ?>
                                            <select name="<?php echo htmlspecialchars($field['name']); ?>" 
                                                    class="form-select"
                                                    <?php echo $field['required'] ? 'required' : ''; ?>>
                                                <option value="">Select an option</option>
                                                <?php foreach ($field['options'] as $option): ?>
                                                    <option value="<?php echo htmlspecialchars($option); ?>"
                                                            <?php echo (($_POST[$field['name']] ?? '') === $option) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        
                                        <?php elseif ($field['type'] === 'radio'): ?>
                                            <?php foreach ($field['options'] as $option): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" 
                                                           name="<?php echo htmlspecialchars($field['name']); ?>" 
                                                           value="<?php echo htmlspecialchars($option); ?>"
                                                           id="<?php echo htmlspecialchars($field['name'] . '_' . $option); ?>"
                                                           <?php echo (($_POST[$field['name']] ?? '') === $option) ? 'checked' : ''; ?>
                                                           <?php echo $field['required'] ? 'required' : ''; ?>>
                                                    <label class="form-check-label" for="<?php echo htmlspecialchars($field['name'] . '_' . $option); ?>">
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        
                                        <?php elseif ($field['type'] === 'checkbox'): ?>
                                            <?php foreach ($field['options'] as $option): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="<?php echo htmlspecialchars($field['name']); ?>[]" 
                                                           value="<?php echo htmlspecialchars($option); ?>"
                                                           id="<?php echo htmlspecialchars($field['name'] . '_' . $option); ?>"
                                                           <?php 
                                                           $selected_values = $_POST[$field['name']] ?? [];
                                                           echo (is_array($selected_values) && in_array($option, $selected_values)) ? 'checked' : ''; 
                                                           ?>>
                                                    <label class="form-check-label" for="<?php echo htmlspecialchars($field['name'] . '_' . $option); ?>">
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        
                                        <?php else: ?>
                                            <input type="<?php echo htmlspecialchars($field['type']); ?>" 
                                                   name="<?php echo htmlspecialchars($field['name']); ?>" 
                                                   class="form-control"
                                                   value="<?php echo htmlspecialchars($_POST[$field['name']] ?? ''); ?>"
                                                   <?php echo $field['required'] ? 'required' : ''; ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-success btn-lg" id="submitBtn" disabled>
                                        <i class="fas fa-paper-plane"></i>
                                        <span id="submitText"><?php echo $strings['getting_location']; ?></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let currentLocation = null;
        let map = null;
        let mapVisible = false;
        let watchId = null;
        let config = {};
        let currentResponseIndex = 0;

        const formConfig = {
            hasForm: <?php echo $form ? 'true' : 'false'; ?>,
            strings: <?php echo json_encode($strings); ?>,
            responses: <?php echo json_encode($user_responses); ?>,
            fieldsConfig: <?php echo json_encode($fields_config ?? []); ?>,
            responseCount: <?php echo $response_count; ?>,
            maxResponses: <?php echo $max_responses; ?>
        };

        document.addEventListener('DOMContentLoaded', function() {
            if (formConfig.hasForm) {
                initializeCollection();
            }
        });

        function initializeCollection() {
            config = formConfig;
            
            setupEventListeners();
            startLocationTracking();
            
            if (config.responses && config.responses.length > 0) {
                showResponse(config.responses.length - 1);
            }
            
            updateResponseCounter();
        }

        function setupEventListeners() {
            const form = document.getElementById('responseForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!currentLocation) {
                        e.preventDefault();
                        alert('Waiting for GPS location...');
                        return false;
                    }
                });
            }
        }

        function startLocationTracking() {
            updateGPSStatus('getting', config.strings.getting_location || 'Getting location...');
            
            if (!navigator.geolocation) {
                updateGPSStatus('error', 'GPS not available');
                return;
            }

            const options = {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 30000
            };

            navigator.geolocation.getCurrentPosition(
                handleLocationSuccess,
                handleLocationError,
                options
            );
            
            watchId = navigator.geolocation.watchPosition(
                handleLocationSuccess,
                handleLocationError,
                options
            );
        }

        function handleLocationSuccess(position) {
            currentLocation = {
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
                timestamp: Date.now()
            };
            
            document.getElementById('latitude').value = currentLocation.latitude;
            document.getElementById('longitude').value = currentLocation.longitude;
            document.getElementById('accuracy').value = currentLocation.accuracy;
            
            updateLocationDisplay();
            updateGPSStatus('success', getAccuracyText());
            enableSubmitButton();
            
            const locationInfo = document.getElementById('locationInfo');
            const mapToggle = document.getElementById('mapToggle');
            if (locationInfo) locationInfo.style.display = 'block';
            if (mapToggle) mapToggle.style.display = 'inline-block';
            
            if (mapVisible && map) {
                updateMiniMap();
            }
        }

        function handleLocationError(error) {
            let message = 'Location error';
            let status = 'error';
            
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    message = 'Permission denied';
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = 'Location unavailable';
                    break;
                case error.TIMEOUT:
                    message = 'Timeout';
                    status = 'poor';
                    break;
            }
            
            updateGPSStatus(status, message);
        }

        function updateGPSStatus(level, text) {
            const indicator = document.getElementById('gpsIndicator');
            const textEl = document.getElementById('gpsText');
            
            if (indicator && textEl) {
                indicator.className = 'gps-indicator';
                
                switch(level) {
                    case 'excellent':
                        indicator.classList.add('gps-excellent');
                        break;
                    case 'good':
                        indicator.classList.add('gps-good');
                        break;
                    case 'poor':
                        indicator.classList.add('gps-poor');
                        break;
                    case 'error':
                        indicator.classList.add('gps-poor');
                        break;
                    case 'getting':
                        indicator.classList.add('gps-poor');
                        break;
                    case 'success':
                        indicator.classList.add('gps-good');
                        break;
                    default:
                        indicator.classList.add('gps-good');
                }
                
                textEl.textContent = text;
            }
        }

        function updateLocationDisplay() {
            if (!currentLocation) return;
            
            const coords = `${currentLocation.latitude.toFixed(6)}, ${currentLocation.longitude.toFixed(6)}`;
            const accuracy = `±${Math.round(currentLocation.accuracy)}m`;
            
            const coordsEl = document.getElementById('coordsDisplay');
            const accuracyEl = document.getElementById('accuracyDisplay');
            
            if (coordsEl) coordsEl.textContent = coords;
            if (accuracyEl) accuracyEl.textContent = accuracy;
        }

        function getAccuracyText() {
            if (!currentLocation) return 'No location';
            
            const accuracy = Math.round(currentLocation.accuracy);
            
            if (accuracy < 5) {
                return `Excellent (±${accuracy}m)`;
            } else if (accuracy <= 10) {
                return `Good (±${accuracy}m)`;
            } else {
                return `Not Recommended (±${accuracy}m)`;
            }
        }

        function getAccuracyLevel() {
            if (!currentLocation) return 'error';
            
            const accuracy = Math.round(currentLocation.accuracy);
            
            if (accuracy < 5) return 'excellent';
            if (accuracy <= 10) return 'good';
            return 'poor';
        }

        function enableSubmitButton() {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            
            if (submitBtn && submitText && currentLocation) {
                submitBtn.disabled = false;
                submitText.textContent = config.strings.submit_response || 'Submit Response';
                updateGPSStatus(getAccuracyLevel(), getAccuracyText());
            }
        }

        function updateResponseCounter() {
            if (!config.responseCount || !config.maxResponses) return;
            
            const percentage = (config.responseCount / config.maxResponses) * 100;
            const progressBar = document.querySelector('.progress-bar-fill');
            
            if (progressBar) {
                progressBar.style.width = `${Math.min(percentage, 100)}%`;
            }
            
            if (config.responseCount >= config.maxResponses) {
                const submitBtn = document.getElementById('submitBtn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-lock"></i> Form Limit Reached';
                }
            }
        }

        function showResponse(index) {
            if (!config.responses || config.responses.length === 0) return;
            
            currentResponseIndex = Math.max(0, Math.min(index, config.responses.length - 1));
            const response = config.responses[currentResponseIndex];
            
            const viewer = document.getElementById('responseViewer');
            if (!viewer) return;
            
            let html = `
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <strong>ID:</strong> <code>${response.id}</code>
                    </div>
                    <div class="text-muted">
                        <small><i class="fas fa-clock"></i> ${formatDateTime(response.created_at)}</small>
                    </div>
                </div>
            `;
            
            if (config.fieldsConfig && config.fieldsConfig.length > 0) {
                config.fieldsConfig.forEach(field => {
                    const value = response.data[field.name] || '';
                    html += `
                        <div class="mb-2">
                            <label class="form-label fw-bold small text-muted">${field.label}:</label>
                            <div class="form-control-plaintext bg-white border rounded px-2 py-1">
                                ${value || '<em class="text-muted">No data</em>'}
                            </div>
                        </div>
                    `;
                });
            }
            
            if (response.latitude && response.longitude) {
                html += `
                    <div class="mb-2">
                        <label class="form-label fw-bold small text-muted">Location:</label>
                        <div class="form-control-plaintext bg-white border rounded px-2 py-1">
                            <small>
                                <i class="fas fa-map-marker-alt text-primary"></i>
                                ${parseFloat(response.latitude).toFixed(6)}, ${parseFloat(response.longitude).toFixed(6)}
                                ${response.accuracy ? `<br><i class="fas fa-crosshairs text-muted"></i> ±${Math.round(response.accuracy)}m accuracy` : ''}
                            </small>
                        </div>
                    </div>
                `;
            }
            
            viewer.innerHTML = html;
            updateNavigationButtons();
        }

        function updateNavigationButtons() {
            const responseInfo = document.getElementById('responseInfo');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            
            if (responseInfo) {
                const displayIndex = config.responses.length - currentResponseIndex;
                responseInfo.textContent = `${displayIndex} / ${config.responses.length}`;
            }
            
            if (prevBtn) {
                prevBtn.disabled = currentResponseIndex >= config.responses.length - 1;
            }
            
            if (nextBtn) {
                nextBtn.disabled = currentResponseIndex <= 0;
            }
        }

        function prevResponse() {
            if (currentResponseIndex < config.responses.length - 1) {
                showResponse(currentResponseIndex + 1);
            }
        }

        function nextResponse() {
            if (currentResponseIndex > 0) {
                showResponse(currentResponseIndex - 1);
            }
        }

        function showNewResponseForm() {
            const newSection = document.getElementById('newResponseSection');
            const viewer = document.getElementById('responseViewer');
            const navControls = document.querySelector('.btn-group');
            const showNewBtn = document.querySelector('.btn-success[onclick="showNewResponseForm()"]');
            
            if (newSection) newSection.style.display = 'block';
            if (viewer) viewer.style.display = 'none';
            if (navControls) navControls.parentElement.style.display = 'none';
            if (showNewBtn) showNewBtn.style.display = 'none';
            
            const firstInput = newSection?.querySelector('input:not([type="hidden"]), textarea, select');
            if (firstInput) {
                firstInput.focus();
            }
            
            if (currentLocation) {
                document.getElementById('latitude').value = currentLocation.latitude;
                document.getElementById('longitude').value = currentLocation.longitude;
                document.getElementById('accuracy').value = currentLocation.accuracy;
                enableSubmitButton();
            }
        }

        function toggleMap() {
            const mapContainer = document.getElementById('miniMap');
            const toggleBtn = document.getElementById('mapToggle');
            
            if (!mapVisible) {
                mapContainer.style.display = 'block';
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide';
                mapVisible = true;
                
                if (!map && currentLocation) {
                    initMap();
                } else if (map) {
                    setTimeout(() => {
                        map.invalidateSize();
                        updateMiniMap();
                    }, 100);
                }
            } else {
                mapContainer.style.display = 'none';
                toggleBtn.innerHTML = '<i class="fas fa-map"></i> Map';
                mapVisible = false;
            }
        }

        function initMap() {
            if (!currentLocation) return;
            
            try {
                map = L.map('miniMap').setView([currentLocation.latitude, currentLocation.longitude], 16);
                
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap'
                }).addTo(map);
                
                updateMiniMap();
            } catch (error) {
                console.error('Error initializing map:', error);
            }
        }

        function updateMiniMap() {
            if (!map || !currentLocation) return;
            
            try {
                map.eachLayer(function(layer) {
                    if (layer instanceof L.Marker || layer instanceof L.Circle) {
                        map.removeLayer(layer);
                    }
                });
                
                L.marker([currentLocation.latitude, currentLocation.longitude]).addTo(map);
                
                L.circle([currentLocation.latitude, currentLocation.longitude], {
                    radius: currentLocation.accuracy,
                    fillColor: '#007bff',
                    fillOpacity: 0.2,
                    color: '#007bff',
                    weight: 2
                }).addTo(map);
                
                map.setView([currentLocation.latitude, currentLocation.longitude], 16);
            } catch (error) {
                console.error('Error updating map:', error);
            }
        }

        function changeForm() {
            window.location.href = window.location.pathname + '?lang=' + (config.strings.language || 'en');
        }

        function resetForm() {
            const form = document.getElementById('responseForm');
            if (form) {
                const inputs = form.querySelectorAll('input[type="text"], input[type="email"], input[type="number"], input[type="url"], input[type="date"], textarea, select');
                inputs.forEach(input => {
                    if (input.name !== 'form_code' && input.name !== 'action' && !input.type.includes('hidden')) {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                            input.checked = false;
                        } else {
                            input.value = '';
                        }
                    }
                });
                
                const checkboxes = form.querySelectorAll('input[type="checkbox"], input[type="radio"]');
                checkboxes.forEach(checkbox => {
                    if (!checkbox.name.includes('form_code') && !checkbox.name.includes('action')) {
                        checkbox.checked = false;
                    }
                });
                
                if (currentLocation) {
                    document.getElementById('latitude').value = currentLocation.latitude;
                    document.getElementById('longitude').value = currentLocation.longitude;
                    document.getElementById('accuracy').value = currentLocation.accuracy;
                }
            }
            
            const firstInput = form?.querySelector('input:not([type="hidden"]), textarea, select');
            if (firstInput) {
                firstInput.focus();
            }
        }

        function formatDateTime(dateString) {
            try {
                const date = new Date(dateString);
                return date.toLocaleString();
            } catch (e) {
                return dateString;
            }
        }

        window.addEventListener('beforeunload', function() {
            if (watchId) {
                navigator.geolocation.clearWatch(watchId);
            }
        });
    </script>
</body>
</html>