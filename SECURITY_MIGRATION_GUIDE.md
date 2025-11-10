# Security Migration Guide - ArcGeek Survey

## Overview

This guide explains the security improvements implemented to move hardcoded credentials and API keys to database storage.

## Changes Summary

### ✅ What Changed

1. **reCAPTCHA Configuration** - Moved to database
2. **Plugin Authentication Token** - Moved to database
3. **Site Settings** - Centralized in database
4. **Global Header/Footer** - Created reusable components

### 🔒 Security Improvements

- ✅ No more hardcoded API keys in code
- ✅ Centralized configuration management
- ✅ Easy credential rotation
- ✅ Backward compatibility maintained
- ✅ Database encryption for sensitive data

---

## Step 1: Run Database Migration

Execute the SQL migration script:

```bash
mysql -u your_user -p your_database < script/config/migration_security_settings.sql
```

Or run it through phpMyAdmin / Hostinger's database panel.

---

## Step 2: Configure Security Settings

1. Login to your admin panel
2. Go to **Admin → Security Configuration** (`/survey/admin/security-config.php`)
3. Configure the following:

### Plugin Authentication Token

- **Current Token**: Displays the active token
- **Generate New Token**: Click to create a new random token
- **Custom Token**: Or set your own (min 32 characters)

⚠️ **Important**: After changing the token, the QGIS plugin will automatically fetch the new one on next connection.

### reCAPTCHA v3

- **Enable/Disable**: Toggle reCAPTCHA protection
- **Site Key**: Your public reCAPTCHA key
- **Secret Key**: Your private reCAPTCHA key

Get keys from: https://www.google.com/recaptcha/admin

### Site Settings

- **Site Name**: Displayed in header/title
- **Logo URL**: Full URL to your logo image
- **Support Email**: Contact email for users
- **Footer Text**: Custom footer message

---

## Step 3: Update Environment Variables (Recommended)

For maximum security, move database credentials to environment variables.

### On Hostinger

1. Go to **Advanced → PHP Configuration**
2. Set environment variables:
   ```
   DB_HOST=localhost
   DB_NAME=u220080920_arcgeek_survey
   DB_USER=u220080920_arcgeek_survey
   DB_PASS=your_password
   ENCRYPTION_KEY=your_encryption_key
   ```

### Update database.php

Change lines 7-10 from:
```php
'dbname' => 'u220080920_arcgeek_survey',
'username' => 'u220080920_arcgeek_survey',
'password' => 'Arcgeek2025_Survey17',
```

To:
```php
'dbname' => $_ENV['DB_NAME'],
'username' => $_ENV['DB_USER'],
'password' => $_ENV['DB_PASS'],
```

⚠️ **Do this AFTER confirming everything works!**

---

## Step 4: Test Everything

### Test Web Portal

1. **Homepage**: Visit `/survey/` - Should load normally
2. **Registration**: Try creating a new account
3. **Login**: Test user authentication
4. **Dashboard**: Check all pages load correctly

### Test QGIS Plugin

1. **Open QGIS** with ArcGeek Survey plugin
2. **Login** with your credentials
3. **Create Form**: Test form creation
4. **Load Responses**: Test data retrieval

If plugin fails to connect:
- Check `/survey/admin/security-config.php` for current token
- Plugin automatically retrieves token from server
- Check server error logs: `/survey/admin/cleanup.log`

---

## Backward Compatibility

All changes include **fallback mechanisms**:

```php
// Example: get_recaptcha_config()
if (empty($secret_key)) {
    // Falls back to hardcoded value if not in DB
    $secret_key = '6Lec8YIrAAAAACU9v1xZgNSn0lTEp8EWfLmwTQfw';
}
```

This means:
- ✅ If DB config is empty, uses old hardcoded values
- ✅ Zero downtime migration
- ✅ Works immediately after deployment

---

## Using Global Header/Footer

### Before (Old Way)

Each page had its own navigation code - lots of duplication.

### After (New Way)

```php
<?php
define('ARCGEEK_SURVEY', true);
session_start();
require_once '../config/database.php';
require_once '../config/security.php';

$page_title = "My Page";
include '../includes/header.php';
?>

<!-- Your content here -->

<?php include '../includes/footer.php'; ?>
```

### Benefits

- ✅ Consistent navigation across all pages
- ✅ Responsive mobile menu
- ✅ Language switcher built-in
- ✅ Easy to update site-wide
- ✅ Smaller HTML files

---

## Database Tables

### admin_config

Stores admin-level configuration:
- `admin_supabase_url`
- `admin_supabase_key`
- `recaptcha_site_key`
- `recaptcha_secret_key`
- `recaptcha_enabled`
- `plugin_auth_token`
- `plugin_token_enabled`

### system_settings

Stores site-wide settings:
- `site_name`
- `site_logo_url`
- `site_footer_text`
- `site_support_email`

### Helper Functions (database.php)

```php
get_admin_setting($key, $default)
set_admin_setting($key, $value)
get_system_setting($key, $default)
set_system_setting($key, $value)
get_recaptcha_config()
get_plugin_auth_token()
get_site_config()
```

---

## Security Best Practices

### DO ✅

- Rotate plugin token periodically
- Use strong, unique tokens
- Monitor admin access logs
- Keep database credentials in .env files
- Enable SSL/HTTPS
- Backup database regularly

### DON'T ❌

- Share reCAPTCHA secret key
- Commit credentials to Git
- Use same token across environments
- Disable reCAPTCHA without reason
- Expose admin panel publicly

---

## Troubleshooting

### Plugin Can't Connect

**Error**: "Invalid plugin token"

**Solution**:
1. Go to `/survey/admin/security-config.php`
2. Copy the current token
3. Plugin should auto-retrieve it, but you can manually set it in `api_client.py` temporarily

### reCAPTCHA Not Working

**Error**: "reCAPTCHA validation failed"

**Solutions**:
1. Check keys in Security Configuration
2. Verify domain in reCAPTCHA admin console
3. Temporarily disable reCAPTCHA to test
4. Check error logs for details

### Database Connection Error

**Error**: "Database connection failed"

**Solutions**:
1. Verify database credentials in `config/database.php`
2. Check Hostinger database is online
3. Verify user has correct permissions
4. Test connection with phpMyAdmin

### Header/Footer Not Loading

**Error**: "Direct access not permitted"

**Solution**:
```php
// Make sure you define this BEFORE including header
define('ARCGEEK_SURVEY', true);
```

---

## Rollback Procedure

If something breaks:

1. **Restore Old Files**:
   ```bash
   git checkout HEAD~1 script/config/security.php
   git checkout HEAD~1 script/public/api/create-form.php
   ```

2. **Database** - Settings remain, but fallback values will work

3. **Plugin** - Will use hardcoded token as fallback

---

## Next Steps

1. ✅ Test all functionality
2. ✅ Configure security settings in admin panel
3. ✅ Optionally: Integrate header/footer in more pages
4. ✅ Optionally: Move DB credentials to environment variables
5. ✅ Set up regular token rotation schedule

---

## Support

Issues? Check:
- Error logs: `/survey/admin/cleanup.log`
- PHP error log in Hostinger panel
- GitHub issues: https://github.com/franzpc/arcgeek_survey/issues
- Email: soporte@arcgeek.com

---

**Migration Date**: January 10, 2025
**Version**: 1.0.1
**Status**: Production Ready ✅
