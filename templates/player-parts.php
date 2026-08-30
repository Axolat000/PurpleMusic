<?php
/**
 * Briques de markup partagées par les trois surfaces de lecteur :
 * mini-barre (#player-bar), plein écran mobile (#full-player) et grand lecteur
 * desktop (#desktop-player).
 *
 * AVANT : chaque surface portait sa propre copie du transport, de la barre de
 * progression et du bloc de paroles. Les mêmes chemins SVG étaient recopiés à la
 * main (alors que le sprite d'icônes existe déjà, voir icons.php), avec des
 * tailles et des marges légèrement différentes à chaque copie. Côté JS, la
 * conséquence était mécanique : chaque mise à jour d'état s'écrivait trois fois
 * — getElementById('x'), puis 'fp-x', puis 'dp-x' — et un oubli sur l'une des
 * trois passait inaperçu jusqu'à ce que quelqu'un ouvre cette surface-là.
 *
 * MAINTENANT : un seul rendu paramétré par $variant, et chaque élément que le JS
 * met à jour porte un attribut data-pm-* (voir pmEach()/pmText() dans
 * js/core.js). Le JS ne connaît plus le nombre de surfaces, il écrit dans toutes
 * celles qui portent l'attribut — ajouter une quatrième surface ne demande donc
 * aucune modification JavaScript.
 *
 * Les identifiants historiques (#masterPlay, #fp-progress-bar, #dp-curr-time…)
 * sont conservés tels quels : ils servent encore de crochets CSS et de points
 * d'ancrage ailleurs dans l'app (thème, aperçu admin, visualiseur).
 */

/** Préfixe d'identifiant historique de chaque surface. */
function pm_prefix(string $variant): string
{
    return ['bar' => '', 'full' => 'fp-', 'desktop' => 'dp-'][$variant] ?? '';
}

/**
 * Contrôles de transport : aléatoire, précédent, lecture/pause, suivant, boucle.
 *
 * Les différences entre surfaces sont purement visuelles (taille des icônes,
 * écartement) et vivent désormais en CSS, sous .pm-transport — plus aucun
 * style en ligne recopié d'une surface à l'autre.
 */
function pm_transport(string $variant): void
{
    $p = pm_prefix($variant);
    $skip = $variant === 'desktop' ? ' dfp-skip-btn' : '';
    $playClass = $variant === 'desktop' ? 'dfp-master-play pm-play' : 'pm-play';
    $lShuffle = htmlspecialchars(t('tooltip_shuffle'));
    $lPrev    = htmlspecialchars(t('tooltip_prev'));
    $lPlay    = htmlspecialchars(t('tooltip_play'));
    $lNext    = htmlspecialchars(t('tooltip_next'));
    $lLoop    = htmlspecialchars(t('tooltip_loop'));
    ?>
    <button type="button" class="control-btn pm-btn-shuffle" id="<?php echo $p; ?>shuffleBtn" data-pm-shuffle
            onclick="toggleShuffle()" aria-label="<?php echo $lShuffle; ?>" title="<?php echo $lShuffle; ?>">
        <svg class="ico" aria-hidden="true"><use href="#ico-shuffle"></use></svg>
    </button>
    <button type="button" class="control-btn pm-btn-prev<?php echo $skip; ?>"
            onclick="prevTrack()" aria-label="<?php echo $lPrev; ?>" title="<?php echo $lPrev; ?>">
        <svg class="ico" aria-hidden="true"><use href="#ico-prev"></use></svg>
    </button>
    <?php // aria-label/title sont réécrits par pmSetPlayIcon() (js/playback.js) au passage en lecture. ?>
    <button type="button" class="<?php echo $playClass; ?>" id="<?php echo $p; ?>masterPlay" data-pm-play
            onclick="togglePlay()" aria-label="<?php echo $lPlay; ?>" title="<?php echo $lPlay; ?>">
        <svg class="ico" aria-hidden="true"><use href="#ico-play"></use></svg>
    </button>
    <button type="button" class="control-btn pm-btn-next<?php echo $skip; ?>"
            onclick="nextTrack()" aria-label="<?php echo $lNext; ?>" title="<?php echo $lNext; ?>">
        <svg class="ico" aria-hidden="true"><use href="#ico-next"></use></svg>
    </button>
    <?php /* Pastille "1" du mode boucle-sur-un-titre. Les trois surfaces la partagent
             maintenant : le plein écran et le desktop affichaient à la place un point
             de couleur, qui ne distinguait pas "boucle la file" de "boucle ce titre".
             L'état "boucle active" tout court reste signalé par .control-btn.active. */ ?>
    <button type="button" class="control-btn pm-btn-loop" id="<?php echo $p; ?>loopBtn" data-pm-loop
            onclick="toggleLoop()" aria-label="<?php echo $lLoop; ?>" title="<?php echo $lLoop; ?>">
        <svg class="ico" aria-hidden="true"><use href="#ico-loop"></use></svg>
        <span class="loop-status" id="<?php echo $p; ?>loop-ind" data-pm-loop-ind hidden>1</span>
    </button>
    <?php
}

/**
 * Barre de progression + temps écoulé/total.
 *
 * La piste et le remplissage ne portent plus de couleurs en dur : le plein écran
 * et le desktop forçaient `rgba(255,255,255,0.2)` et `background:white`, ce qui
 * donnait un remplissage blanc sur fond clair — invisible — dès que le thème
 * clair était actif. Ils suivent maintenant les mêmes jetons que la mini-barre.
 */
function pm_progress(string $variant): void
{
    $p = pm_prefix($variant);
    $wrap = ['bar' => 'progress-container', 'full' => 'fp-progress-wrapper', 'desktop' => 'dfp-progress-wrapper'][$variant];
    ?>
    <div class="<?php echo $wrap; ?> pm-progress" data-pm-progress>
        <div class="progress-bg" id="<?php echo $p; ?>progress-area" data-pm-progress-area>
            <?php /* Forme d'onde : un seul calque masque (voir js/waveform.js). Vide et
                     invisible tant qu'aucun pic n'est connu -- la barre pleine d'origine
                     reste alors affichee telle quelle. aria-hidden : c'est une aide
                     visuelle, la position de lecture est deja annoncee par le role=slider
                     porte par la barre elle-meme. */ ?>
            <div class="pm-wave" data-pm-wave aria-hidden="true"></div>
            <div class="progress-fill" id="<?php echo $p; ?>progress-bar" data-pm-progress-bar></div>
        </div>
        <div class="pm-time-row">
            <span id="<?php echo $p; ?>curr-time" data-pm-curr>0:00</span>
            <span id="<?php echo $p; ?>total-time" data-pm-total>0:00</span>
        </div>
    </div>
    <?php
}

/**
 * Corps du bloc de paroles, identique sur les trois surfaces qui l'affichent
 * (plein écran mobile, carte du carrousel desktop, panneau latéral #lyrics-panel).
 *
 * Le conteneur et son x-data="lyricsScroller(...)" restent propres à chaque
 * surface : c'est leur condition de visibilité qui diffère, pas le contenu.
 */
function pm_lyrics_body(): void
{
    ?>
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
                <div class="lyrics-line" :class="{ active: idx === $store.ui.lyricsActiveIndex }" x-text="line.text || '♪'"
                     x-effect="idx === $store.ui.lyricsActiveIndex && !manualScroll && isActive && $el.scrollIntoView({block:'center', behavior:'smooth'})"
                     @click="seekToLyricLine(line.time)"></div>
            </template>
        </div>
    </template>
    <template x-if="!$store.ui.lyricsLoading && $store.ui.lyricsFound === true && $store.ui.lyricsSynced.length === 0 && $store.ui.lyricsPlain">
        <div class="lyrics-plain-text" x-text="$store.ui.lyricsPlain"></div>
    </template>
    <?php
}
