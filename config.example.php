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

// Authentication Settings
// On first login with default password, you'll be prompted to set a new password.
// Password is stored securely in the database after first change.
// To reset a forgotten password, run: php reset-password.php
define('AUTH_USERNAME', 'admin');
define('AUTH_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); // Default: 'password'
define('AUTH_SESSION_LIFETIME', 86400); // Session lifetime in seconds (24 hours)

// Messages Tab Settings
// View mode: 'posts' shows full message posts (like Posts tab), 'media' shows media grid (like Media tab)
define('MESSAGES_VIEW_MODE', 'posts');
// Show messages from the creator (true/false)
define('MESSAGES_SHOW_CREATOR', true);
// Show messages from the subscriber/user (true/false)
define('MESSAGES_SHOW_USER', true);
