// =============================================================================
// NOTIFICATIONS PUSH ET SUIVI D'ARTISTES
// =============================================================================
// « Être prévenu quand un artiste suivi sort quelque chose » supposait deux
// choses qui n'existaient pas : un suivi d'artiste, et un canal de notification.
// Les deux sont ici.
//
// Rien n'est demandé sans geste explicite. La permission de notifier n'est
// réclamée qu'au moment où l'on active le réglage — jamais au chargement de la
// page, où elle serait refusée neuf fois sur dix et définitivement bloquée
// ensuite par le navigateur.

let pushPublicKey = null;
let pushAvailable = false;

// La clé publique VAPID voyage en base64 URL-safe ; l'API du navigateur veut un
// Uint8Array. Conversion obligatoire, et silencieusement fatale si on l'oublie.
function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(normalized);
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
}

async function loadPushConfig() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    try {
        const res = await fetch('api.php?action=push_config');
        const data = await res.json();
        if (!data || data.status !== 'success' || !data.available) return;
        pushPublicKey = data.public_key;
        pushAvailable = true;

        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (window.Alpine) {
            Alpine.store('ui').pushAvailable = true;
            Alpine.store('ui').pushEnabled = !!sub;
        }
    } catch (e) {
        pushAvailable = false;
    }
}

async function setPushEnabled(enabled) {
    if (!pushAvailable) return;
    const store = Alpine.store('ui');
    try {
        const reg = await navigator.serviceWorker.ready;

        if (!enabled) {
            const sub = await reg.pushManager.getSubscription();
            if (sub) {
                const fd = new FormData();
                fd.append('endpoint', sub.endpoint);
                fd.append('csrf_token', CSRF_TOKEN);
                // On previent le serveur AVANT de resilier cote navigateur : une fois
                // l'abonnement supprime, son endpoint n'est plus lisible.
                await fetch('api.php?action=push_unsubscribe', { method: 'POST', body: fd });
                await sub.unsubscribe();
            }
            store.pushEnabled = false;
            return;
        }

        // La demande de permission n'arrive QUE maintenant : elle est declenchee par
        // un clic, ce que les navigateurs exigent et ce qui donne a la personne le
        // contexte pour repondre.
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            store.pushEnabled = false;
            store.showToast(T('push_denied'), 'info');
            return;
        }

        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(pushPublicKey),
        });
        const fd = new FormData();
        fd.append('endpoint', sub.endpoint);
        fd.append('csrf_token', CSRF_TOKEN);
        const res = await fetch('api.php?action=push_subscribe', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status !== 'success') { store.showToast(data.message || T('err_action_failed'), 'error'); return; }

        store.pushEnabled = true;
        store.showToast(T('push_enabled'), 'success');
    } catch (e) {
        store.pushEnabled = false;
        store.showToast(T('err_action_failed'), 'error');
    }
}

// --- Suivi d'artiste ---------------------------------------------------------

let FOLLOWED_ARTISTS = new Set();

async function loadFollowedArtists() {
    try {
        const res = await fetch('api.php?action=my_follows');
        const data = await res.json();
        if (data && data.status === 'success') FOLLOWED_ARTISTS = new Set(data.artists.map(a => a.toLowerCase()));
    } catch (e) { /* liste vide : le bouton s'affichera en "suivre" */ }
    refreshFollowButton();
}

function isFollowingArtist(name) {
    return FOLLOWED_ARTISTS.has(String(name || '').toLowerCase());
}

// Le bouton vit sur la page Artiste, qui est rendue en JS : on le remet a jour
// apres chaque changement plutot que de le lier a un etat reactif, la page
// n'utilisant pas Alpine pour son contenu.
function refreshFollowButton() {
    const btn = document.getElementById('artist-follow-btn');
    if (!btn || typeof currentArtistName !== 'string') return;
    const following = isFollowingArtist(currentArtistName);
    btn.classList.toggle('is-following', following);
    const label = btn.querySelector('.follow-label');
    if (label) label.textContent = following ? T('artist_following') : T('artist_follow');
    const use = btn.querySelector('use');
    if (use) use.setAttribute('href', following ? '#ico-check' : '#ico-plus');
}

async function toggleFollowArtist() {
    if (typeof currentArtistName !== 'string' || !currentArtistName) return;
    const following = isFollowingArtist(currentArtistName);
    const fd = new FormData();
    fd.append('artist', currentArtistName);
    fd.append('csrf_token', CSRF_TOKEN);
    try {
        const res = await fetch('api.php?action=' + (following ? 'unfollow_artist' : 'follow_artist'), { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return; }

        if (data.following) FOLLOWED_ARTISTS.add(currentArtistName.toLowerCase());
        else FOLLOWED_ARTISTS.delete(currentArtistName.toLowerCase());
        refreshFollowButton();

        // On ne propose les notifications qu'au premier artiste suivi : c'est le
        // moment ou elles ont un sens. Le proposer avant serait une demande de
        // permission sans objet.
        if (data.following && pushAvailable && !Alpine.store('ui').pushEnabled) {
            Alpine.store('ui').showToast(T('artist_follow_hint'), 'info');
        }
    } catch (e) {
        Alpine.store('ui').showToast(T('err_action_failed'), 'error');
    }
}
