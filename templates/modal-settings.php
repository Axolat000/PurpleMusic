    <div id="settingsModal" class="modal" x-show="$store.ui.activeModal === 'settingsModal'" x-transition.opacity.duration.200ms x-cloak @click.self="$store.ui.closeModal('settingsModal')"><div class="modal-content" x-data="settingsModalForm()">
        <h2 style="margin-top:0;"><?php echo t('settings_title'); ?></h2>

        <div class="settings-tabs">
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'general' }" @click="activeTab = 'general'"><?php echo t('settings_tab_general'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'library' }" @click="activeTab = 'library'"><?php echo t('settings_tab_library'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'account' }" @click="activeTab = 'account'"><?php echo t('settings_tab_account'); ?></button>
            <button type="button" class="settings-tab-btn" :class="{ active: activeTab === 'eq' }" @click="activeTab = 'eq'"><?php echo t('settings_tab_eq'); ?></button>
        </div>

        <div x-show="activeTab === 'general'" x-cloak>
            <p class="settings-section-label"><?php echo t('lang_switcher_label'); ?></p>
            <div class="lang-switch-row">
                <?php
                $langOptions = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'de' => 'Deutsch'];
                foreach ($langOptions as $lc => $label):
                ?>
                    <button type="button" class="lang-switch-btn<?php echo $lang === $lc ? ' active' : ''; ?>" onclick="setLanguage('<?php echo $lc; ?>')"><?php echo $label; ?></button>
                <?php endforeach; ?>
            </div>

            <p class="settings-section-label"><?php echo t('settings_theme_label'); ?></p>
            <!-- Rangée de swatches : presets statiques (violet = pas de surcharge, couleurs admin/BDD) +
                 variantes (amoled_purple/white, midnight_blue/silver) + nouveaux presets + swatch
                 "Personnalisé" en dernier (voir activateCustomTheme() dans app.js). .theme-swatch-row a
                 déjà flex-wrap:wrap (style.css) donc ce nombre d'entrées ne déborde pas, il passe juste
                 sur plusieurs lignes selon la largeur de la modale. -->
            <div class="theme-swatch-row">
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'violet' }" style="--sw-primary:#8E44AD; --sw-accent:#BB86FC;" title="<?php echo htmlspecialchars(t('theme_violet_default')); ?>" @click="applyThemePreset('violet')"></button>
                    <span class="theme-swatch-label">Violet</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'amoled_purple' || $store.ui.themePreset === 'amoled' }" style="--sw-primary:#7B2CBF; --sw-accent:#B388FF;" title="Amoled Purple" @click="applyThemePreset('amoled_purple')"></button>
                    <span class="theme-swatch-label">Amoled Purple</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'amoled_white' }" style="--sw-primary:#4D4D4D; --sw-accent:#E8E8E8;" title="Amoled White" @click="applyThemePreset('amoled_white')"></button>
                    <span class="theme-swatch-label">Amoled White</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'midnight_blue' || $store.ui.themePreset === 'midnight' }" style="--sw-primary:#3B5BDB; --sw-accent:#7C9BFF;" title="Midnight Blue" @click="applyThemePreset('midnight_blue')"></button>
                    <span class="theme-swatch-label">Midnight Blue</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'midnight_silver' }" style="--sw-primary:#6B7A99; --sw-accent:#C7D0E0;" title="Midnight Silver" @click="applyThemePreset('midnight_silver')"></button>
                    <span class="theme-swatch-label">Midnight Silver</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'forest' }" style="--sw-primary:#2E7D4F; --sw-accent:#6FCF97;" title="Forest" @click="applyThemePreset('forest')"></button>
                    <span class="theme-swatch-label">Forest</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'crimson' }" style="--sw-primary:#B33A3A; --sw-accent:#FF6B6B;" title="Crimson" @click="applyThemePreset('crimson')"></button>
                    <span class="theme-swatch-label">Crimson</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'ocean' }" style="--sw-primary:#0E8388; --sw-accent:#4DD8D0;" title="Ocean" @click="applyThemePreset('ocean')"></button>
                    <span class="theme-swatch-label">Ocean</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'sunset' }" style="--sw-primary:#D9822B; --sw-accent:#FFB86B;" title="Sunset" @click="applyThemePreset('sunset')"></button>
                    <span class="theme-swatch-label">Sunset</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'rose' }" style="--sw-primary:#C2478D; --sw-accent:#FF8FC7;" title="Rose" @click="applyThemePreset('rose')"></button>
                    <span class="theme-swatch-label">Rose</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch" :class="{ active: $store.ui.themePreset === 'slate' }" style="--sw-primary:#5C6773; --sw-accent:#9BA8B5;" title="Slate" @click="applyThemePreset('slate')"></button>
                    <span class="theme-swatch-label">Slate</span>
                </div>
                <div class="theme-swatch-item">
                    <button type="button" class="theme-swatch theme-swatch-custom" :class="{ active: $store.ui.themePreset === 'custom' }" title="<?php echo htmlspecialchars(t('theme_custom')); ?>" @click="activateCustomTheme()"></button>
                    <span class="theme-swatch-label"><?php echo t('theme_custom'); ?></span>
                </div>
            </div>

            <!-- Constructeur de thème personnalisé : un <input type="color"> par variable de THEME_VAR_NAMES
                 (app.js), même pattern visuel que le sélecteur de couleurs de l'Admin Panel
                 (.extended-color-item, voir plus haut dans ce fichier) mais câblé en JS/localStorage plutôt
                 que via un submit de formulaire serveur -- c'est un réglage personnel par utilisateur, pas
                 le thème global de l'admin. Écriture live : chaque changement de couleur (oninput) persiste
                 et s'applique immédiatement (updateCustomThemeColor() dans app.js), pas de bouton
                 "Enregistrer" séparé. --header-bg/--player-bg/--mob-nav-bg sont normalement des rgba() semi-
                 transparentes dans les presets ; un <input type="color"> ne produit que de l'opaque, c'est
                 un compromis UX volontaire (voir commentaire dans app.js). -->
            <p class="settings-section-label"><?php echo t('custom_theme_label'); ?></p>
            <div class="extended-color-grid">
                <div class="extended-color-item"><span><?php echo t('admin_color_bg'); ?></span><input type="color" id="custom-color-bg-dark" oninput="updateCustomThemeColor('--bg-dark', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_panel'); ?></span><input type="color" id="custom-color-bg-panel" oninput="updateCustomThemeColor('--bg-panel', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_primary'); ?></span><input type="color" id="custom-color-primary" oninput="updateCustomThemeColor('--primary', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_accent'); ?></span><input type="color" id="custom-color-accent" oninput="updateCustomThemeColor('--accent', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_text'); ?></span><input type="color" id="custom-color-text" oninput="updateCustomThemeColor('--text', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_text_muted'); ?></span><input type="color" id="custom-color-text-muted" oninput="updateCustomThemeColor('--text-muted', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_border'); ?></span><input type="color" id="custom-color-border-color" oninput="updateCustomThemeColor('--border-color', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_search_bg'); ?></span><input type="color" id="custom-color-search-bg" oninput="updateCustomThemeColor('--search-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_header_bg_label'); ?></span><input type="color" id="custom-color-header-bg" oninput="updateCustomThemeColor('--header-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_player_bg_label'); ?></span><input type="color" id="custom-color-player-bg" oninput="updateCustomThemeColor('--player-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_mobnav_bg_label'); ?></span><input type="color" id="custom-color-mob-nav-bg" oninput="updateCustomThemeColor('--mob-nav-bg', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_1'); ?></span><input type="color" id="custom-color-fp-gradient-1" oninput="updateCustomThemeColor('--fp-gradient-1', this.value)"></div>
                <div class="extended-color-item"><span><?php echo t('admin_color_fp_gradient_2'); ?></span><input type="color" id="custom-color-fp-gradient-2" oninput="updateCustomThemeColor('--fp-gradient-2', this.value)"></div>
            </div>
            <button type="button" class="btn btn-outline" style="margin-bottom:25px;" @click="prefillCustomThemeFromCurrent()"><?php echo t('custom_theme_prefill_btn'); ?></button>

            <p class="settings-section-label"><?php echo t('settings_volume_label'); ?></p>
            <div class="volume-container settings-vol-row">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                <input type="range" id="settings-vol" class="vol-slider" min="0" max="1" step="0.01" value="1">
            </div>

            <!-- Visualiseur : réglage persistant unique (plus de bouton par lecteur), appliqué automatiquement
                 dans les deux lecteurs dès qu'activé -- voir setVisualizerEnabled()/applyVisualizerForContext()
                 dans app.js. Même composant .switch-toggle que l'activation de l'égaliseur (onglet Égaliseur). -->
            <div class="eq-enable-row">
                <span class="settings-section-label" style="margin:0;"><?php echo t('btn_visualizer'); ?></span>
                <label class="switch-toggle">
                    <input type="checkbox" :checked="$store.ui.visualizerEnabled" @change="setVisualizerEnabled($event.target.checked)">
                    <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                </label>
            </div>

            <!-- Minuteur de sommeil : sélecteur de préréglages directement dans Paramètres (plus de popover
                 par lecteur). Même liste (0/15/30/45/60/90/120 min) alignée sur l'app Android
                 (SettingsDialog.kt::timerOptions) pour rester cohérent entre les deux clients. -->
            <p class="settings-section-label">
                <?php echo t('btn_sleep_timer'); ?>
                <span x-show="$store.ui.sleepTimerActive" x-cloak style="color:var(--accent); font-weight:700;" x-text="'— ' + formatSleepTimerRemaining($store.ui.sleepTimerRemaining)"></span>
            </p>
            <div class="sleep-timer-settings-row">
                <template x-for="opt in [0, 15, 30, 45, 60, 90, 120]" :key="opt">
                    <button type="button" class="sleep-timer-option sleep-timer-option-settings" :class="{ 'sleep-timer-option-recent': opt > 0 && opt === $store.ui.sleepTimerLastMinutes, active: (opt === 0 && !$store.ui.sleepTimerActive) || (opt > 0 && $store.ui.sleepTimerActive && opt === $store.ui.sleepTimerLastMinutes) }" @click="chooseSleepTimer(opt)" x-text="opt === 0 ? T('sleep_timer_off') : T('sleep_timer_minutes', { n: opt })"></button>
                </template>
            </div>

            <!-- Thème dynamique : surcouche par piste PAR-DESSUS le preset statique actif (voir le rangée de
                 swatches plus haut, non modifiée) -- extrait les couleurs dominante/vibrante de la pochette en
                 cours et anime --fp-gradient-1/2 vers ces couleurs (voir setDynamicThemeEnabled()/
                 applyDynamicThemeForCurrentTrack() dans app.js, appelée depuis loadTrack()). Même composant
                 .switch-toggle que le Visualiseur ci-dessus. -->
            <div class="eq-enable-row">
                <span class="settings-section-label" style="margin:0;"><?php echo t('dynamic_theme_label'); ?></span>
                <label class="switch-toggle">
                    <input type="checkbox" :checked="$store.ui.dynamicThemeEnabled" @change="setDynamicThemeEnabled($event.target.checked)">
                    <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                </label>
            </div>
        </div>

        <div x-show="activeTab === 'library'" x-cloak>
            <p style="color:var(--text-muted); font-size:0.9em; margin-bottom: 20px;"><?php echo t('settings_hide_intro_pre'); ?> <strong style="color:var(--danger);"><?php echo t('settings_hide_word'); ?></strong> :</p>
            <div class="settings-grid">
                <?php foreach($genresList as $g): ?>
                    <label><input type="checkbox" class="genre-filter-cb" data-genre="<?php echo htmlspecialchars($g); ?>" onchange="toggleGenreSetting('<?php echo htmlspecialchars($g); ?>', this.checked)"> <?php echo htmlspecialchars($g); ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div x-show="activeTab === 'account'" x-cloak>
            <p class="settings-section-label"><?php echo t('settings_change_password_title'); ?></p>
            <form @submit.prevent="submitPasswordChange()">
                <input type="password" placeholder="<?php echo htmlspecialchars(t('settings_current_password_placeholder')); ?>" x-model="pwCurrent" autocomplete="current-password" required>
                <input type="password" placeholder="<?php echo htmlspecialchars(t('settings_new_password_placeholder')); ?>" x-model="pwNew" autocomplete="new-password" required>
                <input type="password" placeholder="<?php echo htmlspecialchars(t('settings_confirm_password_placeholder')); ?>" x-model="pwConfirm" autocomplete="new-password" required>
                <p x-show="pwError" x-cloak style="color:var(--danger); font-size:0.85em; margin:-10px 0 15px;" x-text="pwError"></p>
                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;" :disabled="pwSubmitting"><?php echo t('btn_change_password'); ?></button>
            </form>
        </div>

        <!-- Onglet Égaliseur : chaîne de 5 BiquadFilterNode (peaking) partagée avec le graphe audio du
             Visualizer (voir initAudioGraph()/EQ_BANDS dans app.js -- un seul createMediaElementSource()
             pour toute la durée de vie de <audio id="mainAudio">, donc EQ et Visualizer lisent/écrivent
             le même graphe plutôt que d'en créer chacun le leur). Réglages persistés en localStorage
             (purpleMusicEqEnabled/purpleMusicEqBands, même convention que purpleMusicVolume) et réappliqués
             au chargement (restoreEqUI()). Curseurs .vol-slider réutilisés tels quels (identité visuelle
             volume) sur une plage bipolaire -12..+12 dB -- l'activation/désactivation ne réinitialise pas
             les valeurs choisies, elle bascule juste entre gains appliqués et gains à 0 (unité). -->
        <div x-show="activeTab === 'eq'" x-cloak>
            <div class="eq-enable-row">
                <span class="settings-section-label" style="margin:0;"><?php echo t('eq_enable_label'); ?></span>
                <label class="switch-toggle">
                    <input type="checkbox" id="eq-enable-cb" onchange="setEqEnabled(this.checked)">
                    <span class="switch-toggle-track"><span class="switch-toggle-thumb"></span></span>
                </label>
            </div>
            <div class="eq-bands" id="eq-bands">
                <?php $eqBandLabels = ['60 Hz', '230 Hz', '910 Hz', '3.6 kHz', '14 kHz']; ?>
                <?php foreach ($eqBandLabels as $eqI => $eqLabel): ?>
                <div class="eq-band-row">
                    <span class="eq-band-freq"><?php echo $eqLabel; ?></span>
                    <input type="range" id="eq-band-<?php echo $eqI; ?>" class="vol-slider eq-band-slider" min="-12" max="12" step="0.5" value="0" oninput="setEqBand(<?php echo $eqI; ?>, this.value)">
                    <span class="eq-band-val" id="eq-band-<?php echo $eqI; ?>-val">0.0 dB</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($terms_enabled): ?>
        <p style="text-align:center; margin: 18px 0 0;"><a href="cgu.php" target="_blank" rel="noopener" style="color:var(--text-muted); font-size:0.82em;"><?php echo t('settings_terms_link'); ?></a></p>
        <?php endif; ?>

        <div style="display:flex; gap:15px; margin-top:15px;">
            <button type="button" class="btn btn-primary" style="flex:1; justify-content:center;" onclick="closeModal('settingsModal')"><?php echo t('btn_close'); ?></button>
        </div>
    </div></div>
