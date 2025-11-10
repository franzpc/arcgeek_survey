<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

define('PLAN_FREE', 'free');
define('PLAN_BASIC', 'basic');
define('PLAN_PREMIUM', 'premium');

$PLANS = [
    PLAN_FREE => [
        'forms_limit' => 2,
        'fields_limit' => 6,
        'responses_limit' => 40,
        'storage_type' => 'admin_supabase',
        'requires_config' => false,
        'spatial_features' => false,
        'can_configure_db' => true,
        'auto_upgrade_on_config' => true
    ],
    PLAN_BASIC => [
        'forms_limit' => 6,
        'fields_limit' => 30,
        'responses_limit' => 300,
        'storage_type' => 'user_configured',
        'requires_config' => false,
        'spatial_features' => true,
        'can_configure_db' => true,
        'auto_upgrade_on_config' => false
    ],
    PLAN_PREMIUM => [
        'forms_limit' => -1,
        'fields_limit' => 15,
        'responses_limit' => 1000,
        'storage_type' => 'user_configured',
        'requires_config' => false,
        'spatial_features' => true,
        'can_configure_db' => true,
        'auto_upgrade_on_config' => false
    ]
];

function get_plan_limits($plan_type) {
    global $PLANS;
    return $PLANS[$plan_type] ?? $PLANS[PLAN_FREE];
}

function validate_form_creation($user_id, $plan_type) {
    global $pdo;
    
    $limits = get_plan_limits($plan_type);
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    $current_forms = $stmt->fetchColumn();
    
    if ($limits['forms_limit'] != -1 && $current_forms >= $limits['forms_limit']) {
        return false;
    }
    
    return true;
}

function validate_field_count($plan_type, $field_count) {
    $limits = get_plan_limits($plan_type);
    return $field_count <= $limits['fields_limit'];
}

function validate_response_limit($form_id, $plan_type) {
    global $pdo;
    
    $limits = get_plan_limits($plan_type);
    
    $stmt = $pdo->prepare("SELECT response_count, max_responses FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch();
    
    if (!$form) return false;
    
    return $form['response_count'] < $form['max_responses'];
}

function get_storage_type($user) {
    if ($user['plan_type'] === PLAN_FREE && !has_database_config($user)) {
        return 'admin_supabase';
    }
    
    if ($user['storage_preference'] === 'postgres' && 
        !empty($user['postgres_host']) && 
        !empty($user['postgres_db']) &&
        !empty($user['postgres_user'])) {
        return 'user_postgres';
    }
    
    if (!empty($user['supabase_url']) && !empty($user['supabase_key'])) {
        return 'user_supabase';
    }
    
    return 'admin_supabase';
}

function requires_database_config($plan_type) {
    $limits = get_plan_limits($plan_type);
    return $limits['requires_config'];
}

function can_configure_database($plan_type) {
    $limits = get_plan_limits($plan_type);
    return $limits['can_configure_db'];
}

function should_auto_upgrade($user, $plan_type) {
    $limits = get_plan_limits($plan_type);
    return $limits['auto_upgrade_on_config'] && has_database_config($user);
}

function auto_upgrade_user($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET plan_type = ? WHERE id = ?");
        return $stmt->execute([PLAN_BASIC, $user_id]);
    } catch (Exception $e) {
        error_log("Auto upgrade error: " . $e->getMessage());
        return false;
    }
}

function update_user_usage($user_id, $forms_delta = 0, $responses_delta = 0) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO user_usage (user_id, forms_count, responses_count) 
                          VALUES (?, ?, ?) 
                          ON DUPLICATE KEY UPDATE 
                          forms_count = GREATEST(0, forms_count + ?), 
                          responses_count = GREATEST(0, responses_count + ?), 
                          updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$user_id, max(0, $forms_delta), max(0, $responses_delta), $forms_delta, $responses_delta]);
}

function get_user_usage($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM user_usage WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $usage = $stmt->fetch();
    
    if (!$usage) {
        $stmt = $pdo->prepare("INSERT INTO user_usage (user_id) VALUES (?)");
        $stmt->execute([$user_id]);
        return ['user_id' => $user_id, 'forms_count' => 0, 'responses_count' => 0];
    }
    
    return $usage;
}

function can_upgrade_plan($current_plan) {
    switch ($current_plan) {
        case PLAN_FREE:
            return PLAN_BASIC;
        case PLAN_BASIC:
            return PLAN_PREMIUM;
        default:
            return false;
    }
}

function get_max_responses_for_plan($plan_type) {
    $limits = get_plan_limits($plan_type);
    return $limits['responses_limit'];
}

function has_spatial_features($plan_type) {
    $limits = get_plan_limits($plan_type);
    return $limits['spatial_features'] ?? false;
}

function get_plan_display_name($plan_type, $lang = 'en') {
    $names = [
        'en' => [
            PLAN_FREE => 'Free Plan',
            PLAN_BASIC => 'Basic Plan', 
            PLAN_PREMIUM => 'Premium Plan'
        ],
        'es' => [
            PLAN_FREE => 'Plan Gratuito',
            PLAN_BASIC => 'Plan Básico',
            PLAN_PREMIUM => 'Plan Premium'
        ]
    ];
    
    return $names[$lang][$plan_type] ?? ucfirst($plan_type);
}

function sync_user_usage($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    $forms_count = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT SUM(response_count) FROM forms WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$user_id]);
    $responses_count = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->prepare("INSERT INTO user_usage (user_id, forms_count, responses_count) 
                          VALUES (?, ?, ?) 
                          ON DUPLICATE KEY UPDATE 
                          forms_count = ?, 
                          responses_count = ?, 
                          updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$user_id, $forms_count, $responses_count, $forms_count, $responses_count]);
    
    return ['forms_count' => $forms_count, 'responses_count' => $responses_count];
}

function get_form_creation_type($user) {
    $plan_type = $user['plan_type'];
    
    if ($plan_type === PLAN_FREE && !has_database_config($user)) {
        return 'admin_supabase';
    }
    
    $storage_pref = $user['storage_preference'];
    
    if ($storage_pref === 'postgres' && 
        !empty($user['postgres_host']) && 
        !empty($user['postgres_db']) &&
        !empty($user['postgres_user'])) {
        return 'user_postgres';
    }
    
    if (!empty($user['supabase_url']) && !empty($user['supabase_key'])) {
        return 'user_supabase';
    }
    
    return 'admin_supabase';
}

function can_create_form_in_postgres($user) {
    return $user['plan_type'] !== PLAN_FREE && has_database_config($user);
}

function get_plugin_form_limits($user) {
    $limits = get_plan_limits($user['plan_type']);
    $usage = get_user_usage($user['id']);
    
    return [
        'can_create' => validate_form_creation($user['id'], $user['plan_type']),
        'forms_used' => $usage['forms_count'],
        'forms_limit' => $limits['forms_limit'],
        'fields_limit' => $limits['fields_limit'],
        'responses_limit' => $limits['responses_limit'],
        'creation_type' => get_form_creation_type($user),
        'requires_postgres' => can_create_form_in_postgres($user)
    ];
}

function validate_plugin_form_creation($user_id, $plan_type, $field_count) {
    if (!validate_form_creation($user_id, $plan_type)) {
        return ['valid' => false, 'error' => 'Form limit reached'];
    }
    
    if (!validate_field_count($plan_type, $field_count)) {
        $limits = get_plan_limits($plan_type);
        return ['valid' => false, 'error' => 'Max fields: ' . $limits['fields_limit']];
    }
    
    return ['valid' => true];
}
?>