// =============================================================================
// GÉNÉRATION D'UNE PLAYLIST PAR FILTRE
// =============================================================================
// « Tout le rock ajouté ce mois-ci », en une action au lieu d'une recherche
// suivie de trente clics d'ajout.
//
// Le filtrage est fait par le SERVEUR (api.php?action=playlist_generate), jamais
// ici : c'est la seule façon de n'écrire les règles qu'une fois. Un filtrage
// côté client aurait recréé un second jeu de règles à garder aligné — le piège
// déjà rencontré avec le découpage des genres, implémenté des deux côtés.
//
// Ce composant ne fait donc que deux choses : présenter les critères, et
// demander au serveur combien de morceaux ils désignent AVANT de créer quoi que
// ce soit. Créer puis constater serait le comportement à éviter : une playlist
// vide, ou de trois mille titres, est désagréable à défaire.
document.addEventListener('alpine:init', () => {
    Alpine.data('playlistGenerateForm', () => ({
        name: '',
        genre: '',
        artist: '',
        days: '0',
        minPlays: '0',
        likedOnly: false,
        sort: 'recent',
        limit: '100',
        isPrivate: false,

        count: null,      // null = pas encore d'aperçu
        previewing: false,
        creating: false,
        _timer: null,

        init() {
            this.preview();
        },

        // Genres proposés en autocomplétion, dérivés de la bibliothèque déjà
        // chargée. Purement indicatif : le champ reste libre, et c'est le serveur
        // qui décide de ce qui correspond.
        get genreSuggestions() {
            if (typeof ALL_MUSIC_DATA === 'undefined') return [];
            const set = new Set();
            ALL_MUSIC_DATA.forEach(t => trackGenres(t).forEach(g => set.add(g)));
            return [...set].sort((a, b) => a.localeCompare(b));
        },

        formData(dryRun) {
            const fd = new FormData();
            if (dryRun) fd.append('dry_run', '1');
            else {
                fd.append('name', this.name.trim());
                if (this.isPrivate) fd.append('is_private', '1');
            }
            if (this.genre.trim()) fd.append('genre', this.genre.trim());
            if (this.artist.trim()) fd.append('artist', this.artist.trim());
            if (this.days !== '0') fd.append('days', this.days);
            if (this.minPlays !== '0') fd.append('min_plays', this.minPlays);
            if (this.likedOnly) fd.append('liked_only', '1');
            fd.append('sort', this.sort);
            fd.append('limit', this.limit);
            fd.append('csrf_token', CSRF_TOKEN);
            return fd;
        },

        // Débouncé : chaque frappe dans le champ genre déclencherait sinon une
        // requête, pour un chiffre qui ne sert qu'à décider.
        schedulePreview() {
            clearTimeout(this._timer);
            this._timer = setTimeout(() => this.preview(), 350);
        },

        async preview() {
            this.previewing = true;
            try {
                const res = await fetch('api.php?action=playlist_generate', { method: 'POST', body: this.formData(true) });
                const data = await res.json();
                this.count = (data && data.status === 'success') ? data.count : null;
            } catch (e) {
                this.count = null;
            } finally {
                this.previewing = false;
            }
        },

        async create() {
            if (!this.name.trim()) { Alpine.store('ui').showToast(T('playlist_gen_need_name'), 'error'); return; }
            if (this.creating) return;
            this.creating = true;
            try {
                const res = await fetch('api.php?action=playlist_generate', { method: 'POST', body: this.formData(false) });
                const data = await res.json();
                if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return; }
                Alpine.store('ui').closeModal('playlistGenerateModal');
                Alpine.store('ui').showToast(T('playlist_gen_created', { n: data.count }), 'success');
                // Rechargement complet : la liste des playlists est rendue côté PHP au
                // chargement de la page, il n'existe pas de chemin pour y insérer une
                // nouvelle carte sans la reconstruire.
                setTimeout(() => window.location.reload(), 700);
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
            } finally {
                this.creating = false;
            }
        },
    }));
});

function openPlaylistGenerate() {
    openModal('playlistGenerateModal');
}
