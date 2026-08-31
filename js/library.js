// Découpe un champ genre multi-valeurs ("Phonk, Nightcore") en genres individuels.
//
// Portage JS de split_genres() (api/helpers.php) : les deux doivent rester
// alignés, sinon une piste serait rangée sous des genres différents selon que le
// calcul vient du serveur (statistiques) ou du client (pastilles, filtres).
//
// La barre oblique est un séparateur elle aussi : elle absorbe les anciennes
// valeurs composées du type "Phonk/Funk", qui n'étaient qu'un seul genre faute de
// pouvoir en attribuer plusieurs.
const GENRE_SPLIT_REGEX = /\s*[,;/]\s*/;
function splitGenres(raw) {
    if (!raw) return [];
    const out = [];
    const seen = new Set();
    for (const g of String(raw).split(GENRE_SPLIT_REGEX)) {
        const name = g.trim();
        if (!name) continue;
        const key = name.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        out.push(name);
    }
    return out;
}

// Genres d'une piste, avec repli sur "Autre" pour une piste non étiquetée —
// pour que tout appelant reçoive toujours au moins un genre à afficher/compter.
function trackGenres(t) {
    const g = splitGenres(t && t.genre);
    return g.length ? g : ['Autre'];
}

// Regroupement multi-artiste ("David Guetta, Sia", "A feat. B") : chaque nom individuel doit avoir son
// propre lien vers sa page Artiste plutôt qu'un seul lien vers la chaîne complète (voir showArtistPage()
// plus bas). Ordre important : les séparateurs les plus spécifiques/longs doivent être essayés avant les
// plus courts qui pourraient en être un préfixe.
const ARTIST_SPLIT_REGEX = /\s*,\s*|\s*&amp;\s*|\s*&\s*|\s+feat\.?\s+|\s+ft\.?\s+|\s+featuring\s+|\s+vs\.?\s+|\s+x\s+|\s+et\s+|\s*;\s*/gi;

// Mention de featuring encadrée : "(feat. X)", "[ft. Y]". Les parenthèses étaient
// conservées telles quelles, si bien que le découpage produisait des fiches
// artiste nommées "(feat. X" ou "Y)". On les retire avant le découpage, en
// gardant leur contenu — c'est bien un artiste supplémentaire.
const FEATURE_BRACKET_REGEX = /[([]\s*((?:feat|ft|featuring|avec|with)\b\.?\s*[^)\]]*)[)\]]/gi;

// Marqueur de featuring resté en tête d'un fragment après découpage. Cas observé
// dans l'index Artistes : "A, ft B" se découpait sur la virgule et produisait une
// fiche littéralement nommée "ft B" — le motif de découpage exige un espace AVANT
// "ft", absent en début de fragment. On nettoie donc chaque fragment séparément.
const LEADING_FEATURE_REGEX = /^(?:feat|ft|featuring|avec|with|and|et|x)\b\.?\s*/i;

// Retire UNIQUEMENT les parenthèses/crochets orphelins, c'est-à-dire ceux dont
// le pendant est parti dans un autre fragment lors du découpage.
//
// Un décapage inconditionnel des extrémités serait une régression : sur un alias
// légitime comme "Nightmargin (Casey Gu)" — une seule entité, pas un featuring —
// il retirerait la parenthèse fermante et laisserait "Nightmargin (Casey Gu".
function stripOrphanBrackets(s) {
    let out = s;
    let changed = true;
    while (changed) {
        changed = false;
        const opens = (out.match(/[([]/g) || []).length;
        const closes = (out.match(/[)\]]/g) || []).length;
        if (closes > opens && /^[)\]]/.test(out)) { out = out.slice(1).trim(); changed = true; }
        else if (opens > closes && /[([]$/.test(out)) { out = out.slice(0, -1).trim(); changed = true; }
        else if (closes > opens && /[)\]]$/.test(out)) { out = out.slice(0, -1).trim(); changed = true; }
        else if (opens > closes && /^[([]/.test(out)) { out = out.slice(1).trim(); changed = true; }
    }
    return out;
}

function splitArtistNames(str) {
    if (!str) return [];
    return str
        .replace(FEATURE_BRACKET_REGEX, ' $1 ')
        .split(ARTIST_SPLIT_REGEX)
        .map(s => stripOrphanBrackets(
                s.trim()
                 .replace(LEADING_FEATURE_REGEX, '')
                 // Les remplacements ci-dessus laissent des espaces multiples
                 // (" $1 " autour d'un featuring extrait) : sans ça, deux noms
                 // identiques à un espace près créeraient deux fiches artiste.
                 .replace(/\s{2,}/g, ' ')
                 .trim()
            ))
        .filter(Boolean);
}

// Rend le champ artiste d'une piste sous forme de lien(s) cliquables vers showArtistPage() -- un span par
// artiste si plusieurs figurent dans le champ (voir splitArtistNames() ci-dessus). Les valeurs stockées
// sont déjà échappées HTML côté serveur (sanitize_text()/htmlspecialchars) -- même convention que
// safeArtist plus bas dans ce fichier, pas de ré-échappement supplémentaire ici.
function artistLinksHTML(rawArtist) {
    const names = splitArtistNames(rawArtist);
    if (names.length <= 1) {
        const n = names[0] || rawArtist || '';
        return `<span class="artist-link" onclick="event.stopPropagation();showArtistPage('${n.replace(/'/g, "\\'")}')">${n}</span>`;
    }
    return names.map(n => `<span class="artist-link" onclick="event.stopPropagation();showArtistPage('${n.replace(/'/g, "\\'")}')">${n}</span>`).join(', ');
}

// Rend le nom d'album d'une piste sous forme de lien cliquable vers showAlbumPage() -- rien n'est rendu
// si la piste n'a pas d'album renseigné (album optionnel, contrairement à artist).
function albumLinkHTML(t) {
    if (!t.album) return '';
    return ` <span style="opacity:0.6;">•</span> <span class="artist-link" onclick="event.stopPropagation();showAlbumPage('${t.album.replace(/'/g, "\\'")}')">${t.album}</span>`;
}

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
    const jsSafeAlbum = escapeHTML(t.album || '').replace(/'/g, "\\'");

    // Modifier/Supprimer : réservés au déposant et aux admins, comme avant. Ils
    // passent d'une paire de boutons toujours visibles (deux pilules ✎/✕ sur
    // chaque ligne, très bruyantes sur une liste de 200 titres) à des boutons
    // icône révélés au survol/focus, cohérents avec le reste des listes.
    let editButtons = '';
    if (t.uploader_id == CURRENT_USER_ID || IS_ADMIN) {
        editButtons = `
            <button type="button" class="track-row-btn" aria-label="${escapeHTML(T('btn_edit'))}" title="${escapeHTML(T('btn_edit'))}"
                    onclick="openEditTrackModal(${t.id}, '${jsSafeTitle}', '${jsSafeArtist}', '${jsSafeGenre}', '${jsSafeAlbum}')">
                <svg class="ico ico-sm"><use href="#ico-edit"></use></svg>
            </button>
            <button type="button" class="track-row-btn danger" aria-label="${escapeHTML(T('btn_delete_short'))}" title="${escapeHTML(T('btn_delete_short'))}"
                    onclick="confirmPostAction('${T('confirm_delete_generic')}', 'delete_track', {track_id: ${t.id}})">
                <svg class="ico ico-sm"><use href="#ico-trash"></use></svg>
            </button>
        `;
    }

    const isLiked = !!(parseInt(t.is_liked) || 0);

    const div = document.createElement('div');
    div.className = 'track-item';
    div.dataset.trackId = String(t.id);
    div.onclick = onClick;
    // Clic droit (desktop) : mêmes actions que le menu contextuel des cartes.
    div.oncontextmenu = (e) => { e.preventDefault(); openTrackContextMenu(e, t.id); };
    div.innerHTML = `
        <div class="track-row-art">
            <img src="covers/${safeCover}" loading="lazy" alt="" class="mini-cover" onerror="this.src='covers/default.png'">
            <span class="track-row-play" aria-hidden="true"><svg class="ico ico-sm"><use href="#ico-play"></use></svg></span>
        </div>
        <div class="track-row-body">
            <div class="marquee-wrap track-row-title"><span>${safeTitle}</span></div>
            <div class="track-row-meta">
                ${artistLinksHTML(t.artist)}${albumLinkHTML(t)}
                <span class="track-row-dim">• ${escapeHTML(trackGenres(t).join(' · '))} • <span class="tabular">${t.play_count || 0}</span> ▶</span>
            </div>
        </div>
        <div class="track-row-actions" onclick="event.stopPropagation()">
            <button type="button" class="like-btn${isLiked ? ' active' : ''}" aria-label="${escapeHTML(T('tooltip_like'))}" title="${escapeHTML(T('tooltip_like'))}" onclick="toggleLikeUI(${t.id}, this)">
                <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                <span class="like-count tabular">${t.like_count || 0}</span>
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
    if (!searchInput || !window.Alpine) return;

    const searchTerm = searchInput.value.toLowerCase();
    const sortValue = Alpine.store('ui').sortValue;
    let filtered = ALL_MUSIC_DATA.filter(t =>
        t.title.toLowerCase().includes(searchTerm) || t.artist.toLowerCase().includes(searchTerm));

    filtered.sort(compareTracksBySort(sortValue));
    CURRENT_VIEW_DATA = filtered;
    renderedCount = 0;
    renderTracksChunk();
}

// "Voir tout" (à côté d'Ajouts récents / Les plus écoutés) : ouvre une page dédiée pré-triée, séparée
// de la bibliothèque de l'accueil -- ne touche jamais $store.ui.sortValue/CURRENT_VIEW_DATA
// (contrairement à l'ancien seeAllHome() qui changeait le tri de l'accueil lui-même).
function openBrowseAll(sortValue, title, pushState = true) {
    browseSort = sortValue;
    let filtered = [...ALL_MUSIC_DATA];
    filtered.sort(compareTracksBySort(sortValue));
    BROWSE_VIEW_DATA = filtered;
    browseRenderedCount = 0;
    if (window.Alpine) Alpine.store('ui').browseTitle = title;
    showSection('browse', pushState);
    renderBrowseChunk();
    const trigger = document.getElementById('browse-load-more-trigger');
    if (trigger) _browseObserver.observe(trigger);
}

let currentArtistName = null;
let currentAlbumName = null;
let artistBioToken = 0;

// -----------------------------------------------------------------------------
// FIL D'ARIANE
// -----------------------------------------------------------------------------
// Reflete le chemin REELLEMENT emprunte, pas une hierarchie theorique : arriver
// sur un album depuis une page artiste ne raconte pas la meme chose qu'y arriver
// depuis l'index Albums, et afficher toujours « Albums > X » mentirait dans le
// premier cas.
//
// Le fil s'arrete a l'entite. Un morceau n'a pas d'ecran a lui (il se joue, il
// ne s'ouvre pas) : il n'y a rien a empiler apres l'album, malgre l'intuition
// "Artiste > Album > Morceau".
//
// Le bouton Retour reste a cote : il suit la pile de navigation reelle
// (goBackSection), qui peut ramener ailleurs que le parent -- les deux repondent
// a deux questions differentes, "d'ou je viens" et "ou je suis".
let BREADCRUMB = [];

function renderBreadcrumb(containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    el.innerHTML = '';
    BREADCRUMB.forEach((item, i) => {
        if (i > 0) {
            const sep = document.createElement('span');
            sep.className = 'breadcrumb-sep';
            sep.setAttribute('aria-hidden', 'true');
            sep.textContent = '\u203A';
            el.appendChild(sep);
        }
        // Le dernier maillon est la page courante : ni lien ni bouton, et
        // aria-current pour que la position soit annoncee, pas seulement vue.
        if (i === BREADCRUMB.length - 1) {
            const cur = document.createElement('span');
            cur.className = 'breadcrumb-current';
            cur.setAttribute('aria-current', 'page');
            cur.textContent = item.label;
            el.appendChild(cur);
        } else {
            const link = document.createElement('button');
            link.type = 'button';
            link.className = 'breadcrumb-link';
            link.textContent = item.label;
            link.onclick = item.go;
            el.appendChild(link);
        }
    });
}


// Rend une liste de pistes (déjà filtrée/triée par l'appelant) dans un conteneur -- même
// buildTrackRowElement() que la bibliothèque, pas de gabarit HTML séparé pour ces pages.
function renderTrackListInto(containerId, tracks) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    if (!tracks.length) {
        container.innerHTML = `<div style="padding:40px; text-align:center; color:#666;">${T('no_tracks_found')}</div>`;
        return;
    }
    const fragment = document.createDocumentFragment();
    tracks.forEach(t => fragment.appendChild(buildTrackRowElement(t, () => playTrackById(t.id))));
    container.appendChild(fragment);
}

// Page Artiste : regroupe toutes les pistes dont le champ artiste contient ce nom (voir
// splitArtistNames()) -- comparaison insensible à la casse pour tolérer les variations de saisie.
function showArtistPage(name, pushState = true) {
    currentArtistName = name;
    const norm = name.trim().toLowerCase();
    const tracks = ALL_MUSIC_DATA.filter(t => splitArtistNames(t.artist).some(n => n.toLowerCase() === norm))
        .sort((a, b) => b.id - a.id);

    document.getElementById('artist-page-title').innerText = name;
    document.getElementById('artist-page-count').innerText = T('tracks_count_label', { n: tracks.length });

    // Pochette de la piste la plus récente (id le plus élevé) utilisée comme photo de profil + fond flouté.
    const heroCover = 'covers/' + (tracks.length ? (tracks[0].cover || 'default.png') : 'default.png');
    const pfpImg = document.getElementById('artist-pfp');
    const heroBgImg = document.getElementById('artist-hero-bg-img');
    if (pfpImg) pfpImg.src = heroCover;
    if (heroBgImg) heroBgImg.src = heroCover;

    BREADCRUMB = [
        { label: T('nav_artists'), go: () => showArtistsIndex() },
        { label: name },
    ];
    renderBreadcrumb('artist-breadcrumb');

    renderTrackListInto('artist-track-list', tracks);
    // L'etat du bouton "suivre" depend de l'artiste affiche : il se remet a jour a
    // chaque ouverture de page, pas seulement au chargement de l'app.
    if (typeof refreshFollowButton === 'function') refreshFollowButton();
    fetchArtistBio(name);
    showSection('artist-page', pushState);
}

// Page Album : regroupe toutes les pistes dont le champ album correspond (insensible à la casse) --
// pas de table albums séparée, le nom lui-même sert de clé de regroupement (voir Track.album).
function showAlbumPage(name, pushState = true) {
    currentAlbumName = name;
    const norm = name.trim().toLowerCase();
    const tracks = ALL_MUSIC_DATA.filter(t => (t.album || '').trim().toLowerCase() === norm)
        .sort((a, b) => b.id - a.id);

    document.getElementById('album-page-title').innerText = name;
    document.getElementById('album-page-count').innerText = T('tracks_count_label', { n: tracks.length });

    const artistMap = new Map();
    tracks.forEach(t => {
        splitArtistNames(t.artist).forEach(n => {
            const key = n.toLowerCase();
            if (!artistMap.has(key)) artistMap.set(key, n);
        });
    });
    const artistsEl = document.getElementById('album-page-artists');
    if (artistsEl) {
        artistsEl.innerHTML = [...artistMap.values()]
            .map(n => `<span class="artist-link" onclick="showArtistPage('${n.replace(/'/g, "\\'")}')">${n}</span>`)
            .join(', ');
    }

    const heroCover = 'covers/' + (tracks.length ? (tracks[0].cover || 'default.png') : 'default.png');
    const pfpImg = document.getElementById('album-pfp');
    const heroBgImg = document.getElementById('album-hero-bg-img');
    if (pfpImg) pfpImg.src = heroCover;
    if (heroBgImg) heroBgImg.src = heroCover;

    // currentSection vaut encore la section QUITTEE ici (showSection() n'est
    // appele qu'en fin de fonction) : c'est ce qui permet de savoir si on arrive
    // depuis une page artiste.
    const parentArtist = (currentSection === 'artist-page' && currentArtistName) ? currentArtistName : null;
    BREADCRUMB = parentArtist
        ? [
            { label: T('nav_artists'), go: () => showArtistsIndex() },
            { label: parentArtist, go: () => showArtistPage(parentArtist) },
            { label: name },
        ]
        : [
            { label: T('nav_albums'), go: () => showAlbumsIndex() },
            { label: name },
        ];
    renderBreadcrumb('album-breadcrumb');

    renderTrackListInto('album-track-list', tracks);
    showSection('album-page', pushState);
}

// Biographie Wikipedia : appel REST public direct depuis le navigateur (CORS ouvert), aucune dépendance
// serveur. Un jeton évite qu'une réponse tardive d'une ancienne recherche n'écrase la bio de l'artiste
// affiché entre-temps si l'utilisateur navigue vite entre plusieurs artistes.
// Biographie d'artiste — désormais servie par api.php?action=artist_bio, qui
// interroge Wikipédia UNE fois puis met le résultat en cache en base.
//
// Auparavant chaque navigateur appelait Wikipédia directement, à chaque
// ouverture d'une page artiste : jusqu'à trois requêtes (résumé, recherche en
// cas d'homonymie, résumé de la page trouvée) refaites par chaque visiteur, pour
// un contenu qui ne bouge pratiquement jamais. La résolution d'homonymie vit
// maintenant dans wikipedia_summary() (api/helpers.php).
//
// Le jeton empêche la réponse tardive d'un artiste précédent d'écraser la bio de
// celui affiché entre-temps si l'utilisateur navigue vite.
async function fetchArtistBio(name) {
    const bioEl = document.getElementById('artist-page-bio');
    if (!bioEl) return;
    const myToken = ++artistBioToken;
    bioEl.innerText = T('loading_bio');
    const lang = (typeof LANG !== 'undefined' && LANG === 'en') ? 'en' : 'fr';
    try {
        const res = await fetch(`api.php?action=artist_bio&name=${encodeURIComponent(name)}&lang=${lang}`);
        const data = await res.json();
        if (myToken !== artistBioToken) return;
        if (data && data.extract) {
            bioEl.innerHTML = escapeHTML(data.extract) +
                (data.url ? ` <a href="${escapeHTML(data.url)}" target="_blank" rel="noopener noreferrer">${escapeHTML(T('wikipedia_link'))}</a>` : '');
        } else {
            bioEl.innerText = T('no_bio_available');
        }
    } catch (e) {
        if (myToken === artistBioToken) bioEl.innerText = T('no_bio_available');
    }
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

    const trigger = document.getElementById('load-more-trigger');
    if (trigger) _observer.observe(trigger);

    filterAndSortTracks();

    applyUrlState(new URLSearchParams(window.location.search), { startPlayback: true });
});

/**
 * Restaure l'écran décrit par les paramètres d'URL, sans jamais recharger la page.
 *
 * Partagée entre le premier chargement et le bouton Précédent/Suivant du
 * navigateur : les deux doivent aboutir exactement au même écran, et les
 * dupliquer les ferait diverger au premier écran ajouté.
 *
 * @param {URLSearchParams} params
 * @param {{startPlayback?: boolean}} opts  startPlayback : ne charge la piste ?v=
 *        qu'au premier affichage. Sur un retour arrière, relancer la piste de
 *        l'entrée d'historique couperait la lecture en cours — le bouton
 *        Précédent doit changer d'écran, pas de morceau.
 */
function applyUrlState(params, opts = {}) {
    const pageParam = params.get('page');
    const sortParam = params.get('sort');
    const videoParam = params.get('v');
    const listParam = params.get('list');
    const nameParam = params.get('name');

    // pushState=false partout : c'est l'URL qui pilote l'écran ici, réécrire
    // l'historique en réponse à une navigation dans l'historique le corromprait.
    if (pageParam === 'playlist-detail' && listParam) {
        openPlaylistDetail(listParam);
    } else if (pageParam === 'playlist-detail') {
        // URL de détail sans identifiant de playlist (ancien lien, ou partage
        // tronqué) : on retombe sur la liste des playlists plutôt que d'afficher
        // la coquille vide du détail, qui n'aurait que son bouton Retour.
        showSection('playlists', false);
    } else if (pageParam === 'browse') {
        const sv = sortParam || 'date_desc';
        openBrowseAll(sv, T(sv === 'popular' ? 'sort_popular' : 'sort_recent'), false);
    } else if (pageParam === 'artist-page' && nameParam) {
        showArtistPage(nameParam, false);
    } else if (pageParam === 'album-page' && nameParam) {
        showAlbumPage(nameParam, false);
    } else if (pageParam === 'artists-page') {
        showArtistsIndex(false);
    } else if (pageParam === 'albums-page') {
        showAlbumsIndex(false);
    } else if (pageParam === 'history-page') {
        showHistoryPage(false);
    } else if (pageParam === 'stats-page') {
        showStatsPage(false);
    } else if (pageParam) {
        showSection(pageParam, false);
    } else {
        showSection('accueil', false);
    }

    if (listParam) currentPlaylistId = listParam;
    if (opts.startPlayback && videoParam) playTrackById(videoParam, false);
}

// Précédent/Suivant du navigateur.
//
// Auparavant : window.location.reload(). Revenir en arrière rechargeait donc
// toute l'application — bibliothèque entière re-téléchargée, lecture coupée,
// défilement perdu — pour un simple changement d'écran. On rejoue désormais
// l'état de l'URL côté client.
//
// La pile de retour interne (SECTION_STACK, js/discovery.js) est indépendante :
// elle sert aux boutons "Retour" de l'app. On la neutralise le temps de la
// restauration pour ne pas y empiler l'écran quitté, sinon le bouton Retour de
// l'app remonterait des écrans déjà défaits par le navigateur.
window.addEventListener('popstate', () => {
    const skipBefore = typeof _skipSectionHistoryPush !== 'undefined' ? _skipSectionHistoryPush : false;
    _skipSectionHistoryPush = true;
    try {
        applyUrlState(new URLSearchParams(window.location.search), { startPlayback: false });
    } finally {
        _skipSectionHistoryPush = skipBefore;
    }
});

