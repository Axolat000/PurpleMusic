<?php
// --- MODE INSTALLATION ---

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
