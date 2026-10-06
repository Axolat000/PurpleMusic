// --- LOGIQUE JAVASCRIPT PURPLE MUSIC ---

// --- ALPINE.JS : STORE GLOBAL UI (modales, sections, dialogue de confirmation, toast) ---
document.addEventListener('alpine:init', () => {
    Alpine.store('ui', {
        activeModal: null,
        section: 'accueil',
        searchTerm: '',
        playlistDetail: null,
        // Renommage sur place du titre d'une playlist (voir startPlaylistTitleEdit()
        // dans player-ui.js). Le brouillon est séparé du nom affiché : annuler avec
        // Échap doit restaurer l'ancien nom, ce qu'un x-model direct sur
        // playlistDetail.name rendrait impossible.
        playlistTitleEditing: false,
        playlistTitleDraft: '',
        browseTitle: '', // titre de la page "Voir tout" (page dédiée, voir openBrowseAll())

        // --- TRI DE LA BIBLIOTHÈQUE : la valeur vit ici et non dans le DOM.
        // Elle était lue sur #sortSelect.value, c'est-à-dire qu'un élément de
        // formulaire servait d'état applicatif — impossible de changer le tri sans
        // toucher au DOM, et impossible de le rendre autrement qu'avec un <select>.
        reverbPreset: 'off',    // ambiance de reverberation, voir js/audio-effects.js
        normalizeEnabled: false, // harmonisation du niveau sonore entre morceaux
        streamQuality: 'original',    // 'original' ou un debit ; voir js/audio-effects.js
        streamQualityAvailable: false, // le serveur sait-il reencoder (ffmpeg present)
        streamQualityBitrates: [],
        crossfadeSeconds: 0,     // duree du fondu enchaine, 0 = desactive
        gaplessEnabled: false,   // enchainement sans blanc
        pushAvailable: false,    // le serveur sait-il emettre des notifications (cles VAPID)
        pushEnabled: false,      // cet appareil est-il abonne
        profilePublic: false,    // mon profil d'ecoute est-il visible par les autres comptes
        publicProfile: null,     // profil actuellement ouvert dans la modale
        sortValue: 'recommended',
        sortMenuOpen: false,
        sortLabel() {
            const opt = (typeof SORT_OPTIONS !== 'undefined') ? SORT_OPTIONS.find(o => o.value === this.sortValue) : null;
            return opt ? opt.label : '';
        },
        setSort(value) {
            this.sortValue = value;
            this.sortMenuOpen = false;
            filterAndSortTracks();
        },
        recentTracks: [],
        popularTracks: [],
        playlistsPreview: [],
        recommendedTracks: [], // rempli de façon asynchrone (voir init()) -- calcul serveur, pas instantané comme les autres rangées
        continueTracks: [],    // "Reprendre l'écoute" : dérivé de l'historique local (voir pushListenHistory())
        dailyMixes: [],        // "Mix du jour" : sélections par genre, refaites chaque jour (voir api/mixes.php)
        hiddenGemTracks: [],   // "Pépites oubliées" : les moins écoutées, hors jamais-jouées
        homeLoaded: false,     // passe à true quand les rangées serveur ont répondu -> retire les squelettes
        confirmState: { open: false, message: '', onConfirm: null },
        // Saisie modale générique (texte libre ou choix dans une liste).
        // L'app avait déjà remplacé window.confirm() par un dialogue maison ;
        // window.prompt() restait le seul trou — il ne suit aucun thème, ne se
        // traduit pas et est bloqué par certains navigateurs. options non vide =>
        // liste déroulante, sinon champ texte.
        promptState: { open: false, title: '', value: '', options: [], onSubmit: null },
        toastState: { visible: false, message: '', kind: 'info' },
        toastTimer: null,

        // --- BARRE LATÉRALE (desktop) : état réduit/déployé, persisté par navigateur.
        // Lu très tôt (voir applySidebarStatePreAlpine() dans theme.js) pour que la
        // barre ne s'affiche pas déployée avant de se replier au premier rendu Alpine.
        sidebarCollapsed: false,

        // --- RECHERCHE : historique local des termes validés (10 max, plus récent en tête).
        // Purement navigateur : rien n'est envoyé au serveur, contrairement à ce que
        // ferait une "recherche récente" côté compte.
        recentSearches: [],

        // --- FILTRE DE GENRE DE L'ACCUEIL : recompose les rangées sans recharger.
        // null = aucun filtre. Purement visuel et non persisté : c'est le seul
        // filtrage par genre qui subsiste, l'ancien masquage durable (Paramètres >
        // Bibliothèque) ayant été retiré — il cachait des morceaux sans que rien ne
        // l'indique depuis l'écran où ils manquaient.
        genrePills: [],
        activeGenre: null,

        // --- ÉTAT DE LECTURE EXPOSÉ À ALPINE ---
        // La lecture est pilotée par des variables globales hors store (queue,
        // currentIndex...). Ces deux miroirs existent uniquement pour que le markup
        // puisse réagir (indicateur "en cours" sur les cartes/lignes) sans que chaque
        // composant ait à interroger l'élément <audio>. Tenus à jour par syncPlaybackState().
        currentTrackId: null,
        isPlaying: false,

        // --- PAGE STATISTIQUES : fenêtre d'analyse en jours (0 = depuis toujours).
        // Voir loadStats() dans js/stats.js.
        statsRange: 30,

        // --- THÈME VISUEL (preset par utilisateur, stocké en localStorage) ---
        themePreset: 'violet',

        // --- ADMIN PANEL (page dédiée) : mot de passe temporaire généré par "Réinitialiser" sur un
        // utilisateur, affiché une seule fois via #adminResetPasswordModal (voir adminResetPassword() plus bas). ---
        adminGeneratedPassword: { username: '', password: '' },

        // --- PAROLES (lrclib.net) : dans le lecteur plein écran (mobile, à la place de la pochette)
        // ou dans un panneau latéral droit (desktop, comme la file d'attente) ---
        showLyricsInPlayer: false,
        lyricsPanelOpen: false,
        lyricsLoading: false,
        lyricsFound: null, // null = pas encore chargé, true/false une fois la recherche faite
        lyricsTrackId: null,
        lyricsSynced: [], // [{ time: seconds, text: string }]
        lyricsPlain: '',
        lyricsActiveIndex: -1,

        // --- LECTEUR DESKTOP "GRAND ÉCRAN" (#desktop-player) : carrousel vertical à 3 positions.
        // 'player' = carte lecteur (vue par défaut) ; 'lyrics'/'queue' = cartes paroles/file d'attente.
        // Remise à 'player' à chaque ouverture/fermeture (voir openDesktopPlayer()/closeDesktopPlayer()).
        desktopPlayerView: 'player',

        // --- VISUALISEUR AUDIO (AnalyserNode partagé avec l'égaliseur, voir initAudioGraph() plus bas) :
        // remplace la pochette dans le lecteur plein écran mobile / la colonne pochette du lecteur desktop
        // tant qu'actif. Un seul réglage persistant (activé/désactivé, réglable uniquement dans Paramètres
        // > Général) plutôt qu'un bouton par lecteur -- appliqué automatiquement dans les deux lecteurs dès
        // qu'il est activé. La boucle requestAnimationFrame réelle (par lecteur) est démarrée/arrêtée par
        // applyVisualizerForContext() selon ce booléen + l'état d'ouverture du lecteur concerné, jamais par
        // simple réactivité x-show (qui ne fait que cacher le canvas, pas arrêter la boucle qui l'anime).
        visualizerEnabled: false,

        // --- THÈME DYNAMIQUE (Paramètres > Général uniquement) : surcouche PAR-DESSUS le preset statique
        // actif (violet/amoled/midnight/forest/crimson, voir THEME_PRESETS plus bas) -- pas un remplacement.
        // Désactivé par défaut. Quand actif, --fp-gradient-1/2 sont recalculées à chaque changement de piste
        // à partir des couleurs dominante/vibrante extraites de la pochette (voir applyDynamicThemeForCurrentTrack()
        // / setDynamicThemeEnabled(), appelé depuis loadTrack()) au lieu de garder les valeurs figées du preset.
        dynamicThemeEnabled: false,

        // --- THÈME DYNAMIQUE D'APPLICATION (Paramètres > Thème) : réglage indépendant de dynamicThemeEnabled
        // ci-dessus -- recolore TOUTES les variables de thème (pas seulement --fp-gradient-1/2) à partir de
        // la pochette en cours. Voir setAppDynamicThemeEnabled()/applyAppDynamicThemeForCurrentTrack() dans
        // theme.js.
        appDynamicThemeEnabled: false,

        // --- MINUTEUR DE SOMMEIL (Paramètres > Général uniquement -- pas de bouton dans les lecteurs).
        // sleepTimerActive/Remaining : un seul minuteur réel peut tourner à la fois (voir
        // startSleepTimer()/cancelSleepTimer(), hors store, en mémoire seulement -- pas de persistance de
        // l'état actif, comme côté Android). sleepTimerLastMinutes est la seule chose qu'on retient
        // (localStorage) : juste pour marquer visuellement le dernier préréglage choisi.
        sleepTimerActive: false,
        sleepTimerRemaining: 0, // secondes restantes, décompte affiché arrondi à la minute supérieure
        sleepTimerLastMinutes: 0,

        // --- MISE À JOUR (popup admin) : vérifie une fois par vrai chargement de page (voir init()),
        // résultat mis en cache côté client (sessionStorage) en plus du cache serveur (1h) pour éviter
        // tout appel réseau superflu. Le "dismiss" (Plus tard) est aussi en sessionStorage : suspendu
        // pour la session du navigateur en cours, pas pour toujours (voir dismissUpdateNotice()).
        updateCheck: { checked: false, available: false, watchtowerConfigured: false },
        updateDismissedThisSession: false,
        updateTriggering: false,
        updateTriggerState: null, // null | 'updating' | 'error'
        updateTriggerError: '',

        // Recompose les rangées de l'accueil. Rappelée à chaque changement de filtre
        // de genre : les rangées se dérivent toutes du même sous-ensemble filtré,
        // il n'y a donc qu'un seul endroit qui décide de ce qui est visible.
        rebuildHomeRows() {
            if (typeof ALL_MUSIC_DATA === 'undefined') return;
            // Une piste peut porter plusieurs genres : elle correspond à la
            // pastille dès que l'UN d'eux correspond.
            const pool = ALL_MUSIC_DATA.filter(t => {
                if (this.activeGenre === null) return true;
                return trackGenres(t).some(g => g.toLowerCase() === this.activeGenre.toLowerCase());
            });

            this.recentTracks = [...pool].sort((a, b) => b.id - a.id).slice(0, 12);
            this.popularTracks = [...pool]
                .filter(t => (parseInt(t.play_count) || 0) > 0)
                .sort((a, b) => (parseInt(b.play_count) || 0) - (parseInt(a.play_count) || 0))
                .slice(0, 12);
            // Pépites : les moins écoutées PARMI celles déjà écoutées au moins une fois.
            // Inclure les jamais-jouées ferait doublon avec "Ajouts récents" et
            // remplirait la rangée de morceaux que personne n'a validés.
            this.hiddenGemTracks = [...pool]
                .filter(t => (parseInt(t.play_count) || 0) > 0)
                .sort((a, b) => (parseInt(a.play_count) || 0) - (parseInt(b.play_count) || 0))
                .slice(0, 12);
            // "Reprendre l'écoute" : historique local, dédoublonné, borné aux pistes
            // encore présentes dans la bibliothèque (une piste supprimée depuis reste
            // dans l'historique du navigateur mais ne doit plus être proposée).
            const byId = new Map(pool.map(t => [String(t.id), t]));
            this.continueTracks = readListenHistory()
                .map(id => byId.get(String(id)))
                .filter(Boolean)
                .slice(0, 12);

            if (typeof ALL_PLAYLISTS_DATA !== 'undefined') {
                this.playlistsPreview = ALL_PLAYLISTS_DATA.slice(0, 12);
            }
        },

        setGenreFilter(genre) {
            this.activeGenre = genre;
            this.rebuildHomeRows();
            if (window.Alpine) Alpine.nextTick(refreshHomeRowMarquees);
        },

        toggleSidebar() {
            this.sidebarCollapsed = !this.sidebarCollapsed;
            document.documentElement.classList.remove('sidebar-collapsed-preload');
            localStorage.setItem('purpleMusicSidebarCollapsed', this.sidebarCollapsed ? '1' : '0');
        },

        addRecentSearch(term) {
            const q = (term || '').trim();
            if (q.length < 2) return;
            // Dédoublonnage insensible à la casse, le terme validé remonte en tête.
            this.recentSearches = [q, ...this.recentSearches.filter(x => x.toLowerCase() !== q.toLowerCase())].slice(0, 10);
            try { localStorage.setItem('purpleMusicRecentSearches', JSON.stringify(this.recentSearches)); } catch (e) { /* quota plein : l'historique est un confort, jamais bloquant */ }
        },

        clearRecentSearches() {
            this.recentSearches = [];
            try { localStorage.removeItem('purpleMusicRecentSearches'); } catch (e) { /* idem */ }
        },

        init() {
            this.sidebarCollapsed = localStorage.getItem('purpleMusicSidebarCollapsed') === '1';
            document.documentElement.classList.remove('sidebar-collapsed-preload');
            try {
                const rs = JSON.parse(localStorage.getItem('purpleMusicRecentSearches') || '[]');
                if (Array.isArray(rs)) this.recentSearches = rs.filter(x => typeof x === 'string').slice(0, 10);
            } catch (e) { /* historique corrompu : on repart d'une liste vide */ }

            // Pastilles de genre : uniquement les genres réellement présents dans la
            // bibliothèque (et non masqués), pas la liste complète configurée en admin —
            // proposer un filtre qui ne renverrait rien n'a pas de sens.
            if (typeof ALL_MUSIC_DATA !== 'undefined') {
                // Chaque genre d'une piste multi-genres compte pour lui-même :
                // une piste "Phonk, Nightcore" doit apparaître sous les deux
                // pastilles, pas sous une pastille "Phonk, Nightcore" bâtarde.
                const counts = new Map();
                ALL_MUSIC_DATA.forEach(t => {
                    trackGenres(t).forEach(g => counts.set(g, (counts.get(g) || 0) + 1));
                });
                this.genrePills = [...counts.entries()].sort((a, b) => b[1] - a[1]).map(e => e[0]);
            }
            this.rebuildHomeRows();
            // recentTracks/popularTracks/playlistsPreview rendus par le x-for ci-dessus : mesurables une
            // fois la micro-tâche Alpine passée (voir refreshHomeRowMarquees() dans ui-modals.js).
            if (window.Alpine) Alpine.nextTick(refreshHomeRowMarquees);
            // Recommandations : calcul serveur (build_recommendations(), api.php), chargé une fois
            // au démarrage comme le reste de l'accueil -- échec réseau non bloquant, la rangée reste
            // simplement absente (x-if sur .length > 0 dans index.php) plutôt que de casser la page.
            fetch('api.php?action=recommendations').then(r => r.json()).then(data => {
                if (Array.isArray(data)) this.recommendedTracks = data;
                // Rangée conditionnée par x-if="recommendedTracks.length > 0" : n'existe dans le DOM
                // qu'une fois cette affectation faite, donc la mesure doit attendre ce même tick.
                if (window.Alpine) Alpine.nextTick(refreshHomeRowMarquees);
            }).catch(e => console.error(e)).finally(() => {
                // Retire les squelettes que la requête ait abouti ou non : en cas d'échec
                // réseau, laisser des squelettes pulser indéfiniment ferait croire à un
                // chargement toujours en cours.
                this.homeLoaded = true;
            });
            // Classement complet (pas juste le top 20 ci-dessus) : alimente le mode de tri 'recommended',
            // par défaut sur la bibliothèque -- arrive après le premier rendu, donc on retrie une fois prêt
            // si l'utilisateur est toujours sur ce tri (voir compareTracksBySort()/filterAndSortTracks()).
            // Mix du jour : une requête séparée des recommandations, parce qu'ils ne
            // répondent pas à la même question (« que puis-je écouter maintenant ? »
            // contre « quoi de neuf pour moi ? ») et qu'une réponse lente de l'un ne
            // doit pas retenir l'autre.
            fetch('api.php?action=daily_mixes').then(r => r.json()).then(data => {
                if (data && data.status === 'success') this.dailyMixes = data.mixes || [];
            }).catch(e => console.error(e));

            fetch('api.php?action=recommendations&full=1').then(r => r.json()).then(data => {
                if (!Array.isArray(data)) return;
                RECOMMENDED_RANK = new Map(data.map((t, i) => [t.id, i]));
                if (Alpine.store('ui').sortValue === 'recommended') filterAndSortTracks();
            }).catch(e => console.error(e));
            this.themePreset = localStorage.getItem('purpleMusicTheme') || 'violet';
            this.sleepTimerLastMinutes = parseInt(localStorage.getItem('purpleMusicSleepTimerLastMinutes') || '0', 10) || 0;
            this.visualizerEnabled = localStorage.getItem('purpleMusicVisualizerEnabled') === '1';
            restoreReverbSetting();
            restoreNormalizeSetting();
            restoreCrossfadeSettings();
            restoreStreamQuality();
            loadPushConfig();
            refreshOfflineTrackIds();
            loadFollowedArtists();
            this.profilePublic = (typeof PROFILE_PUBLIC !== 'undefined') && !!PROFILE_PUBLIC;
            this.dynamicThemeEnabled = localStorage.getItem('purpleMusicDynamicThemeEnabled') === '1';
            this.appDynamicThemeEnabled = localStorage.getItem('purpleMusicAppDynamicThemeEnabled') === '1';

            // Vérif de mise à jour : uniquement pour un admin connecté (IS_ADMIN/CURRENT_USER_ID sont
            // injectés par index.php, absents/false sur la page de connexion). init() ne tourne qu'une
            // fois par vrai chargement de page (pas de routing client dans cette app), donc pas besoin
            // de protection supplémentaire contre un refire lors des changements de section.
            if (typeof IS_ADMIN !== 'undefined' && IS_ADMIN && typeof CURRENT_USER_ID !== 'undefined' && CURRENT_USER_ID) {
                if (sessionStorage.getItem('pmUpdateDismissed') === '1') this.updateDismissedThisSession = true;
                this.checkForUpdate();
            }
        },

        async checkForUpdate() {
            try {
                const cachedRaw = sessionStorage.getItem('pmUpdateCheckResult');
                if (cachedRaw) {
                    const cached = JSON.parse(cachedRaw);
                    if (cached && typeof cached.ts === 'number' && (Date.now() - cached.ts) < 10 * 60 * 1000) {
                        this.applyUpdateCheckResult(cached.data);
                        return;
                    }
                }
            } catch (e) { /* cache client corrompu : on ignore et on retente une vraie requête */ }

            try {
                const res = await fetch('api.php?action=check_update');
                const data = await res.json();
                sessionStorage.setItem('pmUpdateCheckResult', JSON.stringify({ ts: Date.now(), data }));
                this.applyUpdateCheckResult(data);
            } catch (e) {
                // Échec réseau/API : jamais d'erreur visible pour un simple check en arrière-plan.
                console.error('Update check failed', e);
            }
        },

        applyUpdateCheckResult(data) {
            this.updateCheck = {
                checked: !!(data && data.checked),
                available: !!(data && data.update_available),
                watchtowerConfigured: !!(data && data.watchtower_configured),
            };
            if (this.updateCheck.available && !this.updateDismissedThisSession) {
                this.openModal('updateAvailableModal');
            }
        },

        dismissUpdateNotice() {
            this.updateDismissedThisSession = true;
            sessionStorage.setItem('pmUpdateDismissed', '1');
            this.closeModal('updateAvailableModal');
        },

        async triggerUpdate() {
            this.updateTriggering = true;
            this.updateTriggerState = null;
            this.updateTriggerError = '';
            try {
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                const res = await fetch('api.php?action=trigger_update', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    this.updateTriggerState = 'updating';
                    this.attemptReloadAfterUpdate();
                } else {
                    this.updateTriggerState = 'error';
                    this.updateTriggerError = data.message || T('err_update_trigger_failed');
                    if (data.manual) this.updateCheck.watchtowerConfigured = false;
                }
            } catch (e) {
                // Watchtower ne répond qu'une fois le remplacement du conteneur terminé (stop+pull+start) —
                // or c'est justement CE conteneur (celui qui exécute cette requête PHP) qui se fait arrêter
                // en plein milieu. Le fetch() échoue quasi systématiquement (connexion coupée) même quand
                // le déclenchement a parfaitement réussi : un échec réseau ici veut donc dire "probablement
                // en train de mettre à jour", pas "échec" — on suit le même chemin que le succès plutôt que
                // d'afficher une fausse erreur.
                this.updateTriggerState = 'updating';
                this.attemptReloadAfterUpdate();
            } finally {
                this.updateTriggering = false;
            }
        },

        // Le conteneur redémarre après le déclenchement Watchtower : on attend avant de recharger, avec
        // plusieurs tentatives à intervalle croissant (le serveur peut être brièvement injoignable pendant
        // le pull + la recréation du conteneur) plutôt qu'un simple reload() qui tomberait sur une erreur.
        attemptReloadAfterUpdate(attempt = 0) {
            const delays = [5000, 5000, 8000, 8000, 10000, 10000];
            if (attempt >= delays.length) { window.location.reload(); return; }
            setTimeout(() => {
                fetch(window.location.pathname, { method: 'HEAD', cache: 'no-store' })
                    .then(() => window.location.reload())
                    .catch(() => this.attemptReloadAfterUpdate(attempt + 1));
            }, delays[attempt]);
        },

        openModal(id) { this.activeModal = id; },
        closeModal(id) { if (!id || this.activeModal === id) this.activeModal = null; },

        confirmAction(message, onConfirm) {
            this.confirmState = { open: true, message, onConfirm };
        },
        confirmYes() {
            const cb = this.confirmState.onConfirm;
            this.confirmState = { open: false, message: '', onConfirm: null };
            if (cb) cb();
        },
        confirmNo() {
            this.confirmState = { open: false, message: '', onConfirm: null };
        },

        promptAction(title, initialValue, onSubmit, options = []) {
            this.promptState = { open: true, title, value: initialValue || '', options, onSubmit };
        },
        promptSubmit() {
            const cb = this.promptState.onSubmit;
            const value = this.promptState.value;
            this.promptState = { open: false, title: '', value: '', options: [], onSubmit: null };
            // Valeur vide : on annule plutôt que de laisser l'appelant décider —
            // aucun des usages actuels n'accepte une saisie vide.
            if (cb && value && value.trim()) cb(value.trim());
        },
        promptCancel() {
            this.promptState = { open: false, title: '', value: '', options: [], onSubmit: null };
        },

        // kind : 'info' (défaut) | 'success' | 'error' — porté par un liseré coloré à
        // gauche du toast (voir .toast-typed dans css/ui.css) plutôt que par la seule
        // couleur du texte, pour rester lisible sans distinction de couleur.
        showToast(message, kind = 'info', duration = 3000) {
            // Rétrocompat : showToast(msg, 5000) était appelé avec une durée en 2e
            // argument avant l'introduction des types.
            if (typeof kind === 'number') { duration = kind; kind = 'info'; }
            clearTimeout(this.toastTimer);
            this.toastState = { visible: true, message, kind };
            this.toastTimer = setTimeout(() => { this.toastState.visible = false; }, duration);
        }
    });

    // --- Composant réutilisable "Netflix-row" : chevrons gauche/droite pour les rangées d'accueil ---
    // Utilisé sur les 3 rangées (récents / populaires / mixs) via x-data="homeRowScroller()" + x-ref="scrollEl".
    Alpine.data('homeRowScroller', () => ({
        canLeft: false,
        canRight: false,
        init() {
            this.$nextTick(() => this.update());
            window.addEventListener('resize', () => this.update());
        },
        update() {
            const el = this.$refs.scrollEl;
            if (!el) return;
            this.canLeft = el.scrollLeft > 4;
            this.canRight = el.scrollLeft < (el.scrollWidth - el.clientWidth - 4);
        },
        onScroll() { this.update(); },
        scrollDir(dir) {
            const el = this.$refs.scrollEl;
            if (!el) return;
            el.scrollBy({ left: dir * 300, behavior: 'smooth' });
        }
    }));

    // --- Modale Paramètres : onglets (Général / Bibliothèque / Compte) + formulaire de changement
    // de mot de passe (self-service). x-data posé sur .modal-content, donc l'état (onglet actif,
    // champs du formulaire) survit à l'ouverture/fermeture de la modale et aux changements d'onglet
    // (x-show masque juste le panneau, il ne détruit rien du DOM/composant).
    Alpine.data('settingsModalForm', () => ({
        activeTab: 'general',
        pwCurrent: '',
        pwNew: '',
        pwConfirm: '',
        pwError: '',
        pwSubmitting: false,
        async submitPasswordChange() {
            this.pwError = '';
            this.pwSubmitting = true;
            try {
                const fd = new FormData();
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('current_password', this.pwCurrent);
                fd.append('new_password', this.pwNew);
                fd.append('confirm_password', this.pwConfirm);
                const res = await fetch('api.php?action=change_password', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.status === 'success') {
                    this.pwCurrent = '';
                    this.pwNew = '';
                    this.pwConfirm = '';
                    Alpine.store('ui').showToast(data.message || T('settings_password_changed'));
                } else {
                    this.pwError = data.message || T('err_password_change_network');
                }
            } catch (e) {
                console.error(e);
                this.pwError = T('err_password_change_network');
            } finally {
                this.pwSubmitting = false;
            }
        }
    }));

    // --- Admin Panel > onglet Thème : aperçu en direct des couleurs.
    //
    // Régler un thème demandait jusqu'ici d'enregistrer puis de recharger la page
    // pour voir le résultat, à chaque essai. Ici les <input type="color"> écrivent
    // directement dans les variables CSS de <html>, donc toute l'interface (barre
    // latérale, lecteur, cartes) se recolore instantanément.
    //
    // Aucune écriture en base ni en localStorage : c'est un aperçu volatil, annulé
    // par revert() ou par un simple rechargement. L'enregistrement reste le POST
    // normal du formulaire.
    Alpine.data('adminThemePreview', () => ({
        live: false,
        // Valeurs en vigueur au moment d'activer l'aperçu, pour pouvoir revenir
        // exactement à l'état d'avant — et non à une supposée valeur par défaut.
        saved: {},

        // Nom du champ admin -> variable CSS correspondante.
        fieldMap: {
            adm_color_bg: '--bg-dark',
            adm_color_panel: '--bg-panel',
            adm_color_primary: '--primary',
            adm_color_accent: '--accent',
            adm_color_text: '--text',
            adm_color_text_muted: '--text-muted',
            adm_color_border: '--border-color',
            adm_color_search_bg: '--search-bg',
            adm_color_fp_gradient_1: '--fp-gradient-1',
            adm_color_fp_gradient_2: '--fp-gradient-2',
            adm_color_header_bg: '--header-bg',
            adm_color_player_bg: '--player-bg',
            adm_color_mob_nav_bg: '--mob-nav-bg',
        },

        applyAll() {
            const root = document.documentElement;
            if (!Object.keys(this.saved).length) {
                // Première application : on mémorise l'inline style existant de
                // chaque variable (souvent vide — la valeur vient alors de la
                // feuille de style), pour un retour arrière fidèle.
                Object.values(this.fieldMap).forEach(v => {
                    this.saved[v] = root.style.getPropertyValue(v);
                });
            }
            Object.entries(this.fieldMap).forEach(([field, cssVar]) => {
                const input = this.$el.querySelector(`[name="${field}"]`);
                if (!input) return;
                const value = input.value.trim();
                // Un champ rgba() vidé par erreur rendrait la barre transparente :
                // on ignore les valeurs vides plutôt que d'écrire du vide.
                if (value) root.style.setProperty(cssVar, value);
            });
        },

        revert() {
            const root = document.documentElement;
            Object.entries(this.saved).forEach(([cssVar, previous]) => {
                if (previous) root.style.setProperty(cssVar, previous);
                else root.style.removeProperty(cssVar);
            });
            this.saved = {};
            // Le preset utilisateur (violet/amoled/...) est posé par theme.js sur
            // ces mêmes variables : on le réapplique pour ne pas laisser l'aperçu
            // écraser silencieusement le choix personnel de l'admin.
            if (typeof setThemeVars === 'function') {
                setThemeVars(localStorage.getItem('purpleMusicTheme') || 'violet');
            }
        },
    }));

    // --- Admin Panel (page dédiée, x-data posé sur <main id="admin">) : gère uniquement l'onglet actif
    // (Général / Thème / Médias / Genres / Utilisateurs). initialTab vient d'index.php (paramètre d'URL
    // ?admin_tab=..., utilisé pour rester sur le même onglet après un redirect suite à une action utilisateur).
    Alpine.data('adminPageForm', (initialTab) => ({
        activeTab: initialTab || 'general'
    }));

    // --- Page de connexion / inscription (x-data posé sur .auth-page) : bascule entre les deux modes via
    // les onglets .settings-tabs (au lieu de l'ancien second bouton "Créer un compte" en bas du formulaire,
    // facilement confondu avec un bouton secondaire) + validation client avant envoi. Le formulaire reste un
    // vrai POST classique vers auth.php (pas de fetch) : on ne bloque la soumission native que si la
    // validation échoue, sinon le flux serveur existant (redirect / rendu de l'erreur pleine page) est
    // inchangé. initialMode/initialUsername viennent d'index.php : après un échec côté serveur, on rouvre sur
    // le même mode que la tentative avec le nom d'utilisateur repré-rempli (jamais le mot de passe).
    Alpine.data('authForm', (initialMode, initialUsername) => ({
        mode: initialMode || 'login',
        username: initialUsername || '',
        password: '',
        confirmPassword: '',
        acceptTerms: false,
        clientError: '',
        showServerError: true, // masqué dès qu'on change de mode : une erreur de connexion n'a plus de sens une fois basculé sur inscription (et inversement)
        switchMode(m) {
            this.mode = m;
            this.clientError = '';
            this.showServerError = false;
        },
        onSubmit(event) {
            this.clientError = '';
            if (this.mode !== 'register') return; // le mode connexion n'a pas de validation client à faire
            if (this.username.trim() === '') {
                this.clientError = T('err_username_required');
                event.preventDefault();
                return;
            }
            if (this.password.length < 6) {
                this.clientError = T('err_password_too_short');
                event.preventDefault();
                return;
            }
            if (this.password !== this.confirmPassword) {
                this.clientError = T('err_password_mismatch');
                event.preventDefault();
                return;
            }
            if (typeof TERMS_ENABLED !== 'undefined' && TERMS_ENABLED && !this.acceptTerms) {
                this.clientError = T('err_must_accept_terms');
                event.preventDefault();
            }
        }
    }));

    // --- Scroll des paroles synchronisées : suit la ligne en cours automatiquement, mais se
    // met en pause dès que l'utilisateur scrolle lui-même (wheel/touch — pas l'événement "scroll"
    // générique, qui se déclenche aussi pour le scrollIntoView() automatique et empêcherait de
    // distinguer scroll manuel et scroll programmatique). Reprend après 10s d'inactivité ou au clic
    // sur "Revenir au direct". Utilisé sur #lyrics-panel (desktop), .fp-lyrics-view (mobile) et la
    // carte "paroles" du carrousel desktop (#desktop-player).
    //
    // isActiveFn (optionnel) : les 3 surfaces ci-dessus ne sont pas toujours retirées du DOM quand
    // elles ne sont pas affichées (la carte du carrousel reste montée en permanence, juste déplacée
    // hors-écran via transform) — donc son minuteur de reprise continue de tourner même quand on est
    // passé sur un autre onglet. Sans garde, il finissait par déclencher $el.scrollIntoView() sur une
    // ligne techniquement toujours dans le DOM mais visuellement hors-écran, ce qui faisait sauter le
    // scroll de la page entière (vécu en prod : "grosse merde" en changeant d'onglet en pleine pause
    // de scroll manuel). isActiveFn permet à chaque point de montage de dire si SA vue est bien celle
    // actuellement affichée ; sans lui, le composant se comporte comme avant (toujours actif).
    Alpine.data('lyricsScroller', (isActiveFn) => ({
        manualScroll: false,
        resumeTimer: null,
        get isActive() { return typeof isActiveFn === 'function' ? !!isActiveFn() : true; },
        userInteracted() {
            this.manualScroll = true;
            clearTimeout(this.resumeTimer);
            this.resumeTimer = setTimeout(() => { this.manualScroll = false; }, 10000);
        },
        backToLive() {
            clearTimeout(this.resumeTimer);
            this.manualScroll = false;
            this.$nextTick(() => {
                const activeEl = this.$el.querySelector('.lyrics-line.active');
                if (activeEl) activeEl.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        },
        destroy() { clearTimeout(this.resumeTimer); }
    }));
});

// --- I18N (JS) : traduit les chaînes générées dynamiquement (rendu côté client) ---
// I18N_CLIENT et LANG sont injectés par index.php (voir <script> inline avant app.js).
// Nommée T() (et non t()) car `t` est déjà utilisé partout dans ce fichier comme nom de variable "track".
function T(key, vars = {}) {
    const table = (typeof I18N_CLIENT !== 'undefined') ? I18N_CLIENT : null;
    const lang = (typeof LANG !== 'undefined') ? LANG : 'fr';
    let str = (table && table[lang] && table[lang][key]) || (table && table.fr && table.fr[key]) || key;
    Object.keys(vars).forEach(k => { str = str.replace('{' + k + '}', vars[k]); });
    return str;
}

// Pont générique pour les liens/boutons de suppression : remplace window.confirm() par le dialogue Alpine.
// Envoie une action mutante à api.php (session + CSRF, voir authenticate_api_user() côté serveur) et
// recharge la page au succès -- remplace les anciens liens/formulaires GET/POST classiques vers
// actions.php (rechargeaient déjà la page via une redirection serveur ; api.php ne fait jamais de
// redirection HTML, seulement du JSON, donc ce fetch()+reload reproduit le même résultat visible).
function postApiAction(action, fields) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    for (const k in fields) fd.append(k, fields[k]);
    return fetch('api.php?action=' + action, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') { window.location.reload(); return data; }
            const msg = (data && data.message) || T('err_action_failed');
            if (window.Alpine) Alpine.store('ui').showToast(msg); else alert(msg);
            return data;
        })
        .catch(() => { if (window.Alpine) Alpine.store('ui').showToast(T('err_action_failed')); else alert(T('err_action_failed')); });
}

function confirmPostAction(message, action, fields) {
    if (window.Alpine) {
        Alpine.store('ui').confirmAction(message, () => { postApiAction(action, fields); });
    } else if (confirm(message)) {
        postApiAction(action, fields);
    }
    return false;
}

// Intercepte un <form> classique (upload, édition de piste, playlist, réglages admin...) et l'envoie à
// api.php au lieu d'un POST natif vers index.php -- api.php ne fait jamais de redirection HTML, donc ce
// fetch()+reload reproduit le même résultat visible que l'ancienne redirection serveur. Retourne false
// (appelé depuis onsubmit="return ...") pour empêcher systématiquement la soumission native du form.
function submitFormToApi(formEl, action) {
    const fd = new FormData(formEl);
    fetch('api.php?action=' + action, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') { window.location.reload(); return; }
            const msg = (data && data.message) || T('err_action_failed');
            if (window.Alpine) Alpine.store('ui').showToast(msg); else alert(msg);
        })
        .catch(() => { if (window.Alpine) Alpine.store('ui').showToast(T('err_action_failed')); else alert(T('err_action_failed')); });
    return false;
}

// Les deux elements de lecture. `audio` designe celui qui SONNE actuellement : il
// change de main a chaque enchainement (voir swapActiveAudio() dans js/crossfade.js).
// C'est un `let` et non un `const` pour cette raison, et tout le reste du code
// continue de lire `audio` sans savoir lequel des deux c'est.
const audioA = document.getElementById('mainAudio');
const audioB = document.getElementById('mainAudioB');
let audio = audioA;

// L'element qui ne sonne pas : celui dans lequel on precharge la piste suivante.
function idleAudio() { return audio === audioA ? audioB : audioA; }
const queuePanel = document.getElementById('queue-panel');

// --- SURFACES DE LECTEUR -----------------------------------------------------
// Les trois surfaces (mini-barre, plein ecran mobile, grand lecteur desktop) sont
// rendues par les memes briques PHP (templates/player-parts.php) et marquent
// chaque element que le JS doit tenir a jour d'un attribut data-pm-*.
//
// AVANT : chaque mise a jour d'etat s'ecrivait trois fois de suite --
// getElementById('curr-time'), puis 'fp-curr-time', puis 'dp-curr-time', chacune
// avec son test de nullite. Un oubli sur l'une des trois ne se voyait que sur
// cette surface-la, donc tard.
//
// MAINTENANT : le JS ne connait plus le nombre de surfaces, il ecrit dans toutes
// celles qui portent l'attribut. Ajouter une quatrieme surface ne demande aucune
// modification de ce fichier.
const pmEach = (part, fn) => document.querySelectorAll('[data-pm-' + part + ']').forEach(fn);
const pmText = (part, value) => pmEach(part, el => { el.innerText = value; });

// Bascule lecture/pause sur toutes les surfaces d'un coup. La forme du bouton
// (taille, couleur, centrage optique du triangle) est desormais entierement CSS :
// seul le symbole du sprite change ici, au lieu des SVG recopies en JS avec leurs
// styles en ligne -- il y en avait trois jeux, un par surface.
const pmSetPlayIcon = (playing) => {
    const label = (typeof T === 'function') ? T(playing ? 'tooltip_pause' : 'tooltip_play') : '';
    pmEach('play', btn => {
        btn.classList.toggle('is-playing', playing);
        const use = btn.querySelector('use');
        if (use) use.setAttribute('href', playing ? '#ico-pause' : '#ico-play');
        if (label) { btn.setAttribute('aria-label', label); btn.setAttribute('title', label); }
    });
};

// --- GRAPHE AUDIO PARTAGÉ (Égaliseur + Visualiseur) ---
// AudioContext.createMediaElementSource(audio) ne peut être appelé qu'UNE SEULE fois sur toute la durée
// de vie de <audio id="mainAudio"> -- un 2e appel lève une exception et casse la lecture. L'égaliseur (5
// BiquadFilterNode peaking) et le visualiseur (AnalyserNode pour la FFT) doivent donc partager le MÊME
// graphe plutôt que d'en construire chacun le leur :
//   audio -> sourceNode -> eqFilters[0..4] (chaîne) -> analyserNode -> audioCtx.destination
// Construit paresseusement (initAudioGraph(), idempotent) au premier geste utilisateur qui en a besoin
// (togglePlay(), activation de l'égaliseur ou du visualiseur) -- jamais au chargement de la page, les
// navigateurs exigeant une interaction utilisateur avant de démarrer un AudioContext.
let audioCtx = null;
// Une source par element : createMediaElementSource() ne peut etre appele qu'une
// fois par element, et les deux doivent alimenter la MEME chaine d'effets --
// sinon le morceau entrant sortirait sans egaliseur ni ambiance pendant le fondu.
let sourceNode = null;   // element A
let sourceNodeB = null;  // element B
// Faders du fondu enchaine, un par element, places juste apres chaque source.
let fadeGainA = null;
let fadeGainB = null;
let eqFilters = [];
let analyserNode = null;
// 6 bandes -- calqué sur les presets courants d'android.media.audiofx.Equalizer, plus une bande basse
// supplémentaire à 100Hz en "lowshelf" (renforcement large des basses, différent d'un pic étroit) pour un
// vrai contrôle grave/aigu distinct du pic à 60Hz. { freq, type } au lieu d'un simple nombre : chaque bande
// peut avoir son propre type de filtre BiquadFilterNode (voir initAudioGraph() ci-dessous).
const EQ_BANDS = [
    { freq: 60, type: 'peaking' },
    { freq: 100, type: 'lowshelf' },
    { freq: 230, type: 'peaking' },
    { freq: 910, type: 'peaking' },
    { freq: 3600, type: 'peaking' },
    { freq: 14000, type: 'peaking' }
];
const EQ_MIN_DB = -12;
const EQ_MAX_DB = 12;

// Préréglages (voir applyEqPreset() dans player-controls.js) -- un gain par bande, même ordre que EQ_BANDS.
const EQ_PRESETS = {
    flat:   [0, 0, 0, 0, 0, 0],
    bass:   [6, 8, 3, 0, 0, 0],
    treble: [0, 0, 0, 2, 5, 8],
    vocal:  [-2, -3, 1, 4, 3, 0],
    rock:   [4, 2, 3, -2, -1, 5],
    pop:    [2, 3, 4, 3, 0, -1]
};

function initAudioGraph() {
    if (audioCtx || !audio) return;
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        audioCtx = new AudioContextClass();
        sourceNode = audioCtx.createMediaElementSource(audioA);
        sourceNodeB = audioCtx.createMediaElementSource(audioB);
        fadeGainA = audioCtx.createGain();
        fadeGainB = audioCtx.createGain();
        // A porte le son, B est muet : au repos, un seul element joue. Le fondu ne
        // fait qu'echanger ces deux valeurs.
        fadeGainA.gain.value = 1;
        fadeGainB.gain.value = 0;

        eqFilters = EQ_BANDS.map((band) => {
            const filter = audioCtx.createBiquadFilter();
            filter.type = band.type;
            filter.frequency.value = band.freq;
            filter.Q.value = 1;
            filter.gain.value = 0;
            return filter;
        });

        analyserNode = audioCtx.createAnalyser();
        analyserNode.fftSize = 256;
        analyserNode.smoothingTimeConstant = 0.75;

        // Chaîne : source -> eq[0..5] -> [sec | réverb] -> analyser -> destination
        //
        // L'étage de réverbération est inséré APRÈS l'égaliseur (on réverbère le son
        // égalisé, pas l'inverse — égaliser une queue de réverbération donnerait un
        // résultat incohérent quand on change de bande) et AVANT l'analyseur, pour
        // que le visualiseur montre ce qu'on entend réellement.
        // Les deux sources se rejoignent avant l'egaliseur : tout ce qui suit
        // (egaliseur, ambiance, normalisation, visualiseur) est partage, et le
        // morceau entrant est traite exactement comme le sortant.
        const mixInput = audioCtx.createGain();
        sourceNode.connect(fadeGainA);
        sourceNodeB.connect(fadeGainB);
        fadeGainA.connect(mixInput);
        fadeGainB.connect(mixInput);

        let node = mixInput;
        eqFilters.forEach((filter) => {
            node.connect(filter);
            node = filter;
        });

        const reverbStage = createReverbStage(audioCtx);
        node.connect(reverbStage.input);

        // Normalisation en DERNIER, juste avant l'analyseur : elle corrige le niveau
        // du morceau tel qu'il sort réellement, réverbération comprise. Placée avant
        // l'étage d'ambiance, elle aurait corrigé un niveau que la réverbération
        // modifie ensuite.
        const normalizeStage = createNormalizeStage(audioCtx);
        reverbStage.output.connect(normalizeStage);
        normalizeStage.connect(analyserNode);
        analyserNode.connect(audioCtx.destination);

        applyEqGains();
    } catch (e) {
        console.error('initAudioGraph failed', e);
    }
}

function resumeAudioGraph() {
    initAudioGraph();
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
}

let CURRENT_VIEW_DATA = [];
let renderedCount = 0;
const RENDER_CHUNK = 30;
// Vue "Voir tout" (page dédiée, ne touche pas au tri de l'accueil) : mêmes rendu/pagination que la
// bibliothèque complète mais dans son propre conteneur/état, voir openBrowseAll().
let BROWSE_VIEW_DATA = [];
let browseRenderedCount = 0;
let browseSort = null;
// Classement complet "Recommandé pour toi" (id -> rang, 0 = meilleur), utilisé comme mode de tri par
// défaut de la bibliothèque -- rempli de façon asynchrone au démarrage (voir init() dans le store Alpine).
let RECOMMENDED_RANK = new Map();
let originalQueue = [];
let queue = [];
let currentIndex = 0;
let loopMode = 0;
let isShuffle = false;
let currentPlaylistId = null;
let currentSection = 'accueil';
// Le masquage de genres par utilisateur a ete retire : il cachait durablement
// des morceaux sans que ce soit visible depuis l'ecran ou ils manquaient, et la
// saisie libre du genre a l'import (voir templates/modals.php) rend le filtrage
// par liste fermee sans objet. La cle localStorage 'hiddenGenres' est nettoyee
// une fois au chargement pour ne pas laisser trainer un reglage devenu inerte.
try { localStorage.removeItem('hiddenGenres'); } catch (e) { /* stockage indisponible : sans effet */ }
