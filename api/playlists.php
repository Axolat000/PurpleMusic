<?php
switch ($action) {
    case 'playlists':
        // Auth optionnelle : authenticate_api_user() renvoie simplement false si les identifiants sont
        // absents/invalides (elle ne die() jamais) -- l'app Android n'envoie aujourd'hui aucun identifiant
        // sur cet appel, donc reste anonyme, et ne doit voir QUE les playlists publiques (jamais les
        // privées de qui que ce soit, quel que soit le client).
        $playlistsAuth = authenticate_api_user($db);
        if ($playlistsAuth && $playlistsAuth['is_admin']) {
            $rows = $db->query("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id")->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($playlistsAuth) {
            $stmt = $db->prepare("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id WHERE p.is_private = 0 OR p.creator_id = ?");
            $stmt->execute([$playlistsAuth['id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $db->query("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id WHERE p.is_private = 0")->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode($rows);
        break;

    case 'playlist_create':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $playlistName = sanitize_text($_POST['name'] ?? 'Playlist', 100);
        $db->prepare("INSERT INTO playlists (name, creator_id, song_ids) VALUES (?, ?, '')")->execute([$playlistName, $auth['id']]);
        echo json_encode(["status" => "success"]);
        break;

    case 'playlist_mod':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) { echo json_encode(["status" => "error", "message" => "ID de playlist invalide"]); exit; }

        $mode = $_POST['mode'] ?? '';
        $p = $db->prepare("SELECT song_ids, creator_id FROM playlists WHERE id=?"); $p->execute([$pid]); $curr = $p->fetch();

        if($curr && ($auth['is_admin'] || $curr['creator_id'] == $auth['id'])) {
            if ($mode === 'delete') {
                $db->prepare("DELETE FROM playlists WHERE id=?")->execute([$pid]);
            } elseif ($mode === 'rename') {
                $newName = sanitize_text($_POST['new_name'] ?? 'Playlist', 100);
                $db->prepare("UPDATE playlists SET name=? WHERE id=?")->execute([$newName, $pid]);
            } else {
                // --- SÉCURITÉ : Validation stricte des song_ids (entiers positifs uniquement) ---
                $rawIds = array_filter(explode(',', $curr['song_ids']));
                $ids = array_filter(array_map('intval', $rawIds), fn($v) => $v > 0);

                $targetId = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
                if ($targetId === false || $targetId <= 0) {
                    echo json_encode(["status" => "error", "message" => "ID de piste invalide"]); exit;
                }

                if ($mode === 'add' && !in_array($targetId, $ids)) $ids[] = $targetId;
                if ($mode === 'remove') $ids = array_values(array_diff($ids, [$targetId]));

                $db->prepare("UPDATE playlists SET song_ids=? WHERE id=?")->execute([implode(',', $ids), $pid]);
            }
            echo json_encode(["status" => "success"]);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette playlist"]);
        break;

    // --- Actions ci-dessous : migrées depuis actions.php/index.php (fusion des deux backends web/API sur
    // api.php) -- authentifiées via authenticate_api_user() comme le reste de ce fichier (session pour le
    // site web, username+password pour Android), CSRF vérifié automatiquement pour tout POST authentifié
    // par session (voir authenticate_api_user()). ---

    case 'delete_playlist':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) { echo json_encode(["status" => "error", "message" => "ID de playlist invalide"]); exit; }

        $stmt = $db->prepare("SELECT cover, creator_id FROM playlists WHERE id = ? AND (creator_id = ? OR ?)");
        $stmt->execute([$pid, $auth['id'], $auth['is_admin'] ? 1 : 0]);
        $pl = $stmt->fetch();
        if ($pl) {
            $safeCoverFile = basename((string) $pl['cover']);
            if (!empty($safeCoverFile) && file_exists($coverDir . '/' . $safeCoverFile)) unlink($coverDir . '/' . $safeCoverFile);
            $db->prepare("DELETE FROM playlists WHERE id = ?")->execute([$pid]);
            echo json_encode(['status' => 'success']);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette playlist"]);
        break;

    // Sauvegarde "en bloc" (nom + cover + liste complète de morceaux + is_private en un seul appel) --
    // utilisée par la modale playlist du site web (création ET édition). Distincte de playlist_create/
    // playlist_mod ci-dessus (flux incrémental utilisé par Android : créer vide, puis add/remove un
    // morceau à la fois) pour ne rien changer au contrat Android existant.
    case 'playlist_save':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $cleanIds = isset($_POST['selected_songs']) ? array_filter(array_map('intval', (array) $_POST['selected_songs']), fn($v) => $v > 0) : [];
        $songIds = implode(',', $cleanIds);
        $playlistName = sanitize_text($_POST['playlist_name'] ?? 'Playlist', 100);
        $playlistId = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        $isPrivate = !empty($_POST['is_private']) ? 1 : 0;

        $coverName = null;
        if ($playlistId) {
            $stmt = $db->prepare("SELECT cover FROM playlists WHERE id = ? AND (creator_id = ? OR ?)");
            $stmt->execute([$playlistId, $auth['id'], $auth['is_admin'] ? 1 : 0]);
            $currPlaylist = $stmt->fetch();
            if ($currPlaylist === false) { echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette playlist"]); exit; }
            $coverName = $currPlaylist['cover'];
        }

        if (!empty($_FILES['playlist_cover']['name'])) {
            if ($_FILES['playlist_cover']['size'] > MAX_IMAGE_SIZE) { echo json_encode(["status" => "error", "message" => "Image trop volumineuse"]); exit; }
            $imgExt = strtolower(pathinfo($_FILES['playlist_cover']['name'], PATHINFO_EXTENSION));
            if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                $coverName = bin2hex(random_bytes(8)) . '.webp';
                optimizeImage($_FILES['playlist_cover']['tmp_name'], $coverDir . '/' . $coverName);
            }
        }

        if ($playlistId) {
            $db->prepare("UPDATE playlists SET name = ?, song_ids = ?, cover = ?, is_private = ? WHERE id = ?")->execute([$playlistName, $songIds, $coverName, $isPrivate, $playlistId]);
        } else {
            $db->prepare("INSERT INTO playlists (name, creator_id, song_ids, cover, is_private) VALUES (?, ?, ?, ?, ?)")->execute([$playlistName, $auth['id'], $songIds, $coverName, $isPrivate]);
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'get_playlist_tracks':
        $ids = array_filter(array_map('intval', explode(',', $_GET['q'] ?? '')), fn($v) => $v > 0);
        if (empty($ids)) { echo json_encode([]); break; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, filename, title, artist, cover, genre, play_count, duration FROM tracks WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

}
