// =============================================================================
// DÉCOUVERTE & MANIPULATION
// =============================================================================
// Index Artistes/Albums, historique d'écoute, recherche catégorisée, menus
// contextuels, opérations sur la file d'attente, pile de navigation arrière.
//
// Chargé après les autres fichiers js/* : réutilise leurs fonctions
// (buildTrackRowElement, showSection, playTrackById, escapeHTML...) plutôt que
// de redéfinir des gabarits parallèles.

// -----------------------------------------------------------------------------
// HISTORIQUE D'ÉCOUTE (local au navigateur)
// -----------------------------------------------------------------------------
// Volontairement séparé de la table listen_events côté serveur : celle-ci sert à
// l'analytique et aux recommandations (agrégée, tous utilisateurs), et l'exposer
// par utilisateur demanderait un nouvel endpoint. Ici on ne reflète que ce que CE
// navigateur a réellement joué — suffisant pour "Reprendre l'écoute" et la page
// Historique, et sans aucun aller-retour réseau.
const LISTEN_HISTORY_KEY = 'purpleMusicListenHistory';
const LISTEN_HISTORY_MAX = 60;

function readListenHistory() {
    try {
        const raw = JSON.parse(localStorage.getItem(LISTEN_HISTORY_KEY) || '[]');
        return Array.isArray(raw) ? raw : [];
    } catch (e) {
        return [];
    }
}

// Appelée par loadTrack() à chaque changement de piste. Le morceau relancé
// remonte en tête plutôt que de créer un doublon, sinon réécouter deux fois la
// même chose remplirait l'historique d'une seule entrée répétée.
function pushListenHistory(trackId) {
    if (!trackId) return;
    const id = String(trackId);
    const next = [id, ...readListenHistory().filter(x => String(x) !== id)].slice(0, LISTEN_HISTORY_MAX);
    try { localStorage.setItem(LISTEN_HISTORY_KEY, JSON.stringify(next)); } catch (e) { /* quota : non bloquant */ }
    if (window.Alpine) Alpine.store('ui').rebuildHomeRows();
}

function clearListenHistory() {
    try { localStorage.removeItem(LISTEN_HISTORY_KEY); } catch (e) { /* idem */ }
    if (window.Alpine) {
        Alpine.store('ui').rebuildHomeRows();
        Alpine.store('ui').showToast(T('search_recent_clear'), 'success');
    }
    renderHistoryList();
}

// -----------------------------------------------------------------------------
// ÉTAT DE LECTURE EXPOSÉ À ALPINE
// -----------------------------------------------------------------------------
// Miroir minimal des variables globales de lecture, pour que le markup puisse
// afficher l'indicateur "en cours" sans interroger <audio> depuis chaque carte.
function syncPlaybackState() {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    const current = (typeof queue !== 'undefined' && queue[currentIndex]) ? queue[currentIndex] : null;
    store.currentTrackId = current ? current.id : null;
    store.isPlaying = !!(audio && !audio.paused && !audio.ended);
}

// -----------------------------------------------------------------------------
// PILE DE NAVIGATION
// -----------------------------------------------------------------------------
// Les boutons "Retour" des pages secondaires renvoyaient tous en dur vers
// l'accueil : arriver sur une page artiste depuis la page d'un album puis
// cliquer Retour ramenait à l'accueil au lieu de l'album. On garde donc la
// section précédente. Bornée à 20 : une pile non bornée grossit indéfiniment sur
// une session longue sans jamais servir au-delà des derniers pas.
let SECTION_STACK = [];

function pushSectionHistory(from) {
    if (!from) return;
    if (SECTION_STACK[SECTION_STACK.length - 1] === from) return;
    SECTION_STACK.push(from);
    if (SECTION_STACK.length > 20) SECTION_STACK.shift();
}

function goBackSection() {
    const prev = SECTION_STACK.pop();
    // showSection() repousserait la section courante sur la pile et on tournerait
    // en rond : on passe donc par un drapeau pour sauter cet enregistrement.
    _skipSectionHistoryPush = true;
    showSection(prev || 'accueil');
    _skipSectionHistoryPush = false;
}
let _skipSectionHistoryPush = false;

// -----------------------------------------------------------------------------
// INDEX ARTISTES
// -----------------------------------------------------------------------------
// Dérivé de ALL_MUSIC_DATA : un artiste = un nom issu de splitArtistNames(), donc
// "A & B" alimente les deux fiches plutôt qu'une seule entrée "A & B".
function buildArtistIndex() {
    if (typeof ALL_MUSIC_DATA === 'undefined') return [];
    const map = new Map();
    ALL_MUSIC_DATA.forEach(t => {
        if (hiddenGenres.includes(t.genre || 'Autre')) return;
        splitArtistNames(t.artist).forEach(name => {
            const key = name.toLowerCase();
            if (!map.has(key)) map.set(key, { name, count: 0, cover: null, topId: -1 });
            const entry = map.get(key);
            entry.count++;
            // Pochette de la piste la plus récente de l'artiste : repère visuel
            // stable, et cohérent avec ce que montre déjà showArtistPage().
            if (t.id > entry.topId) { entry.topId = t.id; entry.cover = t.cover; }
        });
    });
    return [...map.values()].sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
}

function showArtistsIndex(pushState = true) {
    const artists = buildArtistIndex();
    const grid = document.getElementById('artists-index-grid');
    const countEl = document.getElementById('artists-index-count');
    if (countEl) countEl.textContent = T('artists_count_label', { n: artists.length });
    if (grid) {
        grid.innerHTML = '';
        if (!artists.length) {
            grid.innerHTML = emptyStateHTML('ico-music-off', T('empty_library_title'), T('empty_library_hint'));
        } else {
            const frag = document.createDocumentFragment();
            artists.forEach((a, i) => {
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'entity-card fade-in-row';
                // Plafonné à 24 : au-delà, le bas d'une grille de 300 artistes
                // attendrait plusieurs secondes avant d'apparaître.
                card.style.setProperty('--i', Math.min(i, 24));
                card.innerHTML = `
                    <img src="covers/${escapeHTML(a.cover || 'default.png')}" loading="lazy" alt="" onerror="this.src='covers/default.png'">
                    <div class="entity-card-name">${escapeHTML(a.name)}</div>
                    <div class="entity-card-sub">${T('tracks_count_label', { n: a.count })}</div>
                `;
                card.onclick = () => showArtistPage(a.name);
                frag.appendChild(card);
            });
            grid.appendChild(frag);
        }
    }
    showSection('artists-page', pushState);
}

// -----------------------------------------------------------------------------
// INDEX ALBUMS
// -----------------------------------------------------------------------------
// Le champ album est optionnel : les pistes sans album ne créent pas de fiche
// "(sans titre)" fourre-tout, elles sont simplement absentes de cet index.
function buildAlbumIndex() {
    if (typeof ALL_MUSIC_DATA === 'undefined') return [];
    const map = new Map();
    ALL_MUSIC_DATA.forEach(t => {
        const album = (t.album || '').trim();
        if (!album) return;
        if (hiddenGenres.includes(t.genre || 'Autre')) return;
        const key = album.toLowerCase();
        if (!map.has(key)) map.set(key, { name: album, count: 0, cover: null, topId: -1, artists: new Set() });
        const entry = map.get(key);
        entry.count++;
        splitArtistNames(t.artist).forEach(n => entry.artists.add(n));
        if (t.id > entry.topId) { entry.topId = t.id; entry.cover = t.cover; }
    });
    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
}

function showAlbumsIndex(pushState = true) {
    const albums = buildAlbumIndex();
    const grid = document.getElementById('albums-index-grid');
    const countEl = document.getElementById('albums-index-count');
    if (countEl) countEl.textContent = T('albums_count_label', { n: albums.length });
    if (grid) {
        grid.innerHTML = '';
        if (!albums.length) {
            grid.innerHTML = emptyStateHTML('ico-album', T('empty_library_title'), T('empty_library_hint'));
        } else {
            const frag = document.createDocumentFragment();
            albums.forEach((a, i) => {
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'entity-card fade-in-row';
                card.style.setProperty('--i', Math.min(i, 24));
                card.innerHTML = `
                    <img src="covers/${escapeHTML(a.cover || 'default.png')}" loading="lazy" alt="" onerror="this.src='covers/default.png'">
                    <div class="entity-card-name">${escapeHTML(a.name)}</div>
                    <div class="entity-card-sub">${escapeHTML([...a.artists].join(', '))}</div>
                `;
                card.onclick = () => showAlbumPage(a.name);
                frag.appendChild(card);
            });
            grid.appendChild(frag);
        }
    }
    showSection('albums-page', pushState);
}

// -----------------------------------------------------------------------------
// PAGE HISTORIQUE
// -----------------------------------------------------------------------------
function renderHistoryList() {
    const container = document.getElementById('history-track-list');
    if (!container || typeof ALL_MUSIC_DATA === 'undefined') return;
    const byId = new Map(ALL_MUSIC_DATA.map(t => [String(t.id), t]));
    const tracks = readListenHistory().map(id => byId.get(String(id))).filter(Boolean);
    container.innerHTML = '';
    if (!tracks.length) {
        container.innerHTML = emptyStateHTML('ico-history', T('empty_queue_title'), T('empty_queue_hint'));
        return;
    }
    const frag = document.createDocumentFragment();
    tracks.forEach(t => frag.appendChild(buildTrackRowElement(t, () => playTrackById(t.id))));
    container.appendChild(frag);
    container.querySelectorAll('.marquee-wrap').forEach(applyMarqueeIfOverflowing);
}

function showHistoryPage(pushState = true) {
    renderHistoryList();
    showSection('history-page', pushState);
}

// -----------------------------------------------------------------------------
// RECHERCHE CATÉGORISÉE
// -----------------------------------------------------------------------------
// Un seul passage sur la bibliothèque produit les trois catégories : refiltrer
// séparément pour les artistes, les albums puis les titres traverserait la même
// liste trois fois pour un résultat identique.
function computeSearchResults(term) {
    const q = term.trim().toLowerCase();
    const tracks = [];
    const artistMap = new Map();
    const albumMap = new Map();
    if (!q || typeof ALL_MUSIC_DATA === 'undefined') return { tracks, artists: [], albums: [] };

    ALL_MUSIC_DATA.forEach(t => {
        if (hiddenGenres.includes(t.genre || 'Autre')) return;
        const title = (t.title || '').toLowerCase();
        const artist = (t.artist || '').toLowerCase();
        const album = (t.album || '').toLowerCase();
        const genre = (t.genre || '').toLowerCase();

        // Le titre/artiste étaient les deux seuls champs cherchés : l'album et le
        // genre sont désormais inclus, ce qui permet de retrouver "tout le phonk"
        // ou un album dont on ne connaît aucun titre.
        if (title.includes(q) || artist.includes(q) || album.includes(q) || genre.includes(q)) tracks.push(t);

        splitArtistNames(t.artist).forEach(name => {
            if (!name.toLowerCase().includes(q)) return;
            const key = name.toLowerCase();
            if (!artistMap.has(key)) artistMap.set(key, { name, count: 0, cover: t.cover, topId: t.id });
            const e = artistMap.get(key);
            e.count++;
            if (t.id > e.topId) { e.topId = t.id; e.cover = t.cover; }
        });

        const rawAlbum = (t.album || '').trim();
        if (rawAlbum && album.includes(q)) {
            const key = album;
            if (!albumMap.has(key)) albumMap.set(key, { name: rawAlbum, count: 0, cover: t.cover, topId: t.id });
            const e = albumMap.get(key);
            e.count++;
            if (t.id > e.topId) { e.topId = t.id; e.cover = t.cover; }
        }
    });

    // Le tri des titres suit le mode choisi dans la barre supérieure quand il est
    // disponible, pour que la recherche ne réordonne pas silencieusement autrement
    // que le reste de la bibliothèque.
    const sortSelect = document.getElementById('sortSelect');
    tracks.sort(compareTracksBySort(sortSelect ? sortSelect.value : 'recommended'));

    return {
        tracks,
        artists: [...artistMap.values()].sort((a, b) => b.count - a.count).slice(0, 12),
        albums: [...albumMap.values()].sort((a, b) => b.count - a.count).slice(0, 12),
    };
}

function renderSearchResults() {
    const container = document.getElementById('search-results');
    if (!container || !window.Alpine) return;
    const term = Alpine.store('ui').searchTerm;
    if (!term.trim()) { container.innerHTML = ''; return; }

    const { tracks, artists, albums } = computeSearchResults(term);
    container.innerHTML = '';

    if (!tracks.length && !artists.length && !albums.length) {
        container.innerHTML = emptyStateHTML('ico-search', T('search_no_results_title'), T('search_no_results_hint'));
        return;
    }

    // --- Artistes ---
    if (artists.length) {
        const sec = document.createElement('section');
        sec.className = 'search-section';
        sec.innerHTML = `<h3 class="home-row-title">${escapeHTML(T('search_cat_artists'))}</h3>`;
        const grid = document.createElement('div');
        grid.className = 'entity-grid entity-grid-round entity-grid-compact';
        artists.forEach((a, i) => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'entity-card fade-in-row';
            card.style.setProperty('--i', i);
            card.innerHTML = `
                <img src="covers/${escapeHTML(a.cover || 'default.png')}" loading="lazy" alt="" onerror="this.src='covers/default.png'">
                <div class="entity-card-name">${escapeHTML(a.name)}</div>
                <div class="entity-card-sub">${T('tracks_count_label', { n: a.count })}</div>
            `;
            card.onclick = () => showArtistPage(a.name);
            grid.appendChild(card);
        });
        sec.appendChild(grid);
        container.appendChild(sec);
    }

    // --- Albums ---
    if (albums.length) {
        const sec = document.createElement('section');
        sec.className = 'search-section';
        sec.innerHTML = `<h3 class="home-row-title">${escapeHTML(T('search_cat_albums'))}</h3>`;
        const grid = document.createElement('div');
        grid.className = 'entity-grid entity-grid-compact';
        albums.forEach((a, i) => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'entity-card fade-in-row';
            card.style.setProperty('--i', i);
            card.innerHTML = `
                <img src="covers/${escapeHTML(a.cover || 'default.png')}" loading="lazy" alt="" onerror="this.src='covers/default.png'">
                <div class="entity-card-name">${escapeHTML(a.name)}</div>
                <div class="entity-card-sub">${T('tracks_count_label', { n: a.count })}</div>
            `;
            card.onclick = () => showAlbumPage(a.name);
            grid.appendChild(card);
        });
        sec.appendChild(grid);
        container.appendChild(sec);
    }

    // --- Titres ---
    if (tracks.length) {
        const sec = document.createElement('section');
        sec.className = 'search-section';
        sec.innerHTML = `<h3 class="home-row-title">${escapeHTML(T('search_cat_tracks'))}</h3>`;
        const list = document.createElement('div');
        list.className = 'track-list';
        // Bornée à 50 : au-delà, la recherche devient une deuxième bibliothèque
        // paginée alors que l'objectif est de retrouver un morceau précis.
        const shown = tracks.slice(0, 50);
        // La lecture depuis les résultats doit enchaîner sur les résultats, pas sur
        // la bibliothèque complète : on aligne CURRENT_VIEW_DATA dessus.
        CURRENT_VIEW_DATA = shown;
        shown.forEach(t => list.appendChild(buildTrackRowElement(t, () => playTrackById(t.id))));
        sec.appendChild(list);
        container.appendChild(sec);
        list.querySelectorAll('.marquee-wrap').forEach(applyMarqueeIfOverflowing);
    }
}

function clearSearch() {
    if (!window.Alpine) return;
    Alpine.store('ui').searchTerm = '';
    const input = document.getElementById('searchInput');
    if (input) { input.value = ''; input.focus(); }
    renderSearchResults();
    filterAndSortTracks();
}

function applyRecentSearch(term) {
    if (!window.Alpine) return;
    Alpine.store('ui').searchTerm = term;
    const input = document.getElementById('searchInput');
    if (input) input.value = term;
    onSearchInput();
}

// -----------------------------------------------------------------------------
// LECTURE D'UNE ENTITÉ COMPLÈTE (page artiste / album)
// -----------------------------------------------------------------------------
function playEntityAll(shuffled) {
    const containerId = (window.Alpine && Alpine.store('ui').section === 'album-page')
        ? 'album-track-list' : 'artist-track-list';
    const container = document.getElementById(containerId);
    if (!container) return;

    // La liste affichée est la source de vérité (elle a déjà appliqué les filtres
    // de genre et le tri de la page) — la recalculer risquerait de diverger.
    const source = (containerId === 'album-track-list')
        ? ALL_MUSIC_DATA.filter(t => (t.album || '').trim().toLowerCase() === (currentAlbumName || '').trim().toLowerCase())
        : ALL_MUSIC_DATA.filter(t => splitArtistNames(t.artist).some(n => n.toLowerCase() === (currentArtistName || '').trim().toLowerCase()));
    const tracks = source.filter(t => !hiddenGenres.includes(t.genre || 'Autre'));
    if (!tracks.length) {
        if (window.Alpine) Alpine.store('ui').showToast(T('toast_no_music'), 'error');
        return;
    }

    currentPlaylistId = null;
    originalQueue = [...tracks];
    // Le bouton "Aléatoire" mélange cette file précise sans toucher au réglage
    // global de lecture aléatoire (isShuffle) : l'utilisateur demande un ordre
    // aléatoire ici, pas un changement de mode permanent.
    queue = shuffled ? shuffleArray([...tracks]) : [...tracks];
    currentIndex = 0;
    loadTrack(true);
}

// -----------------------------------------------------------------------------
// OPÉRATIONS SUR LA FILE D'ATTENTE
// -----------------------------------------------------------------------------
function findTrackById(id) {
    if (typeof ALL_MUSIC_DATA === 'undefined') return null;
    return ALL_MUSIC_DATA.find(t => String(t.id) === String(id)) || null;
}

// "Lire ensuite" : insère juste après la piste courante. originalQueue est mise à
// jour en parallèle, sinon désactiver puis réactiver la lecture aléatoire
// (qui repart de originalQueue) ferait disparaître l'insertion.
function playNextInQueue(trackId) {
    const track = findTrackById(trackId);
    if (!track) return;
    if (!queue.length) { playTrackById(trackId); return; }
    queue.splice(currentIndex + 1, 0, track);
    originalQueue.splice(Math.min(currentIndex + 1, originalQueue.length), 0, track);
    updateQueueUI();
    if (window.Alpine) Alpine.store('ui').showToast(T('toast_playing_next'), 'success');
}

function addToQueue(trackId) {
    const track = findTrackById(trackId);
    if (!track) return;
    if (!queue.length) { playTrackById(trackId); return; }
    queue.push(track);
    originalQueue.push(track);
    updateQueueUI();
    if (window.Alpine) Alpine.store('ui').showToast(T('toast_added_to_queue'), 'success');
}

function removeFromQueue(index) {
    if (index < 0 || index >= queue.length) return;
    // Retirer la piste en cours de lecture demanderait de décider quoi jouer à la
    // place : on l'interdit plutôt que d'improviser un comportement surprenant.
    if (index === currentIndex) return;
    const [removed] = queue.splice(index, 1);
    const origIdx = originalQueue.findIndex(t => t.id === removed.id);
    if (origIdx !== -1) originalQueue.splice(origIdx, 1);
    // L'index courant glisse d'un cran si la suppression a eu lieu avant lui.
    if (index < currentIndex) currentIndex--;
    updateQueueUI();
    if (window.Alpine) Alpine.store('ui').showToast(T('toast_removed_from_queue'), 'info');
}

// Réordonnancement par glisser-déposer (voir attachQueueDragHandlers()).
function moveQueueItem(from, to) {
    if (from === to || from < 0 || to < 0 || from >= queue.length || to >= queue.length) return;
    const [moved] = queue.splice(from, 1);
    queue.splice(to, 0, moved);
    // L'index de la piste en cours doit suivre son déplacement, sinon la lecture
    // "saute" sur une autre piste au prochain nextTrack().
    if (from === currentIndex) currentIndex = to;
    else if (from < currentIndex && to >= currentIndex) currentIndex--;
    else if (from > currentIndex && to <= currentIndex) currentIndex++;
    updateQueueUI();
}

function clearQueue() {
    // On garde la piste en cours : vider entièrement couperait la lecture, ce que
    // "vider la file" ne laisse pas attendre.
    const current = queue[currentIndex];
    queue = current ? [current] : [];
    originalQueue = [...queue];
    currentIndex = 0;
    updateQueueUI();
}

// -----------------------------------------------------------------------------
// MENUS CONTEXTUELS
// -----------------------------------------------------------------------------
let _contextMenuEl = null;

function closeContextMenu() {
    if (_contextMenuEl) { _contextMenuEl.remove(); _contextMenuEl = null; }
}

// Positionne le menu au curseur, en le rabattant dans la fenêtre s'il déborde
// (un menu ouvert près du bord droit/bas sortait sinon de l'écran).
function openContextMenu(event, items) {
    closeContextMenu();
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    items.forEach(item => {
        if (item.separator) {
            const sep = document.createElement('div');
            sep.className = 'context-menu-sep';
            menu.appendChild(sep);
            return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'context-menu-item' + (item.danger ? ' danger' : '');
        btn.innerHTML = `<svg class="ico" aria-hidden="true"><use href="#${item.icon}"></use></svg><span>${escapeHTML(item.label)}</span>`;
        btn.onclick = () => { closeContextMenu(); item.action(); };
        menu.appendChild(btn);
    });

    // Hors écran le temps de mesurer : lire offsetWidth avant insertion renverrait 0.
    menu.style.left = '-9999px';
    menu.style.top = '-9999px';
    document.body.appendChild(menu);
    const rect = menu.getBoundingClientRect();
    const x = Math.min(event.clientX, window.innerWidth - rect.width - 8);
    const y = Math.min(event.clientY, window.innerHeight - rect.height - 8);
    menu.style.left = Math.max(8, x) + 'px';
    menu.style.top = Math.max(8, y) + 'px';
    _contextMenuEl = menu;
}

function openTrackContextMenu(event, trackId) {
    const t = findTrackById(trackId);
    if (!t) return;
    const items = [
        { icon: 'ico-play', label: T('ctx_play'), action: () => playTrackById(t.id) },
        { icon: 'ico-play-next', label: T('ctx_play_next'), action: () => playNextInQueue(t.id) },
        { icon: 'ico-queue-add', label: T('ctx_add_queue'), action: () => addToQueue(t.id) },
        { separator: true },
        { icon: 'ico-artist', label: T('ctx_go_artist'), action: () => showArtistPage(splitArtistNames(t.artist)[0] || t.artist) },
    ];
    // L'entrée Album n'a de sens que si la piste en a un.
    if ((t.album || '').trim()) {
        items.push({ icon: 'ico-album', label: T('ctx_go_album'), action: () => showAlbumPage(t.album) });
    }
    items.push({ separator: true });
    items.push({ icon: 'ico-share', label: T('ctx_copy_link'), action: () => copyTrackLink(t.id) });
    openContextMenu(event, items);
}

function openPlaylistContextMenu(event, playlist) {
    openContextMenu(event, [
        { icon: 'ico-play', label: T('ctx_play'), action: () => playPlaylist(playlist.id, playlist.id) },
        { icon: 'ico-library', label: T('home_see_all'), action: () => openPlaylistDetail(playlist.id) },
        { separator: true },
        { icon: 'ico-share', label: T('ctx_copy_link'), action: () => copyPlaylistLink(playlist.id) },
    ]);
}

// navigator.clipboard n'existe qu'en contexte sécurisé (HTTPS ou localhost) :
// sur une instance auto-hébergée servie en HTTP simple, il est absent et l'appel
// lèverait une TypeError silencieuse. On retombe alors sur un champ temporaire.
function copyToClipboard(text) {
    const done = () => { if (window.Alpine) Alpine.store('ui').showToast(T('toast_link_copied'), 'success'); };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    } else {
        fallbackCopy(text, done);
    }
}

function fallbackCopy(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* copie indisponible : silencieux */ }
    ta.remove();
}

function copyTrackLink(trackId) {
    copyToClipboard(`${location.origin}${location.pathname}?v=${encodeURIComponent(trackId)}`);
}

function copyPlaylistLink(playlistId) {
    copyToClipboard(`${location.origin}${location.pathname}?page=playlist-detail&list=${encodeURIComponent(playlistId)}`);
}

// -----------------------------------------------------------------------------
// ÉTAT VIDE (gabarit partagé)
// -----------------------------------------------------------------------------
function emptyStateHTML(icon, title, hint) {
    return `
        <div class="empty-state">
            <svg class="ico" aria-hidden="true"><use href="#${icon}"></use></svg>
            <p class="empty-state-title">${escapeHTML(title)}</p>
            <p class="empty-state-hint">${escapeHTML(hint)}</p>
        </div>
    `;
}

// -----------------------------------------------------------------------------
// LISERÉ DE PROGRESSION
// -----------------------------------------------------------------------------
// Progression volontairement fausse : on ne connaît pas la durée réelle d'un
// changement de section. Elle monte vite jusqu'à 80% puis attend la fin réelle —
// même convention que les barres de navigation de YouTube/GitHub.
let _navProgressTimer = null;

function startNavProgress() {
    const bar = document.getElementById('nav-progress');
    if (!bar) return;
    clearTimeout(_navProgressTimer);
    bar.classList.add('active');
    bar.style.width = '0%';
    requestAnimationFrame(() => { bar.style.width = '80%'; });
}

function endNavProgress() {
    const bar = document.getElementById('nav-progress');
    if (!bar) return;
    bar.style.width = '100%';
    _navProgressTimer = setTimeout(() => {
        bar.classList.remove('active');
        bar.style.width = '0%';
    }, 220);
}

// -----------------------------------------------------------------------------
// BRANCHEMENTS GLOBAUX
// -----------------------------------------------------------------------------
document.addEventListener('click', (e) => {
    // Un clic dans le menu lui-même est déjà géré par le bouton concerné.
    if (_contextMenuEl && !_contextMenuEl.contains(e.target)) closeContextMenu();
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeContextMenu(); });
window.addEventListener('resize', closeContextMenu);
// Le menu est positionné en coordonnées viewport (position:fixed) : il resterait
// collé à l'écran au lieu de suivre la ligne à laquelle il se rapporte.
window.addEventListener('scroll', closeContextMenu, { passive: true });

// Service worker : uniquement en contexte sécurisé (HTTPS ou localhost). Une
// instance servie en HTTP simple sur le réseau local n'y a pas droit — l'appel
// lèverait une erreur au lieu d'être simplement ignoré, d'où la garde explicite.
if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(e => {
            // Échec d'enregistrement : l'app fonctionne exactement comme avant,
            // seule l'installabilité est perdue. Jamais d'erreur visible.
            console.warn('Service worker non enregistré', e);
        });
    });
}

// La restauration depuis l'URL (chargement initial ET Précédent/Suivant) est
// centralisée dans applyUrlState() (js/library.js) : ces écrans y sont traités
// comme les autres. Un second point de restauration ici les rendrait deux fois
// au chargement et divergerait à la première évolution.
