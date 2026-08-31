// =============================================================================
// PANEL ADMIN > HISTORIQUE DES ACTIONS
// =============================================================================
// Rien ne gardait trace de qui avait supprimé une piste, rétrogradé un compte ou
// fusionné deux genres. Sur une instance à plusieurs administrateurs, la seule
// réponse possible à « qui a supprimé ça ? » était : personne ne sait.
//
// Lecture seule, et volontairement : il n'existe aucun bouton pour effacer le
// journal. Un journal qu'on peut vider depuis l'interface qu'il surveille ne
// prouve rien.
document.addEventListener('alpine:init', () => {
    Alpine.data('adminLogPanel', () => ({
        entries: [],
        total: 0,
        hasMore: false,
        loading: true,

        init() {
            this.load(false);
        },

        async load(append) {
            this.loading = true;
            try {
                const offset = append ? this.entries.length : 0;
                const res = await fetch('api.php?action=admin_log&limit=50&offset=' + offset);
                const data = await res.json();
                if (data && data.status === 'success') {
                    this.entries = append ? this.entries.concat(data.entries) : data.entries;
                    this.total = data.total;
                    this.hasMore = data.has_more;
                }
            } catch (e) {
                if (!append) this.entries = [];
            } finally {
                this.loading = false;
            }
        },

        // Libellé lisible de l'action. Repli sur le code brut : une action ajoutée
        // plus tard s'affichera telle quelle plutôt que de disparaître de l'écran
        // faute de traduction.
        actionLabel(code) {
            const key = 'admin_log_action_' + code;
            const label = T(key);
            return label === key ? code : label;
        },

        // Horodatage absolu, pas « il y a 3 jours ». Un journal sert à recouper des
        // faits : « le 12 à 14h02 » se compare à une autre source, « il y a 3 jours »
        // devient faux le lendemain.
        formatDate(ts) {
            try {
                return new Date(ts * 1000).toLocaleString(LANG || undefined, {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit',
                });
            } catch (e) {
                return String(ts);
            }
        },
    }));
});

// =============================================================================
// LIEN DE PARTAGE D'UNE PLAYLIST
// =============================================================================
// Placé ici plutôt que dans un fichier à part : c'est une trentaine de lignes de
// formulaire, et ce fichier regroupe déjà les petits composants Alpine de
// service. Le lien lui-même est produit et révoqué par le serveur
// (api.php?action=playlist_share), qui reste seul juge de qui a le droit.
document.addEventListener('alpine:init', () => {
    Alpine.data('playlistShareForm', () => ({
        link: '',
        busy: false,

        // Meme piege que pour les collaborateurs : au moment ou ce composant est
        // instancie, aucune playlist n'est ouverte. Lu une seule fois a
        // l'instanciation, le jeton etait donc toujours vide et une playlist deja
        // partagee reproposait "Creer un lien" au lieu d'afficher le sien.
        init() {
            this.$watch('$store.ui.activeModal', (modal) => {
                if (modal !== 'playlistShareModal') return;
                const pd = Alpine.store('ui').playlistDetail;
                this.link = (pd && pd.share_token) ? this.urlFor(pd.share_token) : '';
            });
        },

        urlFor(token) {
            // URL absolue construite depuis la page courante : l'instance peut vivre
            // dans un sous-dossier, et un lien relatif ne se colle pas dans un SMS.
            const base = window.location.href.split('?')[0].replace(/[^/]*$/, '');
            return base + 'share.php?t=' + token;
        },

        async send(mode) {
            const pd = Alpine.store('ui').playlistDetail;
            if (!pd || this.busy) return null;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('playlist_id', pd.id);
                if (mode) fd.append('mode', mode);
                fd.append('csrf_token', CSRF_TOKEN);
                const res = await fetch('api.php?action=playlist_share', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return null; }
                // Le détail en mémoire est mis à jour aussi : sans ça, refermer puis
                // rouvrir la modale repartirait de l'ancien état.
                pd.share_token = data.token;
                return data;
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
                return null;
            } finally {
                this.busy = false;
            }
        },

        async create() {
            const data = await this.send('create');
            if (data && data.token) this.link = this.urlFor(data.token);
        },

        async revoke() {
            const data = await this.send('revoke');
            if (data) {
                this.link = '';
                Alpine.store('ui').showToast(T('playlist_share_revoked'), 'info');
            }
        },

        copy() {
            // navigator.clipboard n'existe qu'en contexte sécurisé (HTTPS ou
            // localhost) : sur une instance en HTTP simple, on retombe sur la
            // sélection du champ, que l'utilisateur copie lui-même.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(this.link)
                    .then(() => Alpine.store('ui').showToast(T('playlist_share_copied'), 'success'))
                    .catch(() => this.selectLink());
            } else {
                this.selectLink();
            }
        },

        selectLink() {
            const input = this.$el.querySelector('.share-link-input');
            if (input) { input.focus(); input.select(); }
        },
    }));
});

function openPlaylistShare() {
    openModal('playlistShareModal');
}

// =============================================================================
// COLLABORATEURS D'UNE PLAYLIST
// =============================================================================
// Voisin du formulaire de partage, et pour la même raison : quelques dizaines de
// lignes de formulaire, pas de quoi ouvrir un fichier. Le serveur reste seul juge
// des droits (voir can_edit_playlist_content() dans api/helpers.php) ; ce
// composant ne fait qu'afficher et demander.
document.addEventListener('alpine:init', () => {
    Alpine.data('playlistCollabForm', () => ({
        collaborators: [],
        candidates: [],
        pick: '',
        loading: false,
        busy: false,

        // Le composant Alpine est instancie au chargement de la PAGE, bien avant
        // qu'une playlist soit ouverte : charger dans init() lisait un
        // playlistDetail encore null, sortait aussitot, et la modale restait
        // bloquee sur "Chargement..." pour toujours. On ecoute donc l'ouverture.
        init() {
            this.$watch('$store.ui.activeModal', (modal) => {
                if (modal === 'playlistCollabModal') this.load();
            });
        },

        async load() {
            const pd = Alpine.store('ui').playlistDetail;
            if (!pd) return;
            this.loading = true;
            try {
                const res = await fetch('api.php?action=playlist_collab_list&q=' + encodeURIComponent(pd.id));
                const data = await res.json();
                if (data && data.status === 'success') {
                    this.collaborators = data.collaborators;
                    this.candidates = data.candidates;
                }
            } catch (e) {
                this.collaborators = [];
                this.candidates = [];
            } finally {
                this.loading = false;
            }
        },

        async send(action, userId) {
            const pd = Alpine.store('ui').playlistDetail;
            if (!pd || this.busy) return;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('playlist_id', pd.id);
                fd.append('user_id', userId);
                fd.append('csrf_token', CSRF_TOKEN);
                const res = await fetch('api.php?action=' + action, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status !== 'success') { Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error'); return; }
                // Rechargement de la liste plutot que mise a jour locale : c'est le
                // serveur qui decide qui figure ou non, et il n'y a qu'une ligne a
                // relire.
                await this.load();
                this.pick = '';
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
            } finally {
                this.busy = false;
            }
        },

        add() { if (this.pick) this.send('playlist_collab_add', this.pick); },
        remove(userId) { this.send('playlist_collab_remove', userId); },
    }));
});

function openPlaylistCollab() {
    openModal('playlistCollabModal');
}
