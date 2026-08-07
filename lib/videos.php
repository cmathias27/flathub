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

function normalize_tag_list(array $tags): array
{
    $result = [];
    $seen = [];

    foreach ($tags as $rawTag) {
        if (!is_string($rawTag)) continue;
        $tag = trim(preg_replace('/\s+/u', ' ', $rawTag));
        if ($tag === '') continue;

        $key = canonical_tag_key($tag);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $result[] = $tag;
    }

    return $result;
}

/**
 * Registre global permanent des tags découverts dans les noms de fichiers
 * ou déjà présents dans tags.json. Il n'est jamais nettoyé.
 */
function load_tag_registry(): array
{
    if (!is_file(TAG_REGISTRY_FILE)) return [];
    $data = json_decode((string) @file_get_contents(TAG_REGISTRY_FILE), true);
    return is_array($data) ? $data : [];
}

function register_tags(array $tags): array
{
    $registry = load_tag_registry();
    $now = date('c');
    $changed = false;
    $lookup = [];

    foreach ($registry as $existing => $meta) {
        $lookup[canonical_tag_key((string) $existing)] = $existing;
    }

    foreach (normalize_tag_list($tags) as $tag) {
        $key = canonical_tag_key($tag);
        if ($key === '') continue;

        if (!isset($lookup[$key])) {
            $registry[$tag] = [
                'first_seen' => $now,
                'last_seen' => $now,
            ];
            $lookup[$key] = $tag;
            $changed = true;
        } else {
            $existing = $lookup[$key];
            if (!isset($registry[$existing]) || !is_array($registry[$existing])) {
                $registry[$existing] = [];
                $changed = true;
            }
            if (empty($registry[$existing]['first_seen'])) {
                $registry[$existing]['first_seen'] = $now;
                $changed = true;
            }
            // Le registre reste permanent ; last_seen est purement informatif.
            if (($registry[$existing]['last_seen'] ?? '') !== $now) {
                $registry[$existing]['last_seen'] = $now;
                $changed = true;
            }
        }
    }

    if ($changed) save_json_store(TAG_REGISTRY_FILE, $registry);
    return $registry;
}

/**
 * Décisions persistantes des suggestions.
 * Format :
 * {
 *   "video-id": {
 *     "suggestions": {
 *       "paul": {"tag": "Paul", "status": "accepted", "updated_at": "..."}
 *     }
 *   }
 * }
 *
 * L'ancien format (video-id => {status: ...}) reste lu pour compatibilité.
 */
function load_tag_suggestions_store(): array
{
    if (!is_file(TAG_SUGGESTIONS_FILE)) return [];
    $data = json_decode((string) @file_get_contents(TAG_SUGGESTIONS_FILE), true);
    return is_array($data) ? $data : [];
}

function save_json_store(string $file, array $data): bool
{
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && @file_put_contents($file, $json, LOCK_EX) !== false;
}

function normalize_tag_search_text(string $value): string
{
    $value = preg_replace('/\.[^.]+$/u', '', $value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value);
}

function canonical_tag_key(string $tag): string
{
    return normalize_tag_search_text($tag);
}

function get_existing_tags(array $videos = []): array
{
    $registry = load_tag_registry();
    $all = array_keys($registry);

    // Compatibilité : si le registre est vide/ancien, les tags persistés
    // restent une source de connaissance.
    $stored = load_tags_store();
    foreach ($stored as $tags) {
        if (is_array($tags)) $all = array_merge($all, $tags);
    }

    foreach ($videos as $video) {
        $raw = extract_tags($video['filename'] ?? '');
        $all = array_merge($all, $raw);
    }

    $all = normalize_tag_list($all);
    usort($all, fn($a, $b) => strcasecmp($a, $b));
    return $all;
}

/**
 * Retourne true si un tag connu est présent comme mot/séquence de mots
 * dans un tag inscrit entre parenthèses.
 *
 * Exemple :
 *   "Maurice Paul Jacque" + "Paul" => true
 *   "Paul Jacque Maurice" + "Paul" => true
 *   "Pauline" + "Paul" => false
 */
function tag_occurs_inside(string $containerTag, string $knownTag): bool
{
    $container = canonical_tag_key($containerTag);
    $known = canonical_tag_key($knownTag);

    if ($container === '' || $known === '') return false;
    if ($container === $known) return false;

    return preg_match(
        '/(?:^| )' . preg_quote($known, '/') . '(?: |$)/u',
        $container
    ) === 1;
}

/**
 * Retourne true si un tag connu apparaît comme mot/séquence de mots dans
 * le titre/nom de fichier, sans dépendre des parenthèses.
 *
 * Exemple :
 *   "The Paul Jacque Story" + "Paul" => true
 *   "The Paul-Jacque Story" + "Paul Jacque" => true
 *   "Pauline Story" + "Paul" => false
 */
function tag_occurs_in_title(string $title, string $knownTag): bool
{
    $titleKey = canonical_tag_key($title);
    $knownKey = canonical_tag_key($knownTag);

    if ($titleKey === '' || $knownKey === '') return false;

    return preg_match(
        '/(?:^| )' . preg_quote($knownKey, '/') . '(?: |$)/u',
        $titleKey
    ) === 1;
}

/**
 * Construit les suggestions à partir des tags actuellement présents entre
 * parenthèses dans les noms de fichiers.
 *
 * Le registre global fournit les tags connus. Une vidéo peut donc recevoir
 * une suggestion même si elle possède déjà d'autres tags.
 */
function get_tag_suggestions(array $videos): array
{
    // Avant toute recherche, on apprend tous les tags explicitement écrits
    // entre parenthèses dans les fichiers actuellement présents.
    $discovered = [];
    foreach ($videos as $video) {
        $discovered = array_merge($discovered, extract_tags($video['filename'] ?? ''));
    }
    register_tags($discovered);

    $knownTags = array_keys(load_tag_registry());
    $stored = load_tags_store();
    $decisions = load_tag_suggestions_store();
    $suggestions = [];

    foreach ($videos as $id => $video) {
        $assigned = isset($stored[$id]) && is_array($stored[$id])
            ? normalize_tag_list($stored[$id])
            : normalize_tag_list(extract_tags($video['filename'] ?? ''));

        $assignedKeys = [];
        foreach ($assigned as $tag) {
            $assignedKeys[canonical_tag_key($tag)] = true;
        }

        $rawTags = extract_tags($video['filename'] ?? '');
        $matches = [];
        $matchKeys = [];

        /*
         * 1. Correspondances dans les tags entre parenthèses.
         */
        foreach ($rawTags as $rawTag) {
            foreach ($knownTags as $knownTag) {
                $knownKey = canonical_tag_key($knownTag);
                if ($knownKey === '' || isset($assignedKeys[$knownKey])) continue;
                if (!tag_occurs_inside($rawTag, $knownTag)) continue;

                if (isset($matchKeys[$knownKey])) continue;

                $decision = $decisions[$id] ?? null;
                $decisionEntry = null;
                if (is_array($decision)) {
                    if (isset($decision['suggestions']) && is_array($decision['suggestions'])) {
                        $decisionEntry = $decision['suggestions'][$knownKey] ?? null;
                    } elseif (isset($decision['status'])) {
                        $decisionEntry = null;
                    }
                }

                if (is_array($decisionEntry) && in_array(($decisionEntry['status'] ?? ''), ['ignored', 'accepted'], true)) {
                    continue;
                }

                $matches[] = $knownTag;
                $matchKeys[$knownKey] = true;
            }
        }

        /*
         * 2. Nouvelle détection : pour une vidéo qui n'a actuellement aucun
         * tag attribué, recherche aussi les tags connus directement dans son
         * titre/nom de fichier, même s'ils ne sont PAS entre parenthèses.
         *
         * Exemple :
         *   Tag connu : Paul
         *   Vidéo : "The Paul Jacque Story.mp4"
         *   => suggestion : Paul
         *
         * Les tags déjà attribués ne sont jamais reproposés.
         */
        if (count($assigned) === 0) {
            $titleForSearch = $video['filename'] ?? ($video['title'] ?? '');

            foreach ($knownTags as $knownTag) {
                $knownKey = canonical_tag_key($knownTag);
                if ($knownKey === '' || isset($assignedKeys[$knownKey]) || isset($matchKeys[$knownKey])) continue;
                if (!tag_occurs_in_title($titleForSearch, $knownTag)) continue;

                $decision = $decisions[$id] ?? null;
                $decisionEntry = null;
                if (is_array($decision) && isset($decision['suggestions']) && is_array($decision['suggestions'])) {
                    $decisionEntry = $decision['suggestions'][$knownKey] ?? null;
                }

                if (is_array($decisionEntry) && in_array(($decisionEntry['status'] ?? ''), ['ignored', 'accepted'], true)) {
                    continue;
                }

                $matches[] = $knownTag;
                $matchKeys[$knownKey] = true;
            }
        }

        if (!$matches) continue;

        $suggestions[] = [
            'id' => $id,
            'title' => $video['title'],
            'filename' => $video['filename'],
            'mtime' => $video['mtime'],
            'date_h' => $video['date_h'],
            'existing_tags' => $assigned,
            'suggested_tags' => normalize_tag_list($matches),
        ];
    }

    usort($suggestions, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $suggestions;
}

/**
 * Accepte ou refuse un ou plusieurs tags suggérés pour une vidéo.
 * Les tags ajoutés deviennent immédiatement des tags réels de la vidéo.
 */
function save_tag_decision(string $videoId, string $status, array $tags = []): array
{
    $videos = get_index();
    if (!isset($videos[$videoId])) throw new RuntimeException('Vidéo introuvable.');
    if (!in_array($status, ['ignored', 'accepted'], true)) throw new RuntimeException('Décision invalide.');

    $cleanTags = normalize_tag_list($tags);
    $decisions = load_tag_suggestions_store();
    if (!isset($decisions[$videoId]) || !is_array($decisions[$videoId])) {
        $decisions[$videoId] = ['suggestions' => []];
    }
    if (!isset($decisions[$videoId]['suggestions']) || !is_array($decisions[$videoId]['suggestions'])) {
        $decisions[$videoId]['suggestions'] = [];
    }

    $now = date('c');
    foreach ($cleanTags as $tag) {
        $key = canonical_tag_key($tag);
        if ($key === '') continue;
        $decisions[$videoId]['suggestions'][$key] = [
            'tag' => $tag,
            'status' => $status,
            'updated_at' => $now,
        ];
    }

    if ($status === 'accepted' && $cleanTags) {
        $stored = load_tags_store();
        $current = isset($stored[$videoId]) && is_array($stored[$videoId])
            ? $stored[$videoId]
            : extract_tags($videos[$videoId]['filename'] ?? '');

        $stored[$videoId] = normalize_tag_list(array_merge($current, $cleanTags));
        register_tags($cleanTags);

        if (!save_json_store(TAGS_FILE, $stored)) {
            throw new RuntimeException('Impossible d’enregistrer les tags.');
        }
    }

    if (!save_json_store(TAG_SUGGESTIONS_FILE, $decisions)) {
        throw new RuntimeException('Impossible d’enregistrer la décision.');
    }

    $indexCache = CACHE_DIR . '/index.json';
    if (is_file($indexCache)) @unlink($indexCache);

    return ['status' => $status, 'tags' => $cleanTags];
}

/**
 * Synchronise tags.json avec les vidéos présentes.
 *
 * - Les liens vidéo -> tags sont supprimés lorsque la vidéo disparaît.
 * - Le registre global tag_registry.json n'est jamais nettoyé.
 * - Une vidéo déjà connue conserve ses tags historiques.
 * - Une nouvelle vidéo reçoit les tags explicites de son nom.
 */
function sync_video_tags(array $videos): array
{
    $stored = load_tags_store();
    $next = [];
    $discovered = [];

    foreach ($videos as $id => $video) {
        $rawTags = extract_tags($video['filename'] ?? '');
        $discovered = array_merge($discovered, $rawTags);

        if (isset($stored[$id]) && is_array($stored[$id])) {
            // Aucun tag historique n'est supprimé ou remplacé automatiquement.
            $next[$id] = normalize_tag_list($stored[$id]);
        } else {
            // Première apparition : les tags écrits dans le nom deviennent
            // les tags de la vidéo.
            $next[$id] = normalize_tag_list($rawTags);
        }
    }

    // Le registre apprend de tous les tags explicitement présents dans les
    // noms actuels et des tags historiques encore conservés.
    foreach ($stored as $tags) {
        if (is_array($tags)) $discovered = array_merge($discovered, $tags);
    }
    foreach ($next as $tags) {
        if (is_array($tags)) $discovered = array_merge($discovered, $tags);
    }
    register_tags($discovered);

    // Nettoyage uniquement des liens liés aux vidéos disparues.
    // Le registre global des tags n'est jamais nettoyé.
    if ($next !== $stored) {
        save_json_store(TAGS_FILE, $next);
    }

    // Les décisions vidéo -> tag peuvent elles aussi être nettoyées lorsque
    // la vidéo disparaît. Une vidéo supprimée puis réimportée pourra donc
    // recevoir à nouveau une suggestion.
    $decisions = load_tag_suggestions_store();
    $cleanDecisions = [];
    foreach ($decisions as $videoId => $decision) {
        if (isset($videos[$videoId])) {
            $cleanDecisions[$videoId] = $decision;
        }
    }
    if ($cleanDecisions !== $decisions) {
        save_json_store(TAG_SUGGESTIONS_FILE, $cleanDecisions);
    }

    return $next;
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
 * () (Quartz) - The Amber Sequence - (Lumen Vale) - (Orchid) - ()
 * => ["Quartz", "Lumen Vale", "Orchid"]
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
