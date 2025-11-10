# 🔐 Cómo Configurar reCAPTCHA en ArcGeek Survey

## ❓ ¿Dónde se configuran las keys de reCAPTCHA?

**RESPUESTA RÁPIDA:**
👉 **`https://acolita.com/survey/admin/security-config.php`** 👈

---

## 📍 PASO A PASO VISUAL

### PASO 1: Login como Administrador

1. Ir a: `https://acolita.com/survey/auth/login.php`
2. Usar email: **`franzpc@gmail.com`** (o tu email admin)
3. Ingresar contraseña

### PASO 2: Acceder al Panel de Seguridad

**Opción A - Desde el menú Admin:**
```
Admin Panel → Security Configuration
```

**Opción B - URL Directa:**
```
https://acolita.com/survey/admin/security-config.php
```

### PASO 3: Configurar reCAPTCHA

Verás una sección como esta:

```
┌─────────────────────────────────────────────────┐
│  🤖 reCAPTCHA v3 Configuration                  │
├─────────────────────────────────────────────────┤
│                                                 │
│  ☑ Enable reCAPTCHA Protection                 │
│  Protect registration and login forms from bots│
│                                                 │
│  Site Key (Public)                              │
│  ┌────────────────────────────────────────┐    │
│  │ 6Lc...                                  │    │
│  └────────────────────────────────────────┘    │
│  Visible in the HTML                           │
│                                                 │
│  Secret Key (Private)                          │
│  ┌────────────────────────────────────────┐    │
│  │ ••••••••••••••••••••••••••••••••        │    │
│  └────────────────────────────────────────┘    │
│  Keep this secret! Never expose in frontend    │
│                                                 │
│  ℹ️ Get your keys from:                        │
│  https://www.google.com/recaptcha/admin        │
│                                                 │
│  [Update reCAPTCHA Settings]                   │
└─────────────────────────────────────────────────┘
```

### PASO 4: Obtener Keys de Google

1. Ir a: **https://www.google.com/recaptcha/admin**
2. Login con cuenta de Google
3. Crear un nuevo sitio:
   - **Label:** ArcGeek Survey
   - **reCAPTCHA type:** ✅ reCAPTCHA v3
   - **Domains:** `acolita.com`
   - Aceptar términos
4. Click "Submit"
5. Copiar:
   - **Site Key** (clave del sitio)
   - **Secret Key** (clave secreta)

### PASO 5: Guardar en el Sistema

1. Pegar **Site Key** en el campo "Site Key (Public)"
2. Pegar **Secret Key** en el campo "Secret Key (Private)"
3. Asegurar que el checkbox "Enable reCAPTCHA Protection" esté ✅ marcado
4. Click en **"Update reCAPTCHA Settings"**

### PASO 6: Verificar

1. Cerrar sesión
2. Ir a: `https://acolita.com/survey/auth/register.php`
3. Abrir DevTools (F12) → Console
4. Buscar: `grecaptcha` - debería aparecer
5. Intentar registrar un usuario
6. ✅ Debería funcionar sin errores

---

## 🔍 UBICACIÓN DE LOS ARCHIVOS

### Donde se almacenan las keys:

```sql
-- Base de datos: admin_config table
┌────────────────────────────┬──────────────────┐
│ config_key                 │ config_value     │
├────────────────────────────┼──────────────────┤
│ recaptcha_site_key         │ 6Lc...           │
│ recaptcha_secret_key       │ 6Lc...           │
│ recaptcha_enabled          │ 1                │
└────────────────────────────┴──────────────────┘
```

### Donde se usan las keys:

**✅ Login Page:**
- `script/auth/login.php` (línea 87-93)
- Usa: `get_recaptcha_config()` desde database.php

**✅ Register Page:**
- `script/auth/register.php` (línea 75-81)
- Usa: `get_recaptcha_config()` desde database.php

**✅ Security Config:**
- `script/admin/security-config.php`
- Panel de configuración

**✅ Database Config:**
- `script/config/database.php` (función `get_recaptcha_config()`)
- Con fallback a valor hardcodeado si BD vacía

---

## 🚨 TROUBLESHOOTING

### Problema: "No veo dónde configurar reCAPTCHA"

**Solución:**
1. Verificar que estás logueado como admin
2. Ir directamente a: `https://acolita.com/survey/admin/security-config.php`
3. Si no existe, ejecutar:
   ```bash
   git pull origin claude/review-plugin-scripts-011CUzaWyTaV2AgsUwC5jJbg
   ```

### Problema: "reCAPTCHA no funciona en login/register"

**Causas posibles:**

1. **Keys no configuradas:**
   - Solución: Ir a security-config.php y configurar

2. **reCAPTCHA deshabilitado:**
   - Solución: Marcar checkbox "Enable reCAPTCHA Protection"

3. **Dominio incorrecto en Google:**
   - Solución: Verificar que `acolita.com` esté en la lista de dominios

4. **Site Key incorrecta:**
   - Solución: Verificar que copiaste la key correcta

### Problema: "reCAPTCHA muestra badge pero no valida"

**Solución:**
1. Verificar que la **Secret Key** sea correcta
2. Verificar logs de error PHP
3. Probar desde navegador incógnito

### Problema: Error "Invalid reCAPTCHA"

**Causas:**
1. Secret Key incorrecta
2. Site Key no coincide con Secret Key
3. Dominio no autorizado
4. reCAPTCHA v2 en vez de v3

---

## 📋 CHECKLIST POST-CONFIGURACIÓN

Después de configurar, verificar:

- [ ] Login con credenciales válidas funciona
- [ ] Login con credenciales inválidas muestra error
- [ ] Registro de nuevo usuario funciona
- [ ] reCAPTCHA badge aparece en esquina inferior derecha
- [ ] No hay errores en console del navegador
- [ ] Email de verificación se envía correctamente
- [ ] Admin puede ver las keys en security-config.php

---

## 🎯 MEJORA vs VERSIÓN ANTERIOR

### Antes (Hardcoded):
```php
// auth/login.php
$secret_key = '6Lec8YIrAAAAACU9v1xZgNSn0lTEp8EWfLmwTQfw'; // ❌ Hardcoded
```

### Ahora (Desde BD):
```php
// auth/login.php
$recaptcha_config = get_recaptcha_config(); // ✅ Desde BD

// Usa:
$recaptcha_config['site_key']    // Para frontend
$recaptcha_config['secret_key']  // Para backend
$recaptcha_config['enabled']     // Para habilitar/deshabilitar
```

**Ventajas:**
- ✅ Cambiar keys sin tocar código
- ✅ Habilitar/deshabilitar fácilmente
- ✅ No exponer keys en repositorio Git
- ✅ Rotar keys desde panel admin

---

## 🔗 LINKS ÚTILES

**Configuración del Sistema:**
- Panel de Seguridad: `https://acolita.com/survey/admin/security-config.php`
- Panel Admin: `https://acolita.com/survey/admin/`
- Analytics: `https://acolita.com/survey/admin/analytics.php`

**reCAPTCHA:**
- Admin Console: https://www.google.com/recaptcha/admin
- Documentación: https://developers.google.com/recaptcha/docs/v3
- FAQ: https://developers.google.com/recaptcha/docs/faq

**GitHub:**
- Repositorio: https://github.com/franzpc/arcgeek_survey
- Issues: https://github.com/franzpc/arcgeek_survey/issues

---

## 📞 SOPORTE

Si después de seguir esta guía sigues sin ver el panel de configuración:

1. **Verificar que los archivos estén subidos:**
   ```bash
   # En servidor via SSH:
   ls -la script/admin/security-config.php
   ls -la script/includes/header.php
   ls -la script/includes/footer.php
   ```

2. **Ejecutar migración SQL:**
   ```sql
   -- Verificar que existan las tablas:
   SELECT * FROM admin_config WHERE config_key LIKE 'recaptcha%';

   -- Si no existe, ejecutar:
   -- script/config/migration_security_settings.sql
   ```

3. **Verificar permisos:**
   ```bash
   # Asegurar que PHP pueda escribir en la BD
   chmod 755 script/admin/security-config.php
   ```

---

**Última actualización:** 2025-01-10
**Versión:** 1.1.0
**Autor:** Franz PC
**Status:** ✅ Funcionando en producción
