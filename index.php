<?php
session_start();

$configFile  = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);

if (!$isInstalled) {
    include 'install.php';
    exit;
}

require_once $configFile;
require_once 'functions.php';

try {
    $db = new PDO('sqlite:' . DB_NAME);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    require_once 'auth.php';

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrf_token = $_SESSION['csrf_token'];

    require_once 'actions.php';

    // Settings
    $settingsRaw       = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name         = $settingsRaw['site_name']          ?? 'Purple Music';
    $color_bg          = $settingsRaw['color_bg']           ?? '#0f0c1d';
    $color_panel       = $settingsRaw['color_panel']        ?? '#1b1429';
    $color_primary     = $settingsRaw['color_primary']      ?? '#8e44ad';
    $color_accent      = $settingsRaw['color_accent']       ?? '#bb86fc';
    $color_text        = $settingsRaw['color_text']         ?? '#e0e0e0';
    $color_text_muted  = $settingsRaw['color_text_muted']   ?? '#a196b4';
    $color_border      = $settingsRaw['color_border']       ?? '#3d2b56';
    $color_search_bg   = $settingsRaw['color_search_bg']    ?? '#241b36';
    $color_header_bg   = $settingsRaw['color_header_bg']    ?? 'rgba(27, 20, 41, 0.92)';
    $color_player_bg   = $settingsRaw['color_player_bg']    ?? 'rgba(18, 13, 30, 0.97)';
    $color_mob_nav_bg  = $settingsRaw['color_mob_nav_bg']   ?? 'rgba(21, 16, 32, 0.97)';
    $color_fp_gradient1= $settingsRaw['color_fp_gradient_1']?? '#302b63';
    $color_fp_gradient2= $settingsRaw['color_fp_gradient_2']?? '#0f0c29';
    $default_cover     = $settingsRaw['default_cover']      ?? 'default.png';
    $favicon_file      = $settingsRaw['favicon']            ?? 'favicon.png';

    $genresList = $db->query("SELECT name FROM genres ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($genresList)) {
        $genresList = ['Phonk/Funk','Rap','Pop','Rock','Electro','Hyperpop','Nightcore','Qualité inférieure','Autre'];
    }

    // Ajax endpoints
    if (isset($_GET['increment_play'])) {
        $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?")->execute([$_GET['increment_play']]);
        exit;
    }
    if (isset($_GET['get_playlist_tracks'])) {
        $ids = explode(',', $_GET['get_playlist_tracks']);
        if (!empty($ids[0])) {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("SELECT id, filename, title, artist, cover, genre, play_count, duration FROM tracks WHERE id IN ($ph)");
            $stmt->execute($ids);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
        } else { echo json_encode([]); }
        exit;
    }

    $all_tracks    = $db->query("SELECT tracks.*, users.username as uploader_name FROM tracks JOIN users ON tracks.uploader_id = users.id ORDER BY play_count DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $all_playlists = $db->query("SELECT playlists.*, users.username FROM playlists JOIN users ON playlists.creator_id = users.id")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { die("Erreur BDD : " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($site_name); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($favicon_file); ?>?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="style.css">
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
            --player-bg: <?php echo $color_player_bg; ?>;
            --mob-nav-bg: <?php echo $color_mob_nav_bg; ?>;
            --fp-gradient-1: <?php echo $color_fp_gradient1; ?>;
            --fp-gradient-2: <?php echo $color_fp_gradient2; ?>;
        }
    </style>
</head>
<body>

<?php if (!$user_id): ?>
<!-- ══════════════════════ LOGIN ══════════════════════ -->
<div class="login-page">
    <div class="login-box">
        <div class="login-logo">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
            </div>
            <span class="login-logo-text"><?php echo htmlspecialchars($site_name); ?></span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <?php if (isset($error)) echo '<p class="login-error">' . htmlspecialchars($error) . '</p>'; ?>
            <div class="form-group">
                <label class="form-label">Identifiant</label>
                <input type="text" name="username" placeholder="Ton nom d'utilisateur" required>
            </div>
            <div class="form-group">
                <label class="form-label">Mot de passe</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" name="login" class="btn btn-primary" style="width:100%;justify-content:center;padding:13px;">
                Connexion
            </button>
            <div class="login-divider">ou</div>
            <button type="submit" name="register" class="btn btn-outline" style="width:100%;justify-content:center;padding:13px;">
                Créer un compte
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ══════════════════════ APP ══════════════════════ -->

<!-- HEADER -->
<header id="app-header">
    <a href="#" class="header-logo" onclick="showSection('accueil');return false;">
        <div class="logo-icon">
            <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
        </div>
        <div class="logo-text">
            <?php echo htmlspecialchars($site_name); ?>
            <small>Music</small>
        </div>
    </a>

    <div class="header-search-wrap">
        <span class="search-icon"><svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg></span>
        <input type="text" id="searchInput" placeholder="Titres, artistes…" onkeyup="onSearchInput()">
    </div>

    <div class="header-actions">
        <button class="btn btn-outline" onclick="toggleQueue()" title="File d'attente">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
            <span class="btn-label">File</span>
        </button>
        <button class="btn btn-primary" onclick="openCreateModal()">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            <span class="btn-label">Mix</span>
        </button>
        <button class="btn btn-outline" onclick="openModal('uploadModal')" title="Upload">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
            <span class="btn-label">Upload</span>
        </button>
        <button class="btn-icon" onclick="openModal('settingsModal')" title="Paramètres">
            <svg viewBox="0 0 24 24"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13zM12 15.5c-1.9 0-3.5-1.6-3.5-3.5s1.6-3.5 3.5-3.5 3.5 1.6 3.5 3.5-1.6 3.5-3.5 3.5z"/></svg>
        </button>
        <a href="?logout=1" class="btn btn-ghost" title="Déconnexion">
            <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:currentColor;"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        </a>
    </div>
</header>

<!-- APP BODY -->
<div id="app-body">

    <!-- SIDEBAR -->
    <nav id="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-label">Navigation</div>
            <button class="sidebar-item active" data-section="accueil" onclick="showSection('accueil')">
                <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                Bibliothèque
            </button>
            <button class="sidebar-item" data-section="playlists" onclick="showSection('playlists')">
                <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
                Mes Mixs
            </button>
        </div>

        <div class="sidebar-divider"></div>

        <div class="sidebar-section">
            <div class="sidebar-label">Lecture</div>
            <button class="sidebar-item" onclick="toggleQueue()">
                <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                File d'attente
            </button>
            <button class="sidebar-item" onclick="openLyricsPanel()" id="lyrics-sidebar-btn">
                <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
                Paroles
            </button>
            <button class="sidebar-item" onclick="openModal('uploadModal')">
                <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
                Upload
            </button>
        </div>

        <?php if ($is_admin): ?>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section">
            <div class="sidebar-label">Admin</div>
            <button class="sidebar-item admin-item" onclick="openModal('adminPanelModal')">
                <svg viewBox="0 0 24 24"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13z"/></svg>
                Configuration
            </button>
        </div>
        <?php endif; ?>

        <div style="margin-top:auto;padding:20px 16px 0;">
            <a href="?logout=1" class="sidebar-item" style="color:var(--text-muted);">
                <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                <?php echo htmlspecialchars($username); ?>
                <?php if ($is_admin): ?><small style="color:gold;font-size:9px;margin-left:4px;">ADMIN</small><?php endif; ?>
            </a>
        </div>
    </nav>

    <!-- MAIN -->
    <div id="main-content">

        <!-- BIBLIOTHÈQUE -->
        <section id="section-accueil" class="page-section active">
            <div class="section-header">
                <div>
                    <div class="section-title">Bibliothèque</div>
                    <div class="section-subtitle"><?php echo count($all_tracks); ?> piste<?php echo count($all_tracks) !== 1 ? 's' : ''; ?></div>
                </div>
            </div>

            <div class="filter-bar">
                <div class="sort-select-wrap">
                    <select id="sortSelect" onchange="filterAndSortTracks()">
                        <option value="popular">Les plus écoutés</option>
                        <option value="date_desc">Récents</option>
                        <option value="date_asc">Anciens</option>
                        <option value="alpha_asc">A → Z</option>
                        <option value="alpha_desc">Z → A</option>
                        <option value="artist">Par artiste</option>
                    </select>
                </div>
            </div>

            <div class="track-list" id="global-list"></div>
            <div id="load-more-trigger"></div>
        </section>

        <!-- PLAYLISTS -->
        <section id="section-playlists" class="page-section">
            <div class="section-header">
                <div>
                    <div class="section-title">Mes Mixs</div>
                    <div class="section-subtitle"><?php echo count($all_playlists); ?> playlist<?php echo count($all_playlists) !== 1 ? 's' : ''; ?></div>
                </div>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    Nouveau Mix
                </button>
            </div>
            <div class="playlist-grid">
                <?php foreach ($all_playlists as $p): ?>
                <div class="playlist-card" onclick="playPlaylist('<?php echo htmlspecialchars($p['song_ids']); ?>','<?php echo $p['id']; ?>')">
                    <div class="playlist-card-cover">
                        <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
                    </div>
                    <div class="playlist-card-name"><?php echo htmlspecialchars($p['name']); ?></div>
                    <div class="playlist-card-meta">Par <?php echo htmlspecialchars($p['username']); ?></div>
                    <?php if ($p['creator_id'] == $user_id || $is_admin): ?>
                    <div class="playlist-card-actions" onclick="event.stopPropagation()">
                        <button class="btn btn-outline" style="font-size:0.78rem;padding:6px 12px;" onclick='openEditModal(<?php echo json_encode($p, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>Éditer</button>
                        <a href="?delete_playlist=<?php echo $p['id']; ?>&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-danger" style="padding:6px 12px;" onclick="return confirm('Supprimer ce mix ?')">Suppr.</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($all_playlists)): ?>
                <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text-muted);">
                    <div style="font-size:0.9rem;">Aucun mix pour l'instant.</div>
                    <button class="btn btn-primary" style="margin-top:16px;" onclick="openCreateModal()">Créer mon premier mix</button>
                </div>
                <?php endif; ?>
            </div>
        </section>

    </div><!-- /#main-content -->
</div><!-- /#app-body -->

<!-- ══════════════════════ QUEUE PANEL ══════════════════════ -->
<div id="queue-panel">
    <div class="queue-header">
        <h3>File d'attente</h3>
        <button class="btn-icon" onclick="toggleQueue()">
            <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
    </div>
    <div class="queue-scroll" id="queue-list"></div>
</div>

<!-- ══════════════════════ LYRICS PANEL ══════════════════════ -->
<div id="lyrics-panel">
    <div class="lyrics-topbar">
        <button class="btn-icon" onclick="closeLyricsPanel()">
            <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
        <h2>Paroles</h2>
        <div class="lyrics-track-mini">
            <img id="lyrics-mini-img" src="covers/<?php echo htmlspecialchars($default_cover); ?>" onerror="this.src='covers/default.png'">
            <div class="lyrics-track-mini-info">
                <div class="lyrics-track-mini-title" id="lyrics-mini-title">—</div>
                <div class="lyrics-track-mini-artist" id="lyrics-mini-artist"></div>
            </div>
        </div>
        <button class="btn-icon" onclick="document.getElementById('lrc-file-input-header')?.click()" title="Charger un fichier .lrc" style="margin-left:8px;">
            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        </button>
        <input type="file" id="lrc-file-input-header" accept=".lrc,.txt" style="display:none" onchange="handleLrcFileInput(this)">
    </div>
    <div class="lyrics-body">
        <div class="lyrics-left">
            <img id="lyrics-album-art" class="lyrics-album-art" src="covers/<?php echo htmlspecialchars($default_cover); ?>" onerror="this.src='covers/default.png'">
        </div>
        <div class="lyrics-scroll" id="lyrics-scroll">
            <div class="lyrics-empty">
                <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
                <p>Lance une piste pour afficher les paroles.</p>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════ PLAYER BAR ══════════════════════ -->
<div id="player-bar">
    <!-- Left -->
    <div class="pb-left">
        <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="player-cover" loading="lazy" onerror="this.src='covers/default.png'" onclick="openSmartPlayer()" title="Ouvrir le lecteur">
        <div class="pb-track-info" onclick="openSmartPlayer()" style="cursor:pointer">
            <div id="play-title">Prêt à écouter</div>
            <div id="play-status">—</div>
        </div>
        <button class="btn-icon pb-lyrics-btn" onclick="openLyricsPanel()" title="Paroles">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
        </button>
    </div>

    <!-- Center -->
    <div class="pb-center">
        <div class="pb-controls">
            <button class="control-btn shuffle-btn" onclick="toggleShuffle()" title="Aléatoire">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="control-btn" onclick="prevTrack()" title="Précédent">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="masterPlay" onclick="togglePlay()" title="Lecture / Pause">
                <svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="control-btn" onclick="nextTrack()" title="Suivant">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="control-btn loop-btn" onclick="toggleLoop()" title="Répétition">
                <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                <span class="loop-status" id="loop-ind">1</span>
            </button>
        </div>
        <div class="pb-progress-row">
            <span class="time-label" id="curr-time">0:00</span>
            <div class="progress-bg" id="progress-area">
                <div class="progress-fill" id="progress-bar"></div>
            </div>
            <span class="time-label" id="total-time">0:00</span>
        </div>
    </div>

    <!-- Right -->
    <div class="pb-right">
        <svg class="volume-icon" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
        <input type="range" id="desktop-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
    </div>
</div>

<!-- ══════════════════════ FULL PLAYER (mobile) ══════════════════════ -->
<div id="full-player">
    <div class="fp-topbar">
        <button class="fp-btn" onclick="closeFullPlayer()">
            <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
        </button>
        <span class="fp-topbar-title">Lecture en cours</span>
        <button class="fp-btn" onclick="closeLyricsPanel(); openLyricsPanel();">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
        </button>
    </div>
    <div class="fp-art-wrap">
        <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" loading="lazy" onerror="this.src='covers/default.png'">
    </div>
    <div class="fp-info">
        <div class="fp-info-text">
            <div id="fp-title"><span>Titre</span></div>
            <div id="fp-artist">Artiste</div>
        </div>
    </div>
    <div class="fp-progress-area">
        <div class="fp-progress-bg" id="fp-progress-area">
            <div class="fp-progress-fill" id="fp-progress-bar"></div>
        </div>
        <div class="fp-time-row">
            <span id="fp-curr-time">0:00</span>
            <span id="fp-total-time">0:00</span>
        </div>
    </div>
    <div class="fp-volume">
        <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>
        <input type="range" id="mobile-vol" min="0" max="1" step="0.01" value="1">
        <svg viewBox="0 0 24 24"><path d="M18.5 12c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>
    </div>
    <div class="fp-controls">
        <button class="control-btn shuffle-btn" onclick="toggleShuffle()">
            <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
        </button>
        <button class="control-btn" onclick="prevTrack()" style="transform:scale(1.3)">
            <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
        </button>
        <button id="fp-masterPlay" onclick="togglePlay()">
            <svg viewBox="0 0 24 24" style="width:28px;height:28px;fill:black;margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>
        </button>
        <button class="control-btn" onclick="nextTrack()" style="transform:scale(1.3)">
            <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
        </button>
        <button class="control-btn loop-btn" onclick="toggleLoop()" style="position:relative;">
            <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
            <span class="loop-status" id="fp-loop-ind">1</span>
        </button>
    </div>
</div>

<!-- ══════════════════════ MOBILE BOTTOM NAV ══════════════════════ -->
<nav id="mobile-bottom-nav">
    <button class="mob-nav-item active" data-section="accueil" onclick="showSection('accueil')">
        <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        Biblio
    </button>
    <button class="mob-nav-item" data-section="playlists" onclick="showSection('playlists')">
        <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
        Mixs
    </button>
    <button class="mob-nav-item" onclick="openLyricsPanel()">
        <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
        Paroles
    </button>
    <?php if ($is_admin): ?>
    <button class="mob-nav-item admin-mob" onclick="openModal('adminPanelModal')">
        <svg viewBox="0 0 24 24"><path d="M19.4 13c0-.3.1-.6.1-1s0-.7-.1-1l2.1-1.7c.2-.2.2-.4.1-.6l-2-3.5c-.1-.2-.3-.3-.6-.2l-2.5 1c-.5-.4-1.1-.7-1.7-1l-.4-2.7c0-.2-.2-.4-.5-.4h-4c-.3 0-.5.2-.5.4l-.4 2.7c-.6.2-1.2.6-1.7 1l-2.5-1c-.2-.1-.5 0-.6.2l-2 3.5c-.1.2-.1.5.1.6L4.6 11c-.1.3-.1.6-.1 1s0 .7.1 1l-2.1 1.7c-.2.2-.2.4-.1.6l2 3.5c.1.2.3.3.6.2l2.5-1c.5.4 1.1.7 1.7 1l.4 2.7c0 .2.2.4.5.4h4c.3 0 .5-.2.5-.4l.4-2.7c.6-.2 1.2-.6 1.7-1l2.5 1c.2.1.5 0 .6-.2l2-3.5c.1-.2.1-.5-.1-.6L19.4 13z"/></svg>
        Admin
    </button>
    <?php endif; ?>
    <button class="mob-nav-item" onclick="openModal('uploadModal')">
        <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
        Upload
    </button>
</nav>

<!-- Mobile mini player -->
<div id="mobile-mini-player" onclick="openFullPlayer()" style="cursor:pointer;">
    <img id="mmp-cover" src="covers/<?php echo htmlspecialchars($default_cover); ?>" onerror="this.src='covers/default.png'">
    <div class="mmp-info">
        <div class="mmp-title" id="mmp-title">Prêt à écouter</div>
        <div class="mmp-artist" id="mmp-artist"></div>
    </div>
    <button class="mmp-btn" id="mmp-play-btn" onclick="event.stopPropagation();togglePlay();">
        <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
    </button>
    <div class="mmp-progress" id="mmp-progress"></div>
</div>

<!-- ══════════════════════ MODALS ══════════════════════ -->

<!-- Upload -->
<div id="uploadModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title">Upload une piste</span>
            <button class="modal-close" onclick="closeModal('uploadModal')">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Titre</label>
                    <input type="text" name="title" placeholder="Auto-détecté si vide">
                </div>
                <div class="form-group">
                    <label class="form-label">Artiste</label>
                    <input type="text" name="artist" placeholder="Auto-détecté si vide">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Genre</label>
                <select name="genre">
                    <?php foreach ($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Fichier audio (MP3 / WAV / FLAC / OGG)</label>
                <input type="file" name="music" accept="audio/*" required>
            </div>
            <div class="form-group">
                <label class="form-label">Cover (optionnel — auto-détectée depuis les tags ID3)</label>
                <input type="file" name="cover" accept="image/*">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('uploadModal')">Annuler</button>
                <button type="submit" name="upload" class="btn btn-primary">Publier</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Track -->
<div id="editTrackModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title">Modifier la piste</span>
            <button class="modal-close" onclick="closeModal('editTrackModal')">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="track_id" id="edit-track-id">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Titre</label>
                    <input type="text" name="new_title" id="edit-track-title" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Artiste</label>
                    <input type="text" name="new_artist" id="edit-track-artist">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Genre</label>
                <select name="new_genre" id="edit-track-genre">
                    <?php foreach ($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Nouvelle cover (optionnel)</label>
                <input type="file" name="new_cover" accept="image/*">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editTrackModal')">Annuler</button>
                <button type="submit" name="edit_track" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Playlist -->
<div id="playlistModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title" id="modal-playlist-title">Playlist</span>
            <button class="modal-close" onclick="closeModal('playlistModal')">✕</button>
        </div>
        <form method="post" id="playlist-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="playlist_id" id="form-playlist-id">
            <div class="form-group">
                <label class="form-label">Nom du mix</label>
                <input type="text" name="playlist_name" id="form-playlist-name" placeholder="Mon mix" required>
            </div>
            <div class="form-group">
                <label class="form-label">Rechercher</label>
                <input type="text" id="playlist-search" placeholder="Filtrer les titres…" onkeyup="filterPlaylistTracks()">
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <span class="form-label" style="margin:0;">Sélectionner les titres</span>
                <span class="selected-count-badge" id="selected-count">0 sélectionné(s)</span>
            </div>
            <div class="song-select-container">
                <?php foreach ($all_tracks as $t): ?>
                <div class="song-select-item" onclick="toggleSelection(this)" data-title="<?php echo strtolower(htmlspecialchars($t['title'])); ?>">
                    <input type="checkbox" name="selected_songs[]" value="<?php echo $t['id']; ?>" class="song-cb" data-id="<?php echo $t['id']; ?>">
                    <div class="check-indicator"></div>
                    <img src="covers/<?php echo htmlspecialchars($t['cover']); ?>" loading="lazy" style="width:36px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;" onerror="this.src='covers/default.png'">
                    <div style="overflow:hidden;flex:1;">
                        <div style="font-size:0.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($t['title']); ?></div>
                        <div style="font-size:0.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($t['artist']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('playlistModal')">Annuler</button>
                <button type="submit" name="save_playlist" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Settings (genre filter) -->
<div id="settingsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title">Filtres de genres</span>
            <button class="modal-close" onclick="closeModal('settingsModal')">✕</button>
        </div>
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:16px;">Cochez les genres à <strong style="color:var(--danger);">masquer</strong> de la bibliothèque :</p>
        <div class="settings-grid">
            <?php foreach ($genresList as $g): ?>
            <label>
                <input type="checkbox" class="genre-filter-cb" data-genre="<?php echo htmlspecialchars($g); ?>" onchange="toggleGenreSetting('<?php echo htmlspecialchars($g); ?>', this.checked)">
                <?php echo htmlspecialchars($g); ?>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary" onclick="closeModal('settingsModal')">Fermer</button>
        </div>
    </div>
</div>

<?php if ($is_admin): ?>
<!-- Admin Panel -->
<div id="adminPanelModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <div class="modal-header">
            <span class="modal-title" style="color:#e67e22;">Configuration système</span>
            <button class="modal-close" onclick="closeModal('adminPanelModal')">✕</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="adm-accordion-item open">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Général</div>
                <div class="adm-accordion-content" style="display:block;">
                    <div class="form-group">
                        <label class="form-label">Nom de l'application</label>
                        <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
                    </div>
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Thème & Couleurs</div>
                <div class="adm-accordion-content">
                    <div class="extended-color-grid">
                        <div class="extended-color-item"><span>Arrière-plan</span><input type="color" name="adm_color_bg" value="<?php echo $color_bg; ?>"></div>
                        <div class="extended-color-item"><span>Panneaux</span><input type="color" name="adm_color_panel" value="<?php echo $color_panel; ?>"></div>
                        <div class="extended-color-item"><span>Primaire</span><input type="color" name="adm_color_primary" value="<?php echo $color_primary; ?>"></div>
                        <div class="extended-color-item"><span>Accent</span><input type="color" name="adm_color_accent" value="<?php echo $color_accent; ?>"></div>
                        <div class="extended-color-item"><span>Texte</span><input type="color" name="adm_color_text" value="<?php echo $color_text; ?>"></div>
                        <div class="extended-color-item"><span>Texte discret</span><input type="color" name="adm_color_text_muted" value="<?php echo $color_text_muted; ?>"></div>
                        <div class="extended-color-item"><span>Bordures</span><input type="color" name="adm_color_border" value="<?php echo $color_border; ?>"></div>
                        <div class="extended-color-item"><span>Fond recherche</span><input type="color" name="adm_color_search_bg" value="<?php echo $color_search_bg; ?>"></div>
                        <div class="extended-color-item"><span>Gradient player 1</span><input type="color" name="adm_color_fp_gradient_1" value="<?php echo $color_fp_gradient1; ?>"></div>
                        <div class="extended-color-item"><span>Gradient player 2</span><input type="color" name="adm_color_fp_gradient_2" value="<?php echo $color_fp_gradient2; ?>"></div>
                    </div>
                    <div class="form-group" style="margin-top:10px;">
                        <label class="form-label">Header (rgba accepté)</label>
                        <input type="text" name="adm_color_header_bg" value="<?php echo htmlspecialchars($color_header_bg); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Player bar (rgba accepté)</label>
                        <input type="text" name="adm_color_player_bg" value="<?php echo htmlspecialchars($color_player_bg); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nav mobile (rgba accepté)</label>
                        <input type="text" name="adm_color_mob_nav_bg" value="<?php echo htmlspecialchars($color_mob_nav_bg); ?>">
                    </div>
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Assets médias</div>
                <div class="adm-accordion-content">
                    <div class="form-group">
                        <label class="form-label">Favicon (.png / .ico)</label>
                        <input type="file" name="adm_favicon" accept="image/png,image/x-icon">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cover par défaut (.png)</label>
                        <input type="file" name="adm_default_cover" accept="image/png">
                    </div>
                </div>
            </div>

            <div class="adm-accordion-item">
                <div class="adm-accordion-header" onclick="toggleAccordion(this)">Gestionnaire de genres</div>
                <div class="adm-accordion-content">
                    <div class="form-group">
                        <label class="form-label">Ajouter un genre</label>
                        <input type="text" name="adm_new_genre" placeholder="ex: Ambient, Jazz…">
                    </div>
                    <div class="form-label" style="margin-bottom:8px;">Genres actifs</div>
                    <div class="genre-tag-list">
                        <?php foreach ($genresList as $g): ?>
                        <span class="genre-tag">
                            <?php echo htmlspecialchars($g); ?>
                            <a href="?delete_genre=<?php echo urlencode($g); ?>" onclick="return confirm('Supprimer ce genre ?')">✕</a>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('adminPanelModal')">Annuler</button>
                <button type="submit" name="save_admin_settings" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<audio id="mainAudio"></audio>

<script>
const ALL_MUSIC_DATA = <?php echo json_encode($all_tracks, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;
const CURRENT_USER_ID = <?php echo json_encode($user_id); ?>;
const IS_ADMIN = <?php echo json_encode($is_admin); ?>;
const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

// LRC header file input handler (outside app.js scope due to DOM order)
function handleLrcFileInput(input) {
    const file = input.files[0];
    if (!file) return;
    const r = new FileReader();
    r.onload = e => {
        const t = window.queue && window.queue[window.currentIndex];
        if (!t) return;
        const lines = parseLRC(e.target.result);
        window.lrcByTrackId = window.lrcByTrackId || {};
        window.lrcByTrackId[t.id] = lines;
        window.lrcLines = lines;
        renderLyrics();
    };
    r.readAsText(file, 'utf-8');
    input.value = '';
}
</script>
<script src="app.js"></script>

<?php endif; ?>
</body>
</html>
