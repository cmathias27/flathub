<?php
// --- DEBUG TEMPORAIRE : à retirer une fois le problème résolu ---
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);
// -----------------------------------------------------------------

require_once __DIR__ . '/../config.php';

// Durée de vie du cache d'index (secondes). Le dossier n'est re-scanné en
// entier qu'une fois toutes les X secondes max, même si des dizaines de
// vignettes/flux sont demandés dans l'intervalle.
define('INDEX_TTL', 20);

/**
 * Parcourt récursivement LIBRARY_DIR et retourne la liste "brute" des
 * fichiers vidéo trouvés (sans durée, qui coûte cher à calculer).
 * Lecture seule : aucune écriture dans LIBRARY_DIR.
 */
function build_file_index(): array
{
    $videos = [];

    foreach (VIDEO_SOURCES as $source) {
        $sourceDir = $source['dir'];
        if (!is_dir($sourceDir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
        );
        $sourceReal = realpath($sourceDir);
        if (!$sourceReal) continue;

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $ext = strtolower($fileInfo->getExtension());
            if (!in_array($ext, VIDEO_EXTENSIONS, true)) continue;

            $realPath = $fileInfo->getRealPath();
            $relativePath = ltrim(str_replace($sourceReal, '', $realPath), DIRECTORY_SEPARATOR);

            // Conserve les IDs historiques de /media/library pour ne pas perdre les notes.
            $idSeed = $source['key'] === 'library'
                ? $relativePath
                : $source['key'] . ':' . $relativePath;
            $id = substr(hash('sha256', $idSeed), 0, 16);

            $videos[$id] = [
                'id'          => $id,
                'title'       => clean_title($fileInfo->getFilename()),
                'filename'    => $fileInfo->getFilename(),
                // Les tags sont persistés dans data/tags.json. À la première
                // détection d'une vidéo, on les initialise depuis le nom du fichier.
                'tags'        => extract_tags($fileInfo->getFilename()),
                'relpath'     => $relativePath,
                'source_key'  => $source['key'],
                'source_label'=> $source['label'],
                'source_dir'  => $sourceDir,
                'size'        => $fileInfo->getSize(),
                'size_h'      => human_filesize($fileInfo->getSize()),
                'mtime'       => $fileInfo->getMTime(),
                'date_h'      => date('d/m/Y H:i', $fileInfo->getMTime()),
                'date_rel'    => relative_date($fileInfo->getMTime()),
                'ext'         => $ext,
            ];
        }
    }

    // Synchronise les tags persistés après le scan : les tags existants
    // sont conservés (ce qui permet de les modifier manuellement plus tard),
    // et les nouvelles vidéos sont initialisées depuis leur nom de fichier.
    $storedTags = sync_video_tags($videos);
    foreach ($videos as $id => &$video) {
        if (array_key_exists($id, $storedTags)) {
            $video['tags'] = $storedTags[$id];
        }
    }
    unset($video);

    return $videos;
}

/**
 * Lit data/tags.json. Le format est :
 * {
 *   "video-id": ["Tag 1", "Tag 2"]
 * }
 */
function load_tags_store(): array
{
    if (!is_dir(RATINGS_DIR)) {
        @mkdir(RATINGS_DIR, 0775, true);
    }

    if (!is_file(TAGS_FILE)) {
        return [];
    }

    $data = json_decode((string) @file_get_contents(TAGS_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Nettoie une liste de tags avant persistance.
 */
function normalize_tag_list(array $tags): array
{
    $result = [];
    $seen = [];

    foreach ($tags as $rawTag) {
        if (!is_string($rawTag)) continue;
        $tag = trim(preg_replace('/\s+/u', ' ', $rawTag));
        if ($tag === '') continue;

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($tag, 'UTF-8')
            : strtolower($tag);

        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $result[] = $tag;
    }

    return $result;
}

/**
 * Persiste les tags associés aux vidéos.
 * Les anciennes entrées correspondant à des vidéos disparues/renommées sont
 * retirées afin d'éviter que tags.json ne grossisse indéfiniment.
 */
function sync_video_tags(array $videos): array
{
    $stored = load_tags_store();
    $next = [];

    foreach ($videos as $id => $video) {
        if (isset($stored[$id]) && is_array($stored[$id])) {
            $next[$id] = normalize_tag_list($stored[$id]);
        } else {
            $next[$id] = extract_tags($video['filename'] ?? '');
        }
    }

    if ($next !== $stored) {
        if (!is_dir(RATINGS_DIR)) {
            @mkdir(RATINGS_DIR, 0775, true);
        }
        @file_put_contents(
            TAGS_FILE,
            json_encode($next, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    return $next;
}



/**
 * Lit le registre des décisions concernant les suggestions de tags.
 * Format : { "video-id": {"status":"ignored|accepted", "tags":[...] } }
 */
function load_tag_suggestions_store(): array
{
    if (!is_dir(RATINGS_DIR)) {
        @mkdir(RATINGS_DIR, 0775, true);
    }
    if (!is_file(TAG_SUGGESTIONS_FILE)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents(TAG_SUGGESTIONS_FILE), true);
    return is_array($data) ? $data : [];
}

function save_json_store(string $file, array $data): bool
{
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0775, true);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && @file_put_contents($file, $json, LOCK_EX) !== false;
}

/**
 * Normalise un nom pour comparer proprement les tags au texte du fichier.
 * Les séparateurs (_, -, ., parenthèses, etc.) deviennent des espaces.
 */
function normalize_tag_search_text(string $value): string
{
    $value = preg_replace('/\.[^.]+$/u', '', $value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

/**
 * Retourne les tags existants dans tags.json, dédoublonnés.
 */
function get_existing_tags(array $videos = []): array
{
    $stored = load_tags_store();
    $all = [];
    foreach ($stored as $tags) {
        if (!is_array($tags)) continue;
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') $all[] = $tag;
        }
    }
    foreach ($videos as $video) {
        foreach (($video['tags'] ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') $all[] = $tag;
        }
    }
    $all = normalize_tag_list($all);
    usort($all, fn($a, $b) => strcasecmp($a, $b));
    return $all;
}

/**
 * Trouve les tags existants réellement présents dans le nom de fichier.
 * La comparaison se fait sur des mots complets afin d'éviter les faux positifs
 * (ex. "art" ne correspond pas à "party").
 */
function find_matching_existing_tags(string $filename, array $existingTags): array
{
    $haystack = normalize_tag_search_text($filename);
    if ($haystack === '') return [];

    $matches = [];
    foreach ($existingTags as $tag) {
        $needle = normalize_tag_search_text($tag);
        if ($needle === '') continue;
        $pattern = '/(?:^| )' . preg_quote($needle, '/') . '(?: |$)/u';
        if (preg_match($pattern, $haystack)) {
            $matches[] = $tag;
        }
    }
    return normalize_tag_list($matches);
}

/**
 * Construit les suggestions pour les vidéos qui n'ont actuellement aucun tag
 * et qui n'ont pas déjà été ignorées/traitées.
 */
function get_tag_suggestions(array $videos): array
{
    $existingTags = get_existing_tags($videos);
    $decisions = load_tag_suggestions_store();
    $suggestions = [];

    foreach ($videos as $id => $video) {
        if (!empty($video['tags'])) continue;
        $decision = $decisions[$id] ?? null;
        if (is_array($decision) && in_array(($decision['status'] ?? ''), ['ignored', 'accepted'], true)) continue;

        $matches = find_matching_existing_tags($video['filename'] ?? '', $existingTags);
        if (!$matches) continue;

        $suggestions[] = [
            'id' => $id,
            'title' => $video['title'],
            'filename' => $video['filename'],
            'mtime' => $video['mtime'],
            'date_h' => $video['date_h'],
            'suggested_tags' => $matches,
        ];
    }

    usort($suggestions, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $suggestions;
}

/**
 * Enregistre une décision utilisateur et, si elle est acceptée, les tags de la vidéo.
 */
function save_tag_decision(string $videoId, string $status, array $tags = []): array
{
    $videos = get_index();
    if (!isset($videos[$videoId])) {
        throw new RuntimeException('Vidéo introuvable.');
    }
    if (!in_array($status, ['ignored', 'accepted'], true)) {
        throw new RuntimeException('Décision invalide.');
    }

    $decisions = load_tag_suggestions_store();
    $cleanTags = normalize_tag_list($tags);
    $decisions[$videoId] = [
        'status' => $status,
        'tags' => $cleanTags,
        'updated_at' => date('c'),
    ];

    if ($status === 'accepted') {
        $stored = load_tags_store();
        $stored[$videoId] = $cleanTags;
        if (!save_json_store(TAGS_FILE, $stored)) {
            throw new RuntimeException('Impossible d’enregistrer les tags.');
        }
    }

    if (!save_json_store(TAG_SUGGESTIONS_FILE, $decisions)) {
        throw new RuntimeException('Impossible d’enregistrer la décision.');
    }

    // Les tags font partie de l'index exposé par l'API : invalide le cache
    // immédiatement pour que la nouvelle décision soit visible sans attendre.
    $indexCache = CACHE_DIR . '/index.json';
    if (is_file($indexCache)) @unlink($indexCache);

    return ['status' => $status, 'tags' => $cleanTags];
}

function video_file_path(array $video): ?string
{
    $sourceDir = $video['source_dir'] ?? null;
    if (!$sourceDir || !is_dir($sourceDir)) return null;

    $sourceReal = realpath($sourceDir);
    if (!$sourceReal) return null;

    $candidate = $sourceReal . DIRECTORY_SEPARATOR . ($video['relpath'] ?? '');
    $realFile = realpath($candidate);
    if (!$realFile || !is_file($realFile)) return null;

    if ($realFile !== $sourceReal && !str_starts_with($realFile, $sourceReal . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $realFile;
}

/**
 * Renvoie l'index des vidéos (id => métadonnées), mis en cache sur disque
 * pendant INDEX_TTL secondes pour éviter de re-scanner tout le dossier à
 * chaque requête (une page peut déclencher des dizaines de requêtes : une
 * par vignette + une par flux vidéo).
 */
function get_index(): array
{
    static $memo = null; // évite même de relire le cache disque plusieurs fois dans la même requête
    if ($memo !== null) {
        return $memo;
    }

    $cacheFile = CACHE_DIR . '/index.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < INDEX_TTL) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            $memo = $data;
            return $memo;
        }
    }

    $memo = build_file_index();
    @file_put_contents($cacheFile, json_encode($memo));

    return $memo;
}

/**
 * Retourne la liste des vidéos triée par date de modification décroissante,
 * avec la durée de chaque vidéo (calculée une fois puis mise en cache).
 */
function scan_videos(): array
{
    $videos = array_values(get_index());

    usort($videos, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

    foreach ($videos as &$v) {
        $v['duration_h'] = get_cached_duration($v['id'], $v['source_dir'] . DIRECTORY_SEPARATOR . $v['relpath']);
    }
    unset($v);

    return $videos;
}

/**
 * Retrouve une vidéo par son id (hash du chemin relatif).
 * Lecture directe dans l'index en cache : ne re-scanne PAS tout le dossier
 * à chaque appel (utilisé par thumb.php et stream.php, appelés en masse).
 */
function find_video_by_id(string $id): ?array
{
    $index = get_index();
    return $index[$id] ?? null;
}

/**
 * Extrait les tags écrits entre parenthèses dans le nom du fichier.
 *
 * Exemple :
 * () (StudioTitle) - The Scene Title - (Vanessa Alessia) - (Michael Fly) - ()
 * => ["StudioTitle", "Vanessa Alessia", "Michael Fly"]
 *
 * Les parenthèses vides sont ignorées et les doublons sont supprimés
 * (insensible à la casse) en conservant la première écriture rencontrée.
 */
function extract_tags(string $filename): array
{
    $name = preg_replace('/\\.[^.]+$/u', '', $filename);
    preg_match_all('/\\(([^()]*)\\)/u', $name, $matches);

    $tags = [];
    $seen = [];

    foreach ($matches[1] ?? [] as $rawTag) {
        $tag = trim(preg_replace('/\\s+/u', ' ', $rawTag));
        if ($tag === '') {
            continue;
        }

        $key = function_exists('mb_strtolower')
            ? mb_strtolower($tag, 'UTF-8')
            : strtolower($tag);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $tags[] = $tag;
    }

    return $tags;
}

/**
 * Nettoie un nom de fichier pour en faire un titre lisible.
 */
function clean_title(string $filename): string
{
    $name = preg_replace('/\.[^.]+$/', '', $filename); // retire l'extension
    $name = str_replace(['_', '-', '.'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);

    if (function_exists('mb_convert_case')) {
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }
    // Repli si l'extension mbstring n'est pas installée sur le serveur cible.
    return ucwords($name);
}

/**
 * Formate une taille de fichier en octets vers une unité lisible.
 */
function human_filesize(int $bytes, int $decimals = 1): string
{
    $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $factor = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $factor = min($factor, count($units) - 1);
    return sprintf("%.{$decimals}f %s", $bytes / (1024 ** $factor), $units[$factor]);
}

/**
 * Formate un timestamp en date relative façon YouTube ("il y a 3 jours").
 */
function relative_date(int $timestamp): string
{
    $diff = time() - $timestamp;

    if ($diff < 0) {
        return date('d/m/Y', $timestamp);
    }
    if ($diff < 60) {
        return "à l'instant";
    }
    if ($diff < 3600) {
        $m = floor($diff / 60);
        return "il y a $m minute" . ($m > 1 ? 's' : '');
    }
    if ($diff < 86400) {
        $h = floor($diff / 3600);
        return "il y a $h heure" . ($h > 1 ? 's' : '');
    }
    if ($diff < 86400 * 30) {
        $d = floor($diff / 86400);
        return "il y a $d jour" . ($d > 1 ? 's' : '');
    }
    if ($diff < 86400 * 365) {
        $mo = floor($diff / (86400 * 30));
        return "il y a $mo mois";
    }
    $y = floor($diff / (86400 * 365));
    return "il y a $y an" . ($y > 1 ? 's' : '');
}

/**
 * Récupère la durée d'une vidéo via ffprobe, avec mise en cache disque
 * (fichier .txt dans CACHE_DIR) pour éviter de relancer ffprobe à chaque requête.
 */
function get_cached_duration(string $id, string $filePath): string
{
    $cacheFile = CACHE_DIR . '/' . $id . '.duration';

    if (is_file($cacheFile)) {
        return trim(file_get_contents($cacheFile));
    }

    $duration = probe_duration($filePath);
    $formatted = format_duration($duration);

    @file_put_contents($cacheFile, $formatted);

    return $formatted;
}

function probe_duration(string $filePath): float
{
    $cmd = escapeshellcmd(FFPROBE_BIN) . ' -v error -show_entries format=duration'
        . ' -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($filePath);
    $output = @shell_exec($cmd);
    return $output ? (float) trim($output) : 0.0;
}

function format_duration(float $seconds): string
{
    $seconds = (int) round($seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%d:%02d', $m, $s);
}
