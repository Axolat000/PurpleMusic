<?php
// --- CONTROLES OPÉRATIONNELS COMPLETS ---

if ($user_id) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die("CSRF Invalide.");
        }
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
        $db->prepare("DELETE FROM genres WHERE name = ?")->execute([$_GET['delete_genre']]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // UPLOAD
    if (isset($_POST['upload']) && isset($_FILES['music'])) {
        if (!checkRateLimit('upload', 15)) { die("Rate limit: Patientez 15 secondes."); }
        $audioExt = strtolower(pathinfo($_FILES['music']['name'], PATHINFO_EXTENSION));
        if (!in_array($audioExt, ['mp3', 'wav', 'ogg', 'flac'])) { die("Format audio non autorisé."); }

        $filename = bin2hex(random_bytes(8)) . '.' . $audioExt;
        $meta = extractMp3Data($_FILES['music']['tmp_name']);

        $title = !empty($_POST['title']) ? $_POST['title'] : (!empty($meta['title']) ? $meta['title'] : pathinfo($_FILES['music']['name'], PATHINFO_FILENAME));
        $artist = !empty($_POST['artist']) ? $_POST['artist'] : (!empty($meta['artist']) ? $meta['artist'] : "Artiste inconnu");
        $genre = $_POST['genre'] ?? 'Autre';
        $coverName = "default.png";

        if (!empty($_FILES['cover']['name'])) {
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
        $t_id = $_POST['track_id']; $genre = $_POST['new_genre'] ?? 'Autre';
        $stmt = $db->prepare("SELECT cover FROM tracks WHERE id = ? AND (uploader_id = ? OR ?)");
        $stmt->execute([$t_id, $user_id, $is_admin ? 1 : 0]); $curr = $stmt->fetch();
        if ($curr) {
            $coverName = $curr['cover'];
            if (!empty($_FILES['new_cover']['name'])) {
                $imgExt = strtolower(pathinfo($_FILES['new_cover']['name'], PATHINFO_EXTENSION));
                if (in_array($imgExt, ['png', 'jpg', 'jpeg', 'webp', 'gif'])) {
                    $coverName = bin2hex(random_bytes(8)) . '.webp';
                    optimizeImage($_FILES['new_cover']['tmp_name'], __DIR__ . '/covers/' . $coverName);
                }
            }
            $stmt = $db->prepare("UPDATE tracks SET title = ?, artist = ?, cover = ?, genre = ? WHERE id = ?");
            $stmt->execute([$_POST['new_title'], $_POST['new_artist'], $coverName, $genre, $t_id]);
        }
    }

    if (isset($_GET['delete_track'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die("Erreur CSRF");
        $stmt = $db->prepare("SELECT filename, cover FROM tracks WHERE id = ? AND (uploader_id = ? OR ?)");
        $stmt->execute([$_GET['delete_track'], $user_id, $is_admin ? 1 : 0]); $t = $stmt->fetch();
        if ($t) {
            if(file_exists(__DIR__ . '/music/' . $t['filename'])) unlink(__DIR__ . '/music/' . $t['filename']);
            if($t['cover'] != 'default.png' && file_exists(__DIR__ . '/covers/' . $t['cover'])) unlink(__DIR__ . '/covers/' . $t['cover']);
            $db->prepare("DELETE FROM tracks WHERE id = ?")->execute([$_GET['delete_track']]);
        }
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
    }

    if (isset($_GET['delete_playlist'])) {
        if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) die("Erreur CSRF");
        $db->prepare("DELETE FROM playlists WHERE id = ? AND (creator_id = ? OR ?)")->execute([$_GET['delete_playlist'], $user_id, $is_admin ? 1 : 0]);
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
    }

    if (isset($_POST['save_playlist'])) {
        $song_ids = isset($_POST['selected_songs']) ? implode(',', $_POST['selected_songs']) : "";
        if (!empty($_POST['playlist_id'])) {
            $db->prepare("UPDATE playlists SET name = ?, song_ids = ? WHERE id = ? AND (creator_id = ? OR ?)")->execute([$_POST['playlist_name'], $song_ids, $_POST['playlist_id'], $user_id, $is_admin ? 1 : 0]);
        } else {
            $db->prepare("INSERT INTO playlists (name, creator_id, song_ids) VALUES (?, ?, ?)")->execute([$_POST['playlist_name'], $user_id, $song_ids]);
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
