    <div id="queue-panel">
        <button class="close-queue-mobile" onclick="toggleQueue()"><?php echo t('queue_close'); ?></button>
        <h3 style="margin-top:0; color:var(--accent); font-size:1.2em; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:15px;"><?php echo t('queue_title'); ?></h3>
        <div id="queue-list" style="margin-top:15px;">
            <p style="color:#666; font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
        </div>
    </div>

    <!-- Paroles (desktop) : panneau latéral droit, même mécanisme que la file d'attente. -->
    <div id="lyrics-panel" x-data="lyricsScroller(() => $store.ui.lyricsPanelOpen)" @wheel="userInteracted()" @touchstart="userInteracted()" :class="{ open: $store.ui.lyricsPanelOpen }">
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
                        <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'" x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && isActive && $el.scrollIntoView({block:'center', behavior:'smooth'})" @click="seekToLyricLine(line.time)"></div>
                    </template>
                </div>
            </template>
            <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length === 0 && $store.ui.lyricsPlain">
                <div class="lyrics-plain-text" x-text="$store.ui.lyricsPlain"></div>
            </template>
        </div>
    </div>

