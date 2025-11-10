-- Migration script to add security settings to database
-- Run this script to migrate hardcoded credentials to database storage

-- Insert reCAPTCHA settings (if not exist)
INSERT INTO admin_config (config_key, config_value, created_at)
VALUES ('recaptcha_site_key', '', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

INSERT INTO admin_config (config_key, config_value, created_at)
VALUES ('recaptcha_secret_key', '', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

INSERT INTO admin_config (config_key, config_value, created_at)
VALUES ('recaptcha_enabled', '1', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Insert plugin token settings (if not exist)
INSERT INTO admin_config (config_key, config_value, created_at)
VALUES ('plugin_auth_token', '', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

INSERT INTO admin_config (config_key, config_value, created_at)
VALUES ('plugin_token_enabled', '1', NOW())
ON DUPLICATE KEY UPDATE config_key = config_key;

-- Add site settings
INSERT INTO system_settings (setting_key, setting_value, created_at)
VALUES ('site_name', 'ArcGeek Survey', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO system_settings (setting_key, setting_value, created_at)
VALUES ('site_logo_url', '', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO system_settings (setting_key, setting_value, created_at)
VALUES ('site_footer_text', '© 2024 ArcGeek. Open Source Project', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO system_settings (setting_key, setting_value, created_at)
VALUES ('site_support_email', 'soporte@arcgeek.com', NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'Migration completed successfully!' AS status;
