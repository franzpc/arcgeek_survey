<?php
// ============================================================
// ArcGeek Survey — Hostinger proxy secrets
// ============================================================
// 1. Copy this file → secrets.php
// 2. Fill in real values
// 3. Upload secrets.php to:  public_html/survey/internal/secrets.php
// 4. NEVER commit secrets.php to Git (already in .gitignore)
// ============================================================

// Shared secret between Cloud Run and this proxy.
// Must match the GCP Secret Manager secret "arcgeek-proxy-secret".
define('PROXY_SECRET', 'REPLACE_WITH_PROXY_SECRET');

// MySQL — same values as your portal config/database.php
define('DB_HOST',    'localhost');
define('DB_NAME',    'u220080920_arcgeek_survey');
define('DB_USER',    'u220080920_arcgeek_survey');
define('DB_PASS',    'REPLACE_WITH_DB_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

// Encryption key — must be identical to ENCRYPTION_KEY in config/database.php
define('ENCRYPTION_KEY', 'REPLACE_WITH_ENCRYPTION_KEY');
