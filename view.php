<?php
require_once 'config.php';

$path = $_GET['path'] ?? '';

if (empty($path)) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

// Security Check: Path must be within the OF download folder
// Normalize slashes
$realPath = realpath($path);
$basePath = realpath(OF_DOWNLOAD_PATH);

// Validate path exists
if (!$realPath || !file_exists($realPath)) {
    header("HTTP/1.0 404 Not Found");
    exit;
}

// CRITICAL: Validate path is within allowed base directory (prevent path traversal)
if (!$basePath || strpos($realPath, $basePath) !== 0) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

// Get Content Type
$ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'mp4' => 'video/mp4',
    'mov' => 'video/quicktime',
    'webm' => 'video/webm',
    'avi' => 'video/x-msvideo',
    'wmv' => 'video/x-ms-wmv'
];

$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

// File size
$filesize = filesize($realPath);

// Caching headers
$tsstring = gmdate('D, d M Y H:i:s ', filemtime($realPath)) . 'GMT';
$etag = md5($realPath . filemtime($realPath));

header("Content-Type: $contentType");
header("Last-Modified: $tsstring");
header("ETag: \"$etag\"");
header("Accept-Ranges: bytes");

// Range handling
if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    $p1 = $p2 = 0;
    
    if (preg_match('/bytes=(\d+)-(\d+)?/', $range, $matches)) {
        $p1 = (int)$matches[1];
        $p2 = isset($matches[2]) ? (int)$matches[2] : $filesize - 1;
    }

    if ($p1 > $filesize - 1 || $p2 > $filesize - 1 || $p2 < $p1) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes */$filesize");
        exit;
    }

    $length = $p2 - $p1 + 1;

    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $p1-$p2/$filesize");
    header("Content-Length: $length");

    $file = fopen($realPath, 'rb');
    fseek($file, $p1);
    
    // Chunked output to save memory
    $buffer = 1024 * 8;
    while (!feof($file) && ($p2 - $p1 >= 0)) {
        if ($p2 - $p1 < $buffer) {
            $buffer = $p2 - $p1 + 1;
        }
        $data = fread($file, $buffer);
        echo $data;
        flush();
        $p1 += $buffer;
    }
    fclose($file);
    exit;
}

// Full Content
header("Content-Length: $filesize");
readfile($realPath);
