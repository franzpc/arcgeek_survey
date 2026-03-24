<?php
// ============================================================
// ArcGeek Survey — Hostinger internal secrets template
// ============================================================
// INSTRUCTIONS:
//   1. Copy this file to:  hostinger/internal/secrets.php
//   2. Fill in the real values below.
//   3. Upload ONLY secrets.php to your Hostinger server at:
//      <domain>/internal/secrets.php
//   4. NEVER commit secrets.php to Git — it is in .gitignore.
// ============================================================

// A long random string shared between Cloud Run and this proxy.
// Generate with: openssl rand -hex 32
define('PROXY_SECRET', 'REPLACE_WITH_RANDOM_32_BYTE_HEX_STRING');

// Your Hostinger MySQL credentials (same values as your portal config).
define('DB_HOST', 'localhost');
define('DB_NAME', 'REPLACE_WITH_DATABASE_NAME');    // e.g. u220080920_arcgeek_survey
define('DB_USER', 'REPLACE_WITH_DATABASE_USER');
define('DB_PASS', 'REPLACE_WITH_DATABASE_PASSWORD');
define('DB_CHARSET', 'utf8mb4');
