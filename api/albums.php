<?php
/**
 * Albums : lecture publique (pour l'app), écriture réservée aux admins.
 *
 * Voir migrations.php pour le modèle : une ligne par album, `tracks.album_id` qui
 * pointe dessus, et `tracks.album` (le texte) conservé et tenu synchronisé parce
 * que c'est ce que l'app Android lit depuis toujours dans action=list.
 *
 * Toute écriture qui change le nom d'un album met donc à jour LES DEUX. C'est la
 * règle qui évite qu'un album renommé ici reste affiché sous son ancien nom sur
 * les téléphones.
 */

switch ($action) {

    // Liste des albums avec leur nombre de pistes. La pochette de l'album prime,
    // sinon celle de sa piste la plus écoutée : un album fraîchement créé n'a pas
    // encore de pochette propre et ne doit pas s'afficher vide pour autant.
    case 'albums':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $rows = $db->query(
            "SELECT a.id, a.name, a.artist, a.year,
                    COALESCE(a.cover, (SELECT t2.cover FROM tracks t2 WHERE t2.album_id = a.id ORDER BY t2.play_count DESC, t2.id DESC LIMIT 1)) AS cover,
                    (SELECT COUNT(*) FROM tracks t WHERE t.album_id = a.id) AS track_count,
                    (SELECT SUM(COALESCE(t.duration, 0)) FROM tracks t WHERE t.album_id = a.id) AS total_duration
             FROM albums a
             ORDER BY a.name COLLATE NOCASE ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['track_count'] = (int) $r['track_count'];
            $r['total_duration'] = (int) $r['total_duration'];
            $r['year'] = $r['year'] !== null ? (int) $r['year'] : null;
        }
        unset($r);

        echo json_encode(['status' => 'success', 'albums' => $rows], JSON_UNESCAPED_UNICODE);
        break;

    // Création ou mise à jour d'un album (Panel Admin).
    case 'album_save':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $id = filter_var($_POST['album_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $name = sanitize_text($_POST['name'] ?? '');
        $artist = sanitize_text($_POST['artist'] ?? '');
        $yearRaw = trim((string) ($_POST['year'] ?? ''));
        // Une année vide efface la valeur ; une année absurde est refusée plutôt
        // que tronquée en silence.
        $year = null;
        if ($yearRaw !== '') {
            $year = filter_var($yearRaw, FILTER_VALIDATE_INT);
            if ($year === false || $year < 1900 || $year > (int) date('Y') + 1) {
                echo json_encode(["status" => "error", "message" => "Année invalide."]); exit;
            }
        }
        if ($name === '') { echo json_encode(["status" => "error", "message" => "Le nom de l'album est obligatoire."]); exit; }

        // Collision de nom : l'index unique la refuserait de toute façon, mais un
        // message clair vaut mieux qu'une erreur SQL remontée telle quelle.
        $clash = $db->prepare("SELECT id FROM albums WHERE name = ? COLLATE NOCASE AND id <> ?");
        $clash->execute([$name, $id]);
        if ($clash->fetchColumn() !== false) {
            echo json_encode(["status" => "error", "message" => "Un album porte déjà ce nom."]); exit;
        }

        $cover = null;
        if (!empty($_FILES['cover']['name'])) {
            if ($_FILES['cover']['size'] > MAX_IMAGE_SIZE) {
                echo json_encode(["status" => "error", "message" => "Image trop volumineuse (5 Mo max)"]); exit;
            }
            $imgExt = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                $cover = bin2hex(random_bytes(8)) . "_album.webp";
                if (!optimizeImage($_FILES['cover']['tmp_name'], $coverDir . '/' . $cover)) $cover = null;
            }
        }

        $db->beginTransaction();
        try {
            if ($id > 0) {
                $prev = $db->prepare("SELECT name, cover FROM albums WHERE id = ?");
                $prev->execute([$id]);
                $before = $prev->fetch(PDO::FETCH_ASSOC);
                if (!$before) { $db->rollBack(); echo json_encode(["status" => "error", "message" => "Album introuvable."]); exit; }

                $sets = ["name = ?", "artist = ?", "year = ?"];
                $params = [$name, $artist, $year];
                if ($cover !== null) { $sets[] = "cover = ?"; $params[] = $cover; }
                $params[] = $id;
                $db->prepare("UPDATE albums SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

                // Le texte recopié sur les pistes suit le renommage : sans ça, l'app
                // Android continuerait d'afficher l'ancien nom d'album indéfiniment.
                if ($before['name'] !== $name) {
                    $db->prepare("UPDATE tracks SET album = ? WHERE album_id = ?")->execute([$name, $id]);
                }
                // L'ancienne pochette d'album n'est supprimée qu'une fois la nouvelle
                // écrite, et jamais si elle est encore utilisée comme pochette de piste.
                if ($cover !== null && !empty($before['cover'])) {
                    $old = basename($before['cover']);
                    $stillUsed = $db->prepare("SELECT 1 FROM tracks WHERE cover = ? LIMIT 1");
                    $stillUsed->execute([$old]);
                    if ($old !== 'default.png' && $stillUsed->fetchColumn() === false && file_exists($coverDir . '/' . $old)) {
                        @unlink($coverDir . '/' . $old);
                    }
                }
            } else {
                $db->prepare("INSERT INTO albums (name, artist, cover, year, created_at) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$name, $artist, $cover, $year, time()]);
                $id = (int) $db->lastInsertId();
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(["status" => "error", "message" => "Enregistrement impossible."]); exit;
        }

        echo json_encode(['status' => 'success', 'album_id' => $id], JSON_UNESCAPED_UNICODE);
        break;

    // Édition d'album EN MASSE : rattache d'un coup une sélection de pistes à un
    // album, existant ou créé à la volée. C'est l'opération qui manquait le plus :
    // corriger l'album de 40 pistes se faisait piste par piste, dans la modale
    // d'édition, une fenêtre à la fois.
    case 'album_assign':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $rawIds = $_POST['track_ids'] ?? '';
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $rawIds)), fn($v) => $v > 0));
        if (!$ids) { echo json_encode(["status" => "error", "message" => "Aucune piste sélectionnée."]); exit; }

        $albumId = filter_var($_POST['album_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
        $albumName = sanitize_text($_POST['album_name'] ?? '');

        // album_id = 0 et nom vide => on DÉTACHE les pistes. Utile pour défaire une
        // mauvaise assignation sans avoir à inventer un album poubelle.
        if ($albumId <= 0 && $albumName === '') {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE tracks SET album = NULL, album_id = NULL WHERE id IN ($placeholders)")->execute($ids);
            echo json_encode(['status' => 'success', 'assigned' => count($ids), 'album_id' => null]);
            break;
        }

        if ($albumId > 0) {
            $st = $db->prepare("SELECT id, name FROM albums WHERE id = ?");
            $st->execute([$albumId]);
            $album = $st->fetch(PDO::FETCH_ASSOC);
            if (!$album) { echo json_encode(["status" => "error", "message" => "Album introuvable."]); exit; }
            $albumId = (int) $album['id'];
            $albumName = $album['name'];
        } else {
            // resolve_album() plutôt qu'un INSERT : même règle que register_genre(),
            // c'est lui qui garantit qu'on ne crée pas un doublon de casse.
            $albumId = resolve_album($db, $albumName);
            if (!$albumId) { echo json_encode(["status" => "error", "message" => "Nom d'album invalide."]); exit; }
            $st = $db->prepare("SELECT name FROM albums WHERE id = ?");
            $st->execute([$albumId]);
            $albumName = $st->fetchColumn();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE tracks SET album = ?, album_id = ? WHERE id IN ($placeholders)")
           ->execute(array_merge([$albumName, $albumId], $ids));

        echo json_encode(['status' => 'success', 'assigned' => count($ids), 'album_id' => $albumId, 'album_name' => $albumName], JSON_UNESCAPED_UNICODE);
        break;

    // Suppression d'un album : détache les pistes, ne les supprime JAMAIS. Un album
    // est une étiquette de regroupement, pas un conteneur — supprimer l'étiquette
    // ne doit pas emporter la musique.
    case 'album_delete':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $id = filter_var($_POST['album_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) { echo json_encode(["status" => "error", "message" => "Album invalide."]); exit; }

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE tracks SET album = NULL, album_id = NULL WHERE album_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM albums WHERE id = ?")->execute([$id]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(["status" => "error", "message" => "Suppression impossible."]); exit;
        }

        echo json_encode(['status' => 'success']);
        break;
}
