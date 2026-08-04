<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_dir(RATINGS_DIR)) {
    @mkdir(RATINGS_DIR, 0775, true);
}

function load_ratings(): array {
    if (!is_file(RATINGS_FILE)) return [];
    $data = json_decode((string) file_get_contents(RATINGS_FILE), true);
    return is_array($data) ? $data : [];
}

function save_ratings(array $ratings): bool {
    $tmp = RATINGS_FILE . '.tmp';
    $json = json_encode($ratings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, RATINGS_FILE);
}

function valid_video_id(string $id): bool {
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $ratings = load_ratings();
    $out = [];
    foreach ($ratings as $id => $r) {
        if (!valid_video_id((string)$id) || !is_array($r)) continue;
        $count = max(0, (int)($r['count'] ?? 0));
        $sum = max(0, (int)($r['sum'] ?? 0));
        $out[$id] = [
            'count' => $count,
            'sum' => $sum,
            'average' => $count > 0 ? round($sum / $count, 1) : 0,
        ];
    }
    echo json_encode(['ratings' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$id = is_array($payload) ? (string)($payload['id'] ?? '') : '';
$rating = is_array($payload) ? (int)($payload['rating'] ?? 0) : 0;

if (!valid_video_id($id) || $rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['error' => 'Note invalide']);
    exit;
}

$ratings = load_ratings();
$current = $ratings[$id] ?? ['count' => 0, 'sum' => 0];
$current['count'] = max(0, (int)$current['count']) + 1;
$current['sum'] = max(0, (int)$current['sum']) + $rating;
$ratings[$id] = $current;

if (!save_ratings($ratings)) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossible d’enregistrer la note']);
    exit;
}

echo json_encode([
    'ok' => true,
    'rating' => [
        'count' => $current['count'],
        'sum' => $current['sum'],
        'average' => round($current['sum'] / $current['count'], 1),
    ]
], JSON_UNESCAPED_UNICODE);
