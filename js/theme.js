
// --- THÈMES VISUELS (presets par utilisateur, appliqués par-dessus les couleurs admin/BDD) ---
// "violet" = pas de surcharge : on garde les couleurs configurées par l'admin (comportement par défaut).
// Les autres presets fixent l'intégralité des variables CSS avec des valeurs figées (cohérence avec l'app Android).
const THEME_VAR_NAMES = [
    '--bg-dark', '--bg-panel', '--primary', '--accent', '--text', '--text-muted',
    '--border-color', '--search-bg', '--header-bg', '--player-bg', '--mob-nav-bg',
    '--fp-gradient-1', '--fp-gradient-2'
];
const THEME_PRESETS = {
    // "amoled" existait seul à l'origine ; désormais scindé en variante Purple (valeurs identiques,
    // inchangées) + variante White (même fond quasi-noir, accent neutre/blanc). "amoled" reste défini
    // plus bas comme alias de "amoled_purple" pour ne pas casser un localStorage['purpleMusicTheme']
    // existant chez un utilisateur déjà sur ce preset avant l'ajout des variantes.
    amoled_purple: {
        '--bg-dark': '#000000', '--bg-panel': '#0A0A0A', '--primary': '#7B2CBF', '--accent': '#B388FF',
        '--text': '#E8E8E8', '--text-muted': '#9E9E9E', '--border-color': '#2A2A2A', '--search-bg': '#141414',
        '--header-bg': 'rgba(0,0,0,0.85)', '--player-bg': 'rgba(10,10,10,0.85)', '--mob-nav-bg': 'rgba(0,0,0,0.95)',
        '--fp-gradient-1': '#1A1A1A', '--fp-gradient-2': '#000000'
    },
    amoled_white: {
        '--bg-dark': '#000000', '--bg-panel': '#0A0A0A', '--primary': '#4D4D4D', '--accent': '#E8E8E8',
        '--text': '#F0F0F0', '--text-muted': '#9E9E9E', '--border-color': '#2A2A2A', '--search-bg': '#141414',
        '--header-bg': 'rgba(0,0,0,0.85)', '--player-bg': 'rgba(10,10,10,0.85)', '--mob-nav-bg': 'rgba(0,0,0,0.95)',
        '--fp-gradient-1': '#262626', '--fp-gradient-2': '#000000'
    },
    // Idem pour "midnight" -> "midnight_blue" (inchangé) + nouvelle variante "midnight_silver" (même
    // famille de fond, primary/accent neutres gris-bleu au lieu de bleu vif). "midnight" reste un alias
    // de "midnight_blue" plus bas, même logique de compatibilité ascendante que pour amoled.
    midnight_blue: {
        '--bg-dark': '#0B1120', '--bg-panel': '#161F35', '--primary': '#3B5BDB', '--accent': '#7C9BFF',
        '--text': '#E3E7F5', '--text-muted': '#8D97B8', '--border-color': '#2A3655', '--search-bg': '#1C2740',
        '--header-bg': 'rgba(14,21,38,0.85)', '--player-bg': 'rgba(22,31,53,0.85)', '--mob-nav-bg': 'rgba(14,21,38,0.95)',
        '--fp-gradient-1': '#1B2A4D', '--fp-gradient-2': '#0B1120'
    },
    midnight_silver: {
        '--bg-dark': '#0B1120', '--bg-panel': '#161F35', '--primary': '#6B7A99', '--accent': '#C7D0E0',
        '--text': '#E3E7F5', '--text-muted': '#8D97B8', '--border-color': '#2A3655', '--search-bg': '#1C2740',
        '--header-bg': 'rgba(14,21,38,0.85)', '--player-bg': 'rgba(22,31,53,0.85)', '--mob-nav-bg': 'rgba(14,21,38,0.95)',
        '--fp-gradient-1': '#2B3550', '--fp-gradient-2': '#0B1120'
    },
    forest: {
        '--bg-dark': '#0D1811', '--bg-panel': '#16261C', '--primary': '#2E7D4F', '--accent': '#6FCF97',
        '--text': '#DCEAE1', '--text-muted': '#8FA396', '--border-color': '#24392C', '--search-bg': '#1C2F22',
        '--header-bg': 'rgba(15,29,20,0.85)', '--player-bg': 'rgba(22,38,28,0.85)', '--mob-nav-bg': 'rgba(15,29,20,0.95)',
        '--fp-gradient-1': '#1D3324', '--fp-gradient-2': '#0D1811'
    },
    crimson: {
        '--bg-dark': '#1A0E0E', '--bg-panel': '#2A1616', '--primary': '#B33A3A', '--accent': '#FF6B6B',
        '--text': '#F5DEDE', '--text-muted': '#B09090', '--border-color': '#3D2020', '--search-bg': '#331A1A',
        '--header-bg': 'rgba(30,16,16,0.85)', '--player-bg': 'rgba(42,22,22,0.85)', '--mob-nav-bg': 'rgba(30,16,16,0.95)',
        '--fp-gradient-1': '#34191A', '--fp-gradient-2': '#1A0E0E'
    },
    // --- Nouveaux presets ---
    ocean: {
        '--bg-dark': '#071B1E', '--bg-panel': '#0F2A2E', '--primary': '#0E8388', '--accent': '#4DD8D0',
        '--text': '#DFF5F3', '--text-muted': '#86A8A6', '--border-color': '#1C3A3D', '--search-bg': '#102528',
        '--header-bg': 'rgba(7,27,30,0.85)', '--player-bg': 'rgba(15,42,46,0.85)', '--mob-nav-bg': 'rgba(7,27,30,0.95)',
        '--fp-gradient-1': '#123B3E', '--fp-gradient-2': '#071B1E'
    },
    sunset: {
        '--bg-dark': '#1A1108', '--bg-panel': '#2B1B0E', '--primary': '#D9822B', '--accent': '#FFB86B',
        '--text': '#F7E8D4', '--text-muted': '#B99B7C', '--border-color': '#3D2A16', '--search-bg': '#241708',
        '--header-bg': 'rgba(26,17,8,0.85)', '--player-bg': 'rgba(43,27,14,0.85)', '--mob-nav-bg': 'rgba(26,17,8,0.95)',
        '--fp-gradient-1': '#3D2610', '--fp-gradient-2': '#1A1108'
    },
    rose: {
        '--bg-dark': '#1A0E16', '--bg-panel': '#2A1622', '--primary': '#C2478D', '--accent': '#FF8FC7',
        '--text': '#F5DCEB', '--text-muted': '#B08CA6', '--border-color': '#3D2032', '--search-bg': '#24141F',
        '--header-bg': 'rgba(26,14,22,0.85)', '--player-bg': 'rgba(42,22,34,0.85)', '--mob-nav-bg': 'rgba(26,14,22,0.95)',
        '--fp-gradient-1': '#3A1A2C', '--fp-gradient-2': '#1A0E16'
    },
    slate: {
        '--bg-dark': '#121417', '--bg-panel': '#1C1F24', '--primary': '#5C6773', '--accent': '#9BA8B5',
        '--text': '#E4E7EA', '--text-muted': '#8C949C', '--border-color': '#2A2E34', '--search-bg': '#191C20',
        '--header-bg': 'rgba(18,20,23,0.85)', '--player-bg': 'rgba(28,31,36,0.85)', '--mob-nav-bg': 'rgba(18,20,23,0.95)',
        '--fp-gradient-1': '#262A30', '--fp-gradient-2': '#121417'
    },
    // Seul preset clair du site -- accent assombri par rapport au violet par défaut (#BB86FC est pensé pour
    // un fond sombre ; sur fond quasi blanc il perdrait tout contraste utilisé tel quel en couleur de texte
    // pour nav/liens actifs).
    light: {
        '--bg-dark': '#F5F3F8', '--bg-panel': '#FFFFFF', '--primary': '#8E44AD', '--accent': '#6C2E8C',
        '--text': '#1A1420', '--text-muted': '#6B6076', '--border-color': '#E0DAE8', '--search-bg': '#F0ECF5',
        '--header-bg': 'rgba(255,255,255,0.85)', '--player-bg': 'rgba(255,255,255,0.9)', '--mob-nav-bg': 'rgba(255,255,255,0.95)',
        '--fp-gradient-1': '#E8E0F0', '--fp-gradient-2': '#F5F3F8'
    }
};
// Alias de compatibilité ascendante : un utilisateur avec 'amoled'/'midnight' déjà en localStorage
// (avant la scission en variantes) doit continuer à retomber sur exactement les mêmes couleurs.
THEME_PRESETS.amoled = THEME_PRESETS.amoled_purple;
THEME_PRESETS.midnight = THEME_PRESETS.midnight_blue;

const CUSTOM_THEME_STORAGE_KEY = 'purpleMusicCustomTheme';

// Applique (ou retire) les surcharges de variables CSS sur <html>. "violet" = retour aux couleurs admin/BDD.
// "custom" = thème personnalisé de l'utilisateur (voir section THÈME PERSONNALISÉ plus bas), lu depuis
// localStorage plutôt que depuis l'objet statique THEME_PRESETS.
function setThemeVars(name) {
    const root = document.documentElement;
    if (name === 'custom') {
        const custom = getCustomThemeVars();
        THEME_VAR_NAMES.forEach(v => {
            if (custom[v]) root.style.setProperty(v, custom[v]);
            else root.style.removeProperty(v);
        });
        return;
    }
    const preset = THEME_PRESETS[name];
    if (!preset) {
        THEME_VAR_NAMES.forEach(v => root.style.removeProperty(v));
        return;
    }
    Object.entries(preset).forEach(([k, v]) => root.style.setProperty(k, v));
}

// Applique un preset de thème (ou 'custom'), le persiste en localStorage (par navigateur/utilisateur) et
// met à jour l'UI (swatch actif).
function applyThemePreset(name) {
    if (!THEME_PRESETS[name] && name !== 'violet' && name !== 'custom') return;
    localStorage.setItem('purpleMusicTheme', name);
    setThemeVars(name);
    if (window.Alpine) Alpine.store('ui').themePreset = name;
}

// Application la plus précoce possible (avant même Alpine) pour éviter un flash des couleurs par défaut.
setThemeVars(localStorage.getItem('purpleMusicTheme') || 'violet');

// --- THÈME PERSONNALISÉ (constructeur dans Paramètres > Général) ---
// Contrairement aux presets statiques ci-dessus, le thème personnalisé est un objet {varName: '#hex'}
// stocké en localStorage (CUSTOM_THEME_STORAGE_KEY) et édité via des <input type="color"> (voir
// index.php, bloc "custom-theme-builder"). Écriture "live" : chaque changement de couleur persiste
// immédiatement et active 'custom' comme preset courant (pas de bouton Enregistrer séparé), pour un
// aperçu instantané cohérent avec le reste des réglages de Paramètres.
function getCustomThemeVars() {
    try {
        return JSON.parse(localStorage.getItem(CUSTOM_THEME_STORAGE_KEY)) || {};
    } catch (e) {
        return {};
    }
}

// '--fp-gradient-1' -> 'custom-color-fp-gradient-1' (id des <input type="color"> dans index.php).
function customThemeInputId(varName) {
    return 'custom-color-' + varName.replace(/^--/, '');
}

// Convertit une couleur CSS quelconque (hex 3/6, rgb()/rgba()) en hex 6 chiffres exploitable par un
// <input type="color"> (qui n'accepte que #rrggbb). Réutilise parseColorToRgb (plus bas dans ce fichier,
// disponible ici grâce au hoisting des déclarations `function`). L'alpha des rgba() (header/player/
// mob-nav-bg) est perdu au passage -- limitation acceptée (voir commentaire dans index.php).
function colorToHex(str) {
    const rgb = parseColorToRgb(str);
    if (!rgb) return null;
    return '#' + rgb.map(c => Math.max(0, Math.min(255, c)).toString(16).padStart(2, '0')).join('');
}

// Appelé par chaque <input type="color" oninput="..."> du constructeur : persiste la variable modifiée,
// réapplique tout le thème personnalisé (pour que les variables pas encore personnalisées restent
// cohérentes) et bascule immédiatement le preset actif sur 'custom'.
function updateCustomThemeColor(varName, hexValue) {
    const custom = getCustomThemeVars();
    custom[varName] = hexValue;
    localStorage.setItem(CUSTOM_THEME_STORAGE_KEY, JSON.stringify(custom));
    applyThemePreset('custom');
}

// Repart des couleurs actuellement affichées (preset en cours, quel qu'il soit) comme point de départ du
// thème personnalisé : remplit les <input type="color"> ET active 'custom' avec ces valeurs. Appelé par
// le bouton "Partir du thème actuel" ainsi qu'automatiquement au premier clic sur le swatch "Personnalisé"
// tant qu'aucun thème personnalisé n'a encore été enregistré (voir activateCustomTheme()).
function prefillCustomThemeFromCurrent() {
    const computed = getComputedStyle(document.documentElement);
    const custom = {};
    THEME_VAR_NAMES.forEach(v => {
        const hex = colorToHex(computed.getPropertyValue(v).trim()) || '#000000';
        custom[v] = hex;
        const input = document.getElementById(customThemeInputId(v));
        if (input) input.value = hex;
    });
    localStorage.setItem(CUSTOM_THEME_STORAGE_KEY, JSON.stringify(custom));
    applyThemePreset('custom');
}

// Swatch "Personnalisé" de la rangée de thèmes : première activation -> pré-remplissage automatique
// depuis le thème actuellement affiché (meilleure expérience que de basculer sur du noir/violet par
// défaut) ; activations suivantes -> simple réapplication du thème personnalisé déjà enregistré.
function activateCustomTheme() {
    if (Object.keys(getCustomThemeVars()).length === 0) prefillCustomThemeFromCurrent();
    else applyThemePreset('custom');
}

// Initialise les valeurs des <input type="color"> du constructeur à l'ouverture de la page, à partir des
// couleurs actuellement résolues (déjà correctes à ce stade, qu'il s'agisse des couleurs admin/BDD, d'un
// preset ou d'un thème personnalisé précédemment activé -- setThemeVars() a déjà tourné plus haut).
function initCustomThemeBuilder() {
    const computed = getComputedStyle(document.documentElement);
    THEME_VAR_NAMES.forEach(v => {
        const input = document.getElementById(customThemeInputId(v));
        if (!input) return;
        input.value = colorToHex(computed.getPropertyValue(v).trim()) || '#000000';
    });
}

// --- GÉNÉRATION ALGORITHMIQUE D'UN THÈME COMPLET À PARTIR D'UNE SEULE COULEUR ---
// S'intègre dans le constructeur de thème personnalisé ci-dessus plutôt que de créer un mode de thème
// séparé : pré-remplit les mêmes <input type="color"> que prefillCustomThemeFromCurrent(), juste depuis
// une couleur de base choisie plutôt que depuis le thème actuellement affiché.

function hslToRgb(h, s, l) {
    h = ((h % 360) + 360) % 360;
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs((h / 60) % 2 - 1));
    const m = l - c / 2;
    let r = 0, g = 0, b = 0;
    if (h < 60) { r = c; g = x; b = 0; }
    else if (h < 120) { r = x; g = c; b = 0; }
    else if (h < 180) { r = 0; g = c; b = x; }
    else if (h < 240) { r = 0; g = x; b = c; }
    else if (h < 300) { r = x; g = 0; b = c; }
    else { r = c; g = 0; b = x; }
    return [Math.round((r + m) * 255), Math.round((g + m) * 255), Math.round((b + m) * 255)];
}

function rgbToHex(rgb) {
    return '#' + rgb.map(c => Math.max(0, Math.min(255, Math.round(c))).toString(16).padStart(2, '0')).join('');
}

// Luminance relative WCAG (sRGB -> linéaire) -- sert au calcul du ratio de contraste ci-dessous.
function relLuminance(rgb) {
    const [r, g, b] = rgb.map(v => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

// Ratio de contraste WCAG entre deux couleurs (1 = aucun contraste, 21 = noir/blanc). Utilisé pour garantir
// que l'accent généré reste visible sur le fond généré, plutôt que de se fier uniquement à la formule HSL.
function contrastRatio(rgb1, rgb2) {
    const l1 = relLuminance(rgb1), l2 = relLuminance(rgb2);
    const lighter = Math.max(l1, l2), darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
}

// Dérive les 13 variables de THEME_VAR_NAMES à partir d'une seule couleur hex -- la couleur de base devient
// --bg-dark, tout le reste (panel/primary/accent/texte/bordures/dégradés) est calculé par décalages HSL
// autour de sa teinte, avec un repli violet (identité visuelle du site) si la couleur est quasi grise
// (saturation < 0.08, la formule HSL seule produirait un résultat instable/terne dans ce cas).
function generateThemeFromBaseColor(hex) {
    const baseRgb = parseColorToRgb(hex);
    if (!baseRgb) return null;
    const [h, s, l] = rgbToHsl(baseRgb[0], baseRgb[1], baseRgb[2]);
    const isLight = l > 0.5;
    const isAchromatic = s < 0.08;
    const hue = isAchromatic ? 262 : h;

    const panelRgb = hslToRgb(hue, isAchromatic ? 0.15 : s, isLight ? Math.max(0, l - 0.05) : Math.min(1, l + 0.05));
    const searchRgb = hslToRgb(hue, isAchromatic ? 0.15 : s, isLight ? Math.max(0, l - 0.09) : Math.min(1, l + 0.09));
    const borderRgb = hslToRgb(hue, isAchromatic ? 0.1 : s * 0.6, isLight ? Math.max(0, l - 0.12) : Math.min(1, l + 0.14));
    const primaryRgb = hslToRgb(hue, Math.min(1, (isAchromatic ? 0.5 : s) + 0.15), isLight ? 0.42 : 0.48);

    let accentS = Math.min(1, (isAchromatic ? 0.55 : s) + (isLight ? 0.35 : 0.3));
    let accentL = isLight ? 0.38 : 0.72;
    let accentRgb = hslToRgb(hue, accentS, accentL);
    let guard = 0;
    while (contrastRatio(accentRgb, baseRgb) < 2.5 && guard < 10) {
        accentL = isLight ? Math.max(0.15, accentL - 0.05) : Math.min(0.9, accentL + 0.05);
        accentRgb = hslToRgb(hue, accentS, accentL);
        guard++;
    }

    const textRgb = contrastRatio([230, 230, 230], baseRgb) >= contrastRatio([20, 20, 20], baseRgb) ? [230, 230, 230] : [20, 20, 20];
    const textMutedRgb = hslToRgb(hue, isAchromatic ? 0.1 : s * 0.4, isLight ? 0.35 : 0.65);
    const fpGradient1Rgb = hslToRgb(hue, isAchromatic ? 0.3 : Math.min(1, s + 0.1), isLight ? Math.max(0, l - 0.08) : Math.min(1, l + 0.12));

    return {
        '--bg-dark': rgbToHex(baseRgb),
        '--bg-panel': rgbToHex(panelRgb),
        '--primary': rgbToHex(primaryRgb),
        '--accent': rgbToHex(accentRgb),
        '--text': rgbToHex(textRgb),
        '--text-muted': rgbToHex(textMutedRgb),
        '--border-color': rgbToHex(borderRgb),
        '--search-bg': rgbToHex(searchRgb),
        '--header-bg': `rgba(${panelRgb[0]}, ${panelRgb[1]}, ${panelRgb[2]}, 0.85)`,
        '--player-bg': `rgba(${panelRgb[0]}, ${panelRgb[1]}, ${panelRgb[2]}, 0.85)`,
        '--mob-nav-bg': `rgba(${baseRgb[0]}, ${baseRgb[1]}, ${baseRgb[2]}, 0.95)`,
        '--fp-gradient-1': rgbToHex(fpGradient1Rgb),
        '--fp-gradient-2': rgbToHex(baseRgb)
    };
}

// Appelé par le bouton "Générer" du constructeur de thème (voir modal-settings.php) : calcule le thème
// complet, l'écrit dans les <input type="color"> (même conversion en hex opaque que
// prefillCustomThemeFromCurrent(), l'alpha des rgba() est perdu au passage -- même compromis assumé), le
// persiste et l'active.
function generateAndApplyCustomTheme(baseHex) {
    const custom = generateThemeFromBaseColor(baseHex);
    if (!custom) return;
    localStorage.setItem(CUSTOM_THEME_STORAGE_KEY, JSON.stringify(custom));
    THEME_VAR_NAMES.forEach(v => {
        const input = document.getElementById(customThemeInputId(v));
        if (input) input.value = colorToHex(custom[v]) || '#000000';
    });
    applyThemePreset('custom');
}

// --- THÈME DYNAMIQUE (surcouche par piste, PAR-DESSUS le preset statique actif -- voir THEME_PRESETS
// ci-dessus, qu'on ne touche pas ici) : extrait une couleur dominante et une couleur vibrante de la
// pochette de la piste en cours (Canvas 2D, aucune librairie externe) et anime --fp-gradient-1/2 vers ces
// couleurs, à la place des valeurs figées du preset. Portage du comportement de l'app Android
// (Palette API + animateColorAsState(tween(1000)) dans NowPlayingScreen.kt) : réglage désactivé par défaut
// (Paramètres > Général), persistant en localStorage (purpleMusicDynamicThemeEnabled, même convention que
// purpleMusicEqEnabled/purpleMusicVisualizerEnabled). Tant échec d'extraction (pas de pochette, erreur de
// chargement, canvas "tainted"...) que réglage désactivé -> --fp-gradient-1/2 ne sont jamais touchées et le
// preset statique garde la main entière, comme avant cette fonctionnalité.
let dynamicThemeRaf = null;

// Parse une couleur CSS ("#rgb", "#rrggbb", "rgb()"/"rgba()") en triplet [r,g,b] -- getComputedStyle()
// renvoie généralement du "rgb(...)" pour une valeur qu'on a nous-même écrite via setProperty(), et du hex
// littéral pour les valeurs venant de THEME_PRESETS ou du <style> injecté par index.php (couleurs admin) :
// on doit donc gérer les deux formats pour pouvoir repartir de la valeur actuellement affichée.
function parseColorToRgb(str) {
    if (!str) return null;
    str = str.trim();
    let m = str.match(/^#([0-9a-f]{3})$/i);
    if (m) {
        const h = m[1];
        return [parseInt(h[0] + h[0], 16), parseInt(h[1] + h[1], 16), parseInt(h[2] + h[2], 16)];
    }
    m = str.match(/^#([0-9a-f]{6})$/i);
    if (m) {
        const h = m[1];
        return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)];
    }
    m = str.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i);
    if (m) return [Math.round(parseFloat(m[1])), Math.round(parseFloat(m[2])), Math.round(parseFloat(m[3]))];
    return null;
}

function rgbToCss(rgb) {
    return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
}

// RGB (0..255) -> HSL (h en degrés, s/l en 0..1) -- sert uniquement à repérer le pixel le plus "vibrant".
function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const max = Math.max(r, g, b), min = Math.min(r, g, b);
    const l = (max + min) / 2;
    let h = 0, s = 0;
    const d = max - min;
    if (d !== 0) {
        s = d / (1 - Math.abs(2 * l - 1));
        switch (max) {
            case r: h = ((g - b) / d) % 6; break;
            case g: h = (b - r) / d + 2; break;
            default: h = (r - g) / d + 4; break;
        }
        h *= 60;
        if (h < 0) h += 360;
    }
    return [h, s, l];
}

// Extrait une couleur "dominante" (moyenne de tous les pixels échantillonnés -- simple et donne de bons
// résultats sans vraie quantification de couleurs) et une couleur "vibrante" (pixel de saturation maximale,
// avec un plancher de saturation/luminosité pour éviter de retomber sur un pixel quasi-noir ou quasi-blanc
// "techniquement" le plus saturé -- approximation grossière des contraintes target saturation/luminance de
// l'API Palette d'Android) depuis une image. Sous-échantillonnage à 50x50 via drawImage (au lieu de la
// résolution native) : largement suffisant pour une couleur moyenne/dominante et bien plus rapide que de
// lire chaque pixel d'une pochette potentiellement énorme.
function extractDominantAndVibrantColors(imageUrl) {
    return new Promise((resolve, reject) => {
        try {
            const img = new Image();
            img.crossOrigin = 'anonymous'; // filet de sécurité si jamais une pochette n'était pas same-origin
            img.onload = () => {
                try {
                    const SIZE = 50;
                    const canvas = document.createElement('canvas');
                    canvas.width = SIZE;
                    canvas.height = SIZE;
                    const ctx = canvas.getContext('2d', { willReadFrequently: true });
                    if (!ctx) { reject(new Error('2d context unavailable')); return; }
                    ctx.drawImage(img, 0, 0, SIZE, SIZE);

                    let data;
                    try {
                        data = ctx.getImageData(0, 0, SIZE, SIZE).data;
                    } catch (e) {
                        // Canvas "tainted" (CORS) -- ne devrait pas arriver (pochettes same-origin) mais on
                        // n'échoue jamais silencieusement en pleine lecture pour autant.
                        reject(e);
                        return;
                    }

                    let rSum = 0, gSum = 0, bSum = 0, count = 0;
                    let bestSat = -1, bestColor = null; // meilleur pixel respectant le plancher s/l
                    let bestAnySat = -1, bestAnyColor = null; // repli : meilleure saturation sans plancher (pochette grise/pastel)

                    for (let i = 0; i < data.length; i += 4) {
                        const a = data[i + 3];
                        if (a < 125) continue; // pixel majoritairement transparent -> ignoré (pochette PNG avec zones transparentes)
                        const r = data[i], g = data[i + 1], b = data[i + 2];
                        rSum += r; gSum += g; bSum += b; count++;

                        const [, s, l] = rgbToHsl(r, g, b);
                        if (s > bestAnySat) { bestAnySat = s; bestAnyColor = [r, g, b]; }
                        if (s >= 0.35 && l >= 0.2 && l <= 0.8 && s > bestSat) { bestSat = s; bestColor = [r, g, b]; }
                    }

                    if (count === 0) { reject(new Error('no opaque pixels sampled')); return; }

                    const dominant = [Math.round(rSum / count), Math.round(gSum / count), Math.round(bSum / count)];
                    const vibrant = bestColor || bestAnyColor || dominant;
                    resolve({ dominant: rgbToCss(dominant), vibrant: rgbToCss(vibrant) });
                } catch (e) {
                    reject(e);
                }
            };
            img.onerror = () => reject(new Error('cover image failed to load'));
            img.src = imageUrl;
        } catch (e) {
            reject(e);
        }
    });
}

function cancelDynamicThemeTween() {
    if (dynamicThemeRaf !== null) {
        cancelAnimationFrame(dynamicThemeRaf);
        dynamicThemeRaf = null;
    }
}

// Anime --fp-gradient-1/2 (sur <html>, donc lues aussi bien par #full-player que par .dfp-card, voir
// style.css) de leurs valeurs actuelles vers targetG1/targetG2 en ~1s -- interpolation manuelle RGB via
// requestAnimationFrame (transition CSS native sur une custom property nécessiterait @property, pas
// supporté partout) : ~même effet que animateColorAsState(tween(1000)) côté Android. Repart toujours de la
// valeur CSS actuellement calculée (et non d'un état mémorisé séparément) : ça inclut aussi bien la
// dernière couleur du preset statique (première extraction après activation) qu'une éventuelle valeur
// intermédiaire si un tween précédent est interrompu par un changement de piste rapproché.
function tweenGradientColors(targetG1, targetG2) {
    cancelDynamicThemeTween();
    const root = document.documentElement;
    const computed = getComputedStyle(root);
    const startG1 = parseColorToRgb(computed.getPropertyValue('--fp-gradient-1')) || parseColorToRgb(targetG1);
    const startG2 = parseColorToRgb(computed.getPropertyValue('--fp-gradient-2')) || parseColorToRgb(targetG2);
    const toG1 = parseColorToRgb(targetG1);
    const toG2 = parseColorToRgb(targetG2);

    if (!startG1 || !startG2 || !toG1 || !toG2) {
        // Repli défensif si une valeur ne se parse pas pour une raison quelconque : on applique directement
        // plutôt que de ne rien afficher.
        root.style.setProperty('--fp-gradient-1', targetG1);
        root.style.setProperty('--fp-gradient-2', targetG2);
        return;
    }

    const durationMs = 1000;
    const startTime = performance.now();

    function step(now) {
        const rawT = Math.min(1, (now - startTime) / durationMs);
        // Ease-in-out cubique -- transition un peu plus douce qu'un simple lerp linéaire.
        const t = rawT < 0.5 ? 4 * rawT * rawT * rawT : 1 - Math.pow(-2 * rawT + 2, 3) / 2;

        const c1 = [0, 1, 2].map(i => Math.round(startG1[i] + (toG1[i] - startG1[i]) * t));
        const c2 = [0, 1, 2].map(i => Math.round(startG2[i] + (toG2[i] - startG2[i]) * t));
        root.style.setProperty('--fp-gradient-1', rgbToCss(c1));
        root.style.setProperty('--fp-gradient-2', rgbToCss(c2));

        if (rawT < 1) {
            dynamicThemeRaf = requestAnimationFrame(step);
        } else {
            dynamicThemeRaf = null;
        }
    }
    dynamicThemeRaf = requestAnimationFrame(step);
}

// Appelée depuis loadTrack() à chaque changement de piste (et depuis setDynamicThemeEnabled() à
// l'activation, pour appliquer immédiatement à la piste déjà en cours). No-op silencieux si le réglage est
// désactivé ou si Alpine n'est pas encore prêt.
function applyDynamicThemeForCurrentTrack() {
    if (!window.Alpine || !Alpine.store('ui').dynamicThemeEnabled) return;
    const track = queue[currentIndex];
    if (!track) return;
    const coverUrl = 'covers/' + (track.cover || 'default.png');
    const trackIdAtRequest = track.id;

    extractDominantAndVibrantColors(coverUrl)
        .then(({ dominant, vibrant }) => {
            // Le réglage a pu être désactivé, ou une autre piste a pu être chargée entretemps (extraction
            // asynchrone) : dans les deux cas, on n'applique pas un résultat périmé.
            if (!window.Alpine || !Alpine.store('ui').dynamicThemeEnabled) return;
            if (!queue[currentIndex] || queue[currentIndex].id !== trackIdAtRequest) return;
            tweenGradientColors(dominant, vibrant);
        })
        .catch((e) => {
            // Échec d'extraction (pas de pochette, erreur réseau/décodage...) : jamais d'erreur visible ni de
            // page cassée -- on laisse simplement --fp-gradient-1/2 tel quel (couleurs du preset statique actif).
            console.error('Dynamic theme extraction failed', e);
        });
}

// Bascule le réglage persistant (Paramètres > Général) -- même schéma que setVisualizerEnabled().
// Désactivation : on retire nos surcharges de --fp-gradient-1/2 (retour immédiat, sans tween, aux valeurs
// du preset statique actif -- symétrique avec le "no-op total" documenté plus haut).
function setDynamicThemeEnabled(enabled) {
    if (!window.Alpine) return;
    Alpine.store('ui').dynamicThemeEnabled = enabled;
    localStorage.setItem('purpleMusicDynamicThemeEnabled', enabled ? '1' : '0');
    if (enabled) {
        applyDynamicThemeForCurrentTrack();
    } else {
        cancelDynamicThemeTween();
        document.documentElement.style.removeProperty('--fp-gradient-1');
        document.documentElement.style.removeProperty('--fp-gradient-2');
    }
}

const playIcon = '<svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>';
const pauseIcon = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';

// Tracé du haut-parleur "actif"/"muet" pour le bouton-icône du volume (mini-barre + grand lecteur desktop).
const volumeIconPath = 'M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z';
const mutedVolumeIconPath = 'M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.8L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z';

const desktopVol = document.getElementById('desktop-vol');
const settingsVol = document.getElementById('settings-vol');
const dpVol = document.getElementById('dp-vol');

// --- ADMIN PANEL : réinitialisation du mot de passe d'un utilisateur (admin uniquement) ---
// Différent du changement self-service (onglet Compte des Paramètres) : pas besoin de l'ancien mot
// de passe, génère un mot de passe temporaire aléatoire côté serveur et l'affiche une seule fois.
