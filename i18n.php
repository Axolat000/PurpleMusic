<?php
// --- I18N : TABLE DE TRADUCTION (FR par défaut, EN/ES/DE) ---
// Léger, sans dépendance : un tableau associatif par langue + un helper t().
// Ne traduit QUE le "chrome" de l'UI (labels, boutons, messages système) :
// jamais les titres/artistes/genres/noms de playlists/pseudos (contenu utilisateur).

$I18N = [
    'fr' => [
        // Connexion / inscription
        'login_username_placeholder' => 'Utilisateur',
        'login_password_placeholder' => 'Mot de passe',
        'login_btn' => 'Connexion',
        'login_register_btn' => 'Créer un compte',

        // Header / navigation
        'nav_library' => 'Bibliothèque',
        'nav_playlists' => 'Playlists',
        'admin_badge' => 'ADMIN',
        'nav_admin_panel' => '⚙️ Panel Admin',
        'btn_lyrics' => 'Paroles',
        'btn_queue' => 'File',
        'btn_create_playlist' => '+ Mix',
        'btn_upload' => 'Upload',
        'btn_settings' => 'Paramètres',
        'btn_logout' => 'Sortir',

        // Panel admin
        'admin_panel_title' => 'Configuration Système',
        'admin_section_general' => 'Général',
        'admin_section_theme' => 'Thème Visuel (Couleurs)',
        'admin_section_media' => 'Remplacement Assets Médias',
        'admin_section_genres' => 'Gestionnaire des Genres',
        'admin_app_name_label' => "Nom de l'application",
        'admin_color_bg' => 'Arrière-plan Global',
        'admin_color_panel' => 'Panneaux & Cards',
        'admin_color_primary' => 'Couleur Primaire',
        'admin_color_accent' => 'Couleur Accent',
        'admin_color_text' => 'Texte Principal',
        'admin_color_text_muted' => 'Texte Sombre/Muted',
        'admin_color_border' => 'Bordures & Lignes',
        'admin_color_search_bg' => 'Fond Barre Recherche',
        'admin_color_fp_gradient_1' => 'Gradient Full Player 1',
        'admin_color_fp_gradient_2' => 'Gradient Full Player 2',
        'admin_header_bg_label' => 'Fond de la barre du haut (Header Desktop & Mobile)',
        'admin_player_bg_label' => 'Fond du Mini-Lecteur (Barre du bas)',
        'admin_mobnav_bg_label' => 'Fond de la barre de navigation Téléphone',
        'admin_favicon_label' => 'Nouveau Favicon (.png / .ico)',
        'admin_default_cover_label' => 'Nouvelle Cover par défaut (.png)',
        'admin_new_genre_label' => 'Créer un genre personnalisé',
        'admin_new_genre_placeholder' => 'ex: Phonk, Ambient...',
        'admin_active_genres_label' => 'Genres actifs (cliquer pour supprimer) :',
        'confirm_delete_genre' => 'Détruire ce genre musical ?',
        'btn_cancel' => 'Annuler',
        'btn_save' => 'Enregistrer',

        // File d'attente
        'queue_waiting_empty' => 'Aucune musique en attente...',
        'queue_title' => 'Musiques à suivre',
        'queue_close' => '▼ Fermer la file',
        'queue_empty' => 'File vide...',

        // Paroles
        'lyrics_close' => '▼ Fermer les paroles',
        'lyrics_loading' => 'Chargement des paroles...',
        'lyrics_prompt' => 'Lancez une musique pour voir ses paroles.',
        'lyrics_not_found' => 'Paroles introuvables pour ce titre.',
        'lyrics_back_to_live' => '↓ Revenir au direct',

        // Bibliothèque / accueil
        'section_all_tracks' => 'Toutes les pistes',
        'search_placeholder' => 'Rechercher titre, artiste...',
        'sort_popular' => 'Les plus écoutés',
        'sort_recent' => 'Ajouts récents',
        'sort_oldest' => 'Ajouts anciens',
        'sort_alpha_asc' => 'Nom (A-Z)',
        'sort_alpha_desc' => 'Nom (Z-A)',
        'sort_artist' => 'Par Artiste',
        'tooltip_sort' => 'Trier',
        'home_your_mixes' => 'Tes Mixs',
        'no_tracks_found' => 'Aucune piste trouvée.',

        // Playlists
        'created_by' => 'Créé par',
        'btn_view_mix' => '▶ Voir le mix',
        'btn_edit' => 'Editer',
        'btn_delete_short' => 'Suppr',
        'confirm_delete_generic' => 'Supprimer ?',
        'confirm_delete_playlist' => 'Supprimer ce mix ?',

        // Détail playlist
        'btn_back_to_playlists' => '← Retour aux Mixs',
        'btn_play_all' => '▶ Tout lire',
        'loading_generic' => 'Chargement...',
        'playlist_empty' => 'Ce mix est vide.',

        // Nav mobile
        'mob_nav_library' => 'Biblio',
        'mob_nav_mixes' => 'Mixs',
        'mob_nav_admin' => 'Admin',

        // Modale paramètres
        'settings_title' => 'Filtres & Paramètres',
        'settings_theme_label' => 'Thème visuel',
        'theme_violet_default' => 'Violet (par défaut)',
        'settings_volume_label' => 'Volume',
        'settings_hide_intro_pre' => 'Cochez les genres que vous souhaitez',
        'settings_hide_word' => 'masquer',
        'btn_close' => 'Fermer',
        'lang_switcher_label' => 'Langue',

        // Modale upload
        'upload_title_placeholder' => 'Titre (Optionnel - sinon détecté auto)',
        'upload_artist_placeholder' => 'Artiste (Optionnel - sinon détecté auto)',
        'select_genre_label' => 'Sélectionnez le genre',
        'audio_file_label' => 'Fichier Audio (MP3/WAV/FLAC)',
        'cover_file_label' => 'Cover (Laissez vide pour auto-detect)',
        'btn_publish' => 'Publier',

        // Modale édition piste
        'edit_track_title' => 'Modifier Piste',
        'title_placeholder' => 'Titre',
        'artist_placeholder' => 'Artiste',
        'edit_genre_label' => 'Modifier le genre',
        'change_cover_label' => 'Changer la cover',

        // Modale playlist
        'playlist_new_title' => 'Nouvelle Playlist',
        'playlist_edit_title' => 'Modifier Playlist',
        'playlist_name_placeholder' => 'Nom du mix',
        'playlist_search_placeholder' => '🔍 Rechercher une musique...',
        'select_tracks_label' => 'Sélectionnez les titres :',
        'selected_count' => '{n} sélectionné(s)',

        // Lecteur
        'player_ready' => 'Prêt à écouter',
        'player_stopped' => 'Arrêté',
        'tooltip_shuffle' => 'Aléatoire',
        'tooltip_prev' => 'Précédent',
        'tooltip_play' => 'Lecture',
        'tooltip_next' => 'Suivant',
        'tooltip_loop' => 'Boucle',
        'now_playing_label' => 'LECTURE EN COURS',

        // Confirmation / toast
        'btn_confirm' => 'Confirmer',
        'toast_no_music' => 'Aucune musique disponible.',

        // Erreurs (auth.php / actions.php / index.php ajax)
        'err_csrf_invalid' => 'CSRF Invalide.',
        'err_csrf' => 'Erreur CSRF',
        'err_rate_limit_upload' => 'Rate limit: Patientez 15 secondes.',
        'err_audio_too_large' => 'Fichier audio trop volumineux (100 Mo max).',
        'err_audio_invalid_format' => 'Format audio invalide ou non autorisé.',
        'err_image_too_large' => 'Image de couverture trop volumineuse (5 Mo max).',
        'err_please_wait' => 'Veuillez patienter.',
        'err_username_taken' => 'Nom déjà pris.',
        'err_invalid_credentials' => 'Identifiants incorrects.',
        'err_db_prefix' => 'Erreur BDD : ',
        'err_not_authenticated' => 'Non authentifié',
        'err_invalid_track_id' => 'ID de piste invalide',
        'err_track_not_found' => 'Piste introuvable',
    ],

    'en' => [
        'login_username_placeholder' => 'Username',
        'login_password_placeholder' => 'Password',
        'login_btn' => 'Log In',
        'login_register_btn' => 'Create an account',

        'nav_library' => 'Library',
        'nav_playlists' => 'Playlists',
        'admin_badge' => 'ADMIN',
        'nav_admin_panel' => '⚙️ Admin Panel',
        'btn_lyrics' => 'Lyrics',
        'btn_queue' => 'Queue',
        'btn_create_playlist' => '+ Mix',
        'btn_upload' => 'Upload',
        'btn_settings' => 'Settings',
        'btn_logout' => 'Log Out',

        'admin_panel_title' => 'System Configuration',
        'admin_section_general' => 'General',
        'admin_section_theme' => 'Visual Theme (Colors)',
        'admin_section_media' => 'Media Asset Replacement',
        'admin_section_genres' => 'Genre Manager',
        'admin_app_name_label' => 'Application name',
        'admin_color_bg' => 'Global Background',
        'admin_color_panel' => 'Panels & Cards',
        'admin_color_primary' => 'Primary Color',
        'admin_color_accent' => 'Accent Color',
        'admin_color_text' => 'Main Text',
        'admin_color_text_muted' => 'Muted Text',
        'admin_color_border' => 'Borders & Lines',
        'admin_color_search_bg' => 'Search Bar Background',
        'admin_color_fp_gradient_1' => 'Full Player Gradient 1',
        'admin_color_fp_gradient_2' => 'Full Player Gradient 2',
        'admin_header_bg_label' => 'Top bar background (Desktop & Mobile header)',
        'admin_player_bg_label' => 'Mini player background (bottom bar)',
        'admin_mobnav_bg_label' => 'Mobile navigation bar background',
        'admin_favicon_label' => 'New Favicon (.png / .ico)',
        'admin_default_cover_label' => 'New Default Cover (.png)',
        'admin_new_genre_label' => 'Create a custom genre',
        'admin_new_genre_placeholder' => 'e.g. Phonk, Ambient...',
        'admin_active_genres_label' => 'Active genres (click to remove):',
        'confirm_delete_genre' => 'Delete this genre?',
        'btn_cancel' => 'Cancel',
        'btn_save' => 'Save',

        'queue_waiting_empty' => 'No tracks queued...',
        'queue_title' => 'Up Next',
        'queue_close' => '▼ Close queue',
        'queue_empty' => 'Queue empty...',

        'lyrics_close' => '▼ Close lyrics',
        'lyrics_loading' => 'Loading lyrics...',
        'lyrics_prompt' => 'Play a track to see its lyrics.',
        'lyrics_not_found' => 'No lyrics found for this track.',
        'lyrics_back_to_live' => '↓ Back to live',

        'section_all_tracks' => 'All Tracks',
        'search_placeholder' => 'Search title, artist...',
        'sort_popular' => 'Most Played',
        'sort_recent' => 'Recently Added',
        'sort_oldest' => 'Oldest First',
        'sort_alpha_asc' => 'Name (A-Z)',
        'sort_alpha_desc' => 'Name (Z-A)',
        'sort_artist' => 'By Artist',
        'tooltip_sort' => 'Sort',
        'home_your_mixes' => 'Your Mixes',
        'no_tracks_found' => 'No tracks found.',

        'created_by' => 'Created by',
        'btn_view_mix' => '▶ View mix',
        'btn_edit' => 'Edit',
        'btn_delete_short' => 'Delete',
        'confirm_delete_generic' => 'Delete?',
        'confirm_delete_playlist' => 'Delete this mix?',

        'btn_back_to_playlists' => '← Back to Mixes',
        'btn_play_all' => '▶ Play All',
        'loading_generic' => 'Loading...',
        'playlist_empty' => 'This mix is empty.',

        'mob_nav_library' => 'Library',
        'mob_nav_mixes' => 'Mixes',
        'mob_nav_admin' => 'Admin',

        'settings_title' => 'Filters & Settings',
        'settings_theme_label' => 'Visual theme',
        'theme_violet_default' => 'Violet (default)',
        'settings_volume_label' => 'Volume',
        'settings_hide_intro_pre' => 'Check the genres you want to',
        'settings_hide_word' => 'hide',
        'btn_close' => 'Close',
        'lang_switcher_label' => 'Language',

        'upload_title_placeholder' => 'Title (Optional — auto-detected otherwise)',
        'upload_artist_placeholder' => 'Artist (Optional — auto-detected otherwise)',
        'select_genre_label' => 'Select genre',
        'audio_file_label' => 'Audio File (MP3/WAV/FLAC)',
        'cover_file_label' => 'Cover (Leave empty for auto-detect)',
        'btn_publish' => 'Publish',

        'edit_track_title' => 'Edit Track',
        'title_placeholder' => 'Title',
        'artist_placeholder' => 'Artist',
        'edit_genre_label' => 'Edit genre',
        'change_cover_label' => 'Change cover',

        'playlist_new_title' => 'New Playlist',
        'playlist_edit_title' => 'Edit Playlist',
        'playlist_name_placeholder' => 'Mix name',
        'playlist_search_placeholder' => '🔍 Search for a track...',
        'select_tracks_label' => 'Select tracks:',
        'selected_count' => '{n} selected',

        'player_ready' => 'Ready to play',
        'player_stopped' => 'Stopped',
        'tooltip_shuffle' => 'Shuffle',
        'tooltip_prev' => 'Previous',
        'tooltip_play' => 'Play',
        'tooltip_next' => 'Next',
        'tooltip_loop' => 'Repeat',
        'now_playing_label' => 'NOW PLAYING',

        'btn_confirm' => 'Confirm',
        'toast_no_music' => 'No music available.',

        'err_csrf_invalid' => 'Invalid CSRF token.',
        'err_csrf' => 'CSRF Error',
        'err_rate_limit_upload' => 'Rate limit: please wait 15 seconds.',
        'err_audio_too_large' => 'Audio file too large (100 MB max).',
        'err_audio_invalid_format' => 'Invalid or unsupported audio format.',
        'err_image_too_large' => 'Cover image too large (5 MB max).',
        'err_please_wait' => 'Please wait.',
        'err_username_taken' => 'Username already taken.',
        'err_invalid_credentials' => 'Incorrect credentials.',
        'err_db_prefix' => 'Database error: ',
        'err_not_authenticated' => 'Not authenticated',
        'err_invalid_track_id' => 'Invalid track ID',
        'err_track_not_found' => 'Track not found',
    ],

    'es' => [
        'login_username_placeholder' => 'Usuario',
        'login_password_placeholder' => 'Contraseña',
        'login_btn' => 'Iniciar sesión',
        'login_register_btn' => 'Crear una cuenta',

        'nav_library' => 'Biblioteca',
        'nav_playlists' => 'Playlists',
        'admin_badge' => 'ADMIN',
        'nav_admin_panel' => '⚙️ Panel de administración',
        'btn_lyrics' => 'Letra',
        'btn_queue' => 'Cola',
        'btn_create_playlist' => '+ Mix',
        'btn_upload' => 'Subir',
        'btn_settings' => 'Ajustes',
        'btn_logout' => 'Salir',

        'admin_panel_title' => 'Configuración del sistema',
        'admin_section_general' => 'General',
        'admin_section_theme' => 'Tema visual (Colores)',
        'admin_section_media' => 'Reemplazo de recursos multimedia',
        'admin_section_genres' => 'Gestor de géneros',
        'admin_app_name_label' => 'Nombre de la aplicación',
        'admin_color_bg' => 'Fondo global',
        'admin_color_panel' => 'Paneles y tarjetas',
        'admin_color_primary' => 'Color primario',
        'admin_color_accent' => 'Color de acento',
        'admin_color_text' => 'Texto principal',
        'admin_color_text_muted' => 'Texto atenuado',
        'admin_color_border' => 'Bordes y líneas',
        'admin_color_search_bg' => 'Fondo de la barra de búsqueda',
        'admin_color_fp_gradient_1' => 'Degradado del reproductor completo 1',
        'admin_color_fp_gradient_2' => 'Degradado del reproductor completo 2',
        'admin_header_bg_label' => 'Fondo de la barra superior (encabezado escritorio y móvil)',
        'admin_player_bg_label' => 'Fondo del mini reproductor (barra inferior)',
        'admin_mobnav_bg_label' => 'Fondo de la barra de navegación móvil',
        'admin_favicon_label' => 'Nuevo favicon (.png / .ico)',
        'admin_default_cover_label' => 'Nueva portada predeterminada (.png)',
        'admin_new_genre_label' => 'Crear un género personalizado',
        'admin_new_genre_placeholder' => 'ej: Phonk, Ambient...',
        'admin_active_genres_label' => 'Géneros activos (haz clic para eliminar):',
        'confirm_delete_genre' => '¿Eliminar este género?',
        'btn_cancel' => 'Cancelar',
        'btn_save' => 'Guardar',

        'queue_waiting_empty' => 'No hay música en espera...',
        'queue_title' => 'A continuación',
        'queue_close' => '▼ Cerrar la cola',
        'queue_empty' => 'Cola vacía...',

        'lyrics_close' => '▼ Cerrar letra',
        'lyrics_loading' => 'Cargando la letra...',
        'lyrics_prompt' => 'Reproduce una canción para ver su letra.',
        'lyrics_not_found' => 'No se encontró la letra de esta canción.',
        'lyrics_back_to_live' => '↓ Volver al directo',

        'section_all_tracks' => 'Todas las pistas',
        'search_placeholder' => 'Buscar título, artista...',
        'sort_popular' => 'Más escuchados',
        'sort_recent' => 'Añadidos recientemente',
        'sort_oldest' => 'Añadidos antiguos',
        'sort_alpha_asc' => 'Nombre (A-Z)',
        'sort_alpha_desc' => 'Nombre (Z-A)',
        'sort_artist' => 'Por artista',
        'tooltip_sort' => 'Ordenar',
        'home_your_mixes' => 'Tus mixes',
        'no_tracks_found' => 'No se encontraron pistas.',

        'created_by' => 'Creado por',
        'btn_view_mix' => '▶ Ver el mix',
        'btn_edit' => 'Editar',
        'btn_delete_short' => 'Borrar',
        'confirm_delete_generic' => '¿Eliminar?',
        'confirm_delete_playlist' => '¿Eliminar este mix?',

        'btn_back_to_playlists' => '← Volver a los mixes',
        'btn_play_all' => '▶ Reproducir todo',
        'loading_generic' => 'Cargando...',
        'playlist_empty' => 'Este mix está vacío.',

        'mob_nav_library' => 'Biblio',
        'mob_nav_mixes' => 'Mixes',
        'mob_nav_admin' => 'Admin',

        'settings_title' => 'Filtros y ajustes',
        'settings_theme_label' => 'Tema visual',
        'theme_violet_default' => 'Violeta (por defecto)',
        'settings_volume_label' => 'Volumen',
        'settings_hide_intro_pre' => 'Marca los géneros que deseas',
        'settings_hide_word' => 'ocultar',
        'btn_close' => 'Cerrar',
        'lang_switcher_label' => 'Idioma',

        'upload_title_placeholder' => 'Título (opcional; si no, se detecta automáticamente)',
        'upload_artist_placeholder' => 'Artista (opcional; si no, se detecta automáticamente)',
        'select_genre_label' => 'Selecciona el género',
        'audio_file_label' => 'Archivo de audio (MP3/WAV/FLAC)',
        'cover_file_label' => 'Portada (déjalo vacío para detección automática)',
        'btn_publish' => 'Publicar',

        'edit_track_title' => 'Editar pista',
        'title_placeholder' => 'Título',
        'artist_placeholder' => 'Artista',
        'edit_genre_label' => 'Editar género',
        'change_cover_label' => 'Cambiar portada',

        'playlist_new_title' => 'Nueva playlist',
        'playlist_edit_title' => 'Editar playlist',
        'playlist_name_placeholder' => 'Nombre del mix',
        'playlist_search_placeholder' => '🔍 Buscar una canción...',
        'select_tracks_label' => 'Selecciona las pistas:',
        'selected_count' => '{n} seleccionado(s)',

        'player_ready' => 'Listo para reproducir',
        'player_stopped' => 'Detenido',
        'tooltip_shuffle' => 'Aleatorio',
        'tooltip_prev' => 'Anterior',
        'tooltip_play' => 'Reproducir',
        'tooltip_next' => 'Siguiente',
        'tooltip_loop' => 'Repetir',
        'now_playing_label' => 'REPRODUCIENDO',

        'btn_confirm' => 'Confirmar',
        'toast_no_music' => 'No hay música disponible.',

        'err_csrf_invalid' => 'Token CSRF inválido.',
        'err_csrf' => 'Error CSRF',
        'err_rate_limit_upload' => 'Límite de frecuencia: espera 15 segundos.',
        'err_audio_too_large' => 'Archivo de audio demasiado grande (máx. 100 MB).',
        'err_audio_invalid_format' => 'Formato de audio no válido o no permitido.',
        'err_image_too_large' => 'Imagen de portada demasiado grande (máx. 5 MB).',
        'err_please_wait' => 'Por favor, espera.',
        'err_username_taken' => 'Nombre de usuario ya en uso.',
        'err_invalid_credentials' => 'Credenciales incorrectas.',
        'err_db_prefix' => 'Error de base de datos: ',
        'err_not_authenticated' => 'No autenticado',
        'err_invalid_track_id' => 'ID de pista no válido',
        'err_track_not_found' => 'Pista no encontrada',
    ],

    'de' => [
        'login_username_placeholder' => 'Benutzername',
        'login_password_placeholder' => 'Passwort',
        'login_btn' => 'Anmelden',
        'login_register_btn' => 'Konto erstellen',

        'nav_library' => 'Bibliothek',
        'nav_playlists' => 'Playlists',
        'admin_badge' => 'ADMIN',
        'nav_admin_panel' => '⚙️ Admin-Bereich',
        'btn_lyrics' => 'Songtext',
        'btn_queue' => 'Warteschlange',
        'btn_create_playlist' => '+ Mix',
        'btn_upload' => 'Hochladen',
        'btn_settings' => 'Einstellungen',
        'btn_logout' => 'Abmelden',

        'admin_panel_title' => 'Systemkonfiguration',
        'admin_section_general' => 'Allgemein',
        'admin_section_theme' => 'Visuelles Design (Farben)',
        'admin_section_media' => 'Medien-Assets ersetzen',
        'admin_section_genres' => 'Genre-Verwaltung',
        'admin_app_name_label' => 'Name der Anwendung',
        'admin_color_bg' => 'Globaler Hintergrund',
        'admin_color_panel' => 'Panels & Karten',
        'admin_color_primary' => 'Primärfarbe',
        'admin_color_accent' => 'Akzentfarbe',
        'admin_color_text' => 'Haupttext',
        'admin_color_text_muted' => 'Gedämpfter Text',
        'admin_color_border' => 'Rahmen & Linien',
        'admin_color_search_bg' => 'Hintergrund der Suchleiste',
        'admin_color_fp_gradient_1' => 'Vollbildplayer-Verlauf 1',
        'admin_color_fp_gradient_2' => 'Vollbildplayer-Verlauf 2',
        'admin_header_bg_label' => 'Hintergrund der oberen Leiste (Desktop- & Mobil-Header)',
        'admin_player_bg_label' => 'Hintergrund des Mini-Players (untere Leiste)',
        'admin_mobnav_bg_label' => 'Hintergrund der mobilen Navigationsleiste',
        'admin_favicon_label' => 'Neues Favicon (.png / .ico)',
        'admin_default_cover_label' => 'Neues Standard-Cover (.png)',
        'admin_new_genre_label' => 'Eigenes Genre erstellen',
        'admin_new_genre_placeholder' => 'z.B. Phonk, Ambient...',
        'admin_active_genres_label' => 'Aktive Genres (zum Entfernen anklicken):',
        'confirm_delete_genre' => 'Dieses Genre löschen?',
        'btn_cancel' => 'Abbrechen',
        'btn_save' => 'Speichern',

        'queue_waiting_empty' => 'Keine Musik in der Warteschlange...',
        'queue_title' => 'Als Nächstes',
        'queue_close' => '▼ Warteschlange schließen',
        'queue_empty' => 'Warteschlange leer...',

        'lyrics_close' => '▼ Songtext schließen',
        'lyrics_loading' => 'Songtext wird geladen...',
        'lyrics_prompt' => 'Starte einen Song, um den Songtext zu sehen.',
        'lyrics_not_found' => 'Kein Songtext für diesen Titel gefunden.',
        'lyrics_back_to_live' => '↓ Zurück zum Live-Text',

        'section_all_tracks' => 'Alle Titel',
        'search_placeholder' => 'Titel, Interpret suchen...',
        'sort_popular' => 'Meistgehört',
        'sort_recent' => 'Kürzlich hinzugefügt',
        'sort_oldest' => 'Älteste zuerst',
        'sort_alpha_asc' => 'Name (A-Z)',
        'sort_alpha_desc' => 'Name (Z-A)',
        'sort_artist' => 'Nach Interpret',
        'tooltip_sort' => 'Sortieren',
        'home_your_mixes' => 'Deine Mixe',
        'no_tracks_found' => 'Keine Titel gefunden.',

        'created_by' => 'Erstellt von',
        'btn_view_mix' => '▶ Mix ansehen',
        'btn_edit' => 'Bearbeiten',
        'btn_delete_short' => 'Löschen',
        'confirm_delete_generic' => 'Löschen?',
        'confirm_delete_playlist' => 'Diesen Mix löschen?',

        'btn_back_to_playlists' => '← Zurück zu den Mixen',
        'btn_play_all' => '▶ Alles abspielen',
        'loading_generic' => 'Wird geladen...',
        'playlist_empty' => 'Dieser Mix ist leer.',

        'mob_nav_library' => 'Biblio.',
        'mob_nav_mixes' => 'Mixe',
        'mob_nav_admin' => 'Admin',

        'settings_title' => 'Filter & Einstellungen',
        'settings_theme_label' => 'Visuelles Design',
        'theme_violet_default' => 'Violett (Standard)',
        'settings_volume_label' => 'Lautstärke',
        'settings_hide_intro_pre' => 'Wähle die Genres, die du',
        'settings_hide_word' => 'ausblenden möchtest',
        'btn_close' => 'Schließen',
        'lang_switcher_label' => 'Sprache',

        'upload_title_placeholder' => 'Titel (optional – wird sonst automatisch erkannt)',
        'upload_artist_placeholder' => 'Interpret (optional – wird sonst automatisch erkannt)',
        'select_genre_label' => 'Genre auswählen',
        'audio_file_label' => 'Audiodatei (MP3/WAV/FLAC)',
        'cover_file_label' => 'Cover (leer lassen für automatische Erkennung)',
        'btn_publish' => 'Veröffentlichen',

        'edit_track_title' => 'Titel bearbeiten',
        'title_placeholder' => 'Titel',
        'artist_placeholder' => 'Interpret',
        'edit_genre_label' => 'Genre ändern',
        'change_cover_label' => 'Cover ändern',

        'playlist_new_title' => 'Neue Playlist',
        'playlist_edit_title' => 'Playlist bearbeiten',
        'playlist_name_placeholder' => 'Name des Mix',
        'playlist_search_placeholder' => '🔍 Titel suchen...',
        'select_tracks_label' => 'Titel auswählen:',
        'selected_count' => '{n} ausgewählt',

        'player_ready' => 'Bereit zum Abspielen',
        'player_stopped' => 'Gestoppt',
        'tooltip_shuffle' => 'Zufallswiedergabe',
        'tooltip_prev' => 'Zurück',
        'tooltip_play' => 'Abspielen',
        'tooltip_next' => 'Weiter',
        'tooltip_loop' => 'Wiederholen',
        'now_playing_label' => 'WIRD ABGESPIELT',

        'btn_confirm' => 'Bestätigen',
        'toast_no_music' => 'Keine Musik verfügbar.',

        'err_csrf_invalid' => 'Ungültiges CSRF-Token.',
        'err_csrf' => 'CSRF-Fehler',
        'err_rate_limit_upload' => 'Ratenlimit: Bitte warte 15 Sekunden.',
        'err_audio_too_large' => 'Audiodatei zu groß (max. 100 MB).',
        'err_audio_invalid_format' => 'Ungültiges oder nicht erlaubtes Audioformat.',
        'err_image_too_large' => 'Cover-Bild zu groß (max. 5 MB).',
        'err_please_wait' => 'Bitte warten.',
        'err_username_taken' => 'Benutzername bereits vergeben.',
        'err_invalid_credentials' => 'Falsche Anmeldedaten.',
        'err_db_prefix' => 'Datenbankfehler: ',
        'err_not_authenticated' => 'Nicht authentifiziert',
        'err_invalid_track_id' => 'Ungültige Titel-ID',
        'err_track_not_found' => 'Titel nicht gefunden',
    ],
];

// --- Langue active : cookie "purpleMusicLang", défaut "fr" (pas de sniffing Accept-Language) ---
const I18N_SUPPORTED_LANGS = ['fr', 'en', 'es', 'de'];

$lang = $_COOKIE['purpleMusicLang'] ?? 'fr';
if (!in_array($lang, I18N_SUPPORTED_LANGS, true)) {
    $lang = 'fr';
}

/**
 * Traduit une clé dans la langue active (repli sur le FR puis sur la clé elle-même).
 * $vars permet une substitution simple de type {n} -> valeur (ex: "{n} sélectionné(s)").
 */
function t($key, $vars = []) {
    global $I18N, $lang;
    $str = $I18N[$lang][$key] ?? $I18N['fr'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace('{' . $k . '}', $v, $str);
    }
    return $str;
}

// --- Sous-ensemble exposé au JS : uniquement les clés utilisées par du HTML généré côté client ---
const I18N_CLIENT_KEYS = [
    'no_tracks_found',
    'confirm_delete_generic',
    'confirm_delete_playlist',
    'queue_empty',
    'toast_no_music',
    'playlist_new_title',
    'playlist_edit_title',
    'selected_count',
];

function i18n_client_table() {
    global $I18N;
    $out = [];
    foreach ($I18N as $lc => $strings) {
        $out[$lc] = [];
        foreach (I18N_CLIENT_KEYS as $k) {
            $out[$lc][$k] = $strings[$k] ?? $k;
        }
    }
    return $out;
}
