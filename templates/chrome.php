    <div id="player-bar">
        <div class="player-info" onclick="openSmartPlayer()" style="cursor:pointer">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="player-cover" loading="lazy">
            <div style="overflow: hidden; flex: 1;">
                <div id="play-title" style="font-weight: 700; font-size:0.95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo t('player_ready'); ?></div>
                <div id="play-status" style="font-size: 0.75em; color: var(--accent); margin-top:2px;"><?php echo t('player_stopped'); ?></div>
            </div>
        </div>
        <div class="progress-container">
            <div class="progress-bg" id="progress-area">
                <div class="progress-fill" id="progress-bar"></div>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:0.7em; color:var(--text-muted); margin-top:6px; font-family:monospace;">
                <span id="curr-time">0:00</span><span id="total-time">0:00</span>
            </div>
        </div>
        <div class="controls">
            <button class="control-btn" id="shuffleBtn" onclick="toggleShuffle()" title="<?php echo htmlspecialchars(t('tooltip_shuffle')); ?>">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="control-btn" onclick="prevTrack()" title="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="masterPlay" onclick="togglePlay()" title="<?php echo htmlspecialchars(t('tooltip_play')); ?>">
                <svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="control-btn" onclick="nextTrack()" title="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="control-btn" id="loopBtn" onclick="toggleLoop()" style="position:relative;" title="<?php echo htmlspecialchars(t('tooltip_loop')); ?>">
                <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                <span id="loop-ind" style="display:none;" class="loop-status">1</span>
            </button>
            <button class="control-btn" id="lyricsBarBtn" onclick="openLyricsFromPlayerBar()" title="<?php echo htmlspecialchars(t('btn_lyrics')); ?>">
                <svg viewBox="0 0 24 24"><path d="M14 17H4v2h10v-2zM20 9H4v2h16V9zM4 15h16v-2H4v2zM4 5v2h16V5H4z"/></svg>
            </button>
            <div class="vol-flyout-anchor">
                <div class="volume-container">
                    <button type="button" class="vol-icon-btn" onclick="toggleMute()" title="<?php echo htmlspecialchars(t('tooltip_mute')); ?>">
                        <svg id="vol-icon-desktop-vol" viewBox="0 0 24 24" width="20" height="20" fill="#a196b4"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    </button>
                    <input type="range" id="desktop-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
                </div>
            </div>
        </div>
    </div>

    <div id="full-player">
        <div class="fp-header">
            <button class="fp-btn" onclick="closeFullPlayer()">
                <svg viewBox="0 0 24 24" style="width:30px; height:30px; fill:white;"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
            </button>
            <span style="font-size:0.8em; letter-spacing:1px; color:var(--text-muted); font-weight:600;"><?php echo t('now_playing_label'); ?></span>
            <div style="display:flex; gap:4px;">
                <button class="fp-btn" onclick="toggleQueue(); closeFullPlayer();">
                    <svg viewBox="0 0 24 24" style="width:24px; height:24px; fill:white;"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                </button>
            </div>
        </div>
        <div class="fp-art-container">
            <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="fp-cover" loading="lazy" x-show="!$store.ui.showLyricsInPlayer">
            <canvas id="fp-visualizer-canvas" class="fp-visualizer-canvas" x-show="$store.ui.visualizerEnabled && !$store.ui.showLyricsInPlayer" x-cloak></canvas>
            <div class="fp-lyrics-view" x-data="lyricsScroller(() => $store.ui.showLyricsInPlayer)" @wheel="userInteracted()" @touchstart="userInteracted()" x-show="$store.ui.showLyricsInPlayer" x-cloak>
                <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
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
        <div class="fp-info-area">
            <div id="fp-title"><?php echo t('title_placeholder'); ?></div>
            <div id="fp-artist" style="font-size:1.1em; color:var(--accent); font-weight:500;"><?php echo t('artist_placeholder'); ?></div>
        </div>
        <div class="fp-progress-wrapper">
            <div class="progress-bg" id="fp-progress-area" style="height:6px; background:rgba(255,255,255,0.2);">
                <div class="progress-fill" id="fp-progress-bar" style="background:white;"></div>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:10px; font-size:0.85em; color:#ccc; font-family:monospace;">
                <span id="fp-curr-time">0:00</span>
                <span id="fp-total-time">0:00</span>
            </div>
        </div>
        <div class="fp-controls">
            <button class="control-btn" id="fp-shuffleBtn" onclick="toggleShuffle()" style="transform:scale(1.2);">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="control-btn" onclick="prevTrack()" style="transform:scale(1.5);">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button id="fp-masterPlay" onclick="togglePlay()" style="background:white; color:black; border-radius:50%; width:75px; height:75px; border:none; display:flex; align-items:center; justify-content:center; box-shadow:0 0 40px rgba(255,255,255,0.2);">
                <svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="control-btn" onclick="nextTrack()" style="transform:scale(1.5);">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="control-btn" id="fp-loopBtn" onclick="toggleLoop()" style="transform:scale(1.2); position:relative;">
                <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                <span id="fp-loop-ind" style="display:none; position:absolute; top:-5px; right:-5px; background:var(--primary); width:10px; height:10px; border-radius:50%;"></span>
            </button>
        </div>
        <div class="fp-lyrics-toggle-row">
            <button type="button" class="fp-lyrics-btn" :class="{ active: $store.ui.showLyricsInPlayer }" @click="toggleLyricsInPlayer()">
                <svg viewBox="0 0 24 24"><path d="M14 17H4v2h10v-2zM20 9H4v2h16V9zM4 15h16v-2H4v2zM4 5v2h16V5H4z"/></svg>
                <span><?php echo t('btn_lyrics'); ?></span>
            </button>
        </div>
    </div>

    <!-- Lecteur "grand écran" desktop : carte centrée à deux colonnes (pochette | infos+contrôles), distinct
         du lecteur plein écran mobile (#full-player, jamais modifié) -- ce dernier serait trop vide/étiré tel
         quel à 1920px de large. Réutilise le même dégradé --fp-gradient-1/2 pour l'identité visuelle mais avec
         son propre préfixe de classes (.dfp-*) pour ne jamais hériter des styles .fp-*.
         Carrousel vertical à 3 positions ($store.ui.desktopPlayerView : 'player' | 'lyrics' | 'queue') : les
         trois cartes .dfp-card existent en permanence dans le DOM, empilées en position:absolute dans
         .dfp-stage, et se déplacent via translateY() piloté par l'état Alpine (voir .dfp-stage/.dfp-card
         dans style.css). Paroles/File d'attente ont ici leur PROPRE contenu (dupliqué depuis
         #lyrics-panel/#queue-list, même schéma que la duplication déjà faite pour mobile) -- ces panneaux
         latéraux existants restent inchangés et servent toujours pour leurs propres déclencheurs (icône file
         d'attente du header, lecteur plein écran mobile). Le bouton .dfp-close est hors des 3 cartes (enfant
         direct de .dfp-stage) pour rester cliquable quelle que soit la vue affichée. -->
    <div id="desktop-player" @click.self="closeDesktopPlayer()" @keydown.escape.window="closeDesktopPlayer()">
        <div class="dfp-stage">
            <button class="dfp-close" onclick="closeDesktopPlayer()" title="<?php echo htmlspecialchars(t('queue_close')); ?>">
                <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:currentColor;"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>

            <!-- Carte 1/3 : lecteur (vue par défaut) -->
            <div class="dfp-card dfp-card-player" :class="{ 'dfp-card-out-up': $store.ui.desktopPlayerView === 'lyrics', 'dfp-card-out-down': $store.ui.desktopPlayerView === 'queue' }">
                <div class="dfp-art-col">
                    <img src="covers/<?php echo htmlspecialchars($default_cover); ?>" id="dp-cover" loading="lazy" class="dfp-cover">
                    <canvas id="dp-visualizer-canvas" class="dfp-visualizer-canvas" x-show="$store.ui.visualizerEnabled" x-cloak></canvas>
                </div>
                <div class="dfp-info-col">
                    <span class="dfp-eyebrow"><?php echo t('now_playing_label'); ?></span>
                    <div id="dp-title" class="dfp-title"><?php echo t('title_placeholder'); ?></div>
                    <div id="dp-artist" class="dfp-artist"><?php echo t('artist_placeholder'); ?></div>

                    <div class="dfp-progress-wrapper">
                        <div class="progress-bg" id="dp-progress-area" style="height:6px; background:rgba(255,255,255,0.2);">
                            <div class="progress-fill" id="dp-progress-bar" style="background:white;"></div>
                        </div>
                        <div class="dfp-time-row">
                            <span id="dp-curr-time">0:00</span>
                            <span id="dp-total-time">0:00</span>
                        </div>
                    </div>

                    <div class="dfp-controls">
                        <button class="control-btn" id="dp-shuffleBtn" onclick="toggleShuffle()" title="<?php echo htmlspecialchars(t('tooltip_shuffle')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
                        </button>
                        <button class="control-btn dfp-skip-btn" onclick="prevTrack()" title="<?php echo htmlspecialchars(t('tooltip_prev')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
                        </button>
                        <button id="dp-masterPlay" class="dfp-master-play" onclick="togglePlay()" title="<?php echo htmlspecialchars(t('tooltip_play')); ?>">
                            <svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black; margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>
                        </button>
                        <button class="control-btn dfp-skip-btn" onclick="nextTrack()" title="<?php echo htmlspecialchars(t('tooltip_next')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
                        </button>
                        <button class="control-btn" id="dp-loopBtn" onclick="toggleLoop()" style="position:relative;" title="<?php echo htmlspecialchars(t('tooltip_loop')); ?>">
                            <svg viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                            <span id="dp-loop-ind" style="display:none; position:absolute; top:-5px; right:-5px; background:var(--primary); width:10px; height:10px; border-radius:50%;"></span>
                        </button>
                    </div>

                    <div class="dfp-secondary-actions">
                        <button type="button" class="dfp-action-btn" onclick="showDesktopPlayerLyrics()">
                            <svg viewBox="0 0 24 24"><path d="M14 17H4v2h10v-2zM20 9H4v2h16V9zM4 15h16v-2H4v2zM4 5v2h16V5H4z"/></svg>
                            <span><?php echo t('btn_lyrics'); ?></span>
                        </button>
                        <button type="button" class="dfp-action-btn" onclick="showDesktopPlayerQueue()">
                            <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
                            <span><?php echo t('btn_queue'); ?></span>
                        </button>
                        <div class="vol-hover-zone">
                            <div class="volume-container">
                                <button type="button" class="vol-icon-btn" onclick="toggleMute()" title="<?php echo htmlspecialchars(t('tooltip_mute')); ?>">
                                    <svg id="vol-icon-dp-vol" viewBox="0 0 24 24" width="16" height="16" fill="#a196b4"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                                </button>
                                <input type="range" id="dp-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Carte 2/3 : paroles -- parquée hors-écran en bas tant que la vue n'est pas 'lyrics', arrive
                 par le bas en même temps que la carte lecteur sort par le haut. Contenu dupliqué depuis
                 #lyrics-panel (même x-data="lyricsScroller()" pour le défilement auto/pause manuelle). -->
            <div class="dfp-card dfp-card-lyrics" :class="{ 'dfp-card-in': $store.ui.desktopPlayerView === 'lyrics' }" x-data="lyricsScroller(() => $store.ui.desktopPlayerView === 'lyrics')" @wheel="userInteracted()" @touchstart="userInteracted()">
                <div class="dfp-subcard-header">
                    <button type="button" class="dfp-back-btn" onclick="backToDesktopPlayer()" title="<?php echo htmlspecialchars(t('now_playing_label')); ?>">
                        <svg viewBox="0 0 24 24"><path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
                    </button>
                    <h3 class="dfp-subcard-title"><?php echo t('btn_lyrics'); ?></h3>
                    <button type="button" class="lyrics-back-to-live" x-show="manualScroll" x-cloak x-transition @click="backToLive()"><?php echo t('lyrics_back_to_live'); ?></button>
                </div>
                <div class="dfp-lyrics-body">
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

            <!-- Carte 3/3 : file d'attente -- parquée hors-écran en haut tant que la vue n'est pas 'queue',
                 arrive par le haut en même temps que la carte lecteur sort par le bas. Le rendu de la liste
                 est fait par updateQueueUI() (app.js), qui cible #dp-queue-list en plus de #queue-list. -->
            <div class="dfp-card dfp-card-queue" :class="{ 'dfp-card-in': $store.ui.desktopPlayerView === 'queue' }">
                <div class="dfp-subcard-header">
                    <button type="button" class="dfp-back-btn" onclick="backToDesktopPlayer()" title="<?php echo htmlspecialchars(t('now_playing_label')); ?>">
                        <svg viewBox="0 0 24 24"><path d="M7.41 15.41L12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
                    </button>
                    <h3 class="dfp-subcard-title"><?php echo t('queue_title'); ?></h3>
                </div>
                <div class="dfp-queue-body" id="dp-queue-list">
                    <p style="color:#666; font-size:0.9em;"><?php echo t('queue_waiting_empty'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <audio id="mainAudio"></audio>

    <div class="modal" x-show="$store.ui.confirmState.open" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.confirmNo()" @keydown.escape.window="$store.ui.confirmNo()">
        <div class="modal-content" style="max-width:420px; text-align:center;">
            <p style="font-size:1.1em; margin:0 0 25px 0;" x-text="$store.ui.confirmState.message"></p>
            <div style="display:flex; gap:15px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; border:1px solid var(--border-color); color:#888;" @click="$store.ui.confirmNo()"><?php echo t('btn_cancel'); ?></button>
                <button type="button" class="btn btn-danger" style="flex:1; justify-content:center; padding:10px 20px; font-size:0.9em;" @click="$store.ui.confirmYes()"><?php echo t('btn_confirm'); ?></button>
            </div>
        </div>
    </div>

    <div id="toast" x-show="$store.ui.toastState.visible" x-transition.opacity.duration.200ms x-cloak x-text="$store.ui.toastState.message"
         style="position:fixed; bottom:110px; left:50%; transform:translateX(-50%); background:#1e162e; color:#fff; padding:14px 26px; border-radius:14px; border:1px solid var(--border-color); box-shadow:0 15px 40px rgba(0,0,0,0.5); z-index:6000; font-size:0.9em; font-weight:600; max-width:90%; text-align:center;">
    </div>
