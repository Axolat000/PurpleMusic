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

    // Gestion des actions (Upload, Edit, Delete, etc.)
    require_once 'actions.php';

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

    // Ajax : Incrémenter les écoutes
    if (isset($_GET['increment_play'])) {
        $stmt = $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?");
        $stmt->execute([$_GET['increment_play']]);
        exit;
    }

    // Ajax : Recommandations personnalisées (voir build_recommendations() dans functions.php) -- lecture
    // seule, pas de CSRF nécessaire, même logique que get_lyrics/get_playlist_tracks ci-dessous. cover_url/
    // stream_url doivent pointer vers api.php (même construction que list là-bas) : le front web n'a pas
    // d'autre route publique pour ces fichiers.
    if (isset($_GET['recommendations'])) {
        $recoBaseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/";
        // full=1 : classement complet (pas juste le top 20 de la rangée d'accueil) -- utilisé par le
        // client pour trier "Toute la bibliothèque" par défaut sur la recommandation (voir openBrowseAll()/
        // filterAndSortTracks() dans app.js, mode de tri 'recommended').
        $recoLimit = !empty($_GET['full']) ? PHP_INT_MAX : 20;
        echo json_encode(build_recommendations($db, $user_id, $recoBaseUrl, $recoLimit), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        exit;
    }

    // Ajax : Récupérer les musiques d'une playlist
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

    // Ajax : Récupérer (et mettre en cache) les paroles synchronisées/brutes depuis lrclib.net
    if (isset($_GET['get_lyrics'])) {
        header('Content-Type: application/json');

        if (!$user_id) {
            http_response_code(403);
            echo json_encode(['error' => t('err_not_authenticated')]);
            exit;
        }

        $trackId = filter_var($_GET['get_lyrics'], FILTER_VALIDATE_INT);
        if ($trackId === false || $trackId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => t('err_invalid_track_id')]);
            exit;
        }

        $stmt = $db->prepare("SELECT title, artist, lyrics_synced, lyrics_plain, lyrics_checked_at FROM tracks WHERE id = ?");
        $stmt->execute([$trackId]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$track) {
            http_response_code(404);
            echo json_encode(['error' => t('err_track_not_found')]);
            exit;
        }

        // --- Résultat déjà en cache (lookup déjà tenté, trouvé ou non) : pas de requête réseau ---
        if ($track['lyrics_checked_at'] !== null) {
            $syncedCached = !empty($track['lyrics_synced']) ? $track['lyrics_synced'] : null;
            $plainCached = !empty($track['lyrics_plain']) ? $track['lyrics_plain'] : null;
            echo json_encode([
                'synced' => $syncedCached,
                'plain' => $plainCached,
                'found' => ($syncedCached !== null || $plainCached !== null),
                'cached' => true,
            ]);
            exit;
        }

        // --- Pas encore tenté : on interroge lrclib.net ---
        // Les titres/artistes sont stockés HTML-encodés (sanitize_text -> htmlspecialchars),
        // on les décode pour envoyer le texte brut à l'API de recherche.
        $queryTitle = html_entity_decode((string)$track['title'], ENT_QUOTES, 'UTF-8');
        $queryArtist = html_entity_decode((string)$track['artist'], ENT_QUOTES, 'UTF-8');

        $lrclibUrl = 'https://lrclib.net/api/get?' . http_build_query([
            'track_name' => $queryTitle,
            'artist_name' => $queryArtist,
        ]);

        $synced = null;
        $plain = null;
        $found = false;
        $shouldCache = false;

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
                    // Réponse propre de lrclib.net : piste absente de leur base -> "vérifié, rien trouvé"
                    $found = false;
                    $shouldCache = true;
                }
                // Autres codes (ex: 520 Cloudflare, 5xx) : on ne met pas en cache, on retentera au prochain essai.
            }
        }

        if ($shouldCache) {
            $upd = $db->prepare("UPDATE tracks SET lyrics_synced = ?, lyrics_plain = ?, lyrics_checked_at = ? WHERE id = ?");
            $upd->execute([$synced, $plain, time(), $trackId]);
        }

        echo json_encode([
            'synced' => $synced,
            'plain' => $plain,
            'found' => $found,
            'cached' => false,
        ]);
        exit;
    }

    // Ajax : Vérifier si une nouvelle version de l'app est disponible (admin uniquement, lecture seule
    // -> pas de CSRF nécessaire, même logique que get_lyrics/get_playlist_tracks ci-dessus). Compare le
    // SHA du commit avec lequel l'image Docker a été buildée (APP_COMMIT_SHA, injecté au build par le
    // workflow GitHub Actions) au HEAD actuel de la branche main sur GitHub. Résultat mis en cache
    // (fichier dans PURPLEMUSIC_DATA_DIR, 1h de TTL) pour ne pas taper l'API GitHub non-authentifiée
    // (60 req/h/IP) à chaque rechargement de page par un admin.
    if (isset($_GET['check_update'])) {
        header('Content-Type: application/json');

        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['error' => t('err_not_authenticated')]);
            exit;
        }

        $localSha = (string) getenv('APP_COMMIT_SHA');
        if ($localSha === '' || $localSha === 'unknown') {
            // Pas d'image Docker versionnée (dev local, install manuelle, build sans build-arg...) :
            // aucune base de comparaison fiable, on ne vérifie pas (évite un faux positif permanent).
            echo json_encode(['checked' => false, 'update_available' => false]);
            exit;
        }

        $cacheFile = $dataDir . '/update_check_cache.json';
        $cacheTtl = 3600;
        $cached = null;
        if (file_exists($cacheFile)) {
            $rawCache = @file_get_contents($cacheFile);
            $decoded = $rawCache !== false ? json_decode($rawCache, true) : null;
            if (is_array($decoded)) $cached = $decoded;
        }

        $remoteSha = null;
        // Le cache est écrit dans PURPLEMUSIC_DATA_DIR, qui survit à un redéploiement — mais APP_COMMIT_SHA,
        // lui, change à chaque nouvelle image. Sans le comparateur local_sha ci-dessous, un cache "frais" (TTL)
        // écrit juste avant un déploiement continuait de dire "mise à jour disponible" juste après, même une
        // fois l'admin déjà à jour (vécu en prod : popup affiché à tort pendant jusqu'à 1h après un déploiement).
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
                // GitHub renvoie 403 sans User-Agent sur toutes les requêtes API.
                CURLOPT_USERAGENT => 'PurpleMusic-UpdateChecker',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);

            if ($curlErrNo === 0 && $response !== false && $httpCode === 200) {
                $data = json_decode($response, true);
                if (is_array($data) && !empty($data['sha']) && is_string($data['sha'])) {
                    $remoteSha = $data['sha'];
                }
            }

            if ($remoteSha !== null) {
                @file_put_contents($cacheFile, json_encode(['checked_at' => time(), 'remote_sha' => $remoteSha, 'local_sha' => $localSha]));
            } elseif ($cached !== null && isset($cached['remote_sha'])) {
                // GitHub injoignable/rate-limité : on retombe sur le dernier résultat connu plutôt que rien,
                // sans réécrire le cache (pour retenter une vraie requête au prochain appel après le TTL).
                $remoteSha = $cached['remote_sha'];
            }
        }

        if ($remoteSha === null) {
            // Échec réseau et rien en cache : jamais d'erreur fatale, juste "pas d'info de mise à jour".
            echo json_encode(['checked' => false, 'update_available' => false]);
            exit;
        }

        echo json_encode([
            'checked' => true,
            'update_available' => ($remoteSha !== $localSha),
            'watchtower_configured' => (getenv('WATCHTOWER_API_URL') ?: '') !== '' && (getenv('WATCHTOWER_API_TOKEN') ?: '') !== '',
        ]);
        exit;
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

    <div id="queue-panel">
        <button class="close-queue-mobile" onclick="toggleQueue()"><?php echo t('queue_close'); ?></button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;"><?php echo t('queue_title'); ?></h3>
        <div id="queue-list" style="margin-top:15px;">
            <p style="color:#666; font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
        </div>
    </div>

    <!-- Paroles (desktop) : panneau latéral droit, même mécanisme que la file d'attente. -->
    <div id="lyrics-panel" x-data="lyricsScroller(() => $store.ui.lyricsPanelOpen)" @wheel="userInteracted()" @touchstart="userInteracted()" :class="{ open: $store.ui.lyricsPanelOpen }">
        <button class="lyrics-panel-close" onclick="closeLyricsPanel()" title="<?php echo htmlspecialchars(t('queue_close')); ?>">
            <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:currentColor;"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;"><?php echo t('btn_lyrics'); ?></h3>
        <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
        <div class="lyrics-panel-body" style="margin-top:15px;">
            <template x-if="$store.ui.lyricsLoading">
                <p class="fp-lyrics-status"><?php echo t('lyrics_loading'); ?></p>
            </template>
            <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === null">
                <p class="fp-lyrics-status"><?php echo t('lyrics_prompt'); ?></p>
            </template>
            <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === false">
                <p class="fp-lyrics-status"><?php echo t('lyrics_not_found'); ?></p>
            </template>
            <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length > 0">
                <div>
                    <template x-for="(line, idx) in $store.ui.lyricsSynced" :key="idx">
                        <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'" x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && isActive && $el.scrollIntoView({block:'center', behavior:'smooth'})" @click="seekToLyricLine(line.time)"></div>
                    </template>
                </div>
            </template>
            <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length === 0 && $store.ui.lyricsPlain">
                <div class="lyrics-plain-text" x-text="$store.ui.lyricsPlain"></div>
            </template>
        </div>
    </div>

    <main id="accueil" x-show="$store.ui.section === 'accueil'" x-cloak>
        <div class="controls-container">
            <h2 class="section-title"><?php echo t('section_all_tracks'); ?></h2>
            <div class="search-row">
                <div class="search-container">
                    <input type="text" id="searchInput" class="search-input" placeholder="<?php echo htmlspecialchars(t('search_placeholder')); ?>" onkeyup="onSearchInput()" x-model="$store.ui.searchTerm">
                </div>
                <div class="filter-wrapper" title="<?php echo htmlspecialchars(t('tooltip_sort')); ?>">
                    <div class="filter-icon-visual">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M3 4c0-0.55.45-1 1-1h10c.55 0 1 .45 1 1v1.5c0 .28-.11.53-.3.71L10 10.9v5.2c0 .28-.11.53-.29.71l-2 2c-.18.18-.43.29-.71.29s-.53-.11-.71-.29A.996.996 0 0 1 6 18.1v-7.2L3.3 6.21A.996.996 0 0 1 3 5.5V4z"/>
                            <rect x="16" y="5" width="6" height="2" rx="1" />
                            <rect x="16" y="11" width="6" height="2" rx="1" />
                            <rect x="16" y="17" width="6" height="2" rx="1" />
                        </svg>
                    </div>
                    <select id="sortSelect" class="filter-select-overlay" onchange="filterAndSortTracks()">
                        <option value="recommended" selected><?php echo t('sort_recommended'); ?></option>
                        <option value="popular"><?php echo t('sort_popular'); ?></option>
                        <option value="date_desc"><?php echo t('sort_recent'); ?></option>
                        <option value="date_asc"><?php echo t('sort_oldest'); ?></option>
                        <option value="alpha_asc"><?php echo t('sort_alpha_asc'); ?></option>
                        <option value="alpha_desc"><?php echo t('sort_alpha_desc'); ?></option>
                        <option value="artist"><?php echo t('sort_artist'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div id="home-sections" x-show="$store.ui.searchTerm.trim() === ''" x-cloak>
            <template x-if="$store.ui.recentTracks.length > 0">
                <div class="home-row">
                    <div class="home-row-header">
                        <h3 class="home-row-title"><?php echo t('sort_recent'); ?></h3>
                        <button type="button" class="home-row-see-all" onclick="openBrowseAll('date_desc', '<?php echo t('sort_recent'); ?>')"><?php echo t('home_see_all'); ?></button>
                    </div>
                    <div class="home-row-wrap" x-data="homeRowScroller()">
                        <button type="button" class="home-row-arrow home-row-arrow-left" x-show="canLeft" x-cloak @click="scrollDir(-1)" aria-label="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                        </button>
                        <div class="home-row-scroll" x-ref="scrollEl" @scroll="onScroll">
                            <template x-for="t in $store.ui.recentTracks" :key="'recent-' + t.id">
                                <div class="home-track-card" @click="playTrackById(t.id)">
                                    <img :src="'covers/' + (t.cover || 'default.png')" loading="lazy" @error="$event.target.src = 'covers/default.png'">
                                    <div class="home-track-card-title" x-text="t.title"></div>
                                    <div class="home-track-card-sub" x-text="t.artist"></div>
                                </div>
                            </template>
                        </div>
                        <button type="button" class="home-row-arrow home-row-arrow-right" x-show="canRight" x-cloak @click="scrollDir(1)" aria-label="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                        </button>
                    </div>
                </div>
            </template>
            <!-- "Recommandé pour toi" : rempli de façon asynchrone (voir init() dans app.js, calcul serveur
                 build_recommendations()) -- absent tant que la requête n'a pas répondu, apparaît une fois
                 chargé plutôt que de bloquer l'affichage du reste de l'accueil. -->
            <template x-if="$store.ui.recommendedTracks.length > 0">
                <div class="home-row">
                    <h3 class="home-row-title" style="margin-bottom:15px;"><?php echo t('home_recommended_for_you'); ?></h3>
                    <div class="home-row-wrap" x-data="homeRowScroller()">
                        <button type="button" class="home-row-arrow home-row-arrow-left" x-show="canLeft" x-cloak @click="scrollDir(-1)" aria-label="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                        </button>
                        <div class="home-row-scroll" x-ref="scrollEl" @scroll="onScroll">
                            <template x-for="t in $store.ui.recommendedTracks" :key="'reco-' + t.id">
                                <div class="home-track-card" @click="playTrackById(t.id)">
                                    <img :src="'covers/' + (t.cover || 'default.png')" loading="lazy" @error="$event.target.src = 'covers/default.png'">
                                    <div class="home-track-card-title" x-text="t.title"></div>
                                    <div class="home-track-card-sub" x-text="t.artist"></div>
                                </div>
                            </template>
                        </div>
                        <button type="button" class="home-row-arrow home-row-arrow-right" x-show="canRight" x-cloak @click="scrollDir(1)" aria-label="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                        </button>
                    </div>
                </div>
            </template>
            <template x-if="$store.ui.popularTracks.length > 0">
                <div class="home-row">
                    <div class="home-row-header">
                        <h3 class="home-row-title"><?php echo t('sort_popular'); ?></h3>
                        <button type="button" class="home-row-see-all" onclick="openBrowseAll('popular', '<?php echo t('sort_popular'); ?>')"><?php echo t('home_see_all'); ?></button>
                    </div>
                    <div class="home-row-wrap" x-data="homeRowScroller()">
                        <button type="button" class="home-row-arrow home-row-arrow-left" x-show="canLeft" x-cloak @click="scrollDir(-1)" aria-label="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                        </button>
                        <div class="home-row-scroll" x-ref="scrollEl" @scroll="onScroll">
                            <template x-for="t in $store.ui.popularTracks" :key="'popular-' + t.id">
                                <div class="home-track-card" @click="playTrackById(t.id)">
                                    <img :src="'covers/' + (t.cover || 'default.png')" loading="lazy" @error="$event.target.src = 'covers/default.png'">
                                    <div class="home-track-card-title" x-text="t.title"></div>
                                    <div class="home-track-card-sub" x-text="t.artist"></div>
                                </div>
                            </template>
                        </div>
                        <button type="button" class="home-row-arrow home-row-arrow-right" x-show="canRight" x-cloak @click="scrollDir(1)" aria-label="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                        </button>
                    </div>
                </div>
            </template>
            <template x-if="$store.ui.playlistsPreview.length > 0">
                <div class="home-row">
                    <h3 class="home-row-title"><?php echo t('home_your_mixes'); ?></h3>
                    <div class="home-row-wrap" x-data="homeRowScroller()">
                        <button type="button" class="home-row-arrow home-row-arrow-left" x-show="canLeft" x-cloak @click="scrollDir(-1)" aria-label="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                        </button>
                        <div class="home-row-scroll" x-ref="scrollEl" @scroll="onScroll">
                            <template x-for="p in $store.ui.playlistsPreview" :key="'pl-' + p.id">
                                <div class="home-track-card" @click="openPlaylistDetail(p.id)">
                                    <div class="playlist-cover">🎵<img x-show="p.cover" :src="'covers/' + p.cover" loading="lazy" @error="$event.target.remove()"></div>
                                    <div class="home-track-card-title" x-text="p.name"></div>
                                    <div class="home-track-card-sub" x-text="p.username"></div>
                                </div>
                            </template>
                        </div>
                        <button type="button" class="home-row-arrow home-row-arrow-right" x-show="canRight" x-cloak @click="scrollDir(1)" aria-label="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="track-list" id="global-list"></div>
        <div id="load-more-trigger"></div>
    </main>

    <!-- Page "Voir tout" : liste dédiée pré-triée (Ajouts récents / Les plus écoutés), séparée de la
         bibliothèque de l'accueil -- ne modifie jamais #sortSelect, voir openBrowseAll() dans app.js. -->
    <main id="browse" x-show="$store.ui.section === 'browse'" x-cloak>
        <button class="btn btn-outline" style="margin-bottom:20px;" onclick="showSection('accueil')"><?php echo t('btn_back'); ?></button>
        <h2 class="section-title" style="margin-bottom:20px;" x-text="$store.ui.browseTitle"></h2>
        <div class="track-list" id="browse-list"></div>
        <div id="browse-load-more-trigger"></div>
    </main>

    <?php
    // Séparation public/privé pour l'affichage uniquement -- $all_playlists est déjà filtré plus haut
    // (playlists publiques + les privées de l'utilisateur courant, ou tout pour un admin). "Mes playlists
    // privées" ne montre que les siennes, même pour un admin qui verrait aussi celles des autres en base.
    $publicPlaylists = array_filter($all_playlists, fn($p) => empty($p['is_private']));
    $privatePlaylists = array_filter($all_playlists, fn($p) => !empty($p['is_private']) && $p['creator_id'] == $user_id);
    ?>
    <main id="playlists" x-show="$store.ui.section === 'playlists'" x-cloak>
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo t('home_public_playlists'); ?></h2>
        <div class="playlist-grid">
            <?php foreach($publicPlaylists as $p): ?>
                <?php include __DIR__ . '/templates/playlist-card.php'; ?>
            <?php endforeach; ?>
        </div>

        <h2 class="section-title" style="margin:35px 0 25px;"><?php echo t('home_private_playlists'); ?></h2>
        <?php if (empty($privatePlaylists)): ?>
            <p style="color:var(--text-muted); font-size:0.9em;"><?php echo t('no_private_playlists'); ?></p>
        <?php else: ?>
        <div class="playlist-grid">
            <?php foreach($privatePlaylists as $p): ?>
                <?php include __DIR__ . '/templates/playlist-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <main id="playlist-detail" x-show="$store.ui.section === 'playlist-detail'" x-cloak>
        <button class="btn btn-outline" style="margin-bottom:20px;" onclick="backToPlaylists()"><?php echo t('btn_back_to_playlists'); ?></button>
        <template x-if="$store.ui.playlistDetail">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px; margin-bottom:25px;">
                    <div style="display:flex; gap:18px; align-items:center;">
                        <div class="playlist-cover playlist-detail-cover">🎵<img x-show="$store.ui.playlistDetail.cover" :src="'covers/' + $store.ui.playlistDetail.cover" loading="lazy" @error="$event.target.remove()"></div>
                        <div>
                            <h2 class="section-title" style="margin-bottom:8px;" x-text="$store.ui.playlistDetail.name"></h2>
                            <p style="color:var(--text-muted); font-size:0.9em; margin:0;"><?php echo t('created_by'); ?> <strong x-text="$store.ui.playlistDetail.username"></strong></p>
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn btn-primary" onclick="playAllInPlaylistDetail()"><?php echo t('btn_play_all'); ?></button>
                        <template x-if="$store.ui.playlistDetail.canEdit">
                            <button class="btn btn-outline" onclick="editPlaylistFromDetail()"><?php echo t('btn_edit'); ?></button>
                        </template>
                        <template x-if="$store.ui.playlistDetail.canEdit">
                            <button class="btn btn-danger" @click="confirmDelete('<?php echo t('confirm_delete_playlist'); ?>', '?delete_playlist=' + $store.ui.playlistDetail.id + '&csrf_token=' + CSRF_TOKEN)"><?php echo t('btn_delete_short'); ?></button>
                        </template>
                    </div>
                </div>
                <div class="track-list">
                    <template x-if="$store.ui.playlistDetail.loading">
                        <div style="padding:40px; text-align:center; color:#666;"><?php echo t('loading_generic'); ?></div>
                    </template>
                    <template x-if="!$store.ui.playlistDetail.loading && $store.ui.playlistDetail.tracks.length === 0">
                        <div style="padding:40px; text-align:center; color:#666;"><?php echo t('playlist_empty'); ?></div>
                    </template>
                    <template x-for="t in $store.ui.playlistDetail.tracks" :key="t.id">
                        <div class="track-item" @click="playTrackInPlaylistDetail(t.id)">
                            <img :src="'covers/' + (t.cover || 'default.png')" loading="lazy" class="mini-cover" @error="$event.target.src = 'covers/default.png'">
                            <div style="overflow:hidden;">
                                <div style="font-weight:700; font-size:1.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px;" x-text="t.title"></div>
                                <div style="font-size:0.85em; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <span x-text="t.artist"></span> <span style="opacity:0.6;font-size:0.9em;" x-text="'• ' + (t.genre || 'Autre') + ' • ▶ ' + (t.play_count || 0)"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </main>

    <?php if($is_admin): ?>
    <main id="admin" x-show="$store.ui.section === 'admin'" x-cloak x-data="adminPageForm('<?php echo $initialAdminTab; ?>')">
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo t('admin_panel_title'); ?></h2>

        <div class="settings-tabs admin-page-tabs">
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'general' }" @click="activeTab = 'general'"><?php echo t('admin_section_general'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'theme' }" @click="activeTab = 'theme'"><?php echo t('admin_section_theme'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'media' }" @click="activeTab = 'media'"><?php echo t('admin_section_media'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'genres' }" @click="activeTab = 'genres'"><?php echo t('admin_section_genres'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'users' }" @click="activeTab = 'users'"><?php echo t('admin_section_users'); ?></button>
        </div>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div x-show="activeTab === 'general'" x-cloak>
                <label><?php echo t('admin_app_name_label'); ?></label>
                <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
            </div>

            <div x-show="activeTab === 'theme'" x-cloak>
                <div class="extended-color-grid">
                    <div class="extended-color-item"><span><?php echo t('admin_color_bg'); ?></span><input type="color" name="adm_color_bg" value="<?php echo $color_bg; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_panel'); ?></span><input type="color" name="adm_color_panel" value="<?php echo $color_panel; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_primary'); ?></span><input type="color" name="adm_color_primary" value="<?php echo $color_primary; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_accent'); ?></span><input type="color" name="adm_color_accent" value="<?php echo $color_accent; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_text'); ?></span><input type="color" name="adm_color_text" value="<?php echo $color_text; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_text_muted'); ?></span><input type="color" name="adm_color_text_muted" value="<?php echo $color_text_muted; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_border'); ?></span><input type="color" name="adm_color_border" value="<?php echo $color_border; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_search_bg'); ?></span><input type="color" name="adm_color_search_bg" value="<?php echo $color_search_bg; ?>"></div>

                    <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_1'); ?></span><input type="color" name="adm_color_fp_gradient_1" value="<?php echo $color_fp_gradient_1; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_2'); ?></span><input type="color" name="adm_color_fp_gradient_2" value="<?php echo $color_fp_gradient_2; ?>"></div>
                </div>

                <label style="margin-top: 12px; display: block;"><?php echo t('admin_header_bg_label'); ?></label>
                <input type="text" name="adm_color_header_bg" value="<?php echo htmlspecialchars($color_header_bg); ?>" placeholder="rgba(27, 20, 41, 0.85)">

                <label style="margin-top: 10px; display: block;"><?php echo t('admin_player_bg_label'); ?></label>
                <input type="text" name="adm_color_player_bg" value="<?php echo htmlspecialchars($color_player_bg); ?>" placeholder="rgba(30, 24, 45, 0.85)">

                <label style="margin-top: 10px; display: block;"><?php echo t('admin_mobnav_bg_label'); ?></label>
                <input type="text" name="adm_color_mob_nav_bg" value="<?php echo htmlspecialchars($color_mob_nav_bg); ?>" placeholder="rgba(21, 16, 32, 0.95)">
            </div>

            <div x-show="activeTab === 'media'" x-cloak>
                <label><?php echo t('admin_favicon_label'); ?></label>
                <input type="file" name="adm_favicon" accept="image/png, image/x-icon">
                <label><?php echo t('admin_default_cover_label'); ?></label>
                <input type="file" name="adm_default_cover" accept="image/png">
            </div>

            <div x-show="activeTab === 'genres'" x-cloak>
                <label><?php echo t('admin_new_genre_label'); ?></label>
                <input type="text" name="adm_new_genre" placeholder="<?php echo htmlspecialchars(t('admin_new_genre_placeholder')); ?>">

                <label style="font-weight:bold; display:block; margin-bottom:5px;"><?php echo t('admin_active_genres_label'); ?></label>
                <div style="max-height:220px; overflow-y:auto; border:1px solid var(--border-color); padding:10px; border-radius:10px;">
                    <?php foreach($genresList as $g): ?>
                        <div class="adm-genre-item">
                            <span><?php echo htmlspecialchars($g); ?></span>
                            <a href="?delete_genre=<?php echo urlencode($g); ?>&admin_tab=genres&csrf_token=<?php echo $csrf_token; ?>" style="color:var(--danger); text-decoration:none; font-weight:bold;" onclick="return confirmDelete('<?php echo t('confirm_delete_genre'); ?>', '?delete_genre=<?php echo urlencode($g); ?>&admin_tab=genres&csrf_token=<?php echo $csrf_token; ?>')">✕</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex; gap:15px; margin-top: 25px;" x-show="activeTab !== 'users'" x-cloak>
                <button type="submit" name="save_admin_settings" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>

        <div x-show="activeTab === 'users'" x-cloak>
            <div class="admin-user-table-wrap">
                <table class="admin-user-table">
                    <thead>
                        <tr>
                            <th><?php echo t('admin_users_table_username'); ?></th>
                            <th><?php echo t('admin_users_table_role'); ?></th>
                            <th><?php echo t('admin_users_table_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_users as $u): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($u['username']); ?>
                                    <?php if ($u['id'] == $user_id): ?><span class="admin-user-you-badge">(<?php echo t('admin_users_you'); ?>)</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_admin']): ?>
                                        <span class="admin-user-role-badge admin-user-role-admin"><?php echo t('admin_badge'); ?></span>
                                    <?php else: ?>
                                        <span class="admin-user-role-badge"><?php echo t('admin_users_role_member'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-user-actions">
                                    <?php if ($u['id'] == $user_id): ?>
                                        <span class="admin-user-self-note"><?php echo t('admin_users_self_note'); ?></span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline admin-user-action-btn" onclick="adminResetPassword(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')"><?php echo t('admin_users_reset_password'); ?></button>
                                        <a href="?toggle_admin=<?php echo (int)$u['id']; ?>&admin_tab=users&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-outline admin-user-action-btn"><?php echo $u['is_admin'] ? t('admin_users_demote') : t('admin_users_promote'); ?></a>
                                        <a href="?delete_user=<?php echo (int)$u['id']; ?>&admin_tab=users&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-danger admin-user-action-btn" onclick="return confirmDelete('<?php echo t('confirm_delete_user'); ?>', '?delete_user=<?php echo (int)$u['id']; ?>&admin_tab=users&csrf_token=<?php echo $csrf_token; ?>')"><?php echo t('btn_delete_short'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <?php endif; ?>

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

    <div id="settingsModal" class="modal" x-show="$store.ui.activeModal === 'settingsModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('settingsModal')"><div class="modal-content" x-data="settingsModalForm()">
        <h2 style="margin-top:0;"><?php echo t('settings_title'); ?></h2>

        <div class="settings-tabs">
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'general' }" @click="activeTab = 'general'"><?php echo t('settings_tab_general'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'library' }" @click="activeTab = 'library'"><?php echo t('settings_tab_library'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'account' }" @click="activeTab = 'account'"><?php echo t('settings_tab_account'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'eq' }" @click="activeTab = 'eq'"><?php echo t('settings_tab_eq'); ?></button>
        </div>

        <div x-show="activeTab === 'general'" x-cloak>
            <p class="settings-section-label"><?php echo t('lang_switcher_label'); ?></p>
            <div class="lang-switch-row">
                <?php
                $langOptions = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'de' => 'Deutsch'];
                foreach ($langOptions as $lc => $label):
                ?>
                    <button type="button" class="lang-switch-btn<?php echo $lang === $lc ? ' active' : ''; ?>" onclick="setLanguage('<?php echo $lc; ?>')"><?php echo $label; ?></button>
                <?php endforeach; ?>
            </div>

            <p class="settings-section-label"><?php echo t('settings_theme_label'); ?></p>
            <!-- Rangée de swatches : presets statiques (violet = pas de surcharge, couleurs admin/BDD) +
                 variantes (amoled_purple/white, midnight_blue/silver) + nouveaux presets + swatch
                 "Personnalisé" en dernier (voir activateCustomTheme() dans app.js). .theme-swatch-row a
                 déjà flex-wrap:wrap (style.css) donc ce nombre d'entrées ne déborde pas, il passe juste
                 sur plusieurs lignes selon la largeur de la modale. -->
            <div class="theme-swatch-row">
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'violet' }" style="--sw-primary:#8E44AD; --sw-accent:#BB86FC;" title="<?php echo htmlspecialchars(t('theme_violet_default')); ?>" @click="applyThemePreset('violet')"></button>
                    <span class="theme-swatch-label">Violet</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'amoled_purple' || $store.ui.themePreset === 'amoled' }" style="--sw-primary:#7B2CBF; --sw-accent:#B388FF;" title="Amoled Purple" @click="applyThemePreset('amoled_purple')"></button>
                    <span class="theme-swatch-label">Amoled Purple</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'amoled_white' }" style="--sw-primary:#4D4D4D; --sw-accent:#E8E8E8;" title="Amoled White" @click="applyThemePreset('amoled_white')"></button>
                    <span class="theme-swatch-label">Amoled White</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'midnight_blue' || $store.ui.themePreset === 'midnight' }" style="--sw-primary:#3B5BDB; --sw-accent:#7C9BFF;" title="Midnight Blue" @click="applyThemePreset('midnight_blue')"></button>
                    <span class="theme-swatch-label">Midnight Blue</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'midnight_silver' }" style="--sw-primary:#6B7A99; --sw-accent:#C7D0E0;" title="Midnight Silver" @click="applyThemePreset('midnight_silver')"></button>
                    <span class="theme-swatch-label">Midnight Silver</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'forest' }" style="--sw-primary:#2E7D4F; --sw-accent:#6FCF97;" title="Forest" @click="applyThemePreset('forest')"></button>
                    <span class="theme-swatch-label">Forest</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'crimson' }" style="--sw-primary:#B33A3A; --sw-accent:#FF6B6B;" title="Crimson" @click="applyThemePreset('crimson')"></button>
                    <span class="theme-swatch-label">Crimson</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'ocean' }" style="--sw-primary:#0E8388; --sw-accent:#4DD8D0;" title="Ocean" @click="applyThemePreset('ocean')"></button>
                    <span class="theme-swatch-label">Ocean</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'sunset' }" style="--sw-primary:#D9822B; --sw-accent:#FFB86B;" title="Sunset" @click="applyThemePreset('sunset')"></button>
                    <span class="theme-swatch-label">Sunset</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'rose' }" style="--sw-primary:#C2478D; --sw-accent:#FF8FC7;" title="Rose" @click="applyThemePreset('rose')"></button>
                    <span class="theme-swatch-label">Rose</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'slate' }" style="--sw-primary:#5C6773; --sw-accent:#9BA8B5;" title="Slate" @click="applyThemePreset('slate')"></button>
                    <span class="theme-swatch-label">Slate</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch theme-swatch-custom" :class="{ active: $store.ui.themePreset === 'custom' }" title="<?php echo htmlspecialchars(t('theme_custom')); ?>" @click="activateCustomTheme()"></button>
                    <span class="theme-swatch-label"><?php echo t('theme_custom'); ?></span>
                </div>
            </div>

            <!-- Constructeur de thème personnalisé : un <input type="color"> par variable de THEME_VAR_NAMES
                 (app.js), même pattern visuel que le sélecteur de couleurs de l'Admin Panel
                 (.extended-color-item, voir plus haut dans ce fichier) mais câblé en JS/localStorage plutôt
                 que via un submit de formulaire serveur -- c'est un réglage personnel par utilisateur, pas
                 le thème global de l'admin. Écriture live : chaque changement de couleur (oninput) persiste
                 et s'applique immédiatement (updateCustomThemeColor() dans app.js), pas de bouton
                 "Enregistrer" séparé. --header-bg/--player-bg/--mob-nav-bg sont normalement des rgba() semi-
                 transparentes dans les presets ; un <input type="color"> ne produit que de l'opaque, c'est
                 un compromis UX volontaire (voir commentaire dans app.js). -->
            <p class="settings-section-label"><?php echo t('custom_theme_label'); ?></p>
            <div class="extended-color-grid">
                <div class="extended-color-item"><span><?php echo t('admin_color_bg'); ?></span><input type="color" id="custom-color-bg-dark" oninput="updateCustomThemeColor('--bg-dark', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_panel'); ?></span><input type="color" id="custom-color-bg-panel" oninput="updateCustomThemeColor('--bg-panel', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_primary'); ?></span><input type="color" id="custom-color-primary" oninput="updateCustomThemeColor('--primary', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_accent'); ?></span><input type="color" id="custom-color-accent" oninput="updateCustomThemeColor('--accent', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_text'); ?></span><input type="color" id="custom-color-text" oninput="updateCustomThemeColor('--text', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_text_muted'); ?></span><input type="color" id="custom-color-text-muted" oninput="updateCustomThemeColor('--text-muted', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_border'); ?></span><input type="color" id="custom-color-border-color" oninput="updateCustomThemeColor('--border-color', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_search_bg'); ?></span><input type="color" id="custom-color-search-bg" oninput="updateCustomThemeColor('--search-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_header_bg_label'); ?></span><input type="color" id="custom-color-header-bg" oninput="updateCustomThemeColor('--header-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_player_bg_label'); ?></span><input type="color" id="custom-color-player-bg" oninput="updateCustomThemeColor('--player-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_mobnav_bg_label'); ?></span><input type="color" id="custom-color-mob-nav-bg" oninput="updateCustomThemeColor('--mob-nav-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_1'); ?></span><input type="color" id="custom-color-fp-gradient-1" oninput="updateCustomThemeColor('--fp-gradient-1', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_2'); ?></span><input type="color" id="custom-color-fp-gradient-2" oninput="updateCustomThemeColor('--fp-gradient-2', this.value)"></div>
            </div>
            <button type="button" class="btn btn-outline" style="margin-bottom:25px;" @click="prefillCustomThemeFromCurrent()"><?php echo t('custom_theme_prefill_btn'); ?></button>

            <p class="settings-section-label"><?php echo t('settings_volume_label'); ?></p>
            <div class="volume-container settings-vol-row">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                <input type="range" id="settings-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
            </div>

            <!-- Visualiseur : réglage persistant unique (plus de bouton par lecteur), appliqué automatiquement
                 dans les deux lecteurs dès qu'activé -- voir setVisualizerEnabled()/applyVisualizerForContext()
                 dans app.js. Même composant .switch-toggle que l'activation de l'égaliseur (onglet Égaliseur). -->
            <div class="eq-enable-row">
                <span class="settings-section-label" style="margin:0;"><?php echo t('btn_visualizer'); ?></span>
                <label class="switch-toggle">
                    <input type="checkbox" :checked="$store.ui.visualizerEnabled" @change="setVisualizerEnabled($event.target.checked)">
                    <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                </label>
            </div>

            <!-- Minuteur de sommeil : sélecteur de préréglages directement dans Paramètres (plus de popover
                 par lecteur). Même liste (0/15/30/45/60/90/120 min) alignée sur l'app Android
                 (SettingsDialog.kt::timerOptions) pour rester cohérent entre les deux clients. -->
            <p class="settings-section-label">
                <?php echo t('btn_sleep_timer'); ?>
                <span x-show="$store.ui.sleepTimerActive" x-cloak style="color:var(--accent); font-weight:700;" x-text="'— ' + formatSleepTimerRemaining($store.ui.sleepTimerRemaining)"></span>
            </p>
            <div class="sleep-timer-settings-row">
                <template x-for="opt in [0, 15, 30, 45, 60, 90, 120]" :key="opt">
                    <button type="button" class="sleep-timer-option sleep-timer-option-settings" :class="{ 'sleep-timer-option-recent': opt > 0 && opt === $store.ui.sleepTimerLastMinutes, active: (opt === 0 && !$store.ui.sleepTimerActive) || (opt > 0 && $store.ui.sleepTimerActive && opt === $store.ui.sleepTimerLastMinutes) }" @click="chooseSleepTimer(opt)" x-text="opt === 0 ? T('sleep_timer_off') : T('sleep_timer_minutes', { n: opt })"></button>
                </template>
            </div>

            <!-- Thème dynamique : surcouche par piste PAR-DESSUS le preset statique actif (voir le rangée de
                 swatches plus haut, non modifiée) -- extrait les couleurs dominante/vibrante de la pochette en
                 cours et anime --fp-gradient-1/2 vers ces couleurs (voir setDynamicThemeEnabled()/
                 applyDynamicThemeForCurrentTrack() dans app.js, appelée depuis loadTrack()). Même composant
                 .switch-toggle que le Visualiseur ci-dessus. -->
            <div class="eq-enable-row">
                <span class="settings-section-label" style="margin:0;"><?php echo t('dynamic_theme_label'); ?></span>
                <label class="switch-toggle">
                    <input type="checkbox" :checked="$store.ui.dynamicThemeEnabled" @change="setDynamicThemeEnabled($event.target.checked)">
                    <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                </label>
            </div>
        </div>

        <div x-show="activeTab === 'library'" x-cloak>
            <p style="color:var(--text-muted); font-size:0.9em; margin-bottom: 20px;"><?php echo t('settings_hide_intro_pre'); ?> <strong style="color:var(--danger);"><?php echo t('settings_hide_word'); ?></strong> :</p>
            <div class="settings-grid">
                <?php foreach($genresList as $g): ?>
                    <label><input type="checkbox" class="genre-filter-cb" data-genre="<?php echo htmlspecialchars($g); ?>" onchange="toggleGenreSetting('<?php echo htmlspecialchars($g); ?>', this.checked)"> <?php echo htmlspecialchars($g); ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div x-show="activeTab === 'account'" x-cloak>
            <p class="settings-section-label"><?php echo t('settings_change_password_title'); ?></p>
            <form @submit.prevent="submitPasswordChange()">
                <input type="password" placeholder="<?php echo htmlspecialchars(t('settings_current_password_placeholder')); ?>" x-model="pwCurrent" autocomplete="current-password" required>
                <input type="password" placeholder="<?php echo htmlspecialchars(t('settings_new_password_placeholder')); ?>" x-model="pwNew" autocomplete="new-password" required>
                <input type="password" placeholder="<?php echo htmlspecialchars(t('settings_confirm_password_placeholder')); ?>" x-model="pwConfirm" autocomplete="new-password" required>
                <p x-show="pwError" x-cloak style="color:var(--danger); font-size:0.85em; margin:-10px 0 15px;" x-text="pwError"></p>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;" :disabled="pwSubmitting"><?php echo t('btn_change_password'); ?></button>
            </form>
        </div>

        <!-- Onglet Égaliseur : chaîne de 5 BiquadFilterNode (peaking) partagée avec le graphe audio du
             Visualizer (voir initAudioGraph()/EQ_BANDS dans app.js -- un seul createMediaElementSource()
             pour toute la durée de vie de <audio id="mainAudio">, donc EQ et Visualizer lisent/écrivent
             le même graphe plutôt que d'en créer chacun le leur). Réglages persistés en localStorage
             (purpleMusicEqEnabled/purpleMusicEqBands, même convention que purpleMusicVolume) et réappliqués
             au chargement (restoreEqUI()). Curseurs .vol-slider réutilisés tels quels (identité visuelle
             volume) sur une plage bipolaire -12..+12 dB -- l'activation/désactivation ne réinitialise pas
             les valeurs choisies, elle bascule juste entre gains appliqués et gains à 0 (unité). -->
        <div x-show="activeTab === 'eq'" x-cloak>
            <div class="eq-enable-row">
                <span class="settings-section-label" style="margin:0;"><?php echo t('eq_enable_label'); ?></span>
                <label class="switch-toggle">
                    <input type="checkbox" id="eq-enable-cb" onchange="setEqEnabled(this.checked)">
                    <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                </label>
            </div>
            <div class="eq-bands" id="eq-bands">
                <?php $eqBandLabels = ['60 Hz', '230 Hz', '910 Hz', '3.6 kHz', '14 kHz']; ?>
                <?php foreach ($eqBandLabels as $eqI => $eqLabel): ?>
                <div class="eq-band-row">
                    <span class="eq-band-freq"><?php echo $eqLabel; ?></span>
                    <input type="range" id="eq-band-<?php echo $eqI; ?>" class="vol-slider eq-band-slider" min="-12" max="12" step="0.5" value="0" oninput="setEqBand(<?php echo $eqI; ?>, this.value)">
                    <span class="eq-band-val" id="eq-band-<?php echo $eqI; ?>-val">0.0 dB</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:flex; gap:15px; margin-top:30px;">
            <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" onclick="closeModal('settingsModal')"><?php echo t('btn_close'); ?></button>
        </div>
    </div></div>

    <!-- Révélation du mot de passe temporaire généré par un admin (Admin Panel > Utilisateurs > Réinitialiser).
         Reste affiché jusqu'à fermeture manuelle (pas de toast auto-dismiss) pour laisser le temps de le copier. -->
    <div id="adminResetPasswordModal" class="modal" x-show="$store.ui.activeModal === 'adminResetPasswordModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('adminResetPasswordModal')"><div class="modal-content" style="max-width:420px;">
        <h2 style="margin-top:0;"><?php echo t('admin_users_reset_password_title'); ?></h2>
        <p style="color:var(--text-muted); font-size:0.9em; margin-bottom:15px;" x-text="T('admin_users_reset_password_intro', { username: $store.ui.adminGeneratedPassword.username })"></p>
        <input type="text" readonly x-model="$store.ui.adminGeneratedPassword.password" onclick="this.select()" style="font-family:monospace; font-weight:700; text-align:center; letter-spacing:1px; margin-bottom:0;">
        <div style="display:flex; gap:15px; margin-top:20px;">
            <button type="button" class="btn btn-outline" style="flex:1; justify-content:center;" onclick="copyAdminGeneratedPassword()"><?php echo t('admin_users_copy_password'); ?></button>
            <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" onclick="closeModal('adminResetPasswordModal')"><?php echo t('btn_close'); ?></button>
        </div>
    </div></div>

    <!-- Popup de mise à jour (admin uniquement) : ouverte automatiquement par $store.ui.checkForUpdate()
         (voir app.js init()) quand une nouvelle version est détectée. Deux états selon que Watchtower est
         configuré côté serveur (bouton "Mettre à jour") ou non (install docker-compose sans sidecar /
         install "docker run" simple -> instructions manuelles, voir DOCKER.md/README.md). -->
    <?php if ($is_admin): ?>
    <div id="updateAvailableModal" class="modal" x-show="$store.ui.activeModal === 'updateAvailableModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.dismissUpdateNotice()"><div class="modal-content" style="max-width:480px;">
        <h2 style="margin-top:0;"><?php echo t('update_available_title'); ?></h2>

        <template x-if="$store.ui.updateTriggerState !== 'updating'">
            <div>
                <p style="color:var(--text-muted); font-size:0.9em; margin-bottom:15px;"><?php echo t('update_available_message'); ?></p>

                <template x-if="$store.ui.updateCheck.watchtowerConfigured">
                    <button type="button" class="btn btn-primary" style="width:100%; justify-content:center; margin-bottom:12px;" :disabled="$store.ui.updateTriggering" @click="$store.ui.triggerUpdate()">
                        <span x-show="!$store.ui.updateTriggering"><?php echo t('btn_update_now'); ?></span>
                        <span x-show="$store.ui.updateTriggering" x-cloak><?php echo t('update_triggering'); ?></span>
                    </button>
                </template>
                <template x-if="!$store.ui.updateCheck.watchtowerConfigured">
                    <div>
                        <p style="color:var(--text-muted); font-size:0.85em; margin-bottom:8px;"><?php echo t('update_manual_intro'); ?></p>
                        <code style="display:block; background:var(--bg-dark); border:1px solid var(--border-color); border-radius:10px; padding:10px 14px; font-size:0.8em; margin-bottom:10px; white-space:pre-wrap; word-break:break-all;">docker compose pull && docker compose up -d</code>
                        <p style="color:var(--text-muted); font-size:0.75em; margin-bottom:8px;"><?php echo t('update_manual_or_run'); ?></p>
                        <code style="display:block; background:var(--bg-dark); border:1px solid var(--border-color); border-radius:10px; padding:10px 14px; font-size:0.8em; white-space:pre-wrap; word-break:break-all;">docker pull ghcr.io/axolat000/purplemusic:latest
docker stop purplemusic && docker rm purplemusic</code>
                        <p style="color:var(--text-muted); font-size:0.75em; margin-top:8px;"><?php echo t('update_manual_rerun_note'); ?></p>
                    </div>
                </template>

                <p x-show="$store.ui.updateTriggerState === 'error'" x-cloak style="color:var(--danger); font-size:0.85em; margin-top:12px;" x-text="$store.ui.updateTriggerError"></p>

                <div style="display:flex; gap:15px; margin-top:20px;">
                    <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" @click="$store.ui.dismissUpdateNotice()"><?php echo t('btn_later'); ?></button>
                </div>
            </div>
        </template>

        <template x-if="$store.ui.updateTriggerState === 'updating'">
            <p style="color:var(--text-muted); font-size:0.95em;"><?php echo t('update_updating_message'); ?></p>
        </template>
    </div></div>
    <?php endif; ?>

    <div id="uploadModal" class="modal" x-show="$store.ui.activeModal === 'uploadModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('uploadModal')"><div class="modal-content">
        <h2 style="margin-top:0;"><?php echo t('btn_upload'); ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="text" name="title" placeholder="<?php echo htmlspecialchars(t('upload_title_placeholder')); ?>">
            <input type="text" name="artist" placeholder="<?php echo htmlspecialchars(t('upload_artist_placeholder')); ?>">
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('select_genre_label'); ?></label>
            <select name="genre">
                <?php foreach($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('audio_file_label'); ?></label>
            <input type="file" name="music" accept="audio/*" required>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('cover_file_label'); ?></label>
            <input type="file" name="cover" accept="image/*">
            <div style="display:flex; gap:15px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('uploadModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="upload" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_publish'); ?></button>
            </div>
        </form>
    </div></div>

    <div id="editTrackModal" class="modal" x-show="$store.ui.activeModal === 'editTrackModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('editTrackModal')"><div class="modal-content">
        <h2 style="margin-top:0;"><?php echo t('edit_track_title'); ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="track_id" id="edit-track-id">
            <input type="text" name="new_title" id="edit-track-title" placeholder="<?php echo htmlspecialchars(t('title_placeholder')); ?>" required>
            <input type="text" name="new_artist" id="edit-track-artist" placeholder="<?php echo htmlspecialchars(t('artist_placeholder')); ?>">
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('edit_genre_label'); ?></label>
            <select name="new_genre" id="edit-track-genre">
                <?php foreach($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('change_cover_label'); ?></label>
            <input type="file" name="new_cover" accept="image/*">
            <div style="display:flex; gap:15px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('editTrackModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="edit_track" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>
    </div></div>

    <div id="playlistModal" class="modal" x-show="$store.ui.activeModal === 'playlistModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('playlistModal')"><div class="modal-content">
        <h2 id="modal-playlist-title" style="margin-top:0;">Playlist</h2>
        <form method="post" id="playlist-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="playlist_id" id="form-playlist-id">
            <input type="text" name="playlist_name" id="form-playlist-name" placeholder="<?php echo htmlspecialchars(t('playlist_name_placeholder')); ?>" required>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('playlist_cover_label'); ?></label>
            <div style="display:flex; align-items:center; gap:15px; margin-bottom:15px;">
                <div class="playlist-cover playlist-modal-cover-preview" id="playlist-cover-preview-box">🎵<img id="playlist-cover-preview-img" style="display:none;" loading="lazy"></div>
                <input type="file" name="playlist_cover" id="form-playlist-cover" accept="image/png,image/jpeg,image/webp,image/gif" onchange="previewPlaylistCoverFile(this)" style="flex:1;">
            </div>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.9em; color:var(--text-muted); margin-bottom:15px; cursor:pointer;">
                <input type="checkbox" name="is_private" id="form-playlist-private" value="1" style="width:auto;">
                <?php echo t('playlist_private_label'); ?>
            </label>
            <input type="text" id="playlist-search" placeholder="<?php echo htmlspecialchars(t('playlist_search_placeholder')); ?>" onkeyup="filterPlaylistTracks()" style="margin-bottom:10px;">
            <div style="display:flex; justify-content:space-between; font-size:0.85em; color:var(--text-muted); margin-bottom:10px;">
                <span><?php echo t('select_tracks_label'); ?></span>
                <span id="selected-count"><?php echo t('selected_count', ['n' => 0]); ?></span>
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
                 <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('playlistModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="save_playlist" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>
    </div></div>

    <div id="player-bar">
        <div class="player-info" onclick="openSmartPlayer()" style="cursor:pointer">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="player-cover" loading="lazy">
            <div style="overflow: hidden; flex: 1;">
                <div id="play-title" style="font-weight: 700; font-size:0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo t('player_ready'); ?></div>
                <div id="play-status" style="font-size: 0.75em; color: var(--accent); margin-top:2px;"><?php echo t('player_stopped'); ?></div>
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
            <button class="control-btn" id="shuffleBtn" onclick="toggleShuffle()" title="<?php echo htmlspecialchars(t('tooltip_shuffle')); ?>">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="control-btn" onclick="prevTrack()" title="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="masterPlay" onclick="togglePlay()" title="<?php echo htmlspecialchars(t('tooltip_play')); ?>">
                <svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="control-btn" onclick="nextTrack()" title="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="control-btn" id="loopBtn" onclick="toggleLoop()" style="position:relative;" title="<?php echo htmlspecialchars(t('tooltip_loop')); ?>">
                <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                <span id="loop-ind" style="display:none;" class="loop-status">1</span>
            </button>
            <button class="control-btn" id="lyricsBarBtn" onclick="openLyricsFromPlayerBar()" title="<?php echo htmlspecialchars(t('btn_lyrics')); ?>">
                <svg viewBox="0 0 24 24"><path d="M14 17H4v2h10v-2zM20 9H4v2h16V9zM4 15h16v-2H4v2zM4 5v2h16V5H4z"/></svg>
            </button>
            <div class="vol-flyout-anchor">
                <div class="volume-container">
                    <button type="button" class="vol-icon-btn" onclick="toggleMute()" title="<?php echo htmlspecialchars(t('tooltip_mute')); ?>">
                        <svg id="vol-icon-desktop-vol" viewBox="0 0 24 24" width="20" height="20" fill="#a196b4"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </button>
                    <input type="range" id="desktop-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
                </div>
            </div>
        </div>
    </div>

    <div id="full-player">
        <div class="fp-header">
            <button class="fp-btn" onclick="closeFullPlayer()">
                <svg viewBox="0 0 24 24" style="width:30px; height:30px; fill:white;"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
            </button>
            <span style="font-size:0.8em; letter-spacing:1px; color:var(--text-muted); font-weight:600;"><?php echo t('now_playing_label'); ?></span>
            <div style="display:flex; gap:4px;">
                <button class="fp-btn" onclick="toggleQueue(); closeFullPlayer();">
                    <svg viewBox="0 0 24 24" style="width:24px; height:24px; fill:white;"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                </button>
            </div>
        </div>
        <div class="fp-art-container">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" loading="lazy" x-show="!$store.ui.showLyricsInPlayer">
            <canvas id="fp-visualizer-canvas" class="fp-visualizer-canvas" x-show="$store.ui.visualizerEnabled && !$store.ui.showLyricsInPlayer" x-cloak></canvas>
            <div class="fp-lyrics-view" x-data="lyricsScroller(() => $store.ui.showLyricsInPlayer)" @wheel="userInteracted()" @touchstart="userInteracted()" x-show="$store.ui.showLyricsInPlayer" x-cloak>
                <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
                <template x-if="$store.ui.lyricsLoading">
                    <p class="fp-lyrics-status"><?php echo t('lyrics_loading'); ?></p>
                </template>
                <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === null">
                    <p class="fp-lyrics-status"><?php echo t('lyrics_prompt'); ?></p>
                </template>
                <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === false">
                    <p class="fp-lyrics-status"><?php echo t('lyrics_not_found'); ?></p>
                </template>
                <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length > 0">
                    <div>
                        <template x-for="(line, idx) in $store.ui.lyricsSynced" :key="idx">
                            <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'" x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && isActive && $el.scrollIntoView({block:'center', behavior:'smooth'})" @click="seekToLyricLine(line.time)"></div>
                        </template>
                    </div>
                </template>
                <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length === 0 && $store.ui.lyricsPlain">
                    <div class="lyrics-plain-text" x-text="$store.ui.lyricsPlain"></div>
                </template>
            </div>
        </div>
        <div class="fp-info-area">
            <div id="fp-title"><?php echo t('title_placeholder'); ?></div>
            <div id="fp-artist" style="font-size:1.1em; color:var(--accent); font-weight:500;"><?php echo t('artist_placeholder'); ?></div>
        </div>
        <div class="fp-progress-wrapper">
            <div class="progress-bg" id="fp-progress-area" style="height:6px; background:rgba(255,255,255,0.2);">
                <div class="progress-fill" id="fp-progress-bar" style="background:white;"></div>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.85em; color:#ccc; font-family:monospace;">
                <span id="fp-curr-time">0:00</span>
                <span id="fp-total-time">0:00</span>
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
        <div class="fp-lyrics-toggle-row">
            <button type="button" class="fp-lyrics-btn" :class="{ active: $store.ui.showLyricsInPlayer }" @click="toggleLyricsInPlayer()">
                <svg viewBox="0 0 24 24"><path d="M14 17H4v2h10v-2zM20 9H4v2h16V9zM4 15h16v-2H4v2zM4 5v2h16V5H4z"/></svg>
                <span><?php echo t('btn_lyrics'); ?></span>
            </button>
        </div>
    </div>

    <!-- Lecteur "grand écran" desktop : carte centrée à deux colonnes (pochette | infos+contrôles), distinct
         du lecteur plein écran mobile (#full-player, jamais modifié) -- ce dernier serait trop vide/étiré tel
         quel à 1920px de large. Réutilise le même dégradé --fp-gradient-1/2 pour l'identité visuelle mais avec
         son propre préfixe de classes (.dfp-*) pour ne jamais hériter des styles .fp-*.
         Carrousel vertical à 3 positions ($store.ui.desktopPlayerView : 'player' | 'lyrics' | 'queue') : les
         trois cartes .dfp-card existent en permanence dans le DOM, empilées en position:absolute dans
         .dfp-stage, et se déplacent via translateY() piloté par l'état Alpine (voir .dfp-stage/.dfp-card
         dans style.css). Paroles/File d'attente ont ici leur PROPRE contenu (dupliqué depuis
         #lyrics-panel/#queue-list, même schéma que la duplication déjà faite pour mobile) -- ces panneaux
         latéraux existants restent inchangés et servent toujours pour leurs propres déclencheurs (icône file
         d'attente du header, lecteur plein écran mobile). Le bouton .dfp-close est hors des 3 cartes (enfant
         direct de .dfp-stage) pour rester cliquable quelle que soit la vue affichée. -->
    <div id="desktop-player" @click.self="closeDesktopPlayer()" @keydown.escape.window="closeDesktopPlayer()">
        <div class="dfp-stage">
            <button class="dfp-close" onclick="closeDesktopPlayer()" title="<?php echo htmlspecialchars(t('queue_close')); ?>">
                <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:currentColor;"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>

            <!-- Carte 1/3 : lecteur (vue par défaut) -->
            <div class="dfp-card dfp-card-player" :class="{ 'dfp-card-out-up': $store.ui.desktopPlayerView === 'lyrics', 'dfp-card-out-down': $store.ui.desktopPlayerView === 'queue' }">
                <div class="dfp-art-col">
                    <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="dp-cover" loading="lazy" class="dfp-cover">
                    <canvas id="dp-visualizer-canvas" class="dfp-visualizer-canvas" x-show="$store.ui.visualizerEnabled" x-cloak></canvas>
                </div>
                <div class="dfp-info-col">
                    <span class="dfp-eyebrow"><?php echo t('now_playing_label'); ?></span>
                    <div id="dp-title" class="dfp-title"><?php echo t('title_placeholder'); ?></div>
                    <div id="dp-artist" class="dfp-artist"><?php echo t('artist_placeholder'); ?></div>

                    <div class="dfp-progress-wrapper">
                        <div class="progress-bg" id="dp-progress-area" style="height:6px; background:rgba(255,255,255,0.2);">
                            <div class="progress-fill" id="dp-progress-bar" style="background:white;"></div>
                        </div>
                        <div class="dfp-time-row">
                            <span id="dp-curr-time">0:00</span>
                            <span id="dp-total-time">0:00</span>
                        </div>
                    </div>

                    <div class="dfp-controls">
                        <button class="control-btn" id="dp-shuffleBtn" onclick="toggleShuffle()" title="<?php echo htmlspecialchars(t('tooltip_shuffle')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
                        </button>
                        <button class="control-btn dfp-skip-btn" onclick="prevTrack()" title="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
                        </button>
                        <button id="dp-masterPlay" class="dfp-master-play" onclick="togglePlay()" title="<?php echo htmlspecialchars(t('tooltip_play')); ?>">
                            <svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black; margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>
                        </button>
                        <button class="control-btn dfp-skip-btn" onclick="nextTrack()" title="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
                        </button>
                        <button class="control-btn" id="dp-loopBtn" onclick="toggleLoop()" style="position:relative;" title="<?php echo htmlspecialchars(t('tooltip_loop')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                            <span id="dp-loop-ind" style="display:none; position:absolute; top:-5px; right:-5px; background:var(--primary); width:10px; height:10px; border-radius:50%;"></span>
                        </button>
                    </div>

                    <div class="dfp-secondary-actions">
                        <button type="button" class="dfp-action-btn" onclick="showDesktopPlayerLyrics()">
                            <svg viewBox="0 0 24 24"><path d="M14 17H4v2h10v-2zM20 9H4v2h16V9zM4 15h16v-2H4v2zM4 5v2h16V5H4z"/></svg>
                            <span><?php echo t('btn_lyrics'); ?></span>
                        </button>
                        <button type="button" class="dfp-action-btn" onclick="showDesktopPlayerQueue()">
                            <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                            <span><?php echo t('btn_queue'); ?></span>
                        </button>
                        <div class="vol-hover-zone">
                            <div class="volume-container">
                                <button type="button" class="vol-icon-btn" onclick="toggleMute()" title="<?php echo htmlspecialchars(t('tooltip_mute')); ?>">
                                    <svg id="vol-icon-dp-vol" viewBox="0 0 24 24" width="16" height="16" fill="#a196b4"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                                </button>
                                <input type="range" id="dp-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Carte 2/3 : paroles -- parquée hors-écran en bas tant que la vue n'est pas 'lyrics', arrive
                 par le bas en même temps que la carte lecteur sort par le haut. Contenu dupliqué depuis
                 #lyrics-panel (même x-data="lyricsScroller()" pour le défilement auto/pause manuelle). -->
            <div class="dfp-card dfp-card-lyrics" :class="{ 'dfp-card-in': $store.ui.desktopPlayerView === 'lyrics' }" x-data="lyricsScroller(() => $store.ui.desktopPlayerView === 'lyrics')" @wheel="userInteracted()" @touchstart="userInteracted()">
                <div class="dfp-subcard-header">
                    <button type="button" class="dfp-back-btn" onclick="backToDesktopPlayer()" title="<?php echo htmlspecialchars(t('now_playing_label')); ?>">
                        <svg viewBox="0 0 24 24"><path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
                    </button>
                    <h3 class="dfp-subcard-title"><?php echo t('btn_lyrics'); ?></h3>
                    <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
                </div>
                <div class="dfp-lyrics-body">
                    <template x-if="$store.ui.lyricsLoading">
                        <p class="fp-lyrics-status"><?php echo t('lyrics_loading'); ?></p>
                    </template>
                    <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === null">
                        <p class="fp-lyrics-status"><?php echo t('lyrics_prompt'); ?></p>
                    </template>
                    <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === false">
                        <p class="fp-lyrics-status"><?php echo t('lyrics_not_found'); ?></p>
                    </template>
                    <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length > 0">
                        <div>
                            <template x-for="(line, idx) in $store.ui.lyricsSynced" :key="idx">
                                <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'" x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && isActive && $el.scrollIntoView({block:'center', behavior:'smooth'})" @click="seekToLyricLine(line.time)"></div>
                            </template>
                        </div>
                    </template>
                    <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length === 0 && $store.ui.lyricsPlain">
                        <div class="lyrics-plain-text" x-text="$store.ui.lyricsPlain"></div>
                    </template>
                </div>
            </div>

            <!-- Carte 3/3 : file d'attente -- parquée hors-écran en haut tant que la vue n'est pas 'queue',
                 arrive par le haut en même temps que la carte lecteur sort par le bas. Le rendu de la liste
                 est fait par updateQueueUI() (app.js), qui cible #dp-queue-list en plus de #queue-list. -->
            <div class="dfp-card dfp-card-queue" :class="{ 'dfp-card-in': $store.ui.desktopPlayerView === 'queue' }">
                <div class="dfp-subcard-header">
                    <button type="button" class="dfp-back-btn" onclick="backToDesktopPlayer()" title="<?php echo htmlspecialchars(t('now_playing_label')); ?>">
                        <svg viewBox="0 0 24 24"><path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
                    </button>
                    <h3 class="dfp-subcard-title"><?php echo t('queue_title'); ?></h3>
                </div>
                <div class="dfp-queue-body" id="dp-queue-list">
                    <p style="color:#666; font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <audio id="mainAudio"></audio>

    <div class="modal" x-show="$store.ui.confirmState.open" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.confirmNo()" @keydown.escape.window="$store.ui.confirmNo()">
        <div class="modal-content" style="max-width:420px; text-align:center;">
            <p style="font-size:1.1em; margin:0 0 25px 0;" x-text="$store.ui.confirmState.message"></p>
            <div style="display:flex; gap:15px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; border:1px solid var(--border-color); color:#888;" @click="$store.ui.confirmNo()"><?php echo t('btn_cancel'); ?></button>
                <button type="button" class="btn btn-danger" style="flex:1; justify-content:center; padding:10px 20px; font-size:0.9em;" @click="$store.ui.confirmYes()"><?php echo t('btn_confirm'); ?></button>
            </div>
        </div>
    </div>

    <div id="toast" x-show="$store.ui.toastState.visible" x-transition.opacity.duration.200ms x-cloak x-text="$store.ui.toastState.message"
         style="position:fixed; bottom:110px; left:50%; transform:translateX(-50%); background:#1e162e; color:#fff; padding:14px 26px; border-radius:14px; border:1px solid var(--border-color); box-shadow:0 15px 40px rgba(0,0,0,0.5); z-index:6000; font-size:0.9em; font-weight:600; max-width:90%; text-align:center;">
    </div>

<?php endif; ?>

    <script>
        <?php // Passage des variables PHP au JavaScript. Émis pour les deux états (connecté / déconnecté) : Alpine.js
              // (store 'ui', T(), authForm()...) doit être disponible sur la page de connexion aussi, sinon le
              // x-data posé sur <body> y reste inerte. $all_tracks/$all_playlists sont chargés en base plus haut
              // dans tous les cas, donc aucun coût à les exposer même quand déconnecté (simplement inutilisés). ?>
        const ALL_MUSIC_DATA = <?php echo json_encode($all_tracks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const ALL_PLAYLISTS_DATA = <?php echo json_encode($all_playlists, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const CURRENT_USER_ID = <?php echo json_encode($user_id); ?>;
        const IS_ADMIN = <?php echo json_encode($is_admin); ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

        // --- I18N : langue active (cookie "purpleMusicLang", lu côté PHP) + table de traduction client ---
        const LANG = <?php echo json_encode($lang, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const I18N_CLIENT = <?php echo json_encode(i18n_client_table(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // Change la langue active : persiste dans le cookie lu par PHP, puis recharge la page
        // (les chaînes rendues côté serveur nécessitent un rechargement complet, pas de rendu partiel côté client).
        function setLanguage(code) {
            document.cookie = 'purpleMusicLang=' + code + ';path=/;max-age=' + (365 * 24 * 60 * 60) + ';samesite=lax';
            window.location.reload();
        }
    </script>
    <script defer src="app.js?v=<?php echo urlencode($assetVersion); ?>"></script>
    <script defer src="vendor/alpine.min.js"></script>
</body>
</html>
