<?php
// Carte playlist (grille "Playlists publiques" / "Mes playlists privées" dans index.php) -- attend $p
// (une ligne de $all_playlists) et $user_id/$is_admin/$csrf_token dans le scope appelant (include()
// partage le scope du fichier qui l'inclut, pas besoin de les passer explicitement).
//
// La carte est passée d'un empilement de trois boutons pleine largeur (Voir le mix
// / Éditer / Suppr) à une pochette dominante surmontée d'actions révélées au
// survol : la pochette redevient l'élément principal, et une grille de playlists
// n'est plus un mur de boutons.

$canEdit = ($p['creator_id'] == $user_id || $is_admin);

// Pochette collage : quand aucune image n'est définie, on compose une mosaïque
// avec les pochettes des 4 premiers morceaux plutôt que d'afficher un dégradé
// identique pour toutes les playlists. Les pochettes sont résolues côté PHP
// (les identifiants sont déjà là) pour éviter une requête par carte.
$collageCovers = [];
if (empty($p['cover'])) {
    $memberIds = array_slice(array_filter(array_map('intval', explode(',', (string) $p['song_ids']))), 0, 4);
    if ($memberIds) {
        $ph = implode(',', array_fill(0, count($memberIds), '?'));
        $stmtCollage = $db->prepare("SELECT cover FROM tracks WHERE id IN ($ph) AND cover IS NOT NULL AND cover != ''");
        $stmtCollage->execute($memberIds);
        $collageCovers = $stmtCollage->fetchAll(PDO::FETCH_COLUMN);
    }
}
?>
<article class="playlist-card" onclick="openPlaylistDetail(<?php echo $p['id']; ?>)"
         oncontextmenu="event.preventDefault(); openPlaylistContextMenu(event, <?php echo json_encode(['id' => $p['id'], 'name' => $p['name']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)">
    <div class="playlist-card-art">
        <?php if (!empty($p['cover'])): ?>
            <div class="playlist-cover"><img src="covers/<?php echo htmlspecialchars($p['cover']); ?>" loading="lazy" alt="" onerror="this.remove()"></div>
        <?php elseif (count($collageCovers) >= 4): ?>
            <div class="playlist-cover playlist-cover-collage">
                <?php foreach (array_slice($collageCovers, 0, 4) as $c): ?>
                    <img src="covers/<?php echo htmlspecialchars($c); ?>" loading="lazy" alt="" onerror="this.style.visibility='hidden'">
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($collageCovers)): ?>
            <div class="playlist-cover"><img src="covers/<?php echo htmlspecialchars($collageCovers[0]); ?>" loading="lazy" alt="" onerror="this.remove()"></div>
        <?php else: ?>
            <div class="playlist-cover">🎵</div>
        <?php endif; ?>

        <button type="button" class="playlist-card-play"
                aria-label="<?php echo htmlspecialchars(t('btn_play_all')); ?>"
                title="<?php echo htmlspecialchars(t('btn_play_all')); ?>"
                onclick="event.stopPropagation(); playPlaylist('<?php echo htmlspecialchars($p['song_ids'], ENT_QUOTES); ?>', <?php echo $p['id']; ?>)">
            <svg class="ico" aria-hidden="true"><use href="#ico-play"></use></svg>
        </button>
    </div>

    <h3 class="marquee-wrap playlist-card-title"><span><?php echo htmlspecialchars($p['name']); ?></span></h3>
    <p class="playlist-card-sub"><?php echo t('created_by'); ?> <strong><?php echo htmlspecialchars($p['username']); ?></strong></p>

    <?php if ($canEdit): ?>
    <div class="playlist-card-actions">
        <!-- Bascule public/privé directement sur la carte : elle n'était accessible
             que via la modale d'édition, où elle se confondait avec le formulaire
             de renommage et de sélection des morceaux. -->
        <button type="button" class="track-row-btn"
                aria-label="<?php echo htmlspecialchars(empty($p['is_private']) ? t('home_public_playlists') : t('home_private_playlists')); ?>"
                title="<?php echo htmlspecialchars(empty($p['is_private']) ? t('home_public_playlists') : t('home_private_playlists')); ?>"
                onclick="togglePlaylistVisibility(<?php echo $p['id']; ?>, event)">
            <svg class="ico ico-sm" aria-hidden="true"><use href="#<?php echo empty($p['is_private']) ? 'ico-share' : 'ico-artist'; ?>"></use></svg>
        </button>
        <button type="button" class="track-row-btn"
                aria-label="<?php echo htmlspecialchars(t('btn_edit')); ?>" title="<?php echo htmlspecialchars(t('btn_edit')); ?>"
                onclick='event.stopPropagation(); openEditModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
            <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-edit"></use></svg>
        </button>
        <button type="button" class="track-row-btn danger"
                aria-label="<?php echo htmlspecialchars(t('btn_delete_short')); ?>" title="<?php echo htmlspecialchars(t('btn_delete_short')); ?>"
                onclick="event.stopPropagation(); return confirmPostAction('<?php echo t('confirm_delete_generic'); ?>', 'delete_playlist', { playlist_id: <?php echo $p['id']; ?> })">
            <svg class="ico ico-sm" aria-hidden="true"><use href="#ico-trash"></use></svg>
        </button>
    </div>
    <?php endif; ?>
</article>
