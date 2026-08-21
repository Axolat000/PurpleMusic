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
