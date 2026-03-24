<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

// Plugin token is read from Google Secret Manager via Cloud Run env var.
// Never hardcoded here.
function get_current_plugin_token() {
    $token = getenv('PLUGIN_TOKEN');
    return !empty($token) ? $token : false;
}

function validate_plugin_token($token) {
    $current = get_current_plugin_token();
    return $current && hash_equals($current, $token);
}

function authenticate_plugin_request() {
    $token = $_SERVER['HTTP_X_PLUGIN_TOKEN'] ?? '';
    if (!validate_plugin_token($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid plugin token']);
        exit();
    }
}
