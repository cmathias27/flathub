<?php
require_once __DIR__ . '/../lib/videos.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $videos = get_index();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $suggestions = get_tag_suggestions($videos);
        echo json_encode([
            'suggestions' => $suggestions,
            'count' => count($suggestions),
            'existing_tags' => get_existing_tags($videos),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Méthode non autorisée']);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Données invalides.');
    }

    $id = trim((string) ($payload['id'] ?? ''));
    $action = (string) ($payload['action'] ?? '');
    $tags = is_array($payload['tags'] ?? null) ? $payload['tags'] : [];

    if ($id === '') throw new RuntimeException('ID vidéo manquant.');

    if ($action === 'ignore') {
        $result = save_tag_decision($id, 'ignored', []);
    } elseif ($action === 'accept') {
        $result = save_tag_decision($id, 'accepted', $tags);
    } else {
        throw new RuntimeException('Action inconnue.');
    }

    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
