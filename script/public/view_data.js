<?php
// view_data.php - Ver datos guardados con fotos
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Datos de Supabase
$SUPABASE_URL = 'https://neixcsnkwtgdxkucfcnb.supabase.co';
$SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5laXhjc25rd3RnZHhrdWNmY25iIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDk1NzQ0OTQsImV4cCI6MjA2NTE1MDQ5NH0.OLcE9XYvYL6vzuXqcgp3dMowDZblvQo8qR21Cj39nyY';

$table_name = $_GET['table'] ?? 'test_foto';
$data = [];
$error = '';

// Obtener datos de Supabase
function get_supabase_data($url, $key, $table) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, rtrim($url, '/') . '/rest/v1/' . $table . '?select=*&order=created_at.desc');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "apikey: {$key}",
        "Authorization: Bearer {$key}"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    }
    
    return false;
}

$data = get_supabase_data($SUPABASE_URL, $SUPABASE_KEY, $table_name);

if ($data === false) {
    $error = "No se pudieron obtener los datos de la tabla '{$table_name}'";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ver Datos Guardados</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .record { border: 1px solid #ddd; margin: 15px 0; padding: 15px; background: #f9f9f9; }
        .photo { max-width: 300px; height: auto; border: 1px solid #ccc; margin: 10px 0; }
        .data-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .data-table th { background-color: #f2f2f2; }
        .gps-link { color: #007bff; text-decoration: none; }
        .gps-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>📊 Datos Guardados - Tabla: <?php echo htmlspecialchars($table_name); ?></h1>
    
    <?php if ($error): ?>
        <div style="color: red; background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb;">
            ❌ <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($data && count($data) > 0): ?>
        <p><strong>Total de registros:</strong> <?php echo count($data); ?></p>
        
        <?php foreach ($data as $index => $record): ?>
            <div class="record">
                <h3>📝 Registro #<?php echo ($index + 1); ?></h3>
                
                <table class="data-table">
                    <tr>
                        <th>Campo</th>
                        <th>Valor</th>
                    </tr>
                    <tr>
                        <td><strong>ID</strong></td>
                        <td><?php echo htmlspecialchars($record['id'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Nombre/Texto</strong></td>
                        <td><?php echo htmlspecialchars($record['nombre'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Fecha</strong></td>
                        <td><?php echo htmlspecialchars($record['created_at'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td><strong>GPS</strong></td>
                        <td>
                            <?php if (!empty($record['latitude']) && !empty($record['longitude'])): ?>
                                <a href="https://www.google.com/maps?q=<?php echo $record['latitude']; ?>,<?php echo $record['longitude']; ?>" 
                                   target="_blank" class="gps-link">
                                    📍 <?php echo $record['latitude']; ?>, <?php echo $record['longitude']; ?>
                                </a>
                                <br><small>Haz clic para ver en Google Maps</small>
                            <?php else: ?>
                                Sin coordenadas GPS
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Foto</strong></td>
                        <td>
                            <?php if (!empty($record['foto'])): ?>
                                <div>
                                    <p><strong>URL:</strong> <a href="<?php echo htmlspecialchars($record['foto']); ?>" target="_blank"><?php echo htmlspecialchars($record['foto']); ?></a></p>
                                    <img src="<?php echo htmlspecialchars($record['foto']); ?>" 
                                         alt="Foto del registro" 
                                         class="photo"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                    <div style="display:none; color: red; background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb;">
                                        ❌ No se pudo cargar la imagen desde Supabase Storage. 
                                        <a href="<?php echo htmlspecialchars($record['foto']); ?>" target="_blank">
                                            Intentar abrir directamente
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <em>Sin foto</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        <?php endforeach; ?>
        
    <?php elseif ($data && count($data) === 0): ?>
        <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7;">
            📭 No hay datos en la tabla '<?php echo htmlspecialchars($table_name); ?>'
        </div>
    <?php endif; ?>
    
    <hr>
    <p>
        <a href="collect2_simple.php">← Volver a Collect2</a> | 
        <a href="forms2_simple.php">← Volver a Forms2</a>
    </p>
    
    <div style="background: #e9ecef; padding: 15px; margin-top: 20px;">
        <h3>🔗 Enlaces directos:</h3>
        <p><strong>Ver otra tabla:</strong> <code>view_data.php?table=nombre_tabla</code></p>
        <p><strong>Acceso directo a fotos:</strong> <code>../uploads/nombre_archivo.png</code></p>
    </div>
    
</body>
</html>