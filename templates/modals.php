    <!-- Révélation du mot de passe temporaire généré par un admin (Admin Panel > Utilisateurs > Réinitialiser).
         Reste affiché jusqu'à fermeture manuelle (pas de toast auto-dismiss) pour laisser le temps de le copier. -->
    <div id="adminResetPasswordModal" class="modal" x-show="$store.ui.activeModal === 'adminResetPasswordModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('adminResetPasswordModal')"><div class="modal-content" style="max-width:420px;">
        <h2 style="margin-top:0;"><?php echo t('admin_users_reset_password_title'); ?></h2>
        <p style="color:var(--text-muted); font-size:0.9em; margin-bottom:15px;" x-text="T('admin_users_reset_password_intro', { username: $store.ui.adminGeneratedPassword.username })"></p>
        <input type="text" readonly x-model="$store.ui.adminGeneratedPassword.password" onclick="this.select()" style="font-family:monospace; font-weight:700; text-align:center; letter-spacing:1px; margin-bottom:0;">
        <div style="display:flex; gap:15px; margin-top:20px;">
            <button type="button" class="btn btn-outline" style="flex:1; justify-content:center;" onclick="copyAdminGeneratedPassword()"><?php echo t('admin_users_copy_password'); ?></button>
            <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" onclick="closeModal('adminResetPasswordModal')"><?php echo t('btn_close'); ?></button>
        </div>
    </div></div>

    <!-- Popup de mise à jour (admin uniquement) : ouverte automatiquement par $store.ui.checkForUpdate()
         (voir app.js init()) quand une nouvelle version est détectée. Deux états selon que Watchtower est
         configuré côté serveur (bouton "Mettre à jour") ou non (install docker-compose sans sidecar /
         install "docker run" simple -> instructions manuelles, voir DOCKER.md/README.md). -->
    <?php if ($is_admin): ?>
    <div id="updateAvailableModal" class="modal" x-show="$store.ui.activeModal === 'updateAvailableModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.dismissUpdateNotice()"><div class="modal-content" style="max-width:480px;">
        <h2 style="margin-top:0;"><?php echo t('update_available_title'); ?></h2>

        <template x-if="$store.ui.updateTriggerState !== 'updating'">
            <div>
                <p style="color:var(--text-muted); font-size:0.9em; margin-bottom:15px;"><?php echo t('update_available_message'); ?></p>

                <template x-if="$store.ui.updateCheck.watchtowerConfigured">
                    <button type="button" class="btn btn-primary" style="width:100%; justify-content:center; margin-bottom:12px;" :disabled="$store.ui.updateTriggering" @click="$store.ui.triggerUpdate()">
                        <span x-show="!$store.ui.updateTriggering"><?php echo t('btn_update_now'); ?></span>
                        <span x-show="$store.ui.updateTriggering" x-cloak><?php echo t('update_triggering'); ?></span>
                    </button>
                </template>
                <template x-if="!$store.ui.updateCheck.watchtowerConfigured">
                    <div>
                        <p style="color:var(--text-muted); font-size:0.85em; margin-bottom:8px;"><?php echo t('update_manual_intro'); ?></p>
                        <code style="display:block; background:var(--bg-dark); border:1px solid var(--border-color); border-radius:10px; padding:10px 14px; font-size:0.8em; margin-bottom:10px; white-space:pre-wrap; word-break:break-all;">docker compose pull && docker compose up -d</code>
                        <p style="color:var(--text-muted); font-size:0.75em; margin-bottom:8px;"><?php echo t('update_manual_or_run'); ?></p>
                        <code style="display:block; background:var(--bg-dark); border:1px solid var(--border-color); border-radius:10px; padding:10px 14px; font-size:0.8em; white-space:pre-wrap; word-break:break-all;">docker pull ghcr.io/axolat000/purplemusic:latest
docker stop purplemusic && docker rm purplemusic</code>
                        <p style="color:var(--text-muted); font-size:0.75em; margin-top:8px;"><?php echo t('update_manual_rerun_note'); ?></p>
                    </div>
                </template>

                <p x-show="$store.ui.updateTriggerState === 'error'" x-cloak style="color:var(--danger); font-size:0.85em; margin-top:12px;" x-text="$store.ui.updateTriggerError"></p>

                <div style="display:flex; gap:15px; margin-top:20px;">
                    <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" @click="$store.ui.dismissUpdateNotice()"><?php echo t('btn_later'); ?></button>
                </div>
            </div>
        </template>

        <template x-if="$store.ui.updateTriggerState === 'updating'">
            <p style="color:var(--text-muted); font-size:0.95em;"><?php echo t('update_updating_message'); ?></p>
        </template>
    </div></div>
    <?php endif; ?>

    <div id="uploadModal" class="modal" x-show="$store.ui.activeModal === 'uploadModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('uploadModal')"><div class="modal-content">
        <h2 style="margin-top:0;"><?php echo t('btn_upload'); ?></h2>
        <form method="post" enctype="multipart/form-data" onsubmit="return submitFormToApi(this, 'upload')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="text" name="title" placeholder="<?php echo htmlspecialchars(t('upload_title_placeholder')); ?>">
            <input type="text" name="artist" placeholder="<?php echo htmlspecialchars(t('upload_artist_placeholder')); ?>">
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('select_genre_label'); ?></label>
            <select name="genre">
                <?php foreach($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('audio_file_label'); ?></label>
            <input type="file" name="music" accept="audio/*" required>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('cover_file_label'); ?></label>
            <input type="file" name="cover" accept="image/*">
            <div style="display:flex; gap:15px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('uploadModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="upload" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_publish'); ?></button>
            </div>
        </form>
    </div></div>

    <div id="editTrackModal" class="modal" x-show="$store.ui.activeModal === 'editTrackModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('editTrackModal')"><div class="modal-content">
        <h2 style="margin-top:0;"><?php echo t('edit_track_title'); ?></h2>
        <form method="post" enctype="multipart/form-data" onsubmit="return submitFormToApi(this, 'edit_track')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="track_id" id="edit-track-id">
            <input type="text" name="title" id="edit-track-title" placeholder="<?php echo htmlspecialchars(t('title_placeholder')); ?>" required>
            <input type="text" name="artist" id="edit-track-artist" placeholder="<?php echo htmlspecialchars(t('artist_placeholder')); ?>">
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('edit_genre_label'); ?></label>
            <select name="new_genre" id="edit-track-genre">
                <?php foreach($genresList as $g): ?>
                    <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('change_cover_label'); ?></label>
            <input type="file" name="new_cover" accept="image/*">
            <div style="display:flex; gap:15px; margin-top:20px;">
                <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('editTrackModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="edit_track" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>
    </div></div>

    <div id="playlistModal" class="modal" x-show="$store.ui.activeModal === 'playlistModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('playlistModal')"><div class="modal-content">
        <h2 id="modal-playlist-title" style="margin-top:0;">Playlist</h2>
        <form method="post" id="playlist-form" enctype="multipart/form-data" onsubmit="return submitFormToApi(this, 'playlist_save')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="playlist_id" id="form-playlist-id">
            <input type="text" name="playlist_name" id="form-playlist-name" placeholder="<?php echo htmlspecialchars(t('playlist_name_placeholder')); ?>" required>
            <label style="font-size:0.85em; color:var(--text-muted); display:block; margin-bottom:5px;"><?php echo t('playlist_cover_label'); ?></label>
            <div style="display:flex; align-items:center; gap:15px; margin-bottom:15px;">
                <div class="playlist-cover playlist-modal-cover-preview" id="playlist-cover-preview-box">🎵<img id="playlist-cover-preview-img" style="display:none;" loading="lazy"></div>
                <input type="file" name="playlist_cover" id="form-playlist-cover" accept="image/png,image/jpeg,image/webp,image/gif" onchange="previewPlaylistCoverFile(this)" style="flex:1;">
            </div>
            <label style="display:flex; align-items:center; gap:8px; font-size:0.9em; color:var(--text-muted); margin-bottom:15px; cursor:pointer;">
                <input type="checkbox" name="is_private" id="form-playlist-private" value="1" style="width:auto;">
                <?php echo t('playlist_private_label'); ?>
            </label>
            <input type="text" id="playlist-search" placeholder="<?php echo htmlspecialchars(t('playlist_search_placeholder')); ?>" onkeyup="filterPlaylistTracks()" style="margin-bottom:10px;">
            <div style="display:flex; justify-content:space-between; font-size:0.85em; color:var(--text-muted); margin-bottom:10px;">
                <span><?php echo t('select_tracks_label'); ?></span>
                <span id="selected-count"><?php echo t('selected_count', ['n' => 0]); ?></span>
            </div>
            <div class="song-select-container">
                <?php foreach($all_tracks as $t): ?>
                    <div class="song-select-item" onclick="toggleSelection(this)" data-title="<?php echo strtolower(htmlspecialchars($t['title'])); ?>">
                        <input type="checkbox" name="selected_songs[]" value="<?php echo $t['id']; ?>" class="song-cb" data-id="<?php echo $t['id']; ?>">
                        <div class="check-indicator"></div>
                        <img src="covers/<?php echo htmlspecialchars($t['cover']); ?>" loading="lazy" style="width:40px; height:40px; border-radius:8px; margin-right:12px; object-fit:cover;" onerror="this.src='covers/default.png'">
                        <div style="flex:1; overflow:hidden;">
                            <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($t['title']); ?></div>
                            <div style="font-size:0.85em; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($t['artist']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; gap:15px; margin-top:20px;">
                 <button type="button" class="btn" style="flex:1; justify-content:center; color:#888; border:1px solid var(--border-color);" onclick="closeModal('playlistModal')"><?php echo t('btn_cancel'); ?></button>
                <button type="submit" name="save_playlist" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>
    </div></div>

