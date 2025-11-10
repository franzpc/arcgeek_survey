<?php
// collect2_simple.php - Recolector simple sin validaciones complejas
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$error = '';

// Datos de Supabase
$SUPABASE_URL = 'https://neixcsnkwtgdxkucfcnb.supabase.co';
$SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5laXhjc25rd3RnZHhrdWNmY25iIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDk1NzQ0OTQsImV4cCI6MjA2NTE1MDQ5NH0.OLcE9XYvYL6vzuXqcgp3dMowDZblvQo8qR21Cj39nyY';

if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_data') {
        $table_name = trim($_POST['table_name'] ?? '');
        $text_data = trim($_POST['text_data'] ?? '');
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;
        
        if (empty($table_name) || empty($text_data)) {
            $error = 'Tabla y texto son requeridos';
        } else {
            $foto_url = '';
            
            // Manejar upload de foto a Supabase Storage
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $file_extension = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid() . '.' . $file_extension;
                $bucket_name = 'fotos'; // Nombre del bucket en Supabase
                
                // Leer el archivo
                $file_content = file_get_contents($_FILES['foto']['tmp_name']);
                $file_type = $_FILES['foto']['type'];
                
                // Subir a Supabase Storage con headers correctos
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, rtrim($SUPABASE_URL, '/') . '/storage/v1/object/' . $bucket_name . '/' . $file_name);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "apikey: {$SUPABASE_KEY}",
                    "Authorization: Bearer {$SUPABASE_KEY}",
                    "Content-Type: {$file_type}",
                    "x-upsert: true"
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
                
                $upload_response = curl_exec($ch);
                $upload_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $upload_error = curl_error($ch);
                curl_close($ch);
                
                if ($upload_error) {
                    $error = "Error de cURL: " . $upload_error;
                } elseif ($upload_http_code === 200) {
                    // URL pública de la imagen en Supabase
                    $foto_url = rtrim($SUPABASE_URL, '/') . '/storage/v1/object/public/' . $bucket_name . '/' . $file_name;
                } else {
                    // Información detallada del error
                    $error = "Error al subir foto a Supabase Storage.<br>";
                    $error .= "Código HTTP: {$upload_http_code}<br>";
                    $error .= "Respuesta: {$upload_response}<br>";
                    $error .= "URL usada: " . rtrim($SUPABASE_URL, '/') . '/storage/v1/object/' . $bucket_name . '/' . $file_name;
                }
            }
            
            if (!$error) {
                // Preparar datos para Supabase
                $data_to_save = [
                    'nombre' => $text_data,
                    'foto' => $foto_url,
                    'latitude' => $latitude ? floatval($latitude) : null,
                    'longitude' => $longitude ? floatval($longitude) : null
                ];
                
                // Conectar realmente a Supabase
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, rtrim($SUPABASE_URL, '/') . '/rest/v1/' . $table_name);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "apikey: {$SUPABASE_KEY}",
                    "Authorization: Bearer {$SUPABASE_KEY}",
                    "Content-Type: application/json",
                    "Prefer: return=minimal"
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data_to_save));
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                if ($curl_error) {
                    $error = "Error de conexión: " . $curl_error;
                } elseif ($http_code === 201) {
                    $message = "✅ Datos guardados exitosamente en Supabase!<br>";
                    $message .= "- Tabla: {$table_name}<br>";
                    $message .= "- Texto: {$text_data}<br>";
                    $message .= "- Foto: " . ($foto_url ? $foto_url : 'No subida') . "<br>";
                    $message .= "- GPS: " . ($latitude && $longitude ? "{$latitude}, {$longitude}" : 'No proporcionado');
                } else {
                    $error = "Error al guardar en Supabase. Código HTTP: {$http_code}<br>Respuesta: {$response}";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Collect2 Simple - Recolector de Datos</title>
</head>
<body>
    <h1>Recolector de Datos Simple - Con Foto</h1>
    
    <?php if ($message): ?>
        <div style="color: green; background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; margin: 10px 0;">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div style="color: red; background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; margin: 10px 0;">
            ❌ <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <div style="background: #d1ecf1; padding: 15px; border: 1px solid #bee5eb; margin: 20px 0;">
        <h3>✅ Configuración Supabase Activa</h3>
        <p><strong>URL:</strong> <?php echo $SUPABASE_URL; ?></p>
        <p><strong>Key:</strong> <?php echo substr($SUPABASE_KEY, 0, 30) . '...'; ?></p>
        <p><em>Conexión lista para guardar datos reales</em></p>
    </div>
    
    <h2>Recolectar Datos</h2>
    <form method="POST" enctype="multipart/form-data" style="background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6;">
        <input type="hidden" name="action" value="save_data">
        
        <p>
            <label><strong>Nombre de la tabla:</strong></label><br>
            <input type="text" name="table_name" required placeholder="ej: test_foto" style="padding: 5px; width: 200px;">
            <small>Debe coincidir con la tabla creada en Supabase</small>
        </p>
        
        <p>
            <label><strong>Texto (nombre):</strong></label><br>
            <input type="text" name="text_data" required placeholder="Ingresa el texto" style="padding: 5px; width: 300px;">
        </p>
        
        <p>
            <label><strong>Foto:</strong></label><br>
            <input type="file" name="foto" accept="image/*" capture="camera" style="padding: 5px;">
            <br><small>Se subirá directamente a <strong>Supabase Storage</strong></small>
        </p>
        
        <p>
            <label><strong>Latitud:</strong></label><br>
            <input type="number" name="latitude" step="any" placeholder="ej: -4.006098" id="lat" style="padding: 5px; width: 200px;">
        </p>
        
        <p>
            <label><strong>Longitud:</strong></label><br>
            <input type="number" name="longitude" step="any" placeholder="ej: -79.208870" id="lng" style="padding: 5px; width: 200px;">
        </p>
        
        <button type="submit" style="padding: 10px 20px; background: #28a745; color: white; border: none; cursor: pointer;">
            💾 Guardar Datos
        </button>
        
        <button type="button" onclick="getLocation()" style="padding: 10px 20px; background: #17a2b8; color: white; border: none; cursor: pointer; margin-left: 10px;">
            📍 Obtener GPS
        </button>
    </form>
    
    <div id="locationInfo" style="margin-top: 10px; padding: 10px; background: #e9ecef; display: none;"></div>
    
    <script>
    function getLocation() {
        const info = document.getElementById('locationInfo');
        info.style.display = 'block';
        info.innerHTML = '🔄 Obteniendo ubicación...';
        
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                const accuracy = Math.round(position.coords.accuracy);
                
                document.getElementById('lat').value = lat;
                document.getElementById('lng').value = lng;
                
                info.innerHTML = `✅ Ubicación obtenida:<br>
                    <strong>Latitud:</strong> ${lat}<br>
                    <strong>Longitud:</strong> ${lng}<br>
                    <strong>Precisión:</strong> ±${accuracy}m`;
                info.style.background = '#d4edda';
                info.style.color = 'green';
            }, function(error) {
                info.innerHTML = '❌ Error al obtener ubicación: ' + error.message;
                info.style.background = '#f8d7da';
                info.style.color = 'red';
            });
        } else {
            info.innerHTML = '❌ Geolocalización no soportada por este navegador';
            info.style.background = '#f8d7da';
            info.style.color = 'red';
        }
    }
    </script>
    
    <hr>
    <p>
        <a href="forms2_simple.php">← Volver a Forms2 Simple</a> | 
        <a href="../dashboard/">← Volver al Dashboard</a>
    </p>
    
</body>
</html>