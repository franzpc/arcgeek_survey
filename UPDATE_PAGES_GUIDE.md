# Guía Rápida: Actualizar Páginas con Header/Footer

## 📋 Resumen de Cambios Realizados

### ✅ Páginas Ya Actualizadas

1. **admin/analytics.php** - ✅ Completamente renovado con gráficas
2. **admin/system-settings.php** - ✅ Error 500 arreglado
3. **admin/security-config.php** - ✅ Nueva página con header/footer

### 📝 Páginas que NECESITAN Actualización

#### Admin:
- [ ] admin/index.php
- [ ] admin/config.php

#### Dashboard:
- [ ] dashboard/index.php
- [ ] dashboard/forms.php
- [ ] dashboard/settings.php
- [ ] dashboard/view-data.php

#### Auth:
- [ ] auth/login.php
- [ ] auth/register.php
- [ ] auth/forgot-password.php
- [ ] auth/reset-password.php

---

## 🚀 Cómo Actualizar Cualquier Página (3 pasos)

### PASO 1: Reemplazar el inicio del archivo

**Antes (HTML manual):**
```php
<!DOCTYPE html>
<html>
<head>
    <title>Mi Página</title>
    <link href="bootstrap...">
    <!-- HTML duplicado -->
</head>
<body>
<nav class="navbar">
    <!-- Navegación duplicada -->
</nav>
```

**Después:**
```php
<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

// Tu lógica PHP aquí...

$page_title = "Mi Página";
$navbar_class = "bg-primary"; // o bg-danger, bg-success, etc.
include '../includes/header.php';
?>
```

### PASO 2: Mantén el contenido

Tu contenido HTML principal se queda igual:

```html
<div class="row">
    <div class="col-12">
        <h2>Contenido de la Página</h2>
        <!-- Tu HTML aquí -->
    </div>
</div>
```

### PASO 3: Reemplazar el final del archivo

**Antes:**
```html
    </div> <!-- container -->

    <footer>
        <!-- Footer duplicado -->
    </footer>

    <script src="bootstrap..."></script>
</body>
</html>
```

**Después:**
```php
<?php
// Opcional: Scripts adicionales
$additional_footer_scripts = '<script>console.log("Mi script");</script>';

include '../includes/footer.php';
?>
```

---

## 📖 Ejemplo Completo: Antes y Después

### ANTES (Viejo)

```php
<?php
session_start();
require_once '../config/database.php';

$user = get_user_by_id($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="#">ArcGeek Survey</a>
            <a href="logout.php">Logout</a>
        </div>
    </nav>

    <div class="container mt-4">
        <h2>Dashboard</h2>
        <p>Bienvenido <?php echo $user['name']; ?></p>

        <!-- Contenido aquí -->
    </div>

    <footer class="bg-dark text-light py-4">
        <div class="container">
            <p>© 2024 ArcGeek</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

### DESPUÉS (Nuevo)

```php
<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

if (!validate_session()) {
    header('Location: ../auth/login.php');
    exit();
}

$user = get_user_by_id($_SESSION['user_id']);

$page_title = "Dashboard";
include '../includes/header.php';
?>

<h2>Dashboard</h2>
<p>Bienvenido <?php echo htmlspecialchars($user['name']); ?></p>

<!-- Contenido aquí -->

<?php include '../includes/footer.php'; ?>
```

---

## 🎯 Variables de Configuración Disponibles

### Antes del Header

```php
$page_title = "Título de la Página"; // Se muestra en <title>
$navbar_class = "bg-danger"; // Color del navbar (bg-primary, bg-danger, bg-success)
$container_class = "container-fluid"; // Ancho del contenedor
$no_margin = true; // Eliminar márgenes superior/inferior
$additional_head_content = '<script>...</script>'; // HTML adicional en <head>
```

### Antes del Footer

```php
$additional_footer_scripts = '
<script src="https://cdn.example.com/library.js"></script>
<script>
    // Tu código JavaScript aquí
</script>
';
```

---

## 🛠️ Script Automático de Conversión

Crea este script en `/home/user/convert_page.php`:

```php
<?php
if ($argc < 2) {
    die("Uso: php convert_page.php path/to/file.php\n");
}

$file = $argv[1];
if (!file_exists($file)) {
    die("Archivo no encontrado: $file\n");
}

$content = file_get_contents($file);

// Detectar si ya tiene includes
if (strpos($content, "include '../includes/header.php'") !== false) {
    die("Este archivo ya tiene el header/footer incluido\n");
}

// Extraer la parte PHP inicial
preg_match('/^<\?php(.*?)(?=<|<!DOCTYPE)/s', $content, $php_matches);
$php_code = $php_matches[1] ?? '';

// Extraer el contenido HTML
preg_match('/<div[^>]*class="[^"]*container[^"]*"[^>]*>(.*?)<\/div>\s*(?=<footer|<script|<\/body)/s', $content, $html_matches);
$html_content = $html_matches[1] ?? '';

// Generar nuevo contenido
$new_content = "<?php\n";
$new_content .= "define('ARCGEEK_SURVEY', true);\n";
$new_content .= trim($php_code) . "\n\n";
$new_content .= "\$page_title = \"Page Title\";\n";
$new_content .= "include '../includes/header.php';\n";
$new_content .= "?>\n\n";
$new_content .= trim($html_content) . "\n\n";
$new_content .= "<?php include '../includes/footer.php'; ?>\n";

// Guardar backup
copy($file, $file . '.backup');

// Guardar nuevo archivo
file_put_contents($file, $new_content);

echo "✅ Archivo convertido: $file\n";
echo "📁 Backup creado: $file.backup\n";
?>
```

**Uso:**
```bash
php convert_page.php script/admin/index.php
php convert_page.php script/dashboard/forms.php
```

---

## 📊 Progreso de Actualización

### Admin (2/6 páginas)
- [x] analytics.php - ✅ Con gráficas modernas
- [x] security-config.php - ✅ Nueva página
- [x] system-settings.php - ✅ Error arreglado
- [ ] config.php - ⏳ Pendiente
- [ ] index.php - ⏳ Pendiente
- [ ] cleanup-cron.php - ⏳ (No necesita header/footer, es cron job)

### Dashboard (0/4 páginas)
- [ ] index.php - ⏳ Pendiente
- [ ] forms.php - ⏳ Pendiente
- [ ] settings.php - ⏳ Pendiente
- [ ] view-data.php - ⏳ Pendiente

### Auth (0/4 páginas)
- [ ] login.php - ⏳ Pendiente
- [ ] register.php - ⏳ Pendiente
- [ ] forgot-password.php - ⏳ Pendiente
- [ ] reset-password.php - ⏳ Pendiente

### Páginas Especiales (NO actualizar)
- ✅ collect.php - Es para móvil, mantener así
- ✅ share.php - Es para embeber, mantener así
- ✅ public/api/*.php - Son APIs, no necesitan HTML

---

## 🎨 Personalización del Header

### Cambiar Color del Navbar por Página

```php
// Admin pages
$navbar_class = "bg-danger";

// Dashboard pages
$navbar_class = "bg-primary";

// Auth pages
$navbar_class = "bg-success";
```

### Ocultar Navegación en Ciertas Páginas

Si una página no debe tener navegación completa:

```php
$minimal_header = true; // Crea una variable
include '../includes/header.php';
```

Luego modifica `includes/header.php` para detectar esto.

---

## ✅ Checklist de Actualización

Para cada página:

1. [ ] Backup del archivo original
2. [ ] Agregar `define('ARCGEEK_SURVEY', true);`
3. [ ] Incluir header: `include '../includes/header.php';`
4. [ ] Verificar que el contenido se muestra bien
5. [ ] Incluir footer: `include '../includes/footer.php';`
6. [ ] Probar la página en el navegador
7. [ ] Verificar navegación funciona
8. [ ] Verificar responsive en móvil

---

## 🚨 Errores Comunes y Soluciones

### Error: "Direct access not permitted"

**Causa:** Falta `define('ARCGEEK_SURVEY', true);`

**Solución:**
```php
<?php
define('ARCGEEK_SURVEY', true); // Agregar esta línea ANTES de include
session_start();
```

### Error: "Headers already sent"

**Causa:** HTML antes de `session_start()` o redirects

**Solución:**
```php
<?php
// NO debe haber NADA antes de esta línea, ni espacios ni HTML
session_start();
```

### Error: Variable `$site_config` undefined

**Causa:** Header incluido antes de cargar database.php

**Solución:**
```php
require_once '../config/database.php'; // ANTES del include header
require_once '../config/security.php';
include '../includes/header.php';
```

---

## 📞 Soporte

Si tienes problemas actualizando alguna página:

1. Verifica que el archivo backup existe
2. Revisa los errores en error_log de PHP
3. Compara con `includes/example-page.php`
4. Consulta SECURITY_MIGRATION_GUIDE.md

---

**Última actualización:** 2025-01-10
**Páginas actualizadas:** 3/15
**Tiempo estimado restante:** 30 minutos para completar todas
