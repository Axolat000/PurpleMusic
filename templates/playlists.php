    <?php
    // Séparation public/privé pour l'affichage uniquement -- $all_playlists est déjà filtré plus haut
    // (playlists publiques + les privées de l'utilisateur courant, ou tout pour un admin). "Mes playlists
    // privées" ne montre que les siennes, même pour un admin qui verrait aussi celles des autres en base.
    $publicPlaylists = array_filter($all_playlists, fn($p) => empty($p['is_private']));
    $privatePlaylists = array_filter($all_playlists, fn($p) => !empty($p['is_private']) && $p['creator_id'] == $user_id);
    ?>
    <main id="playlists" x-show="$store.ui.section === 'playlists'" x-cloak>
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo t('home_public_playlists'); ?></h2>
        <div class="playlist-grid">
            <?php foreach($publicPlaylists as $p): ?>
                <?php include __DIR__ . '/playlist-card.php'; ?>
            <?php endforeach; ?>
        </div>

        <h2 class="section-title" style="margin:35px 0 25px;"><?php echo t('home_private_playlists'); ?></h2>
        <?php if (empty($privatePlaylists)): ?>
            <p style="color:var(--text-muted); font-size:0.9em;"><?php echo t('no_private_playlists'); ?></p>
        <?php else: ?>
        <div class="playlist-grid">
            <?php foreach($privatePlaylists as $p): ?>
                <?php include __DIR__ . '/playlist-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <main id="playlist-detail" x-show="$store.ui.section === 'playlist-detail'" x-cloak>
        <button class="btn btn-outline" style="margin-bottom:20px;" onclick="backToPlaylists()"><?php echo t('btn_back_to_playlists'); ?></button>
        <template x-if="$store.ui.playlistDetail">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:15px; margin-bottom:25px;">
                    <div style="display:flex; gap:18px; align-items:center;">
                        <div class="playlist-cover playlist-detail-cover">🎵<img x-show="$store.ui.playlistDetail.cover" :src="'covers/' + $store.ui.playlistDetail.cover" loading="lazy" @error="$event.target.remove()"></div>
                        <div>
                            <h2 class="section-title" style="margin-bottom:8px;" x-text="$store.ui.playlistDetail.name"></h2>
                            <p style="color:var(--text-muted); font-size:0.9em; margin:0;"><?php echo t('created_by'); ?> <strong x-text="$store.ui.playlistDetail.username"></strong></p>
                        </div>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button class="btn btn-primary" onclick="playAllInPlaylistDetail()"><?php echo t('btn_play_all'); ?></button>
                        <template x-if="$store.ui.playlistDetail.canEdit">
                            <button class="btn btn-outline" onclick="editPlaylistFromDetail()"><?php echo t('btn_edit'); ?></button>
                        </template>
                        <template x-if="$store.ui.playlistDetail.canEdit">
                            <button class="btn btn-danger" @click="confirmPostAction('<?php echo t('confirm_delete_playlist'); ?>', 'delete_playlist', { playlist_id: $store.ui.playlistDetail.id })"><?php echo t('btn_delete_short'); ?></button>
                        </template>
                    </div>
                </div>
                <div class="track-list">
                    <template x-if="$store.ui.playlistDetail.loading">
                        <div style="padding:40px; text-align:center; color:#666;"><?php echo t('loading_generic'); ?></div>
                    </template>
                    <template x-if="!$store.ui.playlistDetail.loading && $store.ui.playlistDetail.tracks.length === 0">
                        <div style="padding:40px; text-align:center; color:#666;"><?php echo t('playlist_empty'); ?></div>
                    </template>
                    <template x-for="t in $store.ui.playlistDetail.tracks" :key="t.id">
                        <div class="track-item" @click="playTrackInPlaylistDetail(t.id)">
                            <img :src="'covers/' + (t.cover || 'default.png')" loading="lazy" class="mini-cover" @error="$event.target.src = 'covers/default.png'">
                            <div style="overflow:hidden;">
                                <div style="font-weight:700; font-size:1.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px;" x-text="t.title"></div>
                                <div style="font-size:0.85em; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <span x-text="t.artist"></span> <span style="opacity:0.6;font-size:0.9em;" x-text="'• ' + (t.genre || 'Autre') + ' • ▶ ' + (t.play_count || 0)"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>
    </main>
