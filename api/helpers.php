<?php
// CGU désactivées par défaut (fraîche installation open source) : un admin doit explicitement les
// activer depuis le Panel Admin (miroir de la même règle dans index.php). Sans cache : appelée au plus
// une fois par requête (login/register/accept_terms uniquement), coût négligeable.
function terms_are_enabled($db) {
    $stmt = $db->query("SELECT value FROM settings WHERE key = 'terms_enabled'");
    return $stmt->fetchColumn() === '1';
}

function check_rate_limit($db) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();
    $window = $now - LOGIN_WINDOW;

    // Nettoyer les anciennes entrées
    $db->prepare("DELETE FROM login_attempts WHERE attempt_time < ?")->execute([$window]);

    // Compter les tentatives récentes pour cette IP
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempt_time >= ?");
    $stmt->execute([$ip, $window]);
    $count = (int)$stmt->fetchColumn();

    return $count < LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt($db) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->prepare("INSERT INTO login_attempts (ip, attempt_time) VALUES (?, ?)")->execute([$ip, time()]);
}

// --- SÉCURITÉ : Validation et nettoyage des champs texte ---
function sanitize_text($value, $max_length = MAX_FIELD_LENGTH) {
    $value = trim($value);
    if (mb_strlen($value) > $max_length) {
        $value = mb_substr($value, 0, $max_length);
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// --- SÉCURITÉ : Vérification du type MIME réel d'un fichier audio ---
function is_valid_audio($path, $ext) {
    $allowedExts = ['mp3', 'wav', 'ogg', 'flac'];
    if (!in_array($ext, $allowedExts)) return false;

    $fp = fopen($path, 'rb');
    if (!$fp) return false;
    $sig = fread($fp, 12);
    fclose($fp);

    // MP3 : frame sync ou ID3
    if (substr($sig, 0, 3) === 'ID3') return true;
    if ((ord($sig[0]) === 0xFF) && ((ord($sig[1]) & 0xE0) === 0xE0)) return true;
    // WAV : RIFF....WAVE
    if (substr($sig, 0, 4) === 'RIFF' && substr($sig, 8, 4) === 'WAVE') return true;
    // OGG
    if (substr($sig, 0, 4) === 'OggS') return true;
    // FLAC
    if (substr($sig, 0, 4) === 'fLaC') return true;

    return false;
}

// --- RECOMMANDATIONS ---
// Moteur heuristique volontairement simple (pas de ML — inutile à l'échelle d'une instance
// auto-hébergée) combinant : affinité de genre/artiste de l'utilisateur (son propre historique
// d'écoute), tendance récente (7 derniers jours, tous utilisateurs confondus), qualité perçue (ratio
// durée moyenne écoutée / durée réelle -- une piste qu'on écoute jusqu'au bout est un meilleur signal
// qu'une piste juste "vue"), un petit coup de pouce si la piste est likée, et une popularité globale
// AMORTIE en log (pas play_count brut) pour que les grosses pistes déjà archi-populaires n'écrasent pas
// tout le classement -- "remettre toutes les musiques avec trop de vue à une valeur plus basse", demandé
// explicitement, mais seulement ICI (le tri "Les plus écoutés" lui-même continue d'afficher le vrai
// play_count, sans quoi il mentirait sur ce qu'il prétend montrer). Les pistes déjà beaucoup écoutées par
// CET utilisateur sont fortement dépriorisées (pas exclues) : la recommandation sert à découvrir, pas à
// re-suggérer ce que l'utilisateur retrouve déjà tout seul dans Récents/Plus écoutés.
function build_recommendations($db, $userId, $baseUrl, $limit = 20) {
    $sevenDaysAgo = time() - (7 * 24 * 3600);

    // Affinités de l'utilisateur : genres/artistes des pistes qu'il a réellement écoutées (>=10s).
    $genreStmt = $db->prepare(
        "SELECT tracks.genre as g, COUNT(*) as cnt FROM listen_events
         JOIN tracks ON tracks.id = listen_events.track_id
         WHERE listen_events.user_id = ? AND listen_events.listened_seconds >= 10
         GROUP BY tracks.genre ORDER BY cnt DESC LIMIT 3"
    );
    $genreStmt->execute([$userId]);
    $topGenres = []; // genre => poids (1er = 1.0, 2e = 0.6, 3e = 0.3)
    $genreWeights = [1.0, 0.6, 0.3];
    foreach ($genreStmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) { $topGenres[$row['g']] = $genreWeights[$i] ?? 0.15; }

    $artistStmt = $db->prepare(
        "SELECT tracks.artist as a, COUNT(*) as cnt FROM listen_events
         JOIN tracks ON tracks.id = listen_events.track_id
         WHERE listen_events.user_id = ? AND listen_events.listened_seconds >= 10
         GROUP BY tracks.artist ORDER BY cnt DESC LIMIT 5"
    );
    $artistStmt->execute([$userId]);
    $topArtists = [];
    $artistWeights = [1.0, 0.8, 0.6, 0.4, 0.2];
    foreach ($artistStmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) { $topArtists[$row['a']] = $artistWeights[$i] ?? 0.1; }

    $hasHistory = count($topGenres) > 0 || count($topArtists) > 0;

    // Pistes déjà pas mal écoutées par CET utilisateur (secondes cumulées) -> à dépriorisée, pas exclue.
    $ownListenStmt = $db->prepare("SELECT track_id, SUM(listened_seconds) as total FROM listen_events WHERE user_id = ? GROUP BY track_id");
    $ownListenStmt->execute([$userId]);
    $ownListenSeconds = [];
    foreach ($ownListenStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $ownListenSeconds[$row['track_id']] = (int) $row['total']; }

    // Tendance récente (tous utilisateurs) et qualité (ratio d'écoute moyen), en une passe par piste.
    $recentStmt = $db->prepare("SELECT track_id, COUNT(*) as recent_plays, AVG(listened_seconds) as avg_sec FROM listen_events WHERE created_at > ? GROUP BY track_id");
    $recentStmt->execute([$sevenDaysAgo]);
    $recentPlays = []; $maxRecentPlays = 1;
    $avgListenSeconds = [];
    foreach ($recentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentPlays[$row['track_id']] = (int) $row['recent_plays'];
        $avgListenSeconds[$row['track_id']] = (float) $row['avg_sec'];
        if ($recentPlays[$row['track_id']] > $maxRecentPlays) $maxRecentPlays = $recentPlays[$row['track_id']];
    }

    $likeStmt = $db->query("SELECT track_id, COUNT(*) as cnt FROM likes GROUP BY track_id");
    $likeCounts = [];
    foreach ($likeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $likeCounts[$row['track_id']] = (int) $row['cnt']; }

    $tracks = $db->query("SELECT id, title, artist, cover, genre, play_count, duration, uploader_id FROM tracks")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tracks)) return [];
    $maxPlayCount = max(1, max(array_column($tracks, 'play_count')));

    $scored = [];
    foreach ($tracks as $t) {
        $id = $t['id'];
        $genreScore = $topGenres[$t['genre']] ?? 0.0;
        $artistScore = $topArtists[$t['artist']] ?? 0.0;
        $trendScore = isset($recentPlays[$id]) ? ($recentPlays[$id] / $maxRecentPlays) : 0.0;
        $completionScore = 0.5; // valeur neutre par défaut si pas assez de données
        if (!empty($t['duration']) && isset($avgListenSeconds[$id])) {
            $completionScore = max(0.0, min(1.0, $avgListenSeconds[$id] / $t['duration']));
        }
        $likeBoost = min(0.3, 0.1 * ($likeCounts[$id] ?? 0));
        $popularityDamped = log(1 + (int) $t['play_count']) / log(1 + $maxPlayCount);

        $score = (0.35 * $genreScore) + (0.25 * $artistScore) + (0.2 * $trendScore) + (0.1 * $completionScore) + (0.1 * $popularityDamped) + $likeBoost;

        // Pénalité si déjà bien connue de cet utilisateur (mais jamais mise à zéro : garde une petite
        // chance de resurgir, notamment pour un morceau aimé qu'on a envie de revoir de temps en temps).
        $ownSeconds = $ownListenSeconds[$id] ?? 0;
        if ($ownSeconds >= 120) $score *= 0.25;
        elseif ($ownSeconds >= 30) $score *= 0.6;

        // Sans historique du tout (nouvel utilisateur) : purement tendance + popularité amortie, le
        // matching genre/artiste ($genreScore/$artistScore) étant nul pour tout le monde de toute façon.
        if (!$hasHistory) $score = (0.5 * $trendScore) + (0.3 * $popularityDamped) + $likeBoost;

        $scored[] = ['track' => $t, 'score' => $score];
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($scored, 0, $limit);

    $result = [];
    foreach ($top as $entry) {
        $t = $entry['track'];
        $t['like_count'] = $likeCounts[$t['id']] ?? 0;
        $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
        $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        $result[] = $t;
    }
    return $result;
}

// --- SÉCURITÉ : Fonction d'authentification pour l'API, double mode ---
// 1) Session PHP (navigateur web, cookie déjà posé par le login classique dans auth.php, inchangé) :
//    pour toute requête POST (donc mutante), exige un csrf_token valide -- même protection
//    qu'actions.php avant sa fusion ici, juste centralisée. Jamais atteint par l'app Android, qui
//    n'envoie pas ce cookie.
// 2) Sinon, comportement historique inchangé : username+password à chaque requête (Android).
function authenticate_api_user($db) {
    if (!empty($_SESSION['user_id'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
                return false;
            }
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'is_admin' => !empty($_SESSION['is_admin'])
        ];
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        return false;
    }

    $stmt = $db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'is_admin' => (isset($user['is_admin']) && $user['is_admin'] == 1)
        ];
    }
    return false;
}

// --- CALCULE LA DURÉE MULTI-FORMATS ---
function calculateAudioDuration($path) {
    if (!file_exists($path)) return 0;
    $fp = fopen($path, 'rb');
    if (!$fp) return 0;

    $signature = fread($fp, 4);
    
    // --- 1. CAS DU FLAC NATIF ---
    if ($signature === 'fLaC') {
        fseek($fp, 8);
        $streamInfo = fread($fp, 34);
        fclose($fp);
        
        if (strlen($streamInfo) === 34) {
            $fields = unpack('N3', substr($streamInfo, 10, 12));
            $sampleRate = ($fields[1] >> 12) & 0xFFFFF;
            $totalSamples = (($fields[1] & 0x00F) << 32) | $fields[2];
            if ($sampleRate > 0) {
                return round($totalSamples / $sampleRate);
            }
        }
        return 0;
    }
    
    // --- 2. CAS DU M4A / MP4 / AAC CONTENEUR ---
    if (strpos($signature, 'ftyp') !== false || substr($signature, 1, 3) === 'ftyp') {
        fseek($fp, 0);
        $content = fread($fp, 1024 * 400);
        $mvhdPos = strpos($content, 'mvhd');
        fclose($fp);
        
        if ($mvhdPos !== false) {
            $version = ord($content[$mvhdPos + 4]);
            $timeScaleOffset = ($version === 1) ? 20 : 12;
            $durationOffset = ($version === 1) ? 24 : 16;
            
            $timeScale = unpack('N', substr($content, $mvhdPos + 4 + $timeScaleOffset, 4))[1];
            $durationUnits = unpack('N', substr($content, $mvhdPos + 4 + $durationOffset, 4))[1];
            
            if ($timeScale > 0) {
                return round($durationUnits / $timeScale);
            }
        }
        return 0;
    }

    // --- 3. CAS DU MP3 TRADITIONNEL (CBR/VBR) ---
    fseek($fp, 0);
    $header = fread($fp, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $b = unpack('C*', substr($header, 6, 4));
        $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
        fseek($fp, $tagSize + 10);
    } else {
        fseek($fp, 0);
    }

    $data = fread($fp, 1024 * 200);
    $offset = 0;
    while ($offset < strlen($data) - 4) {
        if (ord($data[$offset]) === 0xFF && (ord($data[$offset+1]) & 0xE0) === 0xE0) {
            $byte1 = ord($data[$offset+1]);
            $byte2 = ord($data[$offset+2]);
            $mpegVersion = ($byte1 >> 3) & 0x03;
            
            $channelMode = ($byte2 >> 6) & 0x03;
            $xingOffset = ($mpegVersion === 3) ? (($channelMode === 3) ? 17 : 32) : (($channelMode === 3) ? 9 : 17);
            $vbrCheck = substr($data, $offset + 4 + $xingOffset, 4);
            
            if ($vbrCheck === 'Xing' || $vbrCheck === 'Info') {
                $flags = unpack('N', substr($data, $offset + 4 + $xingOffset + 4, 4))[1];
                if ($flags & 0x01) {
                    $frameCount = unpack('N', substr($data, $offset + 4 + $xingOffset + 8, 4))[1];
                    $srTable = [3 => [44100, 48000, 32000, 0], 2 => [22050, 24000, 16000, 0]];
                    $sampleRate = $srTable[$mpegVersion][($byte2 >> 2) & 0x03] ?? 44100;
                    $samplesPerFrame = ($mpegVersion === 3) ? 1152 : 576;
                    fclose($fp);
                    if ($sampleRate > 0) return round(($frameCount * $samplesPerFrame) / $sampleRate);
                }
            }
            
            $brTable = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
            $bitrate = $brTable[($byte2 >> 4) & 0x0F] ?? 128;
            fclose($fp);
            if ($bitrate > 0) return round((filesize($path) * 8) / ($bitrate * 1000));
            break;
        }
        $offset++;
    }

    fclose($fp);
    return round((filesize($path) * 8) / (128 * 1000));
}

// --- HELPER METADATA (ROBUSTE) ---
function extractMp3Data($path) {
    if (!file_exists($path)) return ['artist'=>null, 'title'=>null, 'cover'=>null];
    $f = fopen($path, 'rb');
    if (!$f) return ['artist'=>null, 'title'=>null, 'cover'=>null];
    
    $header = fread($f, 10);
    if (substr($header, 0, 3) !== 'ID3') { fclose($f); return ['artist'=>null, 'title'=>null, 'cover'=>null]; }
    
    $b = unpack('C*', substr($header, 6, 4));
    $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
    $tagData = fread($f, $tagSize);
    fclose($f);
    
    $result = ['cover' => null, 'artist' => null, 'title' => null];
    $pos = 0;
    while ($pos < strlen($tagData) - 10) {
        $frameHeader = substr($tagData, $pos, 10);
        $frameName = substr($frameHeader, 0, 4);
        $s = unpack('N', substr($frameHeader, 4, 4));
        $frameSize = $s[1];
        
        if ($frameSize == 0 || $frameName == "\x00\x00\x00\x00") break;
        
        if ($frameName === 'TPE1') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['artist'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'TIT2') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['title'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'APIC') {
            $body = substr($tagData, $pos + 10, $frameSize);
            $nullPos = strpos($body, "\x00", 1);
            if ($nullPos !== false) {
                $jpgPos = strpos($body, "\xFF\xD8");
                $pngPos = strpos($body, "\x89PNG");
                
                $start = false; $mime = 'image/jpeg';
                if($jpgPos !== false && ($pngPos === false || $jpgPos < $pngPos)) { $start = $jpgPos; }
                elseif($pngPos !== false) { $start = $pngPos; $mime = 'image/png'; }
                
                if($start !== false) {
                    $result['cover'] = ['mime' => $mime, 'data' => substr($body, $start)];
                }
            }
        }
        $pos += 10 + $frameSize;
    }
    return $result;
}

// --- OPTIMISATION : Fonction pour compresser les covers ---
function optimizeImage($sourcePath, $destinationPath, $mime = null) {
    if (!extension_loaded('gd')) return move_uploaded_file($sourcePath, $destinationPath);
    
    $info = getimagesize($sourcePath);
    if (!$info) return false;
    $mime = $mime ?? $info['mime'];
    
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($sourcePath); break;
        case 'image/png': $image = imagecreatefrompng($sourcePath); break;
        case 'image/webp': $image = imagecreatefromwebp($sourcePath); break;
        case 'image/gif': $image = imagecreatefromgif($sourcePath); break;
        default: return false;
    }
    
    if (!$image) return false;

    $width = imagesx($image); $height = imagesy($image); $max_size = 300;
    
    if ($width > $max_size || $height > $max_size) {
        $ratio = min($max_size / $width, $max_size / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        if ($mime == 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
        }
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $new_image;
    }

    $success = imagewebp($image, $destinationPath, 80);
    imagedestroy($image);
    if (!$success) move_uploaded_file($sourcePath, $destinationPath);
    return true;
}
