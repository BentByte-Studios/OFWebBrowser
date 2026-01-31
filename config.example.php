<?php
/**
 * OF Web Browser Configuration
 *
 * Copy this file to config.php and update the settings below.
 */

// Path to your OnlyFans downloads folder
// This should contain creator folders with user_data.db files
define('OF_DOWNLOAD_PATH', 'C:/path/to/your/OnlyFans/downloads');

// Site title (shown in browser tab)
define('SITE_TITLE', 'OF Web Browser');

// Number of items per page
define('ITEMS_PER_PAGE', 50);

// Auto-scan interval in seconds (3600 = 1 hour)
define('SCAN_INTERVAL', 3600);

// Timezone
date_default_timezone_set('America/Chicago');
