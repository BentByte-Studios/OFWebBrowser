<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'includes/global_db.php';
require_once 'includes/functions.php';
require_once 'includes/error_handler.php';

// Initialize error handler
ErrorHandler::init();

// Require authentication
requireAuth();

// Initialize Global DB
$globalDbPath = __DIR__ . '/db/global.db';
$globalDb = new OFGlobalDatabase($globalDbPath);

$creatorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;

if (!$creatorId) {
    header('Location: index.php');
    exit;
}

// Fetch Creator Info
$creator = $globalDb->queryOne("SELECT * FROM creators WHERE id = ?", [$creatorId]);
if (!$creator) {
    ErrorHandler::showError('Creator Not Found', 'The requested creator could not be found.', 404);
}

$username = $creator['username'];
$bio = $creator['bio'];
$avatar = $creator['avatar_path'] ? 'view.php?path=' . urlencode($creator['avatar_path']) : null;
$header = $creator['header_path'] ? 'view.php?path=' . urlencode($creator['header_path']) : null;

// Stats - Combined into single query for performance
$stats = $globalDb->queryOne("
    SELECT
        (SELECT COUNT(*) FROM posts WHERE creator_id = ? AND source_type != 'messages') as post_count,
        (SELECT COUNT(*) FROM medias WHERE creator_id = ?) as media_count_all,
        (SELECT COUNT(*) FROM medias WHERE creator_id = ? AND type='video') as media_count_video,
        (SELECT COUNT(*) FROM medias m JOIN posts p ON m.post_id = p.post_id AND m.creator_id = p.creator_id WHERE m.creator_id = ? AND p.source_type = 'messages') as message_media_count,
        (SELECT COUNT(*) FROM medias m JOIN posts p ON m.post_id = p.post_id AND m.creator_id = p.creator_id WHERE m.creator_id = ? AND p.source_type = 'messages' AND m.type='video') as message_video_count,
        (SELECT COUNT(*) FROM posts WHERE creator_id = ? AND source_type = 'messages') as message_post_count,
        (SELECT COUNT(*) FROM posts WHERE creator_id = ? AND source_type = 'messages' AND from_user = 0) as message_creator_count,
        (SELECT COUNT(*) FROM posts WHERE creator_id = ? AND source_type = 'messages' AND from_user = 1) as message_user_count
", [$creatorId, $creatorId, $creatorId, $creatorId, $creatorId, $creatorId, $creatorId, $creatorId]);

$postCount = $stats['post_count'] ?? 0;
$mediaCountAll = $stats['media_count_all'] ?? 0;
$mediaCountVideo = $stats['media_count_video'] ?? 0;
$mediaCountPhoto = $mediaCountAll - $mediaCountVideo;
$messageMediaCount = $stats['message_media_count'] ?? 0;
$messageVideoCount = $stats['message_video_count'] ?? 0;
$messagePhotoCount = $messageMediaCount - $messageVideoCount;
$messagePostCount = $stats['message_post_count'] ?? 0;
$messageCreatorCount = $stats['message_creator_count'] ?? 0;
$messageUserCount = $stats['message_user_count'] ?? 0;

// Messages config
$messagesViewMode = defined('MESSAGES_VIEW_MODE') ? MESSAGES_VIEW_MODE : 'posts';
$messagesShowCreator = defined('MESSAGES_SHOW_CREATOR') ? MESSAGES_SHOW_CREATOR : true;
$messagesShowUser = defined('MESSAGES_SHOW_USER') ? MESSAGES_SHOW_USER : true;

// Validate input parameters (whitelist approach)
$activeTab = in_array($_GET['tab'] ?? '', ['posts', 'media', 'messages']) ? $_GET['tab'] : 'posts';
$filter = in_array($_GET['filter'] ?? '', ['all', 'photo', 'video', 'creator', 'user']) ? $_GET['filter'] : 'all';

$posts = [];
$mediaGrid = [];
$lightboxMedia = [];
$totalPages = 1;

if ($activeTab === 'posts') {
    $totalPages = ceil($postCount / $perPage);
    $offset = ($page - 1) * $perPage;

    // Fetch Posts (excluding messages)
    $posts = $globalDb->query("SELECT * FROM posts WHERE creator_id = ? AND source_type != 'messages' ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", [$creatorId]);
    
    // Fetch Media for these posts
    if (!empty($posts)) {
        $postIds = array_column($posts, 'post_id');
        if (!empty($postIds)) {
            // Use parameterized query to prevent SQL injection
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $params = array_merge($postIds, [$creatorId]);
            $allMedia = $globalDb->query(
                "SELECT * FROM medias WHERE post_id IN ($placeholders) AND creator_id = ? ORDER BY id ASC",
                $params
            );
            
            $mediaMap = [];
            foreach ($allMedia as $m) $mediaMap[$m['post_id']][] = $m;
            
            foreach ($posts as &$p) {
                $realId = $p['post_id'];
                $p['media'] = $mediaMap[$realId] ?? [];
            }
        }
    }
} elseif ($activeTab === 'media') {
    $typeClause = "";
    $count = $mediaCountAll;

    if ($filter === 'video') { $typeClause = "AND type='video'"; $count = $mediaCountVideo; }
    if ($filter === 'photo') { $typeClause = "AND type='photo'"; $count = $mediaCountPhoto; }

    $totalPages = max(1, ceil($count / 40));
    $offset = ($page - 1) * 40;

    $mediaGrid = $globalDb->query("SELECT * FROM medias WHERE creator_id = ? $typeClause ORDER BY id DESC LIMIT 40 OFFSET $offset", [$creatorId]);
} elseif ($activeTab === 'messages') {
    $messagePosts = []; // For posts view mode

    if ($messagesViewMode === 'posts') {
        // Posts view mode - show full messages like Posts tab
        $senderClause = "";
        $count = $messagePostCount;

        // Filter by sender
        if ($filter === 'creator') {
            $senderClause = "AND from_user = 0";
            $count = $messageCreatorCount;
        } elseif ($filter === 'user') {
            $senderClause = "AND from_user = 1";
            $count = $messageUserCount;
        }

        // Apply config filters
        if (!$messagesShowCreator && !$messagesShowUser) {
            $count = 0;
        } elseif (!$messagesShowCreator) {
            $senderClause = "AND from_user = 1";
            if ($filter === 'all') $count = $messageUserCount;
        } elseif (!$messagesShowUser) {
            $senderClause = "AND from_user = 0";
            if ($filter === 'all') $count = $messageCreatorCount;
        }

        $totalPages = max(1, ceil($count / $perPage));
        $offset = ($page - 1) * $perPage;

        // Fetch message posts
        $messagePosts = $globalDb->query("SELECT * FROM posts WHERE creator_id = ? AND source_type = 'messages' $senderClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset", [$creatorId]);

        // Fetch media for these posts
        if (!empty($messagePosts)) {
            $postIds = array_column($messagePosts, 'post_id');
            if (!empty($postIds)) {
                $placeholders = implode(',', array_fill(0, count($postIds), '?'));
                $params = array_merge($postIds, [$creatorId]);
                $allMedia = $globalDb->query(
                    "SELECT * FROM medias WHERE post_id IN ($placeholders) AND creator_id = ? ORDER BY id ASC",
                    $params
                );

                $mediaMap = [];
                foreach ($allMedia as $m) $mediaMap[$m['post_id']][] = $m;

                foreach ($messagePosts as &$p) {
                    $realId = $p['post_id'];
                    $p['media'] = $mediaMap[$realId] ?? [];
                }
            }
        }
    } else {
        // Media view mode - show media grid (original behavior)
        $typeClause = "";
        $count = $messageMediaCount;

        if ($filter === 'video') {
            $typeClause = "AND m.type='video'";
            $count = $messageVideoCount; // Use cached count
        }
        if ($filter === 'photo') {
            $typeClause = "AND m.type='photo'";
            $count = $messagePhotoCount; // Use cached count
        }

        $totalPages = max(1, ceil($count / 40));
        $offset = ($page - 1) * 40;

        $mediaGrid = $globalDb->query("SELECT m.* FROM medias m JOIN posts p ON m.post_id = p.post_id AND m.creator_id = p.creator_id WHERE m.creator_id = ? AND p.source_type = 'messages' $typeClause ORDER BY m.id DESC LIMIT 40 OFFSET $offset", [$creatorId]);
    }
}

// Build lightbox media array with resolved paths
$basePath = $creator['folder_path'];
$lightboxMedia = [];

if ($activeTab === 'posts') {
    foreach ($posts as &$p) {
        foreach ($p['media'] as $m) {
            $fullPath = resolveGlobalPath($basePath, $m['directory'], $m['filename']);
            $lightboxMedia[] = [
                'src' => 'view.php?path=' . urlencode($fullPath),
                'type' => $m['type'],
                'id' => $m['id'],
                'post_id' => $p['post_id']
            ];
        }
    }
} elseif ($activeTab === 'messages' && $messagesViewMode === 'posts' && !empty($messagePosts)) {
    // Messages in posts view mode
    foreach ($messagePosts as &$p) {
        foreach ($p['media'] as $m) {
            $fullPath = resolveGlobalPath($basePath, $m['directory'], $m['filename']);
            $lightboxMedia[] = [
                'src' => 'view.php?path=' . urlencode($fullPath),
                'type' => $m['type'],
                'id' => $m['id'],
                'post_id' => $p['post_id']
            ];
        }
    }
} elseif ($activeTab === 'media' || ($activeTab === 'messages' && $messagesViewMode === 'media')) {
    foreach ($mediaGrid as $m) {
        $fullPath = resolveGlobalPath($basePath, $m['directory'], $m['filename']);
        $lightboxMedia[] = [
            'src' => 'view.php?path=' . urlencode($fullPath),
            'type' => $m['type'],
            'id' => $m['id'],
            'post_id' => $m['post_id']
        ];
    }
}

// Build index map for O(1) lightbox lookups (was O(n^2) nested loop)
$lightboxIndex = [];
foreach ($lightboxMedia as $idx => $lm) {
    $lightboxIndex[$lm['id']] = $idx;
}

function resolveGlobalPath($base, $dir, $file) {
    // Normalize slashes in components first
    $base = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base);
    $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
    
    // Check if directory is already absolute (Windows drive or Unix root)
    if (preg_match('/^([a-zA-Z]:|[\\/])/', $dir)) {
        return $dir . DIRECTORY_SEPARATOR . $file;
    }
    
    // Check if base is already included in dir (common if OF-DL changed behavior)
    if (strpos($dir, $base) === 0) {
        return $dir . DIRECTORY_SEPARATOR . $file;
    }

    return $base . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($username) ?> - <?= SITE_TITLE ?></title>
    <style>
        :root {
            /* OF Dark Theme Colors */
            --bg-body: #0b0e11; 
            --bg-card: #191f27;
            --text-primary: #e4e7eb;
            --text-secondary: #8a96a3;
            --accent: #00aff0;
            --accent-hover: #0091c9;
            --border-color: #242c37;
        }
        body {
            background-color: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Segoe UI', Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            overflow-y: scroll;
        }
        a { text-decoration: none; color: inherit; }
        
        /* Layout */
        .main-wrapper {
            max-width: 600px;
            margin: 0 auto;
            border-left: 1px solid var(--border-color);
            border-right: 1px solid var(--border-color);
            min-height: 100vh;
            background: var(--bg-body);
        }

        /* Header Area */
        .profile-header { position: relative; padding-bottom: 0; border-bottom: 1px solid var(--border-color); }
        .banner { height: 180px; background-color: #334155; background-size: cover; background-position: center; }
        .header-content { padding: 0 15px; position: relative; }
        .profile-avatar { width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--bg-body); background: #475569; object-fit: cover; margin-top: -35px; position: relative; z-index: 2; }
        .profile-avatar-placeholder { width: 100px; height: 100px; border-radius: 50%; border: 3px solid var(--bg-body); background: #475569; margin-top: -35px; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #fff; }
        .user-info { margin-top: 10px; padding-bottom: 15px; }
        .user-name { font-size: 1.3rem; font-weight: 700; display: flex; align-items: center; gap: 5px; }
        .verified-icon { width: 18px; height: 18px; fill: var(--text-primary); }
        .user-handle { color: var(--text-secondary); font-size: 0.95rem; margin-top: 2px; }
        .user-bio { margin-top: 10px; font-size: 0.95rem; line-height: 1.4; white-space: pre-wrap; color: var(--text-primary); }
        
        /* Nav Tabs */
        .nav-tabs { display: flex; border-bottom: none; margin-top: 0; }
        .nav-item { flex: 1; text-align: center; padding: 15px 0; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; font-size: 0.9rem; cursor: pointer; border-bottom: 2px solid transparent; transition: all 0.2s; }
        .nav-item.active { color: var(--text-primary); border-bottom-color: var(--text-primary); }
        .nav-item:hover { background: rgba(255,255,255,0.03); color: var(--text-primary); }

        /* Filter Pills */
        .filter-bar {
            padding: 15px;
            display: flex; gap: 10px;
            overflow-x: auto;
            border-bottom: 1px solid var(--border-color);
        }
        .pill {
            padding: 6px 16px;
            background: #242c37;
            border-radius: 20px;
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 600;
            white-space: nowrap;
        }
        .pill.active { background: var(--accent); color: white; }

        /* Posts */
        .post-card { padding: 15px 0; border-bottom: 1px solid var(--border-color); }
        .post-header { display: flex; padding: 0 15px 10px; gap: 10px; }
        .post-avatar { width: 48px; height: 48px; border-radius: 50%; object-fit: cover; background: #333; }
        .post-meta-info { display: flex; flex-direction: column; justify-content: center; }
        .post-user-name { font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 4px; }
        .post-date { color: var(--text-secondary); font-size: 0.85rem; }
        .post-content { padding: 0 15px 10px; font-size: 1rem; line-height: 1.5; word-wrap: break-word; }
        
        /* Media Grid Inner */
        .media-container { margin-top: 5px; }
        .zero-media { width: 100%; max-height: 600px; object-fit: fill; background: #000; display: block; }
        .one-media { width: 100%; max-height: 600px; object-fit: contain; background: #000; display: block; }
        .grid-media { display: grid; grid-gap: 2px; grid-template-columns: repeat(2, 1fr); }
        .grid-item { position: relative; padding-bottom: 100%; background: #000; }
        .grid-item img, .grid-item video { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; }
        
        /* Full Media Grid (Tab) */
        .full-media-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2px;
        }
        .full-media-item {
            position: relative;
            padding-bottom: 100%;
            cursor: pointer;
            background: #111;
        }
        .full-media-item img, .full-media-item video {
            position: absolute; width: 100%; height: 100%; object-fit: cover;
        }
        .type-icon {
            position: absolute; top: 5px; right: 5px;
            color: white; background: rgba(0,0,0,0.5);
            padding: 2px; border-radius: 4px;
        }
        
        /* Post Footer */
        .post-footer { padding: 10px 15px 0; display: flex; gap: 20px; color: var(--text-secondary); }
        .action-icon { font-size: 1.2rem; display: flex; align-items: center; gap: 6px; cursor: pointer; transition: color 0.2s; }
        .action-icon:hover { color: var(--accent); }
        .action-svg { width: 24px; height: 24px; fill: currentColor; }
        
        /* Lightbox */
        .lightbox { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .lightbox.active { display: flex; }
        .lightbox-content { max-width: 100%; max-height: 100vh; }
        .close-btn { position: absolute; top: 20px; right: 30px; font-size: 40px; color: white; cursor: pointer; z-index: 1001; }
        .nav-btn { position: absolute; top: 50%; transform: translateY(-50%); width: 50px; height: 50px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: white; cursor: pointer; user-select: none; z-index: 1001; }
        .prev-btn { left: 10px; }
        .next-btn { right: 10px; }
        
        /* Play Overlay */
        .media-wrapper { position: relative; width: 100%; height: 100%; cursor: pointer; overflow: hidden; background: #000; }
        .media-blur {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            object-fit: cover;
            filter: blur(25px);
            transform: scale(1.1);
            opacity: 0.6;
            z-index: 0;
        }
        .one-media {
            position: relative; z-index: 1;
            width: 100%; max-height: 600px;
            object-fit: contain;
            background: transparent;
            display: block;
        }
        .video-thumb {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 200px;
        }
        .full-media-item .video-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
            min-height: 150px;
        }

        .play-overlay {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 50px; height: 50px;
            background: rgba(0,0,0,0.6);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            pointer-events: none;
            backdrop-filter: blur(2px);
            z-index: 2;
        }
        .play-overlay svg { fill: #fff; width: 30px; height: 30px; margin-left: 2px; } /* Optical center */

        /* Carousel */
        .post-media-carousel { position: relative; width: 100%; background: #000; overflow: hidden; }
        .carousel-item { display: none; width: 100%; transition: opacity 0.2s; }
        .carousel-item.active { display: block; }
        .carousel-btn {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(0,0,0,0.6); color: #fff;
            border: none; border-radius: 50%;
            width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; z-index: 5;
            transition: background 0.2s;
        }
        .carousel-btn:hover { background: rgba(0,0,0,0.8); }
        .carousel-prev { left: 10px; }
        .carousel-next { right: 10px; }

        /* Rich Header Styles */
        .back-nav { 
            padding: 10px 15px; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            background: var(--bg-body); 
            position: sticky; top: 0; z-index: 50; 
        }
        .nav-left { display: flex; align-items: center; gap: 15px; }
        .nav-title-group { display: flex; flex-direction: column; }
        .nav-name { font-weight: 700; font-size: 1.1rem; display: flex; align-items: center; gap: 5px; color: var(--text-primary); }
        .nav-subtitle { font-size: 0.85rem; color: var(--text-secondary); font-weight: 400; }
        .nav-actions { display: flex; align-items: center; gap: 20px; color: var(--text-primary); }
        .nav-icon { width: 24px; height: 24px; fill: currentColor; cursor: pointer; }
        .nav-icon:hover { color: var(--accent); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px 15px;
            border-top: 1px solid var(--border-color);
        }
        .page-btn {
            padding: 8px 14px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .page-btn:hover:not(.disabled):not(.active) {
            background: var(--accent);
            border-color: var(--accent);
        }
        .page-btn.active {
            background: var(--accent);
            border-color: var(--accent);
            font-weight: 600;
        }
        .page-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .site-footer {
            text-align: center;
            padding: 30px 20px;
            margin-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.85rem;
        }
        .site-footer a {
            color: var(--text-secondary);
            text-decoration: none;
            margin: 0 12px;
            transition: color 0.2s;
        }
        .site-footer a:hover { color: var(--accent); }
        .site-footer svg { vertical-align: middle; margin-right: 5px; }
    </style>
</head>
<body>
    <div class="main-wrapper">
        <!-- Top Nav -->
        <div class="back-nav">
             <div class="nav-left">
                 <a href="index.php" style="display:flex;align-items:center;color:inherit;text-decoration:none;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                 </a>
                 <div class="nav-title-group">
                    <div class="nav-name">
                        <?= htmlspecialchars($username) ?>
                        <svg class="verified-icon" style="width:16px;height:16px;" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                    </div>
                    <?php
                        $lastSeen = "Updated recently";
                        if (!empty($creator['scanned_at'])) {
                             $diff = time() - strtotime($creator['scanned_at']);
                             if ($diff < 60) $lastSeen = "Updated just now";
                             elseif ($diff < 3600) $lastSeen = "Updated " . floor($diff/60) . " mins ago";
                             elseif ($diff < 86400) $lastSeen = "Updated " . floor($diff/3600) . " hours ago";
                             else $lastSeen = "Updated " . floor($diff/86400) . " days ago";
                        }
                    ?>
                    <span class="nav-subtitle"><?= $lastSeen ?></span>
                 </div>
             </div>
             
             <div class="nav-actions">
                 <!-- Tip -->
                 <svg class="nav-icon" viewBox="0 0 24 24" style="border:1.5px solid currentColor;border-radius:50%;padding:2px;width:20px;height:20px;"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.48 0-2.3-1.94-3.52-4.7-4.47z"/></svg>
                 <!-- Chat -->
                 <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/></svg>
                 <!-- Star -->
                 <svg class="nav-icon" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                 <!-- Menu -->
                 <svg class="nav-icon" viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
             </div>
        </div>

        <!-- Header -->
        <div class="profile-header">
            <div class="banner" style="<?= $header ? "background-image: url('$header');" : '' ?>"></div>
            <div class="header-content">
                <div style="display:flex;justify-content:space-between;align-items:flex-end;">
                     <?php if ($avatar): ?>
                        <img src="<?= $avatar ?>" class="profile-avatar" alt="Avatar">
                    <?php else: ?>
                        <div class="profile-avatar-placeholder"><?= strtoupper(substr($username, 0, 1)) ?></div>
                    <?php endif; ?>
                    <div style="margin-top:10px;display:flex;gap:10px;">
                        <svg class="action-svg" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                        <svg class="action-svg" viewBox="0 0 24 24"><path d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
                    </div>
                </div>

                <div class="user-info">
                    <div class="user-name">
                        <?= htmlspecialchars($username) ?>
                        <svg class="verified-icon" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                    </div>
                    <div class="user-handle">@<?= strtolower(str_replace(' ', '', $username)) ?></div>
                    <?php if ($bio): ?>
                        <div class="user-bio"><?= nl2br(cleanText($bio)) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="nav-tabs">
                <a href="?id=<?= $creatorId ?>&tab=posts" class="nav-item <?= $activeTab === 'posts' ? 'active' : '' ?>">
                    <?= $postCount ?> POSTS
                </a>
                <a href="?id=<?= $creatorId ?>&tab=media" class="nav-item <?= $activeTab === 'media' ? 'active' : '' ?>">
                    <?= $mediaCountAll ?> MEDIA
                </a>
                <a href="?id=<?= $creatorId ?>&tab=messages" class="nav-item <?= $activeTab === 'messages' ? 'active' : '' ?>">
                    <?= $messagesViewMode === 'posts' ? $messagePostCount : $messageMediaCount ?> MESSAGES
                </a>
            </div>
        </div>

        <?php if ($activeTab === 'posts'): ?>
            <!-- POSTS VIEW -->
            <div class="feed">
                <?php if (empty($posts)): ?>
                    <div style="padding:40px;text-align:center;color:var(--text-secondary);">
                        No posts found.
                    </div>
                <?php endif; ?>

                <?php foreach ($posts as $post): ?>
                    <div class="post-card">
                        <div class="post-header">
                            <?php if ($avatar): ?>
                                <img src="<?= $avatar ?>" class="post-avatar" loading="lazy">
                            <?php else: ?>
                                <div class="post-avatar" style="background:#555"></div>
                            <?php endif; ?>
                            <div class="post-meta-info">
                                <div class="post-user-name">
                                    <?= htmlspecialchars($username) ?>
                                    <svg class="verified-icon" style="width:14px;height:14px;" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                </div>
                                <div class="post-date"><?= date('M j, Y', strtotime($post['created_at'])) ?></div>
                            </div>
                        </div>
                        
                        <?php if (!empty($post['text'])): ?>
                            <div class="post-content"><?= cleanText($post['text']) ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($post['media'])): ?>
                            <div class="post-media-carousel">
                                <?php
                                    $mediaCount = count($post['media']);
                                    foreach ($post['media'] as $index => $m):
                                        $mId = $m['id'];
                                        $lbIndex = $lightboxIndex[$mId] ?? -1;
                                        if ($lbIndex === -1) continue;
                                        $src = $lightboxMedia[$lbIndex]['src'];
                                        $isVid = $lightboxMedia[$lbIndex]['type'] === 'video';
                                        $isActive = ($index === 0) ? 'active' : '';
                                ?>
                                    <div class="carousel-item media-wrapper <?= $isActive ?>" onclick="openLightbox(<?= $lbIndex ?>, <?= $post['post_id'] ?>)">
                                        <?php if ($isVid): ?>
                                            <video data-src="<?= $src ?>#t=0.001" class="one-media video-thumb" muted preload="none"></video>
                                            <div class="play-overlay"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></div>
                                        <?php else: ?>
                                            <img src="<?= $src ?>" class="media-blur" loading="lazy">
                                            <img src="<?= $src ?>" class="one-media" loading="lazy">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if ($mediaCount > 1): ?>
                                    <button class="carousel-btn carousel-prev" onclick="event.stopPropagation(); moveCarousel(this, -1)">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                                    </button>
                                    <button class="carousel-btn carousel-next" onclick="event.stopPropagation(); moveCarousel(this, 1)">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        
                        <div class="post-footer">
                            <div class="action-icon"><svg class="action-svg" viewBox="0 0 24 24"><path d="M16.5 3c-1.74 0-3.41.81-4.5 2.09C10.91 3.81 9.24 3 7.5 3 4.42 3 2 5.42 2 8.5c0 3.78 3.4 6.86 8.55 11.54L12 21.35l1.45-1.32C18.6 15.36 22 12.28 22 8.5 22 5.42 19.58 3 16.5 3zm-4.4 15.55l-.1.1-.1-.1C7.14 14.24 4 11.39 4 8.5 4 6.5 5.5 5 7.5 5c1.54 0 3.04.99 3.57 2.36h1.87C13.46 5.99 14.96 5 16.5 5c2 0 3.5 1.5 3.5 3.5 0 2.89-3.14 5.74-7.9 10.05z"/></svg></div>
                            <div class="action-icon"><svg class="action-svg" viewBox="0 0 24 24"><path d="M21.99 4c0-1.1-.89-2-1.99-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h14l4 4-.01-18zM18 14H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg></div>
                            <div class="action-icon"><svg class="action-svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.15-1.54-3.32-3.32h2.08c.16.89.93 1.54 1.92 1.54 1.11 0 1.74-.68 1.74-1.34 0-.75-.55-1.18-1.85-1.5-1.63-.39-3.23-1.09-3.23-2.91 0-1.73 1.3-2.8 2.66-3.11V5h2.67v1.9c1.46.31 2.69 1.45 2.89 3h-2.1c-.2-.79-.88-1.29-1.69-1.29-.98 0-1.58.62-1.58 1.22 0 .69.53 1.15 2.07 1.53 1.73.42 3.01 1.21 3.01 2.92 0 1.68-1.26 2.82-2.6 3.12z"/></svg></div>
                            <div class="action-icon" style="margin-left:auto;"><svg class="action-svg" viewBox="0 0 24 24"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php renderPagination($page, $totalPages, $creatorId, 'posts', $filter); ?>

        <?php elseif ($activeTab === 'media'): ?>
            <!-- MEDIA VIEW -->
            <div class="filter-bar">
                <a href="?id=<?= $creatorId ?>&tab=media&filter=all" class="pill <?= $filter === 'all' ? 'active' : '' ?>">All <?= $mediaCountAll ?></a>
                <a href="?id=<?= $creatorId ?>&tab=media&filter=photo" class="pill <?= $filter === 'photo' ? 'active' : '' ?>">Photos <?= $mediaCountPhoto ?></a>
                <a href="?id=<?= $creatorId ?>&tab=media&filter=video" class="pill <?= $filter === 'video' ? 'active' : '' ?>">Videos <?= $mediaCountVideo ?></a>
            </div>

            <div class="feed">
                <?php if (empty($mediaGrid)): ?>
                    <div style="padding:40px;text-align:center;color:var(--text-secondary);">
                        No media found.
                    </div>
                <?php else: ?>
                    <div class="full-media-grid">
                        <?php foreach ($mediaGrid as $index => $m):
                             $mId = $m['id'];
                             $lbIndex = $lightboxIndex[$mId] ?? -1;
                             if ($lbIndex === -1) continue;
                             $src = $lightboxMedia[$lbIndex]['src'];
                             $isVid = $lightboxMedia[$lbIndex]['type'] === 'video';
                        ?>
                            <div class="full-media-item" onclick="openLightbox(<?= $lbIndex ?>, false)">
                                <?php if ($isVid): ?>
                                    <video data-src="<?= $src ?>#t=0.001" class="video-thumb" muted preload="none"></video>
                                    <div class="type-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg></div>
                                <?php else: ?>
                                    <img src="<?= $src ?>" loading="lazy">
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php renderPagination($page, $totalPages, $creatorId, 'media', $filter); ?>

        <?php elseif ($activeTab === 'messages'): ?>
            <!-- MESSAGES VIEW -->
            <?php if ($messagesViewMode === 'posts'): ?>
                <!-- Posts View Mode -->
                <div class="filter-bar">
                    <a href="?id=<?= $creatorId ?>&tab=messages&filter=all" class="pill <?= $filter === 'all' ? 'active' : '' ?>">All <?= $messagePostCount ?></a>
                    <?php if ($messagesShowCreator): ?>
                        <a href="?id=<?= $creatorId ?>&tab=messages&filter=creator" class="pill <?= $filter === 'creator' ? 'active' : '' ?>">Creator <?= $messageCreatorCount ?></a>
                    <?php endif; ?>
                    <?php if ($messagesShowUser): ?>
                        <a href="?id=<?= $creatorId ?>&tab=messages&filter=user" class="pill <?= $filter === 'user' ? 'active' : '' ?>">You <?= $messageUserCount ?></a>
                    <?php endif; ?>
                </div>

                <div class="feed">
                    <?php if (empty($messagePosts)): ?>
                        <div style="padding:40px;text-align:center;color:var(--text-secondary);">
                            No messages found.
                        </div>
                    <?php endif; ?>

                    <?php foreach ($messagePosts as $post): ?>
                        <?php $isFromUser = ($post['from_user'] ?? 0) == 1; ?>
                        <div class="post-card">
                            <div class="post-header">
                                <?php if ($isFromUser): ?>
                                    <div class="post-avatar" style="background:var(--accent);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:bold;">You</div>
                                <?php elseif ($avatar): ?>
                                    <img src="<?= $avatar ?>" class="post-avatar" loading="lazy">
                                <?php else: ?>
                                    <div class="post-avatar" style="background:#555"></div>
                                <?php endif; ?>
                                <div class="post-meta-info">
                                    <div class="post-user-name">
                                        <?= $isFromUser ? 'You' : htmlspecialchars($username) ?>
                                        <?php if (!$isFromUser): ?>
                                            <svg class="verified-icon" style="width:14px;height:14px;" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div class="post-date"><?= date('M j, Y', strtotime($post['created_at'])) ?></div>
                                </div>
                            </div>

                            <?php if (!empty($post['text'])): ?>
                                <div class="post-content"><?= cleanText($post['text']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($post['media'])): ?>
                                <div class="post-media-carousel">
                                    <?php
                                        $mediaCount = count($post['media']);
                                        foreach ($post['media'] as $index => $m):
                                            $mId = $m['id'];
                                            $lbIndex = $lightboxIndex[$mId] ?? -1;
                                            if ($lbIndex === -1) continue;
                                            $src = $lightboxMedia[$lbIndex]['src'];
                                            $isVid = $lightboxMedia[$lbIndex]['type'] === 'video';
                                            $isActive = ($index === 0) ? 'active' : '';
                                    ?>
                                        <div class="carousel-item media-wrapper <?= $isActive ?>" onclick="openLightbox(<?= $lbIndex ?>, <?= $post['post_id'] ?>)">
                                            <?php if ($isVid): ?>
                                                <video data-src="<?= $src ?>#t=0.001" class="one-media video-thumb" muted preload="none"></video>
                                                <div class="play-overlay"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></div>
                                            <?php else: ?>
                                                <img src="<?= $src ?>" class="media-blur" loading="lazy">
                                                <img src="<?= $src ?>" class="one-media" loading="lazy">
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($mediaCount > 1): ?>
                                        <button class="carousel-btn carousel-prev" onclick="event.stopPropagation(); moveCarousel(this, -1)">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                                        </button>
                                        <button class="carousel-btn carousel-next" onclick="event.stopPropagation(); moveCarousel(this, 1)">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php renderPagination($page, $totalPages, $creatorId, 'messages', $filter); ?>

            <?php else: ?>
                <!-- Media View Mode (original grid) -->
                <div class="filter-bar">
                    <a href="?id=<?= $creatorId ?>&tab=messages&filter=all" class="pill <?= $filter === 'all' ? 'active' : '' ?>">All <?= $messageMediaCount ?></a>
                    <a href="?id=<?= $creatorId ?>&tab=messages&filter=photo" class="pill <?= $filter === 'photo' ? 'active' : '' ?>">Photos <?= $messagePhotoCount ?></a>
                    <a href="?id=<?= $creatorId ?>&tab=messages&filter=video" class="pill <?= $filter === 'video' ? 'active' : '' ?>">Videos <?= $messageVideoCount ?></a>
                </div>

                <div class="feed">
                    <?php if (empty($mediaGrid)): ?>
                        <div style="padding:40px;text-align:center;color:var(--text-secondary);">
                            No message media found.
                        </div>
                    <?php else: ?>
                        <div class="full-media-grid">
                            <?php foreach ($mediaGrid as $index => $m):
                                 $mId = $m['id'];
                                 $lbIndex = $lightboxIndex[$mId] ?? -1;
                                 if ($lbIndex === -1) continue;
                                 $src = $lightboxMedia[$lbIndex]['src'];
                                 $isVid = $lightboxMedia[$lbIndex]['type'] === 'video';
                            ?>
                                <div class="full-media-item" onclick="openLightbox(<?= $lbIndex ?>, false)">
                                    <?php if ($isVid): ?>
                                        <video data-src="<?= $src ?>#t=0.001" class="video-thumb" muted preload="none"></video>
                                        <div class="type-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg></div>
                                    <?php else: ?>
                                        <img src="<?= $src ?>" loading="lazy">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php renderPagination($page, $totalPages, $creatorId, 'messages', $filter); ?>
            <?php endif; ?>
        <?php endif; ?>

    <!-- Lightbox (Reused Logic) -->
    <div class="lightbox" id="lightbox">
        <span class="close-btn" onclick="closeLightbox()">&times;</span>
        <div class="nav-btn prev-btn" onclick="nav(-1)">&#10094;</div>
        <div class="nav-btn next-btn" onclick="nav(1)">&#10095;</div>
        <img id="lightbox-img" class="lightbox-content" style="display:none">
        <video id="lightbox-video" class="lightbox-content" controls style="display:none"></video>
    </div>

    <script>
        // Carousel navigation function (moved from head for non-blocking)
        function moveCarousel(btn, dir) {
            const carousel = btn.closest('.post-media-carousel');
            const items = carousel.querySelectorAll('.carousel-item');

            let activeIdx = 0;
            items.forEach((item, idx) => {
                if (item.classList.contains('active')) activeIdx = idx;
                item.classList.remove('active');
            });

            let newIdx = activeIdx + dir;
            if (newIdx < 0) newIdx = items.length - 1;
            if (newIdx >= items.length) newIdx = 0;

            items[newIdx].classList.add('active');

            const prevVid = items[activeIdx].querySelector('video');
            if (prevVid) prevVid.pause();
        }

        const media = <?= json_encode($lightboxMedia) ?>;
        let currentPlaylist = [];
        let currentIndex = 0; // Index relative to currentPlaylist
        
        const lightbox = document.getElementById('lightbox');
        const img = document.getElementById('lightbox-img');
        const vid = document.getElementById('lightbox-video');

        function openLightbox(globalIndex, scopeId) {
            if (globalIndex < 0 || globalIndex >= media.length) return;
            
            if (scopeId) {
                // Scope to specific post (filter by post_id)
                currentPlaylist = media.filter(m => m.post_id == scopeId);
                
                // Find where the clicked item is in this new playlist
                const targetId = media[globalIndex].id;
                currentIndex = currentPlaylist.findIndex(m => m.id == targetId);
                if (currentIndex === -1) currentIndex = 0; 
            } else {
                // Global scope (Media Query)
                currentPlaylist = media;
                currentIndex = globalIndex;
            }

            updateContent();
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeLightbox() {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
            vid.pause();
        }

        function nav(dir) {
            if (currentPlaylist.length === 0) return;
            // Wrap around logic
            currentIndex = (currentIndex + dir + currentPlaylist.length) % currentPlaylist.length;
            updateContent();
        }

        function updateContent() {
            const item = currentPlaylist[currentIndex];
            if (item.type === 'video') {
                img.style.display = 'none';
                vid.style.display = 'block';
                vid.src = item.src;
                vid.play();
            } else {
                vid.style.display = 'none';
                vid.pause();
                img.style.display = 'block';
                img.src = item.src;
            }
        }

        document.addEventListener('keydown', function(e) {
            if (!lightbox.classList.contains('active')) return;
            if (e.key === 'Escape') closeLightbox();
            if (e.key === 'ArrowLeft') nav(-1);
            if (e.key === 'ArrowRight') nav(1);
        });

        // Lazy load video thumbnails - ONE at a time for smooth scrolling
        const videoQueue = [];
        let isLoadingVideo = false;

        function loadNextVideo() {
            if (isLoadingVideo || videoQueue.length === 0) return;

            const video = videoQueue.shift();
            if (!video || video.src) return; // Already loaded

            isLoadingVideo = true;
            video.src = video.dataset.src;
            video.onloadeddata = video.onerror = () => {
                isLoadingVideo = false;
                setTimeout(loadNextVideo, 50); // Small delay between loads
            };
            video.load();
        }

        const videoObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !entry.target.src) {
                    videoQueue.push(entry.target);
                    videoObserver.unobserve(entry.target);
                    loadNextVideo();
                }
            });
        }, { rootMargin: '50px' });

        document.querySelectorAll('video.video-thumb[data-src]').forEach(v => videoObserver.observe(v));
    </script>

    <footer class="site-footer">
        <a href="https://github.com/BentByte-Studios/OFWebBrowser" target="_blank" rel="noopener">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
            GitHub
        </a>
        <a href="https://discord.gg/k86x44ubJR" target="_blank" rel="noopener">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M13.545 2.907a13.227 13.227 0 0 0-3.257-1.011.05.05 0 0 0-.052.025c-.141.25-.297.577-.406.833a12.19 12.19 0 0 0-3.658 0 8.258 8.258 0 0 0-.412-.833.051.051 0 0 0-.052-.025c-1.125.194-2.22.534-3.257 1.011a.041.041 0 0 0-.021.018C.356 6.024-.213 9.047.066 12.032c.001.014.01.028.021.037a13.276 13.276 0 0 0 3.995 2.02.05.05 0 0 0 .056-.019c.308-.42.582-.863.818-1.329a.05.05 0 0 0-.027-.07 8.735 8.735 0 0 1-1.248-.595.05.05 0 0 1-.005-.083c.084-.063.168-.129.248-.195a.05.05 0 0 1 .051-.007c2.619 1.196 5.454 1.196 8.041 0a.052.052 0 0 1 .053.007c.08.066.164.132.248.195a.051.051 0 0 1-.004.085c-.399.233-.813.43-1.249.594a.05.05 0 0 0-.027.07c.24.465.515.909.817 1.329a.05.05 0 0 0 .056.019 13.235 13.235 0 0 0 4.001-2.02.049.049 0 0 0 .021-.037c.334-3.451-.559-6.449-2.366-9.106a.034.034 0 0 0-.02-.019zm-8.198 7.307c-.789 0-1.438-.724-1.438-1.612 0-.889.637-1.613 1.438-1.613.807 0 1.45.73 1.438 1.613 0 .888-.637 1.612-1.438 1.612zm5.316 0c-.788 0-1.438-.724-1.438-1.612 0-.889.637-1.613 1.438-1.613.807 0 1.451.73 1.438 1.613 0 .888-.631 1.612-1.438 1.612z"/></svg>
            Discord
        </a>
        <a href="https://asa.wowemu.forum/" target="_blank" rel="noopener">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0zm3.5 4.5l-5 7h-1l-2-3h1.5l1 1.5 4-5.5h1.5z"/></svg>
            StreamGet
        </a>
    </footer>
</body>
</html>

<?php
function renderPagination($page, $totalPages, $creatorId, $activeTab = 'posts', $filter = 'all') {
    if ($totalPages <= 1) return;
    
    echo '<div class="pagination">';
    
    // Construct query param string
    $queryParams = '&tab=' . urlencode($activeTab);
    if ($activeTab === 'media' || $activeTab === 'messages') {
        $queryParams .= '&filter=' . urlencode($filter);
    }
    
    // Prev
    if ($page > 1) {
        echo '<a href="?id='.$creatorId.$queryParams.'&page='.($page-1).'" class="page-btn">&larr; Prev</a>';
    } else {
        echo '<span class="page-btn disabled">&larr; Prev</span>';
    }
    
    // Page Numbers (simple logic)
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $page) ? 'active' : '';
        echo '<a href="?id='.$creatorId.$queryParams.'&page='.$i.'" class="page-btn '.$active.'">'.$i.'</a>';
    }
    
    // Next
    if ($page < $totalPages) {
        echo '<a href="?id='.$creatorId.$queryParams.'&page='.($page+1).'" class="page-btn">Next &rarr;</a>';
    } else {
        echo '<span class="page-btn disabled">Next &rarr;</span>';
    }
    
    echo '</div>';
}
?>
