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
// RECHERCHE CATÉGORISÉE (côté serveur, paginée)
// -----------------------------------------------------------------------------
// La recherche parcourait ALL_MUSIC_DATA, c'est-à-dire la bibliothèque entière
// sérialisée dans le HTML de chaque chargement de page : invisible sur une petite
// instance, intenable dès qu'elle grossit — et chaque frappe reparcourait tout le
// tableau. Elle passe désormais par api.php?action=search (voir api/search.php),
// qui ne renvoie qu'une page de résultats.
//
// Il n'existe donc plus qu'UNE implémentation des règles de recherche, côté
// serveur. Pas de second jeu de règles côté client susceptible de diverger — le
// piège déjà rencontré avec le découpage des genres, implémenté des deux côtés.
//
// Conséquence assumée : sans réseau, la recherche ne répond plus. Chercher un
// morceau qu'on ne pourrait de toute façon pas écouter (le flux audio vient du
// même serveur) n'avait pas d'intérêt propre.
const SEARCH_PAGE_SIZE = 30;
let SEARCH_STATE = { term: '', offset: 0, total: 0, tracks: [], artists: [], albums: [], reqId: 0, loading: false, failed: false };

// Récupère UNE page de résultats. Renvoie null si une frappe plus récente est
// partie entre-temps : sans ce garde-fou, une réponse lente pour « dra » écrase
// l'affichage de « drake » tapé juste après (course classique de la recherche au
// fil de la frappe, d'autant plus probable que les requêtes larges sont lentes).
async function fetchSearchPage(term, offset) {
    const reqId = ++SEARCH_STATE.reqId;
    const params = new URLSearchParams({ action: 'search', q: term, limit: String(SEARCH_PAGE_SIZE), offset: String(offset) });
    const res = await fetch('api.php?' + params.toString());
    const data = await res.json();
    if (reqId !== SEARCH_STATE.reqId) return null;
    if (!data || data.status !== 'success') throw new Error('search failed');
    return data;
}

async function loadSearchPage(append) {
    const term = SEARCH_STATE.term;
    SEARCH_STATE.loading = true;
    SEARCH_STATE.failed = false;
    paintSearchResults();
    try {
        const data = await fetchSearchPage(term, append ? SEARCH_STATE.offset : 0);
        if (data === null) return; // devancée par une frappe plus récente
        SEARCH_STATE.tracks = append ? SEARCH_STATE.tracks.concat(data.tracks.items) : data.tracks.items;
        SEARCH_STATE.offset = SEARCH_STATE.tracks.length;
        SEARCH_STATE.total = data.tracks.total;
        SEARCH_STATE.artists = data.artists;
        SEARCH_STATE.albums = data.albums;
    } catch (e) {
        SEARCH_STATE.failed = true;
        if (!append) { SEARCH_STATE.tracks = []; SEARCH_STATE.artists = []; SEARCH_STATE.albums = []; }
    } finally {
        SEARCH_STATE.loading = false;
        paintSearchResults();
    }
}

// Point d'entrée appelé à chaque frappe (onSearchInput) et à l'effacement du
// champ. Ne relance pas de requête si le terme n'a pas changé : la frappe est
// déjà débouncée en amont, mais d'autres chemins (retour de navigation, clic sur
// une recherche récente) réappellent cette fonction avec le même terme.
function renderSearchResults() {
    const container = document.getElementById('search-results');
    if (!container || !window.Alpine) return;
    const term = Alpine.store('ui').searchTerm.trim();

    if (!term) {
        SEARCH_STATE.term = '';
        SEARCH_STATE.tracks = []; SEARCH_STATE.artists = []; SEARCH_STATE.albums = [];
        SEARCH_STATE.total = 0; SEARCH_STATE.offset = 0; SEARCH_STATE.failed = false;
        container.innerHTML = '';
        return;
    }
    if (term === SEARCH_STATE.term && !SEARCH_STATE.failed && SEARCH_STATE.tracks.length) { paintSearchResults(); return; }

    SEARCH_STATE.term = term;
    SEARCH_STATE.offset = 0;
    loadSearchPage(false);
}

function paintSearchResults() {
    const container = document.getElementById('search-results');
    if (!container) return;
    const { tracks, artists, albums, loading, failed, total } = SEARCH_STATE;
    container.innerHTML = '';

    // Premier chargement : squelettes plutôt qu'un écran vide, comme les rangées
    // d'accueil. Sur une page suivante, la liste déjà affichée reste en place.
    if (loading && !tracks.length) {
        container.innerHTML = `<div class="search-skeletons">${'<div class="search-skeleton-line skeleton"></div>'.repeat(6)}</div>`;
        return;
    }
    if (failed && !tracks.length) {
        container.innerHTML = emptyStateHTML('ico-wifi-off', T('search_failed_title'), T('search_failed_hint'));
        return;
    }
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
        // Le total annoncé est celui du serveur, pas le nombre de lignes affichées :
        // l'ancienne version tronquait à 50 sans jamais dire qu'il y avait une suite.
        sec.innerHTML = `<h3 class="home-row-title">${escapeHTML(T('search_cat_tracks'))} <span class="search-count">${T('tracks_count_label', { n: total })}</span></h3>`;
        const list = document.createElement('div');
        list.className = 'track-list';
        // La lecture depuis les résultats doit enchaîner sur les résultats, pas sur
        // la bibliothèque complète : on aligne CURRENT_VIEW_DATA dessus.
        CURRENT_VIEW_DATA = tracks;
        tracks.forEach(t => list.appendChild(buildTrackRowElement(t, () => playTrackById(t.id))));
        sec.appendChild(list);

        if (tracks.length < total) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'btn search-load-more';
            more.disabled = SEARCH_STATE.loading;
            more.textContent = SEARCH_STATE.loading ? T('search_loading') : T('search_load_more');
            more.onclick = () => loadSearchPage(true);
            sec.appendChild(more);
        }
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
    const tracks = source;
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
// RADIO — file générée autour d'un morceau
// -----------------------------------------------------------------------------
// Entièrement côté client : ALL_MUSIC_DATA est déjà chargé, un aller-retour
// serveur n'apporterait rien de plus qu'un tri que le navigateur sait faire.
//
// Trois cercles concentriques autour du morceau de départ, du plus proche au
// plus lointain : même artiste, même genre, puis le reste de la bibliothèque.
const RADIO_MAX = 50;
// Au-delà de 2 titres consécutifs du même artiste, ça ne s'entend plus comme une
// radio mais comme la discographie d'un artiste jouée d'affilée.
const RADIO_MAX_CONSECUTIVE_SAME_ARTIST = 2;

function buildRadioQueue(seed) {
    if (typeof ALL_MUSIC_DATA === 'undefined' || !seed) return [];

    const seedArtists = new Set(splitArtistNames(seed.artist).map(n => n.toLowerCase()));
    // Multi-genres : une piste est "du même genre" dès qu'elle en partage AU
    // MOINS un avec le morceau de départ — c'est bien ce qu'on attend d'une
    // radio, une piste "Phonk, Nightcore" devant nourrir les deux radios.
    const seedGenres = new Set(trackGenres(seed).map(g => g.toLowerCase()));

    const pool = ALL_MUSIC_DATA.filter(t =>
        t.id !== seed.id);

    const sameArtist = [];
    const sameGenre = [];
    const rest = [];
    pool.forEach(t => {
        const artists = splitArtistNames(t.artist).map(n => n.toLowerCase());
        if (artists.some(a => seedArtists.has(a))) sameArtist.push(t);
        else if (trackGenres(t).some(g => seedGenres.has(g.toLowerCase()))) sameGenre.push(t);
        else rest.push(t);
    });

    // Chaque cercle est mélangé : sans ça, une radio relancée sur le même morceau
    // rejouerait exactement la même liste dans le même ordre.
    shuffleArray(sameArtist);
    shuffleArray(sameGenre);
    // Le cercle extérieur est le plus large : on le biaise vers les titres déjà
    // écoutés par d'autres, plutôt que de piocher uniformément dans tout le fonds.
    rest.sort((a, b) => (parseInt(b.play_count) || 0) - (parseInt(a.play_count) || 0));
    const restTop = rest.slice(0, 60);
    shuffleArray(restTop);

    // Proportions : la radio reste ancrée sur l'artiste de départ sans s'y
    // enfermer, et s'ouvre progressivement.
    const picked = [
        ...sameArtist.slice(0, 8),
        ...sameGenre.slice(0, 25),
        ...restTop.slice(0, 20),
    ];
    shuffleArray(picked);

    // Dernier passage : on écarte les répétitions d'artiste. Un titre qui
    // dépasserait la limite est repoussé plus loin plutôt que supprimé.
    const out = [seed];
    const deferred = [];
    const consecutiveOf = (list) => {
        const last = list[list.length - 1];
        return last ? splitArtistNames(last.artist).map(n => n.toLowerCase()) : [];
    };
    let streak = 1;
    for (const t of picked) {
        const artists = splitArtistNames(t.artist).map(n => n.toLowerCase());
        const prev = consecutiveOf(out);
        const sameAsPrev = artists.some(a => prev.includes(a));
        if (sameAsPrev && streak >= RADIO_MAX_CONSECUTIVE_SAME_ARTIST) {
            deferred.push(t);
            continue;
        }
        streak = sameAsPrev ? streak + 1 : 1;
        out.push(t);
        if (out.length >= RADIO_MAX) break;
    }
    // Les titres repoussés complètent la fin si la file est encore courte.
    for (const t of deferred) {
        if (out.length >= RADIO_MAX) break;
        out.push(t);
    }
    return out;
}

// Radio depuis une page Artiste/Album : le point de départ est le titre le plus
// écouté de l'entité, plus représentatif qu'une piste prise au hasard.
function startEntityRadio() {
    if (!window.Alpine) return;
    const isAlbum = Alpine.store('ui').section === 'album-page';
    const source = isAlbum
        ? ALL_MUSIC_DATA.filter(t => (t.album || '').trim().toLowerCase() === (currentAlbumName || '').trim().toLowerCase())
        : ALL_MUSIC_DATA.filter(t => splitArtistNames(t.artist).some(n => n.toLowerCase() === (currentArtistName || '').trim().toLowerCase()));
    const tracks = source;
    if (!tracks.length) {
        Alpine.store('ui').showToast(T('toast_no_music'), 'error');
        return;
    }
    const seed = tracks.reduce((best, t) =>
        (parseInt(t.play_count) || 0) > (parseInt(best.play_count) || 0) ? t : best, tracks[0]);
    startRadio(seed.id);
}

function startRadio(seedTrackId) {
    const seed = findTrackById(seedTrackId);
    if (!seed) return;
    const radio = buildRadioQueue(seed);
    if (radio.length <= 1) {
        // Bibliothèque trop petite pour une radio : on lit simplement le morceau
        // plutôt que d'annoncer une radio d'un seul titre.
        playTrackById(seedTrackId);
        return;
    }
    // currentPlaylistId à null : la radio n'est pas une playlist enregistrée, et
    // le laisser pointer sur une playlist ferait diverger la file de son contexte.
    currentPlaylistId = null;
    originalQueue = [...radio];
    queue = [...radio];
    currentIndex = 0;
    loadTrack(true);
    if (window.Alpine) Alpine.store('ui').showToast(T('radio_started', { name: seed.title }), 'success');
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
        { icon: 'ico-cast', label: T('ctx_start_radio'), action: () => startRadio(t.id) },
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


// Lance un mix du jour : sa liste de pistes devient la file, dans l'ordre tire par
// le serveur. On ne rejoue pas le tirage cote client -- l'ordre affiche doit etre
// celui qu'on entend.
function playDailyMix(key) {
    if (!window.Alpine) return;
    const mix = Alpine.store('ui').dailyMixes.find(m => m.key === key);
    if (!mix || !mix.tracks || !mix.tracks.length) return;

    currentPlaylistId = null;
    originalQueue = [...mix.tracks];
    // Le mix EST deja un ordre choisi : on n'y applique pas la lecture aleatoire,
    // qui le detruirait. Le bouton aleatoire reste disponible ensuite.
    queue = [...mix.tracks];
    currentIndex = 0;
    loadTrack(true);
    if (typeof updateQueueUI === 'function') updateQueueUI();
    Alpine.store('ui').showToast(T('mixes_started', { name: mix.title }), 'success');
}
