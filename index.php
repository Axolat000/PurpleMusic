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

    $all_tracks = $db->query("SELECT tracks.*, users.username as uploader_name FROM tracks JOIN users ON tracks.uploader_id = users.id ORDER BY play_count DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $all_playlists = $db->query("SELECT playlists.*, users.username FROM playlists JOIN users ON playlists.creator_id = users.id")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die(t('err_db_prefix') . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_file); ?>?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style.css">
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
    <div style="max-width:350px; width: 90%; margin:100px auto; text-align:center;">
        <div class="logo" style="font-size:3em; margin-bottom:30px;"><?php echo htmlspecialchars($site_name); ?></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <?php if(isset($error)) echo "<p style='color:var(--danger);'>$error</p>"; ?>
            <input type="text" name="username" placeholder="<?php echo htmlspecialchars(t('login_username_placeholder')); ?>" required style="padding:15px; border-radius:12px;">
            <input type="password" name="password" placeholder="<?php echo htmlspecialchars(t('login_password_placeholder')); ?>" required style="padding:15px; border-radius:12px;">
            <button type="submit" name="login" class="btn btn-primary" style="width:100%; justify-content:center; margin-top:10px; padding:15px;"><?php echo t('login_btn'); ?></button>
            <button type="submit" name="register" class="btn btn-outline" style="width:100%; justify-content:center; margin-top:15px; padding:15px;"><?php echo t('login_register_btn'); ?></button>
        </form>
    </div>
<?php else: ?>

    <header>
        <div class="logo"><?php echo htmlspecialchars($site_name); ?> <?php if($is_admin) echo "<small style='color:gold; font-size:10px; vertical-align:middle;'>" . t('admin_badge') . "</small>"; ?></div>
        <nav>
            <span id="nav-accueil" class="active" onclick="showSection('accueil')"><?php echo t('nav_library'); ?></span>
            <span id="nav-playlists" onclick="showSection('playlists')"><?php echo t('nav_playlists'); ?></span>
            <?php if($is_admin): ?>
                <span class="admin-nav-btn" style="cursor:pointer;" onclick="openModal('adminPanelModal')"><?php echo t('nav_admin_panel'); ?></span>
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

    <?php if($is_admin): ?>
    <div id="adminPanelModal" class="modal" x-show="$store.ui.activeModal === 'adminPanelModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('adminPanelModal')"><div class="modal-content">
        <h2 style="margin-top:0; color:#e67e22;"><?php echo t('admin_panel_title'); ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="adm-accordion-item open">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo t('admin_section_general'); ?></div>
                <div class="adm-accordion-content" style="display: block;">
                    <label><?php echo t('admin_app_name_label'); ?></label>
                    <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo t('admin_section_theme'); ?></div>
                <div class="adm-accordion-content">
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
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo t('admin_section_media'); ?></div>
                <div class="adm-accordion-content">
                    <label><?php echo t('admin_favicon_label'); ?></label>
                    <input type="file" name="adm_favicon" accept="image/png, image/x-icon">
                    <label><?php echo t('admin_default_cover_label'); ?></label>
                    <input type="file" name="adm_default_cover" accept="image/png">
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)"><?php echo t('admin_section_genres'); ?></div>
                <div class="adm-accordion-content">
                    <label><?php echo t('admin_new_genre_label'); ?></label>
                    <input type="text" name="adm_new_genre" placeholder="<?php echo htmlspecialchars(t('admin_new_genre_placeholder')); ?>">

                    <label style="font-weight:bold; display:block; margin-bottom:5px;"><?php echo t('admin_active_genres_label'); ?></label>
                    <div style="max-height:160px; overflow-y:auto; border:1px solid var(--border-color); padding:10px; border-radius:10px;">
                        <?php foreach($genresList as $g): ?>
                            <div class="adm-genre-item">
                                <span><?php echo htmlspecialchars($g); ?></span>
                                <a href="?delete_genre=<?php echo urlencode($g); ?>&csrf_token=<?php echo $csrf_token; ?>" style="color:var(--danger); text-decoration:none; font-weight:bold;" onclick="return confirmDelete('<?php echo t('confirm_delete_genre'); ?>', '?delete_genre=<?php echo urlencode($g); ?>&csrf_token=<?php echo $csrf_token; ?>')">✕</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:15px; margin-top: 25px;">
                <button type="button" class="btn" style="flex:1; border:1px solid rgba(255,255,255,0.1);" onclick="closeModal('adminPanelModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="save_admin_settings" class="btn btn-primary" style="flex:1;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>
    </div></div>
    <?php endif; ?>

    <div id="queue-panel">
        <button class="close-queue-mobile" onclick="toggleQueue()"><?php echo t('queue_close'); ?></button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;"><?php echo t('queue_title'); ?></h3>
        <div id="queue-list" style="margin-top:15px;">
            <p style="color:#666; font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
        </div>
    </div>

    <!-- Paroles (desktop) : panneau latéral droit, même mécanisme que la file d'attente. -->
    <div id="lyrics-panel" x-data="lyricsScroller()" @wheel="userInteracted()" @touchstart="userInteracted()" :class="{ open: $store.ui.lyricsPanelOpen }">
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
                        <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'" x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && $el.scrollIntoView({block:'center', behavior:'smooth'})"></div>
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
                    <h3 class="home-row-title"><?php echo t('sort_recent'); ?></h3>
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
            <template x-if="$store.ui.popularTracks.length > 0">
                <div class="home-row">
                    <h3 class="home-row-title"><?php echo t('sort_popular'); ?></h3>
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

    <main id="playlists" x-show="$store.ui.section === 'playlists'" x-cloak>
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo t('home_your_mixes'); ?></h2>
        <div class="playlist-grid">
            <?php foreach($all_playlists as $p): ?>
                <div class="playlist-card" style="cursor:pointer;" onclick="openPlaylistDetail(<?php echo $p['id']; ?>)">
                    <div class="playlist-cover">🎵<?php if (!empty($p['cover'])): ?><img src="covers/<?php echo htmlspecialchars($p['cover']); ?>" loading="lazy" onerror="this.remove()"><?php endif; ?></div>
                    <h3 style="margin-top:0; font-size:1.3em;"><?php echo htmlspecialchars($p['name']); ?></h3>
                    <p style="font-size:0.85em; color:var(--text-muted); margin-bottom:20px;"><?php echo t('created_by'); ?> <strong><?php echo htmlspecialchars($p['username']); ?></strong></p>
                    <button class="btn btn-primary" style="width:100%; justify-content:center; margin-bottom:15px;" onclick="event.stopPropagation(); openPlaylistDetail(<?php echo $p['id']; ?>)"><?php echo t('btn_view_mix'); ?></button>
                    <?php if($p['creator_id'] == $user_id || $is_admin): ?>
                        <div style="display:flex; gap:10px;">
                            <button class="btn btn-outline" style="flex:1; justify-content:center; font-size:0.8em;" onclick='event.stopPropagation(); openEditModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'><?php echo t('btn_edit'); ?></button>
                            <a href="?delete_playlist=<?php echo $p['id']; ?>&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-danger" style="flex:1; justify-content:center; font-size:0.8em; border-radius:99px;" onclick="event.stopPropagation(); return confirmDelete('<?php echo t('confirm_delete_generic'); ?>', '?delete_playlist=<?php echo $p['id']; ?>&csrf_token=<?php echo $csrf_token; ?>')"><?php echo t('btn_delete_short'); ?></a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
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

    <div id="mobile-bottom-nav">
        <button class="mob-nav-item active" id="mob-nav-accueil" onclick="showSection('accueil')">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg><?php echo t('mob_nav_library'); ?>
        </button>
        <button class="mob-nav-item" id="mob-nav-playlists" onclick="showSection('playlists')">
            <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg><?php echo t('mob_nav_mixes'); ?>
        </button>
        <?php if($is_admin): ?>
            <button class="mob-nav-item" onclick="openModal('adminPanelModal')" style="color:#e67e22;">
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

    <div id="settingsModal" class="modal" x-show="$store.ui.activeModal === 'settingsModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('settingsModal')"><div class="modal-content">
        <h2 style="margin-top:0;"><?php echo t('settings_title'); ?></h2>

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
        <div class="theme-swatch-row">
            <div class="theme-swatch-item">
                <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'violet' }" style="--sw-primary:#8E44AD; --sw-accent:#BB86FC;" title="<?php echo htmlspecialchars(t('theme_violet_default')); ?>" @click="applyThemePreset('violet')"></button>
                <span class="theme-swatch-label">Violet</span>
            </div>
            <div class="theme-swatch-item">
                <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'amoled' }" style="--sw-primary:#7B2CBF; --sw-accent:#B388FF;" title="Amoled" @click="applyThemePreset('amoled')"></button>
                <span class="theme-swatch-label">Amoled</span>
            </div>
            <div class="theme-swatch-item">
                <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'midnight' }" style="--sw-primary:#3B5BDB; --sw-accent:#7C9BFF;" title="Midnight" @click="applyThemePreset('midnight')"></button>
                <span class="theme-swatch-label">Midnight</span>
            </div>
            <div class="theme-swatch-item">
                <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'forest' }" style="--sw-primary:#2E7D4F; --sw-accent:#6FCF97;" title="Forest" @click="applyThemePreset('forest')"></button>
                <span class="theme-swatch-label">Forest</span>
            </div>
            <div class="theme-swatch-item">
                <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'crimson' }" style="--sw-primary:#B33A3A; --sw-accent:#FF6B6B;" title="Crimson" @click="applyThemePreset('crimson')"></button>
                <span class="theme-swatch-label">Crimson</span>
            </div>
        </div>

        <p class="settings-section-label"><?php echo t('settings_volume_label'); ?></p>
        <div class="volume-container settings-vol-row">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
            <input type="range" id="settings-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
        </div>

        <p style="color:var(--text-muted); font-size:0.9em; margin-bottom: 20px;"><?php echo t('settings_hide_intro_pre'); ?> <strong style="color:var(--danger);"><?php echo t('settings_hide_word'); ?></strong> :</p>
        <div class="settings-grid">
            <?php foreach($genresList as $g): ?>
                <label><input type="checkbox" class="genre-filter-cb" data-genre="<?php echo htmlspecialchars($g); ?>" onchange="toggleGenreSetting('<?php echo htmlspecialchars($g); ?>', this.checked)"> <?php echo htmlspecialchars($g); ?></label>
            <?php endforeach; ?>
        </div>
        <div style="display:flex; gap:15px; margin-top:30px;">
            <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" onclick="closeModal('settingsModal')"><?php echo t('btn_close'); ?></button>
        </div>
    </div></div>

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
            <span style="font-size:0.8em; letter-spacing:1px; color:var(--text-muted); font-weight:600;"><?php echo t('now_playing_label'); ?></span>
            <div style="display:flex; gap:4px;">
                <button class="fp-btn" onclick="toggleQueue(); closeFullPlayer();">
                    <svg viewBox="0 0 24 24" style="width:24px; height:24px; fill:white;"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                </button>
            </div>
        </div>
        <div class="fp-art-container">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" loading="lazy" x-show="!$store.ui.showLyricsInPlayer">
            <div class="fp-lyrics-view" x-data="lyricsScroller()" @wheel="userInteracted()" @touchstart="userInteracted()" x-show="$store.ui.showLyricsInPlayer" x-cloak>
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
                            <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'" x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && $el.scrollIntoView({block:'center', behavior:'smooth'})"></div>
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

    <script>
        // Passage des variables PHP au JavaScript
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
    <script defer src="app.js"></script>
    <script defer src="vendor/alpine.min.js"></script>
<?php endif; ?>
</body>
</html>
