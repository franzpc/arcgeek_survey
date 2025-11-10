<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/plans.php';
require_once '../config/security.php';

if (!validate_session()) {
    header('Location: ../auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user = get_user_by_id($user_id);
if (!$user) {
    header('Location: ../auth/logout.php');
    exit();
}

$lang = $user['language'];
$strings = include "../lang/{$lang}.php";

$form_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$form_id) {
    header('Location: index.php');
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ? AND user_id = ?");
$stmt->execute([$form_id, $user_id]);
$form = $stmt->fetch();

if (!$form) {
    header('Location: index.php');
    exit();
}

$fields_config = json_decode($form['fields_config'], true);

if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $responses = get_responses($form_id, 10000, 0);
    
    if (empty($responses)) {
        header('Location: view-data.php?id=' . $form_id . '&error=no_data');
        exit();
    }
    
    $filename_base = 'form_' . $form['form_code'] . '_' . date('Y-m-d');
    
    switch ($format) {
        case 'csv':
            export_csv($responses, $filename_base);
            break;
        case 'json':
            export_json($responses, $filename_base);
            break;
        case 'geojson':
            export_geojson($responses, $filename_base);
            break;
        case 'kml':
            export_kml($responses, $filename_base, $form, $fields_config);
            break;
        case 'gpkg':
            export_geopackage($responses, $filename_base, $form, $fields_config);
            break;
        case 'shp':
            export_shapefile($responses, $filename_base, $form, $fields_config);
            break;
        default:
            header('Location: view-data.php?id=' . $form_id . '&error=invalid_format');
            exit();
    }
}

function export_csv($responses, $filename_base) {
    $filename = $filename_base . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ["\xEF\xBB\xBF"]);
    
    if (!empty($responses)) {
        fputcsv($output, array_keys($responses[0]));
        foreach ($responses as $response) {
            fputcsv($output, $response);
        }
    }
    fclose($output);
    exit();
}

function export_json($responses, $filename_base) {
    $filename = $filename_base . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo json_encode($responses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

function export_geojson($responses, $filename_base) {
    $filename = $filename_base . '.geojson';
    $features = [];
    
    foreach ($responses as $response) {
        if (!empty($response['latitude']) && !empty($response['longitude'])) {
            $properties = $response;
            unset($properties['latitude'], $properties['longitude']);
            
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [floatval($response['longitude']), floatval($response['latitude'])]
                ],
                'properties' => $properties
            ];
        }
    }
    
    $geojson = [
        'type' => 'FeatureCollection',
        'crs' => [
            'type' => 'name',
            'properties' => ['name' => 'EPSG:4326']
        ],
        'features' => $features
    ];
    
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

function export_kml($responses, $filename_base, $form, $fields_config) {
    $filename = $filename_base . '.kml';
    
    $kml = '<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
<Document>
    <name>' . htmlspecialchars($form['title']) . '</name>
    <description>' . htmlspecialchars($form['description'] ?? '') . '</description>
    
    <Style id="point_style">
        <IconStyle>
            <Icon>
                <href>http://maps.google.com/mapfiles/kml/paddle/blu-circle.png</href>
            </Icon>
        </IconStyle>
    </Style>';
    
    foreach ($responses as $response) {
        if (!empty($response['latitude']) && !empty($response['longitude'])) {
            $name = $response['unique_display_id'] ?? $response['id'] ?? 'Point';
            $description = '<![CDATA[<table>';
            
            foreach ($fields_config as $field) {
                $value = $response[$field['name']] ?? '';
                $description .= '<tr><td><b>' . htmlspecialchars($field['label']) . ':</b></td><td>' . htmlspecialchars($value) . '</td></tr>';
            }
            
            $description .= '<tr><td><b>GPS Accuracy:</b></td><td>' . ($response['gps_accuracy'] ?? 'N/A') . 'm</td></tr>';
            $description .= '<tr><td><b>Created:</b></td><td>' . ($response['created_at'] ?? 'N/A') . '</td></tr>';
            $description .= '</table>]]>';
            
            $kml .= '
    <Placemark>
        <name>' . htmlspecialchars($name) . '</name>
        <description>' . $description . '</description>
        <styleUrl>#point_style</styleUrl>
        <Point>
            <coordinates>' . $response['longitude'] . ',' . $response['latitude'] . ',0</coordinates>
        </Point>
    </Placemark>';
        }
    }
    
    $kml .= '
</Document>
</kml>';
    
    header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo $kml;
    exit();
}

function export_geopackage($responses, $filename_base, $form, $fields_config) {
    try {
        $filename = $filename_base . '.gpkg';
        $temp_path = sys_get_temp_dir() . '/' . uniqid('gpkg_') . '.gpkg';
        
        $dsn = "sqlite:" . $temp_path;
        $gpkg_pdo = new PDO($dsn);
        $gpkg_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $gpkg_pdo->exec("PRAGMA application_id = 1196444487");
        $gpkg_pdo->exec("PRAGMA user_version = 10200");
        
        $gpkg_pdo->exec("CREATE TABLE gpkg_spatial_ref_sys (
            srs_name TEXT NOT NULL,
            srs_id INTEGER NOT NULL PRIMARY KEY,
            organization TEXT NOT NULL,
            organization_coordsys_id INTEGER NOT NULL,
            definition TEXT NOT NULL,
            description TEXT
        )");
        
        $gpkg_pdo->exec("INSERT INTO gpkg_spatial_ref_sys VALUES (
            'WGS 84',
            4326,
            'EPSG',
            4326,
            'GEOGCS[\"WGS 84\",DATUM[\"WGS_1984\",SPHEROID[\"WGS 84\",6378137,298.257223563,AUTHORITY[\"EPSG\",\"7030\"]],AUTHORITY[\"EPSG\",\"6326\"]],PRIMEM[\"Greenwich\",0,AUTHORITY[\"EPSG\",\"8901\"]],UNIT[\"degree\",0.0174532925199433,AUTHORITY[\"EPSG\",\"9122\"]],AUTHORITY[\"EPSG\",\"4326\"]]',
            'longitude/latitude coordinates in decimal degrees on the WGS 84 spheroid'
        )");
        
        $gpkg_pdo->exec("CREATE TABLE gpkg_contents (
            table_name TEXT NOT NULL PRIMARY KEY,
            data_type TEXT NOT NULL,
            identifier TEXT UNIQUE,
            description TEXT DEFAULT '',
            last_change DATETIME NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
            min_x REAL,
            min_y REAL,
            max_x REAL,
            max_y REAL,
            srs_id INTEGER,
            CONSTRAINT fk_gc_r_srs_id FOREIGN KEY (srs_id) REFERENCES gpkg_spatial_ref_sys(srs_id)
        )");
        
        $gpkg_pdo->exec("CREATE TABLE gpkg_geometry_columns (
            table_name TEXT NOT NULL,
            column_name TEXT NOT NULL,
            geometry_type_name TEXT NOT NULL,
            srs_id INTEGER NOT NULL,
            z TINYINT NOT NULL,
            m TINYINT NOT NULL,
            CONSTRAINT pk_geom_cols PRIMARY KEY (table_name, column_name),
            CONSTRAINT uk_gc_table_name UNIQUE (table_name),
            CONSTRAINT fk_gc_tn FOREIGN KEY (table_name) REFERENCES gpkg_contents(table_name),
            CONSTRAINT fk_gc_srs FOREIGN KEY (srs_id) REFERENCES gpkg_spatial_ref_sys (srs_id)
        )");
        
        $table_name = 'survey_data';
        
        $columns = [
            'fid INTEGER PRIMARY KEY AUTOINCREMENT',
            'geom GEOMETRY',
            'unique_id TEXT',
            'accuracy REAL',
            'created_at TEXT'
        ];
        
        foreach ($fields_config as $field) {
            $col_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $field['name']);
            $columns[] = "{$col_name} TEXT";
        }
        
        $create_sql = "CREATE TABLE {$table_name} (" . implode(', ', $columns) . ")";
        $gpkg_pdo->exec($create_sql);
        
        $bounds = ['min_x' => 180, 'max_x' => -180, 'min_y' => 90, 'max_y' => -90];
        
        foreach ($responses as $response) {
            if (!empty($response['latitude']) && !empty($response['longitude'])) {
                $lat = floatval($response['latitude']);
                $lon = floatval($response['longitude']);
                
                $bounds['min_x'] = min($bounds['min_x'], $lon);
                $bounds['max_x'] = max($bounds['max_x'], $lon);
                $bounds['min_y'] = min($bounds['min_y'], $lat);
                $bounds['max_y'] = max($bounds['max_y'], $lat);
                
                $wkb_point = pack('VVdd', 0x01, 0x01, $lon, $lat);
                
                $values = [
                    ':geom' => $wkb_point,
                    ':unique_id' => $response['unique_display_id'] ?? $response['id'] ?? '',
                    ':accuracy' => $response['gps_accuracy'] ?? null,
                    ':created_at' => $response['created_at'] ?? ''
                ];
                
                $placeholders = [':geom', ':unique_id', ':accuracy', ':created_at'];
                
                foreach ($fields_config as $field) {
                    $col_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $field['name']);
                    $placeholder = ':' . $col_name;
                    $values[$placeholder] = $response[$field['name']] ?? '';
                    $placeholders[] = $placeholder;
                }
                
                $insert_sql = "INSERT INTO {$table_name} (geom, unique_id, accuracy, created_at, " . 
                    implode(', ', array_map(function($f) { 
                        return preg_replace('/[^a-zA-Z0-9_]/', '_', $f['name']); 
                    }, $fields_config)) . 
                    ") VALUES (" . implode(', ', $placeholders) . ")";
                
                $stmt = $gpkg_pdo->prepare($insert_sql);
                $stmt->execute($values);
            }
        }
        
        $gpkg_pdo->exec("INSERT INTO gpkg_contents VALUES (
            '{$table_name}',
            'features',
            '{$form['title']}',
            '{$form['description']}',
            datetime('now'),
            {$bounds['min_x']},
            {$bounds['min_y']},
            {$bounds['max_x']},
            {$bounds['max_y']},
            4326
        )");
        
        $gpkg_pdo->exec("INSERT INTO gpkg_geometry_columns VALUES (
            '{$table_name}',
            'geom',
            'POINT',
            4326,
            0,
            0
        )");
        
        header('Content-Type: application/geopackage+sqlite3');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Length: ' . filesize($temp_path));
        
        readfile($temp_path);
        unlink($temp_path);
        exit();
        
    } catch (Exception $e) {
        error_log("GeoPackage export error: " . $e->getMessage());
        header('Location: view-data.php?id=' . $form_id . '&error=export_failed');
        exit();
    }
}

function export_shapefile($responses, $filename_base, $form, $fields_config) {
    try {
        $filename = $filename_base . '_shapefile.zip';
        $temp_dir = sys_get_temp_dir() . '/' . uniqid('shp_');
        mkdir($temp_dir);
        
        $base_name = 'survey_data';
        $shp_file = $temp_dir . '/' . $base_name . '.shp';
        $shx_file = $temp_dir . '/' . $base_name . '.shx';
        $dbf_file = $temp_dir . '/' . $base_name . '.dbf';
        $prj_file = $temp_dir . '/' . $base_name . '.prj';
        
        $prj_content = 'GEOGCS["WGS 84",DATUM["WGS_1984",SPHEROID["WGS 84",6378137,298.257223563,AUTHORITY["EPSG","7030"]],AUTHORITY["EPSG","6326"]],PRIMEM["Greenwich",0,AUTHORITY["EPSG","8901"]],UNIT["degree",0.0174532925199433,AUTHORITY["EPSG","9122"]],AUTHORITY["EPSG","4326"]]';
        file_put_contents($prj_file, $prj_content);
        
        $valid_responses = array_filter($responses, function($r) {
            return !empty($r['latitude']) && !empty($r['longitude']);
        });
        
        if (empty($valid_responses)) {
            throw new Exception("No valid coordinates found");
        }
        
        $shp_handle = fopen($shp_file, 'wb');
        $shx_handle = fopen($shx_file, 'wb');
        
        $file_length = 50 + (count($valid_responses) * 14);
        $bbox = calculate_bbox($valid_responses);
        
        fwrite($shp_handle, pack('N', 9994));
        fwrite($shp_handle, str_repeat("\0", 20));
        fwrite($shp_handle, pack('N', $file_length));
        fwrite($shp_handle, pack('V', 1000));
        fwrite($shp_handle, pack('V', 1));
        fwrite($shp_handle, pack('d', $bbox['min_x']));
        fwrite($shp_handle, pack('d', $bbox['min_y']));
        fwrite($shp_handle, pack('d', $bbox['max_x']));
        fwrite($shp_handle, pack('d', $bbox['max_y']));
        fwrite($shp_handle, str_repeat("\0", 32));
        
        fwrite($shx_handle, pack('N', 9994));
        fwrite($shx_handle, str_repeat("\0", 20));
        fwrite($shx_handle, pack('N', 50 + (count($valid_responses) * 4)));
        fwrite($shx_handle, pack('V', 1000));
        fwrite($shx_handle, pack('V', 1));
        fwrite($shx_handle, pack('d', $bbox['min_x']));
        fwrite($shx_handle, pack('d', $bbox['min_y']));
        fwrite($shx_handle, pack('d', $bbox['max_x']));
        fwrite($shx_handle, pack('d', $bbox['max_y']));
        fwrite($shx_handle, str_repeat("\0", 32));
        
        $offset = 50;
        $record_number = 1;
        
        foreach ($valid_responses as $response) {
            $content_length = 10;
            
            fwrite($shp_handle, pack('N', $record_number));
            fwrite($shp_handle, pack('N', $content_length));
            fwrite($shp_handle, pack('V', 1));
            fwrite($shp_handle, pack('d', floatval($response['longitude'])));
            fwrite($shp_handle, pack('d', floatval($response['latitude'])));
            
            fwrite($shx_handle, pack('N', $offset));
            fwrite($shx_handle, pack('N', $content_length));
            
            $offset += 4 + $content_length;
            $record_number++;
        }
        
        fclose($shp_handle);
        fclose($shx_handle);
        
        $dbf_fields = [
            ['name' => 'UNIQUE_ID', 'type' => 'C', 'length' => 20],
            ['name' => 'ACCURACY', 'type' => 'N', 'length' => 10, 'decimals' => 2],
            ['name' => 'CREATED_AT', 'type' => 'C', 'length' => 19]
        ];
        
        foreach ($fields_config as $field) {
            $field_name = strtoupper(substr(preg_replace('/[^A-Z0-9_]/', '_', strtoupper($field['name'])), 0, 10));
            $dbf_fields[] = ['name' => $field_name, 'type' => 'C', 'length' => 100];
        }
        
        create_dbf($dbf_file, $dbf_fields, $valid_responses, $fields_config);
        
        $zip = new ZipArchive();
        if ($zip->open($temp_dir . '/shapefile.zip', ZipArchive::CREATE) === TRUE) {
            $zip->addFile($shp_file, $base_name . '.shp');
            $zip->addFile($shx_file, $base_name . '.shx');
            $zip->addFile($dbf_file, $base_name . '.dbf');
            $zip->addFile($prj_file, $base_name . '.prj');
            $zip->close();
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Content-Length: ' . filesize($temp_dir . '/shapefile.zip'));
            
            readfile($temp_dir . '/shapefile.zip');
            
            array_map('unlink', glob($temp_dir . '/*'));
            rmdir($temp_dir);
            exit();
        } else {
            throw new Exception("Could not create ZIP file");
        }
        
    } catch (Exception $e) {
        error_log("Shapefile export error: " . $e->getMessage());
        header('Location: view-data.php?id=' . $form_id . '&error=export_failed');
        exit();
    }
}

function calculate_bbox($responses) {
    $bbox = ['min_x' => 180, 'max_x' => -180, 'min_y' => 90, 'max_y' => -90];
    
    foreach ($responses as $response) {
        $lat = floatval($response['latitude']);
        $lon = floatval($response['longitude']);
        
        $bbox['min_x'] = min($bbox['min_x'], $lon);
        $bbox['max_x'] = max($bbox['max_x'], $lon);
        $bbox['min_y'] = min($bbox['min_y'], $lat);
        $bbox['max_y'] = max($bbox['max_y'], $lat);
    }
    
    return $bbox;
}

function create_dbf($filename, $fields, $responses, $fields_config) {
    $fp = fopen($filename, 'wb');
    
    $record_count = count($responses);
    $header_length = 32 + (count($fields) * 32) + 1;
    $record_length = 1;
    
    foreach ($fields as $field) {
        $record_length += $field['length'];
    }
    
    fwrite($fp, pack('C', 0x03));
    fwrite($fp, pack('C3', date('y'), date('m'), date('d')));
    fwrite($fp, pack('V', $record_count));
    fwrite($fp, pack('v', $header_length));
    fwrite($fp, pack('v', $record_length));
    fwrite($fp, str_repeat("\0", 20));
    
    foreach ($fields as $field) {
        fwrite($fp, str_pad($field['name'], 11, "\0"));
        fwrite($fp, $field['type']);
        fwrite($fp, str_repeat("\0", 4));
        fwrite($fp, chr($field['length']));
        fwrite($fp, chr($field['decimals'] ?? 0));
        fwrite($fp, str_repeat("\0", 14));
    }
    
    fwrite($fp, "\r");
    
    foreach ($responses as $response) {
        fwrite($fp, ' ');
        
        $unique_id = substr($response['unique_display_id'] ?? $response['id'] ?? '', 0, 20);
        fwrite($fp, str_pad($unique_id, 20, ' '));
        
        $accuracy = number_format(floatval($response['gps_accuracy'] ?? 0), 2);
        fwrite($fp, str_pad($accuracy, 10, ' ', STR_PAD_LEFT));
        
        $created_at = substr($response['created_at'] ?? '', 0, 19);
        fwrite($fp, str_pad($created_at, 19, ' '));
        
        foreach ($fields_config as $field) {
            $value = substr($response[$field['name']] ?? '', 0, 100);
            fwrite($fp, str_pad($value, 100, ' '));
        }
    }
    
    fwrite($fp, "\x1A");
    fclose($fp);
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$responses = get_responses($form_id, $limit, $offset);
$total_responses = $form['response_count'];
$total_pages = ceil($total_responses / $limit);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $strings['view_data']; ?> - <?php echo htmlspecialchars($form['title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <style>
        .clickable-row { cursor: pointer; }
        .clickable-row:hover { background-color: #f8f9fa; }
        .selected-row { background-color: #e3f2fd !important; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-map-marked-alt"></i> ArcGeek Survey
            </a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><?php echo htmlspecialchars($form['title']); ?></h2>
                <p class="text-muted mb-0">
                    <?php echo $strings['code']; ?>: <code><?php echo $form['form_code']; ?></code> | 
                    <?php echo $total_responses; ?> <?php echo $strings['responses']; ?>
                </p>
            </div>
            <div>
                <a href="index.php" class="btn btn-outline-secondary"><?php echo $strings['dashboard']; ?></a>
                <a href="forms.php?action=edit&id=<?php echo $form['id']; ?>" class="btn btn-outline-primary"><?php echo $strings['edit']; ?></a>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-primary"><?php echo $total_responses; ?></h5>
                        <p class="card-text mb-0"><?php echo $strings['responses']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-success"><?php echo count($fields_config); ?></h5>
                        <p class="card-text mb-0"><?php echo $strings['fields']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-info"><?php echo $form['storage_type']; ?></h5>
                        <p class="card-text mb-0"><?php echo $strings['storage']; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="text-warning"><?php echo date('M j', strtotime($form['created_at'])); ?></h5>
                        <p class="card-text mb-0"><?php echo $strings['created']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($responses)): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-map"></i> <?php echo $strings['location']; ?></h5>
                    <button class="btn btn-outline-primary btn-sm" onclick="toggleMap()">
                        <i class="fas fa-eye"></i> <span id="mapToggleText">Show Map</span>
                    </button>
                </div>
                <div class="card-body" id="mapContainer" style="display: none;">
                    <div id="map" style="height: 400px; border-radius: 8px;"></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-table"></i> <?php echo $strings['responses']; ?></h5>
                <?php if (!empty($responses)): ?>
                    <div class="btn-group">
                        <button class="btn btn-success btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="?id=<?php echo $form['id']; ?>&export=csv">
                                <i class="fas fa-file-csv"></i> CSV
                            </a></li>
                            <li><a class="dropdown-item" href="?id=<?php echo $form['id']; ?>&export=json">
                                <i class="fas fa-file-code"></i> JSON
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="?id=<?php echo $form['id']; ?>&export=geojson">
                                <i class="fas fa-map-marked-alt"></i> GeoJSON
                            </a></li>
                            <li><a class="dropdown-item" href="?id=<?php echo $form['id']; ?>&export=kml">
                                <i class="fas fa-globe"></i> KML (Google Earth)
                            </a></li>
                            <li><a class="dropdown-item" href="?id=<?php echo $form['id']; ?>&export=gpkg">
                                <i class="fas fa-database"></i> GeoPackage
                            </a></li>
                            <li><a class="dropdown-item" href="?id=<?php echo $form['id']; ?>&export=shp">
                                <i class="fas fa-layer-group"></i> Shapefile
                            </a></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($responses)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3"><?php echo $strings['no_data']; ?></h4>
                        <p class="text-muted">Share the link to start collecting data</p>
                        <div class="alert alert-info d-inline-block">
                            <strong>Collection Link:</strong><br>
                            <code><?php echo $_SERVER['HTTP_HOST']; ?>/public/collect.php?code=<?php echo $form['form_code']; ?></code>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <?php foreach ($fields_config as $field): ?>
                                        <th><?php echo htmlspecialchars($field['label']); ?></th>
                                    <?php endforeach; ?>
                                    <th><?php echo $strings['location']; ?></th>
                                    <th><?php echo $strings['date']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($responses as $response): ?>
                                    <tr class="clickable-row" data-lat="<?php echo $response['latitude'] ?? ''; ?>" data-lng="<?php echo $response['longitude'] ?? ''; ?>" data-id="<?php echo $response['unique_display_id'] ?? $response['id'] ?? ''; ?>">
                                        <td>
                                            <code><?php echo htmlspecialchars($response['unique_display_id'] ?? $response['id'] ?? ''); ?></code>
                                        </td>
                                        <?php foreach ($fields_config as $field): ?>
                                            <td>
                                                <?php 
                                                $value = $response[$field['name']] ?? '';
                                                if ($field['type'] === 'url' && !empty($value)) {
                                                    echo '<a href="' . htmlspecialchars($value) . '" target="_blank">' . htmlspecialchars($value) . '</a>';
                                                } elseif ($field['type'] === 'email' && !empty($value)) {
                                                    echo '<a href="mailto:' . htmlspecialchars($value) . '">' . htmlspecialchars($value) . '</a>';
                                                } else {
                                                    echo htmlspecialchars($value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td>
                                            <?php if (!empty($response['latitude']) && !empty($response['longitude'])): ?>
                                                <small class="text-muted">
                                                    <?php echo number_format($response['latitude'], 6); ?>, <?php echo number_format($response['longitude'], 6); ?>
                                                    <?php if (!empty($response['gps_accuracy'])): ?>
                                                        <br>±<?php echo round($response['gps_accuracy']); ?>m
                                                    <?php endif; ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo date('M j, Y H:i', strtotime($response['created_at'])); ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <nav class="mt-4">
                            <ul class="pagination justify-content-center">
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?id=<?php echo $form['id']; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($responses)): ?>
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6><i class="fas fa-chart-bar"></i> Statistics</h6>
                        </div>
                        <div class="card-body">
                            <?php
                            $valid_coords = array_filter($responses, function($r) {
                                return !empty($r['latitude']) && !empty($r['longitude']);
                            });
                            $coord_count = count($valid_coords);
                            
                            $avg_accuracy = 0;
                            if ($coord_count > 0) {
                                $accuracies = array_filter(array_map(function($r) {
                                    return floatval($r['gps_accuracy'] ?? 0);
                                }, $valid_coords));
                                
                                if (!empty($accuracies)) {
                                    $avg_accuracy = array_sum($accuracies) / count($accuracies);
                                }
                            }
                            ?>
                            <ul class="list-unstyled mb-0">
                                <li><strong>Total Responses:</strong> <?php echo $total_responses; ?></li>
                                <li><strong>With Coordinates:</strong> <?php echo $coord_count; ?> (<?php echo $total_responses > 0 ? round(($coord_count / $total_responses) * 100, 1) : 0; ?>%)</li>
                                <li><strong>Average GPS Accuracy:</strong> ±<?php echo round($avg_accuracy, 1); ?>m</li>
                                <li><strong>Collection Link:</strong> 
                                    <button class="btn btn-outline-primary btn-sm" onclick="copyToClipboard('<?php echo $_SERVER['HTTP_HOST']; ?>/public/collect.php?code=<?php echo $form['form_code']; ?>')">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h6><i class="fas fa-info-circle"></i> Export Information</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <li><strong>CSV:</strong> Spreadsheet format, all data</li>
                                <li><strong>JSON:</strong> Web format, all data</li>
                                <li><strong>GeoJSON:</strong> Web GIS format</li>
                                <li><strong>KML:</strong> Google Earth format</li>
                                <li><strong>GeoPackage:</strong> OGC standard format</li>
                                <li><strong>Shapefile:</strong> ESRI format (ZIP)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map = null;
        let mapVisible = false;
        let markers = [];
        let selectedRow = null;

        function toggleMap() {
            const container = document.getElementById('mapContainer');
            const toggleText = document.getElementById('mapToggleText');
            
            if (!mapVisible) {
                container.style.display = 'block';
                toggleText.textContent = 'Hide Map';
                mapVisible = true;
                
                if (!map) {
                    initMap();
                } else {
                    setTimeout(() => map.invalidateSize(), 100);
                }
            } else {
                container.style.display = 'none';
                toggleText.textContent = 'Show Map';
                mapVisible = false;
            }
        }

        function initMap() {
            const responses = <?php echo json_encode($responses); ?>;
            const validPoints = responses.filter(r => r.latitude && r.longitude);
            
            if (validPoints.length === 0) {
                document.getElementById('map').innerHTML = '<div class="text-center p-5"><h5>No valid coordinates found</h5></div>';
                return;
            }
            
            const bounds = validPoints.reduce((acc, point) => {
                const lat = parseFloat(point.latitude);
                const lng = parseFloat(point.longitude);
                if (!acc.minLat || lat < acc.minLat) acc.minLat = lat;
                if (!acc.maxLat || lat > acc.maxLat) acc.maxLat = lat;
                if (!acc.minLng || lng < acc.minLng) acc.minLng = lng;
                if (!acc.maxLng || lng > acc.maxLng) acc.maxLng = lng;
                return acc;
            }, {});
            
            const centerLat = (bounds.minLat + bounds.maxLat) / 2;
            const centerLng = (bounds.minLng + bounds.maxLng) / 2;
            
            map = L.map('map').setView([centerLat, centerLng], 12);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(map);
            
            validPoints.forEach((point, index) => {
                const lat = parseFloat(point.latitude);
                const lng = parseFloat(point.longitude);
                
                let popupContent = `<strong>ID: ${point.unique_display_id || point.id}</strong><br>`;
                
                <?php foreach ($fields_config as $field): ?>
                    if (point['<?php echo $field['name']; ?>']) {
                        popupContent += '<?php echo htmlspecialchars($field['label']); ?>: ' + point['<?php echo $field['name']; ?>'] + '<br>';
                    }
                <?php endforeach; ?>
                
                if (point.gps_accuracy) {
                    popupContent += 'GPS Accuracy: ±' + Math.round(point.gps_accuracy) + 'm<br>';
                }
                popupContent += 'Date: ' + new Date(point.created_at).toLocaleString();
                
                const marker = L.marker([lat, lng]).addTo(map);
                marker.bindPopup(popupContent);
                marker.pointData = point;
                
                markers.push(marker);
            });
            
            if (validPoints.length > 1) {
                map.fitBounds([[bounds.minLat, bounds.minLng], [bounds.maxLat, bounds.maxLng]], {padding: [20, 20]});
            }
        }

        function zoomToPoint(lat, lng, responseId) {
            if (!map || !mapVisible) {
                toggleMap();
                setTimeout(() => zoomToPoint(lat, lng, responseId), 500);
                return;
            }
            
            map.setView([lat, lng], 18);
            
            markers.forEach(marker => {
                if (marker.pointData && (marker.pointData.unique_display_id === responseId || marker.pointData.id === responseId)) {
                    marker.openPopup();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('.clickable-row');
            
            rows.forEach(row => {
                row.addEventListener('click', function() {
                    const lat = parseFloat(this.dataset.lat);
                    const lng = parseFloat(this.dataset.lng);
                    const responseId = this.dataset.id;
                    
                    if (selectedRow) {
                        selectedRow.classList.remove('selected-row');
                    }
                    
                    this.classList.add('selected-row');
                    selectedRow = this;
                    
                    if (lat && lng) {
                        zoomToPoint(lat, lng, responseId);
                    }
                });
            });
        });

        function copyToClipboard(text) {
            const fullUrl = 'https://' + text;
            navigator.clipboard.writeText(fullUrl).then(() => {
                const btn = event.target.closest('button');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied';
                btn.classList.replace('btn-outline-primary', 'btn-success');
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.replace('btn-success', 'btn-outline-primary');
                }, 2000);
            }).catch(() => {
                alert('Could not copy to clipboard');
            });
        }

        <?php if (isset($_GET['error'])): ?>
            <?php if ($_GET['error'] === 'no_data'): ?>
                alert('No data available for export');
            <?php elseif ($_GET['error'] === 'export_failed'): ?>
                alert('Export failed. Please try again.');
            <?php elseif ($_GET['error'] === 'invalid_format'): ?>
                alert('Invalid export format');
            <?php endif; ?>
        <?php endif; ?>
    </script>
</body>
</html>