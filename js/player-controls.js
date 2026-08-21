function adminResetPassword(userId, username) {
    Alpine.store('ui').confirmAction(T('confirm_reset_password', { username: username }), () => {
        doAdminResetPassword(userId, username);
    });
}

// Copie le mot de passe temporaire affiché dans #adminResetPasswordModal. navigator.clipboard.writeText()
// peut être refusé (permissions, contexte non sécurisé, etc.) : on l'attrape pour ne pas planter avec une
// promesse rejetée non gérée — l'input readonly (onclick="this.select()") reste le filet de sécurité manuel.
function copyAdminGeneratedPassword() {
    const pwd = Alpine.store('ui').adminGeneratedPassword.password;
    if (!navigator.clipboard || !pwd) return;
    navigator.clipboard.writeText(pwd)
        .then(() => Alpine.store('ui').showToast(T('admin_users_password_copied')))
        .catch(() => {});
}

async function doAdminResetPassword(userId, username) {
    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('target_user_id', userId);
        const res = await fetch('api.php?action=admin_reset_password', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'success') {
            Alpine.store('ui').adminGeneratedPassword = { username: username, password: data.password };
            openModal('adminResetPasswordModal');
        } else {
            Alpine.store('ui').showToast(data.message || T('err_password_change_network'));
        }
    } catch (e) {
        console.error(e);
        Alpine.store('ui').showToast(T('err_password_change_network'));
    }
}

function updateVolume(val) {
    if (!audio) return;
    // Remonter le volume à la main (au-dessus de 0) alors qu'on était en muet redonne le son, comme sur
    // la plupart des lecteurs — plus intuitif que de laisser le curseur sans effet audible.
    if (audio.muted && val > 0) audio.muted = false;
    audio.volume = val;
    if(desktopVol) desktopVol.value = val;
    if(settingsVol) settingsVol.value = val;
    if(dpVol) dpVol.value = val;
    localStorage.setItem('purpleMusicVolume', val);
    const percentage = val * 100;
    const bgStyle = `linear-gradient(90deg, var(--accent) ${percentage}%, rgba(255,255,255,0.2) ${percentage}%)`;
    if(desktopVol) desktopVol.style.background = bgStyle;
    if(settingsVol) settingsVol.style.background = bgStyle;
    if(dpVol) dpVol.style.background = bgStyle;
    refreshVolumeIcon();
}

// Bouton-icône (mini-barre + grand lecteur desktop) : coupe/rétablit le son via audio.muted plutôt qu'en
// mémorisant/écrasant audio.volume, pour que le volume réglé soit automatiquement restauré tel quel au
// dé-mute sans logique de sauvegarde séparée.
function toggleMute() {
    if (!audio) return;
    audio.muted = !audio.muted;
    refreshVolumeIcon();
}

// Reflète l'état muet/silencieux sur les deux icônes (mini-barre #vol-icon-desktop-vol, grand lecteur
// #vol-icon-dp-vol) — muet explicite (audio.muted) OU volume à 0 (curseur descendu au minimum) affichent
// tous les deux l'icône barrée, même si seul le premier cas mémorise vraiment un état "muet" togglable.
function refreshVolumeIcon() {
    if (!audio) return;
    const muted = audio.muted || audio.volume <= 0;
    const path = muted ? mutedVolumeIconPath : volumeIconPath;
    ['vol-icon-desktop-vol', 'vol-icon-dp-vol'].forEach(id => {
        const svg = document.getElementById(id);
        if (svg) svg.innerHTML = `<path d="${path}"/>`;
    });
}

if(desktopVol) desktopVol.addEventListener('input', (e) => updateVolume(e.target.value));
if(settingsVol) settingsVol.addEventListener('input', (e) => updateVolume(e.target.value));
if(dpVol) dpVol.addEventListener('input', (e) => updateVolume(e.target.value));

// --- ÉGALISEUR (onglet "Égaliseur" de la modale Paramètres) : 5 bandes fixes (EQ_BANDS, déclarées avec
// le graphe audio partagé plus haut) sur une plage bipolaire -12..+12 dB. Réglages persistés en
// localStorage (même convention que purpleMusicVolume/purpleMusicTheme) et réappliqués au chargement
// (restoreEqUI(), appelé depuis DOMContentLoaded plus bas) -- indépendamment du graphe audio lui-même,
// qui n'existe pas encore tant qu'aucune lecture/interaction n'a eu lieu (voir initAudioGraph()) : c'est
// pour ça qu'applyEqGains() vérifie eqFilters.length avant d'écrire dans les nœuds, et qu'initAudioGraph()
// rappelle applyEqGains() une fois le graphe construit pour rattraper l'état déjà en place dans l'UI.
function formatDb(v) {
    const num = Number(v) || 0;
    return (num > 0 ? '+' : '') + num.toFixed(1) + ' dB';
}

function updateEqSliderFill(slider) {
    if (!slider) return;
    const pct = ((parseFloat(slider.value) - EQ_MIN_DB) / (EQ_MAX_DB - EQ_MIN_DB)) * 100;
    slider.style.background = `linear-gradient(90deg, var(--accent) ${pct}%, rgba(255,255,255,0.2) ${pct}%)`;
}

function saveEqSettings() {
    const enableCb = document.getElementById('eq-enable-cb');
    const enabled = enableCb ? enableCb.checked : false;
    const gains = EQ_BANDS.map((_, i) => {
        const slider = document.getElementById('eq-band-' + i);
        return slider ? parseFloat(slider.value) : 0;
    });
    localStorage.setItem('purpleMusicEqEnabled', enabled ? '1' : '0');
    localStorage.setItem('purpleMusicEqBands', JSON.stringify(gains));
}

// Applique les valeurs actuellement affichées dans l'onglet Égaliseur aux BiquadFilterNode du graphe
// partagé -- gains à 0 (unité, chaîne inaudible) si l'égaliseur est désactivé, sans jamais débrancher les
// nœuds (plus simple que reconstruire la chaîne à chaque toggle, et le graphe reste partagé avec le
// visualiseur en aval).
function applyEqGains() {
    if (!eqFilters.length) return;
    const enableCb = document.getElementById('eq-enable-cb');
    const enabled = enableCb ? enableCb.checked : false;
    EQ_BANDS.forEach((_, i) => {
        const slider = document.getElementById('eq-band-' + i);
        const val = slider ? parseFloat(slider.value) : 0;
        if (eqFilters[i]) eqFilters[i].gain.value = enabled ? val : 0;
    });
}

function setEqControlsDisabled(disabled) {
    const bands = document.getElementById('eq-bands');
    if (bands) bands.classList.toggle('eq-disabled', disabled);
}

function setEqEnabled(enabled) {
    resumeAudioGraph();
    setEqControlsDisabled(!enabled);
    saveEqSettings();
    applyEqGains();
}

function setEqBand(i, val) {
    const slider = document.getElementById('eq-band-' + i);
    const label = document.getElementById('eq-band-' + i + '-val');
    if (label) label.textContent = formatDb(val);
    if (slider) updateEqSliderFill(slider);
    saveEqSettings();
    applyEqGains();
}

// Restaure l'état de l'onglet Égaliseur depuis localStorage -- appelé au chargement de la page (les
// éléments de la modale Paramètres existent dans le DOM dès le rendu serveur, masqués par x-cloak/x-show,
// donc getElementById fonctionne même modale fermée, même schéma que les cases à cocher de genres).
function restoreEqUI() {
    const enabled = localStorage.getItem('purpleMusicEqEnabled') === '1';
    let gains;
    try {
        gains = JSON.parse(localStorage.getItem('purpleMusicEqBands') || 'null');
    } catch (e) {
        gains = null;
    }
    if (!Array.isArray(gains) || gains.length !== EQ_BANDS.length) gains = EQ_BANDS.map(() => 0);

    const enableCb = document.getElementById('eq-enable-cb');
    if (enableCb) enableCb.checked = enabled;

    EQ_BANDS.forEach((_, i) => {
        const slider = document.getElementById('eq-band-' + i);
        const label = document.getElementById('eq-band-' + i + '-val');
        const val = gains[i] || 0;
        if (slider) { slider.value = val; updateEqSliderFill(slider); }
        if (label) label.textContent = formatDb(val);
    });

    setEqControlsDisabled(!enabled);
    applyEqGains();
}

// --- VISUALISEUR AUDIO --- Barres de spectre pilotées par le même AnalyserNode que l'égaliseur (fin de
// chaîne du graphe partagé, voir initAudioGraph() plus haut). Deux instances indépendantes (mobile/dp),
// chacune avec son propre id de boucle requestAnimationFrame pour pouvoir l'arrêter précisément (toggle,
// ou fermeture du lecteur concerné) sans jamais laisser de rAF tourner dans le vide en arrière-plan.
let vizRafMobile = null;
let vizRafDesktop = null;

// Bascule le réglage persistant (appelé depuis le toggle de Paramètres > Général -- plus de bouton par
// lecteur) et ré-applique immédiatement aux deux lecteurs selon lequel est ouvert en ce moment.
function setVisualizerEnabled(enabled) {
    resumeAudioGraph();
    if (!window.Alpine) return;
    Alpine.store('ui').visualizerEnabled = enabled;
    localStorage.setItem('purpleMusicVisualizerEnabled', enabled ? '1' : '0');
    applyVisualizerForContext('mobile');
    applyVisualizerForContext('desktop');
}

// Démarre/arrête la boucle rAF d'un lecteur donné selon : réglage activé + (pour mobile) paroles pas
// affichées (même zone, mutuellement exclusifs) + le lecteur concerné est bien celui actuellement ouvert.
// Appelé au changement du réglage ET à chaque ouverture de lecteur (le canvas peut redevenir visible sans
// que visualizerEnabled ait changé) -- x-show seul ne suffit pas, il cache le canvas mais n'arrête jamais
// la boucle qui continuerait de l'animer hors écran.
function applyVisualizerForContext(context) {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    if (context === 'mobile') {
        const fp = document.getElementById('full-player');
        const shouldRun = store.visualizerEnabled && !store.showLyricsInPlayer && fp && fp.classList.contains('active');
        if (shouldRun) startVisualizer('fp-visualizer-canvas', 'mobile');
        else stopVisualizer('mobile');
    } else {
        const dp = document.getElementById('desktop-player');
        const shouldRun = store.visualizerEnabled && dp && dp.classList.contains('active');
        if (shouldRun) startVisualizer('dp-visualizer-canvas', 'desktop');
        else stopVisualizer('desktop');
    }
}

function stopVisualizer(context) {
    if (context === 'mobile' && vizRafMobile !== null) {
        cancelAnimationFrame(vizRafMobile);
        vizRafMobile = null;
    }
    if (context === 'desktop' && vizRafDesktop !== null) {
        cancelAnimationFrame(vizRafDesktop);
        vizRafDesktop = null;
    }
}

function startVisualizer(canvasId, context) {
    stopVisualizer(context); // sécurité anti-double-boucle si appelé deux fois de suite
    const canvas = document.getElementById(canvasId);
    if (!canvas || !analyserNode) return;
    const ctx2d = canvas.getContext('2d');
    if (!ctx2d) return;

    const bufferLength = analyserNode.frequencyBinCount;
    const dataArray = new Uint8Array(bufferLength);
    const barColor = (getComputedStyle(document.documentElement).getPropertyValue('--accent') || '#BB86FC').trim();
    const barCount = 40;
    let lastW = 0, lastH = 0;

    // Répartition quasi-logarithmique des bins FFT par barre (courbe quadratique) au lieu d'un
    // échantillonnage à pas linéaire fixe : l'essentiel de l'énergie d'un morceau de musique est concentré
    // dans les basses/médiums (premiers bins), donc échantillonner à pas linéaire remplissait surtout les
    // barres de gauche et laissait celles de droite quasi plates en permanence (signalé en prod). Chaque
    // barre couvre maintenant une plage de bins qui s'élargit avec l'index (peu de bins graves par barre,
    // beaucoup de bins aigus regroupés par barre), et prend le MAX de sa plage plutôt qu'un bin isolé pour
    // rester réactive aux pics même sur une large plage regroupée.
    const barBinRanges = [];
    for (let i = 0; i < barCount; i++) {
        const lowBin = Math.floor(Math.pow(i / barCount, 2) * bufferLength);
        const highBin = Math.max(lowBin + 1, Math.floor(Math.pow((i + 1) / barCount, 2) * bufferLength));
        barBinRanges.push([lowBin, Math.min(highBin, bufferLength)]);
    }

    function resizeCanvas() {
        const rect = canvas.getBoundingClientRect();
        const dpr = window.devicePixelRatio || 1;
        const w = Math.round(rect.width * dpr);
        const h = Math.round(rect.height * dpr);
        if (w && h && (w !== lastW || h !== lastH)) {
            canvas.width = w;
            canvas.height = h;
            lastW = w;
            lastH = h;
        }
    }

    function draw() {
        const rafId = requestAnimationFrame(draw);
        if (context === 'mobile') vizRafMobile = rafId; else vizRafDesktop = rafId;

        resizeCanvas();
        if (!canvas.width || !canvas.height) return;
        analyserNode.getByteFrequencyData(dataArray);
        ctx2d.clearRect(0, 0, canvas.width, canvas.height);
        ctx2d.fillStyle = barColor;

        const barWidth = canvas.width / barCount;
        for (let i = 0; i < barCount; i++) {
            const [lowBin, highBin] = barBinRanges[i];
            let v = 0;
            for (let b = lowBin; b < highBin; b++) v = Math.max(v, dataArray[b] || 0);
            const barHeight = Math.max(2, (v / 255) * canvas.height);
            ctx2d.fillRect(i * barWidth + barWidth * 0.15, canvas.height - barHeight, barWidth * 0.7, barHeight);
        }
    }

    requestAnimationFrame(draw);
}

// --- MINUTEUR DE SOMMEIL --- Portage direct de MusicService.kt::startSleepTimer() côté Android (même
// app, même service). Session uniquement (jamais persisté) : un minuteur ne survit pas plus qu'un
// redémarrage de l'app là-bas, donc pas de localStorage ici non plus pour l'état actif lui-même.
// Comportement : > 30s de durée totale -> lecture normale jusqu'aux 30 dernières secondes, puis fondu du
// volume sur 30 paliers d'~1s (currentVolume * paliersRestants/30), puis pause, puis volume restauré à sa
// valeur d'avant fondu (sinon l'utilisateur resterait "coincé" à volume quasi nul à la prochaine lecture).
// <= 30s -> simple attente puis pause, pas de fondu (pas assez de temps pour un fondu utile). Démarrer un
// nouveau minuteur ou l'annuler restaure immédiatement le volume si un fondu était en cours (= preFadeVolume
// côté Android).
const SLEEP_TIMER_FADE_MS = 30 * 1000;
const SLEEP_TIMER_FADE_STEPS = 30;
let sleepTimerTimeouts = [];
let sleepTimerCountdownInterval = null;
let sleepTimerPreFadeVolume = null;
let sleepTimerDeadline = null; // Date.now() + durée totale, sert uniquement au décompte affiché

function clearSleepTimerTimers() {
    sleepTimerTimeouts.forEach(id => clearTimeout(id));
    sleepTimerTimeouts = [];
    if (sleepTimerCountdownInterval) {
        clearInterval(sleepTimerCountdownInterval);
        sleepTimerCountdownInterval = null;
    }
}

// Remet le volume à sa valeur d'avant fondu si un fondu était en cours — no-op sinon. Appelée à la fois
// en fin de minuteur normale (finishSleepTimer) et en annulation/redémarrage (cancelSleepTimer), exactement
// comme preFadeVolume?.let{} est appelé aux deux endroits dans la version Android.
function restoreSleepTimerVolume() {
    if (sleepTimerPreFadeVolume !== null) {
        updateVolume(sleepTimerPreFadeVolume);
        sleepTimerPreFadeVolume = null;
    }
}

function startSleepTimer(minutes) {
    cancelSleepTimer(); // annule un minuteur précédent + restaure un fondu en cours, comme côté Android

    minutes = parseFloat(minutes);
    if (!minutes || minutes <= 0) return;

    const totalMs = minutes * 60 * 1000;
    sleepTimerDeadline = Date.now() + totalMs;

    if (window.Alpine) {
        const store = Alpine.store('ui');
        store.sleepTimerActive = true;
        store.sleepTimerRemaining = Math.round(totalMs / 1000);
        // Ne mémorise le "dernier choisi" que pour les vrais préréglages (minutes entières) affichés dans
        // le popover — pas pour les valeurs fractionnaires utilisées lors des tests manuels en console.
        if (Number.isInteger(minutes)) {
            store.sleepTimerLastMinutes = minutes;
            localStorage.setItem('purpleMusicSleepTimerLastMinutes', String(minutes));
        }
    }

    // Décompte purement visuel (rafraîchi chaque seconde), indépendant du minuteur réel du fondu/pause ci-dessous.
    sleepTimerCountdownInterval = setInterval(() => {
        const remaining = Math.max(0, Math.round((sleepTimerDeadline - Date.now()) / 1000));
        if (window.Alpine) Alpine.store('ui').sleepTimerRemaining = remaining;
        if (remaining <= 0) {
            clearInterval(sleepTimerCountdownInterval);
            sleepTimerCountdownInterval = null;
        }
    }, 1000);

    if (totalMs > SLEEP_TIMER_FADE_MS) {
        sleepTimerTimeouts.push(setTimeout(runSleepTimerFade, totalMs - SLEEP_TIMER_FADE_MS));
    } else {
        sleepTimerTimeouts.push(setTimeout(finishSleepTimer, totalMs));
    }
}

function runSleepTimerFade() {
    sleepTimerPreFadeVolume = audio ? audio.volume : 1.0;
    const stepMs = SLEEP_TIMER_FADE_MS / SLEEP_TIMER_FADE_STEPS;

    for (let stepsRemaining = SLEEP_TIMER_FADE_STEPS; stepsRemaining >= 1; stepsRemaining--) {
        const delayMs = (SLEEP_TIMER_FADE_STEPS - stepsRemaining) * stepMs;
        sleepTimerTimeouts.push(setTimeout(() => {
            // Lecture déjà arrêtée manuellement entretemps : on n'écrase pas un volume que l'utilisateur
            // pourrait déjà avoir retouché, mais on laisse le minuteur suivre son cours (finishSleepTimer
            // restaurera quand même le volume à la fin, comme côté Android).
            if (!audio || audio.paused) return;
            updateVolume(sleepTimerPreFadeVolume * (stepsRemaining / SLEEP_TIMER_FADE_STEPS));
        }, delayMs));
    }

    sleepTimerTimeouts.push(setTimeout(finishSleepTimer, SLEEP_TIMER_FADE_MS));
}

function finishSleepTimer() {
    if (audio && !audio.paused) togglePlay(); // met en pause + met à jour les icônes play/pause partout
    restoreSleepTimerVolume();
    clearSleepTimerTimers();
    sleepTimerDeadline = null;
    if (window.Alpine) {
        Alpine.store('ui').sleepTimerActive = false;
        Alpine.store('ui').sleepTimerRemaining = 0;
    }
}

function cancelSleepTimer() {
    clearSleepTimerTimers();
    restoreSleepTimerVolume();
    sleepTimerDeadline = null;
    if (window.Alpine) {
        Alpine.store('ui').sleepTimerActive = false;
        Alpine.store('ui').sleepTimerRemaining = 0;
    }
}

// Choix depuis le sélecteur de préréglages (Paramètres > Général uniquement) : 0 = "Désactivé" -> annule.
function chooseSleepTimer(minutes) {
    if (!minutes || minutes <= 0) {
        cancelSleepTimer();
        // Un choix explicite sur "Désactivé" doit aussi effacer le repère "dernier préréglage" -- sinon
        // ce dernier préréglage restait coloré indéfiniment même après une désactivation volontaire,
        // ce qui se lit comme "encore actif" alors que rien ne tourne.
        if (window.Alpine) Alpine.store('ui').sleepTimerLastMinutes = 0;
        localStorage.removeItem('purpleMusicSleepTimerLastMinutes');
    } else {
        startSleepTimer(minutes);
    }
}

// Libellé compact affiché une fois le minuteur actif — arrondi à la minute
// supérieure (jamais "0 min" tant qu'il reste ne serait-ce que quelques secondes), volontairement moins
// "bavard" qu'un vrai mm:ss pour rester un indicateur léger plutôt qu'un gros décompte permanent.
function formatSleepTimerRemaining(totalSeconds) {
    const mins = Math.max(1, Math.ceil(totalSeconds / 60));
    return T('sleep_timer_short_minutes', { n: mins });
}

function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

let searchTimeout;
function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(filterAndSortTracks, 250);
}

function updateUrl() {
    const params = new URLSearchParams();
    if (currentSection !== 'accueil') params.set('page', currentSection);
    if (currentSection === 'browse' && browseSort) params.set('sort', browseSort);
    if (queue[currentIndex] && queue[currentIndex].id) params.set('v', queue[currentIndex].id);
    if (currentPlaylistId) params.set('list', currentPlaylistId);
    const newUrl = window.location.pathname + '?' + params.toString();
    window.history.pushState({ path: newUrl }, '', newUrl);
}

function toggleGenreSetting(genre, isChecked) {
    if (isChecked) {
        if (!hiddenGenres.includes(genre)) hiddenGenres.push(genre);
    } else {
        hiddenGenres = hiddenGenres.filter(g => g !== genre);
    }
    localStorage.setItem('hiddenGenres', JSON.stringify(hiddenGenres));
    filterAndSortTracks();
}

