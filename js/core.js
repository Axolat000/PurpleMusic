// --- LOGIQUE JAVASCRIPT PURPLE MUSIC ---

// --- ALPINE.JS : STORE GLOBAL UI (modales, sections, dialogue de confirmation, toast) ---
document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        activeModal: null,
        section: 'accueil',
        searchTerm: '',
        playlistDetail: null,
        browseTitle: '', // titre de la page "Voir tout" (page dédiée, voir openBrowseAll())
        recentTracks: [],
        popularTracks: [],
        playlistsPreview: [],
        recommendedTracks: [], // rempli de façon asynchrone (voir init()) -- calcul serveur, pas instantané comme les autres rangées
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
            // Recommandations : calcul serveur (build_recommendations(), api.php), chargé une fois
            // au démarrage comme le reste de l'accueil -- échec réseau non bloquant, la rangée reste
            // simplement absente (x-if sur .length > 0 dans index.php) plutôt que de casser la page.
            fetch('api.php?action=recommendations').then(r => r.json()).then(data => {
                if (Array.isArray(data)) this.recommendedTracks = data;
            }).catch(e => console.error(e));
            // Classement complet (pas juste le top 20 ci-dessus) : alimente le mode de tri 'recommended',
            // par défaut sur la bibliothèque -- arrive après le premier rendu, donc on retrie une fois prêt
            // si l'utilisateur est toujours sur ce tri (voir compareTracksBySort()/filterAndSortTracks()).
            fetch('api.php?action=recommendations&full=1').then(r => r.json()).then(data => {
                if (!Array.isArray(data)) return;
                RECOMMENDED_RANK = new Map(data.map((t, i) => [t.id, i]));
                const sortSelect = document.getElementById('sortSelect');
                if (sortSelect && sortSelect.value === 'recommended') filterAndSortTracks();
            }).catch(e => console.error(e));
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
                const res = await fetch('api.php?action=check_update');
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
                const res = await fetch('api.php?action=trigger_update', { method: 'POST', body: fd });
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
                fd.append('current_password', this.pwCurrent);
                fd.append('new_password', this.pwNew);
                fd.append('confirm_password', this.pwConfirm);
                const res = await fetch('api.php?action=change_password', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    this.pwCurrent = '';
                    this.pwNew = '';
                    this.pwConfirm = '';
                    Alpine.store('ui').showToast(data.message || T('settings_password_changed'));
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
        acceptTerms: false,
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
                return;
            }
            if (typeof TERMS_ENABLED !== 'undefined' && TERMS_ENABLED && !this.acceptTerms) {
                this.clientError = T('err_must_accept_terms');
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
// Envoie une action mutante à api.php (session + CSRF, voir authenticate_api_user() côté serveur) et
// recharge la page au succès -- remplace les anciens liens/formulaires GET/POST classiques vers
// actions.php (rechargeaient déjà la page via une redirection serveur ; api.php ne fait jamais de
// redirection HTML, seulement du JSON, donc ce fetch()+reload reproduit le même résultat visible).
function postApiAction(action, fields) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    for (const k in fields) fd.append(k, fields[k]);
    return fetch('api.php?action=' + action, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') { window.location.reload(); return data; }
            const msg = (data && data.message) || T('err_action_failed');
            if (window.Alpine) Alpine.store('ui').showToast(msg); else alert(msg);
            return data;
        })
        .catch(() => { if (window.Alpine) Alpine.store('ui').showToast(T('err_action_failed')); else alert(T('err_action_failed')); });
}

function confirmPostAction(message, action, fields) {
    if (window.Alpine) {
        Alpine.store('ui').confirmAction(message, () => { postApiAction(action, fields); });
    } else if (confirm(message)) {
        postApiAction(action, fields);
    }
    return false;
}

// Intercepte un <form> classique (upload, édition de piste, playlist, réglages admin...) et l'envoie à
// api.php au lieu d'un POST natif vers index.php -- api.php ne fait jamais de redirection HTML, donc ce
// fetch()+reload reproduit le même résultat visible que l'ancienne redirection serveur. Retourne false
// (appelé depuis onsubmit="return ...") pour empêcher systématiquement la soumission native du form.
function submitFormToApi(formEl, action) {
    const fd = new FormData(formEl);
    fetch('api.php?action=' + action, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') { window.location.reload(); return; }
            const msg = (data && data.message) || T('err_action_failed');
            if (window.Alpine) Alpine.store('ui').showToast(msg); else alert(msg);
        })
        .catch(() => { if (window.Alpine) Alpine.store('ui').showToast(T('err_action_failed')); else alert(T('err_action_failed')); });
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
// Vue "Voir tout" (page dédiée, ne touche pas au tri de l'accueil) : mêmes rendu/pagination que la
// bibliothèque complète mais dans son propre conteneur/état, voir openBrowseAll().
let BROWSE_VIEW_DATA = [];
let browseRenderedCount = 0;
let browseSort = null;
// Classement complet "Recommandé pour toi" (id -> rang, 0 = meilleur), utilisé comme mode de tri par
// défaut de la bibliothèque -- rempli de façon asynchrone au démarrage (voir init() dans le store Alpine).
let RECOMMENDED_RANK = new Map();
let originalQueue = [];
let queue = [];
let currentIndex = 0;
let loopMode = 0;
let isShuffle = false;
let currentPlaylistId = null;
let currentSection = 'accueil';
let hiddenGenres = JSON.parse(localStorage.getItem('hiddenGenres') || '[]');
