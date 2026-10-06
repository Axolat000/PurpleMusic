<?php
/**
 * Navigation latérale (desktop uniquement — masquée sous 900px, où la barre
 * d'onglets du bas prend le relais, voir #mobile-bottom-nav).
 *
 * Remplace l'ancien bandeau horizontal du haut, qui n'exposait que 3 entrées
 * (Bibliothèque / Playlists / Admin) et noyait toutes les actions secondaires
 * dans une rangée d'icônes sans libellé à droite. Ici : la navigation de contenu
 * est séparée des actions (importer / créer / réglages), et chaque entrée porte
 * un libellé lisible.
 *
 * État réduit : persisté dans localStorage (clé purpleMusicSidebarCollapsed),
 * appliqué AVANT Alpine par js/theme.js pour éviter que la barre s'affiche
 * déployée puis se replie brutalement au premier rendu.
 */
?>
<aside id="app-sidebar" :class="{ 'collapsed': $store.ui.sidebarCollapsed }">
    <button type="button" class="sidebar-edge-toggle"
            @click="$store.ui.toggleSidebar()"
            :aria-label="$store.ui.sidebarCollapsed ? T('sidebar_expand') : T('sidebar_collapse')"
            :title="$store.ui.sidebarCollapsed ? T('sidebar_expand') : T('sidebar_collapse')">
        <svg class="ico ico-sm sidebar-edge-toggle-ico" aria-hidden="true"><use href="#ico-chevron-left"></use></svg>
    </button>

    <div class="sidebar-head">
        <div class="sidebar-logo" title="<?php echo htmlspecialchars($site_name); ?>">
            <span class="sidebar-logo-mark" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($site_name, 0, 1)); ?></span>
            <span class="sidebar-logo-text"><?php echo htmlspecialchars($site_name); ?></span>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="<?php echo htmlspecialchars(t('sidebar_browse')); ?>">
        <p class="sidebar-group-label"><?php echo t('sidebar_browse'); ?></p>

        <button type="button" class="sidebar-link" :class="{ active: $store.ui.section === 'accueil' }" @click="showSection('accueil')">
            <svg class="ico" aria-hidden="true"><use href="#ico-library"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_library'); ?></span>
        </button>

        <button type="button" class="sidebar-link" :class="{ active: $store.ui.section === 'artists-page' }" @click="showArtistsIndex()">
            <svg class="ico" aria-hidden="true"><use href="#ico-artist"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_artists'); ?></span>
        </button>

        <button type="button" class="sidebar-link" :class="{ active: $store.ui.section === 'albums-page' }" @click="showAlbumsIndex()">
            <svg class="ico" aria-hidden="true"><use href="#ico-album"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_albums'); ?></span>
        </button>

        <p class="sidebar-group-label"><?php echo t('sidebar_your_library'); ?></p>

        <button type="button" class="sidebar-link" :class="{ active: $store.ui.section === 'playlists' || $store.ui.section === 'playlist-detail' }" @click="showSection('playlists')">
            <svg class="ico" aria-hidden="true"><use href="#ico-playlist"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_playlists'); ?></span>
        </button>

        <button type="button" class="sidebar-link" :class="{ active: $store.ui.section === 'history-page' }" @click="showHistoryPage()">
            <svg class="ico" aria-hidden="true"><use href="#ico-history"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_history'); ?></span>
        </button>

        <button type="button" class="sidebar-link" :class="{ active: $store.ui.section === 'stats-page' }" @click="showStatsPage()">
            <svg class="ico" aria-hidden="true"><use href="#ico-stats"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_stats'); ?></span>
        </button>

        <?php if ($is_admin): ?>
        <p class="sidebar-group-label"><?php echo t('admin_badge'); ?></p>
        <button type="button" class="sidebar-link sidebar-link-admin" :class="{ active: $store.ui.section === 'admin' }" @click="showSection('admin')">
            <svg class="ico" aria-hidden="true"><use href="#ico-admin"></use></svg>
            <span class="sidebar-link-label"><?php echo t('nav_admin_panel'); ?></span>
        </button>
        <?php endif; ?>
    </nav>

    <!-- Actions : séparées de la navigation de contenu ci-dessus (elles n'amènent
         pas sur une page, elles ouvrent une modale ou terminent la session). -->
    <div class="sidebar-actions">
        <button type="button" class="sidebar-link sidebar-action" @click="openCreateModal()">
            <svg class="ico" aria-hidden="true"><use href="#ico-playlist-add"></use></svg>
            <span class="sidebar-link-label"><?php echo t('btn_create_playlist'); ?></span>
        </button>
        <button type="button" class="sidebar-link sidebar-action" @click="openModal('uploadModal')">
            <svg class="ico" aria-hidden="true"><use href="#ico-upload"></use></svg>
            <span class="sidebar-link-label"><?php echo t('btn_upload'); ?></span>
        </button>
        <button type="button" class="sidebar-link sidebar-action" @click="openModal('settingsModal')">
            <svg class="ico" aria-hidden="true"><use href="#ico-admin"></use></svg>
            <span class="sidebar-link-label"><?php echo t('btn_settings'); ?></span>
        </button>
        <a href="?logout=1" class="sidebar-link sidebar-action">
            <svg class="ico" aria-hidden="true"><use href="#ico-logout"></use></svg>
            <span class="sidebar-link-label"><?php echo t('btn_logout'); ?></span>
        </a>
    </div>
</aside>
