<?php
require_once __DIR__ . '/../lib/videos.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
    http_response_code(400);
    exit('id invalide');
}

$video = find_video_by_id($id);
if (!$video) {
    http_response_code(404);
    exit('vidéo introuvable');
}

$filePath = video_file_path($video);
if (!$filePath) {
    http_response_code(404);
    exit('fichier introuvable');
}
if (!is_file($filePath)) {
    http_response_code(404);
    exit('fichier introuvable');
}

$mimeTypes = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'mkv'  => 'video/x-matroska',
    'mov'  => 'video/quicktime',
    'avi'  => 'video/x-msvideo',
    'm4v'  => 'video/x-m4v',
];
$mime = $mimeTypes[$video['ext']] ?? 'application/octet-stream';

$fileSize = filesize($filePath);
$start = 0;
$end = $fileSize - 1;

header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);

if (isset($_SERVER['HTTP_RANGE'])) {
    // Requête partielle : ex. "bytes=1000000-"
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $start = $matches[1] !== '' ? (int) $matches[1] : 0;
        $end = $matches[2] !== '' ? (int) $matches[2] : $fileSize - 1;
        $end = min($end, $fileSize - 1);

        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
    }
} else {
    http_response_code(200);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

$fp = fopen($filePath, 'rb'); // lecture seule
fseek($fp, $start);

$bufferSize = 1024 * 1024; // 1 Mo
$bytesRemaining = $length;

while ($bytesRemaining > 0 && !feof($fp)) {
    $chunk = min($bufferSize, $bytesRemaining);
    echo fread($fp, $chunk);
    flush();
    $bytesRemaining -= $chunk;
}

fclose($fp);
