function showSection(id, doUpdateUrl = true) {
    if (window.Alpine) Alpine.store('ui').section = id;

    document.querySelectorAll('nav span').forEach(s => s.classList.remove('active'));
    if(document.getElementById('nav-' + id)) document.getElementById('nav-' + id).classList.add('active');

    document.querySelectorAll('.mob-nav-item').forEach(s => s.classList.remove('active'));
    if(document.getElementById('mob-nav-' + id)) document.getElementById('mob-nav-' + id).classList.add('active');

    window.scrollTo(0,0);
    currentSection = id;
    if (doUpdateUrl) updateUrl();

    // Les titres de la page Playlists sont rendus côté PHP au chargement, pendant que la section est
    // encore cachée par x-show (clientWidth = 0 tant qu'elle n'est pas affichée) — le test de dépassement
    // ne peut donc se faire qu'ici, une fois la section réellement visible, pas au chargement de la page.
    if (id === 'playlists' && window.Alpine) {
        // Alpine.nextTick (pas requestAnimationFrame) : x-show retire le display:none sur la section via
        // une micro-tâche Alpine, pas au prochain repaint — mesurer avant que ce changement soit appliqué
        // donnerait clientWidth = 0 partout et ne détecterait jamais de dépassement.
        Alpine.nextTick(() => {
            document.querySelectorAll('#playlists .marquee-wrap').forEach(applyMarqueeIfOverflowing);
        });
    }
}

// Ajoute .scrolling-active (voir style.css, réutilise l'animation déjà en place pour #fp-title) sur le
// <span> interne d'un conteneur .marquee-wrap seulement si le texte dépasse réellement la largeur
// disponible — jamais pour un titre qui tient déjà sur une ligne.
function applyMarqueeIfOverflowing(wrapperEl) {
    const span = wrapperEl.querySelector('span');
    if (!span) return;
    span.classList.remove('scrolling-active');
    if (span.scrollWidth > wrapperEl.clientWidth) span.classList.add('scrolling-active');
}

function openModal(id) {
    if (window.Alpine) Alpine.store('ui').openModal(id);
}
function closeModal(id) {
    if (window.Alpine) Alpine.store('ui').closeModal(id);
}

function openEditTrackModal(id, title, artist, genre) {
    document.getElementById('edit-track-id').value = id;
    document.getElementById('edit-track-title').value = title;
    document.getElementById('edit-track-artist').value = artist;
    const gSelect = document.getElementById('edit-track-genre');
    if (gSelect && genre) gSelect.value = genre;
    openModal('editTrackModal');
}

function filterPlaylistTracks() {
    const term = document.getElementById('playlist-search').value.toLowerCase();
    document.querySelectorAll('.song-select-item').forEach(item => {
        item.style.display = item.dataset.title.includes(term) ? 'flex' : 'none';
    });
}

function toggleSelection(div) {
    const cb = div.querySelector('input');
    cb.checked = !cb.checked;
    cb.checked ? div.classList.add('selected') : div.classList.remove('selected');
    updateSelectedCount();
}
function updateSelectedCount() {
    const count = document.querySelectorAll('.song-cb:checked').length;
    const el = document.getElementById('selected-count');
    if (el) el.innerText = T('selected_count', { n: count });
}

function openCreateModal() {
    const title = document.getElementById('modal-playlist-title');
    if (title) title.innerText = T('playlist_new_title');
    document.getElementById('form-playlist-id').value = "";
    document.getElementById('form-playlist-name').value = "";
    const pPrivateNew = document.getElementById('form-playlist-private');
    if (pPrivateNew) pPrivateNew.checked = false;
    const pSearch = document.getElementById('playlist-search');
    if (pSearch) pSearch.value = "";
    resetPlaylistCoverPreview();

    document.querySelectorAll('.song-select-item').forEach(div => {
        div.classList.remove('selected');
        div.style.display = 'flex';
    });
    document.querySelectorAll('.song-cb').forEach(cb => cb.checked = false);
    updateSelectedCount();
    openModal('playlistModal');
}

function openEditModal(p) {
    const title = document.getElementById('modal-playlist-title');
    if (title) title.innerText = T('playlist_edit_title');
    document.getElementById('form-playlist-id').value = p.id;
    document.getElementById('form-playlist-name').value = p.name;
    const pPrivateEdit = document.getElementById('form-playlist-private');
    if (pPrivateEdit) pPrivateEdit.checked = !!(parseInt(p.is_private) || 0);
    const pSearch = document.getElementById('playlist-search');
    if (pSearch) pSearch.value = "";
    setPlaylistCoverPreview(p.cover);

    const ids = String(p.song_ids).split(',');
    document.querySelectorAll('.song-select-item').forEach(div => {
        const cb = div.querySelector('.song-cb');
        cb.checked = ids.includes(cb.dataset.id);
        cb.checked ? div.classList.add('selected') : div.classList.remove('selected');
        div.style.display = 'flex';
    });
    updateSelectedCount();
    openModal('playlistModal');
}

// --- Aperçu en direct de la cover de playlist (modale création/édition) ---
function resetPlaylistCoverPreview() {
    const fileInput = document.getElementById('form-playlist-cover');
    if (fileInput) fileInput.value = '';
    const img = document.getElementById('playlist-cover-preview-img');
    if (img) { img.removeAttribute('src'); img.style.display = 'none'; }
}

function setPlaylistCoverPreview(cover) {
    const fileInput = document.getElementById('form-playlist-cover');
    if (fileInput) fileInput.value = '';
    const img = document.getElementById('playlist-cover-preview-img');
    if (!img) return;
    if (cover) { img.src = 'covers/' + cover; img.style.display = 'block'; }
    else { img.removeAttribute('src'); img.style.display = 'none'; }
}

function previewPlaylistCoverFile(input) {
    const img = document.getElementById('playlist-cover-preview-img');
    if (!img) return;
    const file = input.files && input.files[0];
    if (file) { img.src = URL.createObjectURL(file); img.style.display = 'block'; }
    else { img.removeAttribute('src'); img.style.display = 'none'; }
}
