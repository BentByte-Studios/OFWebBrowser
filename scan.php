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
        
        // Fetch Metadata
        $row = $sourceDb->queryOne("SELECT userdetail, bio, description, about, text FROM profiles LIMIT 1");
        if (!$row) $row = $sourceDb->queryOne("SELECT bio, description, about, text FROM users LIMIT 1"); // Fallback
        
        $bio = $row['userdetail'] ?? $row['bio'] ?? $row['description'] ?? $row['about'] ?? $row['text'] ?? "";
        
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
        
        // 2. Process Posts
        // ----------------
        // Fetch all posts from source
        // Check if post_id column exists
        $hasPostIdCol = false;
        $cols = $sourceDb->query("PRAGMA table_info(posts)");
        foreach ($cols as $c) { if ($c['name'] === 'post_id') $hasPostIdCol = true; }
        
        $postsValues = [];
        $select = "id, text, price, paid, archived, created_at, text" . ($hasPostIdCol ? ", post_id" : "");
        $sourcePosts = $sourceDb->query("SELECT $select FROM posts");
        
        $globalDb->getPdo()->beginTransaction();
        
        // Clear old posts/media for this creator to ensure clean sync? 
        // Or UPSERT? UPSERT is safer for partial scans but might be slow.
        // Let's Delete all for this creator and re-insert. It's cleaner for a "Rescan".
        $globalDb->execute("DELETE FROM medias WHERE creator_id=?", [$creatorId]);
        $globalDb->execute("DELETE FROM posts WHERE creator_id=?", [$creatorId]);
        
        // Prepare Statements
        $insertPost = $globalDb->getPdo()->prepare("INSERT INTO posts (post_id, creator_id, text, price, paid, archived, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertMedia = $globalDb->getPdo()->prepare("INSERT INTO medias (media_id, post_id, creator_id, filename, directory, size, type, downloaded, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $postMap = []; // Internal ID -> Real Post ID
        
        foreach ($sourcePosts as $p) {
            // Determine Real Post ID
            // If post_id col exists, use it. Else use internal id? No, internal ID is not safe globally.
            // If post_id missing, we can make a fake one using hash of date+text?
            // Most OF-DL versions have post_id.
            $realPostId = $hasPostIdCol ? $p['post_id'] : $p['id']; 
            
            // Clean Text
            $txt = $p['text'] ?? '';
            
            $insertPost->execute([
                $realPostId,
                $creatorId,
                $txt,
                $p['price'] ?? 0,
                $p['paid'] ?? 0,
                $p['archived'] ?? 0,
                $p['created_at']
            ]);
            
            // Map internal ID to Real ID for media linking
            $postMap[$p['id']] = $realPostId;
            if ($hasPostIdCol) $postMap[$p['post_id']] = $realPostId;
        }
        
        // 3. Process Media
        // ----------------
        $sourceMedia = $sourceDb->query("SELECT * FROM medias");
        foreach ($sourceMedia as $m) {
            $postId = 0;
            if (isset($postMap[$m['post_id']])) $postId = $postMap[$m['post_id']];
            
            // Determine Type
            $ext = strtolower(pathinfo($m['filename'], PATHINFO_EXTENSION));
            $type = in_array($ext, ['mp4','mov','wmv','avi','webm']) ? 'video' : 'photo';
            
            $insertMedia->execute([
                $m['media_id'] ?? $m['id'], // Fallback
                $postId,
                $creatorId,
                $m['filename'],
                $m['directory'],
                $m['size'],
                $type,
                $m['downloaded'],
                $m['created_at']
            ]);
        }
        
        $globalDb->getPdo()->commit();
        
        echo json_encode(['status' => 'ok', 'creator' => $folderName, 'posts_count' => count($sourcePosts), 'media_count' => count($sourceMedia)]);
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
