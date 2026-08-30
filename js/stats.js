// =============================================================================
// PAGE STATISTIQUES
// =============================================================================
// Restitue à l'utilisateur ce que la table listen_events sait déjà de son écoute
// (elle n'alimentait jusqu'ici que les recommandations, invisible pour lui).
//
// Tout le rendu est en JS : les données arrivent d'un appel réseau, il n'y a rien
// à pré-rendre côté PHP.

let _statsToken = 0;

// Classement du serveur : fenêtre fixe de 7 jours, donc indépendant de la période
// choisie pour les statistiques personnelles. Mis en cache pour ne pas repartir en
// requête à chaque changement de période, qui ne le concerne pas.
let _serverTopCache = null;

async function fetchServerTop() {
    if (_serverTopCache) return _serverTopCache;
    try {
        const res = await fetch('api.php?action=server_top');
        const data = await res.json();
        _serverTopCache = (data && data.status === 'success') ? data.tracks : [];
    } catch (e) {
        _serverTopCache = [];
    }
    return _serverTopCache;
}

// Rendu séparé : cette section s'affiche MÊME quand l'utilisateur n'a encore rien
// écouté. C'est justement là qu'elle est la plus utile — un compte tout neuf voit
// ce qui tourne sur le serveur au lieu d'un écran vide.
function serverTopSectionHTML(tracks) {
    if (!tracks || !tracks.length) return '';
    return `
        <section class="stats-section">
            <h3 class="home-row-title">${escapeHTML(T('stats_server_top'))}
                <span class="search-count">${escapeHTML(T('stats_server_top_sub'))}</span></h3>
            <div class="rank-list">${renderRankedList(tracks, {
                kind: 'track',
                value: it => it.id,
                title: it => it.title,
                sub: it => it.artist,
                cover: it => it.cover,
                // Le nombre de comptes distincts dit quelque chose que le nombre de
                // lectures ne dit pas : un titre joué 40 fois par une seule personne
                // n'est pas un titre du serveur, c'est une obsession personnelle.
                count: it => T('stats_plays_count', { n: it.plays }) + ' · ' + T('stats_listeners_count', { n: it.listeners }),
            })}</div>
        </section>`;
}

function formatListeningTime(totalSeconds) {
    const s = Math.max(0, parseInt(totalSeconds, 10) || 0);
    const hours = Math.floor(s / 3600);
    const minutes = Math.round((s % 3600) / 60);
    // Sous une heure, afficher "0 h 42 min" ferait passer le zéro pour l'info
    // principale : on ne montre alors que les minutes.
    if (hours === 0) return T('stats_minutes_short', { n: minutes });
    return T('stats_hours_short', { n: hours }) + ' ' + T('stats_minutes_short', { n: minutes });
}

function statTile(label, value) {
    return `
        <div class="stat-tile">
            <div class="stat-tile-value">${escapeHTML(value)}</div>
            <div class="stat-tile-label">${escapeHTML(label)}</div>
        </div>`;
}

// Histogramme des écoutes par heure. En barres CSS et non en <canvas> : le
// graphique suit alors automatiquement le thème (les barres utilisent les mêmes
// variables que le reste), et reste lisible au zoom navigateur.
function renderHourChart(byHour) {
    const max = Math.max(...byHour, 1);
    const bars = byHour.map((count, hour) => {
        // Hauteur minimale de 2% pour les heures à zéro : une barre de hauteur
        // nulle laisse un trou dans l'axe et casse la lecture du rythme.
        const pct = count === 0 ? 2 : Math.max(6, Math.round((count / max) * 100));
        const dimmed = count === 0 ? ' is-empty' : '';
        return `
            <div class="hour-bar-col" title="${hour}h — ${T('stats_plays_count', { n: count })}">
                <div class="hour-bar${dimmed}" style="height:${pct}%"></div>
                <span class="hour-bar-label">${hour % 6 === 0 ? hour + 'h' : ''}</span>
            </div>`;
    }).join('');
    return `<div class="hour-chart" role="img" aria-label="${escapeHTML(T('stats_by_hour'))}">${bars}</div>`;
}

function renderRankedList(items, opts) {
    return items.map((item, i) => `
        <button type="button" class="rank-row" data-kind="${opts.kind}" data-value="${escapeHTML(String(opts.value(item)))}">
            <span class="rank-index tabular">${i + 1}</span>
            ${opts.cover ? `<img src="covers/${escapeHTML(opts.cover(item) || 'default.png')}" loading="lazy" alt="" class="rank-cover" onerror="this.src='covers/default.png'">` : ''}
            <span class="rank-body">
                <span class="rank-title">${escapeHTML(opts.title(item))}</span>
                ${opts.sub ? `<span class="rank-sub">${escapeHTML(opts.sub(item))}</span>` : ''}
            </span>
            <span class="rank-count tabular">${escapeHTML(opts.count ? opts.count(item) : T('stats_plays_count', { n: item.plays }))}</span>
        </button>`).join('');
}

async function loadStats(days) {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    const body = document.getElementById('stats-body');
    if (!body) return;

    store.statsRange = days;
    // Jeton anti-course : cliquer rapidement sur plusieurs périodes pouvait
    // laisser la réponse la plus lente écraser l'affichage de la plus récente.
    const myToken = ++_statsToken;

    body.innerHTML = `
        <div class="stat-grid">
            ${'<div class="skeleton" style="height:88px; border-radius:var(--radius-lg);"></div>'.repeat(4)}
        </div>
        <div class="skeleton" style="height:200px; border-radius:var(--radius-lg); margin-top:var(--space-8);"></div>`;

    // Les deux requêtes partent ensemble : le classement du serveur ne dépend pas
    // de la période choisie, l'enchaîner ferait attendre l'affichage pour rien.
    let data, serverTop, publicProfiles;
    try {
        const [res, top, profiles] = await Promise.all([
            fetch('api.php?action=stats&days=' + encodeURIComponent(days)),
            fetchServerTop(),
            fetchPublicProfiles(),
        ]);
        data = await res.json();
        serverTop = top;
        publicProfiles = profiles;
    } catch (e) {
        if (myToken !== _statsToken) return;
        body.innerHTML = emptyStateHTML('ico-stats', T('err_action_failed'), '');
        return;
    }
    if (myToken !== _statsToken) return;

    if (!data || data.status !== 'success' || !data.totals || data.totals.plays === 0) {
        // Pas encore d'écoute personnelle : on montre quand même le classement du
        // serveur sous le message, plutôt qu'une page entièrement vide.
        body.innerHTML = emptyStateHTML('ico-stats', T('stats_empty_title'), T('stats_empty_hint'))
            + serverTopSectionHTML(serverTop)
            + publicProfilesSectionHTML(publicProfiles);
        attachRankRowHandlers(body);
        return;
    }

    const t = data.totals;
    let html = `
        <div class="stat-grid">
            ${statTile(T('stats_total_time'), formatListeningTime(t.seconds))}
            ${statTile(T('stats_total_plays'), String(t.plays))}
            ${statTile(T('stats_distinct'), String(t.distinct_tracks))}
            ${statTile(T('stats_active_days'), String(t.active_days))}
        </div>

        <section class="stats-section">
            <h3 class="home-row-title">${escapeHTML(T('stats_by_hour'))}</h3>
            ${renderHourChart(data.by_hour || [])}
        </section>`;

    if (data.top_tracks && data.top_tracks.length) {
        html += `
            <section class="stats-section">
                <h3 class="home-row-title">${escapeHTML(T('stats_top_tracks'))}</h3>
                <div class="rank-list">${renderRankedList(data.top_tracks, {
                    kind: 'track',
                    value: it => it.id,
                    title: it => it.title,
                    sub: it => it.artist,
                    cover: it => it.cover,
                })}</div>
            </section>`;
    }

    if (data.top_artists && data.top_artists.length) {
        html += `
            <section class="stats-section">
                <h3 class="home-row-title">${escapeHTML(T('stats_top_artists'))}</h3>
                <div class="rank-list">${renderRankedList(data.top_artists, {
                    kind: 'artist',
                    value: it => it.name,
                    title: it => it.name,
                })}</div>
            </section>`;
    }

    if (data.top_genres && data.top_genres.length) {
        html += `
            <section class="stats-section">
                <h3 class="home-row-title">${escapeHTML(T('stats_top_genres'))}</h3>
                <div class="rank-list">${renderRankedList(data.top_genres, {
                    kind: 'genre',
                    value: it => it.genre,
                    title: it => it.genre,
                })}</div>
            </section>`;
    }

    html += serverTopSectionHTML(serverTop);
    html += publicProfilesSectionHTML(publicProfiles);

    body.innerHTML = html;
    attachRankRowHandlers(body);
}

// Une seule écoute par ligne, posée après chaque rendu — extraite de loadStats()
// parce que le cas "aucune écoute personnelle" rend lui aussi des lignes
// cliquables (celles du classement du serveur).
function attachRankRowHandlers(body) {
    body.querySelectorAll('.profile-card').forEach(card => {
        card.onclick = () => openPublicProfile(card.dataset.profile);
    });
    body.querySelectorAll('.rank-row').forEach(row => {
        row.onclick = () => {
            const kind = row.dataset.kind;
            const value = row.dataset.value;
            if (kind === 'track') playTrackById(value);
            else if (kind === 'artist') showArtistPage(value);
            else if (kind === 'genre') {
                // Un genre n'a pas de page dédiée : on retombe sur l'accueil
                // filtré par ce genre, ce que fait déjà la pastille de genre.
                Alpine.store('ui').setGenreFilter(value);
                showSection('accueil');
            }
        };
    });
}

function showStatsPage(pushState = true) {
    showSection('stats-page', pushState);
    if (window.Alpine) loadStats(Alpine.store('ui').statsRange);
}

// =============================================================================
// PROFILS D'ÉCOUTE PUBLICS
// =============================================================================
// Ce que quelqu'un écoute est une donnée personnelle : un profil n'apparaît ici
// que si son propriétaire l'a explicitement rendu public (réglage dans
// Paramètres > Compte, désactivé par défaut). Le serveur revérifie cette
// visibilité à chaque lecture, pas seulement au moment de dresser la liste —
// quelqu'un qui aurait noté un identifiant pendant que le profil était public
// n'obtient plus rien une fois qu'il est repassé en privé.

let _publicProfilesCache = null;

async function fetchPublicProfiles() {
    if (_publicProfilesCache) return _publicProfilesCache;
    try {
        const res = await fetch('api.php?action=public_profiles');
        const data = await res.json();
        _publicProfilesCache = (data && data.status === 'success') ? data.profiles : [];
    } catch (e) {
        _publicProfilesCache = [];
    }
    return _publicProfilesCache;
}

// Le profil de l'utilisateur courant est retiré de la liste : il a déjà toute la
// page Statistiques pour lui, se voir proposer son propre profil serait un doublon.
function publicProfilesSectionHTML(profiles) {
    const others = (profiles || []).filter(p => String(p.id) !== String(CURRENT_USER_ID));
    if (!others.length) return '';
    return `
        <section class="stats-section">
            <h3 class="home-row-title">${escapeHTML(T('profiles_title'))}
                <span class="search-count">${escapeHTML(T('profiles_sub'))}</span></h3>
            <div class="profile-grid">
                ${others.map(p => `
                    <button type="button" class="profile-card" data-profile="${escapeHTML(String(p.id))}">
                        <span class="profile-avatar" aria-hidden="true">${escapeHTML((p.username || '?').charAt(0).toUpperCase())}</span>
                        <span class="profile-name">${escapeHTML(p.username)}</span>
                        <span class="profile-plays">${escapeHTML(T('stats_plays_count', { n: p.plays }))}</span>
                    </button>`).join('')}
            </div>
        </section>`;
}

async function openPublicProfile(userId) {
    if (!window.Alpine) return;
    const store = Alpine.store('ui');
    store.publicProfile = { loading: true, user: null, totals: null, topTracks: [], topArtists: [] };
    openModal('publicProfileModal');
    try {
        const res = await fetch('api.php?action=public_profile&u=' + encodeURIComponent(userId));
        const data = await res.json();
        if (!data || data.status !== 'success') {
            store.publicProfile = { loading: false, error: data && data.message ? data.message : T('err_action_failed') };
            return;
        }
        store.publicProfile = {
            loading: false,
            user: data.user,
            totals: data.totals,
            topTracks: data.top_tracks || [],
            topArtists: data.top_artists || [],
        };
    } catch (e) {
        store.publicProfile = { loading: false, error: T('err_action_failed') };
    }
}

function setProfilePublic(enabled) {
    const fd = new FormData();
    fd.append('enabled', enabled ? '1' : '0');
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=profile_visibility', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') { Alpine.store('ui').showToast(T('err_action_failed'), 'error'); return; }
            Alpine.store('ui').profilePublic = data.enabled;
            // Le cache de la liste devient faux des qu'on change sa propre visibilité.
            _publicProfilesCache = null;
        })
        .catch(() => Alpine.store('ui').showToast(T('err_action_failed'), 'error'));
}
