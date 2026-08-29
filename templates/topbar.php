<?php
/**
 * Barre supérieure collante — recherche globale + actions de contexte.
 *
 * La recherche vivait auparavant DANS l'écran d'accueil (templates/home.php) :
 * elle disparaissait dès qu'on ouvrait Playlists ou une page artiste, et il
 * fallait revenir en arrière pour chercher quoi que ce soit. Ici elle est
 * permanente ; taper depuis n'importe quel écran bascule automatiquement sur les
 * résultats (voir onSearchInput() dans js/player-controls.js).
 *
 * Le tri ne concerne que la bibliothèque : masqué ailleurs plutôt que laissé
 * visible et sans effet.
 */
?>
<div id="app-topbar">
    <div class="topbar-search" x-data="{ focused: false }">
        <svg class="ico topbar-search-ico" aria-hidden="true"><use href="#ico-search"></use></svg>
        <input type="search"
               id="searchInput"
               class="topbar-search-input"
               placeholder="<?php echo htmlspecialchars(t('search_global_placeholder')); ?>"
               aria-label="<?php echo htmlspecialchars(t('search_global_placeholder')); ?>"
               autocomplete="off"
               x-model="$store.ui.searchTerm"
               @input="onSearchInput()"
               @focus="focused = true"
               @blur="setTimeout(() => focused = false, 150)"
               @keydown.escape="clearSearch()">
        <!-- Bouton d'effacement : présent seulement quand il y a quelque chose à
             effacer, sinon il occupe visuellement une place pour rien. -->
        <button type="button" class="topbar-search-clear"
                x-show="$store.ui.searchTerm !== ''" x-cloak
                @click="clearSearch()"
                aria-label="<?php echo htmlspecialchars(t('search_clear')); ?>"
                title="<?php echo htmlspecialchars(t('search_clear')); ?>">
            <svg class="ico" aria-hidden="true"><use href="#ico-close"></use></svg>
        </button>

        <!-- Recherches récentes : proposées uniquement quand le champ a le focus
             ET qu'il est vide (sinon ce sont les résultats qui comptent). -->
        <div class="search-recent-pop"
             x-show="focused && $store.ui.searchTerm === '' && $store.ui.recentSearches.length > 0"
             x-cloak x-transition.opacity.duration.150ms>
            <div class="search-recent-head">
                <span><?php echo t('search_recent'); ?></span>
                <button type="button" @click="$store.ui.clearRecentSearches()"><?php echo t('search_recent_clear'); ?></button>
            </div>
            <template x-for="q in $store.ui.recentSearches" :key="'rs-' + q">
                <button type="button" class="search-recent-item" @click="applyRecentSearch(q)">
                    <svg class="ico" aria-hidden="true"><use href="#ico-history"></use></svg>
                    <span x-text="q"></span>
                </button>
            </template>
        </div>
    </div>

    <div class="topbar-actions">
        <div class="topbar-sort" x-show="$store.ui.section === 'accueil' && $store.ui.searchTerm.trim() === ''" x-cloak>
            <svg class="ico" aria-hidden="true"><use href="#ico-sort"></use></svg>
            <select id="sortSelect" onchange="filterAndSortTracks()" aria-label="<?php echo htmlspecialchars(t('tooltip_sort')); ?>">
                <option value="recommended" selected><?php echo t('sort_recommended'); ?></option>
                <option value="popular"><?php echo t('sort_popular'); ?></option>
                <option value="date_desc"><?php echo t('sort_recent'); ?></option>
                <option value="date_asc"><?php echo t('sort_oldest'); ?></option>
                <option value="alpha_asc"><?php echo t('sort_alpha_asc'); ?></option>
                <option value="alpha_desc"><?php echo t('sort_alpha_desc'); ?></option>
                <option value="artist"><?php echo t('sort_artist'); ?></option>
            </select>
        </div>

        <button type="button" class="btn-icon" @click="openModal('shortcutsModal')"
                aria-label="<?php echo htmlspecialchars(t('btn_shortcuts')); ?>"
                title="<?php echo htmlspecialchars(t('btn_shortcuts')); ?>">
            <svg class="ico" aria-hidden="true"><use href="#ico-eq"></use></svg>
        </button>

        <button type="button" class="btn-icon" id="queue-toggle" onclick="toggleQueue()"
                aria-label="<?php echo htmlspecialchars(t('btn_queue')); ?>"
                title="<?php echo htmlspecialchars(t('btn_queue')); ?>">
            <svg class="ico" aria-hidden="true"><use href="#ico-queue"></use></svg>
        </button>
    </div>
</div>
