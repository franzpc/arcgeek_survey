<?php
if (!defined('ARCGEEK_SURVEY')) {
    define('ARCGEEK_SURVEY', true);
}

// Plan limits — kept in sync with Hostinger portal config.
// Cloud Run only needs these to validate plugin requests if needed.
define('PLAN_FREE_FORMS',       3);
define('PLAN_FREE_RESPONSES',   100);
define('PLAN_PRO_FORMS',        50);
define('PLAN_PRO_RESPONSES',    10000);
