-- ============================================================
-- ARCGEEK SURVEY - COMPLETE DATABASE MIGRATION
-- Ejecutar en phpMyAdmin o terminal MySQL
-- Fecha: 2025-01-10
-- Versión: 1.1.0
-- ============================================================

-- ============================================================
-- PARTE 1: VERIFICAR Y CREAR TABLAS SI NO EXISTEN
-- ============================================================

-- Tabla para configuración de administrador
CREATE TABLE IF NOT EXISTS `admin_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para configuraciones del sistema
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PARTE 2: INSERTAR CONFIGURACIONES DE SEGURIDAD
-- ============================================================

-- Configuración de reCAPTCHA
INSERT INTO `admin_config` (`config_key`, `config_value`, `created_at`)
VALUES
  ('recaptcha_site_key', '', NOW()),
  ('recaptcha_secret_key', '', NOW()),
  ('recaptcha_enabled', '1', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Configuración de Token del Plugin
INSERT INTO `admin_config` (`config_key`, `config_value`, `created_at`)
VALUES
  ('plugin_auth_token', '', NOW()),
  ('plugin_token_enabled', '1', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Configuración de Supabase Admin (si no existe)
INSERT INTO `admin_config` (`config_key`, `config_value`, `created_at`)
VALUES
  ('admin_supabase_url', '', NOW()),
  ('admin_supabase_key', '', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

-- ============================================================
-- PARTE 3: CONFIGURACIONES DEL SITIO
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `created_at`)
VALUES
  ('site_name', 'ArcGeek Survey', NOW()),
  ('site_logo_url', '', NOW()),
  ('site_footer_text', '© 2024 ArcGeek. Open Source Project', NOW()),
  ('site_support_email', 'soporte@arcgeek.com', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- PARTE 4: CONFIGURACIONES DE LIMPIEZA (CLEANUP)
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `created_at`)
VALUES
  ('cleanup_unverified_days', '7', NOW()),
  ('cleanup_inactive_users_days', '365', NOW()),
  ('cleanup_unused_forms_days', '180', NOW()),
  ('auto_cleanup_enabled', '0', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- PARTE 5: CONFIGURACIONES DE EMAIL (SMTP)
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `created_at`)
VALUES
  ('smtp_enabled', '0', NOW()),
  ('smtp_host', '', NOW()),
  ('smtp_port', '587', NOW()),
  ('smtp_username', '', NOW()),
  ('smtp_password', '', NOW()),
  ('from_email', '', NOW()),
  ('from_name', 'ArcGeek Survey', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- PARTE 6: CONFIGURACIÓN DE MENSAJES DEL PLUGIN
-- ============================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `created_at`)
VALUES
  ('plugin_message_enabled', '0', NOW()),
  ('plugin_message_type', 'info', NOW()),
  ('plugin_message_title', '', NOW()),
  ('plugin_message_content', '', NOW()),
  ('plugin_message_dismissible', '1', NOW()),
  ('plugin_message_show_to', 'all', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ============================================================
-- PARTE 7: VERIFICAR DATOS INSERTADOS
-- ============================================================

-- Ver configuraciones de admin
SELECT 'ADMIN CONFIG:' AS 'TABLE';
SELECT * FROM admin_config ORDER BY config_key;

-- Ver configuraciones del sistema
SELECT 'SYSTEM SETTINGS:' AS 'TABLE';
SELECT * FROM system_settings ORDER BY setting_key;

-- ============================================================
-- PARTE 8: RESUMEN DE MIGRACIÓN
-- ============================================================

SELECT
  'Migration completed successfully!' AS 'STATUS',
  (SELECT COUNT(*) FROM admin_config) AS 'admin_config_rows',
  (SELECT COUNT(*) FROM system_settings) AS 'system_settings_rows',
  NOW() AS 'executed_at';

-- ============================================================
-- NOTAS IMPORTANTES:
-- ============================================================
--
-- 1. Este script es SEGURO de ejecutar múltiples veces
--    (Usa INSERT...ON DUPLICATE KEY UPDATE)
--
-- 2. NO borrará datos existentes
--
-- 3. Después de ejecutar, configura en el panel web:
--    https://acolita.com/survey/admin/security-config.php
--
-- 4. Configuraciones que DEBES llenar manualmente:
--    - recaptcha_site_key (obtener de Google)
--    - recaptcha_secret_key (obtener de Google)
--    - plugin_auth_token (generar desde panel)
--
-- 5. Configuraciones opcionales:
--    - admin_supabase_url (si usas Supabase compartido)
--    - admin_supabase_key (si usas Supabase compartido)
--    - SMTP settings (si quieres enviar emails)
--
-- ============================================================
