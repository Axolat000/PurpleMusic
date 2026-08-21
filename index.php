<?php
session_start();
require_once 'i18n.php';

// PURPLEMUSIC_DATA_DIR permet de stocker config.php/la base ailleurs que le code
// (utilisé par l'image Docker pour survivre aux mises à jour) — absent = comportement
// inchangé (tout reste dans le dossier de l'app, comme avant).
$dataDir = getenv('PURPLEMUSIC_DATA_DIR') ?: __DIR__;
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
$configFile = $dataDir . '/config.php';
$isInstalled = file_exists($configFile);

// Cache-busting pour app.js/style.css/alpine : sans ça, un CDN (Cloudflare, etc.) devant l'app
// continue de servir les anciens fichiers pendant toute sa durée de cache après une mise à jour
// (vécu en prod : app.js resté servi 4h après un déploiement, page cassée entre-temps). On réutilise
// le SHA baké au build (APP_COMMIT_SHA) qui change à chaque nouvelle image ; en dev local (absent),
// on retombe sur filemtime() de ce fichier.
$assetVersion = getenv('APP_COMMIT_SHA') ?: (string) filemtime(__FILE__);

// --- 1. MODE INSTALLATION ---
if (!$isInstalled) {
    include 'install.php';
    exit;
}

// --- 2. CONFIGURATION ET BASE DE DONNÉES ---
require_once $configFile;
require_once 'functions.php';

try {
    $db = new PDO('sqlite:' . DB_NAME);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- MIGRATIONS AUTOMATIQUES (PAROLES / lrclib.net) ---
    // (miroir de la migration dans api.php, les deux scripts partagent music_app.db)
    $lyricsCols = $db->query("PRAGMA table_info(tracks)")->fetchAll(PDO::FETCH_ASSOC);
    $hasLyricsSynced = false;
    $hasLyricsPlain = false;
    $hasLyricsCheckedAt = false;
    foreach ($lyricsCols as $c) {
        if ($c['name'] === 'lyrics_synced') $hasLyricsSynced = true;
        if ($c['name'] === 'lyrics_plain') $hasLyricsPlain = true;
        if ($c['name'] === 'lyrics_checked_at') $hasLyricsCheckedAt = true;
    }
    if (!$hasLyricsSynced) $db->exec("ALTER TABLE tracks ADD COLUMN lyrics_synced TEXT");
    if (!$hasLyricsPlain) $db->exec("ALTER TABLE tracks ADD COLUMN lyrics_plain TEXT");
    if (!$hasLyricsCheckedAt) $db->exec("ALTER TABLE tracks ADD COLUMN lyrics_checked_at INTEGER");

    // --- MIGRATION AUTOMATIQUE (COVER PLAYLIST) ---
    // (miroir de la migration dans api.php, les deux scripts partagent music_app.db)
    $playlistCols = $db->query("PRAGMA table_info(playlists)")->fetchAll(PDO::FETCH_ASSOC);
    $hasPlaylistCover = false;
    foreach ($playlistCols as $c) {
        if ($c['name'] === 'cover') $hasPlaylistCover = true;
    }
    if (!$hasPlaylistCover) $db->exec("ALTER TABLE playlists ADD COLUMN cover TEXT");
    $hasPlaylistPrivate = false;
    foreach ($playlistCols as $c) {
        if ($c['name'] === 'is_private') $hasPlaylistPrivate = true;
    }
    if (!$hasPlaylistPrivate) $db->exec("ALTER TABLE playlists ADD COLUMN is_private INTEGER DEFAULT 0");

    // Likes + analytique d'écoute (miroir de la migration dans api.php, voir les commentaires là-bas).
    $db->exec("CREATE TABLE IF NOT EXISTS likes (user_id INTEGER NOT NULL, track_id INTEGER NOT NULL, created_at INTEGER, PRIMARY KEY (user_id, track_id))");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_likes_track ON likes(track_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS listen_events (id INTEGER PRIMARY KEY AUTOINCREMENT, track_id INTEGER NOT NULL, user_id INTEGER NOT NULL, listened_seconds INTEGER DEFAULT 0, created_at INTEGER)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_listen_track ON listen_events(track_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_listen_user ON listen_events(user_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_listen_created ON listen_events(created_at)");

    // Gestion de l'authentification
    require_once 'auth.php';

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

    // Récupération des paramètres
    $settingsRaw = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name = $settingsRaw['site_name'] ?? 'Purple Music';
    
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

    // like_count : public. is_liked : propre à l'utilisateur connecté (contrairement à api.php?action=list,
    // qui reste anonyme -- ici la session PHP donne déjà l'identité, pas besoin d'un endpoint séparé
    // comme my_likes côté Android).
    $tracksStmt = $db->prepare(
        "SELECT tracks.*, users.username as uploader_name,
                (SELECT COUNT(*) FROM likes WHERE likes.track_id = tracks.id) as like_count,
                (SELECT COUNT(*) FROM likes WHERE likes.track_id = tracks.id AND likes.user_id = ?) as is_liked
         FROM tracks JOIN users ON tracks.uploader_id = users.id ORDER BY play_count DESC, id DESC"
    );
    $tracksStmt->execute([$user_id]);
    $all_tracks = $tracksStmt->fetchAll(PDO::FETCH_ASSOC);
    // Playlists privées (is_private=1) : visibles seulement par leur créateur (ou un admin) -- jamais par
    // les autres utilisateurs, contrairement au comportement historique où toutes les playlists de tout
    // le monde étaient publiques par défaut (voir aussi la même règle dans api.php?action=playlists).
    if ($is_admin) {
        $all_playlists = $db->query("SELECT playlists.*, users.username FROM playlists JOIN users ON playlists.creator_id = users.id")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $playlistsStmt = $db->prepare("SELECT playlists.*, users.username FROM playlists JOIN users ON playlists.creator_id = users.id WHERE playlists.is_private = 0 OR playlists.creator_id = ?");
        $playlistsStmt->execute([$user_id]);
        $all_playlists = $playlistsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Liste des comptes, pour l'onglet "Utilisateurs" de l'Admin Panel (page dédiée, admin uniquement).
    $all_users = $is_admin ? $db->query("SELECT id, username, is_admin FROM users ORDER BY username COLLATE NOCASE ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

    // Onglet initial de l'Admin Panel (préservé après un redirect POST/GET depuis une action de cette page, ex: ?admin_tab=users).
    $adminTabOptions = ['general', 'theme', 'media', 'genres', 'users'];
    $initialAdminTab = in_array($_GET['admin_tab'] ?? '', $adminTabOptions, true) ? $_GET['admin_tab'] : 'general';

} catch (Exception $e) { die(t('err_db_prefix') . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_file); ?>?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style.css?v=<?php echo urlencode($assetVersion); ?>">
    <style>
        /* Variables dynamiques injectées depuis la BDD */
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
            --player-bg: <?php echo $color_player_bg; ?>;
            --mob-nav-bg: <?php echo $color_mob_nav_bg; ?>;
            --fp-gradient-1: <?php echo $color_fp_gradient_1; ?>;
            --fp-gradient-2: <?php echo $color_fp_gradient_2; ?>;
        }
    </style>
</head>
<body x-data>

<?php if (!$user_id): ?>
    <?php
        // Après un échec de soumission (identifiants invalides, mot de passe trop court, nom déjà pris...), on
        // rouvre la page sur le même mode (connexion/inscription) que la tentative, et on repré-remplit le nom
        // d'utilisateur (jamais le mot de passe) — sinon l'utilisateur perd tout son contexte à chaque rechargement.
        $authInitialMode = (isset($error) && isset($_POST['register'])) ? 'register' : 'login';
        $authPrefillUsername = (isset($error) && isset($_POST['username'])) ? (string)$_POST['username'] : '';
    ?>
    <div class="auth-page" x-data="authForm('<?php echo $authInitialMode; ?>', '<?php echo htmlspecialchars(addslashes($authPrefillUsername)); ?>')">
        <div class="logo" style="font-size:3em; text-align:center; margin-bottom:8px;"><?php echo htmlspecialchars($site_name); ?></div>
        <p class="auth-subtitle"><?php echo t('login_page_subtitle'); ?></p>

        <div class="auth-card">
            <!-- Bascule connexion / inscription : deux onglets clairement distincts (même langage visuel pilule
                 que .settings-tabs), plutôt que l'ancien second bouton "Créer un compte" en bas de formulaire
                 qui se confondait facilement avec un simple bouton secondaire. -->
            <div class="settings-tabs auth-mode-tabs">
                <button type="button" class="settings-tab-btn" :class="{ active: mode === 'login' }" @click="switchMode('login')"><?php echo t('login_btn'); ?></button>
                <button type="button" class="settings-tab-btn" :class="{ active: mode === 'register' }" @click="switchMode('register')"><?php echo t('login_register_btn'); ?></button>
            </div>

            <?php if (isset($error)): ?>
                <p class="auth-server-error" x-show="showServerError" x-cloak><?php echo $error; ?></p>
            <?php endif; ?>

            <form method="post" @submit="onSubmit($event)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="text" name="username" x-model="username" placeholder="<?php echo htmlspecialchars(t('login_username_placeholder')); ?>" autocomplete="username" required>
                <input type="password" name="password" x-model="password" placeholder="<?php echo htmlspecialchars(t('login_password_placeholder')); ?>" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" required>
                <!-- Champ de confirmation : uniquement en mode inscription (absent du mode connexion, qui n'a jamais
                     besoin de le poser). :required suit x-show pour ne pas bloquer la validation native HTML quand
                     le champ est masqué. -->
                <div x-show="mode === 'register'" x-cloak>
                    <input type="password" name="confirm_password" x-model="confirmPassword" placeholder="<?php echo htmlspecialchars(t('login_confirm_password_placeholder')); ?>" autocomplete="new-password" :required="mode === 'register'">
                </div>
                <p x-show="clientError" x-cloak class="auth-client-error" x-text="clientError"></p>
                <button type="submit" :name="mode" class="btn btn-primary auth-submit-btn" x-text="mode === 'login' ? T('login_btn') : T('login_register_btn')"><?php echo $authInitialMode === 'login' ? t('login_btn') : t('login_register_btn'); ?></button>
            </form>
        </div>
    </div>
<?php else: ?>

    <header>
        <div class="logo"><?php echo htmlspecialchars($site_name); ?> <?php if($is_admin) echo "<small style='color:gold; font-size:10px; vertical-align:middle;'>" . t('admin_badge') . "</small>"; ?></div>
        <nav>
            <span id="nav-accueil" class="active" onclick="showSection('accueil')"><?php echo t('nav_library'); ?></span>
            <span id="nav-playlists" onclick="showSection('playlists')"><?php echo t('nav_playlists'); ?></span>
            <?php if($is_admin): ?>
                <span id="nav-admin" class="admin-nav-btn" style="cursor:pointer;" onclick="showSection('admin')"><?php echo t('nav_admin_panel'); ?></span>
            <?php endif; ?>
        </nav>
        <div class="header-actions">
            <button class="btn-icon" id="queue-toggle" onclick="toggleQueue()" title="<?php echo htmlspecialchars(t('btn_queue')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
            </button>
            <button class="btn-icon" onclick="openCreateModal()" title="<?php echo htmlspecialchars(t('btn_create_playlist')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M14 10H2v2h12v-2zm0-4H2v2h12V6zM2 16h8v-2H2v2zm16-4v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4z"/></svg>
            </button>
            <button class="btn-icon" onclick="openModal('uploadModal')" title="<?php echo htmlspecialchars(t('btn_upload')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
            </button>
            <button class="btn-icon" onclick="openModal('settingsModal')" title="<?php echo htmlspecialchars(t('btn_settings')); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.4 13c.0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6l-2.1-1.7zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
            </button>
            <a href="?logout=1" class="btn" style="color:#a196b4;"><?php echo t('btn_logout'); ?></a>
        </div>
        <button class="mobile-settings-btn" onclick="openModal('settingsModal')">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19.4 13c.0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6l-2.1-1.7zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
        </button>
    </header>

<?php include __DIR__ . '/templates/panels.php'; ?>
<?php include __DIR__ . '/templates/home.php'; ?>

<?php include __DIR__ . '/templates/playlists.php'; ?>

<?php include __DIR__ . '/templates/admin.php'; ?>

    <div id="mobile-bottom-nav">
        <button class="mob-nav-item active" id="mob-nav-accueil" onclick="showSection('accueil')">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg><?php echo t('mob_nav_library'); ?>
        </button>
        <button class="mob-nav-item" id="mob-nav-playlists" onclick="showSection('playlists')">
            <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg><?php echo t('mob_nav_mixes'); ?>
        </button>
        <?php if($is_admin): ?>
            <button class="mob-nav-item" id="mob-nav-admin" onclick="showSection('admin')" style="color:#e67e22;">
                <svg viewBox="0 0 24 24"><path d="M19.4 13c.0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6l-2.1-1.7zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg><?php echo t('mob_nav_admin'); ?>
            </button>
        <?php endif; ?>
        <button class="mob-nav-item" onclick="toggleQueue()">
            <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg><?php echo t('btn_queue'); ?>
        </button>
        <button class="mob-nav-item" onclick="openModal('uploadModal')">
            <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg><?php echo t('btn_upload'); ?>
        </button>
    </div>

<?php include __DIR__ . '/templates/modal-settings.php'; ?>

<?php include __DIR__ . '/templates/modals.php'; ?>
<?php include __DIR__ . '/templates/chrome.php'; ?>

<?php endif; ?>

<?php include __DIR__ . '/templates/scripts.php'; ?>
    <script defer src="app.js?v=<?php echo urlencode($assetVersion); ?>"></script>
    <script defer src="vendor/alpine.min.js"></script>
</body>
</html>
