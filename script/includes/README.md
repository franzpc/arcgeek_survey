# Global Header & Footer Components

## Usage

These components provide a consistent header and footer across all pages of the ArcGeek Survey platform.

### Basic Usage

```php
<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

// Optional: Set page-specific variables
$page_title = "My Page Title";
$navbar_class = "bg-primary"; // or "bg-danger", "bg-success", etc.
$container_class = "container-fluid"; // or "container"
$no_margin = false; // Set to true to remove top/bottom margins

// Include header
include '../includes/header.php';
?>

<!-- Your page content here -->
<h1>Page Content</h1>

<?php
// Optional: Additional footer scripts
$additional_footer_scripts = '<script>console.log("Custom script");</script>';

// Include footer
include '../includes/footer.php';
?>
```

### Available Variables

**Before header:**
- `$page_title` - Page title (will be appended with site name)
- `$navbar_class` - Bootstrap navbar color class (default: "bg-primary")
- `$container_class` - Container class (default: "container")
- `$no_margin` - Remove top/bottom margins (default: false)
- `$additional_head_content` - Custom HTML for `<head>` section

**Before footer:**
- `$additional_footer_scripts` - Custom JavaScript before closing `</body>`

### Features

#### Header
- Responsive navigation
- Language switcher (EN/ES)
- User authentication status
- Admin menu (for admin users)
- Site logo support
- Mobile-friendly hamburger menu

#### Footer
- Site information
- Quick links
- Contact information
- Social media links
- Customizable footer text from database

### Configuration

All site-wide settings are managed through the database:

- **Site Name**: `system_settings.site_name`
- **Logo URL**: `system_settings.site_logo_url`
- **Footer Text**: `system_settings.site_footer_text`
- **Support Email**: `system_settings.site_support_email`

Configure these in the Admin Panel → Security Configuration page.

### Example Pages

See `example-page.php` for a complete implementation example.
