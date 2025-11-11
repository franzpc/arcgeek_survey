# 🗄️ Actualizar Base de Datos - Hostinger

## ⚠️ IMPORTANTE - Tu estructura es diferente

He detectado que tu base de datos:
- ✅ Las tablas `admin_config` y `system_settings` **YA EXISTEN**
- ⚠️ `admin_config` **NO** tiene campo `created_at` (solo `updated_at`)
- ✅ `system_settings` SÍ tiene `created_at` y `updated_at`

Por eso he creado un SQL específico para tu estructura.

---

## 📋 PASO 1: Verificar Estado Actual

**Ejecuta primero:** `CHECK_DATABASE.sql`

1. Login en phpMyAdmin: https://auth-db439.hstgr.io
2. Seleccionar base de datos: `u220080920_arcgeek_survey`
3. Click en pestaña **"SQL"**
4. Copiar y pegar el contenido de `CHECK_DATABASE.sql`
5. Click **"Continuar"**

**Verás:**
```
admin_config_count: X
system_settings_count: Y
```

- Si ambos son **0** → Continúa al Paso 2
- Si ya tienen datos → Revisa qué tienes antes de insertar

---

## 📋 PASO 2: Ejecutar Migración

**Ejecuta:** `MIGRATION_HOSTINGER.sql`

1. En la misma pestaña SQL
2. **BORRAR** el contenido anterior
3. Copiar y pegar el contenido de `MIGRATION_HOSTINGER.sql`
4. Click **"Continuar"**

**Verás mensajes:**
```
✅ Su consulta se ejecutó con éxito (se repite ~27 veces)
✅ ADMIN_CONFIG: 7 registros
✅ SYSTEM_SETTINGS: 20 registros
✅ RESUMEN: Migration completed
```

---

## 📊 Qué Datos se Insertan

### admin_config (7 registros):
```
recaptcha_site_key       = ''
recaptcha_secret_key     = ''
recaptcha_enabled        = '1'
plugin_auth_token        = ''
plugin_token_enabled     = '1'
admin_supabase_url       = ''
admin_supabase_key       = ''
```

### system_settings (20 registros):
```
site_name                     = 'ArcGeek Survey'
site_logo_url                 = ''
site_footer_text              = '© 2024 ArcGeek...'
site_support_email            = 'soporte@arcgeek.com'
cleanup_unverified_days       = '7'
cleanup_inactive_users_days   = '365'
cleanup_unused_forms_days     = '180'
auto_cleanup_enabled          = '0'
smtp_enabled                  = '0'
smtp_host                     = ''
smtp_port                     = '587'
smtp_username                 = ''
smtp_password                 = ''
from_email                    = ''
from_name                     = 'ArcGeek Survey'
plugin_message_enabled        = '0'
plugin_message_type           = 'info'
plugin_message_title          = ''
plugin_message_content        = ''
plugin_message_dismissible    = '1'
plugin_message_show_to        = 'all'
```

---

## 🔍 VERIFICAR QUE FUNCIONÓ

Ejecuta en phpMyAdmin:

```sql
-- Ver todo admin_config
SELECT * FROM admin_config ORDER BY config_key;

-- Ver todo system_settings
SELECT * FROM system_settings ORDER BY setting_key;

-- Contar
SELECT
    (SELECT COUNT(*) FROM admin_config) as admin_rows,
    (SELECT COUNT(*) FROM system_settings) as system_rows;
```

**Debe mostrar:**
- `admin_rows: 7`
- `system_rows: 20`

---

## ⚙️ CONFIGURAR DESDE EL PANEL WEB

Después de ejecutar el SQL:

### 1. Ir al Admin Panel

URL: `https://acolita.com/survey/admin/`

Verás el menú de acceso rápido:

```
┌────────────────────────────────────────┐
│  📈 Analytics     🔐 Security Config   │
│  💾 Supabase      ⚙️ System Settings   │
└────────────────────────────────────────┘
```

### 2. Click en "Security Config" 🔐

URL: `https://acolita.com/survey/admin/security-config.php`

### 3. Configurar reCAPTCHA

**Obtener keys de Google:**
1. Ir a: https://www.google.com/recaptcha/admin
2. Login con Google
3. Click "+" para agregar nuevo sitio
4. Configurar:
   - **Label:** ArcGeek Survey
   - **reCAPTCHA type:** ✅ reCAPTCHA v3
   - **Domains:** `acolita.com`
   - Aceptar términos
5. Click "Submit"
6. **Copiar las 2 keys:**
   - Site Key (pública)
   - Secret Key (privada)

**En el panel Security Config:**
1. ☑️ Marcar "Enable reCAPTCHA Protection"
2. Pegar **Site Key** en primer campo
3. Pegar **Secret Key** en segundo campo
4. Click "Update reCAPTCHA Settings"
5. ✅ Verás mensaje de éxito

### 4. Generar Token del Plugin

En la misma página, sección "Plugin Authentication Token":
1. Click botón **"Generate New Token"**
2. Se generará automáticamente
3. Verás el token mostrado
4. ✅ Guardar (opcional copiar)

---

## 🧪 PROBAR QUE FUNCIONA

### Test 1: Login
1. Cerrar sesión
2. Ir a: `https://acolita.com/survey/auth/login.php`
3. Abrir DevTools (F12) → Console
4. Buscar: `grecaptcha` - debe aparecer
5. Login con tu cuenta
6. ✅ Debe funcionar

### Test 2: Register
1. Ir a: `https://acolita.com/survey/auth/register.php`
2. Intentar registrar usuario de prueba
3. reCAPTCHA debe ejecutarse invisible
4. ✅ Debe completar registro

### Test 3: Admin Panel
1. Login como admin
2. Ir a: `https://acolita.com/survey/admin/`
3. ✅ Debe verse el menú de 4 botones
4. Click en cada botón
5. ✅ Cada página debe cargar

---

## 🚨 TROUBLESHOOTING

### Error: "Duplicate entry for key 'config_key'"

**Significa:** Los datos ya existen (esto es NORMAL)

**Solución:** ✅ Ignorar, el script usa `ON DUPLICATE KEY UPDATE`

### No veo datos después de ejecutar

**Verificar:**
```sql
SELECT COUNT(*) FROM admin_config;
SELECT COUNT(*) FROM system_settings;
```

Si sigue en 0:
- Verificar que ejecutaste el SQL correcto
- Verificar que no haya errores en phpMyAdmin
- Probar ejecutar línea por línea

### Error: "Column 'created_at' cannot be null"

**Causa:** Usaste el SQL antiguo en vez del nuevo

**Solución:** Usar `MIGRATION_HOSTINGER.sql` (sin created_at en admin_config)

### Panel Security Config no aparece

**Verificar:**
1. Archivos subidos al servidor:
   ```
   script/admin/security-config.php
   script/includes/header.php
   script/includes/footer.php
   ```
2. Permisos: `chmod 755 script/admin/security-config.php`
3. Acceder directo: `/survey/admin/security-config.php`

---

## 🔄 Si Necesitas Resetear

Si algo salió mal y quieres empezar de cero:

```sql
-- ⚠️ ADVERTENCIA: Esto BORRA todos los datos
TRUNCATE TABLE admin_config;
TRUNCATE TABLE system_settings;

-- Luego ejecuta MIGRATION_HOSTINGER.sql de nuevo
```

---

## 📝 RESUMEN RÁPIDO

```bash
1. ✅ Ejecutar CHECK_DATABASE.sql (verificar estado)
2. ✅ Ejecutar MIGRATION_HOSTINGER.sql (insertar datos)
3. ✅ Verificar con SELECT * FROM ...
4. ✅ Ir a admin/security-config.php
5. ✅ Configurar reCAPTCHA keys
6. ✅ Generar plugin token
7. ✅ Probar login/register
8. ✅ ¡Listo!
```

---

## 📂 ARCHIVOS DISPONIBLES

```
✅ CHECK_DATABASE.sql        - Verificar estado actual
✅ MIGRATION_HOSTINGER.sql   - SQL corregido para tu estructura
✅ COMO_ACTUALIZAR_BD.md     - Esta guía
```

---

## 📞 SOPORTE

**Si tienes errores:**
1. Copiar el mensaje de error exacto
2. Ejecutar CHECK_DATABASE.sql
3. Mostrarme los resultados
4. Te ayudo a resolverlo

**Última actualización:** 2025-01-10
**Base de datos:** u220080920_arcgeek_survey
**Host:** auth-db439.hstgr.io
