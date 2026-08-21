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


require_once __DIR__ . '/api/helpers.php';

// --- ROUTAGE PAR DOMAINE ---
// Le gros switch($action) d'origine a été découpé par domaine (api/*.php), chacun contenant son propre
// switch($action) complet pour son sous-ensemble d'actions (on ne peut pas include() des `case` isolés
// au milieu d'un switch en PHP -- testé, ça ne compile pas -- donc chaque fichier reste un switch
// autonome). $db/$musicDir/$coverDir/$baseUrl/$action restent dans la portée globale de ce fichier,
// visibles depuis les fichiers inclus (include partage le scope de l'appelant). Une action qui ne
// correspond à aucune entrée ne produit aucune sortie, comme le faisait déjà le switch d'origine sans
// `default:`.
$actionDomains = [
    'auth' => ['login', 'register', 'change_password', 'admin_reset_password'],
    'tracks' => ['list', 'increment_play', 'stream', 'cover', 'upload', 'edit_track', 'delete_track'],
    'playlists' => ['playlists', 'playlist_create', 'playlist_mod', 'delete_playlist', 'playlist_save', 'get_playlist_tracks'],
    'likes_recommendations' => ['report_listen', 'toggle_like', 'my_likes', 'recommendations'],
    'admin' => ['save_admin_settings', 'delete_genre', 'toggle_admin', 'delete_user', 'trigger_update', 'check_update'],
    'lyrics' => ['get_lyrics'],
];

foreach ($actionDomains as $domainFile => $actions) {
    if (in_array($action, $actions, true)) {
        include __DIR__ . '/api/' . $domainFile . '.php';
        break;
    }
}
