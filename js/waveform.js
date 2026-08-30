// =============================================================================
// FORME D'ONDE DE LA BARRE DE PROGRESSION
// =============================================================================
// La progression était une ligne plate : elle disait où on en est, jamais ce
// qu'il reste à entendre. La forme d'onde montre la structure du morceau — on
// repère un refrain, un pont, un silence de fin, et on vise directement.
//
// D'OÙ VIENNENT LES PICS. Pas du serveur : les extraire demanderait ffmpeg, une
// dépendance binaire qu'une instance auto-hébergée n'a pas forcément, et dont
// l'absence ferait disparaître la fonctionnalité sans explication. C'est le
// navigateur qui décode la piste qu'il joue déjà, une seule fois, puis dépose le
// résultat sur le serveur (action=waveform_save) : le prochain auditeur, sur
// n'importe quel appareil, les reçoit tout faits et ne décode rien.
//
// COMMENT C'EST DESSINÉ. Pas de <canvas> ni de <div> par barre : un masque CSS.
// Les barres forment la SILHOUETTE (mask-image, un SVG généré à la volée), et le
// fond de l'élément est un dégradé à arrêt net dont la position est pilotée par
// --pm-progress. Une seule propriété CSS change à chaque tick de lecture, quel
// que soit le nombre de barres — et les couleurs restent celles du thème, sans
// une seule valeur en dur.
//
// DÉGRADATION. Tant qu'aucun pic n'est disponible, rien n'est posé : la barre
// pleine d'origine reste affichée telle quelle. Une piste dont le décodage échoue
// (format exotique, fichier tronqué) reste donc simplement sans forme d'onde.

const WAVEFORM_POINTS = 120;

// Pistes déjà traitées dans cet onglet : on ne redécode pas un morceau qu'on
// remet, et on ne repart pas en requête pour un morceau dont on sait déjà qu'il
// n'a pas de forme d'onde exploitable.
const _waveformCache = new Map();   // trackId -> tableau de pics | null
const _waveformTried = new Set();   // trackId dont le décodage a déjà été tenté

// Construit le masque SVG : une barre par pic, centrée verticalement.
// preserveAspectRatio="none" : le SVG s'étire à la largeur réelle de la barre,
// quelle que soit la surface (mini-barre étroite, lecteur plein écran large).
function waveformMaskUrl(peaks) {
    const w = peaks.length;
    const bars = peaks.map((p, i) => {
        // Hauteur minimale : un passage silencieux doit rester visible comme une
        // ligne fine, sinon la forme d'onde se troue et on croit à un bug.
        const h = Math.max(6, p);
        const y = (100 - h) / 2;
        return `<rect x="${i + 0.15}" y="${y}" width="0.7" height="${h}" rx="0.35"/>`;
    }).join('');
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} 100" preserveAspectRatio="none">${bars}</svg>`;
    return `url("data:image/svg+xml,${encodeURIComponent(svg)}")`;
}

// Applique (ou retire) la forme d'onde sur toutes les surfaces de lecteur.
function applyWaveform(peaks) {
    pmEach('progress-area', area => {
        const wave = area.querySelector('[data-pm-wave]');
        if (!wave) return;
        if (!peaks) {
            area.classList.remove('has-waveform');
            wave.style.removeProperty('-webkit-mask-image');
            wave.style.removeProperty('mask-image');
            return;
        }
        const url = waveformMaskUrl(peaks);
        wave.style.setProperty('-webkit-mask-image', url);
        wave.style.setProperty('mask-image', url);
        area.classList.add('has-waveform');
    });
}

// Position de lecture, en pourcentage. Appelée au même rythme que la largeur de
// .progress-fill (voir audio.ontimeupdate et le scrub dans js/playback.js).
function setWaveformProgress(pct) {
    pmEach('progress-area', area => {
        area.style.setProperty('--pm-progress', (pct || 0) + '%');
    });
}

// Réduit un AudioBuffer à WAVEFORM_POINTS valeurs 0-100.
//
// On prend le pic (max absolu) de chaque tranche et non la moyenne : la moyenne
// écrase les transitoires et donne une bouillie plate où tous les morceaux se
// ressemblent. Le résultat est ensuite normalisé sur le maximum du morceau, sinon
// un titre enregistré bas serait un trait plat à côté d'un titre masterisé fort.
function analyseBuffer(buffer) {
    const channel = buffer.getChannelData(0);
    const blockSize = Math.floor(channel.length / WAVEFORM_POINTS) || 1;
    const raw = [];
    let max = 0;
    let sumSquares = 0;
    let sampleCount = 0;

    for (let i = 0; i < WAVEFORM_POINTS; i++) {
        const start = i * blockSize;
        let peak = 0;
        // Pas d'inspection échantillon par échantillon : sur un morceau de 3
        // minutes cela ferait 8 millions de lectures pour 120 valeurs. Un pas
        // d'échantillonnage suffit très largement à attraper les pics.
        const step = Math.max(1, Math.floor(blockSize / 400));
        for (let j = start; j < start + blockSize && j < channel.length; j += step) {
            const v = Math.abs(channel[j]);
            if (v > peak) peak = v;
            // Le niveau moyen se calcule sur les mêmes échantillons que les pics :
            // une seconde traversée du morceau pour la même information n'aurait
            // servi à rien.
            sumSquares += v * v;
            sampleCount++;
        }
        raw.push(peak);
        if (peak > max) max = peak;
    }
    if (max === 0 || sampleCount === 0) return null; // piste silencieuse : rien à montrer

    const rms = Math.sqrt(sumSquares / sampleCount);
    return {
        // Normalisés sur le maximum du morceau : sinon un titre enregistré bas
        // serait un trait plat à côté d'un titre masterisé fort.
        peaks: raw.map(v => Math.max(0, Math.min(100, Math.round((v / max) * 100)))),
        // dBFS : 0 = plein échelle, négatif en dessous. C'est l'échelle dans
        // laquelle un écart de niveau se raisonne (et se corrige) additivement.
        loudness: Math.max(-70, Math.min(0, 20 * Math.log10(rms || 1e-7))),
        peak: max,
    };
}

async function computeAndStoreAnalysis(track) {
    if (_waveformTried.has(track.id)) return null;
    _waveformTried.add(track.id);
    try {
        // Même URL que celle que joue <audio> : la réponse vient donc du cache HTTP
        // du navigateur dans l'immense majorité des cas, pas d'un second
        // téléchargement complet.
        const res = await fetch('music/' + track.filename);
        if (!res.ok) return null;
        const bytes = await res.arrayBuffer();
        // AudioContext dédié et jetable : surtout pas celui de l'égaliseur, qui est
        // branché sur la lecture en cours et ne doit jamais servir à décoder.
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const buffer = await ctx.decodeAudioData(bytes);
        const analysis = analyseBuffer(buffer);
        ctx.close();
        if (!analysis) return null;

        const fd = new FormData();
        fd.append('track_id', track.id);
        fd.append('peaks', JSON.stringify(analysis.peaks));
        fd.append('loudness', String(analysis.loudness.toFixed(2)));
        fd.append('peak_amp', String(analysis.peak.toFixed(5)));
        fd.append('csrf_token', CSRF_TOKEN);
        // Le dépôt est un bonus pour les auditeurs suivants : s'il échoue, la forme
        // d'onde et la normalisation fonctionnent quand même pour celui qui vient de
        // faire le calcul.
        fetch('api.php?action=waveform_save', { method: 'POST', body: fd }).catch(() => {});
        return analysis;
    } catch (e) {
        return null;
    }
}

// Point d'entrée, appelé par loadTrack() à chaque changement de piste.
async function loadWaveformFor(track) {
    if (!track) return;
    const trackId = track.id;

    if (_waveformCache.has(trackId)) {
        const cached = _waveformCache.get(trackId);
        applyWaveform(cached && cached.peaks);
        applyTrackNormalization(cached);
        return;
    }
    // On retire la forme d'onde du morceau précédent tout de suite : la garder
    // pendant le chargement afficherait la silhouette d'un autre morceau. Le gain de
    // normalisation revient lui aussi à neutre, pour ne pas appliquer au nouveau
    // morceau la correction calculée pour le précédent.
    applyWaveform(null);
    applyTrackNormalization(null);

    let analysis = null;
    try {
        const res = await fetch('api.php?action=waveform&q=' + encodeURIComponent(trackId));
        const data = await res.json();
        if (data && data.status === 'success' && data.peaks) {
            analysis = { peaks: data.peaks, loudness: data.loudness, peak: data.peak_amp };
        }
    } catch (e) { /* hors ligne : on tentera le calcul local */ }

    // Changement de piste pendant la requête : ce résultat ne concerne plus ce
    // qui joue, l'appliquer ferait clignoter une silhouette étrangère.
    if (!queue[currentIndex] || queue[currentIndex].id !== trackId) return;

    if (analysis) {
        _waveformCache.set(trackId, analysis);
        applyWaveform(analysis.peaks);
        applyTrackNormalization(analysis);
        return;
    }

    // Aucun pic connu : on décode, mais sans presser — la lecture vient de démarrer
    // et le décodage est la tâche la plus lourde de la page. requestIdleCallback la
    // repousse à un moment où le navigateur n'a rien de mieux à faire.
    const compute = async () => {
        const computed = await computeAndStoreAnalysis(track);
        _waveformCache.set(trackId, computed);
        if (computed && queue[currentIndex] && queue[currentIndex].id === trackId) {
            applyWaveform(computed.peaks);
            applyTrackNormalization(computed);
        }
    };
    if (window.requestIdleCallback) requestIdleCallback(() => compute(), { timeout: 5000 });
    else setTimeout(compute, 1500);
}
