// =============================================================================
// ÉCOUTE HORS LIGNE : TÉLÉCHARGEMENT DES COUPS DE CŒUR
// =============================================================================
// Version web allégée du mode hors ligne de l'app Android : quelques morceaux
// choisis, gardés dans le navigateur, jouables sans réseau.
//
// CE QUI EST TÉLÉCHARGÉ, ET PAR QUI. Uniquement les morceaux likés, et seulement
// sur demande explicite. Le service worker ne met JAMAIS d'audio en cache tout
// seul (voir l'en-tête de sw.js) : une bibliothèque auto-hébergée pèse facilement
// plusieurs gigaoctets, un cache opportuniste saturerait le quota du navigateur
// et ferait évincer le reste — y compris les fichiers qu'on croyait posséder.
//
// OÙ. Un cache dédié ('pm-offline') dont le nom ne porte pas la version des
// assets : un déploiement purge les CSS et les JS, jamais la musique téléchargée.
// Seul l'utilisateur la supprime.
//
// Ce fichier ne fait que remplir et vider ce cache ; c'est le service worker qui
// s'en sert au moment de lire.

const OFFLINE_CACHE = 'pm-offline';

// Le navigateur peut refuser d'ouvrir un cache (mode privé strict, stockage
// désactivé). Tout passe par ce point d'entrée pour que l'interface puisse dire
// « indisponible » au lieu de planter.
async function openOfflineCache() {
    if (!('caches' in window)) return null;
    try {
        return await caches.open(OFFLINE_CACHE);
    } catch (e) {
        return null;
    }
}

function offlineUrlFor(track) {
    return 'music/' + track.filename;
}

// Identifiants des morceaux effectivement présents dans le cache.
async function offlineStoredIds(tracks) {
    const cache = await openOfflineCache();
    if (!cache) return new Set();
    const keys = await cache.keys();
    const stored = new Set(keys.map(r => r.url));
    const ids = new Set();
    tracks.forEach(t => {
        // new URL() résout le chemin relatif comme le navigateur l'a fait au
        // moment du stockage : comparer des chaînes brutes échouerait dès que
        // l'app est servie depuis un sous-dossier.
        if (stored.has(new URL(offlineUrlFor(t), window.location.href).href)) ids.add(t.id);
    });
    return ids;
}

// Poids total occupé, en octets. Lu depuis les réponses stockées plutôt que par
// l'API de quota, qui mesure tout le stockage du site et pas seulement ceci.
async function offlineUsedBytes() {
    const cache = await openOfflineCache();
    if (!cache) return 0;
    let total = 0;
    for (const req of await cache.keys()) {
        const res = await cache.match(req);
        if (!res) continue;
        const len = res.headers.get('content-length');
        if (len) { total += parseInt(len, 10) || 0; continue; }
        // Pas d'en-tête de longueur : on lit le corps. Plus coûteux, mais un
        // total faux serait pire qu'un total lent à calculer.
        try { total += (await res.clone().blob()).size; } catch (e) { /* réponse illisible : ignorée */ }
    }
    return total;
}

function formatBytes(bytes) {
    if (bytes >= 1024 * 1024 * 1024) return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' Go';
    if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(0) + ' Mo';
    return Math.max(1, Math.round(bytes / 1024)) + ' Ko';
}

// Télécharge une liste de morceaux, un par un.
//
// Séquentiel et non parallèle : lancer trente téléchargements de front sature la
// liaison et fait passer la lecture en cours devant le même goulot. On préfère
// que ça prenne plus longtemps sans que la musique saccade.
async function offlineDownload(tracks, onProgress) {
    const cache = await openOfflineCache();
    if (!cache) return { ok: 0, failed: tracks.length };

    let ok = 0, failed = 0;
    for (let i = 0; i < tracks.length; i++) {
        if (onProgress) onProgress(i, tracks.length);
        const url = offlineUrlFor(tracks[i]);
        try {
            const existing = await cache.match(url);
            if (existing) { ok++; continue; }
            const res = await fetch(url);
            if (!res.ok) { failed++; continue; }
            await cache.put(url, res);
            ok++;
        } catch (e) {
            // Quota dépassé, réseau coupé : on compte l'échec et on continue. Un
            // morceau qui ne passe pas ne doit pas arrêter les vingt suivants.
            failed++;
        }
    }
    if (onProgress) onProgress(tracks.length, tracks.length);
    return { ok, failed };
}

async function offlineRemoveAll() {
    if (!('caches' in window)) return;
    try { await caches.delete(OFFLINE_CACHE); } catch (e) { /* rien à supprimer */ }
}

// --- Composant de réglage ----------------------------------------------------
document.addEventListener('alpine:init', () => {
    Alpine.data('offlineDownloads', () => ({
        supported: ('caches' in window) && ('serviceWorker' in navigator),
        storedCount: 0,
        usedLabel: '',
        working: false,
        progress: '',

        init() {
            this.refresh();
        },

        // Les coups de cœur, lus dans la bibliothèque déjà chargée. is_liked y est
        // maintenu à jour par toggleLikeUI() : pas besoin d'un appel réseau pour
        // savoir ce qu'on aime.
        get likedTracks() {
            if (typeof ALL_MUSIC_DATA === 'undefined') return [];
            return ALL_MUSIC_DATA.filter(t => Number(t.is_liked) > 0);
        },

        async refresh() {
            if (!this.supported) return;
            const ids = await offlineStoredIds(this.likedTracks);
            this.storedCount = ids.size;
            this.usedLabel = formatBytes(await offlineUsedBytes());
        },

        async download() {
            if (this.working || !this.supported) return;
            this.working = true;
            try {
                const res = await offlineDownload(this.likedTracks, (done, total) => {
                    this.progress = T('offline_progress', { done, total });
                });
                this.progress = '';
                await this.refresh();
                Alpine.store('ui').showToast(
                    res.failed ? T('offline_done_partial', { n: res.ok, failed: res.failed })
                               : T('offline_done', { n: res.ok }),
                    res.failed ? 'info' : 'success');
            } finally {
                this.working = false;
            }
        },

        clear() {
            Alpine.store('ui').confirmAction(T('offline_clear_confirm'), async () => {
                await offlineRemoveAll();
                await this.refresh();
                Alpine.store('ui').showToast(T('offline_cleared'), 'info');
            });
        },
    }));
});
