-- ============================================================
-- QUICK CHECK: Ver qué datos ya existen en tu base de datos
-- Ejecuta esto PRIMERO para ver el estado actual
-- ============================================================

-- 1. Ver todas las tablas
SHOW TABLES;

-- 2. Ver configuraciones admin actuales
SELECT 'CONFIGURACIONES ADMIN ACTUALES:' as info;
SELECT * FROM admin_config;

-- 3. Ver configuraciones sistema actuales
SELECT 'CONFIGURACIONES SISTEMA ACTUALES:' as info;
SELECT * FROM system_settings;

-- 4. Contar registros
SELECT
    'RESUMEN' as info,
    (SELECT COUNT(*) FROM admin_config) as admin_config_count,
    (SELECT COUNT(*) FROM system_settings) as system_settings_count;

-- ============================================================
-- Si ves registros aquí, significa que ya tienes datos
-- Si ves 0 registros, entonces ejecuta MIGRATION_HOSTINGER.sql
-- ============================================================
