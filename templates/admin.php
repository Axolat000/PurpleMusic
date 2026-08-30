    <?php if($is_admin): ?>
    <main id="admin" x-show="$store.ui.section === 'admin'" x-cloak x-data="adminPageForm('<?php echo $initialAdminTab; ?>')">
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo t('admin_panel_title'); ?></h2>

        <div class="settings-tabs admin-page-tabs">
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'general' }" @click="activeTab = 'general'"><?php echo t('admin_section_general'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'legal' }" @click="activeTab = 'legal'"><?php echo t('admin_section_legal'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'theme' }" @click="activeTab = 'theme'"><?php echo t('admin_section_theme'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'media' }" @click="activeTab = 'media'"><?php echo t('admin_section_media'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'genres' }" @click="activeTab = 'genres'"><?php echo t('admin_section_genres'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'albums' }" @click="activeTab = 'albums'"><?php echo t('admin_section_albums'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'users' }" @click="activeTab = 'users'"><?php echo t('admin_section_users'); ?></button>
        </div>

        <form method="post" enctype="multipart/form-data" onsubmit="return submitFormToApi(this, 'save_admin_settings')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div x-show="activeTab === 'general'" x-cloak>
                <label><?php echo t('admin_app_name_label'); ?></label>
                <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
            </div>

            <div x-show="activeTab === 'legal'" x-cloak>
                <!-- Désactivées par défaut (fraîche installation open source, voir $terms_enabled dans
                     index.php) : ce switch est le seul endroit qui les active -- tant qu'il ne l'a jamais
                     été, personne n'est jamais bloqué, aucune case n'apparaît à l'inscription. -->
                <div class="eq-enable-row" style="margin-bottom:20px;">
                    <span class="settings-section-label" style="margin:0;"><?php echo t('admin_legal_enable_label'); ?></span>
                    <label class="switch-toggle">
                        <input type="checkbox" name="adm_terms_enabled" value="1" <?php echo $terms_enabled ? 'checked' : ''; ?>>
                        <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                    </label>
                </div>
                <p style="font-size:0.8em; color:var(--text-muted); margin:-12px 0 20px;"><?php echo t('admin_legal_enable_hint'); ?></p>

                <label><?php echo t('admin_legal_email_label'); ?></label>
                <input type="email" name="adm_legal_contact_email" value="<?php echo htmlspecialchars($legal_contact_email === '[email]' ? '' : $legal_contact_email); ?>" placeholder="<?php echo htmlspecialchars(t('admin_legal_email_placeholder')); ?>">
                <p style="font-size:0.8em; color:var(--text-muted); margin:-8px 0 20px;"><?php echo t('admin_legal_email_hint'); ?></p>
                <a href="cgu.php" target="_blank" rel="noopener" class="btn btn-outline"><?php echo t('admin_legal_view_cgu'); ?></a>
            </div>

            <div x-show="activeTab === 'theme'" x-cloak x-data="adminThemePreview()">
                <!-- Aperçu en direct : chaque changement de couleur s'applique
                     immédiatement à toute l'interface, au lieu d'exiger un
                     enregistrement puis un rechargement pour découvrir le résultat.
                     Rien n'est écrit en base tant qu'on n'a pas enregistré, et
                     "Annuler l'aperçu" restaure les couleurs en vigueur. -->
                <div class="admin-preview-bar">
                    <label class="admin-preview-toggle">
                        <span class="switch-toggle">
                            <input type="checkbox" x-model="live" @change="live ? applyAll() : revert()">
                            <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                        </span>
                        <span><?php echo t('admin_theme_live_preview'); ?></span>
                    </label>
                    <button type="button" class="btn btn-outline btn-sm" x-show="live" x-cloak @click="revert(); live = false;">
                        <?php echo t('admin_theme_reset_preview'); ?>
                    </button>
                </div>

                <div class="extended-color-grid" @input="live && applyAll()">
                    <div class="extended-color-item"><span><?php echo t('admin_color_bg'); ?></span><input type="color" name="adm_color_bg" value="<?php echo $color_bg; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_panel'); ?></span><input type="color" name="adm_color_panel" value="<?php echo $color_panel; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_primary'); ?></span><input type="color" name="adm_color_primary" value="<?php echo $color_primary; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_accent'); ?></span><input type="color" name="adm_color_accent" value="<?php echo $color_accent; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_text'); ?></span><input type="color" name="adm_color_text" value="<?php echo $color_text; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_text_muted'); ?></span><input type="color" name="adm_color_text_muted" value="<?php echo $color_text_muted; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_border'); ?></span><input type="color" name="adm_color_border" value="<?php echo $color_border; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_search_bg'); ?></span><input type="color" name="adm_color_search_bg" value="<?php echo $color_search_bg; ?>"></div>

                    <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_1'); ?></span><input type="color" name="adm_color_fp_gradient_1" value="<?php echo $color_fp_gradient_1; ?>"></div>
                    <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_2'); ?></span><input type="color" name="adm_color_fp_gradient_2" value="<?php echo $color_fp_gradient_2; ?>"></div>
                </div>

                <label style="margin-top: 12px; display: block;"><?php echo t('admin_header_bg_label'); ?></label>
                <input type="text" name="adm_color_header_bg" value="<?php echo htmlspecialchars($color_header_bg); ?>" placeholder="rgba(27, 20, 41, 0.85)" @input="live && applyAll()">

                <label style="margin-top: 10px; display: block;"><?php echo t('admin_player_bg_label'); ?></label>
                <input type="text" name="adm_color_player_bg" value="<?php echo htmlspecialchars($color_player_bg); ?>" placeholder="rgba(30, 24, 45, 0.85)" @input="live && applyAll()">

                <label style="margin-top: 10px; display: block;"><?php echo t('admin_mobnav_bg_label'); ?></label>
                <input type="text" name="adm_color_mob_nav_bg" value="<?php echo htmlspecialchars($color_mob_nav_bg); ?>" placeholder="rgba(21, 16, 32, 0.95)" @input="live && applyAll()">
            </div>

            <div x-show="activeTab === 'media'" x-cloak>
                <label><?php echo t('admin_favicon_label'); ?></label>
                <input type="file" name="adm_favicon" accept="image/png, image/x-icon">
                <label><?php echo t('admin_default_cover_label'); ?></label>
                <input type="file" name="adm_default_cover" accept="image/png">
            </div>

            <div x-show="activeTab === 'genres'" x-cloak>
                <label><?php echo t('admin_new_genre_label'); ?></label>
                <input type="text" name="adm_new_genre" placeholder="<?php echo htmlspecialchars(t('admin_new_genre_placeholder')); ?>">

                <label style="font-weight:bold; display:block; margin-bottom:5px;"><?php echo t('admin_active_genres_label'); ?></label>
                <!-- Chaque ligne affiche son nombre de pistes : supprimer ou fusionner
                     sans cette information revenait à agir à l'aveugle sur toute la
                     bibliothèque. -->
                <div class="adm-genre-list">
                    <?php foreach($genresList as $g):
                        $gJson = json_encode($g, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        $gCount = $genreCounts[$g] ?? 0;
                    ?>
                        <div class="adm-genre-item">
                            <span class="adm-genre-name"><?php echo htmlspecialchars($g); ?></span>
                            <span class="adm-genre-count tabular"><?php echo t('tracks_count_label', ['n' => $gCount]); ?></span>
                            <span class="adm-genre-actions">
                                <button type="button" class="track-row-btn" title="<?php echo htmlspecialchars(t('admin_genre_rename')); ?>" aria-label="<?php echo htmlspecialchars(t('admin_genre_rename')); ?>"
                                        onclick="renameGenre(<?php echo $gJson; ?>)">
                                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-edit"></use></svg>
                                </button>
                                <button type="button" class="track-row-btn" title="<?php echo htmlspecialchars(t('admin_genre_merge')); ?>" aria-label="<?php echo htmlspecialchars(t('admin_genre_merge')); ?>"
                                        onclick="mergeGenre(<?php echo $gJson; ?>)">
                                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-playlist-add"></use></svg>
                                </button>
                                <button type="button" class="track-row-btn danger" title="<?php echo htmlspecialchars(t('btn_delete_short')); ?>" aria-label="<?php echo htmlspecialchars(t('btn_delete_short')); ?>"
                                        onclick="return confirmPostAction('<?php echo t('admin_genre_delete_confirm', ['n' => $gCount]); ?>', 'delete_genre', { name: <?php echo $gJson; ?> })">
                                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-trash"></use></svg>
                                </button>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <script>
                    // Liste des genres exposée au JS pour le sélecteur de cible de fusion.
                    var ADMIN_GENRES = <?php echo json_encode(array_values($genresList), JSON_UNESCAPED_UNICODE); ?>;
                </script>
            </div>

            <div style="display:flex; gap:15px; margin-top: 25px;" x-show="activeTab !== 'users' && activeTab !== 'albums'" x-cloak>
                <button type="submit" name="save_admin_settings" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>


        <?php /* Onglet Albums : hors du <form> des reglages, comme l'onglet Utilisateurs --
                 il a ses propres envois (api/albums.php) et ne doit pas etre emporte par
                 l'enregistrement global des parametres. */ ?>
        <div x-show="activeTab === 'albums'" x-cloak x-data="adminAlbumsPanel">
            <p style="color:var(--text-muted); font-size:0.9em; margin-top:0;"><?php echo t('admin_albums_intro'); ?></p>

            <div class="adm-albums-head">
                <span class="settings-section-label" style="margin:0;"><?php echo t('admin_section_albums'); ?></span>
                <button type="button" class="btn btn-outline btn-sm" @click="newAlbum()">
                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-plus"></use></svg>
                    <?php echo t('admin_album_new'); ?>
                </button>
            </div>

            <template x-if="loading">
                <div class="adm-album-list">
                    <div class="skeleton search-skeleton-line"></div>
                    <div class="skeleton search-skeleton-line"></div>
                </div>
            </template>

            <template x-if="!loading && albums.length === 0">
                <p style="color:var(--text-muted); font-size:0.9em;"><?php echo t('admin_albums_empty'); ?></p>
            </template>

            <div class="adm-album-list" x-show="!loading && albums.length > 0">
                <template x-for="a in albums" :key="a.id">
                    <div class="adm-genre-item">
                        <img class="adm-album-cover" :src="'covers/' + (a.cover || 'default.png')" alt="" loading="lazy" onerror="this.src='covers/default.png'">
                        <span class="adm-genre-name">
                            <span x-text="a.name"></span>
                            <small class="adm-album-sub" x-text="[a.artist, a.year].filter(Boolean).join(' \u00b7 ')"></small>
                        </span>
                        <span class="adm-genre-count tabular" x-text="T('tracks_count_label', { n: a.track_count })"></span>
                        <span class="adm-genre-actions">
                            <button type="button" class="track-row-btn" :title="T('btn_save')" @click="edit(a)">
                                <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-edit"></use></svg>
                            </button>
                            <button type="button" class="track-row-btn danger" :title="T('btn_delete_short')" @click="remove(a)">
                                <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-trash"></use></svg>
                            </button>
                        </span>
                    </div>
                </template>
            </div>

            <?php // Formulaire d'edition/creation, ouvert au clic sur un album. ?>
            <?php /* x-if et non x-show : avec x-show le formulaire reste dans le DOM et
                     ses x-model="editing.*" sont evalues alors qu'`editing` vaut null --
                     Alpine levait une TypeError a chaque rendu de la page. */ ?>
            <template x-if="editing">
            <form class="adm-album-form" enctype="multipart/form-data" @submit.prevent="save($el)">
                <label><?php echo t('admin_album_name'); ?></label>
                <input type="text" x-model="editing.name" required>
                <label><?php echo t('admin_album_artist'); ?></label>
                <input type="text" x-model="editing.artist">
                <label><?php echo t('admin_album_year'); ?></label>
                <input type="number" x-model="editing.year" min="1900" max="2100" placeholder="2003">
                <label><?php echo t('admin_album_cover'); ?></label>
                <input type="file" name="cover" accept="image/*">
                <div style="display:flex; gap:12px; margin-top:16px;">
                    <button type="button" class="btn btn-outline" style="flex:1; justify-content:center;" @click="editing = null"><?php echo t('btn_cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" style="flex:1; justify-content:center;" :disabled="saving"><?php echo t('btn_save'); ?></button>
                </div>
            </form>
            </template>

            <hr class="adm-album-sep">

            <span class="settings-section-label"><?php echo t('admin_albums_assign_title'); ?></span>

            <div class="adm-assign-filters">
                <input type="search" x-model="trackFilter" placeholder="<?php echo htmlspecialchars(t('admin_albums_filter')); ?>">
                <label class="adm-assign-checkbox">
                    <input type="checkbox" x-model="unassignedOnly">
                    <span><?php echo t('admin_albums_unassigned'); ?></span>
                </label>
            </div>

            <div class="adm-assign-list">
                <?php /* La cle inclut l'album, pas seulement l'id : x-for reutilise les
                         noeuds dont la cle n'a pas change, et les objets de piste viennent
                         d'ALL_MUSIC_DATA, un tableau global non reactif -- muter t.album
                         ne notifie donc personne. Sans l'album dans la cle, une piste
                         fraichement rattachee gardait son ancien libelle a l'ecran alors
                         que la donnee etait a jour (constate en vrai). */ ?>
                <template x-for="t in filteredTracks" :key="t.id + ':' + (t.album || '')">
                    <label class="adm-assign-row">
                        <input type="checkbox" :value="t.id" x-model="selected[t.id]">
                        <span class="adm-assign-title" x-text="t.title"></span>
                        <span class="adm-assign-meta" x-text="[t.artist, t.album].filter(Boolean).join(' \u00b7 ')"></span>
                    </label>
                </template>
            </div>

            <div class="adm-assign-actions">
                <span class="adm-assign-count tabular" x-text="T('selected_count', { n: selectedIds.length })"></span>
                <select x-model="targetAlbumId">
                    <option value="">— <?php echo t('admin_albums_target_existing'); ?> —</option>
                    <template x-for="a in albums" :key="a.id">
                        <option :value="a.id" x-text="a.name"></option>
                    </template>
                </select>
                <input type="text" x-model="targetNewName" placeholder="<?php echo htmlspecialchars(t('admin_albums_target_new')); ?>" :disabled="!!targetAlbumId">
                <button type="button" class="btn btn-primary" :disabled="assigning || selectedIds.length === 0" @click="assign(false)"><?php echo t('admin_albums_assign_btn'); ?></button>
                <button type="button" class="btn btn-outline" :disabled="assigning || selectedIds.length === 0" @click="assign(true)"><?php echo t('admin_albums_detach_btn'); ?></button>
            </div>
        </div>

        <div x-show="activeTab === 'users'" x-cloak>
            <div class="admin-user-table-wrap">
                <table class="admin-user-table">
                    <thead>
                        <tr>
                            <th><?php echo t('admin_users_table_username'); ?></th>
                            <th><?php echo t('admin_users_table_role'); ?></th>
                            <th><?php echo t('admin_users_table_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_users as $u): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($u['username']); ?>
                                    <?php if ($u['id'] == $user_id): ?><span class="admin-user-you-badge">(<?php echo t('admin_users_you'); ?>)</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_admin']): ?>
                                        <span class="admin-user-role-badge admin-user-role-admin"><?php echo t('admin_badge'); ?></span>
                                    <?php else: ?>
                                        <span class="admin-user-role-badge"><?php echo t('admin_users_role_member'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="admin-user-actions">
                                    <?php if ($u['id'] == $user_id): ?>
                                        <span class="admin-user-self-note"><?php echo t('admin_users_self_note'); ?></span>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline admin-user-action-btn" onclick="adminResetPassword(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')"><?php echo t('admin_users_reset_password'); ?></button>
                                        <a href="#" class="btn btn-outline admin-user-action-btn" onclick="postApiAction('toggle_admin', { user_id: <?php echo (int)$u['id']; ?> }); return false;"><?php echo $u['is_admin'] ? t('admin_users_demote') : t('admin_users_promote'); ?></a>
                                        <a href="#" class="btn btn-danger admin-user-action-btn" onclick="return confirmPostAction('<?php echo t('confirm_delete_user'); ?>', 'delete_user', { user_id: <?php echo (int)$u['id']; ?> })"><?php echo t('btn_delete_short'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    <?php endif; ?>
