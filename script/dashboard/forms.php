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

$action = $_GET['action'] ?? 'list';
$form_id = $_GET['id'] ?? null;
$step = $_GET['step'] ?? '1';

$limits = get_plan_limits($user['plan_type']);
$message = '';
$error = '';

function generate_clean_label($field_name) {
    $clean = trim($field_name);
    $clean = str_replace(['ñ', 'Ñ'], ['n', 'N'], $clean);
    $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = ucwords(strtolower($clean));
    return substr($clean, 0, 50);
}

function generate_safe_column_name($field_name) {
    $clean = strtolower(trim($field_name));
    $clean = str_replace(['ñ', 'Ñ'], ['n', 'N'], $clean);
    $clean = preg_replace('/[^a-z0-9_]/', '_', $clean);
    $clean = preg_replace('/_+/', '_', $clean);
    $clean = trim($clean, '_');
    
    $reserved_words = [
        'select', 'insert', 'update', 'delete', 'from', 'where', 'join', 'inner', 'outer', 'left', 'right',
        'on', 'as', 'table', 'column', 'index', 'primary', 'foreign', 'key', 'constraint', 'alter',
        'create', 'drop', 'database', 'schema', 'view', 'trigger', 'function', 'procedure', 'begin',
        'end', 'if', 'then', 'else', 'case', 'when', 'group', 'order', 'by', 'having', 'limit',
        'offset', 'union', 'intersect', 'except', 'exists', 'in', 'not', 'and', 'or', 'like',
        'between', 'is', 'null', 'true', 'false', 'distinct', 'all', 'any', 'some', 'count',
        'sum', 'avg', 'min', 'max', 'user', 'role', 'grant', 'revoke', 'commit', 'rollback'
    ];
    
    if (in_array($clean, $reserved_words)) {
        $clean = 'field_' . $clean;
    }
    
    if (empty($clean) || is_numeric($clean[0])) {
        $clean = 'field_' . $clean;
    }
    
    return substr($clean, 0, 15);
}

function generate_spatial_sql($table_name, $fields) {
    $columns = [
        "id SERIAL PRIMARY KEY",
        "unique_display_id VARCHAR(20) UNIQUE",
        "latitude DECIMAL(10,8)",
        "longitude DECIMAL(11,8)", 
        "gps_accuracy DECIMAL(10,2)",
        "geom GEOMETRY(POINT, 4326)",
        "created_at TIMESTAMP DEFAULT NOW()"
    ];
    
    foreach ($fields as $field) {
        $type = match($field['type']) {
            'number' => 'DECIMAL(10,2)',
            'textarea' => 'TEXT',
            'date' => 'DATE',
            'select', 'radio', 'checkbox' => 'TEXT',
            default => 'VARCHAR(255)'
        };
        
        $safe_column_name = generate_safe_column_name($field['name']);
        $columns[] = "\"{$safe_column_name}\" {$type}";
    }
    
    $sql = "CREATE TABLE {$table_name} (\n  " . implode(",\n  ", $columns) . "\n);\n\n";
    
    $sql .= "ALTER TABLE {$table_name} ENABLE ROW LEVEL SECURITY;\n\n";
    
    $sql .= "CREATE POLICY \"Allow insert only\" ON {$table_name} \n";
    $sql .= "    FOR INSERT \n";
    $sql .= "    WITH CHECK (true);\n\n";
    
    $sql .= "CREATE POLICY \"Allow read only\" ON {$table_name} \n";
    $sql .= "    FOR SELECT \n";
    $sql .= "    USING (true);\n\n";
    
    $sql .= "CREATE INDEX idx_{$table_name}_geom ON {$table_name} USING GIST (geom);\n";
    $sql .= "CREATE INDEX idx_{$table_name}_coords ON {$table_name} (latitude, longitude);\n";
    $sql .= "CREATE UNIQUE INDEX idx_{$table_name}_display_id ON {$table_name} (unique_display_id);\n";
    $sql .= "CREATE INDEX idx_{$table_name}_created_at ON {$table_name} (created_at DESC);\n\n";
    
    $sql .= "CREATE OR REPLACE FUNCTION update_{$table_name}_geom()\n";
    $sql .= "RETURNS TRIGGER AS \$\$\n";
    $sql .= "BEGIN\n";
    $sql .= "    IF NEW.latitude IS NOT NULL AND NEW.longitude IS NOT NULL THEN\n";
    $sql .= "        NEW.geom := ST_SetSRID(ST_MakePoint(NEW.longitude, NEW.latitude), 4326);\n";
    $sql .= "    END IF;\n";
    $sql .= "    RETURN NEW;\n";
    $sql .= "END;\n";
    $sql .= "\$\$ LANGUAGE plpgsql;\n\n";
    
    $sql .= "CREATE TRIGGER trigger_{$table_name}_geom\n";
    $sql .= "    BEFORE INSERT OR UPDATE ON {$table_name}\n";
    $sql .= "    FOR EACH ROW\n";
    $sql .= "    EXECUTE FUNCTION update_{$table_name}_geom();";
    
    return $sql;
}

if ($action === 'create') {
    if (!validate_form_creation($user_id, $user['plan_type'])) {
        header('Location: index.php');
        exit();
    }
    
    $form_data = $_SESSION['form_temp_data'] ?? [];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form_action = $_POST['action'] ?? '';
        
        if ($form_action === 'generate_sql') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $fields = $_POST['fields'] ?? [];
            
            if (empty($title)) {
                $error = 'Title is required';
            } elseif (empty($fields)) {
                $error = 'Fields are required';
            } else {
                $fields_config = [];
                foreach ($fields as $field) {
                    if (!empty($field['name']) && !empty($field['type'])) {
                        $field_data = [
                            'name' => generate_safe_column_name($field['name']),
                            'label' => trim($field['name']),
                            'type' => $field['type'],
                            'required' => isset($field['required'])
                        ];
                        
                        if (in_array($field['type'], ['select', 'radio', 'checkbox'])) {
                            $options = [];
                            if (!empty($field['options'])) {
                                foreach ($field['options'] as $option) {
                                    $option = trim($option);
                                    if (!empty($option)) {
                                        $options[] = $option;
                                    }
                                }
                            }
                            $field_data['options'] = $options;
                        }
                        
                        $fields_config[] = $field_data;
                    }
                }
                
                if (!validate_field_count($user['plan_type'], count($fields_config))) {
                    $error = 'Max fields: ' . $limits['fields_limit'];
                } else {
                    $storage_type = get_storage_type($user);
                    
                    if ($storage_type === 'admin_supabase') {
                        $form_code = generate_form_code();
                        $max_responses = get_max_responses_for_plan($user['plan_type']);
                        
                        $stmt = $pdo->prepare("INSERT INTO forms (user_id, title, description, form_code, fields_config, storage_type, table_name, max_responses) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$user_id, $title, $description, $form_code, json_encode($fields_config), $storage_type, 'responses_free', $max_responses])) {
                            update_user_usage($user_id, 1, 0);
                            unset($_SESSION['form_temp_data']);
                            $message = 'Form created successfully';
                            $action = 'list';
                        } else {
                            $error = 'Error creating form';
                        }
                    } else {
                        $table_name = generate_table_name($title);
                        $sql = generate_spatial_sql($table_name, $fields_config);
                        
                        $_SESSION['form_temp_data'] = [
                            'title' => $title,
                            'description' => $description,
                            'fields_config' => $fields_config,
                            'table_name' => $table_name,
                            'sql' => $sql
                        ];
                        
                        header('Location: forms.php?action=create&step=2');
                        exit();
                    }
                }
            }
        } elseif ($form_action === 'create_form') {
            $temp_data = $_SESSION['form_temp_data'] ?? null;
            if (!$temp_data) {
                $error = 'Form data not found';
            } else {
                $storage_type = get_storage_type($user);
                $table_exists = ($storage_type === 'admin_supabase') ? true : validate_table_exists($user, $temp_data['table_name']);
                
                if (!$table_exists) {
                    $error = 'Table not found: ' . $temp_data['table_name'];
                } else {
                    $form_code = generate_form_code();
                    $max_responses = get_max_responses_for_plan($user['plan_type']);
                    
                    $stmt = $pdo->prepare("INSERT INTO forms (user_id, title, description, form_code, fields_config, storage_type, table_name, max_responses) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$user_id, $temp_data['title'], $temp_data['description'], $form_code, json_encode($temp_data['fields_config']), $storage_type, $temp_data['table_name'], $max_responses])) {
                        update_user_usage($user_id, 1, 0);
                        unset($_SESSION['form_temp_data']);
                        $message = 'Form created successfully';
                        $action = 'list';
                    } else {
                        $error = 'Error creating form';
                    }
                }
            }
        }
    }
} elseif ($action === 'edit' && $form_id) {
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$form_id, $user_id]);
    $form = $stmt->fetch();
    if (!$form) {
        header('Location: forms.php');
        exit();
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $fields = $_POST['fields'] ?? [];
        
        if (empty($title)) {
            $error = 'Title is required';
        } elseif (empty($fields)) {
            $error = 'Fields are required';
        } else {
            $fields_config = [];
            foreach ($fields as $field) {
                if (!empty($field['name']) && !empty($field['type'])) {
                    $field_data = [
                        'name' => generate_safe_column_name($field['name']),
                        'label' => trim($field['name']),
                        'type' => $field['type'],
                        'required' => isset($field['required'])
                    ];
                    
                    if (in_array($field['type'], ['select', 'radio', 'checkbox'])) {
                        $options = [];
                        if (!empty($field['options'])) {
                            foreach ($field['options'] as $option) {
                                $option = trim($option);
                                if (!empty($option)) {
                                    $options[] = $option;
                                }
                            }
                        }
                        $field_data['options'] = $options;
                    }
                    
                    $fields_config[] = $field_data;
                }
            }
            
            if (!validate_field_count($user['plan_type'], count($fields_config))) {
                $error = 'Max fields: ' . $limits['fields_limit'];
            } else {
                $stmt = $pdo->prepare("UPDATE forms SET title = ?, description = ?, fields_config = ? WHERE id = ? AND user_id = ?");
                if ($stmt->execute([$title, $description, json_encode($fields_config), $form_id, $user_id])) {
                    $message = 'Form updated successfully';
                    $action = 'list';
                } else {
                    $error = 'Error updating form';
                }
            }
        }
    }
}

if ($action === 'delete' && $form_id) {
    $stmt = $pdo->prepare("UPDATE forms SET is_active = 0 WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$form_id, $user_id])) {
        update_user_usage($user_id, -1, 0);
        $message = 'Form deleted successfully';
    }
    $action = 'list';
}

if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $forms = $stmt->fetchAll();
}

$storage_type = get_storage_type($user);
$requires_sql_step = ($storage_type !== 'admin_supabase');
$can_create_form = validate_form_creation($user_id, $user['plan_type']);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forms - ArcGeek Survey</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../css/styles.css" rel="stylesheet">
</head>
<body>
    <div class="header-brand">
        <div class="header-content">
            <h1><i class="fas fa-map-marked-alt"></i> ArcGeek Survey</h1>
        </div>
    </div>

    <div class="main-container">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($action === 'list'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-list"></i> My Forms</h2>
                <div>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <?php if ($can_create_form): ?>
                        <a href="?action=create" class="btn btn-primary">
                            <i class="fas fa-plus"></i> New Form
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($forms)): ?>
                <div class="form-card fade-in">
                    <div class="form-card-body text-center py-5">
                        <i class="fas fa-clipboard-list text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3">No forms yet</h4>
                        <p class="text-muted">Create your first form to start collecting data</p>
                        <?php if ($can_create_form): ?>
                            <a href="?action=create" class="btn btn-primary btn-lg">
                                <i class="fas fa-plus"></i> Create Form
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($forms as $form): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="form-card fade-in h-100">
                                <div class="form-card-header">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($form['title']); ?></h6>
                                </div>
                                <div class="form-card-body">
                                    <p class="text-muted small">
                                        <strong>Code:</strong> <span class="text-monospace"><?php echo $form['form_code']; ?></span>
                                    </p>
                                    <?php if ($form['description']): ?>
                                        <p class="small"><?php echo htmlspecialchars($form['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="d-flex justify-content-between text-muted small">
                                        <span><i class="fas fa-chart-bar"></i> Responses: <?php echo $form['response_count']; ?></span>
                                        <span><i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($form['created_at'])); ?></span>
                                    </div>
                                </div>
                                <div class="p-3 border-top">
                                    <div class="d-grid gap-2">
                                        <a href="view-data.php?id=<?php echo $form['id']; ?>" class="btn btn-primary btn-sm">
                                            <i class="fas fa-chart-bar"></i> View Data
                                        </a>
                                        <div class="btn-group">
                                            <a href="?action=edit&id=<?php echo $form['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="../public/collect.php?code=<?php echo $form['form_code']; ?>" class="btn btn-outline-success btn-sm" target="_blank">
                                                <i class="fas fa-external-link-alt"></i> Collect
                                            </a>
                                            <a href="?action=delete&id=<?php echo $form['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'create'): ?>
            <?php if ($step === '1'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-plus"></i> Create Form - Step 1</h2>
                    <a href="?action=list" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-10">
                        <div class="form-card fade-in">
                            <div class="form-card-body">
                                <?php if ($storage_type === 'admin_supabase'): ?>
                                    <div class="alert alert-success">
                                        <h6><i class="fas fa-rocket"></i> Quick Setup</h6>
                                        <p class="mb-0">Using shared database - your form will be created instantly!</p>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-info-circle"></i> Spatial Features</h6>
                                        <p class="mb-0">Your database will include PostGIS spatial functions</p>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" id="formEditor">
                                    <input type="hidden" name="action" value="generate_sql">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Form Title *</label>
                                        <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($form_data['title'] ?? ''); ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Description (Optional)</label>
                                        <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label">Fields *</label>
                                            <div>
                                                <span class="badge bg-info" id="fieldCount">0</span> / <?php echo $limits['fields_limit']; ?>
                                            </div>
                                        </div>
                                        <div id="fieldsContainer"></div>
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addField()">
                                                <i class="fas fa-plus"></i> Add Field
                                            </button>
                                        </div>
                                    </div>

                                    <div class="text-end">
                                        <a href="?action=list" class="btn btn-secondary">Cancel</a>
                                        <?php if ($storage_type === 'admin_supabase'): ?>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-check"></i> Create Form
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-arrow-right"></i> Generate SQL
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($step === '2' && !empty($form_data) && $requires_sql_step): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-database"></i> Create Form - Step 2</h2>
                    <a href="?action=create&step=1" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>

                <div class="row justify-content-center">
                    <div class="col-lg-10">
                        <div class="form-card fade-in">
                            <div class="form-card-body">
                                <div class="alert alert-warning">
                                    <h5><i class="fas fa-database"></i> Execute SQL in your database</h5>
                                    <p class="mb-0">Copy and execute this SQL in your Supabase SQL Editor or PostgreSQL client, then click "Create Form"</p>
                                </div>

                                <div class="bg-dark text-light p-3 rounded mb-4 position-relative">
                                    <button class="btn btn-info btn-sm position-absolute top-0 end-0 m-2" onclick="copySQL()">
                                        <i class="fas fa-copy"></i> Copy
                                    </button>
                                    <pre class="mb-0 mt-3" id="sqlCode" style="font-size: 12px;"><?php echo htmlspecialchars($form_data['sql']); ?></pre>
                                </div>

                                <div class="d-flex gap-3 justify-content-center">
                                    <a href="?action=create&step=1" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="create_form">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="fas fa-check"></i> Create Form
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($action === 'edit'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-edit"></i> Edit Form</h2>
                <a href="?action=list" class="btn btn-outline-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="form-card fade-in">
                        <div class="form-card-body">
                            <form method="POST" id="formEditor">
                                <div class="mb-3">
                                    <label class="form-label">Form Title *</label>
                                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($form['title']); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Description (Optional)</label>
                                    <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($form['description']); ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <label class="form-label">Fields *</label>
                                        <div>
                                            <span class="badge bg-info" id="fieldCount">0</span> / <?php echo $limits['fields_limit']; ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="addField()">
                                                <i class="fas fa-plus"></i> Add Field
                                            </button>
                                        </div>
                                    </div>
                                    <div id="fieldsContainer">
                                        <?php 
                                        $existing_fields = json_decode($form['fields_config'], true) ?: [];
                                        foreach ($existing_fields as $index => $field): ?>
                                            <div class="field-row" data-index="<?php echo $index; ?>">
                                                <button type="button" class="remove-field" onclick="removeField(this)">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <div class="row align-items-end">
                                                    <div class="col-md-4">
                                                        <label class="form-label">Field Name</label>
                                                        <input type="text" name="fields[<?php echo $index; ?>][name]" class="form-control" placeholder="Field Name" value="<?php echo htmlspecialchars($field['label'] ?? $field['name']); ?>" required>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label">Type</label>
                                                        <select name="fields[<?php echo $index; ?>][type]" class="form-select" onchange="toggleOptions(this)" required>
                                                            <option value="">Select Type</option>
                                                            <option value="text" <?php echo ($field['type'] === 'text') ? 'selected' : ''; ?>>Text</option>
                                                            <option value="email" <?php echo ($field['type'] === 'email') ? 'selected' : ''; ?>>Email</option>
                                                            <option value="number" <?php echo ($field['type'] === 'number') ? 'selected' : ''; ?>>Number</option>
                                                            <option value="textarea" <?php echo ($field['type'] === 'textarea') ? 'selected' : ''; ?>>Textarea</option>
                                                            <option value="date" <?php echo ($field['type'] === 'date') ? 'selected' : ''; ?>>Date</option>
                                                            <option value="url" <?php echo ($field['type'] === 'url') ? 'selected' : ''; ?>>URL</option>
                                                            <option value="select" <?php echo ($field['type'] === 'select') ? 'selected' : ''; ?>>Select</option>
                                                            <option value="radio" <?php echo ($field['type'] === 'radio') ? 'selected' : ''; ?>>Radio</option>
                                                            <option value="checkbox" <?php echo ($field['type'] === 'checkbox') ? 'selected' : ''; ?>>Checkbox</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2 text-center">
                                                        <div class="form-check">
                                                            <input type="checkbox" name="fields[<?php echo $index; ?>][required]" class="form-check-input" <?php echo !empty($field['required']) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label">Required</label>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <?php if (in_array($field['type'], ['select', 'radio', 'checkbox'])): ?>
                                                    <div class="field-options" style="display: block;">
                                                        <label class="form-label small">Options</label>
                                                        <div class="options-container">
                                                            <?php if (!empty($field['options'])): ?>
                                                                <?php foreach ($field['options'] as $opt_index => $option): ?>
                                                                    <div class="option-item">
                                                                        <input type="text" name="fields[<?php echo $index; ?>][options][]" class="form-control form-control-sm" placeholder="Option text" value="<?php echo htmlspecialchars($option); ?>">
                                                                        <button type="button" class="remove-option" onclick="removeOption(this)">
                                                                            <i class="fas fa-times"></i>
                                                                        </button>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addOption(this)">
                                                            <i class="fas fa-plus"></i> Add Option
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="field-options" style="display: none;">
                                                        <label class="form-label small">Options</label>
                                                        <div class="options-container"></div>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addOption(this)">
                                                            <i class="fas fa-plus"></i> Add Option
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="text-end">
                                    <a href="?action=list" class="btn btn-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let fieldIndex = <?php echo $action === 'edit' ? count($existing_fields ?? []) : 0; ?>;
        const maxFields = <?php echo $limits['fields_limit']; ?>;

        function updateFieldCount() {
            const count = document.querySelectorAll('.field-row').length;
            const counter = document.getElementById('fieldCount');
            if (counter) {
                counter.textContent = count;
            }
        }

        function addField() {
            if (document.querySelectorAll('.field-row').length >= maxFields) {
                alert('Max fields: ' + maxFields);
                return;
            }

            const container = document.getElementById('fieldsContainer');
            if (!container) return;

            const fieldHtml = `
                <div class="field-row" data-index="${fieldIndex}">
                    <button type="button" class="remove-field" onclick="removeField(this)">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="row align-items-end">
                        <div class="col-md-4">
                            <label class="form-label">Field Name</label>
                            <input type="text" name="fields[${fieldIndex}][name]" class="form-control" placeholder="Field Name" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select name="fields[${fieldIndex}][type]" class="form-select" onchange="toggleOptions(this)" required>
                                <option value="">Select Type</option>
                                <option value="text">Text</option>
                                <option value="email">Email</option>
                                <option value="number">Number</option>
                                <option value="textarea">Textarea</option>
                                <option value="date">Date</option>
                                <option value="url">URL</option>
                                <option value="select">Select</option>
                                <option value="radio">Radio</option>
                                <option value="checkbox">Checkbox</option>
                            </select>
                        </div>
                        <div class="col-md-2 text-center">
                            <div class="form-check">
                                <input type="checkbox" name="fields[${fieldIndex}][required]" class="form-check-input" id="req_${fieldIndex}">
                                <label class="form-check-label" for="req_${fieldIndex}">Required</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="field-options" style="display: none;">
                        <label class="form-label small">Options</label>
                        <div class="options-container"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addOption(this)">
                            <i class="fas fa-plus"></i> Add Option
                        </button>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', fieldHtml);
            fieldIndex++;
            updateFieldCount();
        }

        function removeField(button) {
            if (button && button.closest) {
                button.closest('.field-row').remove();
                updateFieldCount();
            }
        }

        function toggleOptions(selectElement) {
            const fieldRow = selectElement.closest('.field-row');
            const optionsDiv = fieldRow.querySelector('.field-options');
            const optionsContainer = fieldRow.querySelector('.options-container');
            
            if (['select', 'radio', 'checkbox'].includes(selectElement.value)) {
                optionsDiv.style.display = 'block';
                if (optionsContainer.children.length === 0) {
                    addOption(optionsDiv.querySelector('button'));
                }
            } else {
                optionsDiv.style.display = 'none';
            }
        }

        function addOption(button) {
            const optionsContainer = button.parentElement.querySelector('.options-container');
            const fieldRow = button.closest('.field-row');
            const fieldIndex = fieldRow.dataset.index;
            
            const optionHtml = `
                <div class="option-item">
                    <input type="text" name="fields[${fieldIndex}][options][]" class="form-control form-control-sm" placeholder="Option text" required>
                    <button type="button" class="remove-option" onclick="removeOption(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            optionsContainer.insertAdjacentHTML('beforeend', optionHtml);
        }

        function removeOption(button) {
            button.closest('.option-item').remove();
        }

        function copySQL() {
            const sqlElement = document.getElementById('sqlCode');
            if (!sqlElement) return;

            const sql = sqlElement.textContent;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(sql).then(() => {
                    const btn = event.target.closest('button');
                    const originalHTML = btn.innerHTML;
                    btn.innerHTML = '<i class="fas fa-check"></i> Copied';
                    btn.classList.replace('btn-info', 'btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalHTML;
                        btn.classList.replace('btn-success', 'btn-info');
                    }, 2000);
                });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            updateFieldCount();
            
            <?php if ($action === 'create' && $step === '1'): ?>
                if (document.querySelectorAll('.field-row').length === 0) {
                    addField();
                }
            <?php endif; ?>

            const form = document.getElementById('formEditor');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const fieldCount = document.querySelectorAll('.field-row').length;
                    if (fieldCount === 0) {
                        e.preventDefault();
                        alert('Please add at least one field');
                        return false;
                    }
                    
                    const selectFields = document.querySelectorAll('select[name*="[type]"]');
                    for (let select of selectFields) {
                        if (['select', 'radio', 'checkbox'].includes(select.value)) {
                            const fieldRow = select.closest('.field-row');
                            const options = fieldRow.querySelectorAll('input[name*="[options]"]');
                            const hasValidOptions = Array.from(options).some(opt => opt.value.trim() !== '');
                            
                            if (!hasValidOptions) {
                                e.preventDefault();
                                alert(`Please add at least one option for the ${select.value} field.`);
                                return false;
                            }
                        }
                    }
                });
            }
            
            const existingSelects = document.querySelectorAll('select[name*="[type]"]');
            existingSelects.forEach(select => {
                if (['select', 'radio', 'checkbox'].includes(select.value)) {
                    toggleOptions(select);
                }
            });
        });
    </script>
</body>
</html>