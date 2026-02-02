<?php
/**
 * Authentication Helper
 * Include this file at the top of any protected page
 */

// Default password hash for 'password' - used to detect first login
define('DEFAULT_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

/**
 * Start session with secure settings
 */
function initSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');

        // Set session lifetime from config
        $lifetime = defined('AUTH_SESSION_LIFETIME') ? AUTH_SESSION_LIFETIME : 86400;
        ini_set('session.gc_maxlifetime', $lifetime);
        session_set_cookie_params($lifetime);

        session_start();
    }
}

/**
 * Get the global database instance for auth operations
 * Creates the database if it doesn't exist
 * @return OFGlobalDatabase|null
 */
function getAuthDb() {
    static $db = null;
    if ($db === null) {
        $globalDbPath = dirname(__DIR__) . '/db/global.db';
        require_once __DIR__ . '/global_db.php';
        $db = new OFGlobalDatabase($globalDbPath);
        $db->initSchema();
    }
    return $db;
}

/**
 * Get the current password hash (from DB first, then config fallback)
 * @return string|null
 */
function getPasswordHash() {
    $db = getAuthDb();
    if ($db) {
        $hash = $db->getMeta('auth_password_hash');
        if ($hash) {
            return $hash;
        }
    }
    // Fallback to config
    return defined('AUTH_PASSWORD_HASH') ? AUTH_PASSWORD_HASH : null;
}

/**
 * Check if password has been changed from default
 * @return bool
 */
function isPasswordChanged() {
    $db = getAuthDb();
    if ($db) {
        $changed = $db->getMeta('auth_password_changed');
        return $changed === '1';
    }
    return false;
}

/**
 * Check if the user needs to change their password (first login)
 * @return bool
 */
function needsPasswordChange() {
    initSecureSession();
    return !empty($_SESSION['needs_password_change']);
}

/**
 * Update the password hash in the database
 * @param string $newPassword The new plaintext password
 * @return bool
 */
function updatePassword($newPassword) {
    $db = getAuthDb();
    if (!$db) {
        return false;
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->updateMeta('auth_password_hash', $hash);
    $db->updateMeta('auth_password_changed', '1');

    // Clear the session flag
    initSecureSession();
    unset($_SESSION['needs_password_change']);

    return true;
}

/**
 * Check if user is authenticated
 * @return bool
 */
function isAuthenticated() {
    initSecureSession();

    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        return false;
    }

    // Check session expiration
    $lifetime = defined('AUTH_SESSION_LIFETIME') ? AUTH_SESSION_LIFETIME : 86400;
    if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $lifetime) {
        logout();
        return false;
    }

    return true;
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth() {
    if (!isAuthenticated()) {
        $currentUrl = $_SERVER['REQUEST_URI'];
        header('Location: login.php?redirect=' . urlencode($currentUrl));
        exit;
    }
}

/**
 * Attempt to log in with username and password
 * @param string $username
 * @param string $password
 * @return bool
 */
function attemptLogin($username, $password) {
    // Config-based authentication for username
    if (!defined('AUTH_USERNAME')) {
        return false;
    }

    $passwordHash = getPasswordHash();
    if (!$passwordHash) {
        return false;
    }

    // Timing-safe comparison for username
    if (!hash_equals(AUTH_USERNAME, $username)) {
        // Still verify password to prevent timing attacks
        password_verify($password, $passwordHash);
        return false;
    }

    // Verify password
    if (!password_verify($password, $passwordHash)) {
        return false;
    }

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    // Set session variables
    $_SESSION['user_id'] = $username;
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

    // Check if this is a first login (password not changed yet)
    if (!isPasswordChanged()) {
        $_SESSION['needs_password_change'] = true;
    }

    return true;
}

/**
 * Log out the current user
 */
function logout() {
    initSecureSession();

    // Unset all session variables
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // Destroy the session
    session_destroy();
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCsrfToken() {
    initSecureSession();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
function validateCsrfToken($token) {
    initSecureSession();

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output CSRF token as hidden input field
 */
function csrfField() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

/**
 * Get current username if logged in
 * @return string|null
 */
function getCurrentUser() {
    if (isAuthenticated()) {
        return $_SESSION['user_id'] ?? null;
    }
    return null;
}

/**
 * Generate a password reset token and store it
 * @return string The reset token
 */
function generateResetToken() {
    $db = getAuthDb();
    if (!$db) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $expiry = time() + 3600; // 1 hour expiry

    $db->updateMeta('auth_reset_token', $token);
    $db->updateMeta('auth_reset_expiry', $expiry);

    return $token;
}

/**
 * Validate a password reset token
 * @param string $token
 * @return bool
 */
function validateResetToken($token) {
    $db = getAuthDb();
    if (!$db) {
        return false;
    }

    $storedToken = $db->getMeta('auth_reset_token');
    $expiry = $db->getMeta('auth_reset_expiry');

    if (!$storedToken || !$expiry) {
        return false;
    }

    if (time() > (int)$expiry) {
        // Token expired, clear it
        $db->updateMeta('auth_reset_token', '');
        $db->updateMeta('auth_reset_expiry', '');
        return false;
    }

    return hash_equals($storedToken, $token);
}

/**
 * Clear the reset token after use
 */
function clearResetToken() {
    $db = getAuthDb();
    if ($db) {
        $db->updateMeta('auth_reset_token', '');
        $db->updateMeta('auth_reset_expiry', '');
    }
}
