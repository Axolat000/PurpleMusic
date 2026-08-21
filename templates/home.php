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
