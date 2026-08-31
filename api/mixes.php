<?php
/**
 * Mix quotidiens : quelques sélections thématiques, refaites chaque jour.
 *
 * Un mix est ancré sur UN genre que la personne écoute réellement, pas sur un
 * découpage arbitraire du catalogue. On produit donc « ton mix Phonk », pas
 * « mix n°3 ».
 *
 * STABLES DANS LA JOURNÉE, DIFFÉRENTS LE LENDEMAIN. Le tirage est pseudo-aléatoire
 * mais DÉTERMINISTE : la graine combine l'identifiant du compte, la date du jour
 * et le genre. Recharger la page ne rebat donc pas les cartes -- un mix qui change
 * à chaque coup d'œil n'est pas un mix, c'est du bruit -- et le lendemain la
 * graine change d'elle-même, sans tâche planifiée ni colonne à stocker.
 *
 * Le hasard est pondéré, pas uniforme : le score d'affinité (genre écouté,
 * artiste connu, popularité amortie) décale le tirage, de sorte qu'un mix reste
 * majoritairement composé de choses plausibles tout en gardant de la surprise.
 */

// Nombre de mix proposés et taille de chacun.
const DAILY_MIX_COUNT = 4;
const DAILY_MIX_SIZE = 25;

/**
 * Générateur pseudo-aléatoire déterministe (xorshift 32 bits).
 *
 * mt_srand() aurait suffi, mais il partage son état avec tout le processus : un
 * appel à shuffle() ou rand() ailleurs dans la requête décalerait la suite et
 * ferait changer les mix sans raison. Ce générateur-ci n'appartient qu'à nous.
 */
function daily_mix_rng(int $seed): callable
{
    $state = $seed !== 0 ? $seed : 0x2545F491;
    return function () use (&$state): float {
        $state ^= ($state << 13) & 0xFFFFFFFF;
        $state ^= ($state >> 17);
        $state ^= ($state << 5) & 0xFFFFFFFF;
        $state &= 0xFFFFFFFF;
        return $state / 0xFFFFFFFF;
    };
}

switch ($action) {

    case 'daily_mixes':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $uid = (int) $auth['id'];

        // Graine du jour : même chaîne toute la journée, différente demain.
        $day = date('Y-m-d');

        // --- Genres réellement écoutés -------------------------------------
        // Découpés des deux côtés (voir build_recommendations) : sans ça une piste
        // "Phonk, Nightcore" ne compterait pour aucun des deux.
        $listened = $db->prepare(
            "SELECT t.genre AS g FROM listen_events le JOIN tracks t ON t.id = le.track_id
             WHERE le.user_id = ? AND le.listened_seconds >= 10"
        );
        $listened->execute([$uid]);
        $genreCounts = [];
        foreach ($listened->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach (split_genres($raw) as $g) {
                $k = mb_strtolower($g);
                if ($k === 'autre') continue; // "Autre" n'est pas un gout, c'est un defaut de saisie
                $genreCounts[$k] = ($genreCounts[$k] ?? 0) + 1;
            }
        }
        arsort($genreCounts);

        $genres = array_slice(array_keys($genreCounts), 0, DAILY_MIX_COUNT);

        // Historique absent ou trop mince : on complete avec les genres les plus
        // represents de la BIBLIOTHEQUE. Un compte tout neuf, ou quelqu'un qui n'a
        // ecoute qu'un seul genre, doit voir plusieurs mix -- sinon la rangee
        // n'existe pratiquement pas au moment ou elle serait la plus utile, celui ou
        // on ne sait pas encore quoi ecouter.
        if (count($genres) < DAILY_MIX_COUNT) {
            $libraryCounts = [];
            foreach ($db->query("SELECT genre FROM tracks")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                foreach (split_genres($raw) as $g) {
                    $k = mb_strtolower($g);
                    if ($k === 'autre') continue;
                    $libraryCounts[$k] = ($libraryCounts[$k] ?? 0) + 1;
                }
            }
            arsort($libraryCounts);
            foreach (array_keys($libraryCounts) as $k) {
                if (count($genres) >= DAILY_MIX_COUNT) break;
                if (!in_array($k, $genres, true)) $genres[] = $k;
            }
        }
        if (!$genres) { echo json_encode(['status' => 'success', 'mixes' => []]); exit; }

        // --- Artistes connus, pour pondérer le tirage ----------------------
        $artStmt = $db->prepare(
            "SELECT t.artist AS a FROM listen_events le JOIN tracks t ON t.id = le.track_id
             WHERE le.user_id = ? AND le.listened_seconds >= 10"
        );
        $artStmt->execute([$uid]);
        $knownArtists = [];
        foreach ($artStmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach (split_artist_names($raw) as $a) $knownArtists[mb_strtolower($a)] = true;
        }

        $allTracks = $db->query(
            "SELECT id, title, artist, cover, genre, play_count, duration FROM tracks"
        )->fetchAll(PDO::FETCH_ASSOC);
        $maxPlay = 1;
        foreach ($allTracks as $t) $maxPlay = max($maxPlay, (int) $t['play_count']);

        $mixes = [];
        foreach ($genres as $genreKey) {
            // Candidats : toutes les pistes portant ce genre, découpage compris.
            $candidates = [];
            $label = $genreKey;
            foreach ($allTracks as $t) {
                foreach (split_genres($t['genre']) as $g) {
                    if (mb_strtolower($g) !== $genreKey) continue;
                    $label = $g; // libellé tel qu'il est écrit dans la bibliothèque
                    $candidates[] = $t;
                    break;
                }
            }
            if (count($candidates) < 3) continue; // un "mix" de deux titres n'en est pas un

            $rng = daily_mix_rng(crc32($day . '|' . $uid . '|' . $genreKey));

            // Tirage pondéré : le score décale la clé de tri, le hasard fait le
            // reste. Un morceau d'un artiste connu et un peu populaire remonte,
            // sans jamais être garanti -- c'est ce qui rend le mix rejouable.
            $ranked = [];
            foreach ($candidates as $t) {
                $known = 0.0;
                foreach (split_artist_names($t['artist']) as $a) {
                    if (isset($knownArtists[mb_strtolower($a)])) { $known = 1.0; break; }
                }
                $pop = log(1 + (int) $t['play_count']) / log(1 + $maxPlay);
                $affinity = (0.6 * $known) + (0.4 * $pop);
                // Le hasard domine (poids 1) et l'affinité l'incline (poids 0,8) :
                // au-delà, le mix redeviendrait le classement des plus écoutés.
                $ranked[] = ['t' => $t, 'k' => $rng() + 0.8 * $affinity];
            }
            usort($ranked, fn($a, $b) => $b['k'] <=> $a['k']);
            $picked = array_slice($ranked, 0, DAILY_MIX_SIZE);

            $tracks = [];
            foreach ($picked as $entry) {
                $t = $entry['t'];
                $t['id'] = (int) $t['id'];
                $tracks[] = $t;
            }

            $mixes[] = [
                'key' => $genreKey,
                'title' => $label,
                'cover' => $tracks[0]['cover'] ?? 'default.png',
                'count' => count($tracks),
                'tracks' => $tracks,
            ];
        }

        echo json_encode(['status' => 'success', 'day' => $day, 'mixes' => $mixes], JSON_UNESCAPED_UNICODE);
        break;
}
