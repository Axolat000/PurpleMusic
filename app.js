// --- LOGIQUE JAVASCRIPT PURPLE MUSIC ---

// --- ALPINE.JS : STORE GLOBAL UI (modales, sections, dialogue de confirmation, toast) ---
document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        activeModal: null,
        section: 'accueil',
        searchTerm: '',
        playlistDetail: null,
        recentTracks: [],
        popularTracks: [],
        playlistsPreview: [],
        confirmState: { open: false, message: '', onConfirm: null },
        toastState: { visible: false, message: '' },
        toastTimer: null,

        // --- THÈME VISUEL (preset par utilisateur, stocké en localStorage) ---
        themePreset: 'violet',

        // --- ADMIN PANEL (page dédiée) : mot de passe temporaire généré par "Réinitialiser" sur un
        // utilisateur, affiché une seule fois via #adminResetPasswordModal (voir adminResetPassword() plus bas). ---
        adminGeneratedPassword: { username: '', password: '' },

        // --- PAROLES (lrclib.net) : dans le lecteur plein écran (mobile, à la place de la pochette)
        // ou dans un panneau latéral droit (desktop, comme la file d'attente) ---
        showLyricsInPlayer: false,
        lyricsPanelOpen: false,
        lyricsLoading: false,
        lyricsFound: null, // null = pas encore chargé, true/false une fois la recherche faite
        lyricsTrackId: null,
        lyricsSynced: [], // [{ time: seconds, text: string }]
        lyricsPlain: '',
        lyricsActiveIndex: -1,

        // --- LECTEUR DESKTOP "GRAND ÉCRAN" (#desktop-player) : carrousel vertical à 3 positions.
        // 'player' = carte lecteur (vue par défaut) ; 'lyrics'/'queue' = cartes paroles/file d'attente.
        // Remise à 'player' à chaque ouverture/fermeture (voir openDesktopPlayer()/closeDesktopPlayer()).
        desktopPlayerView: 'player',

        // --- VISUALISEUR AUDIO (AnalyserNode partagé avec l'égaliseur, voir initAudioGraph() plus bas) :
        // remplace la pochette dans le lecteur plein écran mobile / la colonne pochette du lecteur desktop
        // tant qu'actif. Un seul réglage persistant (activé/désactivé, réglable uniquement dans Paramètres
        // > Général) plutôt qu'un bouton par lecteur -- appliqué automatiquement dans les deux lecteurs dès
        // qu'il est activé. La boucle requestAnimationFrame réelle (par lecteur) est démarrée/arrêtée par
        // applyVisualizerForContext() selon ce booléen + l'état d'ouverture du lecteur concerné, jamais par
        // simple réactivité x-show (qui ne fait que cacher le canvas, pas arrêter la boucle qui l'anime).
        visualizerEnabled: false,

        // --- THÈME DYNAMIQUE (Paramètres > Général uniquement) : surcouche PAR-DESSUS le preset statique
        // actif (violet/amoled/midnight/forest/crimson, voir THEME_PRESETS plus bas) -- pas un remplacement.
        // Désactivé par défaut. Quand actif, --fp-gradient-1/2 sont recalculées à chaque changement de piste
        // à partir des couleurs dominante/vibrante extraites de la pochette (voir applyDynamicThemeForCurrentTrack()
        // / setDynamicThemeEnabled(), appelé depuis loadTrack()) au lieu de garder les valeurs figées du preset.
        dynamicThemeEnabled: false,

        // --- MINUTEUR DE SOMMEIL (Paramètres > Général uniquement -- pas de bouton dans les lecteurs).
        // sleepTimerActive/Remaining : un seul minuteur réel peut tourner à la fois (voir
        // startSleepTimer()/cancelSleepTimer(), hors store, en mémoire seulement -- pas de persistance de
        // l'état actif, comme côté Android). sleepTimerLastMinutes est la seule chose qu'on retient
        // (localStorage) : juste pour marquer visuellement le dernier préréglage choisi.
        sleepTimerActive: false,
        sleepTimerRemaining: 0, // secondes restantes, décompte affiché arrondi à la minute supérieure
        sleepTimerLastMinutes: 0,

        // --- MISE À JOUR (popup admin) : vérifie une fois par vrai chargement de page (voir init()),
        // résultat mis en cache côté client (sessionStorage) en plus du cache serveur (1h) pour éviter
        // tout appel réseau superflu. Le "dismiss" (Plus tard) est aussi en sessionStorage : suspendu
        // pour la session du navigateur en cours, pas pour toujours (voir dismissUpdateNotice()).
        updateCheck: { checked: false, available: false, watchtowerConfigured: false },
        updateDismissedThisSession: false,
        updateTriggering: false,
        updateTriggerState: null, // null | 'updating' | 'error'
        updateTriggerError: '',

        init() {
            if (typeof ALL_MUSIC_DATA !== 'undefined') {
                this.recentTracks = [...ALL_MUSIC_DATA].sort((a, b) => b.id - a.id).slice(0, 10);
                this.popularTracks = [...ALL_MUSIC_DATA]
                    .filter(t => (parseInt(t.play_count) || 0) > 0)
                    .sort((a, b) => (parseInt(b.play_count) || 0) - (parseInt(a.play_count) || 0))
                    .slice(0, 10);
            }
            if (typeof ALL_PLAYLISTS_DATA !== 'undefined') {
                this.playlistsPreview = ALL_PLAYLISTS_DATA.slice(0, 10);
            }
            this.themePreset = localStorage.getItem('purpleMusicTheme') || 'violet';
            this.sleepTimerLastMinutes = parseInt(localStorage.getItem('purpleMusicSleepTimerLastMinutes') || '0', 10) || 0;
            this.visualizerEnabled = localStorage.getItem('purpleMusicVisualizerEnabled') === '1';
            this.dynamicThemeEnabled = localStorage.getItem('purpleMusicDynamicThemeEnabled') === '1';

            // Vérif de mise à jour : uniquement pour un admin connecté (IS_ADMIN/CURRENT_USER_ID sont
            // injectés par index.php, absents/false sur la page de connexion). init() ne tourne qu'une
            // fois par vrai chargement de page (pas de routing client dans cette app), donc pas besoin
            // de protection supplémentaire contre un refire lors des changements de section.
            if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN && typeof CURRENT_USER_ID !== 'undefined' && CURRENT_USER_ID) {
                if (sessionStorage.getItem('pmUpdateDismissed') === '1') this.updateDismissedThisSession = true;
                this.checkForUpdate();
            }
        },

        async checkForUpdate() {
            try {
                const cachedRaw = sessionStorage.getItem('pmUpdateCheckResult');
                if (cachedRaw) {
                    const cached = JSON.parse(cachedRaw);
                    if (cached && typeof cached.ts === 'number' && (Date.now() - cached.ts) < 10 * 60 * 1000) {
                        this.applyUpdateCheckResult(cached.data);
                        return;
                    }
                }
            } catch (e) { /* cache client corrompu : on ignore et on retente une vraie requête */ }

            try {
                const res = await fetch('?check_update=1');
                const data = await res.json();
                sessionStorage.setItem('pmUpdateCheckResult', JSON.stringify({ ts: Date.now(), data }));
                this.applyUpdateCheckResult(data);
            } catch (e) {
                // Échec réseau/API : jamais d'erreur visible pour un simple check en arrière-plan.
                console.error('Update check failed', e);
            }
        },

        applyUpdateCheckResult(data) {
            this.updateCheck = {
                checked: !!(data && data.checked),
                available: !!(data && data.update_available),
                watchtowerConfigured: !!(data && data.watchtower_configured),
            };
            if (this.updateCheck.available && !this.updateDismissedThisSession) {
                this.openModal('updateAvailableModal');
            }
        },

        dismissUpdateNotice() {
            this.updateDismissedThisSession = true;
            sessionStorage.setItem('pmUpdateDismissed', '1');
            this.closeModal('updateAvailableModal');
        },

        async triggerUpdate() {
            this.updateTriggering = true;
            this.updateTriggerState = null;
            this.updateTriggerError = '';
            try {
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('trigger_update', '1');
                const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    this.updateTriggerState = 'updating';
                    this.attemptReloadAfterUpdate();
                } else {
                    this.updateTriggerState = 'error';
                    this.updateTriggerError = data.message || T('err_update_trigger_failed');
                    if (data.manual) this.updateCheck.watchtowerConfigured = false;
                }
            } catch (e) {
                // Watchtower ne répond qu'une fois le remplacement du conteneur terminé (stop+pull+start) —
                // or c'est justement CE conteneur (celui qui exécute cette requête PHP) qui se fait arrêter
                // en plein milieu. Le fetch() échoue quasi systématiquement (connexion coupée) même quand
                // le déclenchement a parfaitement réussi : un échec réseau ici veut donc dire "probablement
                // en train de mettre à jour", pas "échec" — on suit le même chemin que le succès plutôt que
                // d'afficher une fausse erreur.
                this.updateTriggerState = 'updating';
                this.attemptReloadAfterUpdate();
            } finally {
                this.updateTriggering = false;
            }
        },

        // Le conteneur redémarre après le déclenchement Watchtower : on attend avant de recharger, avec
        // plusieurs tentatives à intervalle croissant (le serveur peut être brièvement injoignable pendant
        // le pull + la recréation du conteneur) plutôt qu'un simple reload() qui tomberait sur une erreur.
        attemptReloadAfterUpdate(attempt = 0) {
            const delays = [5000, 5000, 8000, 8000, 10000, 10000];
            if (attempt >= delays.length) { window.location.reload(); return; }
            setTimeout(() => {
                fetch(window.location.pathname, { method: 'HEAD', cache: 'no-store' })
                    .then(() => window.location.reload())
                    .catch(() => this.attemptReloadAfterUpdate(attempt + 1));
            }, delays[attempt]);
        },

        openModal(id) { this.activeModal = id; },
        closeModal(id) { if (!id || this.activeModal === id) this.activeModal = null; },

        confirmAction(message, onConfirm) {
            this.confirmState = { open: true, message, onConfirm };
        },
        confirmYes() {
            const cb = this.confirmState.onConfirm;
            this.confirmState = { open: false, message: '', onConfirm: null };
            if (cb) cb();
        },
        confirmNo() {
            this.confirmState = { open: false, message: '', onConfirm: null };
        },

        showToast(message, duration = 3000) {
            clearTimeout(this.toastTimer);
            this.toastState = { visible: true, message };
            this.toastTimer = setTimeout(() => { this.toastState.visible = false; }, duration);
        }
    });

    // --- Composant réutilisable "Netflix-row" : chevrons gauche/droite pour les rangées d'accueil ---
    // Utilisé sur les 3 rangées (récents / populaires / mixs) via x-data="homeRowScroller()" + x-ref="scrollEl".
    Alpine.data('homeRowScroller', () => ({
        canLeft: false,
        canRight: false,
        init() {
            this.$nextTick(() => this.update());
            window.addEventListener('resize', () => this.update());
        },
        update() {
            const el = this.$refs.scrollEl;
            if (!el) return;
            this.canLeft = el.scrollLeft > 4;
            this.canRight = el.scrollLeft < (el.scrollWidth - el.clientWidth - 4);
        },
        onScroll() { this.update(); },
        scrollDir(dir) {
            const el = this.$refs.scrollEl;
            if (!el) return;
            el.scrollBy({ left: dir * 300, behavior: 'smooth' });
        }
    }));

    // --- Modale Paramètres : onglets (Général / Bibliothèque / Compte) + formulaire de changement
    // de mot de passe (self-service). x-data posé sur .modal-content, donc l'état (onglet actif,
    // champs du formulaire) survit à l'ouverture/fermeture de la modale et aux changements d'onglet
    // (x-show masque juste le panneau, il ne détruit rien du DOM/composant).
    Alpine.data('settingsModalForm', () => ({
        activeTab: 'general',
        pwCurrent: '',
        pwNew: '',
        pwConfirm: '',
        pwError: '',
        pwSubmitting: false,
        async submitPasswordChange() {
            this.pwError = '';
            this.pwSubmitting = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('change_password', '1');
                fd.append('current_password', this.pwCurrent);
                fd.append('new_password', this.pwNew);
                fd.append('confirm_password', this.pwConfirm);
                const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    this.pwCurrent = '';
                    this.pwNew = '';
                    this.pwConfirm = '';
                    Alpine.store('ui').showToast(data.message);
                } else {
                    this.pwError = data.message || T('err_password_change_network');
                }
            } catch (e) {
                console.error(e);
                this.pwError = T('err_password_change_network');
            } finally {
                this.pwSubmitting = false;
            }
        }
    }));

    // --- Admin Panel (page dédiée, x-data posé sur <main id="admin">) : gère uniquement l'onglet actif
    // (Général / Thème / Médias / Genres / Utilisateurs). initialTab vient d'index.php (paramètre d'URL
    // ?admin_tab=..., utilisé pour rester sur le même onglet après un redirect suite à une action utilisateur).
    Alpine.data('adminPageForm', (initialTab) => ({
        activeTab: initialTab || 'general'
    }));

    // --- Page de connexion / inscription (x-data posé sur .auth-page) : bascule entre les deux modes via
    // les onglets .settings-tabs (au lieu de l'ancien second bouton "Créer un compte" en bas du formulaire,
    // facilement confondu avec un bouton secondaire) + validation client avant envoi. Le formulaire reste un
    // vrai POST classique vers auth.php (pas de fetch) : on ne bloque la soumission native que si la
    // validation échoue, sinon le flux serveur existant (redirect / rendu de l'erreur pleine page) est
    // inchangé. initialMode/initialUsername viennent d'index.php : après un échec côté serveur, on rouvre sur
    // le même mode que la tentative avec le nom d'utilisateur repré-rempli (jamais le mot de passe).
    Alpine.data('authForm', (initialMode, initialUsername) => ({
        mode: initialMode || 'login',
        username: initialUsername || '',
        password: '',
        confirmPassword: '',
        clientError: '',
        showServerError: true, // masqué dès qu'on change de mode : une erreur de connexion n'a plus de sens une fois basculé sur inscription (et inversement)
        switchMode(m) {
            this.mode = m;
            this.clientError = '';
            this.showServerError = false;
        },
        onSubmit(event) {
            this.clientError = '';
            if (this.mode !== 'register') return; // le mode connexion n'a pas de validation client à faire
            if (this.username.trim() === '') {
                this.clientError = T('err_username_required');
                event.preventDefault();
                return;
            }
            if (this.password.length < 6) {
                this.clientError = T('err_password_too_short');
                event.preventDefault();
                return;
            }
            if (this.password !== this.confirmPassword) {
                this.clientError = T('err_password_mismatch');
                event.preventDefault();
            }
        }
    }));

    // --- Scroll des paroles synchronisées : suit la ligne en cours automatiquement, mais se
    // met en pause dès que l'utilisateur scrolle lui-même (wheel/touch — pas l'événement "scroll"
    // générique, qui se déclenche aussi pour le scrollIntoView() automatique et empêcherait de
    // distinguer scroll manuel et scroll programmatique). Reprend après 10s d'inactivité ou au clic
    // sur "Revenir au direct". Utilisé sur #lyrics-panel (desktop), .fp-lyrics-view (mobile) et la
    // carte "paroles" du carrousel desktop (#desktop-player).
    //
    // isActiveFn (optionnel) : les 3 surfaces ci-dessus ne sont pas toujours retirées du DOM quand
    // elles ne sont pas affichées (la carte du carrousel reste montée en permanence, juste déplacée
    // hors-écran via transform) — donc son minuteur de reprise continue de tourner même quand on est
    // passé sur un autre onglet. Sans garde, il finissait par déclencher $el.scrollIntoView() sur une
    // ligne techniquement toujours dans le DOM mais visuellement hors-écran, ce qui faisait sauter le
    // scroll de la page entière (vécu en prod : "grosse merde" en changeant d'onglet en pleine pause
    // de scroll manuel). isActiveFn permet à chaque point de montage de dire si SA vue est bien celle
    // actuellement affichée ; sans lui, le composant se comporte comme avant (toujours actif).
    Alpine.data('lyricsScroller', (isActiveFn) => ({
        manualScroll: false,
        resumeTimer: null,
        get isActive() { return typeof isActiveFn === 'function' ? !!isActiveFn() : true; },
        userInteracted() {
            this.manualScroll = true;
            clearTimeout(this.resumeTimer);
            this.resumeTimer = setTimeout(() => { this.manualScroll = false; }, 10000);
        },
        backToLive() {
            clearTimeout(this.resumeTimer);
            this.manualScroll = false;
            this.$nextTick(() => {
                const activeEl = this.$el.querySelector('.lyrics-line.active');
                if (activeEl) activeEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        },
        destroy() { clearTimeout(this.resumeTimer); }
    }));
});

// --- I18N (JS) : traduit les chaînes générées dynamiquement (rendu côté client) ---
// I18N_CLIENT et LANG sont injectés par index.php (voir <script> inline avant app.js).
// Nommée T() (et non t()) car `t` est déjà utilisé partout dans ce fichier comme nom de variable "track".
function T(key, vars = {}) {
    const table = (typeof I18N_CLIENT !== 'undefined') ? I18N_CLIENT : null;
    const lang = (typeof LANG !== 'undefined') ? LANG : 'fr';
    let str = (table && table[lang] && table[lang][key]) || (table && table.fr && table.fr[key]) || key;
    Object.keys(vars).forEach(k => { str = str.replace('{' + k + '}', vars[k]); });
    return str;
}

// Pont générique pour les liens/boutons de suppression : remplace window.confirm() par le dialogue Alpine.
function confirmDelete(message, url) {
    if (window.Alpine) {
        Alpine.store('ui').confirmAction(message, () => { window.location.href = url; });
    } else if (confirm(message)) {
        window.location.href = url;
    }
    return false;
}

const audio = document.getElementById('mainAudio');
const progressBar = document.getElementById('progress-bar');
const progressArea = document.getElementById('progress-area');
const masterPlay = document.getElementById('masterPlay');
const playTitle = document.getElementById('play-title');
const playCover = document.getElementById('player-cover');
const playStatus = document.getElementById('play-status');
const queueList = document.getElementById('queue-list');
const queuePanel = document.getElementById('queue-panel');
const dpQueueList = document.getElementById('dp-queue-list');

// --- GRAPHE AUDIO PARTAGÉ (Égaliseur + Visualiseur) ---
// AudioContext.createMediaElementSource(audio) ne peut être appelé qu'UNE SEULE fois sur toute la durée
// de vie de <audio id="mainAudio"> -- un 2e appel lève une exception et casse la lecture. L'égaliseur (5
// BiquadFilterNode peaking) et le visualiseur (AnalyserNode pour la FFT) doivent donc partager le MÊME
// graphe plutôt que d'en construire chacun le leur :
//   audio -> sourceNode -> eqFilters[0..4] (chaîne) -> analyserNode -> audioCtx.destination
// Construit paresseusement (initAudioGraph(), idempotent) au premier geste utilisateur qui en a besoin
// (togglePlay(), activation de l'égaliseur ou du visualiseur) -- jamais au chargement de la page, les
// navigateurs exigeant une interaction utilisateur avant de démarrer un AudioContext.
let audioCtx = null;
let sourceNode = null;
let eqFilters = [];
let analyserNode = null;
const EQ_BANDS = [60, 230, 910, 3600, 14000]; // Hz -- calqué sur les presets courants d'android.media.audiofx.Equalizer
const EQ_MIN_DB = -12;
const EQ_MAX_DB = 12;

function initAudioGraph() {
    if (audioCtx || !audio) return;
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        audioCtx = new AudioContextClass();
        sourceNode = audioCtx.createMediaElementSource(audio);

        eqFilters = EQ_BANDS.map((freq) => {
            const filter = audioCtx.createBiquadFilter();
            filter.type = 'peaking';
            filter.frequency.value = freq;
            filter.Q.value = 1;
            filter.gain.value = 0;
            return filter;
        });

        analyserNode = audioCtx.createAnalyser();
        analyserNode.fftSize = 256;
        analyserNode.smoothingTimeConstant = 0.75;

        // Chaîne : source -> eq[0] -> eq[1] -> ... -> eq[4] -> analyser -> destination
        let node = sourceNode;
        eqFilters.forEach((filter) => {
            node.connect(filter);
            node = filter;
        });
        node.connect(analyserNode);
        analyserNode.connect(audioCtx.destination);

        applyEqGains();
    } catch (e) {
        console.error('initAudioGraph failed', e);
    }
}

function resumeAudioGraph() {
    initAudioGraph();
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
}

let CURRENT_VIEW_DATA = [];
let renderedCount = 0;
const RENDER_CHUNK = 30;
let originalQueue = [];
let queue = [];
let currentIndex = 0;
let loopMode = 0;
let isShuffle = false;
let currentPlaylistId = null;
let currentSection = 'accueil';
let hiddenGenres = JSON.parse(localStorage.getItem('hiddenGenres') || '[]');

// --- THÈMES VISUELS (presets par utilisateur, appliqués par-dessus les couleurs admin/BDD) ---
// "violet" = pas de surcharge : on garde les couleurs configurées par l'admin (comportement par défaut).
// Les autres presets fixent l'intégralité des variables CSS avec des valeurs figées (cohérence avec l'app Android).
const THEME_VAR_NAMES = [
    '--bg-dark', '--bg-panel', '--primary', '--accent', '--text', '--text-muted',
    '--border-color', '--search-bg', '--header-bg', '--player-bg', '--mob-nav-bg',
    '--fp-gradient-1', '--fp-gradient-2'
];
const THEME_PRESETS = {
    // "amoled" existait seul à l'origine ; désormais scindé en variante Purple (valeurs identiques,
    // inchangées) + variante White (même fond quasi-noir, accent neutre/blanc). "amoled" reste défini
    // plus bas comme alias de "amoled_purple" pour ne pas casser un localStorage['purpleMusicTheme']
    // existant chez un utilisateur déjà sur ce preset avant l'ajout des variantes.
    amoled_purple: {
        '--bg-dark': '#000000', '--bg-panel': '#0A0A0A', '--primary': '#7B2CBF', '--accent': '#B388FF',
        '--text': '#E8E8E8', '--text-muted': '#9E9E9E', '--border-color': '#2A2A2A', '--search-bg': '#141414',
        '--header-bg': 'rgba(0,0,0,0.85)', '--player-bg': 'rgba(10,10,10,0.85)', '--mob-nav-bg': 'rgba(0,0,0,0.95)',
        '--fp-gradient-1': '#1A1A1A', '--fp-gradient-2': '#000000'
    },
    amoled_white: {
        '--bg-dark': '#000000', '--bg-panel': '#0A0A0A', '--primary': '#4D4D4D', '--accent': '#E8E8E8',
        '--text': '#F0F0F0', '--text-muted': '#9E9E9E', '--border-color': '#2A2A2A', '--search-bg': '#141414',
        '--header-bg': 'rgba(0,0,0,0.85)', '--player-bg': 'rgba(10,10,10,0.85)', '--mob-nav-bg': 'rgba(0,0,0,0.95)',
        '--fp-gradient-1': '#262626', '--fp-gradient-2': '#000000'
    },
    // Idem pour "midnight" -> "midnight_blue" (inchangé) + nouvelle variante "midnight_silver" (même
    // famille de fond, primary/accent neutres gris-bleu au lieu de bleu vif). "midnight" reste un alias
    // de "midnight_blue" plus bas, même logique de compatibilité ascendante que pour amoled.
    midnight_blue: {
        '--bg-dark': '#0B1120', '--bg-panel': '#161F35', '--primary': '#3B5BDB', '--accent': '#7C9BFF',
        '--text': '#E3E7F5', '--text-muted': '#8D97B8', '--border-color': '#2A3655', '--search-bg': '#1C2740',
        '--header-bg': 'rgba(14,21,38,0.85)', '--player-bg': 'rgba(22,31,53,0.85)', '--mob-nav-bg': 'rgba(14,21,38,0.95)',
        '--fp-gradient-1': '#1B2A4D', '--fp-gradient-2': '#0B1120'
    },
    midnight_silver: {
        '--bg-dark': '#0B1120', '--bg-panel': '#161F35', '--primary': '#6B7A99', '--accent': '#C7D0E0',
        '--text': '#E3E7F5', '--text-muted': '#8D97B8', '--border-color': '#2A3655', '--search-bg': '#1C2740',
        '--header-bg': 'rgba(14,21,38,0.85)', '--player-bg': 'rgba(22,31,53,0.85)', '--mob-nav-bg': 'rgba(14,21,38,0.95)',
        '--fp-gradient-1': '#2B3550', '--fp-gradient-2': '#0B1120'
    },
    forest: {
        '--bg-dark': '#0D1811', '--bg-panel': '#16261C', '--primary': '#2E7D4F', '--accent': '#6FCF97',
        '--text': '#DCEAE1', '--text-muted': '#8FA396', '--border-color': '#24392C', '--search-bg': '#1C2F22',
        '--header-bg': 'rgba(15,29,20,0.85)', '--player-bg': 'rgba(22,38,28,0.85)', '--mob-nav-bg': 'rgba(15,29,20,0.95)',
        '--fp-gradient-1': '#1D3324', '--fp-gradient-2': '#0D1811'
    },
    crimson: {
        '--bg-dark': '#1A0E0E', '--bg-panel': '#2A1616', '--primary': '#B33A3A', '--accent': '#FF6B6B',
        '--text': '#F5DEDE', '--text-muted': '#B09090', '--border-color': '#3D2020', '--search-bg': '#331A1A',
        '--header-bg': 'rgba(30,16,16,0.85)', '--player-bg': 'rgba(42,22,22,0.85)', '--mob-nav-bg': 'rgba(30,16,16,0.95)',
        '--fp-gradient-1': '#34191A', '--fp-gradient-2': '#1A0E0E'
    },
    // --- Nouveaux presets ---
    ocean: {
        '--bg-dark': '#071B1E', '--bg-panel': '#0F2A2E', '--primary': '#0E8388', '--accent': '#4DD8D0',
        '--text': '#DFF5F3', '--text-muted': '#86A8A6', '--border-color': '#1C3A3D', '--search-bg': '#102528',
        '--header-bg': 'rgba(7,27,30,0.85)', '--player-bg': 'rgba(15,42,46,0.85)', '--mob-nav-bg': 'rgba(7,27,30,0.95)',
        '--fp-gradient-1': '#123B3E', '--fp-gradient-2': '#071B1E'
    },
    sunset: {
        '--bg-dark': '#1A1108', '--bg-panel': '#2B1B0E', '--primary': '#D9822B', '--accent': '#FFB86B',
        '--text': '#F7E8D4', '--text-muted': '#B99B7C', '--border-color': '#3D2A16', '--search-bg': '#241708',
        '--header-bg': 'rgba(26,17,8,0.85)', '--player-bg': 'rgba(43,27,14,0.85)', '--mob-nav-bg': 'rgba(26,17,8,0.95)',
        '--fp-gradient-1': '#3D2610', '--fp-gradient-2': '#1A1108'
    },
    rose: {
        '--bg-dark': '#1A0E16', '--bg-panel': '#2A1622', '--primary': '#C2478D', '--accent': '#FF8FC7',
        '--text': '#F5DCEB', '--text-muted': '#B08CA6', '--border-color': '#3D2032', '--search-bg': '#24141F',
        '--header-bg': 'rgba(26,14,22,0.85)', '--player-bg': 'rgba(42,22,34,0.85)', '--mob-nav-bg': 'rgba(26,14,22,0.95)',
        '--fp-gradient-1': '#3A1A2C', '--fp-gradient-2': '#1A0E16'
    },
    slate: {
        '--bg-dark': '#121417', '--bg-panel': '#1C1F24', '--primary': '#5C6773', '--accent': '#9BA8B5',
        '--text': '#E4E7EA', '--text-muted': '#8C949C', '--border-color': '#2A2E34', '--search-bg': '#191C20',
        '--header-bg': 'rgba(18,20,23,0.85)', '--player-bg': 'rgba(28,31,36,0.85)', '--mob-nav-bg': 'rgba(18,20,23,0.95)',
        '--fp-gradient-1': '#262A30', '--fp-gradient-2': '#121417'
    }
};
// Alias de compatibilité ascendante : un utilisateur avec 'amoled'/'midnight' déjà en localStorage
// (avant la scission en variantes) doit continuer à retomber sur exactement les mêmes couleurs.
THEME_PRESETS.amoled = THEME_PRESETS.amoled_purple;
THEME_PRESETS.midnight = THEME_PRESETS.midnight_blue;

const CUSTOM_THEME_STORAGE_KEY = 'purpleMusicCustomTheme';

// Applique (ou retire) les surcharges de variables CSS sur <html>. "violet" = retour aux couleurs admin/BDD.
// "custom" = thème personnalisé de l'utilisateur (voir section THÈME PERSONNALISÉ plus bas), lu depuis
// localStorage plutôt que depuis l'objet statique THEME_PRESETS.
function setThemeVars(name) {
    const root = document.documentElement;
    if (name === 'custom') {
        const custom = getCustomThemeVars();
        THEME_VAR_NAMES.forEach(v => {
            if (custom[v]) root.style.setProperty(v, custom[v]);
            else root.style.removeProperty(v);
        });
        return;
    }
    const preset = THEME_PRESETS[name];
    if (!preset) {
        THEME_VAR_NAMES.forEach(v => root.style.removeProperty(v));
        return;
    }
    Object.entries(preset).forEach(([k, v]) => root.style.setProperty(k, v));
}

// Applique un preset de thème (ou 'custom'), le persiste en localStorage (par navigateur/utilisateur) et
// met à jour l'UI (swatch actif).
function applyThemePreset(name) {
    if (!THEME_PRESETS[name] && name !== 'violet' && name !== 'custom') return;
    localStorage.setItem('purpleMusicTheme', name);
    setThemeVars(name);
    if (window.Alpine) Alpine.store('ui').themePreset = name;
}

// Application la plus précoce possible (avant même Alpine) pour éviter un flash des couleurs par défaut.
setThemeVars(localStorage.getItem('purpleMusicTheme') || 'violet');

// --- THÈME PERSONNALISÉ (constructeur dans Paramètres > Général) ---
// Contrairement aux presets statiques ci-dessus, le thème personnalisé est un objet {varName: '#hex'}
// stocké en localStorage (CUSTOM_THEME_STORAGE_KEY) et édité via des <input type="color"> (voir
// index.php, bloc "custom-theme-builder"). Écriture "live" : chaque changement de couleur persiste
// immédiatement et active 'custom' comme preset courant (pas de bouton Enregistrer séparé), pour un
// aperçu instantané cohérent avec le reste des réglages de Paramètres.
function getCustomThemeVars() {
    try {
        return JSON.parse(localStorage.getItem(CUSTOM_THEME_STORAGE_KEY)) || {};
    } catch (e) {
        return {};
    }
}

// '--fp-gradient-1' -> 'custom-color-fp-gradient-1' (id des <input type="color"> dans index.php).
function customThemeInputId(varName) {
    return 'custom-color-' + varName.replace(/^--/, '');
}

// Convertit une couleur CSS quelconque (hex 3/6, rgb()/rgba()) en hex 6 chiffres exploitable par un
// <input type="color"> (qui n'accepte que #rrggbb). Réutilise parseColorToRgb (plus bas dans ce fichier,
// disponible ici grâce au hoisting des déclarations `function`). L'alpha des rgba() (header/player/
// mob-nav-bg) est perdu au passage -- limitation acceptée (voir commentaire dans index.php).
function colorToHex(str) {
    const rgb = parseColorToRgb(str);
    if (!rgb) return null;
    return '#' + rgb.map(c => Math.max(0, Math.min(255, c)).toString(16).padStart(2, '0')).join('');
}

// Appelé par chaque <input type="color" oninput="..."> du constructeur : persiste la variable modifiée,
// réapplique tout le thème personnalisé (pour que les variables pas encore personnalisées restent
// cohérentes) et bascule immédiatement le preset actif sur 'custom'.
function updateCustomThemeColor(varName, hexValue) {
    const custom = getCustomThemeVars();
    custom[varName] = hexValue;
    localStorage.setItem(CUSTOM_THEME_STORAGE_KEY, JSON.stringify(custom));
    applyThemePreset('custom');
}

// Repart des couleurs actuellement affichées (preset en cours, quel qu'il soit) comme point de départ du
// thème personnalisé : remplit les <input type="color"> ET active 'custom' avec ces valeurs. Appelé par
// le bouton "Partir du thème actuel" ainsi qu'automatiquement au premier clic sur le swatch "Personnalisé"
// tant qu'aucun thème personnalisé n'a encore été enregistré (voir activateCustomTheme()).
function prefillCustomThemeFromCurrent() {
    const computed = getComputedStyle(document.documentElement);
    const custom = {};
    THEME_VAR_NAMES.forEach(v => {
        const hex = colorToHex(computed.getPropertyValue(v).trim()) || '#000000';
        custom[v] = hex;
        const input = document.getElementById(customThemeInputId(v));
        if (input) input.value = hex;
    });
    localStorage.setItem(CUSTOM_THEME_STORAGE_KEY, JSON.stringify(custom));
    applyThemePreset('custom');
}

// Swatch "Personnalisé" de la rangée de thèmes : première activation -> pré-remplissage automatique
// depuis le thème actuellement affiché (meilleure expérience que de basculer sur du noir/violet par
// défaut) ; activations suivantes -> simple réapplication du thème personnalisé déjà enregistré.
function activateCustomTheme() {
    if (Object.keys(getCustomThemeVars()).length === 0) prefillCustomThemeFromCurrent();
    else applyThemePreset('custom');
}

// Initialise les valeurs des <input type="color"> du constructeur à l'ouverture de la page, à partir des
// couleurs actuellement résolues (déjà correctes à ce stade, qu'il s'agisse des couleurs admin/BDD, d'un
// preset ou d'un thème personnalisé précédemment activé -- setThemeVars() a déjà tourné plus haut).
function initCustomThemeBuilder() {
    const computed = getComputedStyle(document.documentElement);
    THEME_VAR_NAMES.forEach(v => {
        const input = document.getElementById(customThemeInputId(v));
        if (!input) return;
        input.value = colorToHex(computed.getPropertyValue(v).trim()) || '#000000';
    });
}

// --- THÈME DYNAMIQUE (surcouche par piste, PAR-DESSUS le preset statique actif -- voir THEME_PRESETS
// ci-dessus, qu'on ne touche pas ici) : extrait une couleur dominante et une couleur vibrante de la
// pochette de la piste en cours (Canvas 2D, aucune librairie externe) et anime --fp-gradient-1/2 vers ces
// couleurs, à la place des valeurs figées du preset. Portage du comportement de l'app Android
// (Palette API + animateColorAsState(tween(1000)) dans NowPlayingScreen.kt) : réglage désactivé par défaut
// (Paramètres > Général), persistant en localStorage (purpleMusicDynamicThemeEnabled, même convention que
// purpleMusicEqEnabled/purpleMusicVisualizerEnabled). Tant échec d'extraction (pas de pochette, erreur de
// chargement, canvas "tainted"...) que réglage désactivé -> --fp-gradient-1/2 ne sont jamais touchées et le
// preset statique garde la main entière, comme avant cette fonctionnalité.
let dynamicThemeRaf = null;

// Parse une couleur CSS ("#rgb", "#rrggbb", "rgb()"/"rgba()") en triplet [r,g,b] -- getComputedStyle()
// renvoie généralement du "rgb(...)" pour une valeur qu'on a nous-même écrite via setProperty(), et du hex
// littéral pour les valeurs venant de THEME_PRESETS ou du <style> injecté par index.php (couleurs admin) :
// on doit donc gérer les deux formats pour pouvoir repartir de la valeur actuellement affichée.
function parseColorToRgb(str) {
    if (!str) return null;
    str = str.trim();
    let m = str.match(/^#([0-9a-f]{3})$/i);
    if (m) {
        const h = m[1];
        return [parseInt(h[0] + h[0], 16), parseInt(h[1] + h[1], 16), parseInt(h[2] + h[2], 16)];
    }
    m = str.match(/^#([0-9a-f]{6})$/i);
    if (m) {
        const h = m[1];
        return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
    }
    m = str.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i);
    if (m) return [Math.round(parseFloat(m[1])), Math.round(parseFloat(m[2])), Math.round(parseFloat(m[3]))];
    return null;
}

function rgbToCss(rgb) {
    return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
}

// RGB (0..255) -> HSL (h en degrés, s/l en 0..1) -- sert uniquement à repérer le pixel le plus "vibrant".
function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    const l = (max + min) / 2;
    let h = 0, s = 0;
    const d = max - min;
    if (d !== 0) {
        s = d / (1 - Math.abs(2 * l - 1));
        switch (max) {
            case r: h = ((g - b) / d) % 6; break;
            case g: h = (b - r) / d + 2; break;
            default: h = (r - g) / d + 4; break;
        }
        h *= 60;
        if (h < 0) h += 360;
    }
    return [h, s, l];
}

// Extrait une couleur "dominante" (moyenne de tous les pixels échantillonnés -- simple et donne de bons
// résultats sans vraie quantification de couleurs) et une couleur "vibrante" (pixel de saturation maximale,
// avec un plancher de saturation/luminosité pour éviter de retomber sur un pixel quasi-noir ou quasi-blanc
// "techniquement" le plus saturé -- approximation grossière des contraintes target saturation/luminance de
// l'API Palette d'Android) depuis une image. Sous-échantillonnage à 50x50 via drawImage (au lieu de la
// résolution native) : largement suffisant pour une couleur moyenne/dominante et bien plus rapide que de
// lire chaque pixel d'une pochette potentiellement énorme.
function extractDominantAndVibrantColors(imageUrl) {
    return new Promise((resolve, reject) => {
        try {
            const img = new Image();
            img.crossOrigin = 'anonymous'; // filet de sécurité si jamais une pochette n'était pas same-origin
            img.onload = () => {
                try {
                    const SIZE = 50;
                    const canvas = document.createElement('canvas');
                    canvas.width = SIZE;
                    canvas.height = SIZE;
                    const ctx = canvas.getContext('2d', { willReadFrequently: true });
                    if (!ctx) { reject(new Error('2d context unavailable')); return; }
                    ctx.drawImage(img, 0, 0, SIZE, SIZE);

                    let data;
                    try {
                        data = ctx.getImageData(0, 0, SIZE, SIZE).data;
                    } catch (e) {
                        // Canvas "tainted" (CORS) -- ne devrait pas arriver (pochettes same-origin) mais on
                        // n'échoue jamais silencieusement en pleine lecture pour autant.
                        reject(e);
                        return;
                    }

                    let rSum = 0, gSum = 0, bSum = 0, count = 0;
                    let bestSat = -1, bestColor = null; // meilleur pixel respectant le plancher s/l
                    let bestAnySat = -1, bestAnyColor = null; // repli : meilleure saturation sans plancher (pochette grise/pastel)

                    for (let i = 0; i < data.length; i += 4) {
                        const a = data[i + 3];
                        if (a < 125) continue; // pixel majoritairement transparent -> ignoré (pochette PNG avec zones transparentes)
                        const r = data[i], g = data[i + 1], b = data[i + 2];
                        rSum += r; gSum += g; bSum += b; count++;

                        const [, s, l] = rgbToHsl(r, g, b);
                        if (s > bestAnySat) { bestAnySat = s; bestAnyColor = [r, g, b]; }
                        if (s >= 0.35 && l >= 0.2 && l <= 0.8 && s > bestSat) { bestSat = s; bestColor = [r, g, b]; }
                    }

                    if (count === 0) { reject(new Error('no opaque pixels sampled')); return; }

                    const dominant = [Math.round(rSum / count), Math.round(gSum / count), Math.round(bSum / count)];
                    const vibrant = bestColor || bestAnyColor || dominant;
                    resolve({ dominant: rgbToCss(dominant), vibrant: rgbToCss(vibrant) });
                } catch (e) {
                    reject(e);
                }
            };
            img.onerror = () => reject(new Error('cover image failed to load'));
            img.src = imageUrl;
        } catch (e) {
            reject(e);
        }
    });
}

function cancelDynamicThemeTween() {
    if (dynamicThemeRaf !== null) {
        cancelAnimationFrame(dynamicThemeRaf);
        dynamicThemeRaf = null;
    }
}

// Anime --fp-gradient-1/2 (sur <html>, donc lues aussi bien par #full-player que par .dfp-card, voir
// style.css) de leurs valeurs actuelles vers targetG1/targetG2 en ~1s -- interpolation manuelle RGB via
// requestAnimationFrame (transition CSS native sur une custom property nécessiterait @property, pas
// supporté partout) : ~même effet que animateColorAsState(tween(1000)) côté Android. Repart toujours de la
// valeur CSS actuellement calculée (et non d'un état mémorisé séparément) : ça inclut aussi bien la
// dernière couleur du preset statique (première extraction après activation) qu'une éventuelle valeur
// intermédiaire si un tween précédent est interrompu par un changement de piste rapproché.
function tweenGradientColors(targetG1, targetG2) {
    cancelDynamicThemeTween();
    const root = document.documentElement;
    const computed = getComputedStyle(root);
    const startG1 = parseColorToRgb(computed.getPropertyValue('--fp-gradient-1')) || parseColorToRgb(targetG1);
    const startG2 = parseColorToRgb(computed.getPropertyValue('--fp-gradient-2')) || parseColorToRgb(targetG2);
    const toG1 = parseColorToRgb(targetG1);
    const toG2 = parseColorToRgb(targetG2);

    if (!startG1 || !startG2 || !toG1 || !toG2) {
        // Repli défensif si une valeur ne se parse pas pour une raison quelconque : on applique directement
        // plutôt que de ne rien afficher.
        root.style.setProperty('--fp-gradient-1', targetG1);
        root.style.setProperty('--fp-gradient-2', targetG2);
        return;
    }

    const durationMs = 1000;
    const startTime = performance.now();

    function step(now) {
        const rawT = Math.min(1, (now - startTime) / durationMs);
        // Ease-in-out cubique -- transition un peu plus douce qu'un simple lerp linéaire.
        const t = rawT < 0.5 ? 4 * rawT * rawT * rawT : 1 - Math.pow(-2 * rawT + 2, 3) / 2;

        const c1 = [0, 1, 2].map(i => Math.round(startG1[i] + (toG1[i] - startG1[i]) * t));
        const c2 = [0, 1, 2].map(i => Math.round(startG2[i] + (toG2[i] - startG2[i]) * t));
        root.style.setProperty('--fp-gradient-1', rgbToCss(c1));
        root.style.setProperty('--fp-gradient-2', rgbToCss(c2));

        if (rawT < 1) {
            dynamicThemeRaf = requestAnimationFrame(step);
        } else {
            dynamicThemeRaf = null;
        }
    }
    dynamicThemeRaf = requestAnimationFrame(step);
}

// Appelée depuis loadTrack() à chaque changement de piste (et depuis setDynamicThemeEnabled() à
// l'activation, pour appliquer immédiatement à la piste déjà en cours). No-op silencieux si le réglage est
// désactivé ou si Alpine n'est pas encore prêt.
function applyDynamicThemeForCurrentTrack() {
    if (!window.Alpine || !Alpine.store('ui').dynamicThemeEnabled) return;
    const track = queue[currentIndex];
    if (!track) return;
    const coverUrl = 'covers/' + (track.cover || 'default.png');
    const trackIdAtRequest = track.id;

    extractDominantAndVibrantColors(coverUrl)
        .then(({ dominant, vibrant }) => {
            // Le réglage a pu être désactivé, ou une autre piste a pu être chargée entretemps (extraction
            // asynchrone) : dans les deux cas, on n'applique pas un résultat périmé.
            if (!window.Alpine || !Alpine.store('ui').dynamicThemeEnabled) return;
            if (!queue[currentIndex] || queue[currentIndex].id !== trackIdAtRequest) return;
            tweenGradientColors(dominant, vibrant);
        })
        .catch((e) => {
            // Échec d'extraction (pas de pochette, erreur réseau/décodage...) : jamais d'erreur visible ni de
            // page cassée -- on laisse simplement --fp-gradient-1/2 tel quel (couleurs du preset statique actif).
            console.error('Dynamic theme extraction failed', e);
        });
}

// Bascule le réglage persistant (Paramètres > Général) -- même schéma que setVisualizerEnabled().
// Désactivation : on retire nos surcharges de --fp-gradient-1/2 (retour immédiat, sans tween, aux valeurs
// du preset statique actif -- symétrique avec le "no-op total" documenté plus haut).
function setDynamicThemeEnabled(enabled) {
    if (!window.Alpine) return;
    Alpine.store('ui').dynamicThemeEnabled = enabled;
    localStorage.setItem('purpleMusicDynamicThemeEnabled', enabled ? '1' : '0');
    if (enabled) {
        applyDynamicThemeForCurrentTrack();
    } else {
        cancelDynamicThemeTween();
        document.documentElement.style.removeProperty('--fp-gradient-1');
        document.documentElement.style.removeProperty('--fp-gradient-2');
    }
}

const playIcon = '<svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>';
const pauseIcon = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';

// Tracé du haut-parleur "actif"/"muet" pour le bouton-icône du volume (mini-barre + grand lecteur desktop).
const volumeIconPath = 'M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z';
const mutedVolumeIconPath = 'M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.8L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z';

const desktopVol = document.getElementById('desktop-vol');
const settingsVol = document.getElementById('settings-vol');
const dpVol = document.getElementById('dp-vol');

// --- ADMIN PANEL : réinitialisation du mot de passe d'un utilisateur (admin uniquement) ---
// Différent du changement self-service (onglet Compte des Paramètres) : pas besoin de l'ancien mot
// de passe, génère un mot de passe temporaire aléatoire côté serveur et l'affiche une seule fois.
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
        fd.append('admin_reset_password', '1');
        fd.append('target_user_id', userId);
        const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
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

function renderTracksChunk() {
    const listContainer = document.getElementById('global-list');
    if (!listContainer) return;
    const chunk = CURRENT_VIEW_DATA.slice(renderedCount, renderedCount + RENDER_CHUNK);
    if (renderedCount === 0) listContainer.innerHTML = '';
    if (chunk.length === 0 && renderedCount === 0) {
        listContainer.innerHTML = `<div style="padding:40px; text-align:center; color:#666;">${T('no_tracks_found')}</div>`;
        return;
    }

    const fragment = document.createDocumentFragment();
    chunk.forEach((t) => {
        const safeTitle = escapeHTML(t.title);
        const safeArtist = escapeHTML(t.artist);
        const safeGenre = escapeHTML(t.genre || 'Autre');
        const safeCover = escapeHTML(t.cover);
        const jsSafeTitle = safeTitle.replace(/'/g, "\\'");
        const jsSafeArtist = safeArtist.replace(/'/g, "\\'");
        const jsSafeGenre = safeGenre.replace(/'/g, "\\'");

        let editButtons = '';
        if(t.uploader_id == CURRENT_USER_ID || IS_ADMIN) {
            editButtons = `
                <button class="btn btn-outline" style="font-size:0.7em; padding:6px 10px; border-radius:8px;" onclick="openEditTrackModal(${t.id}, '${jsSafeTitle}', '${jsSafeArtist}', '${jsSafeGenre}')">✎</button>
                <a href="?delete_track=${t.id}&csrf_token=${CSRF_TOKEN}" class="btn btn-danger" style="border-radius:8px;" onclick="return confirmDelete('${T('confirm_delete_generic')}', '?delete_track=${t.id}&csrf_token=${CSRF_TOKEN}')">✕</a>
            `;
        }

        const div = document.createElement('div');
        div.className = 'track-item';
        div.onclick = () => playTrackById(t.id);
        div.innerHTML = `
            <img src="covers/${safeCover}" loading="lazy" class="mini-cover" onerror="this.src='covers/default.png'">
            <div style="overflow:hidden;">
                <div class="marquee-wrap" style="font-weight:700; font-size:1.05em; margin-bottom:3px;"><span>${safeTitle}</span></div>
                <div style="font-size:0.85em; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    ${safeArtist} <span style="opacity:0.6;font-size:0.9em;">• ${safeGenre} • ▶ ${t.play_count || 0}</span>
                </div>
            </div>
            <div style="display:flex; gap:8px;" onclick="event.stopPropagation()">${editButtons}</div>
        `;
        fragment.appendChild(div);
    });
    listContainer.appendChild(fragment);
    renderedCount += chunk.length;
    // Synchrone (comme le fait déjà loadTrack() pour #fp-title) : le fragment vient d'être inséré dans le
    // DOM réel, donc immédiatement mesurable — pas besoin d'attendre un repaint.
    listContainer.querySelectorAll('.marquee-wrap').forEach(applyMarqueeIfOverflowing);
}

function filterAndSortTracks() {
    const searchInput = document.getElementById('searchInput');
    const sortSelect = document.getElementById('sortSelect');
    if (!searchInput || !sortSelect) return;

    const searchTerm = searchInput.value.toLowerCase();
    const sortValue = sortSelect.value;
    let filtered = ALL_MUSIC_DATA.filter(t => {
        const trackGenre = t.genre || 'Autre';
        if (hiddenGenres.includes(trackGenre)) return false;
        return t.title.toLowerCase().includes(searchTerm) || t.artist.toLowerCase().includes(searchTerm);
    });

    filtered.sort((a, b) => {
        if (sortValue === 'popular') {
            if (b.play_count !== a.play_count) return (b.play_count || 0) - (a.play_count || 0);
            return b.id - a.id;
        }
        else if (sortValue === 'date_desc') return b.id - a.id;
        else if (sortValue === 'date_asc') return a.id - b.id;
        else if (sortValue === 'alpha_asc') return a.title.localeCompare(b.title);
        else if (sortValue === 'alpha_desc') return b.title.localeCompare(a.title);
        else if (sortValue === 'artist') return a.artist.localeCompare(b.artist);
        return 0;
    });
    CURRENT_VIEW_DATA = filtered;
    renderedCount = 0;
    renderTracksChunk();
}

const _observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && renderedCount < CURRENT_VIEW_DATA.length) {
        renderTracksChunk();
    }
}, { rootMargin: "200px" });

document.addEventListener('DOMContentLoaded', async () => {
    if (typeof ALL_MUSIC_DATA === 'undefined') return;

    const savedVol = localStorage.getItem('purpleMusicVolume');
    if(savedVol !== null) updateVolume(savedVol); else updateVolume(1);

    // Restaure l'état de l'onglet Égaliseur (activé + gains par bande) depuis localStorage. Ne construit
    // pas le graphe audio ici (pas encore de geste utilisateur) -- se contente de refléter l'état
    // sauvegardé dans l'UI ; applyEqGains() n'écrira dans les nœuds qu'une fois initAudioGraph() appelé.
    restoreEqUI();

    // Constructeur de thème personnalisé (Paramètres > Général) : pré-remplit les <input type="color">
    // à partir des couleurs actuellement résolues (voir initCustomThemeBuilder() plus haut).
    initCustomThemeBuilder();

    document.querySelectorAll('.genre-filter-cb').forEach(cb => {
        if (hiddenGenres.includes(cb.dataset.genre)) cb.checked = true;
    });

    const trigger = document.getElementById('load-more-trigger');
    if (trigger) _observer.observe(trigger);

    filterAndSortTracks();

    const urlParams = new URLSearchParams(window.location.search);
    const pageParam = urlParams.get('page');
    const videoParam = urlParams.get('v');
    const listParam = urlParams.get('list');

    if (pageParam === 'playlist-detail' && listParam) {
        openPlaylistDetail(listParam);
    } else if (pageParam) {
        showSection(pageParam, false);
    }
    if (listParam) currentPlaylistId = listParam;
    if (videoParam) playTrackById(videoParam, false);
});

window.onpopstate = function(event) { window.location.reload(); };

function formatTime(s) {
    if(isNaN(s) || !isFinite(s)) return "0:00";
    let min = Math.floor(s / 60);
    let sec = Math.floor(s % 60);
    return min + ":" + (sec < 10 ? "0" : "") + sec;
}

function toggleQueue() {
    if (queuePanel) queuePanel.classList.toggle('open');
    if (window.Alpine && queuePanel && queuePanel.classList.contains('open')) Alpine.store('ui').lyricsPanelOpen = false;
}

// --- PAROLES (lrclib.net) ---

// Parse un texte au format LRC ("[mm:ss.xx]texte") en tableau trié [{time, text}]
function parseLRC(text) {
    const timeTagRe = /\[(\d{1,2}):(\d{2})(?:[.:](\d{1,3}))?\]/g;
    const result = [];
    text.split('\n').forEach(line => {
        const tags = [...line.matchAll(timeTagRe)];
        if (tags.length === 0) return;
        const content = line.replace(timeTagRe, '').trim();
        tags.forEach(tag => {
            const min = parseInt(tag[1], 10);
            const sec = parseInt(tag[2], 10);
            const ms = tag[3] ? parseInt(tag[3].padEnd(3, '0'), 10) : 0;
            result.push({ time: (min * 60) + sec + (ms / 1000), text: content });
        });
    });
    result.sort((a, b) => a.time - b.time);
    return result;
}

// Clic sur une ligne de paroles synchronisées -> avance/recule la lecture jusqu'à ce timestamp, sans
// changer l'état lecture/pause (comportement natif de <audio> quand on ne touche qu'à currentTime).
// Utilisé par les 3 surfaces de rendu des paroles (mobile .fp-lyrics-view, #lyrics-panel desktop, et la
// carte "paroles" du carrousel #desktop-player).
function seekToLyricLine(time) {
    if (audio && !isNaN(audio.duration)) audio.currentTime = time;
}

// Recherche par dichotomie de la dernière ligne dont le timestamp <= currentTime
function findActiveLyricIndex(lines, currentTime) {
    let lo = 0, hi = lines.length - 1, ans = -1;
    while (lo <= hi) {
        const mid = (lo + hi) >> 1;
        if (lines[mid].time <= currentTime) { ans = mid; lo = mid + 1; }
        else hi = mid - 1;
    }
    return ans;
}

async function loadLyricsForCurrentTrack(force = false) {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    const track = queue[currentIndex];

    if (!track) {
        store.lyricsTrackId = null;
        store.lyricsFound = null;
        store.lyricsSynced = [];
        store.lyricsPlain = '';
        store.lyricsActiveIndex = -1;
        return;
    }

    // Déjà chargées pour cette piste : pas besoin de refetch.
    if (!force && store.lyricsTrackId === track.id && store.lyricsFound !== null) return;

    store.lyricsTrackId = track.id;
    store.lyricsLoading = true;
    store.lyricsFound = null;
    store.lyricsSynced = [];
    store.lyricsPlain = '';
    store.lyricsActiveIndex = -1;

    try {
        const res = await fetch('?get_lyrics=' + track.id);
        const data = await res.json();
        // La piste a pu changer pendant l'attente de la réponse : on ignore un résultat périmé.
        if (store.lyricsTrackId !== track.id) return;
        store.lyricsSynced = data.synced ? parseLRC(data.synced) : [];
        store.lyricsPlain = data.plain || '';
        store.lyricsFound = !!data.found;
    } catch (e) {
        console.error(e);
        if (store.lyricsTrackId === track.id) store.lyricsFound = false;
    } finally {
        if (store.lyricsTrackId === track.id) store.lyricsLoading = false;
    }
}

// Bascule l'affichage des paroles à l'intérieur du lecteur plein écran (remplace la pochette en place).
function toggleLyricsInPlayer() {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    store.showLyricsInPlayer = !store.showLyricsInPlayer;
    if (store.showLyricsInPlayer) {
        loadLyricsForCurrentTrack();
    }
    // Paroles et visualiseur se partagent la même zone (.fp-art-container, à la place de la pochette) :
    // mutuellement exclusifs. Ré-applique dans les deux sens -- arrête la boucle rAF en ouvrant les
    // paroles (x-show seul cacherait juste le canvas sans arrêter l'animation invisible), la relance en
    // les refermant si le réglage est toujours activé.
    applyVisualizerForContext('mobile');
}

// Bouton "Paroles" de la barre de lecture desktop : ouvre un panneau latéral droit
// (même mécanisme que la file d'attente), pas le lecteur plein écran.
// Sur mobile (pas de place pour un panneau latéral), on garde le comportement plein écran existant.
function openLyricsFromPlayerBar() {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    if (window.innerWidth > 768) {
        // Toggle : un 2e clic referme le panneau au lieu de le laisser coincé ouvert.
        if (store.lyricsPanelOpen) { store.lyricsPanelOpen = false; return; }
        if (queuePanel) queuePanel.classList.remove('open');
        store.lyricsPanelOpen = true;
        loadLyricsForCurrentTrack();
    } else {
        loadLyricsForCurrentTrack();
        store.showLyricsInPlayer = true;
        const fp = document.getElementById('full-player');
        if (fp) {
            fp.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        applyVisualizerForContext('mobile');
    }
}

function closeLyricsPanel() {
    if (window.Alpine) Alpine.store('ui').lyricsPanelOpen = false;
}

function openSmartPlayer() {
    if (window.innerWidth <= 768) {
        const fp = document.getElementById('full-player');
        if (fp) {
            fp.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        applyVisualizerForContext('mobile');
    } else {
        openDesktopPlayer();
    }
}

function closeFullPlayer() {
    const fp = document.getElementById('full-player');
    if (fp) {
        fp.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    // Arrête la boucle requestAnimationFrame du visualiseur mobile si elle tournait -- ne doit jamais
    // continuer à animer un canvas caché en arrière-plan. Le réglage visualizerEnabled lui-même n'est PAS
    // remis à false ici (c'est un réglage persistant, pas un état par session) -- il sera juste ré-appliqué
    // à la prochaine ouverture via applyVisualizerForContext().
    stopVisualizer('mobile');
}

// --- Lecteur "grand écran" desktop (#desktop-player) : ouvert en cliquant sur .player-info de la barre
// de lecture (largeur > 768px, voir openSmartPlayer() ci-dessus). Distinct de #full-player (mobile).
function openDesktopPlayer() {
    const dp = document.getElementById('desktop-player');
    if (dp) {
        dp.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    // La mini-barre reste sinon visible/au-dessus du grand lecteur (aucun z-index ne l'en empêche) : on la
    // masque tant que le grand lecteur est ouvert, restaurée dans closeDesktopPlayer().
    const pb = document.getElementById('player-bar');
    if (pb) pb.style.display = 'none';
    // Les panneaux latéraux paroles/file d'attente (header, barre de lecture) n'ont pas de raison de
    // rester ouverts par-dessus le grand lecteur, qui a désormais ses propres cartes paroles/file
    // d'attente dédiées (voir le carrousel #desktop-player) — sinon les deux se superposaient.
    if (queuePanel) queuePanel.classList.remove('open');
    if (window.Alpine) {
        Alpine.store('ui').lyricsPanelOpen = false;
        // Toujours rouvrir sur la carte lecteur, jamais coincé sur paroles/file d'attente d'une session précédente.
        Alpine.store('ui').desktopPlayerView = 'player';
        if (dpVol) dpVol.value = audio ? audio.volume : dpVol.value;
    }
    applyVisualizerForContext('desktop');
}

function closeDesktopPlayer() {
    const dp = document.getElementById('desktop-player');
    if (dp) {
        dp.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
    const pb = document.getElementById('player-bar');
    if (pb) pb.style.display = '';
    // Fermer depuis n'importe quelle vue (croix, Échap, clic sur le fond) doit toujours réinitialiser le
    // carrousel sur la carte lecteur pour la prochaine ouverture.
    if (window.Alpine) Alpine.store('ui').desktopPlayerView = 'player';
    // Arrête la boucle requestAnimationFrame du visualiseur desktop si elle tournait -- même précaution
    // que closeFullPlayer() côté mobile. visualizerEnabled (réglage persistant) n'est pas touché ici.
    stopVisualizer('desktop');
}

// --- Carrousel du lecteur desktop : bascule entre les 3 cartes (lecteur / paroles / file d'attente).
// Le mapping des transforms (voir .dfp-card* dans style.css) est purement fonction de cet état, donc
// "revenir au lecteur" depuis n'importe quelle sous-vue est juste backToDesktopPlayer().
function showDesktopPlayerLyrics() {
    if (!window.Alpine) return;
    Alpine.store('ui').desktopPlayerView = 'lyrics';
    loadLyricsForCurrentTrack();
}

function showDesktopPlayerQueue() {
    if (!window.Alpine) return;
    Alpine.store('ui').desktopPlayerView = 'queue';
}

function backToDesktopPlayer() {
    if (!window.Alpine) return;
    Alpine.store('ui').desktopPlayerView = 'player';
}

// Construit le rendu de la file d'attente dans un conteneur donné. Extrait de updateQueueUI() pour être
// réutilisable : la file existe maintenant dans 2 endroits du DOM (#queue-list, panneau latéral existant ;
// #dp-queue-list, carte "file d'attente" du carrousel #desktop-player) qui doivent rester synchronisés.
function renderQueueListInto(container) {
    if (!container) return;
    container.innerHTML = '';
    if(queue.length === 0) {
        container.innerHTML = `<p style="color:#666;">${T('queue_empty')}</p>`;
        return;
    }
    queue.forEach((track, index) => {
        const safeTitle = escapeHTML(track.title);
        const safeArtist = escapeHTML(track.artist);
        const safeCover = escapeHTML(track.cover);
        const div = document.createElement('div');
        div.className = `queue-item ${index === currentIndex ? 'active' : ''}`;
        div.innerHTML = `
            <img src="covers/${safeCover}" loading="lazy" style="width:36px; height:36px; border-radius:8px; object-fit:cover;">
            <div style="flex:1; overflow:hidden;">
                <div style="font-size:0.9em; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${safeTitle}</div>
                <div style="font-size:0.75em; color:#888;">${safeArtist}</div>
            </div>
            ${index === currentIndex ? '<span style="color:var(--accent); font-size:1.5em;">•</span>' : ''}
        `;
        div.onclick = () => { currentIndex = index; loadTrack(true); };
        container.appendChild(div);
    });
}

function updateQueueUI() {
    renderQueueListInto(queueList);
    renderQueueListInto(dpQueueList);
}

function playTrackById(id, autoPlay = true) {
    if (!currentPlaylistId) {
        originalQueue = [...CURRENT_VIEW_DATA];
        queue = isShuffle ? shuffleArray([...originalQueue]) : [...originalQueue];
        currentIndex = queue.findIndex(t => t.id == id);
    } else {
        let inPlaylistIndex = queue.findIndex(t => t.id == id);
        if (inPlaylistIndex === -1) {
            currentPlaylistId = null;
            return playTrackById(id, autoPlay);
        }
        currentIndex = inPlaylistIndex;
    }
    if (currentIndex === -1) currentIndex = 0;
    loadTrack(autoPlay);
}

async function playPlaylist(ids, pId = null) {
    const res = await fetch('?get_playlist_tracks=' + ids);
    let data = await res.json();
    if (hiddenGenres.length > 0) {
        data = data.filter(t => !hiddenGenres.includes(t.genre || 'Autre'));
    }
    if(data.length > 0) {
        currentPlaylistId = pId;
        originalQueue = [...data];
        queue = isShuffle ? shuffleArray([...data]) : [...data];
        currentIndex = 0;
        loadTrack(true);
    } else if (window.Alpine) {
        Alpine.store('ui').showToast(T('toast_no_music'));
    } else {
        alert(T('toast_no_music'));
    }
}

// --- LECTURE DEPUIS LA VUE DÉTAIL D'UNE PLAYLIST (pas de lecture auto à l'ouverture) ---
async function openPlaylistDetail(id) {
    if (!window.Alpine || typeof ALL_PLAYLISTS_DATA === 'undefined') return;
    const store = Alpine.store('ui');
    const playlist = ALL_PLAYLISTS_DATA.find(p => p.id == id);
    if (!playlist) return;

    const canEdit = (playlist.creator_id == CURRENT_USER_ID) || IS_ADMIN;
    store.playlistDetail = {
        id: playlist.id,
        name: playlist.name,
        username: playlist.username,
        creator_id: playlist.creator_id,
        song_ids: playlist.song_ids,
        cover: playlist.cover,
        canEdit: canEdit,
        tracks: [],
        loading: true
    };
    showSection('playlist-detail');

    try {
        const res = await fetch('?get_playlist_tracks=' + playlist.song_ids);
        const data = await res.json();
        if (store.playlistDetail && store.playlistDetail.id == playlist.id) {
            store.playlistDetail.tracks = data;
            store.playlistDetail.loading = false;
        }
    } catch (e) {
        console.error(e);
        if (store.playlistDetail && store.playlistDetail.id == playlist.id) {
            store.playlistDetail.loading = false;
        }
    }
}

function playAllInPlaylistDetail() {
    if (!window.Alpine) return;
    const pd = Alpine.store('ui').playlistDetail;
    if (!pd) return;
    playPlaylist(pd.song_ids, pd.id);
}

function playTrackInPlaylistDetail(id) {
    if (!window.Alpine) return;
    const pd = Alpine.store('ui').playlistDetail;
    if (!pd || !pd.tracks || pd.tracks.length === 0) return;
    currentPlaylistId = pd.id;
    originalQueue = [...pd.tracks];
    queue = isShuffle ? shuffleArray([...pd.tracks]) : [...pd.tracks];
    currentIndex = queue.findIndex(t => t.id == id);
    if (currentIndex === -1) currentIndex = 0;
    loadTrack(true);
}

function backToPlaylists() {
    showSection('playlists');
}

function editPlaylistFromDetail() {
    if (!window.Alpine) return;
    const pd = Alpine.store('ui').playlistDetail;
    if (!pd || typeof ALL_PLAYLISTS_DATA === 'undefined') return;
    const playlist = ALL_PLAYLISTS_DATA.find(p => p.id == pd.id);
    if (playlist) openEditModal(playlist);
}

function loadTrack(autoPlay = true) {
    if (!queue[currentIndex]) return;
    const track = queue[currentIndex];
    audio.src = 'music/' + track.filename;
    fetch('?increment_play=' + track.id).catch(e => console.error(e));
    track.play_count = (parseInt(track.play_count) || 0) + 1;

    if (typeof ALL_MUSIC_DATA !== 'undefined') {
        const globalTrack = ALL_MUSIC_DATA.find(t => t.id == track.id);
        if (globalTrack) globalTrack.play_count = track.play_count;
    }

    if (playTitle) playTitle.innerText = track.title;
    if (playCover) playCover.src = 'covers/' + (track.cover || 'default.png');
    if (playStatus) playStatus.innerText = track.artist || 'Artiste inconnu';

    const fpTitle = document.getElementById('fp-title');
    const fpArtist = document.getElementById('fp-artist');
    const fpCover = document.getElementById('fp-cover');
    const dpTitle = document.getElementById('dp-title');
    const dpArtist = document.getElementById('dp-artist');
    const dpCover = document.getElementById('dp-cover');

    if (fpTitle) {
        const safeFpTitle = escapeHTML(track.title);
        fpTitle.innerHTML = `<span id="fp-title-text">${safeFpTitle}</span>`;
        const titleSpan = document.getElementById('fp-title-text');
        titleSpan.classList.remove('scrolling-active');
        if (titleSpan.scrollWidth > fpTitle.clientWidth) {
            titleSpan.classList.add('scrolling-active');
        }
    }
    if (fpArtist) fpArtist.innerText = track.artist || 'Artiste inconnu';
    if (fpCover) fpCover.src = 'covers/' + (track.cover || 'default.png');

    // Carte desktop : pas de marquee (largeur confortable), simple troncature CSS (ellipsis).
    if (dpTitle) dpTitle.innerText = track.title;
    if (dpArtist) dpArtist.innerText = track.artist || 'Artiste inconnu';
    if (dpCover) dpCover.src = 'covers/' + (track.cover || 'default.png');

    document.getElementById('curr-time').innerText = "0:00";
    document.getElementById('total-time').innerText = "0:00";
    progressBar.style.width = "0%";

    const fpProgressBar = document.getElementById('fp-progress-bar');
    if (fpProgressBar) fpProgressBar.style.width = "0%";
    const fpCurrTime = document.getElementById('fp-curr-time');
    if (fpCurrTime) fpCurrTime.innerText = "0:00";
    const fpTotalTime = document.getElementById('fp-total-time');
    if (fpTotalTime) fpTotalTime.innerText = "0:00";

    const dpProgressBar = document.getElementById('dp-progress-bar');
    if (dpProgressBar) dpProgressBar.style.width = "0%";
    const dpCurrTime = document.getElementById('dp-curr-time');
    if (dpCurrTime) dpCurrTime.innerText = "0:00";
    const dpTotalTime = document.getElementById('dp-total-time');
    if (dpTotalTime) dpTotalTime.innerText = "0:00";

    if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: track.title,
            artist: track.artist || 'Purple Music',
            artwork: [{ src: 'covers/' + (track.cover || 'default.png'), sizes: '96x96', type: 'image/png' }]
        });
    }
    updateUrl();
    applyDynamicThemeForCurrentTrack();
    if (window.Alpine) {
        const s = Alpine.store('ui');
        if (s.showLyricsInPlayer || s.lyricsPanelOpen || s.desktopPlayerView === 'lyrics') loadLyricsForCurrentTrack();
    }
    if (autoPlay) {
        audio.play().catch(e => console.error(e));
        masterPlay.innerHTML = pauseIcon;
        const fpMasterPlay = document.getElementById('fp-masterPlay');
        if (fpMasterPlay) fpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
        const dpMasterPlay = document.getElementById('dp-masterPlay');
        if (dpMasterPlay) dpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    } else {
        masterPlay.innerHTML = playIcon;
        const fpMasterPlay = document.getElementById('fp-masterPlay');
        if (fpMasterPlay) fpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
        const dpMasterPlay = document.getElementById('dp-masterPlay');
        if (dpMasterPlay) dpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black; margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>';
    }
    updateQueueUI();
}

if (audio) {
    audio.onloadedmetadata = () => {
        const t = formatTime(audio.duration);
        document.getElementById('total-time').innerText = t;
        const fpTotalTime = document.getElementById('fp-total-time');
        if (fpTotalTime) fpTotalTime.innerText = t;
        const dpTotalTime = document.getElementById('dp-total-time');
        if (dpTotalTime) dpTotalTime.innerText = t;
    };
    audio.ontimeupdate = () => {
        const pct = (audio.currentTime / audio.duration) * 100;
        progressBar.style.width = (pct || 0) + "%";
        document.getElementById('curr-time').innerText = formatTime(audio.currentTime);
        if(audio.duration) document.getElementById('total-time').innerText = formatTime(audio.duration);

        const fpProgressBar = document.getElementById('fp-progress-bar');
        if (fpProgressBar) fpProgressBar.style.width = (pct || 0) + "%";
        const fpCurrTime = document.getElementById('fp-curr-time');
        if (fpCurrTime) fpCurrTime.innerText = formatTime(audio.currentTime);
        const fpTotalTime = document.getElementById('fp-total-time');
        if (fpTotalTime && audio.duration) fpTotalTime.innerText = formatTime(audio.duration);

        const dpProgressBar = document.getElementById('dp-progress-bar');
        if (dpProgressBar) dpProgressBar.style.width = (pct || 0) + "%";
        const dpCurrTime = document.getElementById('dp-curr-time');
        if (dpCurrTime) dpCurrTime.innerText = formatTime(audio.currentTime);
        const dpTotalTime = document.getElementById('dp-total-time');
        if (dpTotalTime && audio.duration) dpTotalTime.innerText = formatTime(audio.duration);

        if (window.Alpine) {
            const store = Alpine.store('ui');
            if ((store.showLyricsInPlayer || store.lyricsPanelOpen || store.desktopPlayerView === 'lyrics') && store.lyricsSynced && store.lyricsSynced.length > 0) {
                const idx = findActiveLyricIndex(store.lyricsSynced, audio.currentTime);
                if (idx !== store.lyricsActiveIndex) store.lyricsActiveIndex = idx;
            }
        }
    };
    audio.onended = nextTrack;
}

function nextTrack() {
    if (loopMode === 2) { audio.currentTime = 0; audio.play(); return; }
    if (currentIndex < queue.length - 1) {
        currentIndex++;
        loadTrack(true);
    }
    else if (loopMode === 1) {
        currentIndex = 0;
        loadTrack(true);
    }
    else {
        audio.pause();
        audio.currentTime = 0;
        masterPlay.innerHTML = playIcon;
        const fpMasterPlay = document.getElementById('fp-masterPlay');
        if (fpMasterPlay) fpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
        const dpMasterPlay = document.getElementById('dp-masterPlay');
        if (dpMasterPlay) dpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black; margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>';
    }
}

function prevTrack() {
    if (currentIndex > 0) {
        currentIndex--;
        loadTrack(true);
    }
}

function togglePlay() {
    if(!audio.src) return;
    const fpPlayIcon = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
    const fpPauseIcon = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    const dpPlayIcon = '<svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black; margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>';
    const dpPauseIcon = '<svg viewBox="0 0 24 24" style="width:28px; height:28px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    const fpMasterPlay = document.getElementById('fp-masterPlay');
    const dpMasterPlay = document.getElementById('dp-masterPlay');

    if(audio.paused) {
        // Construit/reprend le graphe audio partagé (égaliseur + visualiseur) ici : un clic sur play est
        // un geste utilisateur valide pour démarrer un AudioContext, et c'est le point d'entrée le plus
        // fiable puisqu'une lecture va de toute façon démarrer juste après.
        resumeAudioGraph();
        audio.play();
        masterPlay.innerHTML = pauseIcon;
        if (fpMasterPlay) fpMasterPlay.innerHTML = fpPauseIcon;
        if (dpMasterPlay) dpMasterPlay.innerHTML = dpPauseIcon;
    }
    else {
        audio.pause();
        masterPlay.innerHTML = playIcon;
        if (fpMasterPlay) fpMasterPlay.innerHTML = fpPlayIcon;
        if (dpMasterPlay) dpMasterPlay.innerHTML = dpPlayIcon;
    }
}

function toggleShuffle() {
    isShuffle = !isShuffle;
    document.getElementById('shuffleBtn').classList.toggle('active', isShuffle);
    const fpShuffleBtn = document.getElementById('fp-shuffleBtn');
    if (fpShuffleBtn) fpShuffleBtn.classList.toggle('active', isShuffle);
    const dpShuffleBtn = document.getElementById('dp-shuffleBtn');
    if (dpShuffleBtn) dpShuffleBtn.classList.toggle('active', isShuffle);

    if (queue.length > 0) {
        const currentTrack = queue[currentIndex];
        queue = isShuffle ? shuffleArray([...originalQueue]) : [...originalQueue];
        currentIndex = queue.findIndex(t => t.filename === currentTrack.filename);
        if (currentIndex === -1) currentIndex = 0;
        updateQueueUI();
    }
}

function toggleLoop() {
    loopMode = (loopMode + 1) % 3;
    const isActive = loopMode > 0;
    document.getElementById('loopBtn').classList.toggle('active', isActive);
    const fpLoopBtn = document.getElementById('fp-loopBtn');
    if (fpLoopBtn) fpLoopBtn.classList.toggle('active', isActive);
    const dpLoopBtn = document.getElementById('dp-loopBtn');
    if (dpLoopBtn) dpLoopBtn.classList.toggle('active', isActive);

    const loopInd = document.getElementById('loop-ind');
    if (loopInd) loopInd.style.display = (loopMode === 2) ? 'flex' : 'none';
    const fpLoopInd = document.getElementById('fp-loop-ind');
    if (fpLoopInd) {
        fpLoopInd.style.display = isActive ? 'block' : 'none';
        fpLoopInd.style.background = (loopMode === 2) ? 'var(--primary)' : 'white';
    }
    const dpLoopInd = document.getElementById('dp-loop-ind');
    if (dpLoopInd) {
        dpLoopInd.style.display = isActive ? 'block' : 'none';
        dpLoopInd.style.background = (loopMode === 2) ? 'var(--primary)' : 'white';
    }
}

function shuffleArray(arr) {
    for (let i = arr.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr;
}

if (progressArea) {
    progressArea.onclick = (e) => {
        const rect = progressArea.getBoundingClientRect();
        audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
    };
}

const fpProgressArea = document.getElementById('fp-progress-area');
if (fpProgressArea) {
    fpProgressArea.onclick = (e) => {
        const rect = fpProgressArea.getBoundingClientRect();
        audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
    };
}

const dpProgressArea = document.getElementById('dp-progress-area');
if (dpProgressArea) {
    dpProgressArea.onclick = (e) => {
        const rect = dpProgressArea.getBoundingClientRect();
        audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
    };
}

function showSection(id, doUpdateUrl = true) {
    if (window.Alpine) Alpine.store('ui').section = id;

    document.querySelectorAll('nav span').forEach(s => s.classList.remove('active'));
    if(document.getElementById('nav-' + id)) document.getElementById('nav-' + id).classList.add('active');

    document.querySelectorAll('.mob-nav-item').forEach(s => s.classList.remove('active'));
    if(document.getElementById('mob-nav-' + id)) document.getElementById('mob-nav-' + id).classList.add('active');

    window.scrollTo(0,0);
    currentSection = id;
    if (doUpdateUrl) updateUrl();

    // Les titres de la page Playlists sont rendus côté PHP au chargement, pendant que la section est
    // encore cachée par x-show (clientWidth = 0 tant qu'elle n'est pas affichée) — le test de dépassement
    // ne peut donc se faire qu'ici, une fois la section réellement visible, pas au chargement de la page.
    if (id === 'playlists' && window.Alpine) {
        // Alpine.nextTick (pas requestAnimationFrame) : x-show retire le display:none sur la section via
        // une micro-tâche Alpine, pas au prochain repaint — mesurer avant que ce changement soit appliqué
        // donnerait clientWidth = 0 partout et ne détecterait jamais de dépassement.
        Alpine.nextTick(() => {
            document.querySelectorAll('#playlists .marquee-wrap').forEach(applyMarqueeIfOverflowing);
        });
    }
}

// Ajoute .scrolling-active (voir style.css, réutilise l'animation déjà en place pour #fp-title) sur le
// <span> interne d'un conteneur .marquee-wrap seulement si le texte dépasse réellement la largeur
// disponible — jamais pour un titre qui tient déjà sur une ligne.
function applyMarqueeIfOverflowing(wrapperEl) {
    const span = wrapperEl.querySelector('span');
    if (!span) return;
    span.classList.remove('scrolling-active');
    if (span.scrollWidth > wrapperEl.clientWidth) span.classList.add('scrolling-active');
}

function openModal(id) {
    if (window.Alpine) Alpine.store('ui').openModal(id);
}
function closeModal(id) {
    if (window.Alpine) Alpine.store('ui').closeModal(id);
}

function openEditTrackModal(id, title, artist, genre) {
    document.getElementById('edit-track-id').value = id;
    document.getElementById('edit-track-title').value = title;
    document.getElementById('edit-track-artist').value = artist;
    const gSelect = document.getElementById('edit-track-genre');
    if (gSelect && genre) gSelect.value = genre;
    openModal('editTrackModal');
}

function filterPlaylistTracks() {
    const term = document.getElementById('playlist-search').value.toLowerCase();
    document.querySelectorAll('.song-select-item').forEach(item => {
        item.style.display = item.dataset.title.includes(term) ? 'flex' : 'none';
    });
}

function toggleSelection(div) {
    const cb = div.querySelector('input');
    cb.checked = !cb.checked;
    cb.checked ? div.classList.add('selected') : div.classList.remove('selected');
    updateSelectedCount();
}
function updateSelectedCount() {
    const count = document.querySelectorAll('.song-cb:checked').length;
    const el = document.getElementById('selected-count');
    if (el) el.innerText = T('selected_count', { n: count });
}

function openCreateModal() {
    const title = document.getElementById('modal-playlist-title');
    if (title) title.innerText = T('playlist_new_title');
    document.getElementById('form-playlist-id').value = "";
    document.getElementById('form-playlist-name').value = "";
    const pSearch = document.getElementById('playlist-search');
    if (pSearch) pSearch.value = "";
    resetPlaylistCoverPreview();

    document.querySelectorAll('.song-select-item').forEach(div => {
        div.classList.remove('selected');
        div.style.display = 'flex';
    });
    document.querySelectorAll('.song-cb').forEach(cb => cb.checked = false);
    updateSelectedCount();
    openModal('playlistModal');
}

function openEditModal(p) {
    const title = document.getElementById('modal-playlist-title');
    if (title) title.innerText = T('playlist_edit_title');
    document.getElementById('form-playlist-id').value = p.id;
    document.getElementById('form-playlist-name').value = p.name;
    const pSearch = document.getElementById('playlist-search');
    if (pSearch) pSearch.value = "";
    setPlaylistCoverPreview(p.cover);

    const ids = String(p.song_ids).split(',');
    document.querySelectorAll('.song-select-item').forEach(div => {
        const cb = div.querySelector('.song-cb');
        cb.checked = ids.includes(cb.dataset.id);
        cb.checked ? div.classList.add('selected') : div.classList.remove('selected');
        div.style.display = 'flex';
    });
    updateSelectedCount();
    openModal('playlistModal');
}

// --- Aperçu en direct de la cover de playlist (modale création/édition) ---
function resetPlaylistCoverPreview() {
    const fileInput = document.getElementById('form-playlist-cover');
    if (fileInput) fileInput.value = '';
    const img = document.getElementById('playlist-cover-preview-img');
    if (img) { img.removeAttribute('src'); img.style.display = 'none'; }
}

function setPlaylistCoverPreview(cover) {
    const fileInput = document.getElementById('form-playlist-cover');
    if (fileInput) fileInput.value = '';
    const img = document.getElementById('playlist-cover-preview-img');
    if (!img) return;
    if (cover) { img.src = 'covers/' + cover; img.style.display = 'block'; }
    else { img.removeAttribute('src'); img.style.display = 'none'; }
}

function previewPlaylistCoverFile(input) {
    const img = document.getElementById('playlist-cover-preview-img');
    if (!img) return;
    const file = input.files && input.files[0];
    if (file) { img.src = URL.createObjectURL(file); img.style.display = 'block'; }
    else { img.removeAttribute('src'); img.style.display = 'none'; }
}
