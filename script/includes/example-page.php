<?php
// Example page demonstrating header/footer usage
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

// Optional: Page-specific configuration
$page_title = "Example Page";
$navbar_class = "bg-primary";

// Include header
include 'header.php';
?>

<!-- Page Content -->
<div class="row">
    <div class="col-md-12">
        <h2>Example Page</h2>
        <p class="text-muted">This demonstrates how to use the global header and footer components.</p>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5>Features</h5>
            </div>
            <div class="card-body">
                <ul>
                    <li>Responsive navigation</li>
                    <li>Language switcher</li>
                    <li>User authentication</li>
                    <li>Consistent branding</li>
                    <li>Mobile-friendly</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5>Configuration</h5>
            </div>
            <div class="card-body">
                <p>All settings are managed through the database:</p>
                <ul class="small">
                    <li><strong>Site Name:</strong> <?php echo htmlspecialchars($site_config['name']); ?></li>
                    <li><strong>Support Email:</strong> <?php echo htmlspecialchars($site_config['support_email']); ?></li>
                    <li><strong>Footer Text:</strong> <?php echo htmlspecialchars($site_config['footer_text']); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
// Optional: Add custom scripts
$additional_footer_scripts = '
<script>
    console.log("Custom page script loaded");
</script>
';

// Include footer
include 'footer.php';
?>
