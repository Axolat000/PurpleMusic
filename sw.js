// =============================================================================
// SERVICE WORKER
// =============================================================================
// Objectif : rendre l'app installable et instantanée au relancement, PAS la
// rendre utilisable hors ligne.
//
// Choix délibéré : les fichiers audio (music/) et l'API (api.php) ne sont JAMAIS
// mis en cache.
//   - L'audio : une bibliothèque auto-hébergée pèse facilement plusieurs Go ; la
//     mettre en cache saturerait le quota du navigateur et ferait évincer le
//     reste. Un vrai mode hors ligne demande une sélection explicite par
//     l'utilisateur (comme le client Android), pas un cache opportuniste.
//   - L'API : elle renvoie des données propres à la session ; les resservir
//     depuis un cache montrerait à un utilisateur les données d'un autre après
//     une déconnexion/reconnexion sur le même navigateur.
//
// Sont mis en cache : les assets statiques (CSS/JS/sprite/police) et les
// pochettes, qui changent rarement et pèsent peu.

// Version du cache : à incrémenter pour forcer la purge des anciens assets.
// Le paramètre ?v= des URLs d'assets suffit à récupérer une nouvelle version,
// mais le cache garderait indéfiniment les anciennes entrées sans ce ménage.
const CACHE_VERSION = 'pm-v1';
const STATIC_CACHE = `${CACHE_VERSION}-static`;
const COVER_CACHE = `${CACHE_VERSION}-covers`;
// Les pochettes s'accumulent (une par piste) : on borne le cache pour ne pas
// grignoter indéfiniment le quota sur une grosse bibliothèque.
const COVER_CACHE_MAX = 300;

// Écoute hors ligne : les morceaux que l'utilisateur a explicitement demandés.
//
// Son nom ne contient PAS la version du cache, contrairement aux deux autres, et
// c'est délibéré : ces fichiers ont été choisis un par un et peuvent peser
// plusieurs centaines de méga-octets. Les effacer à chaque déploiement, comme on
// le fait pour les assets, serait une purge invisible de données que l'utilisateur
// croit posséder. Seul lui les supprime (voir js/offline.js).
const OFFLINE_CACHE = 'pm-offline';

self.addEventListener('install', (event) => {
    // skipWaiting : la nouvelle version prend la main immédiatement au lieu
    // d'attendre la fermeture de tous les onglets. Sans lui, un utilisateur qui
    // garde l'app ouverte en permanence resterait sur l'ancienne version après
    // une mise à jour du serveur.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names.filter(n => !n.startsWith(CACHE_VERSION) && n !== OFFLINE_CACHE).map(n => caches.delete(n))
        );
        await self.clients.claim();
    })());
});

function isCoverRequest(url) {
    return url.pathname.includes('/covers/');
}

function isStaticAsset(url) {
    return /\.(css|js|woff2?|svg|ico)$/i.test(url.pathname);
}

// Éviction FIFO simple : suffisant pour des pochettes, toutes de taille et
// d'utilité comparables (une vraie LRU demanderait de journaliser les accès).
async function trimCache(cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length <= maxEntries) return;
    await Promise.all(keys.slice(0, keys.length - maxEntries).map(k => cache.delete(k)));
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    // Les requêtes non-GET modifient l'état côté serveur : jamais interceptées.
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    // Rien d'externe (Wikipédia, lrclib.net) : ces réponses ne nous appartiennent
    // pas et leur fraîcheur compte plus que leur vitesse.
    if (url.origin !== self.location.origin) return;
    // Audio : jamais mis en cache opportunistement (voir l'en-tête de fichier), mais
    // servi depuis le cache hors ligne s'il s'y trouve — c'est-à-dire uniquement si
    // l'utilisateur a demandé ce morceau explicitement. On ne remplit jamais ce
    // cache ici : le remplissage est un geste utilisateur, pas un effet de bord de
    // la lecture.
    if (url.pathname.includes('/music/')) {
        event.respondWith((async () => {
            const cached = await caches.match(req, { cacheName: OFFLINE_CACHE, ignoreSearch: true });
            if (cached) return cached;
            return fetch(req);
        })());
        return;
    }
    if (url.pathname.endsWith('api.php')) return;

    if (isStaticAsset(url)) {
        // Cache d'abord : ces fichiers portent un ?v= qui change à chaque
        // déploiement, une entrée en cache correspond donc toujours à la version
        // demandée — pas de risque de servir un asset périmé.
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            const res = await fetch(req);
            if (res.ok) {
                const cache = await caches.open(STATIC_CACHE);
                cache.put(req, res.clone());
            }
            return res;
        })());
        return;
    }

    if (isCoverRequest(url)) {
        event.respondWith((async () => {
            const cached = await caches.match(req);
            if (cached) return cached;
            try {
                const res = await fetch(req);
                if (res.ok) {
                    const cache = await caches.open(COVER_CACHE);
                    await cache.put(req, res.clone());
                    trimCache(COVER_CACHE, COVER_CACHE_MAX);
                }
                return res;
            } catch (e) {
                // Hors ligne et pochette absente du cache : on laisse l'échec
                // remonter, le onerror de la balise <img> bascule sur default.png.
                return Response.error();
            }
        })());
        return;
    }

    // Le document HTML lui-même : réseau d'abord (il contient l'état de session
    // et la bibliothèque complète, qui doivent être frais), avec repli sur le
    // cache uniquement si le réseau échoue.
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                const res = await fetch(req);
                if (res.ok) {
                    const cache = await caches.open(STATIC_CACHE);
                    cache.put(req, res.clone());
                }
                return res;
            } catch (e) {
                const cached = await caches.match(req);
                if (cached) return cached;
                throw e;
            }
        })());
    }
});

// =============================================================================
// NOTIFICATIONS PUSH
// =============================================================================
// Le serveur envoie un push VIDE : un simple signal, sans contenu (voir
// api/push.php). C'est ici qu'on va chercher quoi afficher.
//
// Deux consequences utiles de ce choix : le titre du morceau ne transite jamais
// par le service de push du navigateur, et le contenu affiche est celui du moment
// ou la notification apparait -- pas celui du moment ou elle a ete emise.
self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let items = [];
        try {
            // credentials:'include' est indispensable : sans cookie de session, le
            // serveur ne sait pas de quel compte il s'agit et ne renvoie rien.
            const res = await fetch('api.php?action=push_pending', { credentials: 'include' });
            const data = await res.json();
            if (data && data.status === 'success') items = data.items || [];
        } catch (e) { /* hors ligne : on affichera le repli ci-dessous */ }

        if (!items.length) {
            // Signal recu mais rien a lire (file deja vidée par un autre appareil,
            // ou session expiree). On n'affiche RIEN plutot qu'une notification
            // vide : la plupart des navigateurs en fabriqueraient une generique,
            // ce qui est pire que le silence.
            return;
        }

        await Promise.all(items.map(item => self.registration.showNotification(item.title, {
            body: item.body || '',
            icon: 'favicon.png',
            badge: 'favicon.png',
            // tag par identifiant : deux signaux pour la meme entree ne empilent pas
            // deux notifications identiques.
            tag: 'pm-' + item.id,
            data: { url: item.url || 'index.php' },
        })));
    })());
});

// Clic sur une notification : on reutilise un onglet deja ouvert sur l'app plutot
// que d'en empiler un nouveau a chaque fois.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || 'index.php';
    event.waitUntil((async () => {
        const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of all) {
            if (client.url.includes(self.registration.scope)) {
                await client.focus();
                if ('navigate' in client) await client.navigate(target);
                return;
            }
        }
        await clients.openWindow(target);
    })());
});
