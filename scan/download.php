<?php
/**
 * ============================================================================
 * YUHU PHOTOBOOTH - MEDIA DOWNLOAD & PROXY HANDLER (scan.yuhu.co.id)
 * ============================================================================
 * Melayani unduhan langsung dan fetch Blob aman (Same-Origin) untuk fitur
 * "Simpan Foto / Video ke Galeri" di iPhone dan "Download Langsung" di Android.
 * ============================================================================
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$folder = trim($_GET['folder'] ?? '');
$file = trim($_GET['file'] ?? '');

if (empty($folder) || empty($file)) {
    http_response_code(400);
    exit('Parameter folder atau file belum ditentukan.');
}

// Sanitasi keamanan: cegah directory traversal
$folder = str_replace(['..', "\0"], '', $folder);
$file = str_replace(['..', "\0"], '', $file);

$basePhotosDir = realpath(__DIR__ . '/../client/photos');
$targetPath = realpath(__DIR__ . '/../client/photos/' . $folder . '/' . $file);

if (!$targetPath || !$basePhotosDir || strpos($targetPath, $basePhotosDir) !== 0 || !is_file($targetPath)) {
    http_response_code(404);
    exit('File media tidak ditemukan.');
}

$ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'webm' => 'video/webm'
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';
$filename = basename($targetPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($targetPath));
header('Cache-Control: public, max-age=86400');

$action = $_GET['action'] ?? 'download';
if ($action === 'download') {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    // inline untuk fetch Blob via JavaScript
    header('Content-Disposition: inline; filename="' . $filename . '"');
}

readfile($targetPath);
exit;
