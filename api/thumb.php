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

$thumbPath = CACHE_DIR . '/' . $id . '.jpg';

if (!is_file($thumbPath)) {
    $sourcePath = video_file_path($video);
    if (!$sourcePath) {
        http_response_code(404);
        exit('fichier introuvable');
    }
    // On ne modifie jamais la source : on écrit uniquement dans CACHE_DIR.
    $seekTime = '00:00:01';
    $cmd = escapeshellcmd(FFMPEG_BIN)
        . ' -y -ss ' . escapeshellarg($seekTime)
        . ' -i ' . escapeshellarg($sourcePath)
        . ' -frames:v 1 -vf "scale=480:-1"'
        . ' -q:v 4 ' . escapeshellarg($thumbPath)
        . ' 2>&1';
    @shell_exec($cmd);
}

if (!is_file($thumbPath)) {
    // Fallback : image grise si ffmpeg échoue (ex: format non supporté)
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="270" viewBox="0 0 480 270">'
        . '<rect width="480" height="270" fill="#272727"/>'
        . '<text x="50%" y="50%" fill="#909090" font-family="sans-serif" font-size="18" text-anchor="middle" dy=".3em">Pas d\'aperçu</text>'
        . '</svg>';
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');
readfile($thumbPath);
