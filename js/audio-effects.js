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
