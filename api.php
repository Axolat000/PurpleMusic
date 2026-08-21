<?php
// Session PHP (cookie) : permet au site web (même origine) de s'authentifier ici sans renvoyer
// username+password à chaque appel, voir authenticate_api_user() plus bas -- n'affecte pas l'app
// Android, qui n'envoie jamais ce cookie et continue donc à s'authentifier par identifiants comme avant.
session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json');

// --- SÉCURITÉ : Entêtes HTTP de sécurité ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// --- CONFIGURATION BDD ---
// Même variable d'environnement que index.php/install.php (voir PURPLEMUSIC_DATA_DIR) :
// absent = comportement inchangé (music_app.db dans le dossier de l'app, comme avant).
$dataDir = getenv('PURPLEMUSIC_DATA_DIR') ?: __DIR__;
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
try {
    $db = new PDO('sqlite:' . $dataDir . '/music_app.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS tracks (id INTEGER PRIMARY KEY, filename TEXT, title TEXT, artist TEXT DEFAULT 'Artiste inconnu', cover TEXT DEFAULT 'default.png', genre TEXT DEFAULT 'Autre', uploader_id INTEGER, upload_date DATETIME DEFAULT CURRENT_TIMESTAMP, play_count INTEGER DEFAULT 0, duration INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS playlists (id INTEGER PRIMARY KEY, name TEXT, creator_id INTEGER, song_ids TEXT)");

    // --- SÉCURITÉ : Table de rate limiting pour les tentatives de login ---
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT, attempt_time INTEGER)");

    // --- OPTIMISATION : Indexation SQL ---
    $db->exec("CREATE INDEX IF NOT EXISTS idx_play_count ON tracks(play_count)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_uploader ON tracks(uploader_id)");

    // --- MIGRATIONS AUTOMATIQUES ---
    $cols = $db->query("PRAGMA table_info(tracks)")->fetchAll(PDO::FETCH_ASSOC);
    $hasGenre = false;
    $hasPlayCount = false;
    $hasDuration = false;
    foreach($cols as $c) { 
        if($c['name'] == 'genre') $hasGenre = true; 
        if($c['name'] == 'play_count') $hasPlayCount = true; 
        if($c['name'] == 'duration') $hasDuration = true; 
    }
    if(!$hasGenre) $db->exec("ALTER TABLE tracks ADD COLUMN genre TEXT DEFAULT 'Autre'");
    if(!$hasPlayCount) $db->exec("ALTER TABLE tracks ADD COLUMN play_count INTEGER DEFAULT 0");
    if(!$hasDuration) $db->exec("ALTER TABLE tracks ADD COLUMN duration INTEGER DEFAULT 0");

    // --- MIGRATIONS AUTOMATIQUES (PAROLES / lrclib.net) ---
    $hasLyricsSynced = false;
    $hasLyricsPlain = false;
    $hasLyricsCheckedAt = false;
    foreach($cols as $c) {
        if($c['name'] == 'lyrics_synced') $hasLyricsSynced = true;
        if($c['name'] == 'lyrics_plain') $hasLyricsPlain = true;
        if($c['name'] == 'lyrics_checked_at') $hasLyricsCheckedAt = true;
    }
    if(!$hasLyricsSynced) $db->exec("ALTER TABLE tracks ADD COLUMN lyrics_synced TEXT");
    if(!$hasLyricsPlain) $db->exec("ALTER TABLE tracks ADD COLUMN lyrics_plain TEXT");
    if(!$hasLyricsCheckedAt) $db->exec("ALTER TABLE tracks ADD COLUMN lyrics_checked_at INTEGER");

    // --- MIGRATIONS AUTOMATIQUES (USERS) ---
    $colsUsers = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $hasIsAdmin = false;
    foreach($colsUsers as $c) { 
        if($c['name'] == 'is_admin') $hasIsAdmin = true; 
    }
    if(!$hasIsAdmin) $db->exec("ALTER TABLE users ADD COLUMN is_admin INTEGER DEFAULT 0");

    // --- MIGRATIONS AUTOMATIQUES (COVER PLAYLIST) ---
    // (miroir de la migration dans index.php, les deux scripts partagent music_app.db)
    $colsPlaylists = $db->query("PRAGMA table_info(playlists)")->fetchAll(PDO::FETCH_ASSOC);
    $hasPlaylistCover = false;
    foreach($colsPlaylists as $c) {
        if($c['name'] == 'cover') $hasPlaylistCover = true;
    }
    if(!$hasPlaylistCover) $db->exec("ALTER TABLE playlists ADD COLUMN cover TEXT");
    $hasPlaylistPrivate = false;
    foreach($colsPlaylists as $c) {
        if($c['name'] == 'is_private') $hasPlaylistPrivate = true;
    }
    if(!$hasPlaylistPrivate) $db->exec("ALTER TABLE playlists ADD COLUMN is_private INTEGER DEFAULT 0");

    // --- LIKES + ANALYTIQUE D'ÉCOUTE (recommandations) ---
    // likes : une ligne = un like (clé composite, pas d'auto-incrément nécessaire).
    // listen_events : une ligne par lecture réellement écoutée, avec le nombre de secondes écoutées --
    // alimente à la fois le compteur de vues (uniquement au-delà de 10s, voir action=report_listen) et le
    // moteur de recommandations (durée moyenne d'écoute, tendances récentes, affinités par genre/artiste).
    // Ne remplace pas tracks.play_count (qui reste le compteur affiché tel quel) : c'est une source de
    // données supplémentaire pour le calcul des recommandations, pas une refonte du compteur existant.
    $db->exec("CREATE TABLE IF NOT EXISTS likes (user_id INTEGER NOT NULL, track_id INTEGER NOT NULL, created_at INTEGER, PRIMARY KEY (user_id, track_id))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_likes_track ON likes(track_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS listen_events (id INTEGER PRIMARY KEY AUTOINCREMENT, track_id INTEGER NOT NULL, user_id INTEGER NOT NULL, listened_seconds INTEGER DEFAULT 0, created_at INTEGER)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_listen_track ON listen_events(track_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_listen_user ON listen_events(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_listen_created ON listen_events(created_at)");

} catch (Exception $e) { die(json_encode(["status" => "error", "message" => "Erreur BDD"])); }

$musicDir = __DIR__ . '/music';
$coverDir = __DIR__ . '/covers';
if(!is_dir($musicDir)) mkdir($musicDir, 0755, true);
if(!is_dir($coverDir)) mkdir($coverDir, 0755, true);

$action = $_GET['action'] ?? '';
$baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/";

// --- SÉCURITÉ : Constantes de validation ---
define('MAX_AUDIO_SIZE',  100 * 1024 * 1024); // 100 Mo
define('MAX_IMAGE_SIZE',    5 * 1024 * 1024); // 5 Mo
define('MAX_FIELD_LENGTH', 200);              // longueur max des champs texte
define('LOGIN_MAX_ATTEMPTS', 10);             // tentatives max sur 15 min
define('LOGIN_WINDOW', 900);                  // fenêtre de 15 minutes (secondes)

// --- SÉCURITÉ : Rate limiting sur les logins (par IP) ---
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

switch($action) {
    case 'login':
        // --- SÉCURITÉ : Rate limiting sur les tentatives de login ---
        if (!check_rate_limit($db)) {
            http_response_code(429);
            echo json_encode(["status" => "error", "message" => "Trop de tentatives. Réessayez dans 15 minutes."]);
            exit;
        }
        record_login_attempt($db);

        $auth = authenticate_api_user($db);
        if ($auth) {
            echo json_encode(["status" => "success", "user_id" => $auth['id'], "username" => $auth['username'], "is_admin" => $auth['is_admin']]);
        } else {
            echo json_encode(["status" => "error", "message" => "Identifiants invalides"]);
        }
        break;

    case 'register':
        $u = $_POST['username'] ?? ''; $p = $_POST['password'] ?? '';
        if(empty($u) || empty($p)) { echo json_encode(["status" => "error", "message" => "Données manquantes"]); exit; }

        // --- SÉCURITÉ : Validation longueur username/password ---
        if (mb_strlen($u) > 50) { echo json_encode(["status" => "error", "message" => "Nom d'utilisateur trop long (50 caractères max)"]); exit; }
        if (mb_strlen($p) < 6)  { echo json_encode(["status" => "error", "message" => "Mot de passe trop court (6 caractères min)"]); exit; }
        if (mb_strlen($p) > 200) { echo json_encode(["status" => "error", "message" => "Mot de passe trop long"]); exit; }

        $u = htmlspecialchars(trim($u), ENT_QUOTES, 'UTF-8');
        try { 
            $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)")->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
            echo json_encode(["status" => "success"]); 
        } catch(Exception $e) { 
            echo json_encode(["status" => "error", "message" => "Nom d'utilisateur déjà pris"]); 
        }
        break;

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
    case 'report_listen':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        $seconds = filter_var($_POST['seconds'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0 || $seconds === false || $seconds < 0) {
            echo json_encode(["status" => "error", "message" => "Paramètres invalides"]); exit;
        }
        $seconds = min($seconds, 24 * 3600); // garde-fou anti-abus, une piste ne dure jamais 24h

        $db->prepare("INSERT INTO listen_events (track_id, user_id, listened_seconds, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$track_id, $auth['id'], $seconds, time()]);

        $counted = $seconds >= 10;
        if ($counted) {
            $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?")->execute([$track_id]);
        }
        echo json_encode(["status" => "success", "counted" => $counted]);
        break;

    case 'toggle_like':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0) { echo json_encode(["status" => "error", "message" => "ID invalide"]); exit; }

        $existing = $db->prepare("SELECT 1 FROM likes WHERE user_id = ? AND track_id = ?");
        $existing->execute([$auth['id'], $track_id]);
        if ($existing->fetch()) {
            $db->prepare("DELETE FROM likes WHERE user_id = ? AND track_id = ?")->execute([$auth['id'], $track_id]);
            $liked = false;
        } else {
            $db->prepare("INSERT INTO likes (user_id, track_id, created_at) VALUES (?, ?, ?)")->execute([$auth['id'], $track_id, time()]);
            $liked = true;
        }
        $countStmt = $db->prepare("SELECT COUNT(*) FROM likes WHERE track_id = ?");
        $countStmt->execute([$track_id]);
        echo json_encode(["status" => "success", "liked" => $liked, "like_count" => (int) $countStmt->fetchColumn()]);
        break;

    case 'my_likes':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $stmt = $db->prepare("SELECT track_id FROM likes WHERE user_id = ?");
        $stmt->execute([$auth['id']]);
        echo json_encode(["liked_ids" => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]);
        break;

    case 'recommendations':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        // full=1 : classement complet plutôt que le top 20 -- voir la même option côté index.php.
        $recoLimit = !empty($_GET['full']) || !empty($_POST['full']) ? PHP_INT_MAX : 20;
        echo json_encode(build_recommendations($db, $auth['id'], $baseUrl, $recoLimit));
        break;

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

    case 'change_password':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$auth['id']]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($currentPassword, $u['password'])) {
            echo json_encode(['status' => 'error', 'message' => "Mot de passe actuel invalide."]); exit;
        }
        if ($newPassword !== $confirmPassword) { echo json_encode(['status' => 'error', 'message' => "Les mots de passe ne correspondent pas."]); exit; }
        if (mb_strlen($newPassword) < 6) { echo json_encode(['status' => 'error', 'message' => "Mot de passe trop court."]); exit; }
        if (mb_strlen($newPassword) > 200) { echo json_encode(['status' => 'error', 'message' => "Mot de passe trop long."]); exit; }

        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $auth['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_reset_password':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $targetId = filter_var($_POST['target_user_id'] ?? '', FILTER_VALIDATE_INT);
        if ($targetId === false) { echo json_encode(['status' => 'error', 'message' => "Utilisateur introuvable."]); exit; }
        if ($targetId == $auth['id']) { echo json_encode(['status' => 'error', 'message' => "Impossible de te modifier toi-même."]); exit; }

        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        if (!$stmt->fetch()) { echo json_encode(['status' => 'error', 'message' => "Utilisateur introuvable."]); exit; }

        $newPassword = bin2hex(random_bytes(6));
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
        echo json_encode(['status' => 'success', 'password' => $newPassword]);
        break;

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
            if (in_array($ext, ['png', 'ico'])) move_uploaded_file($_FILES['adm_favicon']['tmp_name'], __DIR__ . '/favicon.png');
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

    case 'get_lyrics':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["error" => "Non authentifié."]); exit; }

        $trackId = filter_var($_GET['q'] ?? 0, FILTER_VALIDATE_INT);
        if ($trackId === false || $trackId <= 0) { echo json_encode(['error' => "ID de piste invalide"]); exit; }

        $stmt = $db->prepare("SELECT title, artist, lyrics_synced, lyrics_plain, lyrics_checked_at FROM tracks WHERE id = ?");
        $stmt->execute([$trackId]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$track) { echo json_encode(['error' => "Piste introuvable"]); exit; }

        if ($track['lyrics_checked_at'] !== null) {
            $syncedCached = !empty($track['lyrics_synced']) ? $track['lyrics_synced'] : null;
            $plainCached = !empty($track['lyrics_plain']) ? $track['lyrics_plain'] : null;
            echo json_encode(['synced' => $syncedCached, 'plain' => $plainCached, 'found' => ($syncedCached !== null || $plainCached !== null), 'cached' => true]);
            break;
        }

        $queryTitle = html_entity_decode((string) $track['title'], ENT_QUOTES, 'UTF-8');
        $queryArtist = html_entity_decode((string) $track['artist'], ENT_QUOTES, 'UTF-8');
        $lrclibUrl = 'https://lrclib.net/api/get?' . http_build_query(['track_name' => $queryTitle, 'artist_name' => $queryArtist]);

        $synced = null; $plain = null; $found = false; $shouldCache = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($lrclibUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 8,
                // IMPORTANT : lrclib.net (Cloudflare) renvoie 520 pour les User-Agent génériques.
                CURLOPT_USERAGENT => 'PurpleMusic-Web/1.0 (+https://github.com/purplemusic; contact: fujinixx@gmail.com)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);

            if ($curlErrNo === 0 && $response !== false) {
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (is_array($data)) {
                        $synced = !empty($data['syncedLyrics']) ? $data['syncedLyrics'] : null;
                        $plain = !empty($data['plainLyrics']) ? $data['plainLyrics'] : null;
                        $found = ($synced !== null || $plain !== null);
                        $shouldCache = true;
                    }
                } elseif ($httpCode === 404) {
                    $found = false;
                    $shouldCache = true;
                }
            }
        }

        if ($shouldCache) {
            $db->prepare("UPDATE tracks SET lyrics_synced = ?, lyrics_plain = ?, lyrics_checked_at = ? WHERE id = ?")->execute([$synced, $plain, time(), $trackId]);
        }
        echo json_encode(['synced' => $synced, 'plain' => $plain, 'found' => $found, 'cached' => false]);
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
?>