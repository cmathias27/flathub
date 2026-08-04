<?php
/**
 * Configuration centrale de l'application.
 * Les dossiers vidéo sont toujours lus, jamais écrits par l'application.
 */

define('LIBRARY_DIR', '/media/library');
// Dossier supplémentaire "à traiter" : il est indexé comme une seconde source.
define('PROCESSING_DIR', '/media/video');

// Sources vidéo. La première conserve les IDs historiques pour ne pas perdre les notes existantes.
define('VIDEO_SOURCES', [
    ['key' => 'library', 'label' => 'Bibliothèque', 'dir' => LIBRARY_DIR],
    ['key' => 'processing', 'label' => 'À traiter', 'dir' => PROCESSING_DIR],
]);

define('CACHE_DIR', __DIR__ . '/cache/thumbs');
define('RATINGS_DIR', __DIR__ . '/data');
define('RATINGS_FILE', RATINGS_DIR . '/ratings.json');
define('VIDEO_EXTENSIONS', ['mp4', 'webm', 'mkv', 'mov', 'avi', 'm4v']);
define('FFMPEG_BIN', 'ffmpeg');
define('FFPROBE_BIN', 'ffprobe');

if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0775, true);
