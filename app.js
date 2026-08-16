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
    // sur "Revenir au direct". Utilisé sur #lyrics-panel (desktop) et .fp-lyrics-view (mobile).
    Alpine.data('lyricsScroller', () => ({
        manualScroll: false,
        resumeTimer: null,
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
    amoled: {
        '--bg-dark': '#000000', '--bg-panel': '#0A0A0A', '--primary': '#7B2CBF', '--accent': '#B388FF',
        '--text': '#E8E8E8', '--text-muted': '#9E9E9E', '--border-color': '#2A2A2A', '--search-bg': '#141414',
        '--header-bg': 'rgba(0,0,0,0.85)', '--player-bg': 'rgba(10,10,10,0.85)', '--mob-nav-bg': 'rgba(0,0,0,0.95)',
        '--fp-gradient-1': '#1A1A1A', '--fp-gradient-2': '#000000'
    },
    midnight: {
        '--bg-dark': '#0B1120', '--bg-panel': '#161F35', '--primary': '#3B5BDB', '--accent': '#7C9BFF',
        '--text': '#E3E7F5', '--text-muted': '#8D97B8', '--border-color': '#2A3655', '--search-bg': '#1C2740',
        '--header-bg': 'rgba(14,21,38,0.85)', '--player-bg': 'rgba(22,31,53,0.85)', '--mob-nav-bg': 'rgba(14,21,38,0.95)',
        '--fp-gradient-1': '#1B2A4D', '--fp-gradient-2': '#0B1120'
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
    }
};

// Applique (ou retire) les surcharges de variables CSS sur <html>. "violet" = retour aux couleurs admin/BDD.
function setThemeVars(name) {
    const root = document.documentElement;
    const preset = THEME_PRESETS[name];
    if (!preset) {
        THEME_VAR_NAMES.forEach(v => root.style.removeProperty(v));
        return;
    }
    Object.entries(preset).forEach(([k, v]) => root.style.setProperty(k, v));
}

// Applique un preset de thème, le persiste en localStorage (par navigateur/utilisateur) et met à jour l'UI (swatch actif).
function applyThemePreset(name) {
    if (!THEME_PRESETS[name] && name !== 'violet') return;
    localStorage.setItem('purpleMusicTheme', name);
    setThemeVars(name);
    if (window.Alpine) Alpine.store('ui').themePreset = name;
}

// Application la plus précoce possible (avant même Alpine) pour éviter un flash des couleurs par défaut.
setThemeVars(localStorage.getItem('purpleMusicTheme') || 'violet');

const playIcon = '<svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>';
const pauseIcon = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';

const desktopVol = document.getElementById('desktop-vol');
const settingsVol = document.getElementById('settings-vol');

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
    audio.volume = val;
    if(desktopVol) desktopVol.value = val;
    if(settingsVol) settingsVol.value = val;
    localStorage.setItem('purpleMusicVolume', val);
    const percentage = val * 100;
    const bgStyle = `linear-gradient(90deg, var(--accent) ${percentage}%, rgba(255,255,255,0.2) ${percentage}%)`;
    if(desktopVol) desktopVol.style.background = bgStyle;
    if(settingsVol) settingsVol.style.background = bgStyle;
}

if(desktopVol) desktopVol.addEventListener('input', (e) => updateVolume(e.target.value));
if(settingsVol) settingsVol.addEventListener('input', (e) => updateVolume(e.target.value));

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
                <div style="font-weight:700; font-size:1.05em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px;">${safeTitle}</div>
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
    if (store.showLyricsInPlayer) loadLyricsForCurrentTrack();
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
    } else {
        toggleQueue();
    }
}

function closeFullPlayer() {
    const fp = document.getElementById('full-player');
    if (fp) {
        fp.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

function updateQueueUI() {
    if (!queueList) return;
    queueList.innerHTML = '';
    if(queue.length === 0) {
        queueList.innerHTML = `<p style="color:#666;">${T('queue_empty')}</p>`;
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
        queueList.appendChild(div);
    });
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
    if(autoPlay && window.innerWidth > 768 && queuePanel && !queuePanel.classList.contains('open')) toggleQueue();
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
        if(window.innerWidth > 768 && queuePanel && !queuePanel.classList.contains('open')) toggleQueue();
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
    if (window.innerWidth > 768 && queuePanel && !queuePanel.classList.contains('open')) toggleQueue();
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

    document.getElementById('curr-time').innerText = "0:00";
    document.getElementById('total-time').innerText = "0:00";
    progressBar.style.width = "0%";

    const fpProgressBar = document.getElementById('fp-progress-bar');
    if (fpProgressBar) fpProgressBar.style.width = "0%";
    const fpCurrTime = document.getElementById('fp-curr-time');
    if (fpCurrTime) fpCurrTime.innerText = "0:00";
    const fpTotalTime = document.getElementById('fp-total-time');
    if (fpTotalTime) fpTotalTime.innerText = "0:00";

    if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: track.title,
            artist: track.artist || 'Purple Music',
            artwork: [{ src: 'covers/' + (track.cover || 'default.png'), sizes: '96x96', type: 'image/png' }]
        });
    }
    updateUrl();
    if (window.Alpine) {
        const s = Alpine.store('ui');
        if (s.showLyricsInPlayer || s.lyricsPanelOpen) loadLyricsForCurrentTrack();
    }
    if (autoPlay) {
        audio.play().catch(e => console.error(e));
        masterPlay.innerHTML = pauseIcon;
        const fpMasterPlay = document.getElementById('fp-masterPlay');
        if (fpMasterPlay) fpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    } else {
        masterPlay.innerHTML = playIcon;
        const fpMasterPlay = document.getElementById('fp-masterPlay');
        if (fpMasterPlay) fpMasterPlay.innerHTML = '<svg viewBox="0 0 24 24" style="width:35px; height:35px; fill:black; margin-left:4px;"><path d="M8 5v14l11-7z"/></svg>';
    }
    updateQueueUI();
}

if (audio) {
    audio.onloadedmetadata = () => {
        const t = formatTime(audio.duration);
        document.getElementById('total-time').innerText = t;
        const fpTotalTime = document.getElementById('fp-total-time');
        if (fpTotalTime) fpTotalTime.innerText = t;
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

        if (window.Alpine) {
            const store = Alpine.store('ui');
            if ((store.showLyricsInPlayer || store.lyricsPanelOpen) && store.lyricsSynced && store.lyricsSynced.length > 0) {
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
    const fpMasterPlay = document.getElementById('fp-masterPlay');

    if(audio.paused) {
        audio.play();
        masterPlay.innerHTML = pauseIcon;
        if (fpMasterPlay) fpMasterPlay.innerHTML = fpPauseIcon;
    }
    else {
        audio.pause();
        masterPlay.innerHTML = playIcon;
        if (fpMasterPlay) fpMasterPlay.innerHTML = fpPlayIcon;
    }
}

function toggleShuffle() {
    isShuffle = !isShuffle;
    document.getElementById('shuffleBtn').classList.toggle('active', isShuffle);
    const fpShuffleBtn = document.getElementById('fp-shuffleBtn');
    if (fpShuffleBtn) fpShuffleBtn.classList.toggle('active', isShuffle);

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

    const loopInd = document.getElementById('loop-ind');
    if (loopInd) loopInd.style.display = (loopMode === 2) ? 'flex' : 'none';
    const fpLoopInd = document.getElementById('fp-loop-ind');
    if (fpLoopInd) {
        fpLoopInd.style.display = isActive ? 'block' : 'none';
        fpLoopInd.style.background = (loopMode === 2) ? 'var(--primary)' : 'white';
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

function showSection(id, doUpdateUrl = true) {
    if (window.Alpine) Alpine.store('ui').section = id;

    document.querySelectorAll('nav span').forEach(s => s.classList.remove('active'));
    if(document.getElementById('nav-' + id)) document.getElementById('nav-' + id).classList.add('active');

    document.querySelectorAll('.mob-nav-item').forEach(s => s.classList.remove('active'));
    if(document.getElementById('mob-nav-' + id)) document.getElementById('mob-nav-' + id).classList.add('active');

    window.scrollTo(0,0);
    currentSection = id;
    if (doUpdateUrl) updateUrl();
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
