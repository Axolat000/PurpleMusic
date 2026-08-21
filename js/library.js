// Construit la rangée DOM d'une piste pour une liste triée/paginée (bibliothèque complète, page "Voir
// tout") -- factorisé pour être partagé entre renderTracksChunk() (#global-list) et renderBrowseChunk()
// (#browse-list), qui n'ont que leur conteneur/état de pagination de différent.
function buildTrackRowElement(t, onClick) {
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
            <button type="button" class="btn btn-danger" style="border-radius:8px;" onclick="confirmPostAction('${T('confirm_delete_generic')}', 'delete_track', {track_id: ${t.id}})">✕</button>
        `;
    }

    const isLiked = !!(parseInt(t.is_liked) || 0);

    const div = document.createElement('div');
    div.className = 'track-item';
    div.onclick = onClick;
    div.innerHTML = `
        <img src="covers/${safeCover}" loading="lazy" class="mini-cover" onerror="this.src='covers/default.png'">
        <div style="overflow:hidden;">
            <div class="marquee-wrap" style="font-weight:700; font-size:1.05em; margin-bottom:3px;"><span>${safeTitle}</span></div>
            <div style="font-size:0.85em; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                ${safeArtist} <span style="opacity:0.6;font-size:0.9em;">• ${safeGenre} • ▶ ${t.play_count || 0}</span>
            </div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;" onclick="event.stopPropagation()">
            <button type="button" class="like-btn${isLiked ? ' active' : ''}" title="${T('tooltip_like')}" onclick="toggleLikeUI(${t.id}, this)">
                <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                <span class="like-count">${t.like_count || 0}</span>
            </button>
            ${editButtons}
        </div>
    `;
    return div;
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
    chunk.forEach((t) => fragment.appendChild(buildTrackRowElement(t, () => playTrackById(t.id))));
    listContainer.appendChild(fragment);
    renderedCount += chunk.length;
    // Synchrone (comme le fait déjà loadTrack() pour #fp-title) : le fragment vient d'être inséré dans le
    // DOM réel, donc immédiatement mesurable — pas besoin d'attendre un repaint.
    listContainer.querySelectorAll('.marquee-wrap').forEach(applyMarqueeIfOverflowing);
}

// Pagination de la page "Voir tout" (#browse-list) -- même logique que renderTracksChunk() mais sur son
// propre conteneur/état, pour ne jamais toucher au tri de la bibliothèque de l'accueil.
function renderBrowseChunk() {
    const listContainer = document.getElementById('browse-list');
    if (!listContainer) return;
    const chunk = BROWSE_VIEW_DATA.slice(browseRenderedCount, browseRenderedCount + RENDER_CHUNK);
    if (browseRenderedCount === 0) listContainer.innerHTML = '';
    if (chunk.length === 0 && browseRenderedCount === 0) {
        listContainer.innerHTML = `<div style="padding:40px; text-align:center; color:#666;">${T('no_tracks_found')}</div>`;
        return;
    }

    const fragment = document.createDocumentFragment();
    chunk.forEach((t) => fragment.appendChild(buildTrackRowElement(t, () => playTrackById(t.id))));
    listContainer.appendChild(fragment);
    browseRenderedCount += chunk.length;
    listContainer.querySelectorAll('.marquee-wrap').forEach(applyMarqueeIfOverflowing);
}

const _browseObserver = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && browseRenderedCount < BROWSE_VIEW_DATA.length) {
        renderBrowseChunk();
    }
}, { rootMargin: "200px" });

// Comparateur partagé entre la bibliothèque de l'accueil (filterAndSortTracks) et la page "Voir tout"
// (openBrowseAll) -- une seule définition des modes de tri disponibles.
function compareTracksBySort(sortValue) {
    return (a, b) => {
        if (sortValue === 'recommended') {
            const ra = RECOMMENDED_RANK.has(a.id) ? RECOMMENDED_RANK.get(a.id) : Infinity;
            const rb = RECOMMENDED_RANK.has(b.id) ? RECOMMENDED_RANK.get(b.id) : Infinity;
            if (ra !== rb) return ra - rb;
            return b.id - a.id;
        }
        else if (sortValue === 'popular') {
            if (b.play_count !== a.play_count) return (b.play_count || 0) - (a.play_count || 0);
            return b.id - a.id;
        }
        else if (sortValue === 'date_desc') return b.id - a.id;
        else if (sortValue === 'date_asc') return a.id - b.id;
        else if (sortValue === 'alpha_asc') return a.title.localeCompare(b.title);
        else if (sortValue === 'alpha_desc') return b.title.localeCompare(a.title);
        else if (sortValue === 'artist') return a.artist.localeCompare(b.artist);
        return 0;
    };
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

    filtered.sort(compareTracksBySort(sortValue));
    CURRENT_VIEW_DATA = filtered;
    renderedCount = 0;
    renderTracksChunk();
}

// "Voir tout" (à côté d'Ajouts récents / Les plus écoutés) : ouvre une page dédiée pré-triée, séparée
// de la bibliothèque de l'accueil -- ne touche jamais #sortSelect/CURRENT_VIEW_DATA (contrairement à
// l'ancien seeAllHome() qui changeait le tri de l'accueil lui-même).
function openBrowseAll(sortValue, title, pushState = true) {
    browseSort = sortValue;
    let filtered = ALL_MUSIC_DATA.filter(t => !hiddenGenres.includes(t.genre || 'Autre'));
    filtered.sort(compareTracksBySort(sortValue));
    BROWSE_VIEW_DATA = filtered;
    browseRenderedCount = 0;
    if (window.Alpine) Alpine.store('ui').browseTitle = title;
    showSection('browse', pushState);
    renderBrowseChunk();
    const trigger = document.getElementById('browse-load-more-trigger');
    if (trigger) _browseObserver.observe(trigger);
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
    const sortParam = urlParams.get('sort');
    const videoParam = urlParams.get('v');
    const listParam = urlParams.get('list');

    if (pageParam === 'playlist-detail' && listParam) {
        openPlaylistDetail(listParam);
    } else if (pageParam === 'browse') {
        const sv = sortParam || 'date_desc';
        openBrowseAll(sv, T(sv === 'popular' ? 'sort_popular' : 'sort_recent'), false);
    } else if (pageParam) {
        showSection(pageParam, false);
    }
    if (listParam) currentPlaylistId = listParam;
    if (videoParam) playTrackById(videoParam, false);
});

window.onpopstate = function(event) { window.location.reload(); };

