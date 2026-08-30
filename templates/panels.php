<?php require_once __DIR__ . '/player-parts.php'; ?>
    <div id="queue-panel">
        <button class="close-queue-mobile" onclick="toggleQueue()"><?php echo t('queue_close'); ?></button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;"><?php echo t('queue_title'); ?></h3>
        <?php // data-pm-queue-list : updateQueueUI() (js/player-ui.js) remplit toutes les surfaces qui le
              // portent — ce panneau latéral et la carte "file d'attente" du grand lecteur desktop. ?>
        <div id="queue-list" data-pm-queue-list style="margin-top:15px;">
            <p style="color:var(--text-muted); font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
        </div>
    </div>

    <!-- Paroles (desktop) : panneau latéral droit, même mécanisme que la file d'attente.
         Le corps vient de pm_lyrics_body() (templates/player-parts.php), partagé avec le
         lecteur plein écran mobile et la carte "paroles" du grand lecteur desktop. -->
    <div id="lyrics-panel" x-data="lyricsScroller(() => $store.ui.lyricsPanelOpen)" @wheel="userInteracted()" @touchstart="userInteracted()" :class="{ open: $store.ui.lyricsPanelOpen }">
        <button type="button" class="lyrics-panel-close" onclick="closeLyricsPanel()" aria-label="<?php echo htmlspecialchars(t('queue_close')); ?>" title="<?php echo htmlspecialchars(t('queue_close')); ?>">
            <svg class="ico" aria-hidden="true"><use href="#ico-close"></use></svg>
        </button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;"><?php echo t('btn_lyrics'); ?></h3>
        <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
        <div class="lyrics-panel-body" style="margin-top:15px;">
            <?php pm_lyrics_body(); ?>
        </div>
    </div>
