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
        const res = await fetch('api.php?action=get_lyrics&q=' + track.id);
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
    const res = await fetch('api.php?action=get_playlist_tracks&q=' + ids);
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
        const res = await fetch('api.php?action=get_playlist_tracks&q=' + playlist.song_ids);
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
