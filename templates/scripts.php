    <script>
        <?php // Passage des variables PHP au JavaScript. Émis pour les deux états (connecté / déconnecté) : Alpine.js
              // (store 'ui', T(), authForm()...) doit être disponible sur la page de connexion aussi, sinon le
              // x-data posé sur <body> y reste inerte. $all_tracks/$all_playlists sont chargés en base plus haut
              // dans tous les cas, donc aucun coût à les exposer même quand déconnecté (simplement inutilisés). ?>
        const ALL_MUSIC_DATA = <?php echo json_encode($all_tracks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const ALL_PLAYLISTS_DATA = <?php echo json_encode($all_playlists, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const CURRENT_USER_ID = <?php echo json_encode($user_id); ?>;
        const IS_ADMIN = <?php echo json_encode($is_admin); ?>;
        const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;
        const TERMS_ENABLED = <?php echo json_encode($terms_enabled); ?>;

        <?php // Modes de tri de la bibliotheque. Emis ici plutot qu'en dur cote JS : les
              // libelles sont traduits par PHP, et la liste sert a la fois au menu overlay
              // (templates/topbar.php) et au libelle affiche sur son declencheur. ?>
        const SORT_OPTIONS = <?php echo json_encode([
            ['value' => 'recommended', 'label' => t('sort_recommended')],
            ['value' => 'popular',     'label' => t('sort_popular')],
            ['value' => 'date_desc',   'label' => t('sort_recent')],
            ['value' => 'date_asc',    'label' => t('sort_oldest')],
            ['value' => 'alpha_asc',   'label' => t('sort_alpha_asc')],
            ['value' => 'alpha_desc',  'label' => t('sort_alpha_desc')],
            ['value' => 'artist',      'label' => t('sort_artist')],
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // --- I18N : langue active (cookie "purpleMusicLang", lu côté PHP) + table de traduction client ---
        const LANG = <?php echo json_encode($lang, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const I18N_CLIENT = <?php echo json_encode(i18n_client_table(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        // Change la langue active : persiste dans le cookie lu par PHP, puis recharge la page
        // (les chaînes rendues côté serveur nécessitent un rechargement complet, pas de rendu partiel côté client).
        function setLanguage(code) {
            document.cookie = 'purpleMusicLang=' + code + ';path=/;max-age=' + (365 * 24 * 60 * 60) + ';samesite=lax';
            window.location.reload();
        }
    </script>
