<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

// All sensitive values come from Google Secret Manager via Cloud Run env vars.
// No credentials are hardcoded in this file.
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');
define('ADMIN_EMAIL',    getenv('ADMIN_EMAIL')    ?: '');

// ---------------------------------------------------------------------------
// Proxy client — all MySQL operations are delegated to Hostinger db-proxy.php
// ---------------------------------------------------------------------------
function proxy_call(string $action, array $params = []): ?array {
    $proxy_url    = getenv('PROXY_URL');
    $proxy_secret = getenv('PROXY_SECRET');

    if (empty($proxy_url) || empty($proxy_secret)) {
        error_log("Proxy not configured: PROXY_URL or PROXY_SECRET env var missing");
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
        error_log("Proxy HTTP {$http_code} for action [{$action}]");
        return null;
    }

    return json_decode($response, true);
}

// ---------------------------------------------------------------------------
// Credential helpers (ENCRYPTION_KEY comes from env var)
// ---------------------------------------------------------------------------
function decrypt_credential(string $encrypted_data): string {
    if (empty($encrypted_data)) return '';
    $key = ENCRYPTION_KEY;
    if (empty($key)) return '';
    $data      = base64_decode($encrypted_data);
    $iv_length = openssl_cipher_iv_length('aes-256-cbc');
    $iv        = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv) ?: '';
}

// ---------------------------------------------------------------------------
// Public API (used by api.php)
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
    $config = $result['data'] ?? null;

    if ($config) {
        // Decrypt credentials locally using the key from env var
        $config['supabase_url']   = decrypt_credential($config['supabase_url']   ?? '');
        $config['supabase_key']   = decrypt_credential($config['supabase_key']   ?? '');
        $config['postgres_host']  = decrypt_credential($config['postgres_host']  ?? '');
        $config['postgres_db']    = decrypt_credential($config['postgres_db']    ?? '');
        $config['postgres_user']  = decrypt_credential($config['postgres_user']  ?? '');
        $config['postgres_pass']  = decrypt_credential($config['postgres_pass']  ?? '');
    }

    return $config;
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
