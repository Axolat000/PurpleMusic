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

        // Le jeton connu est celui du détail de playlist déjà chargé : rouvrir la
        // modale ne redemande donc rien au serveur tant que rien n'a changé.
        init() {
            const pd = Alpine.store('ui').playlistDetail;
            this.link = (pd && pd.share_token) ? this.urlFor(pd.share_token) : '';
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
