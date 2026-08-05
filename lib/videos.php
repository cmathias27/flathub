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

    return $videos;
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
