<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

// Admin email (non-sensitive, used only for stats API key check)
define('ADMIN_EMAIL', 'franzpc@gmail.com');

// ---------------------------------------------------------------------------
// Proxy client — all MySQL operations delegated to Hostinger db-proxy.php
// PROXY_URL and PROXY_SECRET come from Google Secret Manager via env vars.
// ---------------------------------------------------------------------------
function proxy_call(string $action, array $params = []): ?array {
    $proxy_url    = getenv('PROXY_URL');
    $proxy_secret = getenv('PROXY_SECRET');

    if (empty($proxy_url) || empty($proxy_secret)) {
        error_log("Proxy not configured: PROXY_URL or PROXY_SECRET missing");
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $proxy_url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['action' => $action, 'params' => $params]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $proxy_secret,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("Proxy curl error [{$action}]: {$curl_error}");
        return null;
    }
    if ($http_code !== 200) {
        error_log("Proxy HTTP {$http_code} [{$action}]");
        return null;
    }

    return json_decode($response, true);
}

// ---------------------------------------------------------------------------
// Public API — used by api.php
// Credentials are decrypted by the proxy; Cloud Run never holds ENCRYPTION_KEY.
// ---------------------------------------------------------------------------
function get_user_config_for_plugin(string $email, string $password) {
    $result = proxy_call('auth_user', ['email' => $email, 'password' => $password]);
    return $result['data'] ?? false;
}

function get_form_by_code(string $form_code): ?array {
    $result = proxy_call('get_form', ['form_code' => $form_code]);
    return $result['data'] ?? null;
}

function get_connection_config(int $form_id): ?array {
    $result = proxy_call('get_connection_config', ['form_id' => $form_id]);
    return $result['data'] ?? null;
}

function get_responses_admin_db(int $form_id): array {
    $result = proxy_call('get_responses_free', ['form_id' => $form_id]);
    return $result['data'] ?? [];
}

function validate_api_access_proxy(int $form_id, string $api_key): bool {
    $result = proxy_call('validate_api_key', ['form_id' => $form_id, 'api_key' => $api_key]);
    return $result['valid'] ?? false;
}

function get_form_stats(): array {
    $result = proxy_call('get_stats');
    return $result['stats'] ?? [];
}

function get_recent_activity(): array {
    $result = proxy_call('get_stats');
    return $result['recent_activity'] ?? [];
}
