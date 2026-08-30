    <?php
    // Séparation public/privé pour l'affichage uniquement -- $all_playlists est déjà filtré plus haut
    // (playlists publiques + les privées de l'utilisateur courant, ou tout pour un admin). "Mes playlists
    // privées" ne montre que les siennes, même pour un admin qui verrait aussi celles des autres en base.
    $publicPlaylists = array_filter($all_playlists, fn($p) => empty($p['is_private']));
    $privatePlaylists = array_filter($all_playlists, fn($p) => !empty($p['is_private']) && $p['creator_id'] == $user_id);
    ?>
    <main id="playlists" x-show="$store.ui.section === 'playlists'" x-cloak>
        <div class="page-head">
            <h2 class="page-title"><?php echo t('home_public_playlists'); ?></h2>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-outline btn-sm" onclick="openCreateModal()">
                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-plus"></use></svg>
                    <?php echo t('btn_create_playlist'); ?>
                </button>
                <?php /* Generer et creer sont deux facons de faire la meme chose : elles
                         doivent etre cote a cote, pas l'une en tete de page et l'autre
                         enfouie plus bas. */ ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="openPlaylistGenerate()">
                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-sort"></use></svg>
                    <?php echo t('playlist_gen_button'); ?>
                </button>
            </div>
        </div>
        <?php if (empty($publicPlaylists)): ?>
            <!-- État vide explicite : une grille vide ressemblait à un chargement bloqué. -->
            <div class="empty-state">
                <svg class="ico" aria-hidden="true"><use href="#ico-playlist"></use></svg>
                <p class="empty-state-title"><?php echo t('empty_playlists_title'); ?></p>
                <p class="empty-state-hint"><?php echo t('empty_playlists_hint'); ?></p>
            </div>
        <?php else: ?>
        <div class="playlist-grid">
            <?php foreach($publicPlaylists as $p): ?>
                <?php include __DIR__ . '/playlist-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h2 class="page-title section-divider"><?php echo t('home_private_playlists'); ?></h2>
        <?php if (empty($privatePlaylists)): ?>
            <p class="page-sub"><?php echo t('no_private_playlists'); ?></p>
        <?php else: ?>
        <div class="playlist-grid">
            <?php foreach($privatePlaylists as $p): ?>
                <?php include __DIR__ . '/playlist-card.php'; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <main id="playlist-detail" x-show="$store.ui.section === 'playlist-detail'" x-cloak>
        <button class="btn btn-outline btn-sm entity-back" onclick="goBackSection()"><?php echo t('btn_back_to_playlists'); ?></button>
        <template x-if="$store.ui.playlistDetail">
            <div>
                <div class="playlist-detail-head">
                    <div class="playlist-detail-ident">
                        <div class="playlist-cover playlist-detail-cover">🎵<img x-show="$store.ui.playlistDetail.cover" :src="'covers/' + $store.ui.playlistDetail.cover" loading="lazy" alt="" @error="$event.target.remove()"></div>
                        <div class="playlist-detail-meta">
                            <p class="entity-page-kind"><?php echo t('nav_playlists'); ?></p>

                            <!-- Titre modifiable sur place : un clic sur le titre suffit,
                                 au lieu d'ouvrir la modale d'édition complète (qui recharge
                                 aussi la liste des morceaux) pour un simple renommage. -->
                            <h2 class="entity-page-title playlist-title-view"
                                x-show="!$store.ui.playlistTitleEditing"
                                :class="{ 'editable': $store.ui.playlistDetail.canEdit }"
                                :title="$store.ui.playlistDetail.canEdit ? T('btn_edit') : ''"
                                @click="$store.ui.playlistDetail.canEdit && startPlaylistTitleEdit()"
                                x-text="$store.ui.playlistDetail.name"></h2>
                            <input type="text" class="playlist-title-input"
                                   x-show="$store.ui.playlistTitleEditing" x-cloak
                                   x-ref="playlistTitleInput"
                                   x-model="$store.ui.playlistTitleDraft"
                                   @keydown.enter.prevent="savePlaylistTitleInline()"
                                   @keydown.escape="cancelPlaylistTitleEdit()"
                                   @blur="savePlaylistTitleInline()"
                                   maxlength="100">

                            <p class="entity-page-count"><?php echo t('created_by'); ?> <strong x-text="$store.ui.playlistDetail.username"></strong></p>

                            <div class="entity-page-actions">
                                <button class="btn btn-primary" onclick="playAllInPlaylistDetail()">
                                    <svg class="ico" aria-hidden="true"><use href="#ico-play"></use></svg>
                                    <?php echo t('entity_play_all'); ?>
                                </button>
                                <button class="btn btn-outline" onclick="shufflePlaylistDetail()">
                                    <svg class="ico" aria-hidden="true"><use href="#ico-shuffle"></use></svg>
                                    <?php echo t('entity_shuffle'); ?>
                                </button>
                                <template x-if="$store.ui.playlistDetail.canEdit">
                                    <button class="btn btn-outline" onclick="editPlaylistFromDetail()"><?php echo t('btn_edit'); ?></button>
                                </template>
                                <?php /* Partage reserve au proprietaire (canEdit) : un lien de
                                         partage engage la playlist de quelqu'un, ce n'est pas
                                         une action de lecteur. */ ?>
                                <template x-if="$store.ui.playlistDetail.canEdit">
                                    <button class="btn btn-outline" @click="openPlaylistShare()">
                                        <svg class="ico" aria-hidden="true"><use href="#ico-share"></use></svg>
                                        <?php echo t('playlist_share'); ?>
                                    </button>
                                </template>
                                <template x-if="$store.ui.playlistDetail.canEdit">
                                    <button class="btn btn-danger" @click="confirmPostAction('<?php echo t('confirm_delete_playlist'); ?>', 'delete_playlist', { playlist_id: $store.ui.playlistDetail.id })"><?php echo t('btn_delete_short'); ?></button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Liste rendue en JS (et non par un x-for Alpine) : le glisser-déposer
                     manipule directement le DOM, et un re-rendu réactif d'Alpine au milieu
                     d'un glissement réinitialiserait les éléments en cours de déplacement.
                     Voir renderPlaylistDetailTracks() dans js/player-ui.js. -->
                <div class="track-list" id="playlist-detail-list"></div>
                <p class="playlist-reorder-hint" x-show="$store.ui.playlistDetail.canEdit && $store.ui.playlistDetail.tracks.length > 1" x-cloak>
                    <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-drag"></use></svg>
                    <?php echo t('queue_reorder_hint'); ?>
                </p>
            </div>
        </template>
    </main>
