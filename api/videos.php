<?php
// --- DEBUG TEMPORAIRE : à retirer une fois le problème résolu ---
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------

require_once __DIR__ . '/../lib/videos.php';

header('Content-Type: application/json; charset=utf-8');

$videos = scan_videos();

// On expose une URL de miniature et de flux pour chaque vidéo, sans exposer le chemin disque réel.
foreach ($videos as &$v) {
    $v['thumb_url'] = 'api/thumb.php?id=' . urlencode($v['id']);
    $v['stream_url'] = 'api/stream.php?id=' . urlencode($v['id']);
    unset($v['relpath']); // ne pas exposer le chemin réel côté client
}
unset($v);

echo json_encode(['videos' => $videos], JSON_UNESCAPED_UNICODE);
