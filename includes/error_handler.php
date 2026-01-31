<?php
/**
 * Centralized Error Handler
 * Provides logging and graceful error handling throughout the application
 */

class ErrorHandler {
    private static $logFile;
    private static $initialized = false;

    /**
     * Initialize the error handler
     * @param string|null $logPath Optional custom log path
     */
    public static function init($logPath = null) {
        if (self::$initialized) return;

        self::$logFile = $logPath ?? __DIR__ . '/../logs/app.log';
        $logDir = dirname(self::$logFile);

        // Create logs directory if it doesn't exist
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Set custom handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);

        self::$initialized = true;
    }

    /**
     * Handle PHP errors
     */
    public static function handleError($errno, $errstr, $errfile, $errline) {
        $errorType = self::getErrorType($errno);
        self::log("$errorType: $errstr in $errfile:$errline", 'ERROR');

        // Don't execute PHP internal error handler for notices/warnings
        if ($errno === E_NOTICE || $errno === E_WARNING || $errno === E_DEPRECATED) {
            return true;
        }

        return false; // Let PHP handle fatal errors
    }

    /**
     * Handle uncaught exceptions
     */
    public static function handleException($e) {
        self::log("Uncaught Exception: " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString(), 'CRITICAL');

        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            http_response_code(500);

            // Check if this is an API request (JSON expected)
            if (strpos($_SERVER['REQUEST_URI'] ?? '', 'scan.php') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
            } else {
                // HTML error page for browser requests
                echo '<!DOCTYPE html><html><head><title>Error</title></head><body style="background:#0b0e11;color:#e4e7eb;font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;"><div style="text-align:center;"><h1>Something went wrong</h1><p>An error occurred. Please try again later.</p><a href="index.php" style="color:#00aff0;">Return to Library</a></div></body></html>';
            }
        }
        exit(1);
    }

    /**
     * Log a message to the log file
     * @param string $message The message to log
     * @param string $level Log level (INFO, WARNING, ERROR, CRITICAL)
     */
    public static function log($message, $level = 'INFO') {
        if (!self::$logFile) {
            self::$logFile = __DIR__ . '/../logs/app.log';
        }

        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] [$level] $message\n";

        // Ensure directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        @error_log($line, 3, self::$logFile);
    }

    /**
     * Get human-readable error type
     */
    private static function getErrorType($errno) {
        $types = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];
        return $types[$errno] ?? "UNKNOWN_ERROR($errno)";
    }

    /**
     * Display a user-friendly error page and exit
     * @param string $title Error title
     * @param string $message Error message
     * @param int $httpCode HTTP status code
     */
    public static function showError($title, $message, $httpCode = 500) {
        if (!headers_sent()) {
            http_response_code($httpCode);
        }
        echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($title) . '</title></head>';
        echo '<body style="background:#0b0e11;color:#e4e7eb;font-family:sans-serif;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;">';
        echo '<div style="text-align:center;"><h1>' . htmlspecialchars($title) . '</h1>';
        echo '<p>' . htmlspecialchars($message) . '</p>';
        echo '<a href="index.php" style="color:#00aff0;">Return to Library</a></div></body></html>';
        exit;
    }
}
