<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

// Plugin token is fetched live from the proxy, which reads it from Supabase
// exactly as the Hostinger portal does. Token rotation is transparent.
function get_current_plugin_token() {
    static $cache      = null;
    static $cache_time = 0;

    if ($cache !== null && (time() - $cache_time) < 1800) {
        return $cache;
    }

    $result = proxy_call('get_plugin_token');
    $token  = $result['token'] ?? false;

    if ($token) {
        $cache      = $token;
        $cache_time = time();
    }

    return $token ?: false;
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
