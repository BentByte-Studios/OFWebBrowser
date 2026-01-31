<?php
// browser/scan.php
// API endpoint for aggregating SQLite DBs into Global DB

header('Content-Type: application/json');
set_time_limit(300); // 5 mins per request should be plenty for one creator

require_once 'config.php';
require_once 'includes/db.php';
require_once 'includes/global_db.php';
require_once 'includes/functions.php';
require_once 'includes/error_handler.php';

// Initialize error handler for logging
ErrorHandler::init();

$action = $_GET['action'] ?? '';
$globalDbPath = __DIR__ . '/db/global.db';
$globalDb = new OFGlobalDatabase($globalDbPath);

try {
    if ($action === 'status') {
        $lastScan = $globalDb->getMeta('last_scan_success');
        echo json_encode([
            'status' => 'ok', 
            'last_scan' => $lastScan ? (int)$lastScan : 0,
            'interval' => 3600 // 1 Hour Default
        ]);
        exit;
    }

    if ($action === 'complete') {
        // Release scan lock and update last scan time
        $globalDb->releaseScanLock();
        $globalDb->updateMeta('last_scan_success', time());
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'init') {
        // Initialize Schema
        $globalDb->initSchema();

        // Try to acquire scan lock to prevent concurrent scans
        if (!$globalDb->acquireScanLock()) {
            echo json_encode(['status' => 'error', 'message' => 'A scan is already in progress. Please wait.']);
            exit;
        }

        // Scan for folders
        $dirs = array_filter(glob(OF_DOWNLOAD_PATH . '/*'), 'is_dir');
        $creators = [];

        foreach ($dirs as $dirPath) {
            $folderName = basename($dirPath);
            // Check if user_data.db exists
            if (file_exists($dirPath . '/Metadata/user_data.db') || file_exists($dirPath . '/user_data.db')) {
                $creators[] = $folderName;
            }
        }

        echo json_encode(['status' => 'ok', 'creators' => $creators]);
        exit;
    }
    
    if ($action === 'process') {
        // Sanitize folder name - strip any path components and validate format
        $folderName = basename($_GET['folder'] ?? '');
        if (empty($folderName)) throw new Exception("No folder specified");

        // Validate folder name contains only safe characters (alphanumeric, underscore, hyphen, dot, space)
        if (!preg_match('/^[a-zA-Z0-9_\-\. ]+$/', $folderName)) {
            throw new Exception("Invalid folder name");
        }

        $profilePath = OF_DOWNLOAD_PATH . '/' . $folderName;

        // Additional security: verify resolved path is within base directory
        $realProfilePath = realpath($profilePath);
        $realBasePath = realpath(OF_DOWNLOAD_PATH);
        if (!$realProfilePath || !$realBasePath || strpos($realProfilePath, $realBasePath) !== 0) {
            throw new Exception("Invalid folder path");
        }

        $dbPath = $profilePath . '/Metadata/user_data.db';
        if (!file_exists($dbPath)) $dbPath = $profilePath . '/user_data.db';

        if (!file_exists($dbPath)) throw new Exception("Database not found");

        // Skip if modified recently (Active Download)
        if (time() - filemtime($dbPath) < 300) {
            echo json_encode(['status' => 'skipped', 'message' => 'Active download (modified < 5m ago)', 'creator' => $folderName]);
            exit;
        }
        
        // Connect to Source DB
        $sourceDb = new OFDatabase($dbPath);
        
        // 1. Process Profile
        // ------------------
        // Try to get existing ID or create new
        // We look up by Folder Name (username fallback)
        $existing = $globalDb->queryOne("SELECT id FROM creators WHERE username = ?", [$folderName]);
        $creatorId = $existing ? $existing['id'] : null;
        
        // Fetch Metadata - try different column names for compatibility
        $bio = "";

        // Check profiles table for bio-like columns
        $profilesTable = $sourceDb->queryOne("SELECT name FROM sqlite_master WHERE type='table' AND name='profiles'");
        if ($profilesTable) {
            // Get column names from profiles table
            $columns = $sourceDb->query("PRAGMA table_info(profiles)");
            $colNames = array_column($columns, 'name');

            // Try bio columns in order of preference
            $bioColumns = ['bio', 'description', 'about', 'text'];
            foreach ($bioColumns as $col) {
                if (in_array($col, $colNames)) {
                    $row = $sourceDb->queryOne("SELECT $col FROM profiles LIMIT 1");
                    if ($row && !empty($row[$col])) {
                        $bio = $row[$col];
                        break;
                    }
                }
            }
        }

        // Fall back to users table if no bio found
        if (empty($bio)) {
            $usersTable = $sourceDb->queryOne("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
            if ($usersTable) {
                $row = $sourceDb->queryOne("SELECT bio FROM users LIMIT 1");
                if ($row && !empty($row['bio'])) {
                    $bio = $row['bio'];
                }
            }
        }
        
        // Resolve Images
        $avatar = findProfileImage($profilePath, 'avatar');
        $header = findProfileImage($profilePath, 'header');
        // Make paths relative to OF_DOWNLOAD_PATH for portability, or store full?
        // Let's store Full Path for now as logic expects it, or relative to browser root?
        // The viewer expects paths that can be served. 'view.php' takes absolute path.
        // So absolute path is fine.
        
        if ($creatorId) {
            $globalDb->execute(
                "UPDATE creators SET bio=?, avatar_path=?, header_path=?, scanned_at=CURRENT_TIMESTAMP WHERE id=?", 
                [$bio, $avatar, $header, $creatorId]
            );
        } else {
            $globalDb->execute(
                "INSERT INTO creators (username, folder_path, avatar_path, header_path, bio, scanned_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
                [$folderName, $profilePath, $avatar, $header, $bio]
            );
            $creatorId = $globalDb->getPdo()->lastInsertId();
        }
        
        // 2. Process Posts (and messages, stories, others)
        // ------------------------------------------------
        // SQLite performance tuning
        $globalDb->execute("PRAGMA journal_mode = WAL");
        $globalDb->execute("PRAGMA synchronous = NORMAL");
        $globalDb->execute("PRAGMA cache_size = 10000");

        $globalDb->getPdo()->beginTransaction();

        // Clear old posts/media for this creator to ensure clean sync
        $globalDb->execute("DELETE FROM medias WHERE creator_id=?", [$creatorId]);
        $globalDb->execute("DELETE FROM posts WHERE creator_id=?", [$creatorId]);

        // Prepare Statements
        $insertPost = $globalDb->getPdo()->prepare("INSERT OR IGNORE INTO posts (post_id, creator_id, text, price, paid, archived, created_at, source_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insertMedia = $globalDb->getPdo()->prepare("INSERT OR IGNORE INTO medias (media_id, post_id, creator_id, filename, directory, size, type, downloaded, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $postMap = []; // post_id -> post_id (for media linking)
        $totalPosts = 0;

        // Tables that contain post-like content
        $postTables = ['posts', 'messages', 'stories', 'others'];

        foreach ($postTables as $tableName) {
            // Check if table exists
            $tableCheck = $sourceDb->queryOne("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$tableName]);
            if (!$tableCheck) continue;

            // Fetch posts from this table
            $sourcePosts = $sourceDb->query("SELECT id, post_id, text, price, paid, archived, created_at FROM $tableName");

            foreach ($sourcePosts as $p) {
                $realPostId = $p['post_id'] ?? $p['id'];

                $insertPost->execute([
                    $realPostId,
                    $creatorId,
                    $p['text'] ?? '',
                    $p['price'] ?? 0,
                    $p['paid'] ? 1 : 0,
                    $p['archived'] ? 1 : 0,
                    $p['created_at'],
                    $tableName
                ]);

                // Map for media linking
                $postMap[$realPostId] = $realPostId;
                $totalPosts++;
            }
        }
        
        // 3. Process Media
        // ----------------
        $sourceMedia = $sourceDb->query("SELECT * FROM medias");
        $totalMedia = 0;

        foreach ($sourceMedia as $m) {
            $postId = $m['post_id'] ?? 0;

            // Determine Type - use media_type if available, otherwise guess from extension
            $type = 'photo';
            if (!empty($m['media_type'])) {
                // media_type values: "Videos", "Images", "Audios", etc.
                $mediaType = strtolower($m['media_type']);
                if (strpos($mediaType, 'video') !== false) {
                    $type = 'video';
                } elseif (strpos($mediaType, 'audio') !== false) {
                    $type = 'audio';
                }
            } else {
                // Fallback to extension-based detection
                $ext = strtolower(pathinfo($m['filename'] ?? '', PATHINFO_EXTENSION));
                if (in_array($ext, ['mp4', 'mov', 'wmv', 'avi', 'webm', 'm4v', 'mkv'])) {
                    $type = 'video';
                } elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac'])) {
                    $type = 'audio';
                }
            }

            // Skip if no filename
            if (empty($m['filename'])) continue;

            $insertMedia->execute([
                $m['media_id'] ?? $m['id'],
                $postId,
                $creatorId,
                $m['filename'],
                $m['directory'] ?? '',
                $m['size'] ?? 0,
                $type,
                $m['downloaded'] ?? 0,
                $m['created_at']
            ]);
            $totalMedia++;
        }

        $globalDb->getPdo()->commit();

        echo json_encode(['status' => 'ok', 'creator' => $folderName, 'posts_count' => $totalPosts, 'media_count' => $totalMedia]);
    }

} catch (Exception $e) {
    // Rollback any active transaction
    if (isset($globalDb) && $globalDb->getPdo() && $globalDb->getPdo()->inTransaction()) {
        $globalDb->getPdo()->rollBack();
    }

    // Log the error
    ErrorHandler::log("Scan error: " . $e->getMessage(), 'ERROR');

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
