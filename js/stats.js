// =============================================================================
// PAGE STATISTIQUES
// =============================================================================
// Restitue à l'utilisateur ce que la table listen_events sait déjà de son écoute
// (elle n'alimentait jusqu'ici que les recommandations, invisible pour lui).
//
// Tout le rendu est en JS : les données arrivent d'un appel réseau, il n'y a rien
// à pré-rendre côté PHP.

let _statsToken = 0;

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
            <span class="rank-count tabular">${escapeHTML(T('stats_plays_count', { n: item.plays }))}</span>
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

    let data;
    try {
        const res = await fetch('api.php?action=stats&days=' + encodeURIComponent(days));
        data = await res.json();
    } catch (e) {
        if (myToken !== _statsToken) return;
        body.innerHTML = emptyStateHTML('ico-stats', T('err_action_failed'), '');
        return;
    }
    if (myToken !== _statsToken) return;

    if (!data || data.status !== 'success' || !data.totals || data.totals.plays === 0) {
        body.innerHTML = emptyStateHTML('ico-stats', T('stats_empty_title'), T('stats_empty_hint'));
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

    body.innerHTML = html;

    // Délégation : une seule écoute pour toutes les lignes, plutôt qu'un
    // gestionnaire par élément re-attaché à chaque changement de période.
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
