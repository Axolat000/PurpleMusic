<?php
// Carte playlist (grille "Playlists publiques" / "Mes playlists privées" dans index.php) -- attend $p
// (une ligne de $all_playlists) et $user_id/$is_admin/$csrf_token dans le scope appelant (include()
// partage le scope du fichier qui l'inclut, pas besoin de les passer explicitement).
?>
<div class="playlist-card" style="cursor:pointer;" onclick="openPlaylistDetail(<?php echo $p['id']; ?>)">
    <div class="playlist-cover">🎵<?php if (!empty($p['cover'])): ?><img src="covers/<?php echo htmlspecialchars($p['cover']); ?>" loading="lazy" onerror="this.remove()"><?php endif; ?></div>
    <h3 class="marquee-wrap playlist-card-title" style="margin-top:0; font-size:1.3em;"><span><?php echo htmlspecialchars($p['name']); ?></span></h3>
    <p style="font-size:0.85em; color:var(--text-muted); margin-bottom:20px;"><?php echo t('created_by'); ?> <strong><?php echo htmlspecialchars($p['username']); ?></strong></p>
    <button class="btn btn-primary" style="width:100%; justify-content:center; margin-bottom:15px;" onclick="event.stopPropagation(); openPlaylistDetail(<?php echo $p['id']; ?>)"><?php echo t('btn_view_mix'); ?></button>
    <?php if($p['creator_id'] == $user_id || $is_admin): ?>
        <div style="display:flex; gap:10px;">
            <button class="btn btn-outline" style="flex:1; justify-content:center; font-size:0.8em;" onclick='event.stopPropagation(); openEditModal(<?php echo json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'><?php echo t('btn_edit'); ?></button>
            <a href="#" class="btn btn-danger" style="flex:1; justify-content:center; font-size:0.8em; border-radius:99px;" onclick="event.stopPropagation(); return confirmPostAction('<?php echo t('confirm_delete_generic'); ?>', 'delete_playlist', { playlist_id: <?php echo $p['id']; ?> })"><?php echo t('btn_delete_short'); ?></a>
        </div>
    <?php endif; ?>
</div>
