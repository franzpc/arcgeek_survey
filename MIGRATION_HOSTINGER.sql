-- ============================================================
-- ARCGEEK SURVEY - SQL PARA ESTRUCTURA EXISTENTE
-- Ejecutar en phpMyAdmin - Hostinger
-- Base de datos: u220080920_arcgeek_survey
-- Fecha: 2025-01-10
-- ============================================================

-- PASO 1: Verificar datos existentes
SELECT 'Verificando datos existentes...' AS status;

SELECT
    'admin_config' as tabla,
    COUNT(*) as registros_actuales
FROM admin_config;

SELECT
    'system_settings' as tabla,
    COUNT(*) as registros_actuales
FROM system_settings;

-- ============================================================
-- PASO 2: INSERTAR/ACTUALIZAR CONFIGURACIONES DE SEGURIDAD
-- ============================================================

-- reCAPTCHA Configuration
INSERT INTO admin_config (config_key, config_value)
VALUES ('recaptcha_site_key', '')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

INSERT INTO admin_config (config_key, config_value)
VALUES ('recaptcha_secret_key', '')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

INSERT INTO admin_config (config_key, config_value)
VALUES ('recaptcha_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Plugin Token Configuration
INSERT INTO admin_config (config_key, config_value)
VALUES ('plugin_auth_token', '')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

INSERT INTO admin_config (config_key, config_value)
VALUES ('plugin_token_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- Admin Supabase Configuration (si usas Supabase compartido)
INSERT INTO admin_config (config_key, config_value)
VALUES ('admin_supabase_url', '')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

INSERT INTO admin_config (config_key, config_value)
VALUES ('admin_supabase_key', '')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);

-- ============================================================
-- PASO 3: CONFIGURACIONES DEL SITIO
-- ============================================================

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('site_name', 'ArcGeek Survey')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('site_logo_url', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('site_footer_text', '© 2024 ArcGeek. Open Source Project')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('site_support_email', 'soporte@arcgeek.com')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- PASO 4: CONFIGURACIONES DE CLEANUP
-- ============================================================

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('cleanup_unverified_days', '7')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('cleanup_inactive_users_days', '365')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('cleanup_unused_forms_days', '180')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('auto_cleanup_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- PASO 5: CONFIGURACIONES DE EMAIL (SMTP)
-- ============================================================

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('smtp_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('smtp_host', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('smtp_port', '587')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('smtp_username', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('smtp_password', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('from_email', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('from_name', 'ArcGeek Survey')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- PASO 6: CONFIGURACIÓN DE MENSAJES DEL PLUGIN
-- ============================================================

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('plugin_message_enabled', '0')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('plugin_message_type', 'info')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('plugin_message_title', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('plugin_message_content', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('plugin_message_dismissible', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('plugin_message_show_to', 'all')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================
-- PASO 7: VERIFICAR RESULTADOS
-- ============================================================

SELECT 'Configuraciones insertadas correctamente' AS status;

-- Ver todas las configuraciones de admin
SELECT 'ADMIN_CONFIG:' as tabla;
SELECT config_key,
       CASE
           WHEN config_key LIKE '%key%' AND config_value != ''
           THEN '***CONFIGURADO***'
           ELSE config_value
       END as valor,
       updated_at
FROM admin_config
ORDER BY config_key;

-- Ver todas las configuraciones del sistema
SELECT 'SYSTEM_SETTINGS:' as tabla;
SELECT setting_key, setting_value, created_at, updated_at
FROM system_settings
ORDER BY setting_key;

-- Resumen final
SELECT
    'RESUMEN' as info,
    (SELECT COUNT(*) FROM admin_config) as admin_config_rows,
    (SELECT COUNT(*) FROM system_settings) as system_settings_rows,
    NOW() as fecha_ejecucion;

-- ============================================================
-- NOTAS IMPORTANTES:
-- ============================================================
--
-- ✅ Este script es SEGURO - no borra nada
-- ✅ Puedes ejecutarlo múltiples veces
-- ✅ Usa ON DUPLICATE KEY UPDATE
--
-- DESPUÉS DE EJECUTAR:
-- 1. Ir a: https://acolita.com/survey/admin/
-- 2. Click en botón "Security Config"
-- 3. Configurar reCAPTCHA keys
-- 4. Generar plugin token
--
-- ============================================================
