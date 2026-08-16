<?php
// --- CONTROLES OPÉRATIONNELS COMPLETS ---

if ($user_id) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die(t('err_csrf_invalid'));
        }
    }

    // CHANGEMENT DE MOT DE PASSE (self-service, requête AJAX depuis l'onglet "Compte" des Paramètres)
    if (isset($_POST['change_password'])) {
        header('Content-Type: application/json');

        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        // On revérifie le hash actuel en base (jamais confiance dans une valeur envoyée par le client pour l'identité).
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($currentPassword, $u['password'])) {
            echo json_encode(['status' => 'error', 'message' => t('err_current_password_invalid')]);
            exit;
        }
        if ($newPassword !== $confirmPassword) {
            echo json_encode(['status' => 'error', 'message' => t('err_password_mismatch')]);
            exit;
        }
        if (mb_strlen($newPassword) < 6) {
            echo json_encode(['status' => 'error', 'message' => t('err_password_too_short')]);
            exit;
        }
        if (mb_strlen($newPassword) > 200) {
            echo json_encode(['status' => 'error', 'message' => t('err_password_too_long')]);
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user_id]);
        echo json_encode(['status' => 'success', 'message' => t('settings_password_changed')]);
        exit;
    }

    // RÉINITIALISATION DE MOT DE PASSE PAR UN ADMIN (Admin Panel > Utilisateurs) : différent du
    // changement self-service ci-dessus (ne requiert pas l'ancien mot de passe). Génère un mot de
    // passe temporaire aléatoire, le hash en base, et le renvoie EN CLAIR une seule fois au client
    // (jamais loggué/stocké en clair) pour que l'admin le communique à l'utilisateur hors-bande.
    if ($is_admin && isset($_POST['admin_reset_password'])) {
        header('Content-Type: application/json');

        $targetId = filter_var($_POST['target_user_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$targetId) {
            echo json_encode(['status' => 'error', 'message' => t('err_user_not_found')]);
            exit;
        }
        // Anti-verrouillage : un admin ne peut pas réinitialiser son propre mot de passe via cette UI.
        if ($targetId == $user_id) {
            echo json_encode(['status' => 'error', 'message' => t('err_cannot_modify_self')]);
            exit;
        }
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        if (!$stmt->fetch()) {
            echo json_encode(['status' => 'error', 'message' => t('err_user_not_found')]);
            exit;
        }

        $newPassword = bin2hex(random_bytes(6)); // mot de passe temporaire lisible (12 car. hexa)
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $targetId]);
        echo json_encode(['status' => 'success', 'password' => $newPassword]);
        exit;
    }

    // Sauvegarde de configuration étendue Admin
    if ($is_admin && isset($_POST['save_admin_settings'])) {
        $stmtUpdate = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $fields = [
            'site_name' => trim($_POST['adm_site_name']),
            'color_bg' => $_POST['adm_color_bg'],
            'color_panel' => $_POST['adm_color_panel'],
            'color_primary' => $_POST['adm_color_primary'],
            'color_accent' => $_POST['adm_color_accent'],
            'color_text' => $_POST['adm_color_text'],
            'color_text_muted' => $_POST['adm_color_text_muted'],
            'color_border' => $_POST['adm_color_border'],
            'color_search_bg' => $_POST['adm_color_search_bg'],
            'color_header_bg' => $_POST['adm_color_header_bg'],
            'color_player_bg' => $_POST['adm_color_player_bg'],
            'color_mob_nav_bg' => $_POST['adm_color_mob_nav_bg'],
            'color_fp_gradient_1' => $_POST['adm_color_fp_gradient_1'],
            'color_fp_gradient_2' => $_POST['adm_color_fp_gradient_2'],
        ];

        foreach($fields as $k => $v) {
            $stmtUpdate->execute([$k, $v]);
        }

        if (!empty($_FILES['adm_favicon']['name'])) {
            $ext = strtolower(pathinfo($_FILES['adm_favicon']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'ico'])) move_uploaded_file($_FILES['adm_favicon']['tmp_name'], __DIR__ . '/favicon.png');
        }
        if (!empty($_FILES['adm_default_cover']['name'])) {
            $ext = strtolower(pathinfo($_FILES['adm_default_cover']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) move_uploaded_file($_FILES['adm_default_cover']['tmp_name'], __DIR__ . '/covers/default.png');
        }
        if (!empty($_POST['adm_new_genre'])) {
            $db->prepare("INSERT OR IGNORE INTO genres (name) VALUES (?)")->execute([trim($_POST['adm_new_genre'])]);
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($is_admin && isset($_GET['delete_genre'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die(t('err_csrf'));
        $db->prepare("DELETE FROM genres WHERE name = ?")->execute([$_GET['delete_genre']]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // PROMOTION / RÉTROGRADATION ADMIN (Admin Panel > Utilisateurs)
    if ($is_admin && isset($_GET['toggle_admin'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die(t('err_csrf'));
        $targetId = filter_var($_GET['toggle_admin'], FILTER_VALIDATE_INT);
        // Anti-verrouillage : un admin ne peut pas se retirer ses propres droits via cette UI (vérifié ici, pas seulement caché côté client).
        if ($targetId && $targetId != $user_id) {
            $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            $curr = $stmt->fetchColumn();
            if ($curr !== false) {
                $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$curr == 1 ? 0 : 1, $targetId]);
            }
        }
        header("Location: " . $_SERVER['PHP_SELF'] . '?page=admin&admin_tab=users');
        exit;
    }

    // SUPPRESSION D'UTILISATEUR (Admin Panel > Utilisateurs) : cascade sur ses pistes et playlists.
    // Nécessaire car la liste des pistes (index.php) utilise un INNER JOIN sur users : sans ce nettoyage,
    // les pistes de l'utilisateur supprimé disparaîtraient silencieusement de tous les listings au lieu
    // de simplement devenir orphelines (elles ne remonteraient plus jamais dans aucune requête).
    if ($is_admin && isset($_GET['delete_user'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die(t('err_csrf'));
        $targetId = filter_var($_GET['delete_user'], FILTER_VALIDATE_INT);
        // Anti-verrouillage : un admin ne peut pas supprimer son propre compte via cette UI (vérifié ici, pas seulement caché côté client).
        if ($targetId && $targetId != $user_id) {
            $stmt = $db->prepare("SELECT filename, cover FROM tracks WHERE uploader_id = ?");
            $stmt->execute([$targetId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                unlinkTrackFiles($t['filename'], $t['cover']);
            }
            $db->prepare("DELETE FROM tracks WHERE uploader_id = ?")->execute([$targetId]);

            $stmt = $db->prepare("SELECT cover FROM playlists WHERE creator_id = ?");
            $stmt->execute([$targetId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pl) {
                unlinkPlaylistCover($pl['cover']);
            }
            $db->prepare("DELETE FROM playlists WHERE creator_id = ?")->execute([$targetId]);

            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
        }
        header("Location: " . $_SERVER['PHP_SELF'] . '?page=admin&admin_tab=users');
        exit;
    }

    // UPLOAD
    if (isset($_POST['upload']) && isset($_FILES['music'])) {
        if (!checkRateLimit('upload', 15)) { die(t('err_rate_limit_upload')); }

        if ($_FILES['music']['size'] > MAX_AUDIO_SIZE) { die(t('err_audio_too_large')); }

        $audioExt = strtolower(pathinfo($_FILES['music']['name'], PATHINFO_EXTENSION));
        if (!is_valid_audio($_FILES['music']['tmp_name'], $audioExt)) { die(t('err_audio_invalid_format')); }

        $filename = bin2hex(random_bytes(8)) . '.' . $audioExt;
        $meta = extractMp3Data($_FILES['music']['tmp_name']);

        $title = sanitize_text(!empty($_POST['title']) ? $_POST['title'] : (!empty($meta['title']) ? $meta['title'] : pathinfo($_FILES['music']['name'], PATHINFO_FILENAME)));
        $artist = sanitize_text(!empty($_POST['artist']) ? $_POST['artist'] : (!empty($meta['artist']) ? $meta['artist'] : "Artiste inconnu"));
        $genre = sanitize_text($_POST['genre'] ?? 'Autre', 50);
        $coverName = "default.png";

        if (!empty($_FILES['cover']['name'])) {
            if ($_FILES['cover']['size'] > MAX_IMAGE_SIZE) { die(t('err_image_too_large')); }
            $imgExt = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
            if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                $coverName = bin2hex(random_bytes(8)) . '.webp';
                optimizeImage($_FILES['cover']['tmp_name'], __DIR__ . '/covers/' . $coverName);
            }
        } elseif (!empty($meta['cover'])) {
            $coverName = bin2hex(random_bytes(8)) . "_meta.webp";
            $tmpImgPath = sys_get_temp_dir() . '/' . uniqid() . '.tmp'; file_put_contents($tmpImgPath, $meta['cover']['data']);
            optimizeImage($tmpImgPath, __DIR__ . '/covers/' . $coverName, $meta['cover']['mime']); @unlink($tmpImgPath);
        }

        $duration = calculateAudioDuration($_FILES['music']['tmp_name']);
        if (move_uploaded_file($_FILES['music']['tmp_name'], __DIR__ . '/music/' . $filename)) {
            $stmt = $db->prepare("INSERT INTO tracks (filename, title, artist, cover, genre, uploader_id, duration) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$filename, $title, $artist, $coverName, $genre, $user_id, $duration]);
        }
    }

    // EDIT
    if (isset($_POST['edit_track'])) {
        $t_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        $genre = sanitize_text($_POST['new_genre'] ?? 'Autre', 50);
        $stmt = $db->prepare("SELECT cover FROM tracks WHERE id = ? AND (uploader_id = ? OR ?)");
        $stmt->execute([$t_id, $user_id, $is_admin ? 1 : 0]); $curr = $stmt->fetch();
        if ($t_id && $curr) {
            $coverName = $curr['cover'];
            if (!empty($_FILES['new_cover']['name'])) {
                if ($_FILES['new_cover']['size'] > MAX_IMAGE_SIZE) { die(t('err_image_too_large')); }
                $imgExt = strtolower(pathinfo($_FILES['new_cover']['name'], PATHINFO_EXTENSION));
                if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $coverName = bin2hex(random_bytes(8)) . '.webp';
                    optimizeImage($_FILES['new_cover']['tmp_name'], __DIR__ . '/covers/' . $coverName);
                }
            }
            $stmt = $db->prepare("UPDATE tracks SET title = ?, artist = ?, cover = ?, genre = ? WHERE id = ?");
            $stmt->execute([sanitize_text($_POST['new_title'] ?? ''), sanitize_text($_POST['new_artist'] ?? ''), $coverName, $genre, $t_id]);
        }
    }

    if (isset($_GET['delete_track'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die(t('err_csrf'));
        $stmt = $db->prepare("SELECT filename, cover FROM tracks WHERE id = ? AND (uploader_id = ? OR ?)");
        $stmt->execute([$_GET['delete_track'], $user_id, $is_admin ? 1 : 0]); $t = $stmt->fetch();
        if ($t) {
            unlinkTrackFiles($t['filename'], $t['cover']);
            $db->prepare("DELETE FROM tracks WHERE id = ?")->execute([$_GET['delete_track']]);
        }
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
    }

    if (isset($_GET['delete_playlist'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die(t('err_csrf'));
        $stmt = $db->prepare("SELECT cover FROM playlists WHERE id = ? AND (creator_id = ? OR ?)");
        $stmt->execute([$_GET['delete_playlist'], $user_id, $is_admin ? 1 : 0]); $pl = $stmt->fetch();
        if ($pl) unlinkPlaylistCover($pl['cover']);
        $db->prepare("DELETE FROM playlists WHERE id = ? AND (creator_id = ? OR ?)")->execute([$_GET['delete_playlist'], $user_id, $is_admin ? 1 : 0]);
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
    }

    if (isset($_POST['save_playlist'])) {
        $cleanIds = isset($_POST['selected_songs']) ? array_filter(array_map('intval', $_POST['selected_songs']), fn($v) => $v > 0) : [];
        $song_ids = implode(',', $cleanIds);
        $playlistName = sanitize_text($_POST['playlist_name'] ?? 'Playlist', 100);
        $playlistId = filter_var($_POST['playlist_id'] ?? 0, FILTER_VALIDATE_INT);

        // Couverture existante conservée par défaut (édition) ou NULL (création)
        $coverName = null;
        if ($playlistId) {
            $stmt = $db->prepare("SELECT cover FROM playlists WHERE id = ? AND (creator_id = ? OR ?)");
            $stmt->execute([$playlistId, $user_id, $is_admin ? 1 : 0]);
            $currPlaylist = $stmt->fetch();
            if ($currPlaylist) $coverName = $currPlaylist['cover'];
        }

        if (!empty($_FILES['playlist_cover']['name'])) {
            if ($_FILES['playlist_cover']['size'] > MAX_IMAGE_SIZE) { die(t('err_image_too_large')); }
            $imgExt = strtolower(pathinfo($_FILES['playlist_cover']['name'], PATHINFO_EXTENSION));
            if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                $coverName = bin2hex(random_bytes(8)) . '.webp';
                optimizeImage($_FILES['playlist_cover']['tmp_name'], __DIR__ . '/covers/' . $coverName);
            }
        }

        if ($playlistId) {
            $db->prepare("UPDATE playlists SET name = ?, song_ids = ?, cover = ? WHERE id = ? AND (creator_id = ? OR ?)")->execute([$playlistName, $song_ids, $coverName, $playlistId, $user_id, $is_admin ? 1 : 0]);
        } else {
            $db->prepare("INSERT INTO playlists (name, creator_id, song_ids, cover) VALUES (?, ?, ?, ?)")->execute([$playlistName, $user_id, $song_ids, $coverName]);
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
