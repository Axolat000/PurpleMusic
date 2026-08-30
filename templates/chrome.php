<?php require_once __DIR__ . '/player-parts.php'; ?>
    <?php /* Les trois surfaces ci-dessous (mini-barre, plein écran mobile, grand lecteur
             desktop) partagent leur transport, leur barre de progression et leur bloc de
             paroles via templates/player-parts.php, et signalent au JS ce qu'il doit
             mettre à jour par des attributs data-pm-* (voir pmEach() dans js/core.js). */ ?>
    <div id="player-bar" data-pm-surface="bar">
        <div class="player-info" onclick="openSmartPlayer()" style="cursor:pointer">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="player-cover" data-pm-cover loading="lazy">
            <div style="overflow: hidden; flex: 1;">
                <div id="play-title" class="marquee-wrap player-title" data-pm-title><span><?php echo t('player_ready'); ?></span></div>
                <div id="play-status" class="player-artist-link" data-pm-artist style="font-size: 0.75em; color: var(--accent); margin-top:2px;"><?php echo t('player_stopped'); ?></div>
            </div>
        </div>
        <?php pm_progress('bar'); ?>
        <div class="controls pm-transport">
            <?php pm_transport('bar'); ?>
            <button type="button" class="control-btn" id="lyricsBarBtn" onclick="openLyricsFromPlayerBar()" aria-label="<?php echo htmlspecialchars(t('btn_lyrics')); ?>" title="<?php echo htmlspecialchars(t('btn_lyrics')); ?>">
                <svg class="ico" aria-hidden="true"><use href="#ico-lyrics"></use></svg>
            </button>
            <div class="vol-flyout-anchor">
                <div class="volume-container">
                    <button type="button" class="vol-icon-btn" onclick="toggleMute()" aria-label="<?php echo htmlspecialchars(t('tooltip_mute')); ?>" title="<?php echo htmlspecialchars(t('tooltip_mute')); ?>">
                        <svg class="ico" id="vol-icon-desktop-vol" data-pm-vol-icon aria-hidden="true"><use href="#ico-volume"></use></svg>
                    </button>
                    <input type="range" id="desktop-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
                </div>
            </div>
        </div>
    </div>

    <div id="full-player" data-pm-surface="full">
        <!-- Fond d'ambiance : la pochette en cours, floutée et saturée, derrière
             le dégradé du thème. Le dégradé seul (--fp-gradient-1/2) donnait le
             même fond à tous les morceaux ; ici chaque piste colore réellement
             son lecteur. La source est posée par loadTrack() (js/playback.js).
             aria-hidden : pure décoration, la pochette est déjà annoncée par
             #fp-cover juste en dessous. -->
        <div class="fp-ambient" id="fp-ambient" data-pm-ambient aria-hidden="true"></div>
        <div class="fp-header">
            <button type="button" class="fp-btn" onclick="closeFullPlayer()" aria-label="<?php echo htmlspecialchars(t('btn_close')); ?>">
                <svg class="ico ico-lg" aria-hidden="true"><use href="#ico-chevron-down"></use></svg>
            </button>
            <span style="font-size:0.8em; letter-spacing:1px; color:var(--text-muted); font-weight:600;"><?php echo t('now_playing_label'); ?></span>
            <div style="display:flex; gap:4px;">
                <button type="button" class="fp-btn" onclick="toggleQueue(); closeFullPlayer();" aria-label="<?php echo htmlspecialchars(t('btn_queue')); ?>">
                    <svg class="ico ico-lg" aria-hidden="true"><use href="#ico-queue"></use></svg>
                </button>
            </div>
        </div>
        <div class="fp-art-container">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" data-pm-cover loading="lazy" x-show="!$store.ui.showLyricsInPlayer">
            <canvas id="fp-visualizer-canvas" class="fp-visualizer-canvas" x-show="$store.ui.visualizerEnabled && !$store.ui.showLyricsInPlayer" x-cloak></canvas>
            <div class="fp-lyrics-view" x-data="lyricsScroller(() => $store.ui.showLyricsInPlayer)" @wheel="userInteracted()" @touchstart="userInteracted()" x-show="$store.ui.showLyricsInPlayer" x-cloak>
                <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
                <?php pm_lyrics_body(); ?>
            </div>
        </div>
        <div class="fp-info-area">
            <div id="fp-title" class="marquee-wrap" data-pm-title><span><?php echo t('title_placeholder'); ?></span></div>
            <div id="fp-artist" class="player-artist-link" data-pm-artist style="font-size:1.1em; color:var(--accent); font-weight:500;"><?php echo t('artist_placeholder'); ?></div>
        </div>
        <?php pm_progress('full'); ?>
        <div class="fp-controls pm-transport">
            <?php pm_transport('full'); ?>
        </div>
        <div class="fp-lyrics-toggle-row">
            <button type="button" class="fp-lyrics-btn" :class="{ active: $store.ui.showLyricsInPlayer }" @click="toggleLyricsInPlayer()">
                <svg class="ico" aria-hidden="true"><use href="#ico-lyrics"></use></svg>
                <span><?php echo t('btn_lyrics'); ?></span>
            </button>
            <!-- Égaliseur : il n'était atteignable que par Paramètres > Égaliseur,
                 c'est-à-dire en quittant le lecteur — alors que c'est justement en
                 écoutant qu'on veut y toucher. -->
            <button type="button" class="fp-lyrics-btn" @click="openSettingsTab('eq')">
                <svg class="ico" aria-hidden="true"><use href="#ico-eq"></use></svg>
                <span><?php echo t('settings_tab_eq'); ?></span>
            </button>
            <button type="button" class="fp-lyrics-btn" :class="{ active: $store.ui.sleepTimerActive }" @click="openSettingsTab('general')">
                <svg class="ico" aria-hidden="true"><use href="#ico-sleep"></use></svg>
                <span x-text="$store.ui.sleepTimerActive ? formatSleepTimerRemaining($store.ui.sleepTimerRemaining) : T('btn_sleep_timer')"><?php echo t('btn_sleep_timer'); ?></span>
            </button>
        </div>
    </div>

    <!-- Lecteur "grand écran" desktop : carte centrée à deux colonnes (pochette | infos+contrôles), distinct
         du lecteur plein écran mobile (#full-player) -- ce dernier serait trop vide/étiré tel quel à 1920px
         de large. Réutilise le même dégradé --fp-gradient-1/2 pour l'identité visuelle, avec son propre
         préfixe de classes (.dfp-*) pour la mise en page ; le transport, la progression et les paroles
         viennent en revanche des briques partagées (templates/player-parts.php).
         Carrousel vertical à 3 positions ($store.ui.desktopPlayerView : 'player' | 'lyrics' | 'queue') : les
         trois cartes .dfp-card existent en permanence dans le DOM, empilées en position:absolute dans
         .dfp-stage, et se déplacent via translateY() piloté par l'état Alpine (voir .dfp-stage/.dfp-card
         dans css/player.css). Le bouton .dfp-close est hors des 3 cartes (enfant direct de .dfp-stage) pour
         rester cliquable quelle que soit la vue affichée. -->
    <div id="desktop-player" data-pm-surface="desktop" @click.self="closeDesktopPlayer()" @keydown.escape.window="closeDesktopPlayer()">
        <div class="dfp-stage">
            <button type="button" class="dfp-close" onclick="closeDesktopPlayer()" aria-label="<?php echo htmlspecialchars(t('queue_close')); ?>" title="<?php echo htmlspecialchars(t('queue_close')); ?>">
                <svg class="ico" aria-hidden="true"><use href="#ico-close"></use></svg>
            </button>

            <!-- Carte 1/3 : lecteur (vue par défaut) -->
            <div class="dfp-card dfp-card-player" :class="{ 'dfp-card-out-up': $store.ui.desktopPlayerView === 'lyrics', 'dfp-card-out-down': $store.ui.desktopPlayerView === 'queue' }">
                <!-- Même fond d'ambiance que le lecteur plein écran mobile (voir #fp-ambient). -->
                <div class="fp-ambient" id="dp-ambient" data-pm-ambient aria-hidden="true"></div>
                <div class="dfp-art-col">
                    <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="dp-cover" data-pm-cover loading="lazy" class="dfp-cover">
                    <canvas id="dp-visualizer-canvas" class="dfp-visualizer-canvas" x-show="$store.ui.visualizerEnabled" x-cloak></canvas>
                </div>
                <div class="dfp-info-col">
                    <span class="dfp-eyebrow"><?php echo t('now_playing_label'); ?></span>
                    <div id="dp-title" class="dfp-title marquee-wrap" data-pm-title><span><?php echo t('title_placeholder'); ?></span></div>
                    <div id="dp-artist" class="dfp-artist player-artist-link" data-pm-artist><?php echo t('artist_placeholder'); ?></div>

                    <?php pm_progress('desktop'); ?>

                    <div class="dfp-controls pm-transport">
                        <?php pm_transport('desktop'); ?>
                    </div>

                    <div class="dfp-secondary-actions">
                        <button type="button" class="dfp-action-btn" onclick="showDesktopPlayerLyrics()" aria-label="<?php echo htmlspecialchars(t('btn_lyrics')); ?>">
                            <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-lyrics"></use></svg>
                            <span><?php echo t('btn_lyrics'); ?></span>
                        </button>
                        <button type="button" class="dfp-action-btn" onclick="showDesktopPlayerQueue()" aria-label="<?php echo htmlspecialchars(t('btn_queue')); ?>">
                            <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-queue"></use></svg>
                            <span><?php echo t('btn_queue'); ?></span>
                        </button>
                        <div class="vol-hover-zone">
                            <div class="volume-container">
                                <button type="button" class="vol-icon-btn" onclick="toggleMute()" aria-label="<?php echo htmlspecialchars(t('tooltip_mute')); ?>" title="<?php echo htmlspecialchars(t('tooltip_mute')); ?>">
                                    <svg class="ico ico-sm" id="vol-icon-dp-vol" data-pm-vol-icon aria-hidden="true"><use href="#ico-volume"></use></svg>
                                </button>
                                <input type="range" id="dp-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Carte 2/3 : paroles -- parquée hors-écran en bas tant que la vue n'est pas 'lyrics', arrive
                 par le bas en même temps que la carte lecteur sort par le haut. Le corps vient de
                 pm_lyrics_body(), partagé avec le plein écran mobile et le panneau latéral. -->
            <div class="dfp-card dfp-card-lyrics" :class="{ 'dfp-card-in': $store.ui.desktopPlayerView === 'lyrics' }" x-data="lyricsScroller(() => $store.ui.desktopPlayerView === 'lyrics')" @wheel="userInteracted()" @touchstart="userInteracted()">
                <div class="dfp-subcard-header">
                    <button type="button" class="dfp-back-btn" onclick="backToDesktopPlayer()" aria-label="<?php echo htmlspecialchars(t('now_playing_label')); ?>" title="<?php echo htmlspecialchars(t('now_playing_label')); ?>">
                        <svg class="ico" aria-hidden="true"><use href="#ico-chevron-up"></use></svg>
                    </button>
                    <h3 class="dfp-subcard-title"><?php echo t('btn_lyrics'); ?></h3>
                    <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
                </div>
                <div class="dfp-lyrics-body">
                    <?php pm_lyrics_body(); ?>
                </div>
            </div>

            <!-- Carte 3/3 : file d'attente -- parquée hors-écran en haut tant que la vue n'est pas 'queue',
                 arrive par le haut en même temps que la carte lecteur sort par le bas. Le rendu de la liste
                 est fait par updateQueueUI() (js/player-ui.js), qui remplit toutes les surfaces portant
                 [data-pm-queue-list]. -->
            <div class="dfp-card dfp-card-queue" :class="{ 'dfp-card-in': $store.ui.desktopPlayerView === 'queue' }">
                <div class="dfp-subcard-header">
                    <button type="button" class="dfp-back-btn" onclick="backToDesktopPlayer()" aria-label="<?php echo htmlspecialchars(t('now_playing_label')); ?>" title="<?php echo htmlspecialchars(t('now_playing_label')); ?>">
                        <svg class="ico" aria-hidden="true"><use href="#ico-chevron-up"></use></svg>
                    </button>
                    <h3 class="dfp-subcard-title"><?php echo t('queue_title'); ?></h3>
                </div>
                <div class="dfp-queue-body" id="dp-queue-list" data-pm-queue-list>
                    <p style="color:var(--text-muted); font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <audio id="mainAudio"></audio>

    <div class="modal" x-show="$store.ui.confirmState.open" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.confirmNo()" @keydown.escape.window="$store.ui.confirmNo()">
        <div class="modal-content" style="max-width:420px; text-align:center;">
            <p style="font-size:1.1em; margin:0 0 25px 0;" x-text="$store.ui.confirmState.message"></p>
            <div style="display:flex; gap:15px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; border:1px solid var(--border-color); color:var(--text-muted);" @click="$store.ui.confirmNo()"><?php echo t('btn_cancel'); ?></button>
                <button type="button" class="btn btn-danger" style="flex:1; justify-content:center; padding:10px 20px; font-size:0.9em;" @click="$store.ui.confirmYes()"><?php echo t('btn_confirm'); ?></button>
            </div>
        </div>
    </div>

    <!-- Saisie modale générique (renommage/fusion de genre). Remplace window.prompt(),
         qui ne suit aucun thème, ne se traduit pas et est bloqué par certains
         navigateurs — l'app avait déjà fait ce remplacement pour confirm(). -->
    <div class="modal" x-show="$store.ui.promptState.open" x-transition.opacity.duration.200ms x-cloak
         @click.self="$store.ui.promptCancel()" @keydown.escape.window="$store.ui.promptCancel()">
        <div class="modal-content" style="max-width:420px;">
            <h2 style="margin-top:0; font-size:1.1em;" x-text="$store.ui.promptState.title"></h2>

            <template x-if="$store.ui.promptState.options.length === 0">
                <input type="text" x-model="$store.ui.promptState.value"
                       @keydown.enter.prevent="$store.ui.promptSubmit()"
                       x-effect="$store.ui.promptState.open && $nextTick(() => $el.focus())">
            </template>
            <template x-if="$store.ui.promptState.options.length > 0">
                <select x-model="$store.ui.promptState.value">
                    <template x-for="opt in $store.ui.promptState.options" :key="opt">
                        <option :value="opt" x-text="opt"></option>
                    </template>
                </select>
            </template>

            <div style="display:flex; gap:15px; margin-top:10px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; border:1px solid var(--border-color); color:var(--text-muted);" @click="$store.ui.promptCancel()"><?php echo t('btn_cancel'); ?></button>
                <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" @click="$store.ui.promptSubmit()"><?php echo t('btn_confirm'); ?></button>
            </div>
        </div>
    </div>

    <!-- role="status" + aria-live : un lecteur d'écran annonce le message sans
         voler le focus (contrairement à role="alert", trop intrusif pour une
         confirmation d'action). -->
    <div id="toast" role="status" aria-live="polite"
         class="toast-typed"
         :class="'toast-' + $store.ui.toastState.kind"
         x-show="$store.ui.toastState.visible" x-transition.opacity.duration.200ms x-cloak
         x-text="$store.ui.toastState.message"></div>
