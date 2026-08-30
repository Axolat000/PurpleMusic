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
        <?php /* Le tri etait un <select> natif : rendu par le systeme, donc hors du
                 theme de l'app (menu blanc sur une interface sombre, police et rayons
                 du systeme, aucun etat actif lisible). Remplace par un menu overlay
                 maison, avec la semantique ARIA d'un groupe de boutons radio pour que
                 le choix courant reste annonce aux lecteurs d'ecran -- ce que le
                 <select> donnait gratuitement et qu'il aurait ete facile de perdre.
                 La valeur vit maintenant dans le store Alpine ($store.ui.sortValue) et
                 non dans le DOM : c'etait la valeur d'un element cache qui servait
                 d'etat, un piege classique. */ ?>
        <div class="topbar-sort" x-data @keydown.escape.window="$store.ui.sortMenuOpen = false"
             @click.outside="$store.ui.sortMenuOpen = false"
             x-show="$store.ui.section === 'accueil' && $store.ui.searchTerm.trim() === ''" x-cloak>
            <button type="button" class="sort-trigger" id="sortTrigger"
                    @click="$store.ui.sortMenuOpen = !$store.ui.sortMenuOpen"
                    :aria-expanded="$store.ui.sortMenuOpen ? 'true' : 'false'"
                    aria-haspopup="true"
                    aria-label="<?php echo htmlspecialchars(t('tooltip_sort')); ?>">
                <svg class="ico" aria-hidden="true"><use href="#ico-sort"></use></svg>
                <span class="sort-trigger-label" x-text="$store.ui.sortLabel()"></span>
                <svg class="ico ico-sm sort-trigger-caret" aria-hidden="true"><use href="#ico-chevron-down"></use></svg>
            </button>
            <div class="sort-menu" role="radiogroup" aria-labelledby="sortTrigger"
                 x-show="$store.ui.sortMenuOpen" x-transition.opacity.duration.150ms x-cloak>
                <template x-for="opt in SORT_OPTIONS" :key="opt.value">
                    <button type="button" class="sort-menu-item" role="radio"
                            :class="{ active: $store.ui.sortValue === opt.value }"
                            :aria-checked="$store.ui.sortValue === opt.value ? 'true' : 'false'"
                            @click="$store.ui.setSort(opt.value)">
                        <span x-text="opt.label"></span>
                        <svg class="ico ico-sm sort-menu-check" aria-hidden="true"><use href="#ico-check"></use></svg>
                    </button>
                </template>
            </div>
        </div>

        <!-- Aide des raccourcis : sans objet sur un appareil tactile sans clavier,
             masquée sous 900px (voir css/responsive.css). -->
        <button type="button" class="btn-icon topbar-shortcuts-btn" @click="openModal('shortcutsModal')"
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
