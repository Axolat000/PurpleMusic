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
