<?php
/**
 * Accueil = trois états exclusifs dans le même <main> :
 *   1. recherche vide   -> rangées de découverte (#home-sections)
 *   2. recherche active -> résultats catégorisés (#search-results)
 *   3. bibliothèque complète (#global-list), toujours sous les rangées
 *
 * La barre de recherche elle-même n'est plus ici : elle a migré dans la barre
 * supérieure collante (templates/topbar.php) pour rester accessible depuis tous
 * les écrans.
 */

/**
 * Rangée horizontale de découverte ("Netflix row").
 *
 * Les 4 rangées de l'accueil étaient jusqu'ici copiées-collées : ~22 lignes de
 * markup identiques à chaque fois, chevrons et template x-for compris, avec des
 * divergences déjà installées (une rangée avec en-tête + "Voir tout", une autre
 * avec un <h3> nu et un style="margin-bottom:15px" en dur). Un seul point de
 * définition évite qu'elles redivergent.
 *
 * @param string      $storeKey  clé du store Alpine contenant le tableau
 * @param string      $title     titre affiché
 * @param string      $keyPrefix préfixe de :key (doit être unique par rangée)
 * @param string|null $seeAllSort tri de la page "Voir tout", ou null pour masquer le lien
 * @param string      $kind      'track' | 'playlist'
 */
function home_row(string $storeKey, string $title, string $keyPrefix, ?string $seeAllSort = null, string $kind = 'track'): void {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    ?>
    <template x-if="$store.ui.<?php echo $storeKey; ?>.length > 0">
        <section class="home-row">
            <div class="home-row-header">
                <h3 class="home-row-title"><?php echo htmlspecialchars($title); ?></h3>
                <?php if ($seeAllSort !== null): ?>
                <button type="button" class="home-row-see-all" onclick="openBrowseAll('<?php echo $seeAllSort; ?>', '<?php echo $safeTitle; ?>')"><?php echo t('home_see_all'); ?></button>
                <?php endif; ?>
            </div>
            <div class="home-row-wrap" x-data="homeRowScroller()">
                <button type="button" class="home-row-arrow home-row-arrow-left" x-show="canLeft" x-cloak @click="scrollDir(-1)" aria-label="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                    <svg class="ico" aria-hidden="true"><use href="#ico-chevron-left"></use></svg>
                </button>
                <div class="home-row-scroll" x-ref="scrollEl" @scroll="onScroll">
                    <?php if ($kind === 'playlist'): ?>
                    <template x-for="(p, i) in $store.ui.<?php echo $storeKey; ?>" :key="'<?php echo $keyPrefix; ?>-' + p.id">
                        <article class="home-track-card fade-in-row" :style="'--i:' + i" @click="openPlaylistDetail(p.id)"
                                 @contextmenu.prevent="openPlaylistContextMenu($event, p)">
                            <div class="playlist-cover">🎵<img x-show="p.cover" :src="'covers/' + p.cover" loading="lazy" alt="" @error="$event.target.remove()"></div>
                            <div class="marquee-wrap home-track-card-title"><span x-text="p.name"></span></div>
                            <div class="home-track-card-sub" x-text="p.username"></div>
                        </article>
                    </template>
                    <?php else: ?>
                    <template x-for="(t, i) in $store.ui.<?php echo $storeKey; ?>" :key="'<?php echo $keyPrefix; ?>-' + t.id">
                        <article class="home-track-card fade-in-row" :style="'--i:' + i" @click="playTrackById(t.id)"
                                 @contextmenu.prevent="openTrackContextMenu($event, t.id)">
                            <div class="home-card-art">
                                <img :src="'covers/' + (t.cover || 'default.png')" loading="lazy" alt="" @error="$event.target.src = 'covers/default.png'">
                                <!-- Bouton de lecture révélé au survol, façon carte Spotify/YT Music :
                                     la carte entière reste cliquable, ce bouton ne fait que rendre
                                     l'action évidente au lieu de la laisser deviner. -->
                                <button type="button" class="home-card-play" tabindex="-1" aria-hidden="true">
                                    <svg class="ico" aria-hidden="true"><use href="#ico-play"></use></svg>
                                </button>
                                <div class="now-playing-bars" x-show="$store.ui.currentTrackId === t.id" x-cloak
                                     :class="{ paused: !$store.ui.isPlaying }"><span></span><span></span><span></span></div>
                            </div>
                            <div class="marquee-wrap home-track-card-title"><span x-text="t.title"></span></div>
                            <div class="home-track-card-sub home-track-card-artist" x-text="t.artist" @click.stop="showArtistPage(splitArtistNames(t.artist)[0] || t.artist)"></div>
                        </article>
                    </template>
                    <?php endif; ?>
                </div>
                <button type="button" class="home-row-arrow home-row-arrow-right" x-show="canRight" x-cloak @click="scrollDir(1)" aria-label="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                    <svg class="ico" aria-hidden="true"><use href="#ico-chevron-right"></use></svg>
                </button>
            </div>
        </section>
    </template>
    <?php
}
?>
    <main id="accueil" x-show="$store.ui.section === 'accueil'" x-cloak>

        <!-- ÉTAT 1 : découverte (recherche vide) -->
        <div id="home-sections" x-show="$store.ui.searchTerm.trim() === ''" x-cloak>

            <!-- Filtre par genre : recompose les rangées à la volée, sans recharger.
                 "Tous" est toujours en tête et actif par défaut. -->
            <div class="genre-pills" x-show="$store.ui.genrePills.length > 1" x-cloak>
                <button type="button" class="genre-pill" :class="{ active: $store.ui.activeGenre === null }"
                        @click="$store.ui.setGenreFilter(null)"><?php echo t('home_all_genres'); ?></button>
                <template x-for="g in $store.ui.genrePills" :key="'gp-' + g">
                    <button type="button" class="genre-pill" :class="{ active: $store.ui.activeGenre === g }"
                            @click="$store.ui.setGenreFilter(g)" x-text="g"></button>
                </template>
            </div>

            <?php
            home_row('continueTracks', t('home_continue_listening'), 'cont');
            home_row('recentTracks', t('sort_recent'), 'recent', 'date_desc');
            home_row('recommendedTracks', t('home_recommended_for_you'), 'reco');
            home_row('popularTracks', t('sort_popular'), 'popular', 'popular');
            home_row('hiddenGemTracks', t('home_hidden_gems'), 'gem');
            home_row('playlistsPreview', t('home_your_mixes'), 'pl', null, 'playlist');
            ?>

            <!-- Squelettes : tenus tant que les recommandations serveur n'ont pas répondu.
                 Avant, la zone restait simplement vide puis une rangée apparaissait d'un
                 coup en poussant tout le contenu vers le bas. -->
            <div class="home-row" x-show="!$store.ui.homeLoaded" x-cloak>
                <div class="skeleton skeleton-line" style="width:180px; height:16px; margin-bottom:16px;"></div>
                <div class="skeleton-row">
                    <template x-for="n in 7" :key="'sk-' + n">
                        <div class="skeleton-card">
                            <div class="skeleton skeleton-cover"></div>
                            <div class="skeleton skeleton-line"></div>
                            <div class="skeleton skeleton-line short"></div>
                        </div>
                    </template>
                </div>
            </div>

            <h3 class="home-row-title section-divider"><?php echo t('section_all_tracks'); ?></h3>
        </div>

        <!-- ÉTAT 2 : résultats de recherche catégorisés (Artistes / Albums / Titres).
             Rendu en JS (renderSearchResults(), js/library.js) : les trois catégories
             se dérivent du même filtrage, les recalculer en Alpine ferait 3 passes. -->
        <div id="search-results" x-show="$store.ui.searchTerm.trim() !== ''" x-cloak></div>

        <!-- ÉTAT 3 : bibliothèque complète — masquée pendant une recherche, les
             résultats catégorisés ci-dessus la remplacent. -->
        <div x-show="$store.ui.searchTerm.trim() === ''" x-cloak>
            <div class="track-list" id="global-list"></div>
            <div id="load-more-trigger"></div>
        </div>
    </main>

    <!-- Page "Voir tout" : liste dédiée pré-triée (Ajouts récents / Les plus écoutés), séparée de la
         bibliothèque de l'accueil -- ne modifie jamais $store.ui.sortValue, voir openBrowseAll(). -->
    <main id="browse" x-show="$store.ui.section === 'browse'" x-cloak>
        <div class="page-head">
            <button class="btn btn-outline btn-sm" onclick="goBackSection()"><?php echo t('btn_back'); ?></button>
            <h2 class="page-title" x-text="$store.ui.browseTitle"></h2>
        </div>
        <div class="track-list" id="browse-list"></div>
        <div id="browse-load-more-trigger"></div>
    </main>

    <!-- Page Artiste : regroupe les pistes dont le champ artiste correspond (voir splitArtistNames() dans
         library.js), pas de nouvel appel réseau -- filtrage de ALL_MUSIC_DATA côté client. Peuplée par
         showArtistPage()/fetchArtistBio() (JS), pas de réactivité Alpine ici (comme #browse). -->
    <main id="artist-page" x-show="$store.ui.section === 'artist-page'" x-cloak>
        <div class="entity-topline">
            <button class="btn btn-outline btn-sm entity-back" onclick="goBackSection()"><?php echo t('btn_back'); ?></button>
            <nav class="breadcrumb" id="artist-breadcrumb" aria-label="<?php echo htmlspecialchars(t('breadcrumb_label')); ?>"></nav>
        </div>
        <div class="entity-page-hero has-groove">
            <img id="artist-hero-bg-img" class="entity-page-hero-bg" alt="">
            <div class="entity-page-hero-content">
                <img id="artist-pfp" class="entity-page-pfp" alt="">
                <div class="entity-page-meta">
                    <p class="entity-page-kind"><?php echo t('nav_artists'); ?></p>
                    <h2 class="entity-page-title" id="artist-page-title"></h2>
                    <p class="entity-page-count" id="artist-page-count"></p>
                    <div class="entity-page-actions">
                        <button type="button" class="btn btn-primary" onclick="playEntityAll(false)">
                            <svg class="ico" aria-hidden="true"><use href="#ico-play"></use></svg>
                            <?php echo t('entity_play_all'); ?>
                        </button>
                        <button type="button" class="btn btn-outline" onclick="playEntityAll(true)">
                            <svg class="ico" aria-hidden="true"><use href="#ico-shuffle"></use></svg>
                            <?php echo t('entity_shuffle'); ?>
                        </button>
                        <button type="button" class="btn btn-outline" onclick="startEntityRadio()">
                            <svg class="ico" aria-hidden="true"><use href="#ico-cast"></use></svg>
                            <?php echo t('entity_radio'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <p id="artist-page-bio" class="entity-page-bio"></p>
        <div class="track-list" id="artist-track-list"></div>
    </main>

    <!-- Page Album : regroupe les pistes dont le champ album correspond (insensible à la casse) -- pas de
         table albums séparée, le nom sert de clé de regroupement (voir Track.album). -->
    <main id="album-page" x-show="$store.ui.section === 'album-page'" x-cloak>
        <div class="entity-topline">
            <button class="btn btn-outline btn-sm entity-back" onclick="goBackSection()"><?php echo t('btn_back'); ?></button>
            <nav class="breadcrumb" id="album-breadcrumb" aria-label="<?php echo htmlspecialchars(t('breadcrumb_label')); ?>"></nav>
        </div>
        <div class="entity-page-hero has-groove">
            <img id="album-hero-bg-img" class="entity-page-hero-bg" alt="">
            <div class="entity-page-hero-content">
                <img id="album-pfp" class="entity-page-pfp entity-page-pfp-square" alt="">
                <div class="entity-page-meta">
                    <p class="entity-page-kind"><?php echo t('nav_albums'); ?></p>
                    <h2 class="entity-page-title" id="album-page-title"></h2>
                    <p class="entity-page-count" id="album-page-count"></p>
                    <p class="entity-page-count" id="album-page-artists"></p>
                    <div class="entity-page-actions">
                        <button type="button" class="btn btn-primary" onclick="playEntityAll(false)">
                            <svg class="ico" aria-hidden="true"><use href="#ico-play"></use></svg>
                            <?php echo t('entity_play_all'); ?>
                        </button>
                        <button type="button" class="btn btn-outline" onclick="playEntityAll(true)">
                            <svg class="ico" aria-hidden="true"><use href="#ico-shuffle"></use></svg>
                            <?php echo t('entity_shuffle'); ?>
                        </button>
                        <button type="button" class="btn btn-outline" onclick="startEntityRadio()">
                            <svg class="ico" aria-hidden="true"><use href="#ico-cast"></use></svg>
                            <?php echo t('entity_radio'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="track-list" id="album-track-list"></div>
    </main>
