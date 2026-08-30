<?php
/**
 * Recherche côté serveur, paginée.
 *
 * AVANT : la recherche s'exécutait entièrement dans le navigateur, sur
 * ALL_MUSIC_DATA — la bibliothèque complète, sérialisée dans le HTML à chaque
 * chargement de page. Sur une petite instance c'est invisible ; le jour où la
 * bibliothèque grossit, c'est toute la table `tracks` qui traverse le réseau
 * avant que la page ne s'affiche, et chaque frappe reparcourt le tableau entier.
 *
 * MAINTENANT : un vrai endpoint, qui ne renvoie qu'une page de résultats. Le
 * navigateur ne reçoit plus que ce qu'il affiche.
 *
 * Trois catégories en une seule requête HTTP (titres paginés, artistes et albums
 * agrégés), parce que l'écran de résultats les affiche ensemble : les séparer en
 * trois appels tripleraient les allers-retours pour un rendu simultané.
 */

switch ($action) {
    case 'search':
        // Authentifié : le contenu de la bibliothèque n'a pas à être interrogeable
        // sans compte, contrairement à action=list qui est un contrat public déjà
        // établi avec l'app Android et qu'on ne touche pas.
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $userId = (int) $auth['id'];

        $q = trim((string) ($_GET['q'] ?? ''));
        // Bornes : le client demande 30, mais l'endpoint ne se fie jamais à ce
        // qu'on lui envoie -- un limit=999999 rendrait la pagination inutile.
        $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 30)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $sort   = (string) ($_GET['sort'] ?? 'relevance');

        if ($q === '') {
            echo json_encode([
                'status' => 'success', 'q' => '',
                'tracks' => ['items' => [], 'total' => 0, 'offset' => 0, 'limit' => $limit, 'has_more' => false],
                'artists' => [], 'albums' => [],
            ]);
            exit;
        }

        // `%` et `_` sont des jokers LIKE : sans échappement, chercher "100%" ou
        // "a_b" retournerait n'importe quoi. On échappe avec \ et on le déclare
        // explicitement à SQLite (ESCAPE), qui n'a pas de caractère par défaut.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $like    = '%' . mb_strtolower($escaped) . '%';
        $prefix  = mb_strtolower($escaped) . '%';
        $exact   = mb_strtolower($q);

        // LOWER() des deux côtés : LIKE de SQLite n'ignore la casse que pour
        // l'ASCII, et seulement si on ne lui donne pas déjà des minuscules. Les
        // caractères accentués restent sensibles à la casse (limite connue de
        // SQLite sans extension ICU) -- « Éclat » ne se trouve donc pas en tapant
        // « éclat » majuscule/minuscule mélangés sur la première lettre accentuée.
        $where = "(LOWER(tracks.title) LIKE :like ESCAPE '\\'
                OR LOWER(tracks.artist) LIKE :like ESCAPE '\\'
                OR LOWER(COALESCE(tracks.album, '')) LIKE :like ESCAPE '\\'
                OR LOWER(COALESCE(tracks.genre, '')) LIKE :like ESCAPE '\\')";

        // Pertinence : un titre qui correspond exactement passe devant un titre qui
        // commence par le terme, lui-même devant une correspondance au milieu, puis
        // artiste, puis album/genre. Le tri client précédent ne faisait rien de tel
        // -- il rangeait les résultats comme la bibliothèque (recommandé/populaire),
        // donc le morceau dont on tapait le titre exact pouvait arriver en 30e.
        $relevance = "CASE
                WHEN LOWER(tracks.title) = :exact THEN 0
                WHEN LOWER(tracks.title) LIKE :prefix ESCAPE '\\' THEN 1
                WHEN LOWER(tracks.title) LIKE :like ESCAPE '\\' THEN 2
                WHEN LOWER(tracks.artist) LIKE :like ESCAPE '\\' THEN 3
                WHEN LOWER(COALESCE(tracks.album, '')) LIKE :like ESCAPE '\\' THEN 4
                ELSE 5
            END";

        // Les modes correspondent un à un à ceux du sélecteur de tri de l'interface
        // (voir compareTracksBySort() dans js/library.js), pour que changer le tri
        // pendant une recherche donne le même ordre qu'ailleurs dans l'app.
        $orderBy = [
            'popular'    => 'tracks.play_count DESC, tracks.id DESC',
            'date_desc'  => 'tracks.id DESC',
            'date_asc'   => 'tracks.id ASC',
            'alpha_asc'  => 'tracks.title COLLATE NOCASE ASC',
            'alpha_desc' => 'tracks.title COLLATE NOCASE DESC',
            'artist'     => 'tracks.artist COLLATE NOCASE ASC, tracks.title COLLATE NOCASE ASC',
        ][$sort] ?? ($relevance . ', tracks.play_count DESC, tracks.id DESC');

        $bind = [':like' => $like, ':prefix' => $prefix, ':exact' => $exact];

        $countStmt = $db->prepare("SELECT COUNT(*) FROM tracks WHERE $where");
        // Le COUNT n'utilise pas :prefix/:exact (absents de $where) : PDO refuse
        // les paramètres nommés non utilisés, on ne lui passe donc que :like.
        $countStmt->execute([':like' => $like]);
        $total = (int) $countStmt->fetchColumn();

        // Mêmes colonnes que la sérialisation faite par index.php ($all_tracks) :
        // les résultats alimentent exactement les mêmes composants de ligne côté
        // client, ils doivent avoir la même forme.
        $sql = "SELECT tracks.*, users.username as uploader_name,
                       (SELECT COUNT(*) FROM likes WHERE likes.track_id = tracks.id) as like_count,
                       (SELECT COUNT(*) FROM likes WHERE likes.track_id = tracks.id AND likes.user_id = :uid) as is_liked
                FROM tracks JOIN users ON tracks.uploader_id = users.id
                WHERE $where
                ORDER BY $orderBy
                LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($sql);
        foreach ($bind as $k => $v) {
            // Les paramètres de pertinence ne sont liés que si la requête les contient
            // vraiment : en tri explicite (popular, alpha...), $relevance n'est pas dans
            // l'ORDER BY et PDO échouerait sur un paramètre inconnu.
            if (strpos($sql, $k) !== false) $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Paroles : parfois plusieurs kilo-octets par piste, jamais utilisées par
        // une liste de résultats (le lecteur les recharge lui-même via get_lyrics).
        foreach ($tracks as &$t) {
            unset($t['lyrics_synced'], $t['lyrics_plain'], $t['lyrics_checked_at']);
        }
        unset($t);

        // --- Artistes et albums ---------------------------------------------
        // Agrégés en PHP et non en SQL : un champ `artist` peut contenir plusieurs
        // noms ("A & B", "A, B"), que seul split_artist_names() sait découper --
        // un GROUP BY artist rangerait "A & B" comme un artiste à part entière.
        // Borné à AGG_SCAN_MAX lignes : au-delà, les 12 entrées affichées ne
        // changeraient plus, et on ne veut pas charger la table entière pour ça.
        $AGG_SCAN_MAX = 2000;
        $aggStmt = $db->prepare(
            "SELECT id, artist, album, cover FROM tracks
             WHERE LOWER(tracks.artist) LIKE :like ESCAPE '\\'
                OR LOWER(COALESCE(tracks.album, '')) LIKE :like ESCAPE '\\'
             ORDER BY tracks.play_count DESC, tracks.id DESC
             LIMIT $AGG_SCAN_MAX"
        );
        $aggStmt->execute([':like' => $like]);

        $needle = mb_strtolower($q);
        $artistMap = [];
        $albumMap = [];
        foreach ($aggStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (split_artist_names($row['artist']) as $name) {
                if (mb_strpos(mb_strtolower($name), $needle) === false) continue;
                $key = mb_strtolower($name);
                if (!isset($artistMap[$key])) $artistMap[$key] = ['name' => $name, 'count' => 0, 'cover' => $row['cover'], 'top_id' => (int) $row['id']];
                $artistMap[$key]['count']++;
                if ((int) $row['id'] > $artistMap[$key]['top_id']) {
                    $artistMap[$key]['top_id'] = (int) $row['id'];
                    $artistMap[$key]['cover'] = $row['cover'];
                }
            }
            $album = trim((string) ($row['album'] ?? ''));
            if ($album !== '' && mb_strpos(mb_strtolower($album), $needle) !== false) {
                $key = mb_strtolower($album);
                if (!isset($albumMap[$key])) $albumMap[$key] = ['name' => $album, 'count' => 0, 'cover' => $row['cover'], 'top_id' => (int) $row['id']];
                $albumMap[$key]['count']++;
                if ((int) $row['id'] > $albumMap[$key]['top_id']) {
                    $albumMap[$key]['top_id'] = (int) $row['id'];
                    $albumMap[$key]['cover'] = $row['cover'];
                }
            }
        }
        $byCount = function ($a, $b) { return $b['count'] <=> $a['count']; };
        usort($artistMap, $byCount);
        usort($albumMap, $byCount);

        echo json_encode([
            'status'  => 'success',
            'q'       => $q,
            'tracks'  => [
                'items'    => $tracks,
                'total'    => $total,
                'offset'   => $offset,
                'limit'    => $limit,
                'has_more' => ($offset + count($tracks)) < $total,
            ],
            'artists' => array_slice(array_values($artistMap), 0, 12),
            'albums'  => array_slice(array_values($albumMap), 0, 12),
        ], JSON_UNESCAPED_UNICODE);
        break;
}
