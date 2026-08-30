// =============================================================================
// PANEL ADMIN > ALBUMS
// =============================================================================
// Écran d'édition des albums et, surtout, d'édition EN MASSE : rattacher d'un
// coup une sélection de pistes au même album. C'était le manque le plus concret
// du Panel Admin — corriger l'album de quarante pistes se faisait piste par
// piste, dans la modale d'édition, une fenêtre à la fois.
//
// S'appuie sur les endpoints d'api/albums.php (voir migrations.php pour le
// modèle). Aucune règle métier ici : le serveur reste seul juge de l'unicité des
// noms, de la validité d'une année et de la synchronisation entre `tracks.album`
// (le texte lu par l'app Android) et `tracks.album_id`.
//
// Le sélecteur de pistes lit ALL_MUSIC_DATA, la bibliothèque déjà sérialisée
// dans la page. C'est assumé ici et seulement ici : cet écran est réservé aux
// admins, la donnée est déjà en mémoire, et il s'agit de choisir parmi SA propre
// bibliothèque, pas de chercher dans une collection qu'on ne connaît pas (ce
// cas-là est passé côté serveur, voir api/search.php). Le jour où ALL_MUSIC_DATA
// disparaîtra de la page, c'est ce sélecteur-ci qu'il faudra rebrancher.
document.addEventListener('alpine:init', () => {
    Alpine.data('adminAlbumsPanel', () => ({
        albums: [],
        loading: true,
        // Formulaire d'édition. null = aucun album ouvert ; { id: 0 } = création.
        editing: null,
        saving: false,

        // Sélection de pistes pour l'édition en masse. Un objet et non un Set :
        // Alpine ne suit pas les mutations d'un Set, la case cochée ne se
        // rafraîchirait pas.
        selected: {},
        trackFilter: '',
        unassignedOnly: false,
        targetAlbumId: '',
        targetNewName: '',
        assigning: false,

        // ALL_MUSIC_DATA est un tableau global ordinaire, pas un etat Alpine : le
        // muter ne redeclenche aucun rendu. Ce compteur, lui, est suivi -- on
        // l'incremente apres chaque ecriture pour que la liste de pistes se
        // redessine. Sans lui, une piste rattachee ou un album renomme gardait son
        // ancien libelle dans le selecteur juste en dessous (constate en vrai).
        libraryVersion: 0,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            try {
                const res = await fetch('api.php?action=albums');
                const data = await res.json();
                this.albums = (data && data.status === 'success') ? data.albums : [];
            } catch (e) {
                this.albums = [];
            } finally {
                this.loading = false;
            }
        },

        get selectedIds() {
            return Object.keys(this.selected).filter(id => this.selected[id]);
        },

        // Liste proposée dans le sélecteur. Bornée à 300 lignes : au-delà, on
        // fait défiler sans jamais trouver — c'est le champ de filtre qui sert à
        // réduire, pas le défilement.
        get filteredTracks() {
            this.libraryVersion; // dependance explicite, voir sa declaration
            if (typeof ALL_MUSIC_DATA === 'undefined') return [];
            const q = this.trackFilter.trim().toLowerCase();
            return ALL_MUSIC_DATA.filter(t => {
                if (this.unassignedOnly && (t.album || '').trim() !== '') return false;
                if (!q) return true;
                return (t.title || '').toLowerCase().includes(q)
                    || (t.artist || '').toLowerCase().includes(q)
                    || (t.album || '').toLowerCase().includes(q);
            }).slice(0, 300);
        },

        newAlbum() {
            this.editing = { id: 0, name: '', artist: '', year: '' };
        },

        edit(album) {
            this.editing = {
                id: album.id,
                name: album.name,
                artist: album.artist || '',
                year: album.year !== null ? String(album.year) : '',
            };
        },

        async save(formEl) {
            if (!this.editing || this.saving) return;
            this.saving = true;
            const fd = new FormData(formEl);
            fd.set('album_id', String(this.editing.id));
            fd.set('name', this.editing.name);
            fd.set('artist', this.editing.artist);
            fd.set('year', this.editing.year);
            fd.set('csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('api.php?action=album_save', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return; }

                // Un renommage propage le nouveau nom au texte d'album de toutes les
                // pistes liées, côté serveur (voir album_save). La copie locale doit
                // suivre, sinon les listes déjà rendues -- y compris le sélecteur juste
                // en dessous -- continuent d'afficher l'ancien nom jusqu'au prochain
                // rechargement complet de la page.
                const savedId = data.album_id;
                const savedName = this.editing.name;
                if (typeof ALL_MUSIC_DATA !== 'undefined') {
                    ALL_MUSIC_DATA.forEach(t => {
                        if (String(t.album_id) === String(savedId)) t.album = savedName;
                    });
                    this.libraryVersion++;
                }

                this.editing = null;
                await this.load();
                this.refreshLocalLibrary();
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
            } finally {
                this.saving = false;
            }
        },

        remove(album) {
            // confirmAction() est le dialogue maison de l'app (le confirm() natif ne
            // suit aucun theme et n'est pas traduit) : il rappelle par callback, pas
            // par promesse, d'ou l'imbrication plutot qu'un await.
            Alpine.store('ui').confirmAction(
                T('admin_album_delete_confirm', { n: album.track_count }),
                () => this.doRemove(album)
            );
        },

        async doRemove(album) {
            const fd = new FormData();
            fd.append('album_id', album.id);
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('api.php?action=album_delete', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return; }
                await this.load();
                this.refreshLocalLibrary();
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
            }
        },

        // detach = true : on vide l'album des pistes choisies au lieu de leur en
        // attribuer un (le serveur interprète une cible vide comme un détachement).
        async assign(detach) {
            const ids = this.selectedIds;
            if (!ids.length || this.assigning) return;
            if (!detach && !this.targetAlbumId && !this.targetNewName.trim()) {
                Alpine.store('ui').showToast(T('admin_albums_pick_target'), 'error');
                return;
            }
            this.assigning = true;
            const fd = new FormData();
            fd.append('track_ids', ids.join(','));
            if (!detach) {
                if (this.targetAlbumId) fd.append('album_id', this.targetAlbumId);
                else fd.append('album_name', this.targetNewName.trim());
            }
            fd.append('csrf_token', CSRF_TOKEN);
            try {
                const res = await fetch('api.php?action=album_assign', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return; }

                // Mise à jour optimiste de la copie locale : même convention que
                // toggleLikeUI(), pour que les listes déjà rendues reflètent le
                // changement sans rechargement de page.
                if (typeof ALL_MUSIC_DATA !== 'undefined') {
                    const set = new Set(ids.map(String));
                    ALL_MUSIC_DATA.forEach(t => {
                        if (!set.has(String(t.id))) return;
                        t.album = detach ? null : data.album_name;
                        t.album_id = detach ? null : data.album_id;
                    });
                    this.libraryVersion++;
                }
                // Remettre chaque case a false plutot que remplacer l'objet : en
                // remplacant `selected` par {}, les cases cochees restaient cochees a
                // l'ecran alors que la selection etait vide (verifie en vrai). Alpine
                // ne re-evalue pas x-model="selected[id]" pour une cle qui a disparu ;
                // il suit la valeur de la cle, pas l'existence de l'objet.
                this.selectedIds.forEach(id => { this.selected[id] = false; });
                this.targetNewName = '';
                await this.load();
                Alpine.store('ui').showToast(T(detach ? 'admin_albums_detached_ok' : 'admin_albums_assigned_ok', { n: data.assigned }), 'success');
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
            } finally {
                this.assigning = false;
            }
        },

        // Les écrans déjà rendus (index Albums, page Album, lignes de piste) sont
        // dessinés à partir d'ALL_MUSIC_DATA ; après une écriture serveur, la copie
        // locale est périmée. On la recharge depuis l'endpoint albums plutôt que de
        // recharger toute la page, qui interromprait la lecture en cours.
        refreshLocalLibrary() {
            if (typeof showAlbumsIndex === 'function' && Alpine.store('ui').section === 'albums-page') showAlbumsIndex(false);
        },
    }));
});
