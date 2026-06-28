<?php
// --- CONFIGURATION & INITIALISATION BDD ---

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
} else {
    define('DB_NAME', 'music_app.db'); // Fallback pour l'API avant install
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/' . DB_NAME);
    $db.setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tables de base
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password TEXT, is_admin INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS tracks (id INTEGER PRIMARY KEY, filename TEXT, title TEXT, artist TEXT DEFAULT 'Artiste inconnu', cover TEXT DEFAULT 'default.png', genre TEXT DEFAULT 'Autre', uploader_id INTEGER, upload_date DATETIME DEFAULT CURRENT_TIMESTAMP, play_count INTEGER DEFAULT 0, duration INTEGER DEFAULT 0)");
    $db->exec("CREATE TABLE IF NOT EXISTS playlists (id INTEGER PRIMARY KEY, name TEXT, creator_id INTEGER, song_ids TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS genres (id INTEGER PRIMARY KEY, name TEXT UNIQUE)");
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT, attempt_time INTEGER)");

    // Indexation pour les performances
    $db->exec("CREATE INDEX IF NOT EXISTS idx_play_count ON tracks(play_count)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_uploader ON tracks(uploader_id)");

    // Migrations automatiques (vérification des colonnes)
    $cols = $db->query("PRAGMA table_info(tracks)")->fetchAll(PDO::FETCH_ASSOC);
    $hasGenre = false; $hasPlayCount = false; $hasDuration = false;
    foreach($cols as $c) {
        if($c['name'] == 'genre') $hasGenre = true;
        if($c['name'] == 'play_count') $hasPlayCount = true;
        if($c['name'] == 'duration') $hasDuration = true;
    }
    if(!$hasGenre) $db->exec("ALTER TABLE tracks ADD COLUMN genre TEXT DEFAULT 'Autre'");
    if(!$hasPlayCount) $db->exec("ALTER TABLE tracks ADD COLUMN play_count INTEGER DEFAULT 0");
    if(!$hasDuration) $db->exec("ALTER TABLE tracks ADD COLUMN duration INTEGER DEFAULT 0");

} catch (Exception $e) {
    if (php_sapi_name() === 'cli' || strpos($_SERVER['REQUEST_URI'], 'api.php') !== false) {
        die(json_encode(["status" => "error", "message" => "Erreur BDD"]));
    }
    die("Erreur BDD : " . $e->getMessage());
}
