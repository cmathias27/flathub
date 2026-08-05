# Médiathèque – lecteur vidéo style YouTube

## Installation

1. Dépose tout le contenu de ce dossier sur ton serveur web PHP (Apache/Nginx + PHP-FPM, ou même `php -S` pour tester).
2. Vérifie que **ffmpeg** et **ffprobe** sont installés et accessibles dans le `PATH` du serveur (utilisés uniquement pour lire les durées et générer les miniatures — jamais pour modifier tes fichiers).
3. Vérifie que `/media/library` et `/media/video` sont lisibles par l'utilisateur système qui exécute PHP (ex: `www-data`). Aucune écriture n'est jamais effectuée dans ces deux dossiers. Le dossier `/media/video` est la source « À traiter ».
4. Le dossier `cache/thumbs/` doit être accessible en écriture par PHP (c'est le seul dossier où l'appli écrit : miniatures `.jpg` et durées `.duration` mises en cache).

## Test rapide en local

```bash
php -S 127.0.0.1:8000
```
Puis ouvre `http://127.0.0.1:8000/index.html`.

## Structure

```
index.html          Page principale (grille + lecteur)
style.css            Thème sombre façon YouTube
app.js               Logique front (fetch API, navigation grille/lecteur)
config.php           Chemin de /media/library, extensions, dossier de cache
lib/videos.php        Scan du dossier (lecture seule), métadonnées, tri
api/videos.php        Endpoint JSON : liste des vidéos triées par date desc
api/thumb.php          Génère/sert une miniature (cache dans cache/thumbs/)
api/stream.php          Sert la vidéo en streaming avec support Range (seek)
cache/thumbs/         Cache des miniatures + durées + index vidéo (généré automatiquement)
data/tags.json        Tags persistants associés aux IDs des vidéos
```

## Fonctionnement

- `/media/library` et `/media/video` sont scannés par `api/videos.php` et réunis dans une seule médiathèque, triés par date de modification décroissante (le plus récent en premier).
- `/media/video` apparaît comme source « À traiter » et est soumis aux mêmes fonctions de lecture, notation, Discover et nettoyage par rating.
- Les sous-dossiers sont parcourus récursivement.
- Les formats reconnus par défaut : mp4, webm, mkv, mov, avi, m4v (modifiable dans `config.php`).
- Aucun fichier de `/media/library` ou `/media/video` n'est modifié ou renommé. La page de nettoyage peut supprimer définitivement les vidéos notées sous 3 étoiles dans l'un ou l'autre dossier après confirmation.

## Nettoyage par notation

La page `delete.html` permet de préparer la suppression des vidéos dont la note moyenne est **strictement inférieure à 3/5**.

- Les vidéos sans note ne sont pas proposées à la suppression.
- Chaque vidéo est prévisualisable directement avant suppression.
- Suppression unitaire ou en masse avec cases à cocher.
- Une confirmation finale est demandée avant toute suppression.
- L'API `api/deletions.php` refait la vérification du rating côté serveur avant de supprimer le fichier.
- La suppression est définitive dans `/media/library`.
- Les caches associés et la note de la vidéo supprimée sont également nettoyés.

## Tags persistants

Les tags sont stockés dans `data/tags.json`, avec l'ID de chaque vidéo comme clé. Lorsqu'une vidéo apparaît pour la première fois, les tags entre parenthèses de son nom de fichier sont extraits et enregistrés. Les tags déjà présents dans `tags.json` sont ensuite conservés, même si le nom du fichier change. Les entrées des vidéos qui ne sont plus présentes dans la bibliothèque sont nettoyées lors du prochain scan.
