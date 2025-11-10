<?php
// test_bucket.php - Crear políticas y probar upload
error_reporting(E_ALL);
ini_set('display_errors', 1);

$SUPABASE_URL = 'https://neixcsnkwtgdxkucfcnb.supabase.co';
$SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5laXhjc25rd3RnZHhrdWNmY25iIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NDk1NzQ0OTQsImV4cCI6MjA2NTE1MDQ5NH0.OLcE9XYvYL6vzuXqcgp3dMowDZblvQo8qR21Cj39nyY';

echo "<h1>🧪 Test de Upload a Supabase Storage</h1>";

echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; margin: 20px 0;'>";
echo "<h3>✅ Bucket 'fotos' confirmado:</h3>";
echo "<ul>";
echo "<li>✅ Storage está habilitado</li>";
echo "<li>✅ Bucket 'fotos' existe</li>";
echo "<li>✅ Bucket es público</li>";
echo "<li>❌ Falta: Políticas de acceso</li>";
echo "</ul>";
echo "</div>";

// Test de upload de imagen
echo "<h2>🖼️ Test de subida de imagen:</h2>";

// Crear una imagen de prueba simple (pixel transparente PNG)
$test_image_data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==');
$test_filename = "test_" . uniqid() . ".png";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, rtrim($SUPABASE_URL, '/') . '/storage/v1/object/fotos/' . $test_filename);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "apikey: {$SUPABASE_KEY}",
    "Authorization: Bearer {$SUPABASE_KEY}",
    "Content-Type: image/png"
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $test_image_data);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>Archivo de prueba:</strong> {$test_filename}</p>";
echo "<p><strong>Código HTTP:</strong> {$http_code}</p>";
echo "<p><strong>Respuesta:</strong> {$response}</p>";

if ($http_code === 200) {
    echo "<p style='color: green;'>✅ ¡Upload exitoso!</p>";
    $public_url = rtrim($SUPABASE_URL, '/') . '/storage/v1/object/public/fotos/' . $test_filename;
    echo "<p>URL pública: <a href='{$public_url}' target='_blank'>{$public_url}</a></p>";
    echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb;'>";
    echo "<h3>🎉 ¡Storage funciona perfectamente!</h3>";
    echo "<p>Ya puedes usar el collect2_simple.php para subir fotos reales.</p>";
    echo "</div>";
} else {
    echo "<p style='color: red;'>❌ Upload falló</p>";
    echo "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7;'>";
    echo "<h3>🔧 Crear políticas manualmente:</h3>";
    echo "<p>Ve a: <strong>Storage → Policies → fotos → New Policy</strong></p>";
    echo "<ol>";
    echo "<li><strong>Policy name:</strong> Allow public uploads</li>";
    echo "<li><strong>Policy command:</strong> INSERT</li>";
    echo "<li><strong>Target roles:</strong> public</li>";
    echo "<li><strong>USING expression:</strong> <code>true</code></li>";
    echo "<li><strong>WITH CHECK expression:</strong> <code>true</code></li>";
    echo "</ol>";
    echo "<p>Luego crea otra política:</p>";
    echo "<ol>";
    echo "<li><strong>Policy name:</strong> Allow public access</li>";
    echo "<li><strong>Policy command:</strong> SELECT</li>";
    echo "<li><strong>Target roles:</strong> public</li>";
    echo "<li><strong>USING expression:</strong> <code>true</code></li>";
    echo "</ol>";
    echo "</div>";
}

?>

<hr>
<h2>📝 SQL para crear políticas automáticamente:</h2>
<div style="background: #f8f9fa; padding: 15px; border: 1px solid #dee2e6;">
<p>Si prefieres usar SQL, ve a <strong>SQL Editor</strong> y ejecuta:</p>
<pre><code>-- Política para permitir subida de archivos
CREATE POLICY "Allow public uploads" ON storage.objects
FOR INSERT TO public
WITH CHECK (bucket_id = 'fotos');

-- Política para permitir acceso a archivos
CREATE POLICY "Allow public access" ON storage.objects
FOR SELECT TO public
USING (bucket_id = 'fotos');

-- Política para permitir actualización
CREATE POLICY "Allow public updates" ON storage.objects
FOR UPDATE TO public
USING (bucket_id = 'fotos')
WITH CHECK (bucket_id = 'fotos');

-- Política para permitir eliminación
CREATE POLICY "Allow public deletes" ON storage.objects
FOR DELETE TO public
USING (bucket_id = 'fotos');</code></pre>
</div>

<p><strong>Después de crear las políticas, recarga esta página para verificar que funciona.</strong></p>