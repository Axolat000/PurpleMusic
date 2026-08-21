    <?php if($is_admin): ?>
    <main id="admin" x-show="$store.ui.section === 'admin'" x-cloak x-data="adminPageForm('<?php echo $initialAdminTab; ?>')">
        <h2 class="section-title" style="margin-bottom:25px;"><?php echo t('admin_panel_title'); ?></h2>

        <div class="settings-tabs admin-page-tabs">
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'general' }" @click="activeTab = 'general'"><?php echo t('admin_section_general'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'theme' }" @click="activeTab = 'theme'"><?php echo t('admin_section_theme'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'media' }" @click="activeTab = 'media'"><?php echo t('admin_section_media'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'genres' }" @click="activeTab = 'genres'"><?php echo t('admin_section_genres'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'users' }" @click="activeTab = 'users'"><?php echo t('admin_section_users'); ?></button>
        </div>

        <form method="post" enctype="multipart/form-data" onsubmit="return submitFormToApi(this, 'save_admin_settings')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div x-show="activeTab === 'general'" x-cloak>
                <label><?php echo t('admin_app_name_label'); ?></label>
                <input type="text" name="adm_site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
            </div>

            <div x-show="activeTab === 'theme'" x-cloak>
                <div class="extended-color-grid">
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
                <input type="text" name="adm_color_header_bg" value="<?php echo htmlspecialchars($color_header_bg); ?>" placeholder="rgba(27, 20, 41, 0.85)">

                <label style="margin-top: 10px; display: block;"><?php echo t('admin_player_bg_label'); ?></label>
                <input type="text" name="adm_color_player_bg" value="<?php echo htmlspecialchars($color_player_bg); ?>" placeholder="rgba(30, 24, 45, 0.85)">

                <label style="margin-top: 10px; display: block;"><?php echo t('admin_mobnav_bg_label'); ?></label>
                <input type="text" name="adm_color_mob_nav_bg" value="<?php echo htmlspecialchars($color_mob_nav_bg); ?>" placeholder="rgba(21, 16, 32, 0.95)">
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
                <div style="max-height:220px; overflow-y:auto; border:1px solid var(--border-color); padding:10px; border-radius:10px;">
                    <?php foreach($genresList as $g): ?>
                        <div class="adm-genre-item">
                            <span><?php echo htmlspecialchars($g); ?></span>
                            <a href="#" style="color:var(--danger); text-decoration:none; font-weight:bold;" onclick="return confirmPostAction('<?php echo t('confirm_delete_genre'); ?>', 'delete_genre', { name: <?php echo json_encode($g, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?> })">✕</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex; gap:15px; margin-top: 25px;" x-show="activeTab !== 'users'" x-cloak>
                <button type="submit" name="save_admin_settings" class="btn btn-primary" style="flex:1; justify-content:center;"><?php echo t('btn_save'); ?></button>
            </div>
        </form>

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
