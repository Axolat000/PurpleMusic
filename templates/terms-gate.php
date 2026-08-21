<?php
// Écran de blocage : affiché à la place de toute l'app tant que terms_accepted_at est NULL pour
// l'utilisateur connecté (voir $terms_accepted, calculé dans index.php). Concerne aussi bien un compte
// tout juste créé sans case cochée (ne devrait jamais arriver, la case est requise à l'inscription) que,
// surtout, tout compte qui existait déjà avant l'introduction des CGU (migration -> NULL pour tous).
?>
<header>
    <div class="logo"><?php echo htmlspecialchars($site_name); ?></div>
    <div class="header-actions">
        <a href="?logout=1" class="btn" style="color:#a196b4;"><?php echo t('btn_logout'); ?></a>
    </div>
</header>

<div class="auth-page" style="margin-top: 60px;">
    <div class="auth-card">
        <h2 style="margin-top:0;"><?php echo t('terms_gate_title'); ?></h2>
        <p style="color:var(--text-muted); font-size:0.95em; line-height:1.6;"><?php echo t('terms_gate_body'); ?></p>
        <a href="cgu.php" target="_blank" rel="noopener" class="btn btn-outline" style="width:100%; justify-content:center; margin-bottom:12px;"><?php echo t('terms_gate_read_link'); ?></a>
        <button type="button" class="btn btn-primary auth-submit-btn" onclick="postApiAction('accept_terms', {})"><?php echo t('terms_gate_accept_btn'); ?></button>
    </div>
</div>
