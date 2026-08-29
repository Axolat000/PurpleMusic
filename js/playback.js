
// --- SUIVI D'ÉCOUTE (vues après 10s + durée moyenne pour le moteur de recommandations) ---
// Un intervalle de 1s qui n'incrémente listenSeconds QUE si l'audio joue réellement à ce moment-là (pas
// en pause) -- gère naturellement pause/reprise/seek sans suivi événementiel précis : on ne se fie qu'à
// l'état réel de <audio> à chaque tick, jamais à un delta de temps qui pourrait inclure une pause.
let listenTrackId = null;
let listenSeconds = 0;
let listenCountedForCurrentTrack = false;
let listenIntervalId = null;

function startListenTracking(trackId) {
    stopListenTracking(); // journalise la session précédente avant d'en démarrer une nouvelle
    listenTrackId = trackId;
    listenSeconds = 0;
    listenCountedForCurrentTrack = false;
    listenIntervalId = setInterval(() => {
        if (!audio || audio.paused || audio.ended) return;
        listenSeconds++;
        if (listenSeconds === 10 && !listenCountedForCurrentTrack) {
            listenCountedForCurrentTrack = true;
            reportListen(listenTrackId, listenSeconds);
        }
    }, 1000);
}

function stopListenTracking() {
    if (listenIntervalId) { clearInterval(listenIntervalId); listenIntervalId = null; }
    // Pas encore atteint 10s (donc jamais reporté) : on journalise quand même la session courte pour la
    // durée moyenne d'écoute (signal utile même pour un skip rapide -- indique qu'on n'a PAS accroché).
    if (listenTrackId && !listenCountedForCurrentTrack && listenSeconds > 0) {
        reportListen(listenTrackId, listenSeconds);
    }
    listenTrackId = null;
}

// Bouton cœur (liste de pistes) : bascule optimiste (le cœur/compteur change tout de suite) puis corrigé
// au besoin par la vraie réponse serveur -- toggle_like est idempotent côté état (juste insert/delete),
// pas de risque de désync grave même en cas de double-clic rapide (le serveur reste la source de vérité).
function toggleLikeUI(trackId, btnEl) {
    const wasActive = btnEl.classList.contains('active');
    const countEl = btnEl.querySelector('.like-count');
    const prevCount = parseInt(countEl.textContent) || 0;
    btnEl.classList.toggle('active', !wasActive);
    countEl.textContent = Math.max(0, prevCount + (wasActive ? -1 : 1));

    const fd = new FormData();
    fd.append('track_id', trackId);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=toggle_like', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') { btnEl.classList.toggle('active', wasActive); countEl.textContent = prevCount; return; }
            btnEl.classList.toggle('active', data.liked);
            countEl.textContent = data.like_count;
            if (typeof ALL_MUSIC_DATA !== 'undefined') {
                const t = ALL_MUSIC_DATA.find(x => x.id == trackId);
                if (t) { t.is_liked = data.liked ? 1 : 0; t.like_count = data.like_count; }
            }
        })
        .catch(() => { btnEl.classList.toggle('active', wasActive); countEl.textContent = prevCount; });
}

function reportListen(trackId, seconds) {
    const fd = new FormData();
    fd.append('track_id', trackId);
    fd.append('seconds', seconds);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=report_listen', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.counted) return;
            // Miroir de l'ancien comportement optimiste d'increment_play, mais seulement une fois la vue
            // réellement comptée côté serveur (pas au chargement) -- met à jour l'affichage local du
            // compteur sans attendre un rechargement de page.
            if (typeof ALL_MUSIC_DATA !== 'undefined') {
                const t = ALL_MUSIC_DATA.find(x => x.id == trackId);
                if (t) t.play_count = (parseInt(t.play_count) || 0) + 1;
            }
        })
        .catch(e => console.error(e));
}

function loadTrack(autoPlay = true) {
    if (!queue[currentIndex]) return;
    const track = queue[currentIndex];
    audio.src = 'music/' + track.filename;
    // Ne compte plus la vue immédiatement au chargement -- voir startListenTracking() : une "vue" n'est
    // désormais journalisée que si le morceau est réellement écouté 10s ou plus (report_listen côté
    // serveur revérifie aussi ce seuil, jamais confiance aveugle au client).
    startListenTracking(track.id);

    // Navigue vers la page du premier artiste du champ (voir splitArtistNames() dans library.js) --
    // stopPropagation empêche le clic de remonter jusqu'au conteneur parent (ex: .player-info ouvre le
    // lecteur plein écran au clic). Réassigné à chaque piste plutôt qu'une fois pour toutes : le nom
    // affiché change, et .onclick (pas addEventListener) écrase proprement le précédent sans fuite.
    const goToTrackArtist = (e) => {
        e.stopPropagation();
        const name = splitArtistNames(track.artist)[0] || track.artist;
        if (name) showArtistPage(name);
    };

    if (playTitle) playTitle.innerText = track.title;
    if (playCover) playCover.src = 'covers/' + (track.cover || 'default.png');
    if (playStatus) { playStatus.innerText = track.artist || 'Artiste inconnu'; playStatus.onclick = goToTrackArtist; }

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
    if (fpArtist) { fpArtist.innerText = track.artist || 'Artiste inconnu'; fpArtist.onclick = goToTrackArtist; }
    if (fpCover) fpCover.src = 'covers/' + (track.cover || 'default.png');

    // Carte desktop : pas de marquee (largeur confortable), simple troncature CSS (ellipsis).
    if (dpTitle) dpTitle.innerText = track.title;
    if (dpArtist) { dpArtist.innerText = track.artist || 'Artiste inconnu'; dpArtist.onclick = goToTrackArtist; }
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
            album: track.album || '',
            // Plusieurs tailles : Android/Windows choisissent la plus proche de leur
            // besoin. Une seule entrée 96x96 donnait une vignette floue sur l'écran
            // verrouillé et dans le panneau média de Chrome.
            artwork: [96, 128, 192, 256, 384, 512].map(px => ({
                src: 'covers/' + (track.cover || 'default.png'),
                sizes: `${px}x${px}`,
                type: 'image/png'
            }))
        });
        setupMediaSessionHandlers();
    }
    // Fond d'ambiance des deux lecteurs : même pochette que #fp-cover/#dp-cover,
    // floutée en CSS (voir .fp-ambient). Posée en background-image plutôt que via
    // une <img> pour que background-size:cover gère le recadrage quel que soit le
    // format de la pochette.
    const ambientUrl = `url("covers/${encodeURIComponent(track.cover || 'default.png')}")`;
    ['fp-ambient', 'dp-ambient'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.backgroundImage = ambientUrl;
        el.classList.add('is-visible');
    });

    pushListenHistory(track.id);
    updateUrl();
    applyDynamicThemeForCurrentTrack();
    applyAppDynamicThemeForCurrentTrack();
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
    syncPlaybackState();
}

// Contrôles média du système (écran verrouillé, panneau média du navigateur,
// touches multimédia du clavier, boutons d'un casque Bluetooth).
//
// Seules les métadonnées étaient renseignées jusqu'ici : le titre s'affichait bien
// sur l'écran verrouillé mais les boutons Lecture/Suivant n'y faisaient rien, car
// aucun gestionnaire d'action n'était déclaré. Posé une seule fois (les
// gestionnaires survivent aux changements de piste) plutôt qu'à chaque loadTrack().
let _mediaSessionReady = false;
function setupMediaSessionHandlers() {
    if (_mediaSessionReady || !('mediaSession' in navigator)) return;
    _mediaSessionReady = true;
    const set = (action, handler) => {
        // Un navigateur qui ne connaît pas une action lève NotSupportedError :
        // chaque déclaration est isolée pour qu'un manque n'annule pas les autres.
        try { navigator.mediaSession.setActionHandler(action, handler); } catch (e) { /* action non supportée */ }
    };
    set('play', () => { if (audio.paused) togglePlay(); });
    set('pause', () => { if (!audio.paused) togglePlay(); });
    set('previoustrack', prevTrack);
    set('nexttrack', nextTrack);
    set('seekbackward', (d) => { audio.currentTime = Math.max(0, audio.currentTime - (d.seekOffset || 10)); });
    set('seekforward', (d) => { audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + (d.seekOffset || 10)); });
    set('seekto', (d) => { if (d.fastSeek && 'fastSeek' in audio) audio.fastSeek(d.seekTime); else audio.currentTime = d.seekTime; });
    set('stop', () => { audio.pause(); audio.currentTime = 0; });
}

if (audio) {
    // L'état "en cours de lecture" alimente l'indicateur des cartes/lignes : il doit
    // suivre TOUTES les origines de changement (bouton de l'app, contrôles système,
    // fin de piste, coupure réseau), pas seulement togglePlay().
    //
    // Appel indirect (et non `addEventListener('play', syncPlaybackState)`) : ce bloc
    // s'exécute à l'évaluation de playback.js, alors que syncPlaybackState est
    // déclarée dans discovery.js, chargé APRÈS — passer la référence directement
    // lèverait un ReferenceError ici. L'enveloppe ne résout le nom qu'au moment où
    // l'événement se produit, quand tous les fichiers sont chargés.
    audio.addEventListener('play', () => syncPlaybackState());
    audio.addEventListener('pause', () => syncPlaybackState());
    audio.addEventListener('ended', () => syncPlaybackState());

    audio.onloadedmetadata = () => {
        const t = formatTime(audio.duration);
        document.getElementById('total-time').innerText = t;
        const fpTotalTime = document.getElementById('fp-total-time');
        if (fpTotalTime) fpTotalTime.innerText = t;
        const dpTotalTime = document.getElementById('dp-total-time');
        if (dpTotalTime) dpTotalTime.innerText = t;
    };
    audio.ontimeupdate = () => {
        // Pendant un glissement sur la barre, c'est le curseur qui pilote
        // l'affichage : laisser la lecture réécrire la largeur ferait revenir la
        // barre à la position réelle entre deux mouvements, donc clignoter.
        // Voir attachSeekHandlers() plus bas.
        if (scrubbing) return;
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

// --- BARRE DE PROGRESSION : clic ET glissement ------------------------------
// Le comportement précédent était un simple clic-pour-sauter : maintenir le
// bouton et faire glisser ne suivait pas le curseur, il fallait recliquer pour
// ajuster. On ajoute un vrai scrub continu, avec aperçu en direct.
//
// Pendant le glissement, `scrubbing` empêche audio.ontimeupdate de réécrire la
// largeur de la barre : sans ce verrou, la barre repartait à la position réelle
// de lecture entre deux mouvements de souris et clignotait.
let scrubbing = false;

function attachSeekHandlers(areaEl, barEl, timeLabelId) {
    if (!areaEl) return;

    const ratioFromEvent = (e) => {
        const rect = areaEl.getBoundingClientRect();
        // clientX est borné aux limites de la barre : glisser au-delà de ses
        // extrémités doit saturer à 0% / 100%, pas produire une valeur négative
        // ou supérieure à la durée (ce qui ferait échouer l'affectation).
        const x = Math.min(Math.max(e.clientX, rect.left), rect.right);
        return rect.width ? (x - rect.left) / rect.width : 0;
    };

    const preview = (ratio) => {
        if (barEl) barEl.style.width = (ratio * 100) + '%';
        const label = timeLabelId ? document.getElementById(timeLabelId) : null;
        if (label && audio.duration) label.innerText = formatTime(ratio * audio.duration);
    };

    const commit = (e) => {
        if (!audio.duration) return;
        audio.currentTime = ratioFromEvent(e) * audio.duration;
    };

    areaEl.addEventListener('pointerdown', (e) => {
        if (!audio.duration) return;
        scrubbing = true;
        // setPointerCapture : les mouvements continuent d'être reçus même quand
        // le curseur sort de la barre (cas courant, la barre ne fait que 6px de
        // haut) — sans lui le glissement s'interrompt dès qu'on la quitte.
        areaEl.setPointerCapture(e.pointerId);
        preview(ratioFromEvent(e));
    });

    areaEl.addEventListener('pointermove', (e) => {
        if (!scrubbing) return;
        preview(ratioFromEvent(e));
    });

    const finish = (e) => {
        if (!scrubbing) return;
        scrubbing = false;
        try { areaEl.releasePointerCapture(e.pointerId); } catch (err) { /* pointeur déjà relâché */ }
        commit(e);
    };
    areaEl.addEventListener('pointerup', finish);
    areaEl.addEventListener('pointercancel', finish);

    // Accessibilité clavier : la barre est focalisable et se pilote aux flèches,
    // ce qui était impossible auparavant (aucun gestionnaire clavier).
    areaEl.setAttribute('tabindex', '0');
    areaEl.setAttribute('role', 'slider');
    areaEl.addEventListener('keydown', (e) => {
        if (!audio.duration) return;
        const step = e.shiftKey ? 30 : 5;
        if (e.key === 'ArrowRight') { e.preventDefault(); audio.currentTime = Math.min(audio.duration, audio.currentTime + step); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); audio.currentTime = Math.max(0, audio.currentTime - step); }
        else if (e.key === 'Home') { e.preventDefault(); audio.currentTime = 0; }
        else if (e.key === 'End') { e.preventDefault(); audio.currentTime = audio.duration; }
    });
}

attachSeekHandlers(progressArea, progressBar, 'curr-time');
attachSeekHandlers(document.getElementById('fp-progress-area'), document.getElementById('fp-progress-bar'), 'fp-curr-time');
attachSeekHandlers(document.getElementById('dp-progress-area'), document.getElementById('dp-progress-bar'), 'dp-curr-time');

