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

// Cache mémoire des paroles, par identifiant de piste.
//
// Le serveur les met déjà en cache en base (colonnes lyrics_synced/lyrics_plain),
// mais le garde-fou existant ne couvrait QUE la piste courante : revenir sur un
// morceau déjà consulté relançait une requête complète à chaque fois. En mémoire
// et non en localStorage : des paroles complètes pour une longue session
// pourraient saturer le quota, alors qu'ici tout disparaît à la fermeture de
// l'onglet — le cache serveur prend le relais au rechargement.
const LYRICS_CACHE = new Map();
const LYRICS_CACHE_MAX = 80;

function cacheLyrics(trackId, payload) {
    // Éviction du plus ancien inséré (Map conserve l'ordre d'insertion) : borne
    // simple, les entrées étant toutes de taille comparable.
    if (LYRICS_CACHE.size >= LYRICS_CACHE_MAX) {
        LYRICS_CACHE.delete(LYRICS_CACHE.keys().next().value);
    }
    LYRICS_CACHE.set(String(trackId), payload);
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
    store.lyricsActiveIndex = -1;

    // Piste déjà consultée dans cette session : restitution immédiate, sans
    // requête ni état de chargement visible.
    const cached = !force ? LYRICS_CACHE.get(String(track.id)) : null;
    if (cached) {
        store.lyricsSynced = cached.synced;
        store.lyricsPlain = cached.plain;
        store.lyricsFound = cached.found;
        store.lyricsLoading = false;
        return;
    }

    store.lyricsLoading = true;
    store.lyricsFound = null;
    store.lyricsSynced = [];
    store.lyricsPlain = '';

    try {
        const res = await fetch('api.php?action=get_lyrics&q=' + track.id);
        const data = await res.json();
        // La piste a pu changer pendant l'attente de la réponse : on ignore un résultat périmé.
        if (store.lyricsTrackId !== track.id) return;
        const synced = data.synced ? parseLRC(data.synced) : [];
        const plain = data.plain || '';
        const found = !!data.found;
        store.lyricsSynced = synced;
        store.lyricsPlain = plain;
        store.lyricsFound = found;
        // Une absence de paroles est mise en cache elle aussi : sans ça, un
        // morceau instrumental relancerait une requête à chaque réécoute.
        cacheLyrics(track.id, { synced, plain, found });
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
        // Le titre n'est mesurable qu'une fois la surface reellement affichee :
        // tant qu'elle est fermee, clientWidth vaut 0 et applyMarqueeIfOverflowing()
        // refuse (a juste titre) de trancher. On relance donc la mesure ici.
        if (window.Alpine) Alpine.nextTick(() => applyMarqueeIfOverflowing(document.getElementById('fp-title')));

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
        // Le titre n'est mesurable qu'une fois la surface reellement affichee :
        // tant qu'elle est fermee, clientWidth vaut 0 et applyMarqueeIfOverflowing()
        // refuse (a juste titre) de trancher. On relance donc la mesure ici.
        if (window.Alpine) Alpine.nextTick(() => applyMarqueeIfOverflowing(document.getElementById('fp-title')));
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
        // Le titre n'est mesurable qu'une fois la carte réellement affichée :
        // fermée, elle rapporte clientWidth = 0 et applyMarqueeIfOverflowing()
        // refuse (à juste titre) de trancher. D'où cette relance à l'ouverture.
        Alpine.nextTick(() => applyMarqueeIfOverflowing(document.getElementById('dp-title')));
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
// File d'attente : liste manipulable (réordonnancement par glisser-déposer,
// retrait d'une piste), et non plus une simple liste consultable.
//
// Structurée en trois blocs — déjà joué / en cours / à suivre — plutôt qu'une
// suite plate où seule une pastille distinguait la piste courante : on voit d'un
// coup d'œil ce qui reste à venir.
function renderQueueListInto(container) {
    if (!container) return;
    container.innerHTML = '';
    if (queue.length === 0) {
        container.innerHTML = typeof emptyStateHTML === 'function'
            ? emptyStateHTML('ico-queue', T('empty_queue_title'), T('empty_queue_hint'))
            : `<p style="color:var(--text-muted);">${T('queue_empty')}</p>`;
        return;
    }

    const addLabel = (text, extraClass = '') => {
        const p = document.createElement('p');
        p.className = 'queue-group-label ' + extraClass;
        p.textContent = text;
        container.appendChild(p);
    };

    if (currentIndex > 0) addLabel(T('queue_history'));

    queue.forEach((track, index) => {
        if (index === currentIndex) addLabel(T('queue_now_playing'), 'queue-group-current');
        if (index === currentIndex + 1) addLabel(T('queue_up_next'));

        const isCurrent = index === currentIndex;
        const isPast = index < currentIndex;

        const div = document.createElement('div');
        div.className = 'queue-item' + (isCurrent ? ' active' : '') + (isPast ? ' past' : '');
        // Seules les pistes à venir se réordonnent : déplacer un morceau déjà joué
        // ou celui en cours n'a pas de sens et compliquerait le suivi de currentIndex.
        div.draggable = !isCurrent && !isPast;
        div.dataset.index = String(index);

        div.innerHTML = `
            <span class="queue-drag-handle" aria-hidden="true">
                <svg class="ico ico-sm"><use href="#ico-drag"></use></svg>
            </span>
            <img src="covers/${escapeHTML(track.cover)}" loading="lazy" alt="" class="queue-item-cover" onerror="this.src='covers/default.png'">
            <div class="queue-item-body">
                <div class="queue-item-title">${escapeHTML(track.title)}</div>
                <div class="queue-item-artist">${escapeHTML(track.artist)}</div>
            </div>
            ${isCurrent ? '<span class="now-playing-bars"><span></span><span></span><span></span></span>' : ''}
            ${isCurrent ? '' : `<button type="button" class="queue-item-remove" aria-label="${escapeHTML(T('queue_remove'))}" title="${escapeHTML(T('queue_remove'))}">
                <svg class="ico ico-sm"><use href="#ico-close"></use></svg>
            </button>`}
        `;

        div.onclick = () => { currentIndex = index; loadTrack(true); };
        const removeBtn = div.querySelector('.queue-item-remove');
        if (removeBtn) {
            removeBtn.onclick = (e) => {
                // Sans cette coupure, le clic remonterait au conteneur et lancerait
                // la piste qu'on vient de demander à retirer.
                e.stopPropagation();
                removeFromQueue(index);
            };
        }
        container.appendChild(div);
    });

    attachQueueDragHandlers(container);
}

// Glisser-déposer HTML5 natif (pas de bibliothèque tierce) : l'app n'a aucune
// dépendance JS hors Alpine, en ajouter une pour un seul écran serait
// disproportionné.
function attachQueueDragHandlers(container) {
    let dragFrom = null;

    container.querySelectorAll('.queue-item[draggable="true"]').forEach(item => {
        item.addEventListener('dragstart', (e) => {
            dragFrom = parseInt(item.dataset.index, 10);
            item.classList.add('dragging');
            // Firefox n'amorce pas de glisser sans données transférées.
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(dragFrom));
        });
        item.addEventListener('dragend', () => {
            item.classList.remove('dragging');
            container.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
        });
        item.addEventListener('dragover', (e) => {
            // preventDefault est obligatoire : sans lui l'élément refuse le dépôt.
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!item.classList.contains('dragging')) item.classList.add('drag-over');
        });
        item.addEventListener('dragleave', () => item.classList.remove('drag-over'));
        item.addEventListener('drop', (e) => {
            e.preventDefault();
            item.classList.remove('drag-over');
            const to = parseInt(item.dataset.index, 10);
            if (dragFrom === null || Number.isNaN(to)) return;
            moveQueueItem(dragFrom, to);
            dragFrom = null;
            if (window.Alpine) Alpine.store('ui').showToast(T('toast_queue_reordered'), 'success');
        });
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
    const res = await fetch('api.php?action=get_playlist_tracks&q=' + ids);
    const data = await res.json();
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

    renderPlaylistDetailTracks(true);

    try {
        const res = await fetch('api.php?action=get_playlist_tracks&q=' + playlist.song_ids);
        const data = await res.json();
        if (store.playlistDetail && store.playlistDetail.id == playlist.id) {
            store.playlistDetail.tracks = data;
            store.playlistDetail.loading = false;
            renderPlaylistDetailTracks(false);
        }
    } catch (e) {
        console.error(e);
        if (store.playlistDetail && store.playlistDetail.id == playlist.id) {
            store.playlistDetail.loading = false;
            renderPlaylistDetailTracks(false);
        }
    }
}

// Rend la liste du détail d'une playlist. En JS et non via un x-for Alpine : le
// glisser-déposer manipule le DOM directement, et un re-rendu réactif au milieu
// d'un glissement remplacerait les éléments en cours de déplacement.
function renderPlaylistDetailTracks(loading) {
    const container = document.getElementById('playlist-detail-list');
    if (!container || !window.Alpine) return;
    const pd = Alpine.store('ui').playlistDetail;
    if (!pd) return;

    container.innerHTML = '';

    if (loading) {
        // Squelettes plutôt qu'un « Chargement... » : la liste garde sa hauteur,
        // le contenu ne saute pas quand les vraies lignes arrivent.
        for (let i = 0; i < 5; i++) {
            const row = document.createElement('div');
            row.className = 'track-item';
            row.innerHTML = `
                <div class="skeleton" style="width:48px; height:48px; border-radius:var(--radius-sm);"></div>
                <div style="width:100%;">
                    <div class="skeleton skeleton-line" style="width:45%;"></div>
                    <div class="skeleton skeleton-line short"></div>
                </div>
                <div></div>`;
            container.appendChild(row);
        }
        return;
    }

    if (!pd.tracks.length) {
        container.innerHTML = emptyStateHTML('ico-music-off', T('empty_queue_title'), T('empty_queue_hint'));
        return;
    }

    const frag = document.createDocumentFragment();
    pd.tracks.forEach((t, i) => {
        const row = buildTrackRowElement(t, () => playTrackInPlaylistDetail(t.id));
        row.classList.add('fade-in-row');
        row.style.setProperty('--i', Math.min(i, 24));
        if (pd.canEdit) {
            row.draggable = true;
            row.dataset.plIndex = String(i);
            // Poignée insérée en tête de ligne : signale que la ligne est
            // déplaçable, ce qu'un draggable seul ne montre pas.
            const handle = document.createElement('span');
            handle.className = 'queue-drag-handle playlist-drag-handle';
            handle.setAttribute('aria-hidden', 'true');
            handle.innerHTML = '<svg class="ico ico-sm"><use href="#ico-drag"></use></svg>';
            row.insertBefore(handle, row.firstChild);
        }
        frag.appendChild(row);
    });
    container.appendChild(frag);
    container.querySelectorAll('.marquee-wrap').forEach(applyMarqueeIfOverflowing);
    if (pd.canEdit) attachPlaylistDragHandlers(container);
}

function attachPlaylistDragHandlers(container) {
    let dragFrom = null;

    container.querySelectorAll('.track-item[draggable="true"]').forEach(item => {
        item.addEventListener('dragstart', (e) => {
            dragFrom = parseInt(item.dataset.plIndex, 10);
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(dragFrom));
        });
        item.addEventListener('dragend', () => {
            item.classList.remove('dragging');
            container.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
        });
        item.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!item.classList.contains('dragging')) item.classList.add('drag-over');
        });
        item.addEventListener('dragleave', () => item.classList.remove('drag-over'));
        item.addEventListener('drop', (e) => {
            e.preventDefault();
            item.classList.remove('drag-over');
            const to = parseInt(item.dataset.plIndex, 10);
            if (dragFrom === null || Number.isNaN(to) || dragFrom === to) return;
            movePlaylistTrack(dragFrom, to);
            dragFrom = null;
        });
    });
}

// Déplace une piste puis persiste le nouvel ordre. L'affichage est mis à jour
// immédiatement (optimiste) : attendre l'aller-retour réseau donnerait
// l'impression que le glisser-déposer n'a pas fonctionné.
async function movePlaylistTrack(from, to) {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    const pd = store.playlistDetail;
    if (!pd || !pd.canEdit) return;

    const tracks = [...pd.tracks];
    if (from < 0 || to < 0 || from >= tracks.length || to >= tracks.length) return;
    const previous = [...tracks];
    const [moved] = tracks.splice(from, 1);
    tracks.splice(to, 0, moved);
    pd.tracks = tracks;
    const newIds = tracks.map(t => t.id).join(',');
    pd.song_ids = newIds;
    renderPlaylistDetailTracks(false);

    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('playlist_id', pd.id);
        fd.append('song_ids', newIds);
        const res = await fetch('api.php?action=playlist_reorder', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message || 'reorder failed');
        // Le cache client sert à rouvrir la playlist sans requête : sans cette
        // mise à jour, revenir dessus réafficherait l'ancien ordre.
        const cached = ALL_PLAYLISTS_DATA.find(p => p.id == pd.id);
        if (cached) cached.song_ids = newIds;
        store.showToast(T('toast_queue_reordered'), 'success');
    } catch (e) {
        // Échec serveur : on remet l'ordre précédent plutôt que de laisser
        // l'affichage mentir sur ce qui est réellement enregistré.
        pd.tracks = previous;
        pd.song_ids = previous.map(t => t.id).join(',');
        renderPlaylistDetailTracks(false);
        store.showToast(T('err_action_failed'), 'error');
    }
}

// --- RENOMMAGE SUR PLACE DU TITRE ---
function startPlaylistTitleEdit() {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    if (!store.playlistDetail || !store.playlistDetail.canEdit) return;
    store.playlistTitleDraft = store.playlistDetail.name;
    store.playlistTitleEditing = true;
    // Le champ est masqué par x-show au moment de l'appel : focus() sur un élément
    // en display:none ne fait rien. Alpine.nextTick suffit — vérifié, le style
    // calculé est déjà appliqué dans ce callback.
    //
    // Surtout PAS de requestAnimationFrame ici : il est gelé dans un onglet en
    // arrière-plan ou masqué, et le champ ne recevrait alors jamais le focus.
    Alpine.nextTick(() => {
        const input = document.querySelector('.playlist-title-input');
        if (input) { input.focus(); input.select(); }
    });
}

function cancelPlaylistTitleEdit() {
    if (!window.Alpine) return;
    Alpine.store('ui').playlistTitleEditing = false;
}

async function savePlaylistTitleInline() {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    if (!store.playlistTitleEditing) return; // le blur suit déjà un enregistrement par Entrée
    const pd = store.playlistDetail;
    const name = (store.playlistTitleDraft || '').trim();
    store.playlistTitleEditing = false;

    // Nom vide ou inchangé : rien à enregistrer, on ressort simplement du mode
    // édition (un nom vide rendrait la playlist introuvable dans les listes).
    if (!pd || !name || name === pd.name) return;

    const previousName = pd.name;
    pd.name = name;

    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('playlist_id', pd.id);
        fd.append('mode', 'rename');
        fd.append('new_name', name);
        const res = await fetch('api.php?action=playlist_mod', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message);
        const cached = ALL_PLAYLISTS_DATA.find(p => p.id == pd.id);
        if (cached) cached.name = name;
        // Le nouveau nom est déjà visible dans le titre : le toast confirme que
        // l'enregistrement serveur a bien eu lieu, d'où le nom repris en message.
        store.showToast(name, 'success');
    } catch (e) {
        pd.name = previousName;
        store.showToast(T('err_action_failed'), 'error');
    }
}

function shufflePlaylistDetail() {
    if (!window.Alpine) return;
    const pd = Alpine.store('ui').playlistDetail;
    if (!pd || !pd.tracks || !pd.tracks.length) return;
    currentPlaylistId = pd.id;
    originalQueue = [...pd.tracks];
    queue = shuffleArray([...pd.tracks]);
    currentIndex = 0;
    loadTrack(true);
}

// Bascule public/privé depuis la carte de playlist, sans passer par la modale
// d'édition complète.
async function togglePlaylistVisibility(playlistId, event) {
    if (event) event.stopPropagation();
    try {
        const fd = new FormData();
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('playlist_id', playlistId);
        const res = await fetch('api.php?action=playlist_toggle_visibility', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message);
        // Rechargement : la playlist change de section (Publiques <-> Mes privées),
        // toutes deux rendues côté PHP — les déplacer côté client dupliquerait
        // cette logique de répartition.
        window.location.reload();
    } catch (e) {
        if (window.Alpine) Alpine.store('ui').showToast(T('err_action_failed'), 'error');
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
