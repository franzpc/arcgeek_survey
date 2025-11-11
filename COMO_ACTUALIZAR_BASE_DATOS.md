# 🗄️ Guía para Actualizar la Base de Datos

## 📋 EJECUTAR SQL EN PHPMYADMIN

### PASO 1: Acceder a phpMyAdmin

**Hostinger:**
1. Login en: https://hpanel.hostinger.com
2. Ir a: **Bases de datos** → **phpMyAdmin**
3. O usar: https://phpmyadmin.hostinger.com

**Seleccionar Base de Datos:**
- Clic en tu base de datos (ejemplo: `u220080920_arcgeek_survey`)

---

### PASO 2: Ejecutar el Script SQL

**Opción A - Copiar y Pegar (Recomendado):**

1. En phpMyAdmin, clic en pestaña **"SQL"**
2. Abrir el archivo: `MIGRATION_COMPLETE.sql`
3. **Copiar TODO el contenido**
4. **Pegar** en el editor SQL
5. Clic en botón **"Continuar"** o **"Go"**
6. ✅ Verás mensajes de éxito

**Opción B - Importar Archivo:**

1. En phpMyAdmin, clic en pestaña **"Importar"**
2. Clic en **"Elegir archivo"**
3. Seleccionar: `MIGRATION_COMPLETE.sql`
4. Formato: **SQL**
5. Clic en **"Continuar"**
6. ✅ Verás mensajes de éxito

---

### PASO 3: Verificar Resultados

Después de ejecutar, deberías ver:

```
✅ 2 filas insertadas en admin_config
✅ 20+ filas insertadas en system_settings
✅ Migration completed successfully!
```

**Verificar manualmente:**

```sql
-- Ver configuraciones admin
SELECT * FROM admin_config;

-- Ver configuraciones sistema
SELECT * FROM system_settings;
```

**Deberías ver:**

```
admin_config:
- recaptcha_site_key
- recaptcha_secret_key
- recaptcha_enabled
- plugin_auth_token
- plugin_token_enabled
- admin_supabase_url
- admin_supabase_key

system_settings:
- site_name
- site_logo_url
- site_footer_text
- site_support_email
- cleanup_*
- smtp_*
- plugin_message_*
```

---

## 🔧 QUÉ HACE ESTE SCRIPT

### Tablas Creadas/Verificadas:

**1. `admin_config`** - Configuraciones del administrador
- reCAPTCHA keys
- Plugin auth token
- Supabase admin credentials

**2. `system_settings`** - Configuraciones del sistema
- Información del sitio
- Configuración SMTP
- Cleanup settings
- Plugin messages

### Datos Insertados:

**Configuraciones de Seguridad:**
```sql
admin_config:
├── recaptcha_site_key = '' (TÚ debes llenar)
├── recaptcha_secret_key = '' (TÚ debes llenar)
├── recaptcha_enabled = '1'
├── plugin_auth_token = '' (Generar desde panel)
└── plugin_token_enabled = '1'
```

**Configuraciones del Sitio:**
```sql
system_settings:
├── site_name = 'ArcGeek Survey'
├── site_logo_url = ''
├── site_footer_text = '© 2024 ArcGeek...'
└── site_support_email = 'soporte@arcgeek.com'
```

---

## ✅ DESPUÉS DE EJECUTAR SQL

### 1. Configurar reCAPTCHA

1. Ir a: `https://acolita.com/survey/admin/security-config.php`
2. En sección "reCAPTCHA v3 Configuration":
   - Obtener keys de: https://www.google.com/recaptcha/admin
   - Pegar **Site Key**
   - Pegar **Secret Key**
   - Marcar ✅ "Enable reCAPTCHA Protection"
   - Clic "Update reCAPTCHA Settings"

### 2. Generar Token del Plugin

1. En la misma página (`security-config.php`)
2. En sección "Plugin Authentication Token":
   - Clic "Generate New Token"
   - Se generará automáticamente
   - Copiar el token (opcional, el plugin lo obtiene automáticamente)

### 3. Configurar Sitio (Opcional)

1. En `security-config.php`, sección "Site Settings":
   - **Site Name:** Nombre de tu sitio
   - **Logo URL:** URL completa de tu logo
   - **Footer Text:** Texto personalizado del footer
   - **Support Email:** Email de contacto

### 4. Configurar SMTP (Opcional)

1. Ir a: `https://acolita.com/survey/admin/system-settings.php`
2. En sección "Email Configuration":
   - ✅ Enable SMTP Email
   - SMTP Host: `smtp.gmail.com` (ejemplo)
   - SMTP Port: `587`
   - Username: tu email
   - Password: contraseña de aplicación
   - From Email: email remitente
   - From Name: nombre remitente

---

## 🚨 TROUBLESHOOTING

### Error: "Table 'admin_config' doesn't exist"

**Causa:** Tabla no creada correctamente

**Solución:**
```sql
-- Ejecutar solo esta parte:
CREATE TABLE IF NOT EXISTS `admin_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Error: "Duplicate entry for key 'config_key'"

**Causa:** Los datos ya existen (esto es NORMAL)

**Solución:** ✅ Ignorar, el script usa `ON DUPLICATE KEY UPDATE`

### Error: "Access denied"

**Causa:** Permisos de usuario de BD

**Solución:**
1. Verificar usuario de BD tiene permisos de escritura
2. Contactar soporte de Hostinger

### No veo los datos después de ejecutar

**Verificar:**
```sql
-- ¿Existen las tablas?
SHOW TABLES LIKE '%config%';
SHOW TABLES LIKE '%settings%';

-- ¿Tienen datos?
SELECT COUNT(*) FROM admin_config;
SELECT COUNT(*) FROM system_settings;
```

---

## 📊 VERIFICACIÓN COMPLETA

### Checklist Post-Migración:

```sql
-- 1. Verificar tablas existen
SHOW TABLES;

-- 2. Verificar estructura admin_config
DESCRIBE admin_config;

-- 3. Verificar estructura system_settings
DESCRIBE system_settings;

-- 4. Contar registros
SELECT
  (SELECT COUNT(*) FROM admin_config) as admin_rows,
  (SELECT COUNT(*) FROM system_settings) as system_rows;

-- 5. Ver todas las configuraciones
SELECT * FROM admin_config ORDER BY config_key;
SELECT * FROM system_settings ORDER BY setting_key;
```

**Resultados esperados:**
```
admin_rows: 7
system_rows: 20+
```

---

## 🔄 ROLLBACK (Si algo sale mal)

Si necesitas revertir los cambios:

```sql
-- ⚠️ ADVERTENCIA: Esto BORRA las tablas
-- Solo usar si necesitas empezar de cero

DROP TABLE IF EXISTS admin_config;
DROP TABLE IF EXISTS system_settings;

-- Luego ejecuta MIGRATION_COMPLETE.sql de nuevo
```

---

## 📞 SOPORTE

**Si tienes problemas:**

1. **Verificar error exacto:**
   - Copiar mensaje de error completo
   - Revisar qué línea SQL falló

2. **Verificar versión MySQL:**
   ```sql
   SELECT VERSION();
   ```
   - Debe ser MySQL 5.7+ o MariaDB 10.3+

3. **Contactar soporte:**
   - GitHub Issues: https://github.com/franzpc/arcgeek_survey/issues
   - Soporte Hostinger: Si es problema de permisos

---

## 🎯 RESUMEN RÁPIDO

```bash
1. Login en phpMyAdmin
2. Seleccionar base de datos
3. Ir a pestaña "SQL"
4. Copiar contenido de MIGRATION_COMPLETE.sql
5. Pegar y ejecutar
6. Verificar que aparezcan datos
7. Ir a admin/security-config.php
8. Configurar reCAPTCHA
9. Generar token del plugin
10. ¡Listo! ✅
```

---

**Última actualización:** 2025-01-10
**Archivo SQL:** `MIGRATION_COMPLETE.sql`
**Versión:** 1.1.0
