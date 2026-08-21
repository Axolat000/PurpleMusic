<?php
switch ($action) {
    case 'save_admin_settings':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        // Un champ absent du POST garde sa valeur actuelle (jamais écrasé par une chaîne vide) : le
        // vrai formulaire (Panel Admin) envoie toujours tous les champs d'un coup (onglets en x-show,
        // pas x-if -- restent dans le DOM/FormData même masqués), mais un futur appelant partiel (ou un
        // test manuel de cet endpoint) ne doit jamais pouvoir vider le thème/site_name par accident.
        $existingSettings = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $stmtUpdate = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $fields = [
            'site_name' => isset($_POST['adm_site_name']) ? trim($_POST['adm_site_name']) : ($existingSettings['site_name'] ?? ''),
            'color_bg' => $_POST['adm_color_bg'] ?? $existingSettings['color_bg'] ?? '',
            'color_panel' => $_POST['adm_color_panel'] ?? $existingSettings['color_panel'] ?? '',
            'color_primary' => $_POST['adm_color_primary'] ?? $existingSettings['color_primary'] ?? '',
            'color_accent' => $_POST['adm_color_accent'] ?? $existingSettings['color_accent'] ?? '',
            'color_text' => $_POST['adm_color_text'] ?? $existingSettings['color_text'] ?? '',
            'color_text_muted' => $_POST['adm_color_text_muted'] ?? $existingSettings['color_text_muted'] ?? '',
            'color_border' => $_POST['adm_color_border'] ?? $existingSettings['color_border'] ?? '',
            'color_search_bg' => $_POST['adm_color_search_bg'] ?? $existingSettings['color_search_bg'] ?? '',
            'color_header_bg' => $_POST['adm_color_header_bg'] ?? $existingSettings['color_header_bg'] ?? '',
            'color_player_bg' => $_POST['adm_color_player_bg'] ?? $existingSettings['color_player_bg'] ?? '',
            'color_mob_nav_bg' => $_POST['adm_color_mob_nav_bg'] ?? $existingSettings['color_mob_nav_bg'] ?? '',
            'color_fp_gradient_1' => $_POST['adm_color_fp_gradient_1'] ?? $existingSettings['color_fp_gradient_1'] ?? '',
            'color_fp_gradient_2' => $_POST['adm_color_fp_gradient_2'] ?? $existingSettings['color_fp_gradient_2'] ?? '',
        ];
        foreach ($fields as $k => $v) { $stmtUpdate->execute([$k, $v]); }

        if (!empty($_FILES['adm_favicon']['name'])) {
            $ext = strtolower(pathinfo($_FILES['adm_favicon']['name'], PATHINFO_EXTENSION));
            // dirname(__DIR__), pas __DIR__ : ce fichier vit dans api/, la racine du site (où vit
            // favicon.php) est un niveau au-dessus -- __DIR__ tout court écrivait dans api/favicon.png,
            // jamais servi (bug introduit par le découpage en domaines, jamais exercé en test local).
            if (in_array($ext, ['png', 'ico'])) move_uploaded_file($_FILES['adm_favicon']['tmp_name'], dirname(__DIR__) . '/favicon.png');
        }
        if (!empty($_FILES['adm_default_cover']['name'])) {
            $ext = strtolower(pathinfo($_FILES['adm_default_cover']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) move_uploaded_file($_FILES['adm_default_cover']['tmp_name'], $coverDir . '/default.png');
        }
        if (!empty($_POST['adm_new_genre'])) {
            $db->prepare("INSERT OR IGNORE INTO genres (name) VALUES (?)")->execute([trim($_POST['adm_new_genre'])]);
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_genre':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $db->prepare("DELETE FROM genres WHERE name = ?")->execute([$_POST['name'] ?? '']);
        echo json_encode(['status' => 'success']);
        break;

    case 'toggle_admin':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $targetId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
        if ($targetId !== false && $targetId != $auth['id']) {
            $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt->execute([$targetId]);
            $curr = $stmt->fetchColumn();
            if ($curr !== false) {
                $db->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$curr == 1 ? 0 : 1, $targetId]);
            }
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'delete_user':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $targetId = filter_var($_POST['user_id'] ?? '', FILTER_VALIDATE_INT);
        if ($targetId !== false && $targetId != $auth['id']) {
            // Cascade sur pistes/playlists de l'utilisateur : nécessaire, sinon elles deviennent orphelines
            // (le JOIN sur users dans les listings les ferait disparaître silencieusement).
            $stmt = $db->prepare("SELECT filename, cover FROM tracks WHERE uploader_id = ?");
            $stmt->execute([$targetId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $safeMusicFile = basename($t['filename']);
                $safeCoverFile = basename($t['cover']);
                if (!empty($safeMusicFile) && file_exists($musicDir . '/' . $safeMusicFile)) unlink($musicDir . '/' . $safeMusicFile);
                if ($safeCoverFile !== 'default.png' && file_exists($coverDir . '/' . $safeCoverFile)) unlink($coverDir . '/' . $safeCoverFile);
            }
            $db->prepare("DELETE FROM tracks WHERE uploader_id = ?")->execute([$targetId]);

            $stmt = $db->prepare("SELECT cover FROM playlists WHERE creator_id = ?");
            $stmt->execute([$targetId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pl) {
                $safeCoverFile = basename((string) $pl['cover']);
                if (!empty($safeCoverFile) && file_exists($coverDir . '/' . $safeCoverFile)) unlink($coverDir . '/' . $safeCoverFile);
            }
            $db->prepare("DELETE FROM playlists WHERE creator_id = ?")->execute([$targetId]);

            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'trigger_update':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $watchtowerUrl = (string) getenv('WATCHTOWER_API_URL');
        $watchtowerToken = (string) getenv('WATCHTOWER_API_TOKEN');

        if ($watchtowerUrl === '' || $watchtowerToken === '') {
            echo json_encode(['status' => 'error', 'message' => "Watchtower n'est pas configuré.", 'manual' => true]); exit;
        }
        if (!function_exists('curl_init')) {
            echo json_encode(['status' => 'error', 'message' => "Échec du déclenchement.", 'manual' => true]); exit;
        }

        $ch = curl_init($watchtowerUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $watchtowerToken],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        if ($curlErrNo === 0 && $httpCode >= 200 && $httpCode < 300) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => "Échec du déclenchement.", 'manual' => true]);
        }
        break;

    case 'check_update':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(['error' => "Non authentifié."]); exit; }

        $localSha = (string) getenv('APP_COMMIT_SHA');
        if ($localSha === '' || $localSha === 'unknown') { echo json_encode(['checked' => false, 'update_available' => false]); break; }

        $cacheFile = $dataDir . '/update_check_cache.json';
        $cacheTtl = 3600;
        $cached = null;
        if (file_exists($cacheFile)) {
            $rawCache = @file_get_contents($cacheFile);
            $decoded = $rawCache !== false ? json_decode($rawCache, true) : null;
            if (is_array($decoded)) $cached = $decoded;
        }

        $remoteSha = null;
        $cacheIsFresh = $cached !== null
            && isset($cached['checked_at'], $cached['remote_sha'], $cached['local_sha'])
            && $cached['local_sha'] === $localSha
            && (time() - $cached['checked_at']) < $cacheTtl;

        if ($cacheIsFresh) {
            $remoteSha = $cached['remote_sha'];
        } elseif (function_exists('curl_init')) {
            $ch = curl_init('https://api.github.com/repos/Axolat000/PurpleMusic/commits/main');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT => 'PurpleMusic-UpdateChecker',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);

            if ($curlErrNo === 0 && $response !== false && $httpCode === 200) {
                $data = json_decode($response, true);
                if (is_array($data) && !empty($data['sha']) && is_string($data['sha'])) $remoteSha = $data['sha'];
            }
            if ($remoteSha !== null) {
                @file_put_contents($cacheFile, json_encode(['checked_at' => time(), 'remote_sha' => $remoteSha, 'local_sha' => $localSha]));
            } elseif ($cached !== null && isset($cached['remote_sha'])) {
                $remoteSha = $cached['remote_sha'];
            }
        }

        if ($remoteSha === null) { echo json_encode(['checked' => false, 'update_available' => false]); break; }

        echo json_encode([
            'checked' => true,
            'update_available' => ($remoteSha !== $localSha),
            'watchtower_configured' => (getenv('WATCHTOWER_API_URL') ?: '') !== '' && (getenv('WATCHTOWER_API_TOKEN') ?: '') !== '',
        ]);
        break;
}
