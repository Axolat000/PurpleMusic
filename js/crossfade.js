// =============================================================================
// ENCHAÎNEMENT DES MORCEAUX : FONDU ENCHAÎNÉ ET LECTURE SANS BLANC
// =============================================================================
// Les deux réglages sont deux extrémités du MÊME mécanisme, d'où un seul fichier :
// on démarre le morceau suivant avant la fin du précédent, et on croise les gains.
// Avec une durée de fondu nulle, le croisement est instantané — c'est la lecture
// gapless. Avec une durée non nulle, c'est le fondu enchaîné.
//
// POURQUOI DEUX ÉLÉMENTS <audio>. Faire sonner deux morceaux en même temps est
// impossible avec un seul élément, quel que soit le montage Web Audio derrière.
// Les deux éléments ont donc chacun leur source dans le graphe partagé, se
// rejoignent avant l'égaliseur (le morceau entrant est traité exactement comme le
// sortant), et un GainNode par élément sert de fader.
//
// SÉCURITÉ DU CŒUR DE LECTURE. Les deux réglages sont désactivés par défaut. Tant
// qu'ils le sont, ce fichier ne fait littéralement rien : maybeStartCrossfade()
// sort à la première ligne, le second élément ne reçoit jamais de source, et la
// lecture suit exactement le chemin d'avant. C'est délibéré — le mécanisme
// s'ajoute à côté du fonctionnement existant, il ne le remplace pas.

// Durées proposées, en secondes. 0 = pas de fondu.
const CROSSFADE_DURATIONS = [0, 2, 4, 6, 8, 12];

let crossfadeSeconds = 0;   // 0 = fondu désactivé
let gaplessEnabled = false; // enchaînement sans blanc (fondu de durée nulle)
let _crossfading = false;   // un enchaînement est en cours
let _preloadedIndex = -1;   // index déjà chargé dans l'élément au repos

// Marge de déclenchement du mode gapless. On ne peut pas attendre l'événement
// `ended` : il arrive APRÈS le silence, c'est-à-dire trop tard par définition. On
// enchaîne donc juste avant la fin, assez tôt pour que le décodeur ait démarré.
const GAPLESS_LEAD = 0.35;

function crossfadeActive() {
    return crossfadeSeconds > 0 || gaplessEnabled;
}

// Fenêtre pendant laquelle l'enchaînement doit commencer.
function crossfadeLead() {
    return crossfadeSeconds > 0 ? crossfadeSeconds : GAPLESS_LEAD;
}

function nextQueueIndex() {
    // loopMode 2 = répéter le morceau courant : il n'y a rien à enchaîner, la
    // piste reprend au même endroit.
    if (loopMode === 2) return -1;
    if (currentIndex < queue.length - 1) return currentIndex + 1;
    if (loopMode === 1) return 0; // boucle sur la file
    return -1;
}

// Prépare la piste suivante dans l'élément au repos, sans la jouer. Appelée un peu
// avant le besoin réel : c'est ce préchargement qui supprime le blanc, bien plus
// que la bascule elle-même.
function preloadNextTrack() {
    const nextIdx = nextQueueIndex();
    if (nextIdx < 0 || nextIdx === _preloadedIndex) return;
    const track = queue[nextIdx];
    if (!track) return;

    const el = idleAudio();
    el.src = 'music/' + track.filename;
    el.currentTime = 0;
    el.preload = 'auto';
    el.load();
    _preloadedIndex = nextIdx;
}

// Appelée à chaque tick de lecture (voir audio.ontimeupdate dans js/playback.js).
function maybeStartCrossfade() {
    if (!crossfadeActive() || _crossfading) return;
    if (!audio || !audio.duration || !isFinite(audio.duration)) return;

    const remaining = audio.duration - audio.currentTime;

    // Préchargement large : on veut que le décodeur ait fini son travail AVANT le
    // moment de la bascule, pas au moment de la bascule.
    if (remaining <= crossfadeLead() + 8) preloadNextTrack();
    if (remaining > crossfadeLead()) return;

    const nextIdx = nextQueueIndex();
    if (nextIdx < 0) return;

    startCrossfadeTo(nextIdx);
}

function startCrossfadeTo(nextIdx) {
    const track = queue[nextIdx];
    if (!track) return;

    // Sans graphe audio, il n'y a pas de fader : on laisse alors l'enchaînement
    // normal (onended -> nextTrack) faire son travail. Le graphe n'existe qu'après
    // un premier geste de lecture, donc ce cas est réel.
    resumeAudioGraph();
    if (!audioCtx || !fadeGainA || !fadeGainB) return;

    const incoming = idleAudio();
    if (_preloadedIndex !== nextIdx) {
        incoming.src = 'music/' + track.filename;
        incoming.currentTime = 0;
    }

    _crossfading = true;

    const outgoingGain = (audio === audioA) ? fadeGainA : fadeGainB;
    const incomingGain = (audio === audioA) ? fadeGainB : fadeGainA;
    const now = audioCtx.currentTime;
    const dur = crossfadeSeconds;

    incoming.volume = audio.volume;
    incoming.muted = audio.muted;
    const playPromise = incoming.play();
    if (playPromise && playPromise.catch) playPromise.catch(() => { /* geste utilisateur manquant : on laissera nextTrack() reprendre */ });

    if (dur > 0) {
        // Rampes en puissance (setValueCurveAtTime aurait été plus fidèle, mais
        // deux rampes linéaires croisées suffisent et restent lisibles). On part de
        // la valeur courante pour ne pas produire de saut au démarrage.
        outgoingGain.gain.cancelScheduledValues(now);
        incomingGain.gain.cancelScheduledValues(now);
        outgoingGain.gain.setValueAtTime(outgoingGain.gain.value, now);
        incomingGain.gain.setValueAtTime(0, now);
        outgoingGain.gain.linearRampToValueAtTime(0, now + dur);
        incomingGain.gain.linearRampToValueAtTime(1, now + dur);
    } else {
        // Gapless : bascule sèche, aucun fondu. Le morceau sortant est coupé net,
        // ce qui est exactement l'effet voulu sur un album mixé.
        outgoingGain.gain.setValueAtTime(0, now);
        incomingGain.gain.setValueAtTime(1, now);
    }

    // La bascule d'état (quel élément est "actif", quelle piste est courante) se
    // fait à la FIN du fondu et non au début : pendant tout le fondu, c'est encore
    // le morceau sortant qui pilote l'affichage, ce qui correspond à ce qu'on
    // entend majoritairement.
    setTimeout(() => finishCrossfade(nextIdx), Math.max(50, dur * 1000));
}

function finishCrossfade(nextIdx) {
    if (!_crossfading) return;
    const outgoing = audio;

    // L'élément sortant est arrêté et vidé : laisser une source chargée
    // maintiendrait un décodeur actif et le tampon réseau associé pour rien.
    outgoing.pause();
    outgoing.removeAttribute('src');
    outgoing.load();

    swapActiveAudio();
    currentIndex = nextIdx;
    _preloadedIndex = -1;
    _crossfading = false;

    // Toute la mise à jour d'écran que loadTrack() ferait, SANS toucher à la
    // lecture : le morceau suivant sonne déjà, le recharger le ferait repartir de
    // zéro et annulerait tout l'intérêt de l'enchaînement.
    refreshUiForCurrentTrack();
}

function swapActiveAudio() {
    audio = (audio === audioA) ? audioB : audioA;
}

// Remet l'interface au diapason du morceau qui sonne, sans recharger l'audio.
// Reprend les mêmes gestes que loadTrack() (titres, pochettes, fond d'ambiance,
// Media Session, historique, thème) en laissant de côté tout ce qui touche à
// <audio>.
function refreshUiForCurrentTrack() {
    const track = queue[currentIndex];
    if (!track) return;

    const coverUrl = 'covers/' + (track.cover || 'default.png');
    const artistLabel = track.artist || 'Artiste inconnu';
    const goToTrackArtist = (e) => {
        e.stopPropagation();
        const name = splitArtistNames(track.artist)[0] || track.artist;
        if (name) showArtistPage(name);
    };

    pmEach('title', el => setPlayerTitle(el, track.title));
    pmEach('artist', el => { el.innerText = artistLabel; el.onclick = goToTrackArtist; });
    pmEach('cover', el => { el.src = coverUrl; });

    const ambientUrl = `url("covers/${encodeURIComponent(track.cover || 'default.png')}")`;
    pmEach('ambient', el => {
        el.style.backgroundImage = ambientUrl;
        el.classList.add('is-visible');
    });

    if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: track.title,
            artist: track.artist || 'Purple Music',
            album: track.album || '',
            artwork: [96, 128, 192, 256, 384, 512].map(px => ({
                src: coverUrl, sizes: `${px}x${px}`, type: 'image/png',
            })),
        });
    }

    startListenTracking(track.id);
    pushListenHistory(track.id);
    updateUrl();
    updateQueueUI();
    loadWaveformFor(track);
    applyDynamicThemeForCurrentTrack();
    applyAppDynamicThemeForCurrentTrack();
    if (typeof syncPlaybackState === 'function') syncPlaybackState();
    if (window.Alpine) {
        const s = Alpine.store('ui');
        if (s.showLyricsInPlayer || s.lyricsPanelOpen || s.desktopPlayerView === 'lyrics') loadLyricsForCurrentTrack();
    }
}

// Annule un enchaînement en cours et remet les faders au repos. Appelée dès que
// l'utilisateur reprend la main (piste suivante/précédente, clic dans la file) :
// sans ça, un fondu déclenché juste avant continuerait de monter le gain d'un
// morceau qu'on vient d'abandonner.
function cancelCrossfade() {
    _preloadedIndex = -1;
    _crossfading = false;

    // L'element au repos est vide DANS TOUS LES CAS, meme si aucun fondu n'etait en
    // cours : un simple prechargement a pu avoir lieu, et laisser cette source
    // chargee maintiendrait un decodeur et un tampon reseau pour une piste qu'on ne
    // jouera peut-etre jamais (constate : le second element gardait sa source apres
    // desactivation du reglage).
    const other = idleAudio();
    if (other && other.getAttribute('src')) {
        other.pause();
        other.removeAttribute('src');
        other.load();
    }

    if (!audioCtx || !fadeGainA || !fadeGainB) return;
    const now = audioCtx.currentTime;
    const activeGain = (audio === audioA) ? fadeGainA : fadeGainB;
    const otherGain = (audio === audioA) ? fadeGainB : fadeGainA;
    activeGain.gain.cancelScheduledValues(now);
    otherGain.gain.cancelScheduledValues(now);
    activeGain.gain.setValueAtTime(1, now);
    otherGain.gain.setValueAtTime(0, now);
}

// --- Réglages ---------------------------------------------------------------

function setCrossfadeSeconds(seconds) {
    const v = CROSSFADE_DURATIONS.includes(Number(seconds)) ? Number(seconds) : 0;
    crossfadeSeconds = v;
    localStorage.setItem('purpleMusicCrossfade', String(v));
    if (window.Alpine) Alpine.store('ui').crossfadeSeconds = v;
    cancelCrossfade();
}

function setGaplessEnabled(enabled) {
    gaplessEnabled = !!enabled;
    localStorage.setItem('purpleMusicGapless', gaplessEnabled ? '1' : '0');
    if (window.Alpine) Alpine.store('ui').gaplessEnabled = gaplessEnabled;
    cancelCrossfade();
}

function restoreCrossfadeSettings() {
    const saved = parseInt(localStorage.getItem('purpleMusicCrossfade') || '0', 10);
    crossfadeSeconds = CROSSFADE_DURATIONS.includes(saved) ? saved : 0;
    gaplessEnabled = localStorage.getItem('purpleMusicGapless') === '1';
    if (window.Alpine) {
        Alpine.store('ui').crossfadeSeconds = crossfadeSeconds;
        Alpine.store('ui').gaplessEnabled = gaplessEnabled;
    }
}
