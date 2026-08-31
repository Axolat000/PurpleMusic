<?php
/**
 * Import en masse depuis un dossier du serveur.
 *
 * L'envoi fichier par fichier depuis le navigateur reste le chemin normal. Il
 * devient absurde dès qu'on veut verser une discothèque entière : deux cents
 * fichiers, c'est deux cents formulaires, et le moindre onglet fermé perd tout.
 * Ici, on dépose les fichiers sur le serveur par le moyen qu'on veut (scp, rsync,
 * un montage réseau, un volume Docker), et l'app les adopte.
 *
 * DOSSIER SURVEILLÉ : music/_import. Un sous-dossier du dossier musique, et non
 * un chemin libre saisi dans l'interface — un chemin libre côté serveur serait une
 * lecture arbitraire du disque offerte à l'interface d'administration. Ici, aucun
 * paramètre de chemin ne vient du client : il est fixe, en dur, et tout ce que le
 * client peut nommer est un fichier À L'INTÉRIEUR de ce dossier, verrouillé par
 * basename() et par une revérification de son emplacement réel.
 *
 * Rien n'est jamais importé automatiquement. Le scan propose, un humain choisit.
 */

// Sous-dossier surveillé, relatif au dossier musique.
const IMPORT_SUBDIR = '_import';

function import_dir(string $musicDir): string
{
    return $musicDir . '/' . IMPORT_SUBDIR;
}

/**
 * Vérifie qu'un nom de fichier désigne bien un fichier du dossier d'import, et
 * renvoie son chemin réel. Renvoie null dans tous les autres cas.
 *
 * Double verrou : basename() écarte les séquences de remontée (../), et
 * realpath() confirme que le fichier résolu est réellement SOUS le dossier
 * d'import — ce qui neutralise aussi un lien symbolique qui pointerait ailleurs.
 */
function import_safe_path(string $musicDir, string $name): ?string
{
    $base = basename($name);
    if ($base === '' || $base === '.' || $base === '..') return null;

    $dir = realpath(import_dir($musicDir));
    if ($dir === false) return null;

    $full = realpath($dir . '/' . $base);
    if ($full === false || !is_file($full)) return null;
    if (strpos($full, $dir . DIRECTORY_SEPARATOR) !== 0) return null;

    return $full;
}

switch ($action) {

    // Liste les fichiers audio déposés, avec les métadonnées lues dans leurs tags.
    // Rien n'est écrit : ce scan ne fait que regarder.
    case 'import_scan':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $dir = import_dir($musicDir);
        if (!is_dir($dir)) {
            // Le dossier est créé au premier scan : l'administrateur n'a pas à
            // deviner son nom ni à le fabriquer à la main avant de pouvoir déposer.
            @mkdir($dir, 0755, true);
        }

        $allowed = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'opus', 'webm'];
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir . '/' . $name;
            if (!is_file($full)) continue;

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;
            if (!is_valid_audio($full, $ext)) continue;

            $meta = extractMp3Data($full);
            $files[] = [
                'file' => $name,
                'size' => filesize($full),
                // Le nom de fichier sert de titre de repli : un fichier sans tags
                // reste importable, il ne disparaît pas de la liste.
                'title' => $meta['title'] ?: pathinfo($name, PATHINFO_FILENAME),
                'artist' => $meta['artist'] ?: '',
                'album' => $meta['album'] ?: '',
                'has_cover' => !empty($meta['cover']),
                'duration' => calculateAudioDuration($full),
            ];
        }

        usort($files, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        echo json_encode([
            'status' => 'success',
            'folder' => 'music/' . IMPORT_SUBDIR,
            'files' => $files,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // Importe les fichiers choisis. Chaque fichier est traité indépendamment : un
    // fichier illisible au milieu du lot ne doit pas faire échouer les 199 autres,
    // il est signalé et on continue.
    case 'import_run':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $names = json_decode((string) ($_POST['files'] ?? ''), true);
        if (!is_array($names) || !$names) { echo json_encode(["status" => "error", "message" => "Aucun fichier sélectionné."]); exit; }
        // Borne par lot : l'interface découpe les grosses sélections, ce qui évite
        // qu'une requête unique dépasse le temps d'exécution PHP et laisse
        // l'import à moitié fait sans que personne sache où il s'est arrêté.
        if (count($names) > 50) { echo json_encode(["status" => "error", "message" => "Trop de fichiers en une fois (50 max)."]); exit; }

        $defaultGenre = sanitize_text($_POST['genre'] ?? 'Autre', 50) ?: 'Autre';

        $imported = [];
        $failed = [];
        foreach ($names as $name) {
            $src = import_safe_path($musicDir, (string) $name);
            if (!$src) { $failed[] = ['file' => (string) $name, 'reason' => 'introuvable']; continue; }

            $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
            if (!is_valid_audio($src, $ext)) { $failed[] = ['file' => basename($src), 'reason' => 'format invalide']; continue; }
            if (filesize($src) > MAX_AUDIO_SIZE) { $failed[] = ['file' => basename($src), 'reason' => 'trop volumineux']; continue; }

            $meta = extractMp3Data($src);
            $title = sanitize_text($meta['title'] ?: pathinfo($src, PATHINFO_FILENAME));
            $artist = sanitize_text($meta['artist'] ?: 'Inconnu');
            $album = $meta['album'] ? sanitize_text($meta['album']) : null;
            $duration = calculateAudioDuration($src);

            // Nom de stockage aléatoire, comme à l'upload : deux fichiers déposés
            // avec le même nom ne doivent pas s'écraser, et le nom d'origine ne doit
            // pas se retrouver dans une URL publique.
            $fn = bin2hex(random_bytes(8)) . '.' . $ext;
            // rename() et non copy() : le fichier QUITTE le dossier d'import, donc un
            // second scan ne le repropose pas et on ne double pas l'espace disque.
            if (!@rename($src, $musicDir . '/' . $fn)) {
                $failed[] = ['file' => basename($src), 'reason' => 'deplacement impossible'];
                continue;
            }

            // Pochette embarquée : extraite et optimisée comme à l'upload.
            $cover = 'default.png';
            if (!empty($meta['cover']['data'])) {
                $tmp = $musicDir . '/' . $fn . '.coverdata';
                if (@file_put_contents($tmp, $meta['cover']['data']) !== false) {
                    $candidate = bin2hex(random_bytes(8)) . '_import.webp';
                    if (optimizeImage($tmp, $coverDir . '/' . $candidate, $meta['cover']['mime'])) $cover = $candidate;
                    @unlink($tmp);
                }
            }

            $albumId = resolve_album($db, $album, $artist, $cover);
            $db->prepare("INSERT INTO tracks (filename, title, artist, album, album_id, cover, genre, uploader_id, duration) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$fn, $title, $artist, $album, $albumId, $cover, $defaultGenre, $auth['id'], $duration]);
            register_genre($db, $defaultGenre);
            require_once __DIR__ . '/push.php';
            push_notify_new_track($db, ['artist' => $artist, 'title' => $title]);

            $imported[] = ['file' => basename($src), 'title' => $title, 'artist' => $artist];
        }

        if ($imported) {
            log_admin_action($db, $auth, 'import_run', count($imported) . ' piste(s)', $failed ? (count($failed) . ' echec(s)') : null);
        }

        echo json_encode([
            'status' => 'success',
            'imported' => $imported,
            'failed' => $failed,
        ], JSON_UNESCAPED_UNICODE);
        break;
}
