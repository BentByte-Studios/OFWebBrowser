<?php
// Helper Functions

function getProfiles($rootPath) {
    if (!is_dir($rootPath)) return [];

    $profiles = [];
    $dirs = array_filter(glob($rootPath . '/*'), 'is_dir');

    // Sort alphabetically
    sort($dirs);

    foreach ($dirs as $dir) {
        $name = basename($dir);
        $lastUpdated = filemtime($dir);
        
        // Find avatar and header
        $avatarPath = findProfileImage($dir, 'avatar');
        $headerPath = findProfileImage($dir, 'header');
        
        $avatar = $avatarPath ? 'view.php?path=' . urlencode($avatarPath) : null;
        $header = $headerPath ? 'view.php?path=' . urlencode($headerPath) : null;
        
        // Count media files (rough count)
        $mediaCount = countMediaFiles($dir);

        // Get Bio from DB
        $bio = "";
        $dbPath = $dir . '/Metadata/user_data.db';
        if (!file_exists($dbPath)) $dbPath = $dir . '/user_data.db';
        
        if (file_exists($dbPath)) {
            $db = new OFDatabase($dbPath);
            // Try standard tables
            $row = $db->queryOne("SELECT bio, description, about, text FROM profiles LIMIT 1");
            if (!$row) $row = $db->queryOne("SELECT bio, description, about, text FROM users LIMIT 1");

            if ($row) {
                // Pick the first non-empty value
                $bio = $row['bio'] ?? $row['description'] ?? $row['about'] ?? $row['text'] ?? "";
            }
        }

        $profiles[] = [
            'name' => $name,
            'path' => $dir,
            'avatar' => $avatar,
            'header' => $header,
            'mediaCount' => $mediaCount,
            'lastUpdated' => $lastUpdated,
            'bio' => cleanText($bio)
        ];
    }
    return $profiles;
}

function findProfileImage($dir, $type) {
    // Logic similar to C# ViewModel
    $folderName = ($type === 'avatar') ? 'Avatars' : (($type === 'header') ? 'Headers' : $type);
    
    $searchPaths = [
        $dir . '/Profile/' . $folderName,
        $dir . '/Metadata/Profile/' . $folderName,
        $dir . '/' . $folderName,
        $dir . '/Profile',
        $dir
    ];

    foreach ($searchPaths as $path) {
        if (is_dir($path)) {
            // Find images
            $files = glob($path . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
            if ($files) {
                // Filter if in generic folder
                if (basename($path) !== $folderName) {
                    $files = array_filter($files, function($f) use ($type) {
                        return stripos(basename($f), $type) !== false;
                    });
                }

                if (!empty($files)) {
                    // Sort by modification time desc
                    usort($files, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });
                    
                    // Return raw absolute path
                    return $files[0];
                }
            }
        }
    }
    return null;
}

function countMediaFiles($dir) {
    // Simple recursive count (approximated for speed)
    // Using RecursiveDirectoryIterator
    $count = 0;
    try {
        $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach($objects as $object) {
            $ext = strtolower($object->getExtension());
            if (in_array($ext, ['jpg','jpeg','png','gif','mp4','mov','wmv','avi','webm'])) {
                $count++;
            }
        }
    } catch(Exception $e) { }
    return $count;
}

function cleanText($text) {
    if (empty($text)) return '';
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text);
    // Force VS16 for emojis
    $text = str_replace("\xEF\xB8\x8E", "\xEF\xB8\x8F", $text); // VS15 -> VS16 bytes
    return trim($text);
}

function formatSize($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
