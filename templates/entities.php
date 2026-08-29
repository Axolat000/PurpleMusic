<?php
/**
 * Index Artistes / Albums / Historique.
 *
 * Ces trois écrans n'existaient pas : on ne pouvait atteindre une page artiste
 * qu'en cliquant le nom d'artiste d'une piste croisée par hasard, et il n'y
 * avait aucun moyen de parcourir les albums ni de retrouver ce qu'on venait
 * d'écouter.
 *
 * Aucun appel réseau : les trois se dérivent de ALL_MUSIC_DATA (déjà chargé) et,
 * pour l'historique, de localStorage. Le rendu est fait en JS (voir
 * showArtistsIndex()/showAlbumsIndex()/showHistoryPage() dans js/library.js),
 * pas en x-for Alpine, pour rester cohérent avec les autres listes de pistes.
 */
?>
    <!-- Index Artistes -->
    <main id="artists-page" x-show="$store.ui.section === 'artists-page'" x-cloak>
        <div class="page-head">
            <h2 class="page-title"><?php echo t('artists_page_title'); ?></h2>
            <p class="page-sub" id="artists-index-count"></p>
        </div>
        <div class="entity-grid entity-grid-round" id="artists-index-grid"></div>
    </main>

    <!-- Index Albums -->
    <main id="albums-page" x-show="$store.ui.section === 'albums-page'" x-cloak>
        <div class="page-head">
            <h2 class="page-title"><?php echo t('albums_page_title'); ?></h2>
            <p class="page-sub" id="albums-index-count"></p>
        </div>
        <div class="entity-grid" id="albums-index-grid"></div>
    </main>

    <!-- Historique d'écoute (local au navigateur — voir pushHistory() dans js/playback.js).
         Volontairement pas côté serveur : listen_events existe déjà en base pour
         l'analytique/les recommandations, mais l'exposer par utilisateur demanderait
         un nouvel endpoint ; ici on ne fait que refléter ce que CE navigateur a joué. -->
    <!-- Statistiques d'écoute personnelles (voir api/stats.php). Toutes les données
         viennent de listen_events, alimentée depuis longtemps mais jusqu'ici
         exploitée uniquement pour les recommandations : rien n'en était restitué
         à l'utilisateur. -->
    <main id="stats-page" x-show="$store.ui.section === 'stats-page'" x-cloak>
        <div class="page-head">
            <h2 class="page-title"><?php echo t('stats_title'); ?></h2>
            <div class="stats-range" role="group" aria-label="<?php echo htmlspecialchars(t('stats_title')); ?>">
                <?php
                // 0 = depuis toujours (voir $days dans api/stats.php).
                $ranges = [30 => t('stats_range_30'), 90 => t('stats_range_90'), 365 => t('stats_range_365'), 0 => t('stats_range_all')];
                foreach ($ranges as $d => $label): ?>
                <button type="button" class="genre-pill" :class="{ active: $store.ui.statsRange === <?php echo $d; ?> }"
                        @click="loadStats(<?php echo $d; ?>)"><?php echo htmlspecialchars($label); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="stats-body"></div>
    </main>

    <main id="history-page" x-show="$store.ui.section === 'history-page'" x-cloak>
        <div class="page-head">
            <h2 class="page-title"><?php echo t('nav_history'); ?></h2>
            <button type="button" class="btn btn-outline btn-sm" onclick="clearListenHistory()">
                <?php echo t('search_recent_clear'); ?>
            </button>
        </div>
        <div class="track-list" id="history-track-list"></div>
    </main>
