<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json');

// --- SÉCURITÉ : Entêtes HTTP de sécurité ---
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

require_once 'db.php';
require_once 'functions.php';

$musicDir = __DIR__ . '/music';
$coverDir = __DIR__ . '/covers';
if(!is_dir($musicDir)) mkdir($musicDir, 0755, true);
if(!is_dir($coverDir)) mkdir($coverDir, 0755, true);

$action = $_GET['action'] ?? '';
$baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/";

switch($action) {
    case 'login':
        if (!check_login_rate_limit($db)) {
            http_response_code(429);
            echo json_encode(["status" => "error", "message" => "Trop de tentatives. Réessayez plus tard."]);
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

        if (mb_strlen($u) > 50) { echo json_encode(["status" => "error", "message" => "Nom trop long"]); exit; }
        if (mb_strlen($p) < 6)  { echo json_encode(["status" => "error", "message" => "Mot de passe trop court"]); exit; }

        $u = sanitize_text($u, 50);
        try { 
            $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)")->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
            echo json_encode(["status" => "success"]); 
        } catch(Exception $e) { 
            echo json_encode(["status" => "error", "message" => "Nom déjà pris"]);
        }
        break;

    case 'list':
        $stmt = $db->query("SELECT tracks.id, tracks.title, tracks.artist, tracks.cover, tracks.genre, tracks.play_count, tracks.duration, tracks.uploader_id FROM tracks ORDER BY play_count DESC, id DESC");
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($tracks as &$t) { 
            $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
            $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        }
        echo json_encode($tracks);
        break;
        
    case 'increment_play':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0) { echo json_encode(["status" => "error", "message" => "ID invalide"]); exit; }

        $stmt = $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?");
        $stmt->execute([$track_id]);
        echo json_encode(["status" => "success"]);
        break;

    case 'stream':
        $stmt = $db->prepare("SELECT filename FROM tracks WHERE id = ?"); 
        $stmt->execute([$_GET['q'] ?? 0]); 
        $t = $stmt->fetch();
        
        if($t && !empty($t['filename'])) { 
            $path = $musicDir . '/' . basename($t['filename']);
            if (file_exists($path)) {
                $size = filesize($path);
                $fp = @fopen($path, 'rb');
                if (!$fp) { header("HTTP/1.1 500 Internal Server Error"); exit; }

                $start = 0; $end = $size - 1;
                if (isset($_SERVER['HTTP_RANGE'])) {
                    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
                    if (strpos($range, ',') !== false) { header('HTTP/1.1 416 Requested Range Not Satisfiable'); exit; }
                    $range = explode('-', $range);
                    $start = $range[0];
                    $end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
                    header('HTTP/1.1 206 Partial Content');
                    header("Content-Range: bytes $start-$end/$size");
                    fseek($fp, $start);
                }

                header('Content-Type: audio/mpeg');
                header('Content-Length: ' . ($end - $start + 1));
                @set_time_limit(0);
                while(!feof($fp) && ($p = ftell($fp)) <= $end) {
                    echo fread($fp, 1024 * 16); flush();
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
        
        $mime = 'image/jpeg';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'webp') $mime = 'image/webp';
        elseif ($ext === 'png') $mime = 'image/png';

        header("Content-Type: " . $mime); readfile($path); exit;

    case 'upload':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        if(isset($_FILES['music'])) {
            $file = $_FILES['music'];
            if ($file['size'] > MAX_AUDIO_SIZE) { echo json_encode(["status" => "error", "message" => "Trop gros"]); exit; }
            
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!is_valid_audio($file['tmp_name'], $ext)) { echo json_encode(["status" => "error", "message" => "Format invalide"]); exit; }

            $meta = extractMp3Data($file['tmp_name']);
            $fn = bin2hex(random_bytes(8)) . '.' . $ext;
            
            $ti = sanitize_text($_POST['title'] ?? $meta['title'] ?? pathinfo($file['name'], PATHINFO_FILENAME));
            $ar = sanitize_text($_POST['artist'] ?? $meta['artist'] ?? "Inconnu");
            $ge = sanitize_text($_POST['genre'] ?? 'Autre', 50);
            
            $cn = "default.png";
            if(!empty($_FILES['cover']['name'])) {
                $cn = bin2hex(random_bytes(8)) . ".webp";
                optimizeImage($_FILES['cover']['tmp_name'], $coverDir.'/'.$cn);
            } elseif(!empty($meta['cover']['data'])) {
                $cn = bin2hex(random_bytes(8)) . "_meta.webp"; 
                $tmp = sys_get_temp_dir() . '/' . uniqid();
                file_put_contents($tmp, $meta['cover']['data']);
                optimizeImage($tmp, $coverDir.'/'.$cn, $meta['cover']['mime']);
                @unlink($tmp);
            }

            if(move_uploaded_file($file['tmp_name'], $musicDir.'/'.$fn)) {
                $db->prepare("INSERT INTO tracks (filename, title, artist, cover, genre, uploader_id, duration) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$fn, $ti, $ar, $cn, $ge, $auth['id'], calculateAudioDuration($musicDir.'/'.$fn)]);
                echo json_encode(["status" => "success"]);
            }
        }
        break;

    case 'playlists':
        echo json_encode($db->query("SELECT p.*, u.username as creator FROM playlists p JOIN users u ON p.creator_id = u.id")->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'playlist_mod':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        // ... (Logique simplifiée pour l'exemple)
        break;
}
?>
