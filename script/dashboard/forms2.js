<?php
// forms2_simple.php - Versión simple y robusta
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Solo generar SQL sin complicaciones
?>
<!DOCTYPE html>
<html>
<head>
    <title>Forms2 Simple - Generador SQL</title>
</head>
<body>
    <h1>Generador SQL Simple para Test de Fotos</h1>
    
    <?php
    $message = '';
    $error = '';
    $sql_generated = '';
    
    if ($_POST) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'generate_sql') {
            $table_name = trim($_POST['table_name'] ?? '');
            $text_field = trim($_POST['text_field'] ?? '');
            
            if (empty($table_name) || empty($text_field)) {
                $error = 'Todos los campos son requeridos';
            } else {
                // Generar SQL para PostgreSQL (Supabase)
                $safe_table = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($table_name));
                $safe_text = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($text_field));
                
                $sql_generated = "-- SQL para crear tabla en Supabase
CREATE TABLE {$safe_table} (
    id SERIAL PRIMARY KEY,
    {$safe_text} TEXT,
    foto TEXT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Habilitar RLS (Row Level Security)
ALTER TABLE {$safe_table} ENABLE ROW LEVEL SECURITY;

-- Crear política para permitir todas las operaciones
CREATE POLICY \"Allow all operations\" ON {$safe_table} FOR ALL USING (true);";
                
                $message = "SQL generado correctamente. Tabla: {$safe_table}, Campo texto: {$safe_text}";
            }
        }
    }
    ?>
    
    <?php if ($message): ?>
        <p style="color: green; background: #d4edda; padding: 10px; border: 1px solid #c3e6cb;">
            ✅ <?php echo $message; ?>
        </p>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <p style="color: red; background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb;">
            ❌ <?php echo $error; ?>
        </p>
    <?php endif; ?>
    
    <h2>Generar SQL para Tabla de Test</h2>
    <form method="POST" style="background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6;">
        <input type="hidden" name="action" value="generate_sql">
        
        <p>
            <label><strong>Nombre de la tabla:</strong></label><br>
            <input type="text" name="table_name" placeholder="ej: test_foto" required style="padding: 5px; width: 200px;">
        </p>
        
        <p>
            <label><strong>Nombre del campo de texto:</strong></label><br>
            <input type="text" name="text_field" placeholder="ej: nombre" required style="padding: 5px; width: 200px;">
        </p>
        
        <button type="submit" style="padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer;">
            Generar SQL
        </button>
    </form>
    
    <?php if ($sql_generated): ?>
        <h2>SQL Generado</h2>
        <div style="background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6; margin: 20px 0;">
            <textarea rows="15" cols="80" readonly style="width: 100%; font-family: monospace;"><?php echo $sql_generated; ?></textarea>
        </div>
        
        <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; margin: 20px 0;">
            <h3>📋 Instrucciones:</h3>
            <ol>
                <li>Copia el SQL de arriba</li>
                <li>Ve a tu dashboard de <strong>Supabase</strong></li>
                <li>Abre el <strong>"SQL Editor"</strong></li>
                <li>Pega y ejecuta el SQL</li>
                <li>Ve a <a href="collect2_simple.php">collect2_simple.php</a> para probar</li>
            </ol>
        </div>
        
        <p><strong>Tabla creada:</strong> <?php echo preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($_POST['table_name'] ?? '')); ?></p>
        <p><strong>Campo texto:</strong> <?php echo preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($_POST['text_field'] ?? '')); ?></p>
    <?php endif; ?>
    
    <hr>
    <p>
        <a href="collect2_simple.php">→ Ir a Collect2 Simple</a> | 
        <a href="../dashboard/">← Volver al Dashboard</a>
    </p>
    
</body>
</html>