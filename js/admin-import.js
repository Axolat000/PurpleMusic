// =============================================================================
// PANEL ADMIN > IMPORT EN MASSE
// =============================================================================
// L'envoi fichier par fichier reste le chemin normal ; il devient absurde dès
// qu'on veut verser une discothèque entière. Ici on dépose les fichiers sur le
// serveur par le moyen qu'on veut (scp, rsync, un volume Docker), et l'app les
// adopte depuis un dossier surveillé.
//
// Rien n'est importé automatiquement : le scan propose, un humain choisit. Un
// import automatique sur simple dépôt transformerait un `cp` malheureux en
// modification de bibliothèque.
document.addEventListener('alpine:init', () => {
    Alpine.data('adminImportPanel', () => ({
        files: [],
        folder: '',
        selected: {},
        genre: 'Autre',
        scanning: false,
        importing: false,
        progress: '',
        report: null,

        init() {
            this.scan();
        },

        // keepReport : apres un import, on rescanne pour vider la liste, mais le
        // compte-rendu doit rester a l'ecran -- c'est la seule trace de ce qui vient
        // d'etre importe et de ce qui a ete ignore. Constate en vrai : le rescan
        // effacait le rapport avant meme qu'il soit lu.
        async scan(keepReport = false) {
            this.scanning = true;
            if (!keepReport) this.report = null;
            try {
                const res = await fetch('api.php?action=import_scan');
                const data = await res.json();
                if (data && data.status === 'success') {
                    this.files = data.files;
                    this.folder = data.folder;
                    // Tout est coché par défaut : on vient de déposer ces fichiers
                    // exprès, le cas courant est de tout importer.
                    this.selected = {};
                    this.files.forEach(f => { this.selected[f.file] = true; });
                } else {
                    this.files = [];
                }
            } catch (e) {
                this.files = [];
            } finally {
                this.scanning = false;
            }
        },

        get selectedFiles() {
            return this.files.map(f => f.file).filter(name => this.selected[name]);
        },

        toggleAll(checked) {
            this.files.forEach(f => { this.selected[f.file] = checked; });
        },

        formatSize(bytes) {
            const mo = bytes / (1024 * 1024);
            return (mo >= 1 ? mo.toFixed(1) + ' Mo' : Math.round(bytes / 1024) + ' Ko');
        },

        // L'import part par lots de 25. Le serveur en refuse plus de 50 d'un coup :
        // une requête unique sur deux cents fichiers dépasserait le temps
        // d'exécution PHP et laisserait l'import à moitié fait sans que personne
        // sache où il s'est arrêté. Par lots, chaque réponse est un point d'arrêt
        // net, et la progression est visible.
        async run() {
            const all = this.selectedFiles;
            if (!all.length || this.importing) return;
            this.importing = true;
            this.report = { imported: [], failed: [] };

            try {
                for (let i = 0; i < all.length; i += 25) {
                    const batch = all.slice(i, i + 25);
                    this.progress = T('admin_import_progress', { done: i, total: all.length });

                    const fd = new FormData();
                    fd.append('files', JSON.stringify(batch));
                    fd.append('genre', this.genre);
                    fd.append('csrf_token', CSRF_TOKEN);
                    const res = await fetch('api.php?action=import_run', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.status !== 'success') {
                        Alpine.store('ui').showToast(data.message || T('err_action_failed'), 'error');
                        break;
                    }
                    this.report.imported.push(...data.imported);
                    this.report.failed.push(...data.failed);
                }
                this.progress = '';
                Alpine.store('ui').showToast(T('admin_import_done', { n: this.report.imported.length }), 'success');
                await this.scan(true);
            } catch (e) {
                Alpine.store('ui').showToast(T('err_action_failed'), 'error');
            } finally {
                this.importing = false;
            }
        },
    }));
});
