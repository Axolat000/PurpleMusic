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

        // Renommer et supprimer restent au createur ; ajouter et retirer des morceaux
        // s'ouvrent aux collaborateurs. Un collaborateur contribue au contenu, il ne
        // dispose pas de l'objet.
        $isOwner = $curr && ($auth['is_admin'] || $curr['creator_id'] == $auth['id']);
        $canEditContent = $curr && can_edit_playlist_content($db, $auth, $curr['creator_id'], $pid);
        $needsOwner = in_array($mode, ['delete', 'rename'], true);

        if($curr && ($needsOwner ? $isOwner : $canEditContent)) {
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
        $stmt = $db->prepare("SELECT id, filename, title, artist, album, cover, genre, play_count, duration FROM tracks WHERE id IN ($placeholders)");
        $stmt->execute(array_values($ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Remise dans l'ordre de song_ids. `WHERE id IN (...)` ne garantit AUCUN
        // ordre (SQLite renvoie en pratique l'ordre de la clé primaire) : l'ordre
        // choisi par l'utilisateur dans sa playlist était donc ignoré et les
        // morceaux se lisaient par identifiant croissant. Le réordonnancement se
        // fait ici plutôt qu'en SQL (un CASE ... WHEN sur N identifiants) pour
        // rester lisible et indépendant du moteur.
        $byId = [];
        foreach ($rows as $r) { $byId[(int) $r['id']] = $r; }
        $ordered = [];
        foreach ($ids as $id) {
            // Un identifiant peut manquer si la piste a été supprimée depuis :
            // on l'ignore au lieu d'insérer un trou dans la liste.
            if (isset($byId[$id])) $ordered[] = $byId[$id];
        }
        echo json_encode($ordered);
        break;

    // Enregistre un nouvel ordre de pistes (glisser-déposer dans le détail d'une
    // playlist). Distinct de playlist_save : celui-ci réécrit aussi le nom, la
    // pochette et la visibilité, ce qui obligerait le client à tout renvoyer pour
    // un simple déplacement de ligne.
    case 'playlist_reorder':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) { echo json_encode(["status" => "error", "message" => "ID de playlist invalide"]); exit; }

        $stmt = $db->prepare("SELECT song_ids, creator_id FROM playlists WHERE id = ?");
        $stmt->execute([$pid]);
        $curr = $stmt->fetch();
        // Reordonner, c'est modifier le contenu : ouvert aux collaborateurs. Changer
        // la visibilite ou le nom, non -- ces actions gardent la comparaison directe
        // au creator_id.
        if (!$curr || !can_edit_playlist_content($db, $auth, $curr['creator_id'], $pid)) {
            echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette playlist"]); exit;
        }

        $newIds = array_values(array_filter(array_map('intval', explode(',', $_POST['song_ids'] ?? '')), fn($v) => $v > 0));
        $oldIds = array_values(array_filter(array_map('intval', explode(',', (string) $curr['song_ids'])), fn($v) => $v > 0));

        // Un réordonnancement ne doit QUE permuter : on refuse une liste qui
        // ajoute ou retire des morceaux. Sans cette vérification, cet endpoint
        // permettrait de réécrire entièrement le contenu d'une playlist en
        // contournant playlist_save (et sa validation d'appartenance des pistes).
        sort($newIds);
        $oldSorted = $oldIds;
        sort($oldSorted);
        if ($newIds !== $oldSorted) {
            echo json_encode(["status" => "error", "message" => "La liste ne correspond pas au contenu de la playlist"]); exit;
        }

        $submitted = array_values(array_filter(array_map('intval', explode(',', $_POST['song_ids'] ?? '')), fn($v) => $v > 0));
        $db->prepare("UPDATE playlists SET song_ids = ? WHERE id = ?")->execute([implode(',', $submitted), $pid]);
        echo json_encode(['status' => 'success']);
        break;

    // Bascule public/privé sans passer par la modale d'édition complète.
    case 'playlist_toggle_visibility':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($pid === false || $pid <= 0) { echo json_encode(["status" => "error", "message" => "ID de playlist invalide"]); exit; }

        $stmt = $db->prepare("SELECT is_private, creator_id FROM playlists WHERE id = ?");
        $stmt->execute([$pid]);
        $curr = $stmt->fetch();
        if (!$curr || !($auth['is_admin'] || $curr['creator_id'] == $auth['id'])) {
            echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette playlist"]); exit;
        }

        $next = empty($curr['is_private']) ? 1 : 0;
        $db->prepare("UPDATE playlists SET is_private = ? WHERE id = ?")->execute([$next, $pid]);
        echo json_encode(['status' => 'success', 'is_private' => $next]);
        break;


    // Lien de partage en lecture seule.
    //
    // Le jeton est aleatoire et independant de l'identifiant de la playlist : sans
    // ca, il suffirait d'incrementer un numero dans l'URL pour tomber sur les
    // playlists des autres. Il est aussi revocable -- "revoke" invalide
    // instantanement tous les liens deja envoyes, ce qu'un identifiant ne permet
    // evidemment pas.
    //
    // Le partage est un acte EXPLICITE du proprietaire : il rend la playlist
    // lisible par quiconque a le lien, y compris si elle est marquee privee. C'est
    // le sens meme du bouton, et c'est dit dans l'interface.
    case 'playlist_share':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$pid || $pid <= 0) { echo json_encode(["status" => "error", "message" => "Playlist invalide."]); exit; }

        $stmt = $db->prepare("SELECT creator_id, share_token FROM playlists WHERE id = ?");
        $stmt->execute([$pid]);
        $pl = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pl) { echo json_encode(["status" => "error", "message" => "Playlist introuvable."]); exit; }
        // Seul le createur (ou un admin) partage : un lien de partage engage la
        // playlist de quelqu'un d'autre, ce n'est pas une action de lecteur.
        if (!$auth['is_admin'] && (int) $pl['creator_id'] !== (int) $auth['id']) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit;
        }

        $mode = $_POST['mode'] ?? 'create';
        if ($mode === 'revoke') {
            $db->prepare("UPDATE playlists SET share_token = NULL WHERE id = ?")->execute([$pid]);
            echo json_encode(['status' => 'success', 'token' => null]);
            break;
        }

        // 32 caracteres hexadecimaux (128 bits) : un lien de partage doit etre
        // indevinable, pas court.
        $token = $pl['share_token'] ?: bin2hex(random_bytes(16));
        $db->prepare("UPDATE playlists SET share_token = ? WHERE id = ?")->execute([$token, $pid]);
        echo json_encode(['status' => 'success', 'token' => $token]);
        break;


    // Generation d'une playlist par filtre : "tout le rock ajoute ce mois-ci".
    //
    // Le filtrage est fait par le SERVEUR et non par le navigateur, pour la meme
    // raison que la recherche (voir api/search.php) : c'est la seule facon d'ecrire
    // les regles une fois. Un filtrage cote client aurait redonne un second jeu de
    // regles a garder aligne, exactement le piege deja rencontre avec le decoupage
    // des genres.
    //
    // dry_run=1 renvoie seulement le nombre de correspondances : c'est ce qui permet
    // a l'interface d'annoncer "142 morceaux" AVANT de creer quoi que ce soit. Creer
    // puis constater est le comportement qu'on veut eviter -- une playlist vide ou de
    // 3000 titres est desagreable a defaire.
    case 'playlist_generate':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $uid = (int) $auth['id'];

        $dryRun = !empty($_POST['dry_run']);
        $name = sanitize_text($_POST['name'] ?? '');
        $genre = trim((string) ($_POST['genre'] ?? ''));
        $artist = trim((string) ($_POST['artist'] ?? ''));
        $days = filter_var($_POST['days'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $minPlays = filter_var($_POST['min_plays'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $likedOnly = !empty($_POST['liked_only']);
        $sort = (string) ($_POST['sort'] ?? 'recent');
        // Borne haute : une playlist de plusieurs milliers de titres n'est plus une
        // playlist, et song_ids est une simple chaine en base.
        $limit = min(500, max(1, filter_var($_POST['limit'] ?? 100, FILTER_VALIDATE_INT) ?: 100));

        $where = ['1=1'];
        $params = [];

        if ($days > 0) {
            // upload_date est un DATETIME texte ('YYYY-MM-DD HH:MM:SS') : la
            // comparaison se fait donc en SQL sur la meme forme, pas sur un
            // timestamp qui n'existe pas dans cette colonne.
            $where[] = "tracks.upload_date >= datetime('now', ?)";
            $params[] = '-' . $days . ' days';
        }
        if ($minPlays > 0) {
            // CAST explicite : PDO lie ce parametre comme du TEXTE, et COALESCE()
            // renvoie une valeur SANS affinite de colonne -- SQLite comparait donc un
            // entier a une chaine, ce qui est toujours faux chez lui (les entiers
            // trient avant le texte). Le filtre ne remontait rien, en silence.
            $where[] = "COALESCE(tracks.play_count, 0) >= CAST(? AS INTEGER)";
            $params[] = $minPlays;
        }
        if ($likedOnly) {
            $where[] = "EXISTS (SELECT 1 FROM likes WHERE likes.track_id = tracks.id AND likes.user_id = ?)";
            $params[] = $uid;
        }
        // Genre et artiste sont pre-filtres en SQL (LIKE large) puis VERIFIES en PHP :
        // les deux champs contiennent des listes ("Phonk, Nightcore", "A & B") que
        // seul split_genres()/split_artist_names() sait decouper. Un LIKE seul
        // rangerait "Post-Rock" sous "Rock" et "Bob Marley" sous "Marley".
        if ($genre !== '') {
            $where[] = "LOWER(COALESCE(tracks.genre, '')) LIKE ?";
            $params[] = '%' . mb_strtolower($genre) . '%';
        }
        if ($artist !== '') {
            $where[] = "LOWER(tracks.artist) LIKE ?";
            $params[] = '%' . mb_strtolower($artist) . '%';
        }

        $orderBy = [
            'recent'  => 'tracks.id DESC',
            'oldest'  => 'tracks.id ASC',
            'popular' => 'tracks.play_count DESC, tracks.id DESC',
            'random'  => 'RANDOM()',
            'alpha'   => 'tracks.title COLLATE NOCASE ASC',
        ][$sort] ?? 'tracks.id DESC';

        $sql = "SELECT tracks.id, tracks.genre, tracks.artist FROM tracks WHERE " . implode(' AND ', $where) . " ORDER BY $orderBy";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $ids = [];
        $needleGenre = mb_strtolower($genre);
        $needleArtist = mb_strtolower($artist);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($genre !== '') {
                $match = false;
                foreach (split_genres($row['genre']) as $g) {
                    if (mb_strtolower($g) === $needleGenre) { $match = true; break; }
                }
                if (!$match) continue;
            }
            if ($artist !== '') {
                $match = false;
                foreach (split_artist_names($row['artist']) as $a) {
                    if (mb_strtolower($a) === $needleArtist) { $match = true; break; }
                }
                if (!$match) continue;
            }
            $ids[] = (int) $row['id'];
            if (count($ids) >= $limit) break;
        }

        if ($dryRun) {
            echo json_encode(['status' => 'success', 'count' => count($ids)]);
            break;
        }

        if (!$ids) { echo json_encode(["status" => "error", "message" => "Aucun morceau ne correspond."]); exit; }
        if ($name === '') { echo json_encode(["status" => "error", "message" => "Nom de playlist manquant."]); exit; }

        $isPrivate = !empty($_POST['is_private']) ? 1 : 0;
        $db->prepare("INSERT INTO playlists (name, creator_id, song_ids, is_private) VALUES (?, ?, ?, ?)")
           ->execute([$name, $uid, implode(',', $ids), $isPrivate]);

        echo json_encode([
            'status' => 'success',
            'playlist_id' => (int) $db->lastInsertId(),
            'count' => count($ids),
        ], JSON_UNESCAPED_UNICODE);
        break;


    // --- COLLABORATEURS D'UNE PLAYLIST ---------------------------------------
    //
    // Une table de liens plutot qu'un drapeau "ouverte a tous" : sur un serveur
    // partage entre amis, on veut ouvrir une playlist a trois personnes, pas a tous
    // les comptes existants.
    //
    // Seul le createur (ou un admin) gere la liste. Un collaborateur qui pourrait
    // en inviter d'autres ferait perdre au proprietaire le controle de sa playlist.

    case 'playlist_collab_list':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $pid = filter_var($_GET['q'] ?? 0, FILTER_VALIDATE_INT);
        if (!$pid || $pid <= 0) { echo json_encode(["status" => "error", "message" => "Playlist invalide."]); exit; }

        $st = $db->prepare("SELECT creator_id FROM playlists WHERE id = ?");
        $st->execute([$pid]);
        $creatorId = $st->fetchColumn();
        if ($creatorId === false) { echo json_encode(["status" => "error", "message" => "Playlist introuvable."]); exit; }
        if (!$auth['is_admin'] && (int) $creatorId !== (int) $auth['id']) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit;
        }

        $cols = $db->prepare(
            "SELECT u.id, u.username FROM playlist_collaborators c
             JOIN users u ON u.id = c.user_id
             WHERE c.playlist_id = ? ORDER BY u.username COLLATE NOCASE ASC"
        );
        $cols->execute([$pid]);
        $collaborators = $cols->fetchAll(PDO::FETCH_ASSOC);

        // Comptes invitables : tous sauf le createur et ceux deja invites. La liste
        // est nominative parce qu'inviter demande de choisir quelqu'un ; elle ne
        // revele rien de plus que ce que le Panel Admin montre deja.
        $candidates = $db->prepare(
            "SELECT id, username FROM users
             WHERE id <> ? AND id NOT IN (SELECT user_id FROM playlist_collaborators WHERE playlist_id = ?)
             ORDER BY username COLLATE NOCASE ASC"
        );
        $candidates->execute([(int) $creatorId, $pid]);

        foreach ($collaborators as &$c) { $c['id'] = (int) $c['id']; }
        unset($c);

        echo json_encode([
            'status' => 'success',
            'collaborators' => $collaborators,
            'candidates' => $candidates->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'playlist_collab_add':
    case 'playlist_collab_remove':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $pid = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);
        $uid = filter_var($_POST['user_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$pid || $pid <= 0 || !$uid || $uid <= 0) { echo json_encode(["status" => "error", "message" => "Paramètres invalides."]); exit; }

        $st = $db->prepare("SELECT creator_id FROM playlists WHERE id = ?");
        $st->execute([$pid]);
        $creatorId = $st->fetchColumn();
        if ($creatorId === false) { echo json_encode(["status" => "error", "message" => "Playlist introuvable."]); exit; }
        if (!$auth['is_admin'] && (int) $creatorId !== (int) $auth['id']) {
            echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit;
        }
        // Le createur est deja tout-puissant sur sa playlist : l'inscrire comme
        // collaborateur creerait une ligne sans effet, et un bouton "retirer" qui
        // laisserait croire qu'on peut lui enlever ses droits.
        if ((int) $uid === (int) $creatorId) { echo json_encode(["status" => "error", "message" => "Le créateur a déjà tous les droits."]); exit; }

        $exists = $db->prepare("SELECT 1 FROM users WHERE id = ?");
        $exists->execute([$uid]);
        if ($exists->fetchColumn() === false) { echo json_encode(["status" => "error", "message" => "Compte introuvable."]); exit; }

        if ($action === 'playlist_collab_add') {
            $db->prepare("INSERT OR IGNORE INTO playlist_collaborators (playlist_id, user_id, added_at) VALUES (?, ?, ?)")
               ->execute([$pid, $uid, time()]);
        } else {
            $db->prepare("DELETE FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?")->execute([$pid, $uid]);
        }

        echo json_encode(['status' => 'success']);
        break;

}
