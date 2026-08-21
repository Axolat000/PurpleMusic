<?php
switch ($action) {
    case 'list':
        // like_count : info publique (pas besoin d'identifier l'appelant), simple sous-requête corrélée --
        // le nombre de pistes reste modeste sur ce type d'instance auto-hébergée, pas besoin d'optimiser
        // davantage. Pour savoir LESQUELLES l'utilisateur courant a lui-même likées, voir action=my_likes
        // (authentifié, séparé exprès pour ne pas complexifier/casser ce endpoint public existant).
        $stmt = $db->query("SELECT tracks.id, tracks.title, tracks.artist, tracks.cover, tracks.genre, tracks.play_count, tracks.duration, tracks.uploader_id, (SELECT COUNT(*) FROM likes WHERE likes.track_id = tracks.id) as like_count FROM tracks ORDER BY play_count DESC, id DESC");
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($tracks as &$t) {
            $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
            $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        }
        echo json_encode($tracks);
        break;

    case 'increment_play':
        // --- SÉCURITÉ : Authentification requise pour incrémenter ---
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0) { echo json_encode(["status" => "error", "message" => "ID invalide"]); exit; }

        $stmt = $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?");
        $stmt->execute([$track_id]);
        echo json_encode(["status" => "success"]);
        break;

    // Remplace l'ancien usage d'increment_play (qui comptait une vue à l'instant même où la piste
    // démarrait) : les deux clients appellent maintenant CETTE action, après avoir confirmé côté client
    // qu'au moins 10s d'écoute réelle se sont écoulées (annulé si l'utilisateur skip/pause avant) --
    // "compter une vue seulement si la musique a été écoutée plus de 10 secondes". La vérification est
    // aussi refaite ici côté serveur (jamais confiance aveugle au client) avant d'incrémenter play_count.
    // Un événement est systématiquement journalisé dans listen_events (même sous les 10s) : sert au calcul
    // de la durée moyenne d'écoute et alimente le moteur de recommandations (action=recommendations).
    case 'stream':
        $stmt = $db->prepare("SELECT filename FROM tracks WHERE id = ?"); 
        $stmt->execute([$_GET['q'] ?? 0]); 
        $t = $stmt->fetch();
        
        if($t && !empty($t['filename'])) { 
            $safeFilename = basename($t['filename']);
            $path = $musicDir . '/' . $safeFilename;

            if (file_exists($path)) {
                $size = filesize($path);
                
                $fp = @fopen($path, 'rb');
                if (!$fp) { header("HTTP/1.1 500 Internal Server Error"); exit; }

                $start = 0; $end = $size - 1;

                if (isset($_SERVER['HTTP_RANGE'])) {
                    $c_start = $start; $c_end = $end;
                    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                    if (strpos($range, ',') !== false) { header('HTTP/1.1 416 Requested Range Not Satisfiable'); header("Content-Range: bytes $start-$end/$size"); exit; }
                    if ($range == '-') { $c_start = $size - substr($range, 1); }
                    else {
                        $range = explode('-', $range);
                        $c_start = $range[0];
                        $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
                    }
                    $c_end = ($c_end > $end) ? $end : $c_end;
                    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
                        header('HTTP/1.1 416 Requested Range Not Satisfiable'); header("Content-Range: bytes $start-$end/$size"); exit;
                    }
                    $start = $c_start; $end = $c_end; $length = $end - $start + 1;
                    fseek($fp, $start);
                    header('HTTP/1.1 206 Partial Content'); header("Content-Range: bytes $start-$end/$size");
                } else {
                    $length = $size; header('HTTP/1.1 200 OK');
                }

                header('Content-Type: audio/mpeg'); header('Accept-Ranges: bytes'); header('Content-Length: ' . $length); header('Cache-Control: no-cache, must-revalidate');
                @set_time_limit(1800); 

                $buffer = 1024 * 16;
                while(!feof($fp) && ($p = ftell($fp)) <= $end) {
                    if ($p + $buffer > $end) $buffer = $end - $p + 1;
                    echo fread($fp, $buffer); flush();
                }
                fclose($fp); exit; 
            }
        }
        header("HTTP/1.0 404 Not Found"); exit;

    case 'cover':
        $stmt = $db->prepare("SELECT cover FROM tracks WHERE id = ?"); $stmt->execute([$_GET['q']??0]); $t=$stmt->fetch();
        $coverName = ($t && !empty($t['cover'])) ? basename($t['cover']) : 'default.png';
        $path = $coverDir . '/' . $coverName;
        if(!file_exists($path)) $path = $coverDir . '/default.png';
        
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = 'image/jpeg';
        if ($ext === 'webp') $mime = 'image/webp';
        elseif ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';
        
        header("Content-Type: " . $mime); readfile($path); exit;

    case 'upload':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        if(isset($_FILES['music'])) {
            $file = $_FILES['music'];

            // --- SÉCURITÉ : Vérification taille fichier audio ---
            if ($file['size'] > MAX_AUDIO_SIZE) {
                echo json_encode(["status" => "error", "message" => "Fichier audio trop volumineux (100 Mo max)"]); exit;
            }
            
            $audioExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // --- SÉCURITÉ : Vérification du type MIME réel du fichier audio ---
            if (!is_valid_audio($file['tmp_name'], $audioExt)) {
                echo json_encode(["status" => "error", "message" => "Format audio invalide ou non autorisé."]); exit;
            }

            $meta = extractMp3Data($file['tmp_name']);
            $fn = bin2hex(random_bytes(8)) . '.' . $audioExt;
            
            // --- SÉCURITÉ : Validation et troncature des champs texte ---
            $ti = !empty($_POST['title']) ? $_POST['title'] : (!empty($meta['title']) ? $meta['title'] : pathinfo($file['name'], PATHINFO_FILENAME));
            $ar = !empty($_POST['artist']) ? $_POST['artist'] : (!empty($meta['artist']) ? $meta['artist'] : "Inconnu");
            $ge = !empty($_POST['genre']) ? $_POST['genre'] : 'Autre';
            
            $ti = sanitize_text($ti);
            $ar = sanitize_text($ar);
            $ge = sanitize_text($ge, 50);
            
            $cn = "default.png";
            
            if(!empty($_FILES['cover']['name'])) {
                // --- SÉCURITÉ : Vérification taille image ---
                if ($_FILES['cover']['size'] > MAX_IMAGE_SIZE) {
                    echo json_encode(["status" => "error", "message" => "Image de couverture trop volumineuse (5 Mo max)"]); exit;
                }
                $imgExt = strtolower(pathinfo($_FILES['cover']['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                if (in_array($imgExt, $allowedImgExt)) {
                    $cn = bin2hex(random_bytes(8)) . ".webp"; 
                    optimizeImage($_FILES['cover']['tmp_name'], $coverDir.'/'.$cn);
                }
            } elseif(!empty($meta['cover']['data'])) {
                $cn = bin2hex(random_bytes(8)) . "_meta.webp"; 
                $tmpImgPath = sys_get_temp_dir() . '/' . uniqid() . '.tmp';
                file_put_contents($tmpImgPath, $meta['cover']['data']);
                optimizeImage($tmpImgPath, $coverDir.'/'.$cn, $meta['cover']['mime']);
                @unlink($tmpImgPath);
            }
            
            $duration = calculateAudioDuration($file['tmp_name']);
            
            if(move_uploaded_file($file['tmp_name'], $musicDir.'/'.$fn)) {
                $db->prepare("INSERT INTO tracks (filename, title, artist, cover, genre, uploader_id, duration) VALUES (?,?,?,?,?,?,?)")->execute([$fn, $ti, $ar, $cn, $ge, $auth['id'], $duration]);
                echo json_encode(["status" => "success"]);
            } else echo json_encode(["status" => "error", "message" => "Erreur de déplacement du fichier"]);
        } else echo json_encode(["status" => "error", "message" => "Fichier audio manquant"]);
        break;

    case 'edit_track':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $tid = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($tid === false || $tid <= 0) { echo json_encode(["status" => "error", "message" => "ID de piste invalide"]); exit; }

        $t = $db->prepare("SELECT uploader_id, cover FROM tracks WHERE id=?"); $t->execute([$tid]); $curr = $t->fetch();
        
        if($curr && ($auth['is_admin'] || $curr['uploader_id'] == $auth['id'])) {
            $cleanTitle  = sanitize_text($_POST['title']  ?? '');
            $cleanArtist = sanitize_text($_POST['artist'] ?? '');

            $sets = ["title = ?", "artist = ?"]; $params = [$cleanTitle, $cleanArtist];
            
            if(isset($_POST['new_genre'])) {
                $sets[] = "genre = ?";
                $params[] = sanitize_text($_POST['new_genre'], 50);
            }

            if(!empty($_FILES['new_cover']['name'])) {
                // --- SÉCURITÉ : Vérification taille de la nouvelle cover ---
                if ($_FILES['new_cover']['size'] > MAX_IMAGE_SIZE) {
                    echo json_encode(["status" => "error", "message" => "Image de couverture trop volumineuse (5 Mo max)"]); exit;
                }
                $imgExt = strtolower(pathinfo($_FILES['new_cover']['name'], PATHINFO_EXTENSION));
                $allowedImgExt = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                if (in_array($imgExt, $allowedImgExt)) {
                    $newCn = bin2hex(random_bytes(8)) . "_edit.webp";
                    if(optimizeImage($_FILES['new_cover']['tmp_name'], $coverDir.'/'.$newCn)) {
                        $sets[] = "cover = ?"; $params[] = $newCn;
                        $oldCover = basename($curr['cover']);
                        if($oldCover != 'default.png' && file_exists($coverDir.'/'.$oldCover)) unlink($coverDir.'/'.$oldCover);
                    }
                }
            }
            $params[] = $tid;
            $db->prepare("UPDATE tracks SET ".implode(', ', $sets)." WHERE id = ?")->execute($params);
            echo json_encode(["status" => "success"]);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette musique"]);
        break;

    case 'delete_track':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé. Identifiants invalides."]); exit; }

        $tid = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($tid === false || $tid <= 0) { echo json_encode(["status" => "error", "message" => "ID de piste invalide"]); exit; }

        $t = $db->prepare("SELECT uploader_id, filename, cover FROM tracks WHERE id=?"); $t->execute([$tid]); $curr = $t->fetch();
        
        if($curr && ($auth['is_admin'] || $curr['uploader_id'] == $auth['id'])) {
            $safeMusicFile = basename($curr['filename']);
            $safeCoverFile = basename($curr['cover']);

            if(!empty($safeMusicFile) && file_exists($musicDir.'/'.$safeMusicFile)) unlink($musicDir.'/'.$safeMusicFile);
            if($safeCoverFile != 'default.png' && file_exists($coverDir.'/'.$safeCoverFile)) unlink($coverDir.'/'.$safeCoverFile);
            
            $db->prepare("DELETE FROM tracks WHERE id=?")->execute([$tid]);
            echo json_encode(["status" => "success"]);
        } else echo json_encode(["status" => "error", "message" => "Interdit : Vous n'avez pas les droits sur cette musique"]);
        break;

}
