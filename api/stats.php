<?php
/**
 * Statistiques d'écoute personnelles.
 *
 * Tout est calculé à partir de listen_events, table déjà alimentée par
 * report_listen mais jusqu'ici utilisée uniquement pour les recommandations :
 * l'utilisateur n'avait aucun moyen de voir ce qu'il écoutait réellement.
 *
 * Toutes les requêtes sont bornées par user_id : ce sont les statistiques du
 * compte connecté, jamais un agrégat de tous les utilisateurs du serveur.
 */
switch ($action) {
    case 'stats':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $uid = $auth['id'];

        // Fenêtre d'analyse en jours : 30 par défaut, 0 = depuis toujours.
        $days = filter_var($_GET['days'] ?? 30, FILTER_VALIDATE_INT);
        if ($days === false || $days < 0) $days = 30;
        $since = $days > 0 ? time() - ($days * 86400) : 0;

        // Seuil de 10s : identique à celui qui décide d'incrémenter play_count
        // (voir report_listen). Sans lui, chaque morceau simplement survolé en
        // zappant compterait comme une écoute et fausserait tous les classements.
        $MIN_SECONDS = 10;

        $where = "le.user_id = ? AND le.listened_seconds >= ? AND le.created_at >= ?";
        $params = [$uid, $MIN_SECONDS, $since];

        // --- Totaux ---
        $totalStmt = $db->prepare(
            "SELECT COUNT(*) AS plays,
                    COALESCE(SUM(le.listened_seconds), 0) AS seconds,
                    COUNT(DISTINCT le.track_id) AS distinct_tracks
             FROM listen_events le WHERE $where"
        );
        $totalStmt->execute($params);
        $totals = $totalStmt->fetch(PDO::FETCH_ASSOC) ?: ['plays' => 0, 'seconds' => 0, 'distinct_tracks' => 0];

        // --- Jours d'activité distincts ---
        // strftime plutôt qu'un GROUP BY sur created_at/86400 : ce dernier
        // découpe les journées sur UTC minuit, donc une écoute de 23h locale
        // tombait dans le jour suivant.
        $daysStmt = $db->prepare(
            "SELECT COUNT(DISTINCT date(le.created_at, 'unixepoch', 'localtime')) AS active_days
             FROM listen_events le WHERE $where"
        );
        $daysStmt->execute($params);
        $activeDays = (int) $daysStmt->fetchColumn();

        // --- Top pistes ---
        $topTracksStmt = $db->prepare(
            "SELECT t.id, t.title, t.artist, t.album, t.cover,
                    COUNT(*) AS plays, SUM(le.listened_seconds) AS seconds
             FROM listen_events le JOIN tracks t ON t.id = le.track_id
             WHERE $where
             GROUP BY t.id ORDER BY plays DESC, seconds DESC LIMIT 10"
        );
        $topTracksStmt->execute($params);
        $topTracks = $topTracksStmt->fetchAll(PDO::FETCH_ASSOC);

        // --- Top genres ---
        // Une piste peut porter plusieurs genres ("Phonk, Nightcore") : comme
        // pour les artistes, SQL ne sait pas découper la chaîne, l'agrégation se
        // fait donc en PHP avec split_genres() — le même découpage que côté
        // client, pour que les deux comptages concordent.
        $genreRowsStmt = $db->prepare(
            "SELECT t.genre, COUNT(*) AS plays, SUM(le.listened_seconds) AS seconds
             FROM listen_events le JOIN tracks t ON t.id = le.track_id
             WHERE $where GROUP BY t.genre"
        );
        $genreRowsStmt->execute($params);

        $genreTotals = [];
        foreach ($genreRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names = split_genres($row['genre']);
            if (!$names) $names = ['Autre'];
            foreach ($names as $name) {
                $key = mb_strtolower($name);
                if (!isset($genreTotals[$key])) {
                    $genreTotals[$key] = ['genre' => $name, 'plays' => 0, 'seconds' => 0];
                }
                $genreTotals[$key]['plays'] += (int) $row['plays'];
                $genreTotals[$key]['seconds'] += (int) $row['seconds'];
            }
        }
        usort($genreTotals, fn($a, $b) => $b['plays'] <=> $a['plays']);
        $topGenres = array_slice(array_values($genreTotals), 0, 8);

        // --- Répartition par heure de la journée ---
        // Renvoyée en 24 créneaux toujours présents (même à zéro) : un histogramme
        // à trous serait illisible et obligerait le client à combler lui-même.
        $hourStmt = $db->prepare(
            "SELECT CAST(strftime('%H', le.created_at, 'unixepoch', 'localtime') AS INTEGER) AS hour,
                    COUNT(*) AS plays
             FROM listen_events le WHERE $where GROUP BY hour"
        );
        $hourStmt->execute($params);
        $byHour = array_fill(0, 24, 0);
        foreach ($hourStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byHour[(int) $r['hour']] = (int) $r['plays'];
        }

        // --- Artistes ---
        // Le champ artiste est une chaîne libre pouvant contenir plusieurs noms
        // ("A & B", "A feat. B") : SQL ne sait pas la découper. On agrège donc en
        // PHP avec les mêmes séparateurs que splitArtistNames() côté client, pour
        // que les deux comptages concordent.
        $artistRowsStmt = $db->prepare(
            "SELECT t.artist, COUNT(*) AS plays, SUM(le.listened_seconds) AS seconds
             FROM listen_events le JOIN tracks t ON t.id = le.track_id
             WHERE $where GROUP BY t.artist"
        );
        $artistRowsStmt->execute($params);

        $artistTotals = [];
        foreach ($artistRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (split_artist_names($row['artist']) as $name) {
                $key = mb_strtolower($name);
                if (!isset($artistTotals[$key])) {
                    $artistTotals[$key] = ['name' => $name, 'plays' => 0, 'seconds' => 0];
                }
                $artistTotals[$key]['plays'] += (int) $row['plays'];
                $artistTotals[$key]['seconds'] += (int) $row['seconds'];
            }
        }
        usort($artistTotals, fn($a, $b) => $b['plays'] <=> $a['plays']);
        $topArtists = array_slice(array_values($artistTotals), 0, 10);

        echo json_encode([
            'status' => 'success',
            'days' => $days,
            'totals' => [
                'plays' => (int) $totals['plays'],
                'seconds' => (int) $totals['seconds'],
                'distinct_tracks' => (int) $totals['distinct_tracks'],
                'active_days' => $activeDays,
            ],
            'top_tracks' => $topTracks,
            'top_artists' => $topArtists,
            'top_genres' => $topGenres,
            'by_hour' => $byHour,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // Classement du serveur : tous comptes confondus, sur une fenetre glissante
    // de 7 jours. C'est le seul endpoint de ce fichier qui ne borne PAS par
    // user_id -- d'ou son action separee plutot qu'un parametre de `stats`, pour
    // qu'aucune requete personnelle ne puisse devenir globale par accident.
    //
    // Vie privee : uniquement des agregats. On expose combien de comptes
    // distincts ont ecoute un morceau, jamais lesquels -- le classement dit ce
    // qui tourne sur le serveur, pas qui ecoute quoi.
    case 'server_top':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        // Meme seuil de 10s que report_listen et que les statistiques personnelles :
        // sans lui, zapper vingt morceaux d'affilee ferait un classement.
        $MIN_SECONDS = 10;
        $since = time() - (7 * 86400);

        $stmt = $db->prepare(
            "SELECT t.id, t.title, t.artist, t.album, t.cover,
                    COUNT(*) AS plays,
                    COUNT(DISTINCT le.user_id) AS listeners
             FROM listen_events le JOIN tracks t ON t.id = le.track_id
             WHERE le.listened_seconds >= ? AND le.created_at >= ?
             GROUP BY t.id
             ORDER BY plays DESC, listeners DESC, t.id DESC
             LIMIT 20"
        );
        $stmt->execute([$MIN_SECONDS, $since]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['plays'] = (int) $r['plays'];
            $r['listeners'] = (int) $r['listeners'];
        }
        unset($r);

        echo json_encode([
            'status' => 'success',
            'since' => $since,
            'tracks' => $rows,
        ], JSON_UNESCAPED_UNICODE);
        break;
}
