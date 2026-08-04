<?php
require_once __DIR__ . '/../lib/videos.php';

header('Content-Type: application/json; charset=utf-8');

function load_ratings_for_delete(): array {
    if (!is_file(RATINGS_FILE)) return [];
    $data = json_decode((string) file_get_contents(RATINGS_FILE), true);
    return is_array($data) ? $data : [];
}

function save_ratings_for_delete(array $ratings): bool {
    $tmp = RATINGS_FILE . '.tmp';
    $json = json_encode($ratings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, RATINGS_FILE);
}

function valid_delete_id(string $id): bool {
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

function clear_video_cache(string $id): void {
    foreach (glob(CACHE_DIR . '/' . $id . '.*') ?: [] as $file) {
        if (is_file($file)) @unlink($file);
    }
    $indexCache = CACHE_DIR . '/index.json';
    if (is_file($indexCache)) @unlink($indexCache);
}

function deletion_candidates(): array {
    $ratings = load_ratings_for_delete();
    $videos = scan_videos();
    $out = [];
    foreach ($videos as $video) {
        $r = $ratings[$video['id']] ?? null;
        if (!is_array($r)) continue; // Une vidéo sans note n'est pas supprimée.
        $count = max(0, (int)($r['count'] ?? 0));
        $sum = max(0, (int)($r['sum'] ?? 0));
        if ($count <= 0) continue;
        $average = $sum / $count;
        if ($average < 3) {
            $video['rating'] = [
                'count' => $count,
                'sum' => $sum,
                'average' => round($average, 1),
            ];
            $video['thumb_url'] = 'thumb.php?id=' . urlencode($video['id']);
            $video['stream_url'] = 'stream.php?id=' . urlencode($video['id']);
            $out[] = $video;
        }
    }
    return $out;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $items = deletion_candidates();
    echo json_encode([
        'threshold' => 3,
        'count' => count($items),
        'videos' => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$ids = is_array($payload) ? ($payload['ids'] ?? []) : [];
if (!is_array($ids)) $ids = [$ids];
$ids = array_values(array_unique(array_filter(array_map('strval', $ids), 'valid_delete_id')));

if (!$ids) {
    http_response_code(400);
    echo json_encode(['error' => 'Aucune vidéo sélectionnée']);
    exit;
}

$ratings = load_ratings_for_delete();
$videos = get_index();
$deleted = [];
$errors = [];

foreach ($ids as $id) {
    $video = $videos[$id] ?? null;
    if (!$video) {
        $errors[] = "$id : vidéo introuvable";
        continue;
    }

    $r = $ratings[$id] ?? null;
    $count = is_array($r) ? max(0, (int)($r['count'] ?? 0)) : 0;
    $sum = is_array($r) ? max(0, (int)($r['sum'] ?? 0)) : 0;
    $average = $count > 0 ? $sum / $count : 0;

    // Double vérification côté serveur : seules les vidéos réellement < 3 sont supprimables.
    if ($count <= 0 || $average >= 3) {
        $errors[] = "$id : la note n'est plus inférieure à 3 étoiles";
        continue;
    }

    $realFile = video_file_path($video);
    if (!$realFile) {
        $errors[] = "$id : chemin de fichier invalide";
        continue;
    }

    if (!@unlink($realFile)) {
        $errors[] = "$video[filename] : suppression impossible (droits d'accès ?)";
        continue;
    }

    unset($ratings[$id]);
    clear_video_cache($id);
    $deleted[] = [
        'id' => $id,
        'title' => $video['title'],
        'filename' => $video['filename'],
        'rating' => round($average, 1),
    ];
}

if ($deleted && !save_ratings_for_delete($ratings)) {
    // Les fichiers sont déjà supprimés : on signale l'erreur sans tenter de les restaurer.
    $errors[] = 'Certains fichiers ont été supprimés, mais la base des notes n’a pas pu être mise à jour.';
}

http_response_code($errors && !$deleted ? 400 : 200);
echo json_encode([
    'ok' => !$errors,
    'deleted' => $deleted,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE);
