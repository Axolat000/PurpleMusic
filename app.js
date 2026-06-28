/* =====================================================
   PURPLE MUSIC — app.js
   ===================================================== */

// ── DOM refs ──────────────────────────────────────────
const audio        = document.getElementById('mainAudio');
const masterPlay   = document.getElementById('masterPlay');
const fpMasterPlay = document.getElementById('fp-masterPlay');
const progressBg   = document.getElementById('progress-area');
const progressFill = document.getElementById('progress-bar');
const playTitle    = document.getElementById('play-title');
const playStatus   = document.getElementById('play-status');
const playCover    = document.getElementById('player-cover');
const queueList    = document.getElementById('queue-list');
const queuePanel   = document.getElementById('queue-panel');
const desktopVol   = document.getElementById('desktop-vol');
const mobileVol    = document.getElementById('mobile-vol');

// ── State ─────────────────────────────────────────────
let CURRENT_VIEW_DATA = [];
let renderedCount     = 0;
const RENDER_CHUNK    = 30;
let originalQueue     = [];
let queue             = [];
let currentIndex      = 0;
let loopMode          = 0;  // 0=off 1=all 2=one
let isShuffle         = false;
let currentPlaylistId = null;
let currentSection    = 'accueil';
let hiddenGenres      = JSON.parse(localStorage.getItem('hiddenGenres') || '[]');

// ── LRC State ─────────────────────────────────────────
let lrcLines     = [];   // [{time: seconds, text: string}]
let lrcByTrackId = {};   // { trackId: [{time, text}] }
let activeLyricIdx = -1;

// ── Icons ─────────────────────────────────────────────
const ICON_PLAY  = `<svg viewBox="0 0 24 24" style="margin-left:2px;"><path d="M8 5v14l11-7z"/></svg>`;
const ICON_PAUSE = `<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>`;
const FP_ICON_PLAY  = `<svg viewBox="0 0 24 24" style="width:28px;height:28px;fill:black;margin-left:3px;"><path d="M8 5v14l11-7z"/></svg>`;
const FP_ICON_PAUSE = `<svg viewBox="0 0 24 24" style="width:28px;height:28px;fill:black;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>`;

// ── Helpers ───────────────────────────────────────────
function escapeHTML(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function formatTime(s) {
    if (!isFinite(s) || isNaN(s)) return '0:00';
    const m = Math.floor(s / 60), sec = Math.floor(s % 60);
    return `${m}:${sec < 10 ? '0' : ''}${sec}`;
}
function shuffleArray(arr) {
    const a = [...arr];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

// ── Volume ────────────────────────────────────────────
function updateVolume(val) {
    if (!audio) return;
    val = parseFloat(val);
    audio.volume = val;
    if (desktopVol) { desktopVol.value = val; desktopVol.style.background = `linear-gradient(90deg, var(--accent) ${val*100}%, rgba(255,255,255,0.15) ${val*100}%)`; }
    if (mobileVol) { mobileVol.value = val; mobileVol.style.background = `linear-gradient(90deg, rgba(255,255,255,0.8) ${val*100}%, rgba(255,255,255,0.2) ${val*100}%)`; }
    localStorage.setItem('purpleMusicVolume', val);
}
if (desktopVol) desktopVol.addEventListener('input', e => updateVolume(e.target.value));
if (mobileVol)  mobileVol.addEventListener('input',  e => updateVolume(e.target.value));

// ── Search / Filter / Sort ────────────────────────────
let _searchTimer;
function onSearchInput() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(filterAndSortTracks, 220);
}

function filterAndSortTracks() {
    const si = document.getElementById('searchInput');
    const ss = document.getElementById('sortSelect');
    if (!si || !ss) return;
    const term = si.value.toLowerCase();
    const sort = ss.value;

    let data = ALL_MUSIC_DATA.filter(t => {
        if (hiddenGenres.includes(t.genre || 'Autre')) return false;
        return t.title.toLowerCase().includes(term) || t.artist.toLowerCase().includes(term);
    });

    data.sort((a, b) => {
        switch (sort) {
            case 'popular':   return (b.play_count||0) - (a.play_count||0) || b.id - a.id;
            case 'date_desc': return b.id - a.id;
            case 'date_asc':  return a.id - b.id;
            case 'alpha_asc': return a.title.localeCompare(b.title);
            case 'alpha_desc':return b.title.localeCompare(a.title);
            case 'artist':    return a.artist.localeCompare(b.artist);
            default: return 0;
        }
    });

    CURRENT_VIEW_DATA = data;
    renderedCount = 0;
    renderTracksChunk();
}

// ── Track rendering ───────────────────────────────────
function renderTracksChunk() {
    const container = document.getElementById('global-list');
    if (!container) return;
    const chunk = CURRENT_VIEW_DATA.slice(renderedCount, renderedCount + RENDER_CHUNK);
    if (renderedCount === 0) {
        container.innerHTML = '';
        if (chunk.length === 0) {
            container.innerHTML = '<div class="load-spinner">Aucune piste trouvée.</div>';
            return;
        }
    }
    const frag = document.createDocumentFragment();
    chunk.forEach((t, i) => {
        const idx = renderedCount + i + 1;
        const isPlaying = queue[currentIndex] && queue[currentIndex].id == t.id && !audio.paused;
        const safeTitle  = escapeHTML(t.title);
        const safeArtist = escapeHTML(t.artist);
        const safeGenre  = escapeHTML(t.genre || 'Autre');
        const safeCover  = escapeHTML(t.cover);
        const jsSafe = s => safeTitle.replace(/'/g,"\\'"); // used for onclick args
        let editBtns = '';
        if (t.uploader_id == CURRENT_USER_ID || IS_ADMIN) {
            editBtns = `
                <button class="btn btn-ghost btn-icon" title="Modifier" onclick="openEditTrackModal(${t.id},'${escapeHTML(t.title).replace(/'/g,"\\'")}','${escapeHTML(t.artist).replace(/'/g,"\\'")}','${escapeHTML(t.genre||'Autre').replace(/'/g,"\\'")}')">
                    <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 000-1.41l-2.34-2.34a1 1 0 00-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                </button>
                <a href="?delete_track=${t.id}&csrf_token=${CSRF_TOKEN}" class="btn btn-danger" style="border-radius:99px;padding:6px 10px;" onclick="return confirm('Supprimer cette piste ?')">
                    <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:currentColor;"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                </a>`;
        }
        const div = document.createElement('div');
        div.className = 'track-item' + (isPlaying ? ' playing' : '');
        div.dataset.id = t.id;
        div.innerHTML = `
            <div class="track-num">
                <span class="track-num-val">${idx}</span>
                <span class="play-indicator">
                    <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                </span>
            </div>
            <img src="covers/${safeCover}" loading="lazy" class="mini-cover" onerror="this.src='covers/default.png'" onclick="playTrackById(${t.id})">
            <div class="track-meta" onclick="playTrackById(${t.id})">
                <div class="track-title">${safeTitle}</div>
                <div class="track-info-line">
                    <span>${safeArtist}</span>
                    <span class="track-info-dot">•</span>
                    <span>${safeGenre}</span>
                    <span class="track-info-dot">•</span>
                    <span>▶ ${t.play_count||0}</span>
                </div>
            </div>
            <div class="track-actions">${editBtns}</div>
        `;
        frag.appendChild(div);
    });
    container.appendChild(frag);
    renderedCount += chunk.length;
}

const _ioObserver = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && renderedCount < CURRENT_VIEW_DATA.length) {
        renderTracksChunk();
    }
}, { rootMargin: '200px' });

// ── Section navigation ────────────────────────────────
function showSection(id, pushUrl = true) {
    document.querySelectorAll('.page-section').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.sidebar-item[data-section]').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.mob-nav-item[data-section]').forEach(el => el.classList.remove('active'));

    const sec = document.getElementById('section-' + id);
    if (sec) sec.classList.add('active');
    const si = document.querySelector(`.sidebar-item[data-section="${id}"]`);
    if (si) si.classList.add('active');
    const mi = document.querySelector(`.mob-nav-item[data-section="${id}"]`);
    if (mi) mi.classList.add('active');

    currentSection = id;
    window.scrollTo(0, 0);
    document.getElementById('main-content')?.scrollTo(0, 0);

    if (pushUrl) {
        const p = new URLSearchParams(window.location.search);
        if (id !== 'accueil') p.set('page', id); else p.delete('page');
        window.history.pushState({}, '', window.location.pathname + (p.toString() ? '?' + p : ''));
    }
}

// ── Modal helpers ─────────────────────────────────────
function openModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('open');
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('open');
}

// ── Queue ─────────────────────────────────────────────
function toggleQueue() {
    if (queuePanel) queuePanel.classList.toggle('open');
}

function updateQueueUI() {
    if (!queueList) return;
    queueList.innerHTML = '';
    if (!queue.length) {
        queueList.innerHTML = '<div style="padding:16px;color:var(--text-muted);font-size:0.82rem;">File vide…</div>';
        return;
    }
    queue.forEach((t, i) => {
        const active = i === currentIndex;
        const div = document.createElement('div');
        div.className = 'queue-item' + (active ? ' active' : '');
        div.innerHTML = `
            <img src="covers/${escapeHTML(t.cover)}" loading="lazy" onerror="this.src='covers/default.png'">
            <div class="queue-item-info">
                <div class="queue-item-title">${escapeHTML(t.title)}</div>
                <div class="queue-item-artist">${escapeHTML(t.artist)}</div>
            </div>
            ${active ? '<span class="queue-active-dot">♪</span>' : ''}
        `;
        div.onclick = () => { currentIndex = i; loadTrack(true); };
        queueList.appendChild(div);
    });
    // Scroll active item into view
    const activeItem = queueList.querySelector('.active');
    if (activeItem) activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// ── Playback ──────────────────────────────────────────
function playTrackById(id, autoPlay = true) {
    if (!currentPlaylistId) {
        originalQueue = [...CURRENT_VIEW_DATA];
        queue = isShuffle ? shuffleArray([...originalQueue]) : [...originalQueue];
        currentIndex = queue.findIndex(t => t.id == id);
    } else {
        let idx = queue.findIndex(t => t.id == id);
        if (idx === -1) { currentPlaylistId = null; return playTrackById(id, autoPlay); }
        currentIndex = idx;
    }
    if (currentIndex === -1) currentIndex = 0;
    loadTrack(autoPlay);
}

async function playPlaylist(ids, pId = null) {
    const res = await fetch('?get_playlist_tracks=' + ids);
    let data = await res.json();
    if (hiddenGenres.length) data = data.filter(t => !hiddenGenres.includes(t.genre||'Autre'));
    if (!data.length) { alert('Aucune musique disponible.'); return; }
    currentPlaylistId = pId;
    originalQueue = [...data];
    queue = isShuffle ? shuffleArray([...data]) : [...data];
    currentIndex = 0;
    loadTrack(true);
}

function loadTrack(autoPlay = true) {
    const t = queue[currentIndex];
    if (!t) return;

    audio.src = 'music/' + t.filename;
    fetch('?increment_play=' + t.id).catch(() => {});
    t.play_count = (parseInt(t.play_count) || 0) + 1;
    const gt = typeof ALL_MUSIC_DATA !== 'undefined' && ALL_MUSIC_DATA.find(x => x.id == t.id);
    if (gt) gt.play_count = t.play_count;

    // Update mini bar
    if (playTitle) playTitle.textContent = t.title;
    if (playStatus) playStatus.textContent = t.artist || 'Artiste inconnu';
    if (playCover) playCover.src = 'covers/' + (t.cover || 'default.png');

    // Update full player
    const fpCover   = document.getElementById('fp-cover');
    const fpTitle   = document.getElementById('fp-title');
    const fpArtist  = document.getElementById('fp-artist');
    if (fpCover) fpCover.src = 'covers/' + (t.cover || 'default.png');
    if (fpArtist) fpArtist.textContent = t.artist || 'Artiste inconnu';
    if (fpTitle) {
        fpTitle.innerHTML = `<span>${escapeHTML(t.title)}</span>`;
        const span = fpTitle.querySelector('span');
        span.classList.remove('scrolling-active');
        requestAnimationFrame(() => {
            if (span.scrollWidth > fpTitle.clientWidth) span.classList.add('scrolling-active');
        });
    }

    // Update mobile mini player
    const mmpImg   = document.getElementById('mmp-cover');
    const mmpTitle = document.getElementById('mmp-title');
    const mmpArtist= document.getElementById('mmp-artist');
    if (mmpImg)    mmpImg.src = 'covers/' + (t.cover || 'default.png');
    if (mmpTitle)  mmpTitle.textContent = t.title;
    if (mmpArtist) mmpArtist.textContent = t.artist || '';

    // Reset progress
    setProgress(0, 0);

    // Media Session API
    if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = new MediaMetadata({
            title: t.title, artist: t.artist || 'Purple Music',
            artwork: [{ src: 'covers/' + (t.cover || 'default.png'), sizes: '96x96', type: 'image/png' }]
        });
        navigator.mediaSession.setActionHandler('play',         () => togglePlay());
        navigator.mediaSession.setActionHandler('pause',        () => togglePlay());
        navigator.mediaSession.setActionHandler('nexttrack',    () => nextTrack());
        navigator.mediaSession.setActionHandler('previoustrack',() => prevTrack());
    }

    // Track highlighting in list
    document.querySelectorAll('.track-item').forEach(el => el.classList.remove('playing'));
    const el = document.querySelector(`.track-item[data-id="${t.id}"]`);
    if (el) el.classList.add('playing');

    updateQueueUI();
    updateUrl();

    // LRC: load stored lyrics if any
    loadLyricsForTrack(t.id);

    if (autoPlay) {
        audio.play().catch(console.error);
        setPlayState(true);
    } else {
        setPlayState(false);
    }
}

function setPlayState(playing) {
    if (masterPlay)   masterPlay.innerHTML   = playing ? ICON_PAUSE : ICON_PLAY;
    if (fpMasterPlay) fpMasterPlay.innerHTML = playing ? FP_ICON_PAUSE : FP_ICON_PLAY;
    const mmpBtn = document.getElementById('mmp-play-btn');
    if (mmpBtn) mmpBtn.innerHTML = playing
        ? `<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>`
        : `<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>`;
}

function togglePlay() {
    if (!audio.src) return;
    if (audio.paused) { audio.play().catch(console.error); setPlayState(true); }
    else              { audio.pause(); setPlayState(false); }
}
function nextTrack() {
    if (loopMode === 2) { audio.currentTime = 0; audio.play(); return; }
    if (currentIndex < queue.length - 1) { currentIndex++; loadTrack(true); }
    else if (loopMode === 1) { currentIndex = 0; loadTrack(true); }
    else { audio.pause(); audio.currentTime = 0; setPlayState(false); }
}
function prevTrack() {
    if (audio.currentTime > 3) { audio.currentTime = 0; return; }
    if (currentIndex > 0) { currentIndex--; loadTrack(true); }
}
function toggleShuffle() {
    isShuffle = !isShuffle;
    document.querySelectorAll('.shuffle-btn').forEach(b => b.classList.toggle('active', isShuffle));
    if (queue.length > 0) {
        const cur = queue[currentIndex];
        queue = isShuffle ? shuffleArray([...originalQueue]) : [...originalQueue];
        currentIndex = queue.findIndex(t => t.filename === cur.filename);
        if (currentIndex === -1) currentIndex = 0;
        updateQueueUI();
    }
}
function toggleLoop() {
    loopMode = (loopMode + 1) % 3;
    document.querySelectorAll('.loop-btn').forEach(b => {
        b.classList.toggle('active', loopMode > 0);
        const ind = b.querySelector('.loop-status');
        if (ind) { ind.style.display = loopMode === 2 ? 'flex' : 'none'; }
    });
}

// ── Audio events ──────────────────────────────────────
function setProgress(current, total) {
    const pct = total > 0 ? (current / total * 100) : 0;
    if (progressFill) progressFill.style.width = pct + '%';
    const fpFill = document.getElementById('fp-progress-bar');
    if (fpFill) fpFill.style.width = pct + '%';
    const mmpProg = document.getElementById('mmp-progress');
    if (mmpProg) mmpProg.style.width = pct + '%';

    const ct = formatTime(current), tt = formatTime(total);
    const currEl = document.getElementById('curr-time'), totEl = document.getElementById('total-time');
    const fpCurr = document.getElementById('fp-curr-time'), fpTot = document.getElementById('fp-total-time');
    if (currEl) currEl.textContent = ct;
    if (totEl)  totEl.textContent  = tt;
    if (fpCurr) fpCurr.textContent = ct;
    if (fpTot)  fpTot.textContent  = tt;
}

if (audio) {
    audio.addEventListener('timeupdate', () => {
        setProgress(audio.currentTime, audio.duration || 0);
        updateActiveLyric(audio.currentTime);
    });
    audio.addEventListener('loadedmetadata', () => setProgress(0, audio.duration || 0));
    audio.addEventListener('ended', nextTrack);
}

// ── Progress click ────────────────────────────────────
function setupProgressClick(bgId) {
    const el = document.getElementById(bgId);
    if (!el) return;
    let dragging = false;
    const seek = e => {
        const rect = el.getBoundingClientRect();
        const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        audio.currentTime = Math.max(0, Math.min(1, x / rect.width)) * (audio.duration || 0);
    };
    el.addEventListener('mousedown',  e => { dragging = true; seek(e); });
    el.addEventListener('touchstart', e => { dragging = true; seek(e); }, { passive: true });
    document.addEventListener('mousemove',  e => { if (dragging) seek(e); });
    document.addEventListener('touchmove',  e => { if (dragging) seek(e); }, { passive: true });
    document.addEventListener('mouseup',    () => { dragging = false; });
    document.addEventListener('touchend',   () => { dragging = false; });
}

// ── Full player (mobile) ──────────────────────────────
function openFullPlayer() {
    const fp = document.getElementById('full-player');
    if (fp) { fp.classList.add('active'); document.body.style.overflow = 'hidden'; }
}
function closeFullPlayer() {
    const fp = document.getElementById('full-player');
    if (fp) { fp.classList.remove('active'); document.body.style.overflow = ''; }
}
function openSmartPlayer() {
    if (window.innerWidth <= 768) openFullPlayer();
    else toggleQueue();
}

// ── Lyrics ────────────────────────────────────────────
function openLyricsPanel() {
    const lp = document.getElementById('lyrics-panel');
    if (lp) lp.classList.add('open');
    // sync album art
    const la = document.getElementById('lyrics-album-art');
    if (la && playCover) la.src = playCover.src;
    renderLyrics();
}
function closeLyricsPanel() {
    const lp = document.getElementById('lyrics-panel');
    if (lp) lp.classList.remove('open');
}

// Parse LRC format: [mm:ss.xx] or [mm:ss]
function parseLRC(text) {
    const lines = [];
    const re = /\[(\d{1,2}):(\d{2})(?:[.:](\d{1,3}))?\]\s*(.*)/g;
    let m;
    while ((m = re.exec(text)) !== null) {
        const min = parseInt(m[1]), sec = parseInt(m[2]);
        const ms  = m[3] ? parseInt(m[3].padEnd(3, '0')) : 0;
        const time = min * 60 + sec + ms / 1000;
        const txt  = m[4].trim();
        if (txt) lines.push({ time, text: txt });
    }
    lines.sort((a, b) => a.time - b.time);
    return lines;
}

function loadLyricsForTrack(id) {
    lrcLines = lrcByTrackId[id] || [];
    activeLyricIdx = -1;
    const lp = document.getElementById('lyrics-panel');
    if (lp && lp.classList.contains('open')) renderLyrics();
}

function renderLyrics() {
    const scroll = document.getElementById('lyrics-scroll');
    if (!scroll) return;
    if (!lrcLines.length) {
        scroll.innerHTML = `
            <div class="lyrics-empty">
                <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
                <p>Aucune parole disponible pour ce titre.</p>
                <div class="lrc-hint">
                    <strong>Ajouter des paroles</strong><br>
                    Glissez un fichier <code>.lrc</code> ici ou cliquez pour choisir un fichier.
                    Les fichiers LRC utilisent le format <code>[mm:ss.xx]&nbsp;Parole…</code>
                </div>
                <div class="lrc-drop-zone" id="lrc-drop-zone" onclick="document.getElementById('lrc-file-input').click()">
                    <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    <p>Cliquer ou glisser un fichier .lrc</p>
                </div>
                <input type="file" id="lrc-file-input" accept=".lrc,.txt" style="display:none">
            </div>`;
        setupLrcDropZone();
    } else {
        scroll.innerHTML = '';
        lrcLines.forEach((line, i) => {
            const div = document.createElement('div');
            div.className = 'lyric-line';
            div.dataset.idx = i;
            div.textContent = line.text;
            div.onclick = () => { audio.currentTime = line.time; if (audio.paused) audio.play().catch(()=>{}); };
            scroll.appendChild(div);
        });
        updateActiveLyric(audio.currentTime || 0);
    }
    // sync album art
    const la = document.getElementById('lyrics-album-art');
    const pCover = document.getElementById('player-cover');
    if (la && pCover) la.src = pCover.src;
    // sync mini info
    const lt = document.getElementById('lyrics-mini-title');
    const la2 = document.getElementById('lyrics-mini-artist');
    const li  = document.getElementById('lyrics-mini-img');
    const t = queue[currentIndex];
    if (t) {
        if (lt) lt.textContent = t.title;
        if (la2) la2.textContent = t.artist || '';
        if (li) li.src = 'covers/' + (t.cover || 'default.png');
    }
}

function setupLrcDropZone() {
    const dz  = document.getElementById('lrc-drop-zone');
    const inp = document.getElementById('lrc-file-input');
    if (!dz || !inp) return;
    const handleFile = file => {
        const r = new FileReader();
        r.onload = e => {
            const t = queue[currentIndex];
            if (!t) return;
            lrcLines = parseLRC(e.target.result);
            lrcByTrackId[t.id] = lrcLines;
            renderLyrics();
        };
        r.readAsText(file, 'utf-8');
    };
    inp.onchange = e => { if (e.target.files[0]) handleFile(e.target.files[0]); };
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave',() => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => {
        e.preventDefault(); dz.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file && (file.name.endsWith('.lrc') || file.name.endsWith('.txt'))) handleFile(file);
    });
}

function updateActiveLyric(currentTime) {
    if (!lrcLines.length) return;
    const lp = document.getElementById('lyrics-panel');
    if (!lp || !lp.classList.contains('open')) return;

    // Binary search for active line
    let idx = -1;
    for (let i = 0; i < lrcLines.length; i++) {
        if (lrcLines[i].time <= currentTime) idx = i;
        else break;
    }
    if (idx === activeLyricIdx) return;
    activeLyricIdx = idx;

    const scroll = document.getElementById('lyrics-scroll');
    if (!scroll) return;
    const lines = scroll.querySelectorAll('.lyric-line');
    lines.forEach((el, i) => {
        el.classList.toggle('active', i === idx);
        el.classList.toggle('past',   i < idx);
    });
    // Auto-scroll
    if (idx >= 0 && lines[idx]) {
        lines[idx].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// ── Genre settings ────────────────────────────────────
function toggleGenreSetting(genre, checked) {
    if (checked) { if (!hiddenGenres.includes(genre)) hiddenGenres.push(genre); }
    else         { hiddenGenres = hiddenGenres.filter(g => g !== genre); }
    localStorage.setItem('hiddenGenres', JSON.stringify(hiddenGenres));
    filterAndSortTracks();
}

// ── URL state ─────────────────────────────────────────
function updateUrl() {
    const p = new URLSearchParams();
    if (currentSection !== 'accueil') p.set('page', currentSection);
    const t = queue[currentIndex];
    if (t) p.set('v', t.id);
    if (currentPlaylistId) p.set('list', currentPlaylistId);
    const url = window.location.pathname + (p.toString() ? '?' + p : '');
    window.history.pushState({}, '', url);
}
window.addEventListener('popstate', () => window.location.reload());

// ── Modal helpers ─────────────────────────────────────
function openEditTrackModal(id, title, artist, genre) {
    document.getElementById('edit-track-id').value    = id;
    document.getElementById('edit-track-title').value  = title;
    document.getElementById('edit-track-artist').value = artist;
    const gs = document.getElementById('edit-track-genre');
    if (gs) gs.value = genre;
    openModal('editTrackModal');
}

function filterPlaylistTracks() {
    const term = document.getElementById('playlist-search').value.toLowerCase();
    document.querySelectorAll('.song-select-item').forEach(el => {
        el.style.display = el.dataset.title.includes(term) ? 'flex' : 'none';
    });
}
function toggleSelection(div) {
    const cb = div.querySelector('input');
    cb.checked = !cb.checked;
    div.classList.toggle('selected', cb.checked);
    updateSelectedCount();
}
function updateSelectedCount() {
    const n = document.querySelectorAll('.song-cb:checked').length;
    const el = document.getElementById('selected-count');
    if (el) el.textContent = `${n} sélectionné(s)`;
}

function openCreateModal() {
    document.getElementById('modal-playlist-title').textContent = 'Nouvelle Playlist';
    document.getElementById('form-playlist-id').value = '';
    document.getElementById('form-playlist-name').value = '';
    const ps = document.getElementById('playlist-search');
    if (ps) ps.value = '';
    document.querySelectorAll('.song-select-item').forEach(el => {
        el.classList.remove('selected'); el.style.display = 'flex';
    });
    document.querySelectorAll('.song-cb').forEach(cb => cb.checked = false);
    updateSelectedCount();
    openModal('playlistModal');
}
function openEditModal(p) {
    document.getElementById('modal-playlist-title').textContent = 'Modifier le Mix';
    document.getElementById('form-playlist-id').value = p.id;
    document.getElementById('form-playlist-name').value = p.name;
    const ids = String(p.song_ids).split(',');
    document.querySelectorAll('.song-select-item').forEach(el => {
        const cb = el.querySelector('.song-cb');
        cb.checked = ids.includes(cb.dataset.id);
        el.classList.toggle('selected', cb.checked);
        el.style.display = 'flex';
    });
    updateSelectedCount();
    openModal('playlistModal');
}

// ── Accordion ─────────────────────────────────────────
function toggleAccordion(header) {
    const item = header.parentElement;
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.adm-accordion-item').forEach(el => {
        el.classList.remove('open');
        const c = el.querySelector('.adm-accordion-content');
        if (c) c.style.display = 'none';
    });
    if (!wasOpen) {
        item.classList.add('open');
        const c = item.querySelector('.adm-accordion-content');
        if (c) c.style.display = 'block';
    }
}

// ── DOMContentLoaded ──────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (typeof ALL_MUSIC_DATA === 'undefined') return;

    // Volume
    const savedVol = localStorage.getItem('purpleMusicVolume');
    updateVolume(savedVol !== null ? savedVol : 1);

    // Genre checkboxes
    document.querySelectorAll('.genre-filter-cb').forEach(cb => {
        if (hiddenGenres.includes(cb.dataset.genre)) cb.checked = true;
    });

    // IntersectionObserver for infinite scroll
    const trigger = document.getElementById('load-more-trigger');
    if (trigger) _ioObserver.observe(trigger);

    filterAndSortTracks();

    // Progress seek
    setupProgressClick('progress-area');
    setupProgressClick('fp-progress-area');

    // URL restore
    const p = new URLSearchParams(window.location.search);
    const page = p.get('page');
    const vid  = p.get('v');
    const list = p.get('list');
    if (page) showSection(page, false);
    if (list) currentPlaylistId = list;
    if (vid)  playTrackById(vid, false);

    // Close modals on backdrop click
    document.querySelectorAll('.modal').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
    });

    // Global drag-and-drop for LRC on lyrics panel
    const lp = document.getElementById('lyrics-panel');
    if (lp) {
        lp.addEventListener('dragover', e => { e.preventDefault(); });
        lp.addEventListener('drop', e => {
            e.preventDefault();
            const file = e.dataTransfer.files[0];
            if (file && (file.name.endsWith('.lrc') || file.name.endsWith('.txt'))) {
                const r = new FileReader();
                r.onload = ev => {
                    const t = queue[currentIndex];
                    if (!t) return;
                    lrcLines = parseLRC(ev.target.result);
                    lrcByTrackId[t.id] = lrcLines;
                    renderLyrics();
                };
                r.readAsText(file, 'utf-8');
            }
        });
    }
});
