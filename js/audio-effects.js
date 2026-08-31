// =============================================================================
// EFFETS AUDIO : RÉVERBÉRATION / SPATIALISATION
// =============================================================================
// S'insère dans le graphe audio partagé déjà en place (voir initAudioGraph() dans
// core.js), entre l'égaliseur et l'analyseur du visualiseur :
//
//   source -> eq[0..5] -> [ sec | réverb ] -> analyseur -> destination
//
// La réponse impulsionnelle est GÉNÉRÉE, pas chargée : embarquer un fichier
// d'impulsion (plusieurs centaines de kilo-octets par ambiance) alourdirait
// l'image Docker pour un effet optionnel. Un bruit à décroissance exponentielle
// donne une réverbération tout à fait crédible, et on le fabrique une fois par
// ambiance choisie.
//
// La spatialisation vient de la même source : l'impulsion est STÉRÉO, avec deux
// canaux de bruit indépendants. Les deux oreilles reçoivent donc des réflexions
// décorrélées, ce qui élargit l'image sonore — c'est exactement ce que fait une
// vraie pièce, et ça évite un étage mid/side séparé qui aurait doublé le nombre
// de nœuds pour le même résultat perçu.
//
// Le signal sec n'est jamais coupé : on mélange sec et réverbéré. Passer tout le
// son dans le convolveur donnerait un morceau noyé, jamais un morceau dans une
// pièce.

// Longueur de l'impulsion (secondes) et proportion de signal réverbéré.
// 'off' garde le chemin sec seul : aucun convolveur n'est même construit tant
// qu'on n'a pas choisi une ambiance.
const REVERB_PRESETS = {
    off:        { seconds: 0,   wet: 0 },
    room:       { seconds: 1.1, wet: 0.16 },
    hall:       { seconds: 2.6, wet: 0.26 },
    cathedral:  { seconds: 4.5, wet: 0.36 },
};

let reverbConvolver = null;
let reverbWetGain = null;
let reverbDryGain = null;
let reverbPreset = 'off';

// Bruit blanc à décroissance exponentielle, un canal par oreille.
//
// decay=2 : une décroissance en carré, plus proche d'une vraie pièce qu'une
// droite — l'énergie tombe vite au début puis traîne, ce qui donne la queue de
// réverbération. Une décroissance linéaire s'entend comme un écho artificiel.
function buildImpulseResponse(ctx, seconds, decay = 2) {
    const rate = ctx.sampleRate;
    const length = Math.max(1, Math.floor(rate * seconds));
    const impulse = ctx.createBuffer(2, length, rate);
    for (let channel = 0; channel < 2; channel++) {
        const data = impulse.getChannelData(channel);
        for (let i = 0; i < length; i++) {
            // Math.random() est tiré séparément pour chaque canal : c'est cette
            // décorrélation qui produit la largeur stéréo.
            data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / length, decay);
        }
    }
    return impulse;
}

// Construit l'étage sec/réverbéré et le renvoie sous la forme { input, output }.
// Appelé par initAudioGraph() : ce module ne touche jamais au graphe lui-même,
// il fournit un maillon que le graphe insère où il veut.
function createReverbStage(ctx) {
    reverbDryGain = ctx.createGain();
    reverbWetGain = ctx.createGain();
    reverbConvolver = ctx.createConvolver();

    // Point d'entrée commun : un simple nœud de gain neutre qui alimente les deux
    // chemins. Sans lui, l'appelant devrait connecter deux fois sa sortie et
    // connaître notre structure interne.
    const input = ctx.createGain();
    const output = ctx.createGain();

    input.connect(reverbDryGain);
    reverbDryGain.connect(output);

    input.connect(reverbConvolver);
    reverbConvolver.connect(reverbWetGain);
    reverbWetGain.connect(output);

    // Valeurs posées DIRECTEMENT à la construction, sans rampe : un GainNode naît
    // à 1, et applyReverbPreset() ne fait que tendre vers sa cible. Le chemin
    // réverbéré aurait donc démarré à plein volume pendant la descente -- une
    // bouffée de réverbération au tout premier play (mesuré : wet à 1 juste après
    // la construction).
    applyReverbPreset(reverbPreset, ctx, true);
    return { input, output };
}

// Applique une ambiance. Le convolveur n'est rechargé que si la durée change :
// régénérer plusieurs secondes de bruit à chaque appel bloquerait le fil
// principal pour rien.
let _reverbLoadedSeconds = -1;
function applyReverbPreset(name, ctx = audioCtx, immediate = false) {
    const preset = REVERB_PRESETS[name] || REVERB_PRESETS.off;
    reverbPreset = REVERB_PRESETS[name] ? name : 'off';

    if (!ctx || !reverbDryGain || !reverbWetGain) return;

    if (preset.seconds > 0 && _reverbLoadedSeconds !== preset.seconds) {
        reverbConvolver.buffer = buildImpulseResponse(ctx, preset.seconds);
        _reverbLoadedSeconds = preset.seconds;
    }

    // Le sec baisse à peine quand le mouillé monte : on ajoute une ambiance, on ne
    // remplace pas le morceau par sa réverbération.
    const dry = 1 - preset.wet * 0.35;
    if (immediate) {
        reverbDryGain.gain.value = dry;
        reverbWetGain.gain.value = preset.wet;
        return;
    }
    // Rampe courte plutôt qu'affectation directe : un saut de gain sur un signal
    // en cours de lecture s'entend comme un clic.
    const now = ctx.currentTime;
    const ramp = 0.05;
    reverbDryGain.gain.setTargetAtTime(dry, now, ramp);
    reverbWetGain.gain.setTargetAtTime(preset.wet, now, ramp);
}

function setReverbPreset(name) {
    // Le graphe n'existe pas tant qu'aucune lecture n'a démarré : on mémorise le
    // choix, initAudioGraph() l'appliquera à la construction.
    reverbPreset = REVERB_PRESETS[name] ? name : 'off';
    localStorage.setItem('purpleMusicReverb', reverbPreset);
    if (audioCtx) applyReverbPreset(reverbPreset);
    if (window.Alpine) Alpine.store('ui').reverbPreset = reverbPreset;
}

function restoreReverbSetting() {
    const saved = localStorage.getItem('purpleMusicReverb');
    reverbPreset = REVERB_PRESETS[saved] ? saved : 'off';
    if (window.Alpine) Alpine.store('ui').reverbPreset = reverbPreset;
}

// =============================================================================
// NORMALISATION DU VOLUME
// =============================================================================
// Un morceau masterisé fort et un rip discret s'enchaînaient avec un écart de
// niveau brutal : on baissait le son, puis on le remontait au titre suivant.
//
// La correction est un simple gain par piste, calculé à partir du niveau moyen
// mesuré à l'analyse (voir js/waveform.js) — le même décodage que la forme
// d'onde, une seule fois par piste, partagé ensuite par tous les auditeurs.
//
// PAS DE COMPRESSEUR NI DE LIMITEUR. C'est le choix structurant : un limiteur en
// bout de chaîne aurait modifié le son de tout le monde, y compris des morceaux
// déjà au bon niveau. Ici on ne fait que déplacer un curseur de volume, et on
// s'interdit tout dépassement grâce à la crête réelle du morceau, mesurée elle
// aussi à l'analyse : le gain ne peut mathématiquement pas faire saturer.
//
// Une piste jamais analysée reste à gain neutre. Une bibliothèque partiellement
// analysée est donc partiellement normalisée -- elle se complète au fil des
// écoutes, sans jamais rien dégrader.

// Niveau visé, en dBFS RMS. -16 est un compromis courant : assez haut pour ne pas
// obliger à monter le volume système, assez bas pour laisser de la marge aux
// morceaux dynamiques sans les écraser contre le plafond.
const NORMALIZE_TARGET_DB = -16;
// Bornes de correction. Au-delà, ce n'est plus une harmonisation mais une
// réécriture du morceau : un enregistrement très bas doit rester un peu bas.
const NORMALIZE_MAX_GAIN = 4;    // +12 dB
const NORMALIZE_MIN_GAIN = 0.25; // -12 dB

let normalizeGain = null;
let normalizeEnabled = false;
let _lastAnalysis = null;

function createNormalizeStage(ctx) {
    normalizeGain = ctx.createGain();
    normalizeGain.gain.value = 1;
    return normalizeGain;
}

// Calcule le gain à appliquer pour une piste analysée. Exportée séparément de son
// application pour rester vérifiable sans graphe audio.
function normalizationGainFor(analysis) {
    if (!normalizeEnabled || !analysis || typeof analysis.loudness !== 'number' || !isFinite(analysis.loudness)) return 1;

    let gain = Math.pow(10, (NORMALIZE_TARGET_DB - analysis.loudness) / 20);

    // Plafond anti-saturation : la crête du morceau multipliée par le gain ne doit
    // jamais atteindre le plein échelle. 0.98 laisse une marge pour le
    // dépassement inter-échantillon, invisible sur les crêtes mesurées.
    if (typeof analysis.peak === 'number' && analysis.peak > 0) {
        gain = Math.min(gain, 0.98 / analysis.peak);
    }
    return Math.max(NORMALIZE_MIN_GAIN, Math.min(NORMALIZE_MAX_GAIN, gain));
}

function applyTrackNormalization(analysis) {
    _lastAnalysis = analysis || null;
    if (!normalizeGain || !audioCtx) return;
    const gain = normalizationGainFor(analysis);
    // Rampe courte : un changement de gain instantané en pleine lecture s'entend
    // comme un clic, exactement comme pour la réverbération.
    normalizeGain.gain.setTargetAtTime(gain, audioCtx.currentTime, 0.05);
}

function setNormalizeEnabled(enabled) {
    normalizeEnabled = !!enabled;
    localStorage.setItem('purpleMusicNormalize', normalizeEnabled ? '1' : '0');
    if (window.Alpine) Alpine.store('ui').normalizeEnabled = normalizeEnabled;
    // Réapplique immédiatement au morceau en cours : attendre le suivant donnerait
    // l'impression que le réglage n'a rien fait.
    applyTrackNormalization(_lastAnalysis);
}

function restoreNormalizeSetting() {
    normalizeEnabled = localStorage.getItem('purpleMusicNormalize') === '1';
    if (window.Alpine) Alpine.store('ui').normalizeEnabled = normalizeEnabled;
}


// =============================================================================
// QUALITE DE STREAMING
// =============================================================================
// L'app ne stocke qu'UN fichier par piste : proposer plusieurs debits suppose de
// les fabriquer, donc de reencoder, donc ffmpeg. Le serveur dit lui-meme s'il en
// dispose (api.php?action=quality_options) et l'interface n'affiche le reglage
// que dans ce cas -- plutot qu'un menu sans effet.
//
// En qualite d'origine, la lecture pointe DIRECTEMENT sur le fichier statique :
// c'est le chemin le plus rapide, sans PHP dans la boucle. Ce n'est qu'en debit
// reduit qu'on passe par api.php, qui sert la version reencodee et mise en cache.
let streamQuality = 'original';   // 'original' ou un debit en kbit/s
let streamQualityAvailable = false;

// Source de lecture d'une piste. Un seul endroit, utilise par loadTrack() comme
// par le prechargement du fondu enchaine -- sinon les deux divergeraient au
// premier changement de reglage.
function trackStreamUrl(track) {
    if (streamQuality === 'original') return 'music/' + track.filename;
    return 'api.php?action=stream&q=' + encodeURIComponent(track.id) + '&br=' + encodeURIComponent(streamQuality);
}

function setStreamQuality(value) {
    streamQuality = (value === 'original') ? 'original' : String(parseInt(value, 10) || 'original');
    localStorage.setItem('purpleMusicQuality', streamQuality);
    if (window.Alpine) Alpine.store('ui').streamQuality = streamQuality;
    // Le changement ne s'applique qu'a la piste SUIVANTE : recharger la source en
    // cours de lecture ferait repartir le morceau de zero, ce que personne
    // n'attend d'un reglage de qualite.
}

async function restoreStreamQuality() {
    const saved = localStorage.getItem('purpleMusicQuality') || 'original';
    streamQuality = saved;
    if (window.Alpine) Alpine.store('ui').streamQuality = saved;
    try {
        const res = await fetch('api.php?action=quality_options');
        const data = await res.json();
        streamQualityAvailable = !!(data && data.status === 'success' && data.available);
        if (window.Alpine) {
            Alpine.store('ui').streamQualityAvailable = streamQualityAvailable;
            Alpine.store('ui').streamQualityBitrates = (data && data.bitrates) || [];
        }
        // Le serveur ne sait pas reencoder mais un debit etait memorise (image
        // changee, ffmpeg retire) : on revient a l'original sans le dire, plutot
        // que de demander un debit qui retombera de toute facon sur l'original.
        if (!streamQualityAvailable && streamQuality !== 'original') {
            streamQuality = 'original';
            localStorage.setItem('purpleMusicQuality', 'original');
            if (window.Alpine) Alpine.store('ui').streamQuality = 'original';
        }
    } catch (e) {
        streamQualityAvailable = false;
    }
}
