<?php
session_start();

$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);

// --- 1. MODE INSTALLATION (PREMIER LANCEMENT SUR TON THÈME) ---
if (!$isInstalled) {
    if (isset($_POST['install'])) {
        $admin_user = trim($_POST['admin_username'] ?? '');
        $admin_pass = $_POST['admin_password'] ?? '';
        $site_name = trim($_POST['site_name'] ?? 'Purple Music');
        $db_name = trim($_POST['db_name'] ?? 'music_app.db');

        $color_bg = $_POST['inst_color_bg'] ?? '#0f0c1d';
        $color_panel = $_POST['inst_color_panel'] ?? '#1b1429';
        $color_primary = $_POST['inst_color_primary'] ?? '#8e44ad';
        $color_accent = $_POST['inst_color_accent'] ?? '#bb86fc';
        $color_text = $_POST['inst_color_text'] ?? '#e0e0e0';

        if (empty($admin_user) || empty($admin_pass)) {
            $install_error = "Le nom d'utilisateur et le mot de passe admin sont requis.";
        } else {
            $configContent = "<?php\n"
                           . "define('DB_NAME', '" . addslashes($db_name) . "');\n"
                           . "define('MUSIC_DIR', __DIR__ . '/music');\n"
                           . "define('COVER_DIR', __DIR__ . '/covers');\n";
            file_put_contents($configFile, $configContent);

            try {
                $db = new PDO('sqlite:' . $db_name);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT, is_admin INTEGER DEFAULT 0)");
                $db->exec("CREATE TABLE IF NOT EXISTS tracks (id INTEGER PRIMARY KEY, filename TEXT, title TEXT, artist TEXT DEFAULT 'Artiste inconnu', cover TEXT DEFAULT 'default.png', genre TEXT DEFAULT 'Autre', uploader_id INTEGER, upload_date DATETIME DEFAULT CURRENT_TIMESTAMP, play_count INTEGER DEFAULT 0, duration INTEGER DEFAULT 0)");
                $db->exec("CREATE TABLE IF NOT EXISTS playlists (id INTEGER PRIMARY KEY, name TEXT, creator_id INTEGER, song_ids TEXT)");
                $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
                $db->exec("CREATE TABLE IF NOT EXISTS genres (id INTEGER PRIMARY KEY, name TEXT UNIQUE)");

                if(!is_dir(__DIR__ . '/music')) mkdir(__DIR__ . '/music', 0755, true);
                if(!is_dir(__DIR__ . '/covers')) mkdir(__DIR__ . '/covers', 0755, true);

                $favicon_name = 'favicon.png';
                if (!empty($_FILES['inst_favicon']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['inst_favicon']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'ico'])) {
                        move_uploaded_file($_FILES['inst_favicon']['tmp_name'], __DIR__ . '/favicon.png');
                    }
                }

                $cover_name = 'default.png';
                if (!empty($_FILES['inst_default_cover']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['inst_default_cover']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        move_uploaded_file($_FILES['inst_default_cover']['tmp_name'], __DIR__ . '/covers/default.png');
                    }
                }

                $defaultSettings = [
                    'site_name' => $site_name,
                    'color_bg' => $color_bg,
                    'color_panel' => $color_panel,
                    'color_primary' => $color_primary,
                    'color_accent' => $color_accent,
                    'color_text' => $color_text,
                    'color_text_muted' => '#a196b4',
                    'color_border' => '#3d2b56',
                    'color_search_bg' => '#241b36',
                    'color_header_bg' => 'rgba(27, 20, 41, 0.85)',
                    'color_player_bg' => 'rgba(30, 24, 45, 0.85)',
                    'color_mob_nav_bg' => 'rgba(21, 16, 32, 0.95)',
                    'color_fp_gradient_1' => '#302b63',
                    'color_fp_gradient_2' => '#0f0c29',
                    'default_cover' => $cover_name,
                    'favicon' => $favicon_name
                ];
                $stmtSet = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
                foreach($defaultSettings as $k => $v) { $stmtSet->execute([$k, $v]); }

                $defaultGenres = ['Phonk/Funk', 'Rap', 'Pop', 'Rock', 'Electro', 'Hyperpop', 'Nightcore', 'Qualité inférieure', 'Autre'];
                $stmtGen = $db->prepare("INSERT OR IGNORE INTO genres (name) VALUES (?)");
                foreach($defaultGenres as $g) { $stmtGen->execute([$g]); }
                
                $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, is_admin) VALUES (?, ?, 1)");
                $stmt->execute([$admin_user, $hash]);
                $adminId = $db->lastInsertId();

                file_put_contents(__DIR__ . '/music/.htaccess', "RemoveHandler .php .phtml .phps\nDisableEDM\nOptions -ExecCGI\n<Files *>\nSetHandler default-handler\n</Files>");
                file_put_contents(__DIR__ . '/covers/.htaccess', "RemoveHandler .php .phtml .phps\nDisableEDM\nOptions -ExecCGI\n<Files *>\nSetHandler default-handler\n</Files>");

                $_SESSION['user_id'] = $adminId;
                $_SESSION['username'] = $admin_user;
                $_SESSION['is_admin'] = 1;

                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } catch (Exception $e) {
                @unlink($configFile);
                $install_error = "Erreur d'installation : " . $e->getMessage();
            }
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Installation — Purple Music</title>
        <style>
            body { background: #0f0c1d; color: #e0e0e0; font-family: system-ui, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px 0; }
            .box { background: #1b1429; padding: 40px; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.6); width: 100%; max-width: 500px; box-sizing: border-box; }
            h2, h3 { color: #bb86fc; text-align: center; margin-top: 0; }
            h3 { border-bottom: 1px solid #3d2b56; padding-bottom: 8px; margin-top: 25px; font-size: 1.1em; text-align: left; }
            label { font-size: 0.9em; color: #a196b4; display: block; margin-top: 10px; }
            input[type="text"], input[type="password"], input[type="file"] { width: 100%; padding: 12px; margin: 6px 0 16px 0; background: #140f1f; border: 1px solid #3d2b56; color: #fff; border-radius: 10px; box-sizing: border-box; outline: none; }
            .color-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 10px; }
            .color-item { background: #140f1f; padding: 10px; border-radius: 10px; border: 1px solid #3d2b56; display: flex; align-items: center; justify-content: space-between; }
            input[type="color"] { border: none; width: 40px; height: 30px; background: transparent; cursor: pointer; }
            button { width: 100%; padding: 14px; background: #8e44ad; border: none; color: white; font-weight: bold; font-size: 1em; border-radius: 50px; cursor: pointer; transition: 0.2s; margin-top: 20px; }
            button:hover { background: #9b59b6; }
            .error { color: #ff4757; text-align: center; font-size: 0.9em; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="box">
            <h2>Configuration Initiale</h2>
            <?php if(isset($install_error)) echo '<div class="error">'.$install_error.'</div>'; ?>
            <form method="post" enctype="multipart/form-data">
                <h3>Général</h3>
                <label>Nom du Site</label>
                <input type="text" name="site_name" value="Purple Music" required>
                <label>Base de données (SQLite)</label>
                <input type="text" name="db_name" value="music_app.db" required>

                <h3>Compte Administrateur</h3>
                <label>Identifiant Admin</label>
                <input type="text" name="admin_username" placeholder="ex: Axolat" required>
                <label>Mot de passe Admin</label>
                <input type="password" name="admin_password" required>

                <h3>Thème & Personnalisation</h3>
                <div class="color-grid">
                    <div class="color-item"><span>Arrière-plan</span><input type="color" name="inst_color_bg" value="#0f0c1d"></div>
                    <div class="color-item"><span>Panneaux</span><input type="color" name="inst_color_panel" value="#1b1429"></div>
                    <div class="color-item"><span>Primaire</span><input type="color" name="inst_color_primary" value="#8e44ad"></div>
                    <div class="color-item"><span>Accent</span><input type="color" name="inst_color_accent" value="#bb86fc"></div>
                </div>
                <label>Couleur du texte</label>
                <input type="color" name="inst_color_text" value="#e0e0e0" style="width:100%; height:40px; background:#140f1f; padding:5px; border:1px solid #3d2b56; border-radius:10px;">

                <h3>Assets Médias</h3>
                <label>Favicon (.png/.ico)</label>
                <input type="file" name="inst_favicon" accept="image/png, image/x-icon">
                <label>Couverture par défaut (.png)</label>
                <input type="file" name="inst_default_cover" accept="image/*">

                <button type="submit" name="install">Installer et démarrer</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- 2. CONFIGURATION ET BASE DE DONNÉES ---
require_once $configFile;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function checkRateLimit($action, $limitSeconds) {
    $key = 'last_' . $action . '_time';
    if (isset($_SESSION[$key]) && (time() - $_SESSION[$key]) < $limitSeconds) {
        return false;
    }
    $_SESSION[$key] = time();
    return true;
}

try {
    $db = new PDO('sqlite:' . DB_NAME);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS genres (id INTEGER PRIMARY KEY, name TEXT UNIQUE)");
    
    $settingsRaw = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name = $settingsRaw['site_name'] ?? 'Purple Music';
    
    // Initialisation absolue de toutes les variables de couleurs
    $color_bg = $settingsRaw['color_bg'] ?? '#0f0c1d';
    $color_panel = $settingsRaw['color_panel'] ?? '#1b1429';
    $color_primary = $settingsRaw['color_primary'] ?? '#8e44ad';
    $color_accent = $settingsRaw['color_accent'] ?? '#bb86fc';
    $color_text = $settingsRaw['color_text'] ?? '#e0e0e0';
    $color_text_muted = $settingsRaw['color_text_muted'] ?? '#a196b4';
    $color_border = $settingsRaw['color_border'] ?? '#3d2b56';
    $color_search_bg = $settingsRaw['color_search_bg'] ?? '#241b36';
    $color_header_bg = $settingsRaw['color_header_bg'] ?? 'rgba(27, 20, 41, 0.85)';
    $color_player_bg = $settingsRaw['color_player_bg'] ?? 'rgba(30, 24, 45, 0.85)';
    $color_mob_nav_bg = $settingsRaw['color_mob_nav_bg'] ?? 'rgba(21, 16, 32, 0.95)';
    $color_fp_gradient_1 = $settingsRaw['color_fp_gradient_1'] ?? '#302b63';
    $color_fp_gradient_2 = $settingsRaw['color_fp_gradient_2'] ?? '#0f0c29';
    
    $default_cover = $settingsRaw['default_cover'] ?? 'default.png';
    $favicon_file = $settingsRaw['favicon'] ?? 'favicon.png';

    $genresList = $db->query("SELECT name FROM genres ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    if(empty($genresList)) {
        $genresList = ['Phonk/Funk', 'Rap', 'Pop', 'Rock', 'Electro', 'Hyperpop', 'Nightcore', 'Qualité inférieure', 'Autre'];
    }

} catch (Exception $e) { die("Erreur BDD : " . $e->getMessage()); }

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;

$is_admin = false;
if ($user_id) {
    $stmtAdmin = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmtAdmin->execute([$user_id]);
    $isAdminCol = $stmtAdmin->fetchColumn();
    $is_admin = ($isAdminCol == 1 || $username === 'Axolat');
}

if (isset($_GET['increment_play'])) {
    $stmt = $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?");
    $stmt->execute([$_GET['increment_play']]);
    exit;
}

// --- FONCTIONS DE CALCUL & EXTRACTION ID3 ---
function calculateAudioDuration($path) {
    if (!file_exists($path)) return 0;
    $fp = fopen($path, 'rb'); if (!$fp) return 0;
    $signature = fread($fp, 4);
    if ($signature === 'fLaC') {
        fseek($fp, 8); $streamInfo = fread($fp, 34); fclose($fp);
        if (strlen($streamInfo) === 34) {
            $fields = unpack('N3', substr($streamInfo, 10, 12));
            $sampleRate = ($fields[1] >> 12) & 0xFFFFF; $totalSamples = (($fields[1] & 0x00F) << 32) | $fields[2];
            if ($sampleRate > 0) return round($totalSamples / $sampleRate);
        }
        return 0;
    }
    if (strpos($signature, 'ftyp') !== false || substr($signature, 1, 3) === 'ftyp') {
        fseek($fp, 0); $content = fread($fp, 1024 * 400); $mvhdPos = strpos($content, 'mvhd'); fclose($fp);
        if ($mvhdPos !== false) {
            $version = ord($content[$mvhdPos + 4]);
            $timeScaleOffset = ($version === 1) ? 20 : 12; $durationOffset = ($version === 1) ? 24 : 16;
            $timeScale = unpack('N', substr($content, $mvhdPos + 4 + $timeScaleOffset, 4))[1];
            $durationUnits = unpack('N', substr($content, $mvhdPos + 4 + $durationOffset, 4))[1];
            if ($timeScale > 0) return round($durationUnits / $timeScale);
        }
        return 0;
    }
    fseek($fp, 0); $header = fread($fp, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $b = unpack('C*', substr($header, 6, 4)); $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
        fseek($fp, $tagSize + 10);
    } else { fseek($fp, 0); }
    $data = fread($fp, 1024 * 200); $offset = 0;
    while ($offset < strlen($data) - 4) {
        if (ord($data[$offset]) === 0xFF && (ord($data[$offset+1]) & 0xE0) === 0xE0) {
            $byte1 = ord($data[$offset+1]); $byte2 = ord($data[$offset+2]); $mpegVersion = ($byte1 >> 3) & 0x03;
            $channelMode = ($byte2 >> 6) & 0x03; $xingOffset = ($mpegVersion === 3) ? (($channelMode === 3) ? 17 : 32) : (($channelMode === 3) ? 9 : 17);
            $vbrCheck = substr($data, $offset + 4 + $xingOffset, 4);
            if ($vbrCheck === 'Xing' || $vbrCheck === 'Info') {
                $flags = unpack('N', substr($data, $offset + 4 + $xingOffset + 4, 4))[1];
                if ($flags & 0x01) {
                    $frameCount = unpack('N', substr($data, $offset + 4 + $xingOffset + 8, 4))[1];
                    $srTable = [3 => [44100, 48000, 32000, 0], 2 => [22050, 24000, 16000, 0]];
                    $sampleRate = $srTable[$mpegVersion][($byte2 >> 2) & 0x03] ?? 44100; $samplesPerFrame = ($mpegVersion === 3) ? 1152 : 576;
                    fclose($fp); if ($sampleRate > 0) return round(($frameCount * $samplesPerFrame) / $sampleRate);
                }
            }
            $brTable = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0]; $bitrate = $brTable[($byte2 >> 4) & 0x0F] ?? 128;
            fclose($fp); if ($bitrate > 0) return round((filesize($path) * 8) / ($bitrate * 1000));
            break;
        }
        $offset++;
    }
    fclose($fp); return round((filesize($path) * 8) / (128 * 1000));
}

function extractMp3Data($path) {
    if (!file_exists($path)) return [];
    $f = fopen($path, 'rb'); if (!$f) return [];
    $header = fread($f, 10); if (substr($header, 0, 3) !== 'ID3') { fclose($f); return []; }
    $b = unpack('C*', substr($header, 6, 4)); $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
    $tagData = fread($f, $tagSize); fclose($f);
    $result = ['cover' => null, 'artist' => null, 'title' => null]; $pos = 0;
    while ($pos < $tagSize) {
        if ($pos + 10 > strlen($tagData)) break;
        $frameHeader = substr($tagData, $pos, 10); $frameName = substr($frameHeader, 0, 4);
        $s = unpack('N', substr($frameHeader, 4, 4)); $frameSize = $s[1];
        if ($frameSize == 0 || $frameName == "\x00\x00\x00\x00") break;
        if ($pos + 10 + $frameSize > strlen($tagData)) break;
        if ($frameName === 'APIC') {
            $frameBody = substr($tagData, $pos + 10, $frameSize); $nullPos = strpos($frameBody, "\x00", 1); 
            if ($nullPos !== false) {
                $mime = substr($frameBody, 1, $nullPos - 1); $picTypePos = $nullPos + 1; $imgStart = 0;
                $jpgPos = strpos($frameBody, "\xFF\xD8", $picTypePos); $pngPos = strpos($frameBody, "\x89PNG", $picTypePos);
                if ($mime === 'image/jpeg' || $mime === 'image/jpg') { if ($jpgPos !== false) $imgStart = $jpgPos; }
                elseif ($mime === 'image/png') { if ($pngPos !== false) $imgStart = $pngPos; }
                else {
                    if ($jpgPos !== false && ($pngPos === false || $jpgPos < $pngPos)) { $imgStart = $jpgPos; $mime = 'image/jpeg'; }
                    elseif ($pngPos !== false) { $imgStart = $pngPos; $mime = 'image/png'; }
                }
                if ($imgStart > 0) $result['cover'] = ['mime' => $mime, 'data' => substr($frameBody, $imgStart)];
            }
        }
        if ($frameName === 'TPE1') {
            $frameBody = substr($tagData, $pos + 10, $frameSize);
            if (strlen($frameBody) > 1) {
                $rawText = substr($frameBody, 1); $cleanText = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $rawText));
                if (!empty($cleanText)) $result['artist'] = $cleanText;
            }
        }
        if ($frameName === 'TIT2') {
            $frameBody = substr($tagData, $pos + 10, $frameSize);
            if (strlen($frameBody) > 1) {
                $rawText = substr($frameBody, 1); $cleanText = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $rawText));
                if (!empty($cleanText)) $result['title'] = $cleanText;
            }
        }
        $pos += 10 + $frameSize;
    }
    return $result;
}

function optimizeImage($sourcePath, $destinationPath, $mime = null) {
    if (!extension_loaded('gd')) return move_uploaded_file($sourcePath, $destinationPath);
    $info = getimagesize($sourcePath); if (!$info) return false;
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
        $new_width = round($width * $ratio); $new_height = round($height * $ratio);
        $new_image = imagecreatetruecolor($new_width, $new_height);
        if ($mime == 'image/png') { imagealphablending($new_image, false); imagesavealpha($new_image, true); }
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image); $image = $new_image;
    }
    $success = imagewebp($image, $destinationPath, 80); imagedestroy($image);
    if (!$success) move_uploaded_file($sourcePath, $destinationPath);
    return true;
}

if (isset($_GET['get_playlist_tracks'])) {
    $ids = explode(',', $_GET['get_playlist_tracks']);
    if (!empty($ids[0])) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, filename, title, artist, cover, genre, play_count, duration FROM tracks WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($tracks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    } else { echo json_encode([]); }
    exit;
}

// --- SECURE AUTHENTICATION ---
if (isset($_POST['register'])) {
    if (!checkRateLimit('register', 30)) { $error = "Veuillez patienter."; } else {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        try { $stmt->execute([$_POST['username'], $hash]); } catch (Exception $e) { $error = "Nom déjà pris."; }
    }
}
if (isset($_POST['login'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]); $u = $stmt->fetch();
    if ($u && password_verify($_POST['password'], $u['password'])) {
        $_SESSION['user_id'] = $u['id']; $_SESSION['username'] = $u['username'];
        $_SESSION['is_admin'] = $u['is_admin'] ?? 0;
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit; }

// --- CONTROLES OPÉRATIONNELS COMPLETS ---
if ($user_id) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) { die("CSRF Invalide."); }
    }

    // Sauvegarde de configuration étendue Admin (Inclut absolument toutes les couleurs de l'UI)
    if ($is_admin && isset($_POST['save_admin_settings'])) {
        $stmtUpdate = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmtUpdate->execute(['site_name', trim($_POST['adm_site_name'])]);
        $stmtUpdate->execute(['color_bg', $_POST['adm_color_bg']]);
        $stmtUpdate->execute(['color_panel', $_POST['adm_color_panel']]);
        $stmtUpdate->execute(['color_primary', $_POST['adm_color_primary']]);
        $stmtUpdate->execute(['color_accent', $_POST['adm_color_accent']]);
        $stmtUpdate->execute(['color_text', $_POST['adm_color_text']]);
        $stmtUpdate->execute(['color_text_muted', $_POST['adm_color_text_muted']]);
        $stmtUpdate->execute(['color_border', $_POST['adm_color_border']]);
        $stmtUpdate->execute(['color_search_bg', $_POST['adm_color_search_bg']]);
        $stmtUpdate->execute(['color_header_bg', $_POST['adm_color_header_bg']]);
        $stmtUpdate->execute(['color_player_bg', $_POST['adm_color_player_bg']]);
        $stmtUpdate->execute(['color_mob_nav_bg', $_POST['adm_color_mob_nav_bg']]);
        $stmtUpdate->execute(['color_fp_gradient_1', $_POST['adm_color_fp_gradient_1']]);
        $stmtUpdate->execute(['color_fp_gradient_2', $_POST['adm_color_fp_gradient_2']]);

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
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }

    if ($is_admin && isset($_GET['delete_genre'])) {
        $db->prepare("DELETE FROM genres WHERE name = ?")->execute([$_GET['delete_genre']]);
        header("Location: " . $_SERVER['PHP_SELF']); exit;
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
        header("Location: " . $_SERVER['PHP_SELF']); exit;
    }
}

$all_tracks = $db->query("SELECT tracks.*, users.username as uploader_name FROM tracks JOIN users ON tracks.uploader_id = users.id ORDER BY play_count DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$all_playlists = $db->query("SELECT playlists.*, users.username FROM playlists JOIN users ON playlists.creator_id = users.id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_file); ?>?v=<?php echo time(); ?>">
    <style>
        :root { 
            --bg-dark: <?php echo $color_bg; ?>; 
            --bg-panel: <?php echo $color_panel; ?>; 
            --primary: <?php echo $color_primary; ?>; 
            --accent: <?php echo $color_accent; ?>; 
            --text: <?php echo $color_text; ?>; 
            --text-muted: <?php echo $color_text_muted; ?>;
            --border-color: <?php echo $color_border; ?>;
            --search-bg: <?php echo $color_search_bg; ?>;
            --header-bg: <?php echo $color_header_bg; ?>;
            --mob-nav-bg: <?php echo $color_mob_nav_bg; ?>;
            --danger: #ff4757; 
            --radius-sm: 8px; --radius-md: 16px; --radius-lg: 24px; --radius-full: 9999px;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { margin:0; font-family:'Segoe UI', system-ui, -apple-system, sans-serif; background: var(--bg-dark); color: var(--text); padding-bottom: 160px; overflow-x: hidden; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: var(--radius-full); }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

        header { display:flex; justify-content:space-between; padding:15px 30px; background: var(--header-bg); backdrop-filter: blur(15px); border-bottom: 1px solid rgba(61, 43, 86, 0.5); align-items:center; position:sticky; top:0; z-index:100; height: 70px; }
        .logo { font-weight: 800; font-size: 1.6em; color: var(--accent); white-space: nowrap; letter-spacing: -1px; }
        nav { display:flex; gap:25px; margin-left: 40px; flex-grow: 1; }
        nav span { cursor:pointer; font-weight: 600; color: var(--text-muted); transition: 0.3s; white-space: nowrap; padding: 5px 10px; border-radius: var(--radius-sm); }
        nav span:hover { color: white; background: rgba(255,255,255,0.05); }
        nav span.active { color: var(--accent); }
        nav span.admin-nav-btn { color: #e67e22; font-weight: 700; }
        .header-actions { display:flex; gap:12px; align-items:center; }
        
        .btn { padding:10px 20px; border-radius: var(--radius-full); border:none; cursor:pointer; font-weight:700; transition: all 0.2s ease; text-decoration: none; display: inline-flex; align-items: center; font-size: 0.9em; white-space: nowrap; justify-content: center; }
        .btn:active { transform: scale(0.96); }
        .btn-primary { background: var(--primary); color: white; box-shadow: 0 4px 15px rgba(142, 68, 173, 0.3); }
        .btn-primary:hover { background: #9b59b6; }
        .btn-outline { background: transparent; border: 1px solid var(--primary); color: var(--accent); }
        .btn-outline:hover { background: rgba(142, 68, 173, 0.1); }
        .btn-danger { background: rgba(255, 71, 87, 0.1); color: var(--danger); font-size: 0.75em; border: 1px solid rgba(255, 71, 87, 0.3); padding: 6px 12px; }
        
        main { padding:30px; max-width:1100px; margin:auto; transition: 0.5s; }
        .controls-container { margin-bottom: 25px; }
        .section-title { border-left: 5px solid var(--primary); padding-left: 15px; margin-bottom: 20px; font-size: 1.5em; letter-spacing: 0.5px; border-radius: 2px; }

        .search-row { display: flex; align-items: center; gap: 15px; width: 100%; position: relative; }
        .search-container { flex-grow: 1; position: relative; }
        .search-input { width: 100%; height: 50px; padding: 0 25px; border-radius: 50px; border: 1px solid rgba(61, 43, 86, 0.5); background: var(--search-bg); color: white; font-size: 1em; outline: none; transition: all 0.3s; box-shadow: 0 4px 10px rgba(0,0,0,0.2); line-height: 48px; }
        .search-input:focus { border-color: var(--accent); background: #2d2444; box-shadow: 0 0 0 3px rgba(187, 134, 252, 0.2); }
        .search-input::placeholder { color: #6b5e85; }

        .filter-wrapper { position: relative; width: 50px; height: 50px; flex-shrink: 0; }
        .filter-icon-visual { width: 100%; height: 100%; background: var(--search-bg); border: 1px solid rgba(61, 43, 86, 0.5); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--accent); box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: 0.3s; }
        .filter-select-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; appearance: none; -webkit-appearance: none; z-index: 10; }
        .filter-wrapper:hover .filter-icon-visual { border-color: var(--accent); background: #32264d; transform: translateY(-2px); }
        
        .track-list { background: var(--bg-panel); border-radius: 24px; overflow: hidden; border: 1px solid #2d2444; min-height: 200px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .track-item { display:grid; grid-template-columns: 40px 50px 1fr auto; align-items:center; padding:15px 25px; border-bottom:1px solid rgba(255,255,255,0.03); gap:20px; transition: background 0.2s; }
        .track-item:last-child { border-bottom: none; }
        .track-item:hover { background: rgba(255,255,255,0.07); }
        
        .mini-cover { width: 50px; height: 50px; border-radius: 12px; object-fit: cover; box-shadow: 0 4px 8px rgba(0,0,0,0.3); }
        .track-index { color: var(--primary); font-weight: 700; opacity: 0.7; }
        #load-more-trigger { height: 40px; text-align: center; color: #6b5e85; padding-top: 15px; font-size: 0.9em; }

        .playlist-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 25px; }
        .playlist-card { background: var(--bg-panel); border-radius: 24px; padding: 25px; border: 1px solid rgba(61, 43, 86, 0.5); transition: transform 0.3s, box-shadow 0.3s; }
        .playlist-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.4); border-color: var(--primary); }
        
        #queue-panel { position: fixed; right: -360px; top: 70px; width: 340px; height: calc(100vh - 70px); background: rgba(27, 20, 41, 0.95); backdrop-filter: blur(20px); border-left: 1px solid rgba(255,255,255,0.1); z-index: 999; transition: 0.4s cubic-bezier(0.4, 0, 0.2, 1); padding: 25px; padding-bottom: 120px; box-shadow: -10px 0 40px rgba(0,0,0,0.5); overflow-y: auto; }
        #queue-panel.open { right: 0; }
        .queue-item { display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 12px; margin-bottom: 8px; cursor: pointer; border: 1px solid transparent; transition: 0.2s; }
        .queue-item.active { background: rgba(142, 68, 173, 0.15); border-color: var(--primary); }
        .queue-item:hover { background: rgba(255,255,255,0.05); }
        .close-queue-mobile { display: none; width: 100%; margin-bottom: 20px; background: #2d2444; border:none; color: white; padding: 12px; border-radius: var(--radius-sm); font-weight:bold; }

        #player-bar { position:fixed; bottom:25px; left: 50%; transform: translateX(-50%); width:94%; max-width: 1000px; background: <?php echo $color_player_bg; ?>; backdrop-filter: blur(20px) saturate(180%); padding:15px 30px; border-radius: 20px; display:flex; align-items:center; z-index: 1000; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 15px 50px rgba(0,0,0,0.6); transition: all 0.3s; }
        .player-info { display: flex; align-items: center; gap: 15px; width: 25%; min-width: 180px; }
        #player-cover { width: 56px; height: 56px; border-radius: 12px; object-fit: cover; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .progress-container { flex-grow: 1; margin: 0 20px; }
        .progress-bg { background: rgba(255,255,255,0.1); height: 6px; border-radius: 10px; cursor: pointer; position: relative; overflow: hidden; }
        .progress-fill { background: linear-gradient(90deg, var(--primary), var(--accent)); height: 100%; width: 0%; border-radius: 10px; }
        
        .controls { display:flex; align-items:center; gap:12px; }
        .control-btn { background: none; border: none; color: white; cursor: pointer; opacity: 0.8; transition: 0.2s; padding: 8px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .control-btn svg { width: 24px; height: 24px; fill: white; display: block; }
        .control-btn:hover { background: rgba(255,255,255,0.1); opacity: 1; }
        .control-btn.active { color: var(--accent); opacity: 1; position:relative; }
        .control-btn.active svg { fill: var(--accent); }
        .control-btn.active::after { content:''; position:absolute; bottom:0; left:50%; transform:translateX(-50%); width:4px; height:4px; background:var(--accent); border-radius:50%; }
        
        #masterPlay { background: white; border: none; width: 50px; height: 50px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 0 20px rgba(255,255,255,0.3); flex-shrink: 0; }
        #masterPlay:hover { transform: scale(1.1); box-shadow: 0 0 30px rgba(255,255,255,0.5); }
        #masterPlay svg { fill: #0f0c1d; width: 22px; height: 22px; }

        .volume-container { display: flex; align-items: center; gap: 8px; width: 110px; margin-left: 10px;}
        .fp-volume-container { width: 100%; margin: 15px 0 25px 0; justify-content: center;}
        input[type="range"].vol-slider { -webkit-appearance: none; width: 100%; height: 4px; background: linear-gradient(90deg, var(--accent) 100%, rgba(255,255,255,0.2) 100%); border-radius: 5px; outline: none; cursor: pointer; }
        input[type="range"].vol-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; background: #fff; border-radius: 50%; cursor: pointer; transition: 0.2s; box-shadow: 0 0 5px rgba(0,0,0,0.5); }
        input[type="range"].vol-slider::-webkit-slider-thumb:hover { transform: scale(1.3); }
        
        .modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter: blur(8px); }
        .modal-content { background: #1e162e; margin: 5% auto; padding:30px; width:90%; max-width:550px; border-radius: 28px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 80px rgba(0,0,0,0.5); max-height: 85vh; overflow-y: auto; animation: modalPop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        input[type="text"], input[type="password"], input[type="file"], select { width:100%; padding:14px; margin:10px 0 20px 0; background:#140f1f; border:1px solid var(--border-color); color:#fff; border-radius: 12px; outline:none; transition: 0.3s; }
        input[type="text"]:focus, input[type="password"]:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(142, 68, 173, 0.2); }

        /* --- SYSTÈME D'ACCORDÉON DE RUBRIQUES DÉROULANTES --- */
        .adm-accordion-item { border: 1px solid var(--border-color); border-radius: 14px; margin-bottom: 12px; background: rgba(0,0,0,0.15); overflow: hidden; }
        .adm-accordion-header { background: rgba(255,255,255,0.02); padding: 16px 20px; font-weight: bold; font-size: 1.05em; color: var(--accent); cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; transition: background 0.2s; }
        .adm-accordion-header:hover { background: rgba(255,255,255,0.05); }
        .adm-accordion-header::after { content: '▼'; font-size: 0.8em; opacity: 0.7; transition: transform 0.3s ease; }
        .adm-accordion-item.open .adm-accordion-header::after { transform: rotate(-180deg); color: white; }
        .adm-accordion-content { padding: 20px; display: none; border-top: 1px solid rgba(255,255,255,0.05); }

        /* --- CORRECTION ET ENRICHISSEMENT DE LA GRILLE DE COULEURS ADM --- */
        .extended-color-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 10px; }
        .extended-color-item { background: #140f1f; padding: 12px 15px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .extended-color-item span { font-size: 0.85em; color: var(--text-muted); font-weight: 500; }
        /* Fix de taille strict pour éviter que l'input écrase le texte */
        .extended-color-item input[type="color"] { border: none; width: 45px !important; height: 35px !important; background: transparent; cursor: pointer; padding: 0; box-shadow: 0 2px 5px rgba(0,0,0,0.4); border-radius: 6px; margin: 0; flex-shrink: 0; }

        .song-select-container { max-height: 300px; overflow-y: auto; margin-top: 15px; border: 1px solid var(--border-color); border-radius: 16px; background: #140f1f; }
        .song-select-item { display: flex; align-items: center; padding: 12px; border-bottom: 1px solid rgba(255,255,255,0.05); cursor: pointer; transition: 0.2s; }
        .song-select-item:hover { background: rgba(255,255,255,0.05); }
        .song-select-item.selected { background: rgba(142, 68, 173, 0.2); }
        
        #full-player { position: fixed; top: 100%; left: 0; width: 100%; height: 100%; background: radial-gradient(circle at top right, <?php echo $color_fp_gradient_1; ?>, <?php echo $color_fp_gradient_2; ?>); z-index: 5000; transition: top 0.4s cubic-bezier(0.2, 0.8, 0.2, 1); display: flex; flex-direction: column; padding: 30px; box-sizing: border-box; color: white; justify-content: space-between; }
        #full-player.active { top: 0; }
        .fp-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .fp-btn { background: none; border: none; cursor: pointer; padding: 10px; border-radius:50%; }
        .fp-btn:active { background: rgba(255,255,255,0.1); }
        .fp-art-container { flex-grow: 1; display: flex; align-items: center; justify-content: center; margin: 20px 0; max-height: 45vh; }
        #fp-cover { width: 100%; height: auto; aspect-ratio: 1/1; object-fit: cover; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); max-width: 350px; }
        .fp-info-area { text-align: left; margin-bottom: 30px; padding: 0 10px; overflow: hidden; } 
        #fp-title { font-size: 1.8em; font-weight: 800; margin-bottom: 5px; white-space: nowrap; overflow: hidden; width: 100%; position: relative; mask-image: linear-gradient(to right, transparent 0%, black 5%, black 95%, transparent 100%); -webkit-mask-image: linear-gradient(to right, transparent 0%, black 5%, black 95%, transparent 100%); }
        #fp-title span { display: inline-block; padding-left: 0; }
        .scrolling-active { padding-left: 100%; animation: marquee 12s linear infinite; }
        @keyframes marquee { 0% { transform: translate(0, 0); } 100% { transform: translate(-100%, 0); } }
        
        .fp-controls { display: flex; justify-content: space-between; align-items: center; padding: 0 10px 20px 10px; width: 100%; box-sizing: border-box; }

        #mobile-bottom-nav { display: none; position: fixed; bottom: 0; left: 0; width: 100%; background: var(--mob-nav-bg); backdrop-filter: blur(15px); border-top: 1px solid rgba(255,255,255,0.05); z-index: 3000; justify-content: space-around; padding: 10px 0 15px 0; height: 70px; box-sizing: border-box; box-shadow: 0 -10px 30px rgba(0,0,0,0.2); }
        .mob-nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); font-size: 0.7em; background: none; border: none; gap: 5px; font-weight: 600; width: 20%; }
        .mob-nav-item svg { width: 24px; height: 24px; fill: currentColor; transition: transform 0.2s; }
        .mob-nav-item.active { color: var(--accent); }
        .mob-nav-item:active svg { transform: scale(0.9); }

        .mobile-settings-btn { display:none; }
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .settings-grid label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.95em; }
        .settings-grid input[type="checkbox"] { width: 18px; height: 18px; margin: 0; }
        .adm-genre-item { display: flex; justify-content: space-between; align-items: center; padding: 8px; background: rgba(0,0,0,0.2); margin-bottom: 5px; border-radius: 8px; border: 1px solid var(--border-color); }

        @media (max-width: 768px) {
            body { padding-bottom: 240px; } 
            header { display: flex; justify-content: center; align-items: center; height: 60px; padding: 10px 20px; position: relative; }
            nav, .header-actions { display: none; }
            .mobile-settings-btn { display: block; position: absolute; right: 20px; background: none; border: none; padding: 5px; cursor: pointer; }
            .mobile-settings-btn svg { width: 24px; height: 24px; fill: #a196b4; }
            main { padding: 20px; width: 100%; box-sizing: border-box; }
            .search-row { gap: 10px; }
            .search-input { padding: 0 20px; font-size: 0.95em; height: 50px; line-height: 48px; }
            .track-item { grid-template-columns: 50px 1fr auto; padding: 12px 10px; gap: 12px; }
            .track-index { display: none; }
            #player-bar { width: calc(100% - 20px); max-width: 100%; bottom: 80px; left: 50%; transform: translateX(-50%); border-radius: 16px; flex-direction: column; padding: 12px 15px; box-sizing: border-box; height: auto; background: var(--player-bg); border: 1px solid rgba(255,255,255,0.08); }
            .player-info { width: 100%; justify-content: flex-start; margin-bottom: 5px; }
            .progress-container { width: 100%; margin: 8px 0 12px 0; }
            .controls { width: 100%; justify-content: space-between; gap: 0; padding: 0 10px; box-sizing: border-box; }
            .control-btn { padding: 5px; }
            #masterPlay { width: 45px; height: 45px; }
            #player-bar .volume-container { display: none; }
            #queue-panel { width: 100%; right: -100%; top: 0; height: 100%; z-index: 2000; border-left: none; }
            .close-queue-mobile { display: block; }
            .modal-content { width: 90%; margin: 8% auto; padding: 25px; }
            .settings-grid { grid-template-columns: 1fr; }
            #mobile-bottom-nav { display: flex; }
            .extended-color-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php if (!$user_id): ?>
    <div style="max-width:350px; width: 90%; margin:100px auto; text-align:center;">
        <div class="logo" style="font-size:3em; margin-bottom:30px;"><?php echo htmlspecialchars($site_name); ?></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <?php if(isset($error)) echo "<p style='color:var(--danger);'>$error</p>"; ?>
            <input type="text" name="username" placeholder="Utilisateur" required style="padding:15px; border-radius:12px;">
            <input type="password" name="password" placeholder="Mot de passe" required style="padding:15px; border-radius:12px;">
            <button type="submit" name="login" class="btn btn-primary" style="width:100%; justify-content:center; margin-top:10px; padding:15px;">Connexion</button>
            <button type="submit" name="register" class="btn btn-outline" style="width:100%; justify-content:center; margin-top:15px; padding:15px;">Créer un compte</button>
        </form>
    </div>
<?php else: ?>

    <header>
        <div class="logo"><?php echo htmlspecialchars($site_name); ?> <?php if($is_admin) echo "<small style='color:gold; font-size:10px; vertical-align:middle;'>ADMIN</small>"; ?></div>
        <nav>
            <span id="nav-accueil" class="active" onclick="showSection('accueil')">Bibliothèque</span>
            <span id="nav-playlists" onclick="showSection('playlists')">Playlists</span>
            <?php if($is_admin): ?>
                <span class="admin-nav-btn" style="cursor:pointer;" onclick="openModal('adminPanelModal')">⚙️ Panel Admin</span>
            <?php endif; ?>
        </nav>
        <div class="header-actions">
            <button class="btn btn-outline" id="queue-toggle" onclick="toggleQueue()">File</button>
            <button class="btn btn-primary" onclick="openCreateModal()">+ Mix</button>
            <button class="btn btn-outline" onclick="openModal('uploadModal')">Upload</button>
            <button class="btn btn-outline" onclick="openModal('settingsModal')" title="Paramètres" style="padding: 10px; border-radius: 50%;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.4 13c.0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6l-2.1-1.7zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
            </button>
            <a href="?logout=1" class="btn" style="color:#a196b4;">Sortir</a>
        </div>
        <button class="mobile-settings-btn" onclick="openModal('settingsModal')">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19.4 13c.0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6l-2.1-1.7zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
        </button>
    </header>

    <?php if($is_admin): ?>
    <div id="adminPanelModal" class="modal"><div class="modal-content">
        <h2 style="margin-top:0; color:#e67e22;">Configuration Système</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            
            <div class="adm-accordion-item open">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Général</div>
                <div class="adm-accordion-content" style="display: block;">
                    <label>Nom de l'application</label>
                    <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Thème Visuel (Couleurs)</div>
                <div class="adm-accordion-content">
                    <div class="extended-color-grid">
                        <div class="extended-color-item"><span>Arrière-plan Global</span><input type="color" name="adm_color_bg" value="<?php echo $color_bg; ?>"></div>
                        <div class="extended-color-item"><span>Panneaux & Cards</span><input type="color" name="adm_color_panel" value="<?php echo $color_panel; ?>"></div>
                        <div class="extended-color-item"><span>Couleur Primaire</span><input type="color" name="adm_color_primary" value="<?php echo $color_primary; ?>"></div>
                        <div class="extended-color-item"><span>Couleur Accent</span><input type="color" name="adm_color_accent" value="<?php echo $color_accent; ?>"></div>
                        <div class="extended-color-item"><span>Texte Principal</span><input type="color" name="adm_color_text" value="<?php echo $color_text; ?>"></div>
                        <div class="extended-color-item"><span>Texte Sombre/Muted</span><input type="color" name="adm_color_text_muted" value="<?php echo $color_text_muted; ?>"></div>
                        <div class="extended-color-item"><span>Bordures & Lignes</span><input type="color" name="adm_color_border" value="<?php echo $color_border; ?>"></div>
                        <div class="extended-color-item"><span>Fond Barre Recherche</span><input type="color" name="adm_color_search_bg" value="<?php echo $color_search_bg; ?>"></div>
                        
                        <div class="extended-color-item"><span>Gradient Full Player 1</span><input type="color" name="adm_color_fp_gradient_1" value="<?php echo $color_fp_gradient_1; ?>"></div>
                        <div class="extended-color-item"><span>Gradient Full Player 2</span><input type="color" name="adm_color_fp_gradient_2" value="<?php echo $color_fp_gradient_2; ?>"></div>
                    </div>
                    
                    <label style="margin-top: 12px; display: block;">Fond de la barre du haut (Header Desktop & Mobile)</label>
                    <input type="text" name="adm_color_header_bg" value="<?php echo htmlspecialchars($color_header_bg); ?>" placeholder="rgba(27, 20, 41, 0.85)">
                    
                    <label style="margin-top: 10px; display: block;">Fond du Mini-Lecteur (Barre du bas)</label>
                    <input type="text" name="adm_color_player_bg" value="<?php echo htmlspecialchars($color_player_bg); ?>" placeholder="rgba(30, 24, 45, 0.85)">

                    <label style="margin-top: 10px; display: block;">Fond de la barre de navigation Téléphone</label>
                    <input type="text" name="adm_color_mob_nav_bg" value="<?php echo htmlspecialchars($color_mob_nav_bg); ?>" placeholder="rgba(21, 16, 32, 0.95)">
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Remplacement Assets Médias</div>
                <div class="adm-accordion-content">
                    <label>Nouveau Favicon (.png / .ico)</label>
                    <input type="file" name="adm_favicon" accept="image/png, image/x-icon">
                    <label>Nouvelle Cover par défaut (.png)</label>
                    <input type="file" name="adm_default_cover" accept="image/png">
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Gestionnaire des Genres</div>
                <div class="adm-accordion-content">
                    <label>Créer un genre personnalisé</label>
                    <input type="text" name="adm_new_genre" placeholder="ex: Phonk, Ambient...">
                    
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Genres actifs (cliquer pour supprimer) :</label>
                    <div style="max-height:160px; overflow-y:auto; border:1px solid var(--border-color); padding:10px; border-radius:10px;">
                        <?php foreach($genresList as $g): ?>
                            <div class="adm-genre-item">
                                <span><?php echo htmlspecialchars($g); ?></span>
                                <a href="?delete_genre=<?php echo urlencode($g); ?>" style="color:var(--danger); text-decoration:none; font-weight:bold;" onclick="return confirm('Détruire ce genre musical ?')">✕</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:15px; margin-top: 25px;">
                <button type="button" class="btn" style="flex:1; border:1px solid rgba(255,255,255,0.1);" onclick="closeModal('adminPanelModal')">Annuler</button>
                <button type="submit" name="save_admin_settings" class="btn btn-primary" style="flex:1;">Enregistrer</button>
            </div>
        </form>
    </div></div>
    <?php endif; ?>

    <div id="queue-panel">
        <button class="close-queue-mobile" onclick="toggleQueue()">▼ Fermer la file</button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;">Musiques à suivre</h3>
        <div id="queue-list" style="margin-top:15px;">
            <p style="color:#666; font-size:0.9em;">Aucune musique en attente...</p>
        </div>
    </div>

    <main id="accueil">
        <div class="controls-container">
            <h2 class="section-title">Toutes les pistes</h2>
            <div class="search-row">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="Rechercher titre, artiste..." onkeyup="onSearchInput()">
                </div>
                <div class="filter-wrapper" title="Trier">
                    <div class="filter-icon-visual">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M3 4c0-0.55.45-1 1-1h10c.55 0 1 .45 1 1v1.5c0 .28-.11.53-.3.71L10 10.9v5.2c0 .28-.11.53-.29.71l-2 2c-.18.18-.43.29-.71.29s-.53-.11-.71-.29A.996.996 0 0 1 6 18.1v-7.2L3.3 6.21A.996.996 0 0 1 3 5.5V4z"/>
                            <rect x="16" y="5" width="6" height="2" rx="1" />
                            <rect x="16" y="11" width="6" height="2" rx="1" />
                            <rect x="16" y="17" width="6" height="2" rx="1" />
                        </svg>
                    </div>
                    <select id="sortSelect" class="filter-select-overlay" onchange="filterAndSortTracks()">
                        <option value="popular">Les plus écoutés</option>
                        <option value="date_desc">Ajouts récents</option>
                        <option value="date_asc">Ajouts anciens</option>
                        <option value="alpha_asc">Nom (A-Z)</option>
                        <option value="alpha_desc">Nom (Z-A)</option>
                        <option value="artist">Par Artiste</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="track-list" id="global-list"></div>
        <div id="load-more-trigger"></div>
    </main>

    <main id="playlists" style="display:none;">
        <h2 class="section-title" style="margin-bottom:25px;">Tes Mixs</h2>
        <div class="playlist-grid">
            <?php foreach($all_playlists as $p): ?>
                <div class="playlist-card">
                    <h3 style="margin-top:0; font-size:1.3em;"><?php echo htmlspecialchars($p['name']); ?></h3>
                    <p style="font-size:0.85em; color:var(--text-muted); margin-bottom:20px;">Créé par <strong><?php echo htmlspecialchars($p['username']); ?></strong></p>
                    <button class="btn btn-primary" style="width:100%; justify-content:center; margin-bottom:15px;" onclick="playPlaylist('<?php echo htmlspecialchars($p['song_ids']); ?>', '<?php echo $p['id']; ?>')">▶ Écouter le mix</button>
                    <?php if($p['creator_id'] == $user_id || $is_admin): ?>
                        <div style="display:flex; gap:10px;">
                            <button class="btn btn-outline" style="flex:1; justify-content:center; font-size:0.8em;" onclick='openEditModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Editer</button>
                            <a href="?delete_playlist=<?php echo $p['id']; ?>&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-danger" style="flex:1; justify-content:center; font-size:0.8em; border-radius:99px;" onclick="return confirm('Supprimer ?')">Suppr</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <div id="mobile-bottom-nav">
        <button class="mob-nav-item active" id="mob-nav-accueil" onclick="showSection('accueil')">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>Biblio
        </button>
        <button class="mob-nav-item" id="mob-nav-playlists" onclick="showSection('playlists')">
            <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>Mixs
        </button>
        <?php if($is_admin): ?>
            <button class="mob-nav-item" onclick="openModal('adminPanelModal')" style="color:#e67e22;">
                <svg viewBox="0 0 24 24"><path d="M19.4 13c.0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6l-2.1-1.7zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>Admin
            </button>
        <?php endif; ?>
        <button class="mob-nav-item" onclick="toggleQueue()">
            <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>File
        </button>
        <button class="mob-nav-item" onclick="openModal('uploadModal')">
            <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>Upload
        </button>
    </div>

    <div id="settingsModal" class="modal"><div class="modal-content">
        <h2 style="margin-top:0;">Filtres & Paramètres</h2>
        <p style="color:var(--text-muted); font-size:0.9em; margin-bottom: 20px;">Cochez les genres que vous souhaitez <strong style="color:var(--danger);">masquer</strong> :</p>
        <div class="settings-grid">
            <?php foreach($genresList as $g): ?>
                <label><input type="checkbox" class="genre-filter-cb" data-genre="<?php echo htmlspecialchars($g); ?>" onchange="toggleGenreSetting('<?php echo htmlspecialchars($g); ?>', this.checked)"> <?php echo htmlspecialchars($g); ?></label>
            <?php endforeach; ?>
        </div>
        <div style="display:flex; gap:15px; margin-top:30px;">
            <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" onclick="closeModal('settingsModal')">Fermer</button>
        </div>
    </div></div>

    <div id="uploadModal" class="modal"><div class="modal-content">
        <h2 style="margin-top:0;">Upload</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="text" name="title" placeholder="Titre (Optionnel - sinon détecté auto)">
            <input type="text" name="artist" placeholder="Artiste (Optionnel - sinon détecté auto)">
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;">Sélectionnez le genre</label>
            <select name="genre">
                <?php foreach($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;">Fichier Audio (MP3/WAV/FLAC)</label>
            <input type="file" name="music" accept="audio/*" required>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;">Cover (Laissez vide pour auto-detect)</label>
            <input type="file" name="cover" accept="image/*">
            <div style="display:flex; gap:15px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('uploadModal')">Annuler</button>
                <button type="submit" name="upload" class="btn btn-primary" style="flex:1; justify-content:center;">Publier</button>
            </div>
        </form>
    </div></div>

    <div id="editTrackModal" class="modal"><div class="modal-content">
        <h2 style="margin-top:0;">Modifier Piste</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="track_id" id="edit-track-id">
            <input type="text" name="new_title" id="edit-track-title" placeholder="Titre" required>
            <input type="text" name="new_artist" id="edit-track-artist" placeholder="Artiste">
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;">Modifier le genre</label>
            <select name="new_genre" id="edit-track-genre">
                <?php foreach($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;">Changer la cover</label>
            <input type="file" name="new_cover" accept="image/*">
            <div style="display:flex; gap:15px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('editTrackModal')">Annuler</button>
                <button type="submit" name="edit_track" class="btn btn-primary" style="flex:1; justify-content:center;">Enregistrer</button>
            </div>
        </form>
    </div></div>

    <div id="playlistModal" class="modal"><div class="modal-content">
        <h2 id="modal-playlist-title" style="margin-top:0;">Playlist</h2>
        <form method="post" id="playlist-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="playlist_id" id="form-playlist-id">
            <input type="text" name="playlist_name" id="form-playlist-name" placeholder="Nom du mix" required>
            <input type="text" id="playlist-search" placeholder="🔍 Rechercher une musique..." onkeyup="filterPlaylistTracks()" style="margin-bottom:10px;">
            <div style="display:flex; justify-content:space-between; font-size:0.85em; color:var(--text-muted); margin-bottom:10px;">
                <span>Sélectionnez les titres :</span>
                <span id="selected-count">0 sélectionné(s)</span>
            </div>
            <div class="song-select-container">
                <?php foreach($all_tracks as $t): ?>
                    <div class="song-select-item" onclick="toggleSelection(this)" data-title="<?php echo strtolower(htmlspecialchars($t['title'])); ?>">
                        <input type="checkbox" name="selected_songs[]" value="<?php echo $t['id']; ?>" class="song-cb" data-id="<?php echo $t['id']; ?>">
                        <div class="check-indicator"></div>
                        <img src="covers/<?php echo htmlspecialchars($t['cover']); ?>" loading="lazy" style="width:40px; height:40px; border-radius:8px; margin-right:12px; object-fit:cover;" onerror="this.src='covers/default.png'">
                        <div style="flex:1; overflow:hidden;">
                            <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($t['title']); ?></div>
                            <div style="font-size:0.85em; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($t['artist']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; gap:15px; margin-top:20px;">
                 <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('playlistModal')">Annuler</button>
                <button type="submit" name="save_playlist" class="btn btn-primary" style="flex:1; justify-content:center;">Enregistrer</button>
            </div>
        </form>
    </div></div>

    <div id="player-bar">
        <div class="player-info" onclick="openSmartPlayer()" style="cursor:pointer">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="player-cover" loading="lazy">
            <div style="overflow: hidden; flex: 1;">
                <div id="play-title" style="font-weight: 700; font-size:0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Prêt à écouter</div>
                <div id="play-status" style="font-size: 0.75em; color: var(--accent); margin-top:2px;">Arrêté</div>
            </div>
        </div>
        <div class="progress-container">
            <div class="progress-bg" id="progress-area">
                <div class="progress-fill" id="progress-bar"></div>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:0.7em; color:var(--text-muted); margin-top:6px; font-family:monospace;">
                <span id="curr-time">0:00</span><span id="total-time">0:00</span>
            </div>
        </div>
        <div class="controls">
            <button class="control-btn" id="shuffleBtn" onclick="toggleShuffle()" title="Aléatoire">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="control-btn" onclick="prevTrack()" title="Précédent">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="masterPlay" onclick="togglePlay()" title="Lecture">
                <svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="control-btn" onclick="nextTrack()" title="Suivant">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="control-btn" id="loopBtn" onclick="toggleLoop()" style="position:relative;" title="Boucle">
                <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                <span id="loop-ind" style="display:none;" class="loop-status">1</span>
            </button>
            <div class="volume-container">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="#a196b4"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                <input type="range" id="desktop-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
            </div>
        </div>
    </div>

    <div id="full-player">
        <div class="fp-header">
            <button class="fp-btn" onclick="closeFullPlayer()">
                <svg viewBox="0 0 24 24" style="width:30px; height:30px; fill:white;"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
            </button>
            <span style="font-size:0.8em; letter-spacing:1px; color:var(--text-muted); font-weight:600;">LECTURE EN COURS</span>
            <button class="fp-btn" onclick="toggleQueue(); closeFullPlayer();">
                <svg viewBox="0 0 24 24" style="width:24px; height:24px; fill:white;"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
            </button>
        </div>
        <div class="fp-art-container">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" loading="lazy">
        </div>
        <div class="fp-info-area">
            <div id="fp-title">Titre</div>
            <div id="fp-artist" style="font-size:1.1em; color:var(--accent); font-weight:500;">Artiste</div>
        </div>
        <div class="fp-progress-wrapper">
            <div class="progress-bg" id="fp-progress-area" style="height:6px; background:rgba(255,255,255,0.2);">
                <div class="progress-fill" id="fp-progress-bar" style="background:white;"></div>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.85em; color:#ccc; font-family:monospace;">
                <span id="fp-curr-time">0:00</span>
                <span id="fp-total-time">0:00</span>
            </div>
            <div class="volume-container fp-volume-container">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="white"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                <input type="range" id="mobile-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
            </div>
        </div>
        <div class="fp-controls">
            <button class="control-btn" id="fp-shuffleBtn" onclick="toggleShuffle()" style="transform:scale(1.2);">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="control-btn" onclick="prevTrack()" style="transform:scale(1.5);">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="fp-masterPlay" onclick="togglePlay()" style="background:white; color:black; border-radius:50%; width:75px; height:75px; border:none; display:flex; align-items:center; justify-content:center; box-shadow:0 0 40px rgba(255,255,255,0.2);">
                <svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="control-btn" onclick="nextTrack()" style="transform:scale(1.5);">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="control-btn" id="fp-loopBtn" onclick="toggleLoop()" style="transform:scale(1.2); position:relative;">
                <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                <span id="fp-loop-ind" style="display:none; position:absolute; top:-5px; right:-5px; background:var(--primary); width:10px; height:10px; border-radius:50%;"></span>
            </button>
        </div>
    </div>

    <audio id="mainAudio"></audio>

    <script>
        const ALL_MUSIC_DATA = <?php echo json_encode($all_tracks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const CURRENT_USER_ID = <?php echo json_encode($user_id); ?>;
        const IS_ADMIN = <?php echo json_encode($is_admin); ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

        const audio = document.getElementById('mainAudio');
        const progressBar = document.getElementById('progress-bar');
        const progressArea = document.getElementById('progress-area');
        const masterPlay = document.getElementById('masterPlay');
        const playTitle = document.getElementById('play-title');
        const playCover = document.getElementById('player-cover');
        const playStatus = document.getElementById('play-status');
        const queueList = document.getElementById('queue-list');
        const queuePanel = document.getElementById('queue-panel');
        
        let CURRENT_VIEW_DATA = []; let renderedCount = 0; const RENDER_CHUNK = 30;
        let originalQueue = []; let queue = []; let currentIndex = 0; let loopMode = 0; let isShuffle = false;
        let currentPlaylistId = null; let currentSection = 'accueil';
        let hiddenGenres = JSON.parse(localStorage.getItem('hiddenGenres') || '[]');

        const playIcon = '<svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>';
        const pauseIcon = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';

        const desktopVol = document.getElementById('desktop-vol');
        const mobileVol = document.getElementById('mobile-vol');

        // --- ACCORDÉON LOGIQUE SCRIPT ---
        function toggleAccordion(header) {
            const item = header.parentElement;
            const content = item.querySelector('.adm-accordion-content');
            const isOpen = item.classList.contains('open');
            
            document.querySelectorAll('.adm-accordion-item').forEach(el => {
                el.classList.remove('open');
                el.querySelector('.adm-accordion-content').style.display = 'none';
            });

            if (!isOpen) {
                item.classList.add('open');
                content.style.display = 'block';
            }
        }

        function updateVolume(val) {
            audio.volume = val; desktopVol.value = val; mobileVol.value = val;
            localStorage.setItem('purpleMusicVolume', val);
            const percentage = val * 100;
            const bgStyle = `linear-gradient(90deg, var(--accent) ${percentage}%, rgba(255,255,255,0.2) ${percentage}%)`;
            desktopVol.style.background = bgStyle; mobileVol.style.background = bgStyle;
        }

        desktopVol.addEventListener('input', (e) => updateVolume(e.target.value));
        mobileVol.addEventListener('input', (e) => updateVolume(e.target.value));

        function escapeHTML(str) {
            if (str === null || str === undefined) return '';
            return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        let searchTimeout;
        function onSearchInput() {
            clearTimeout(searchTimeout); searchTimeout = setTimeout(filterAndSortTracks, 250);
        }

        function updateUrl() {
            const params = new URLSearchParams();
            if (currentSection !== 'accueil') params.set('page', currentSection);
            if (queue[currentIndex] && queue[currentIndex].id) params.set('v', queue[currentIndex].id);
            if (currentPlaylistId) params.set('list', currentPlaylistId);
            const newUrl = window.location.pathname + '?' + params.toString();
            window.history.pushState({ path: newUrl }, '', newUrl);
        }

        function toggleGenreSetting(genre, isChecked) {
            if (isChecked) {
                if (!hiddenGenres.includes(genre)) hiddenGenres.push(genre);
            } else {
                hiddenGenres = hiddenGenres.filter(g => g !== genre);
            }
            localStorage.setItem('hiddenGenres', JSON.stringify(hiddenGenres));
            filterAndSortTracks();
        }

        function renderTracksChunk() {
            const listContainer = document.getElementById('global-list');
            const chunk = CURRENT_VIEW_DATA.slice(renderedCount, renderedCount + RENDER_CHUNK);
            if (renderedCount === 0) listContainer.innerHTML = '';
            if (chunk.length === 0 && renderedCount === 0) {
                listContainer.innerHTML = '<div style="padding:40px; text-align:center; color:#666;">Aucune piste trouvée.</div>'; return;
            }

            const fragment = document.createDocumentFragment();
            chunk.forEach((t, index) => {
                const absoluteIndex = renderedCount + index + 1;
                const safeTitle = escapeHTML(t.title); const safeArtist = escapeHTML(t.artist);
                const safeGenre = escapeHTML(t.genre || 'Autre'); const safeCover = escapeHTML(t.cover);
                const jsSafeTitle = safeTitle.replace(/'/g, "\\'"); const jsSafeArtist = safeArtist.replace(/'/g, "\\'");
                const jsSafeGenre = safeGenre.replace(/'/g, "\\'");

                let editButtons = '';
                if(t.uploader_id == CURRENT_USER_ID || IS_ADMIN) {
                    editButtons = `
                        <button class="btn btn-outline" style="font-size:0.7em; padding:6px 10px; border-radius:8px;" onclick="openEditTrackModal(${t.id}, '${jsSafeTitle}', '${jsSafeArtist}', '${jsSafeGenre}')">✎</button>
                        <a href="?delete_track=${t.id}&csrf_token=${CSRF_TOKEN}" class="btn btn-danger" style="border-radius:8px;" onclick="return confirm('Supprimer ?')">✕</a>
                    `;
                }

                const div = document.createElement('div'); div.className = 'track-item';
                div.innerHTML = `
                    <div class="track-index">${absoluteIndex}</div>
                    <img src="covers/${safeCover}" loading="lazy" class="mini-cover" onerror="this.src='covers/default.png'">
                    <div style="cursor:pointer; overflow:hidden;" onclick="playTrackById(${t.id})">
                        <div style="font-weight:700; font-size:1.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px;">${safeTitle}</div>
                        <div style="font-size:0.85em; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            ${safeArtist} <span style="opacity:0.6;font-size:0.9em;">• ${safeGenre} • ▶ ${t.play_count || 0}</span>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px;">${editButtons}</div>
                `;
                fragment.appendChild(div);
            });
            listContainer.appendChild(fragment); renderedCount += chunk.length;
        }

        function filterAndSortTracks() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const sortValue = document.getElementById('sortSelect').value;
            let filtered = ALL_MUSIC_DATA.filter(t => {
                const trackGenre = t.genre || 'Autre';
                if (hiddenGenres.includes(trackGenre)) return false;
                return t.title.toLowerCase().includes(searchTerm) || t.artist.toLowerCase().includes(searchTerm);
            });

            filtered.sort((a, b) => {
                if (sortValue === 'popular') {
                    if (b.play_count !== a.play_count) return (b.play_count || 0) - (a.play_count || 0);
                    return b.id - a.id;
                }
                else if (sortValue === 'date_desc') return b.id - a.id;
                else if (sortValue === 'date_asc') return a.id - b.id;
                else if (sortValue === 'alpha_asc') return a.title.localeCompare(b.title);
                else if (sortValue === 'alpha_desc') return b.title.localeCompare(a.title);
                else if (sortValue === 'artist') return a.artist.localeCompare(b.artist);
                return 0;
            });
            CURRENT_VIEW_DATA = filtered; renderedCount = 0; renderTracksChunk();
        }

        const _observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && renderedCount < CURRENT_VIEW_DATA.length) { renderTracksChunk(); }
        }, { rootMargin: "200px" });

        document.addEventListener('DOMContentLoaded', async () => {
            const savedVol = localStorage.getItem('purpleMusicVolume');
            if(savedVol !== null) updateVolume(savedVol); else updateVolume(1);
            document.querySelectorAll('.genre-filter-cb').forEach(cb => {
                if (hiddenGenres.includes(cb.dataset.genre)) cb.checked = true;
            });
            _observer.observe(document.getElementById('load-more-trigger')); filterAndSortTracks();

            const urlParams = new URLSearchParams(window.location.search);
            const pageParam = urlParams.get('page'); const videoParam = urlParams.get('v'); const listParam = urlParams.get('list');
            if (pageParam) showSection(pageParam, false);
            if (listParam) currentPlaylistId = listParam;
            if (videoParam) playTrackById(videoParam, false);
        });
        
        window.onpopstate = function(event) { window.location.reload(); };
        function formatTime(s) {
            if(isNaN(s) || !isFinite(s)) return "0:00";
            let min = Math.floor(s / 60); let sec = Math.floor(s % 60); return min + ":" + (sec < 10 ? "0" : "") + sec;
        }
        function toggleQueue() { queuePanel.classList.toggle('open'); }

        function openSmartPlayer() {
            if (window.innerWidth <= 768) {
                document.getElementById('full-player').classList.add('active'); document.body.style.overflow = 'hidden'; 
            } else { toggleQueue(); }
        }
        function closeFullPlayer() {
            document.getElementById('full-player').classList.remove('active'); document.body.style.overflow = 'auto';
        }

        function updateQueueUI() {
            queueList.innerHTML = '';
            if(queue.length === 0) { queueList.innerHTML = '<p style="color:#666;">File vide...</p>'; return; }
            queue.forEach((track, index) => {
                const safeTitle = escapeHTML(track.title); const safeArtist = escapeHTML(track.artist); const safeCover = escapeHTML(track.cover);
                const div = document.createElement('div'); div.className = `queue-item ${index === currentIndex ? 'active' : ''}`;
                div.innerHTML = `
                    <img src="covers/${safeCover}" loading="lazy" style="width:36px; height:36px; border-radius:8px; object-fit:cover;">
                    <div style="flex:1; overflow:hidden;">
                        <div style="font-size:0.9em; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${safeTitle}</div>
                        <div style="font-size:0.75em; color:#888;">${safeArtist}</div>
                    </div>
                    ${index === currentIndex ? '<span style="color:var(--accent); font-size:1.5em;">•</span>' : ''}
                `;
                div.onclick = () => { currentIndex = index; loadTrack(true); }; queueList.appendChild(div);
            });
        }

        function playTrackById(id, autoPlay = true) {
            if (!currentPlaylistId) { 
                originalQueue = [...CURRENT_VIEW_DATA]; queue = isShuffle ? shuffleArray([...originalQueue]) : [...originalQueue];
                currentIndex = queue.findIndex(t => t.id == id);
            } else {
                let inPlaylistIndex = queue.findIndex(t => t.id == id);
                if (inPlaylistIndex === -1) { currentPlaylistId = null; return playTrackById(id, autoPlay); }
                currentIndex = inPlaylistIndex;
            }
            if (currentIndex === -1) currentIndex = 0;
            loadTrack(autoPlay);
            if(autoPlay && window.innerWidth > 768 && !queuePanel.classList.contains('open')) toggleQueue();
        }

        async function playPlaylist(ids, pId = null) {
            const res = await fetch('?get_playlist_tracks=' + ids); let data = await res.json();
            if (hiddenGenres.length > 0) { data = data.filter(t => !hiddenGenres.includes(t.genre || 'Autre')); }
            if(data.length > 0) {
                currentPlaylistId = pId; originalQueue = [...data]; queue = isShuffle ? shuffleArray([...data]) : [...data];
                currentIndex = 0; loadTrack(true);
                if(window.innerWidth > 768 && !queuePanel.classList.contains('open')) toggleQueue();
            } else { alert("Aucune musique disponible."); }
        }

        function loadTrack(autoPlay = true) {
            if (!queue[currentIndex]) return;
            const track = queue[currentIndex]; audio.src = 'music/' + track.filename;
            fetch('?increment_play=' + track.id).catch(e => console.error(e));
            track.play_count = (parseInt(track.play_count) || 0) + 1;
            const globalTrack = ALL_MUSIC_DATA.find(t => t.id == track.id); if (globalTrack) globalTrack.play_count = track.play_count;
            
            playTitle.innerText = track.title; playCover.src = 'covers/' + (track.cover || 'default.png'); playStatus.innerText = track.artist || 'Artiste inconnu';
            
            const fpTitle = document.getElementById('fp-title'); const fpArtist = document.getElementById('fp-artist'); const fpCover = document.getElementById('fp-cover');
            const safeFpTitle = escapeHTML(track.title); fpTitle.innerHTML = `<span id="fp-title-text">${safeFpTitle}</span>`;
            fpArtist.innerText = track.artist || 'Artiste inconnu'; fpCover.src = 'covers/' + (track.cover || 'default.png');
            
            const titleSpan = document.getElementById('fp-title-text'); titleSpan.classList.remove('scrolling-active');
            if (titleSpan.scrollWidth > fpTitle.clientWidth) { titleSpan.classList.add('scrolling-active'); }

            document.getElementById('curr-time').innerText = "0:00"; document.getElementById('total-time').innerText = "0:00"; progressBar.style.width = "0%";
            document.getElementById('fp-progress-bar').style.width = "0%"; document.getElementById('fp-curr-time').innerText = "0:00"; document.getElementById('fp-total-time').innerText = "0:00";
            
            if ('mediaSession' in navigator) {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: track.title, artist: track.artist || 'Purple Music',
                    artwork: [{ src: 'covers/' + (track.cover || 'default.png'), sizes: '96x96', type: 'image/png' }]
                });
            }
            updateUrl();
            if (autoPlay) {
                audio.play().catch(e => console.error(e)); masterPlay.innerHTML = pauseIcon; 
                document.getElementById('fp-masterPlay').innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
            } else {
                masterPlay.innerHTML = playIcon; document.getElementById('fp-masterPlay').innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
            }
            updateQueueUI();
        }

        audio.onloadedmetadata = () => {
            const t = formatTime(audio.duration); document.getElementById('total-time').innerText = t; document.getElementById('fp-total-time').innerText = t;
        };
        function nextTrack() {
            if (loopMode === 2) { audio.currentTime = 0; audio.play(); return; }
            if (currentIndex < queue.length - 1) { currentIndex++; loadTrack(true); } 
            else if (loopMode === 1) { currentIndex = 0; loadTrack(true); } 
            else {
                audio.pause(); audio.currentTime = 0; masterPlay.innerHTML = playIcon;
                document.getElementById('fp-masterPlay').innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
            }
        }
        function prevTrack() { if (currentIndex > 0) { currentIndex--; loadTrack(true); } }

        function togglePlay() {
            if(!audio.src) return;
            const fpPlayIcon = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
            const fpPauseIcon = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
            if(audio.paused) { audio.play(); masterPlay.innerHTML = pauseIcon; document.getElementById('fp-masterPlay').innerHTML = fpPauseIcon; } 
            else { audio.pause(); masterPlay.innerHTML = playIcon; document.getElementById('fp-masterPlay').innerHTML = fpPlayIcon; }
        }

        function toggleShuffle() {
            isShuffle = !isShuffle; document.getElementById('shuffleBtn').classList.toggle('active', isShuffle); document.getElementById('fp-shuffleBtn').classList.toggle('active', isShuffle);
            if (queue.length > 0) {
                const currentTrack = queue[currentIndex]; queue = isShuffle ? shuffleArray([...originalQueue]) : [...originalQueue];
                currentIndex = queue.findIndex(t => t.filename === currentTrack.filename); if (currentIndex === -1) currentIndex = 0;
                updateQueueUI();
            }
        }

        function toggleLoop() {
            loopMode = (loopMode + 1) % 3; const isActive = loopMode > 0;
            document.getElementById('loopBtn').classList.toggle('active', isActive); document.getElementById('fp-loopBtn').classList.toggle('active', isActive);
            document.getElementById('loop-ind').style.display = (loopMode === 2) ? 'flex' : 'none';
            document.getElementById('fp-loop-ind').style.display = isActive ? 'block' : 'none';
            document.getElementById('fp-loop-ind').style.background = (loopMode === 2) ? 'var(--primary)' : 'white';
        }

        function shuffleArray(arr) {
            for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [arr[i], arr[j]] = [arr[j], arr[i]]; } return arr;
        }

        audio.ontimeupdate = () => {
            const pct = (audio.currentTime / audio.duration) * 100; progressBar.style.width = (pct || 0) + "%";
            document.getElementById('curr-time').innerText = formatTime(audio.currentTime);
            if(audio.duration) document.getElementById('total-time').innerText = formatTime(audio.duration);
            document.getElementById('fp-progress-bar').style.width = (pct || 0) + "%";
            document.getElementById('fp-curr-time').innerText = formatTime(audio.currentTime);
            if(audio.duration) document.getElementById('fp-total-time').innerText = formatTime(audio.duration);
        };
        audio.onended = nextTrack;
        
        progressArea.onclick = (e) => {
            const rect = progressArea.getBoundingClientRect(); audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
        };
        document.getElementById('fp-progress-area').onclick = (e) => {
            const rect = document.getElementById('fp-progress-area').getBoundingClientRect(); audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
        };

        function showSection(id, doUpdateUrl = true) {
            document.getElementById('accueil').style.display = (id === 'accueil') ? 'block' : 'none';
            document.getElementById('playlists').style.display = (id === 'playlists') ? 'block' : 'none';
            document.querySelectorAll('nav span').forEach(s => s.classList.remove('active'));
            if(document.getElementById('nav-' + id)) document.getElementById('nav-' + id).classList.add('active');
            document.querySelectorAll('.mob-nav-item').forEach(s => s.classList.remove('active'));
            if(document.getElementById('mob-nav-' + id)) document.getElementById('mob-nav-' + id).classList.add('active');
            window.scrollTo(0,0); currentSection = id; if (doUpdateUrl) updateUrl();
        }

        function openModal(id) { document.getElementById(id).style.display = 'block'; }
        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditTrackModal(id, title, artist, genre) {
            document.getElementById('edit-track-id').value = id; document.getElementById('edit-track-title').value = title; document.getElementById('edit-track-artist').value = artist;
            if (genre) document.getElementById('edit-track-genre').value = genre;
            openModal('editTrackModal');
        }

        function filterPlaylistTracks() {
            const term = document.getElementById('playlist-search').value.toLowerCase();
            document.querySelectorAll('.song-select-item').forEach(item => { item.style.display = item.dataset.title.includes(term) ? 'flex' : 'none'; });
        }

        function toggleSelection(div) {
            const cb = div.querySelector('input'); cb.checked = !cb.checked;
            cb.checked ? div.classList.add('selected') : div.classList.remove('selected'); updateSelectedCount();
        }
        function updateSelectedCount() {
            document.getElementById('selected-count').innerText = document.querySelectorAll('.song-cb:checked').length + " sélectionné(s)";
        }

        function openCreateModal() {
            document.getElementById('modal-playlist-title').innerText = "Nouvelle Playlist"; document.getElementById('form-playlist-id').value = ""; document.getElementById('form-playlist-name').value = ""; document.getElementById('playlist-search').value = "";
            document.querySelectorAll('.song-select-item').forEach(div => { div.classList.remove('selected'); div.style.display = 'flex'; });
            document.querySelectorAll('.song-cb').forEach(cb => cb.checked = false); updateSelectedCount(); openModal('playlistModal');
        }

        function openEditModal(p) {
            document.getElementById('modal-playlist-title').innerText = "Modifier Playlist"; document.getElementById('form-playlist-id').value = p.id; document.getElementById('form-playlist-name').value = p.name; document.getElementById('playlist-search').value = "";
            const ids = String(p.song_ids).split(',');
            document.querySelectorAll('.song-select-item').forEach(div => {
                const cb = div.querySelector('.song-cb'); cb.checked = ids.includes(cb.dataset.id);
                cb.checked ? div.classList.add('selected') : div.classList.remove('selected'); div.style.display = 'flex';
            });
            updateSelectedCount(); openModal('playlistModal');
        }
    </script>
<?php endif; ?>
</body>
</html>
