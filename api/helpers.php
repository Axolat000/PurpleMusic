<?php
// CGU désactivées par défaut (fraîche installation open source) : un admin doit explicitement les
// activer depuis le Panel Admin (miroir de la même règle dans index.php). Sans cache : appelée au plus
// une fois par requête (login/register/accept_terms uniquement), coût négligeable.
function terms_are_enabled($db) {
    $stmt = $db->query("SELECT value FROM settings WHERE key = 'terms_enabled'");
    return $stmt->fetchColumn() === '1';
}

function check_rate_limit($db) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = time();
    $window = $now - LOGIN_WINDOW;

    // Nettoyer les anciennes entrées
    $db->prepare("DELETE FROM login_attempts WHERE attempt_time < ?")->execute([$window]);

    // Compter les tentatives récentes pour cette IP
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempt_time >= ?");
    $stmt->execute([$ip, $window]);
    $count = (int)$stmt->fetchColumn();

    return $count < LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt($db) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->prepare("INSERT INTO login_attempts (ip, attempt_time) VALUES (?, ?)")->execute([$ip, time()]);
}

// --- SÉCURITÉ : Validation et nettoyage des champs texte (stockage brut en BDD, l'échappement XSS se fait au rendu) ---
function sanitize_text($value, $max_length = MAX_FIELD_LENGTH) {
    $value = trim($value);
    if (mb_strlen($value) > $max_length) {
        $value = mb_substr($value, 0, $max_length);
    }
    return $value;
}

// --- SÉCURITÉ : Vérification du type MIME réel d'un fichier audio ---
function is_valid_audio($path, $ext) {
    $allowedExts = ['mp3', 'wav', 'ogg', 'flac'];
    if (!in_array($ext, $allowedExts)) return false;

    $fp = fopen($path, 'rb');
    if (!$fp) return false;
    $sig = fread($fp, 12);
    fclose($fp);

    // MP3 : frame sync ou ID3
    if (substr($sig, 0, 3) === 'ID3') return true;
    if ((ord($sig[0]) === 0xFF) && ((ord($sig[1]) & 0xE0) === 0xE0)) return true;
    // WAV : RIFF....WAVE
    if (substr($sig, 0, 4) === 'RIFF' && substr($sig, 8, 4) === 'WAVE') return true;
    // OGG
    if (substr($sig, 0, 4) === 'OggS') return true;
    // FLAC
    if (substr($sig, 0, 4) === 'fLaC') return true;

    return false;
}

// --- RECOMMANDATIONS ---
// Moteur heuristique volontairement simple (pas de ML — inutile à l'échelle d'une instance
// auto-hébergée) combinant : affinité de genre/artiste de l'utilisateur (son propre historique
// d'écoute), tendance récente (7 derniers jours, tous utilisateurs confondus), qualité perçue (ratio
// durée moyenne écoutée / durée réelle -- une piste qu'on écoute jusqu'au bout est un meilleur signal
// qu'une piste juste "vue"), un petit coup de pouce si la piste est likée, et une popularité globale
// AMORTIE en log (pas play_count brut) pour que les grosses pistes déjà archi-populaires n'écrasent pas
// tout le classement -- "remettre toutes les musiques avec trop de vue à une valeur plus basse", demandé
// explicitement, mais seulement ICI (le tri "Les plus écoutés" lui-même continue d'afficher le vrai
// play_count, sans quoi il mentirait sur ce qu'il prétend montrer). Les pistes déjà beaucoup écoutées par
// CET utilisateur sont fortement dépriorisées (pas exclues) : la recommandation sert à découvrir, pas à
// re-suggérer ce que l'utilisateur retrouve déjà tout seul dans Récents/Plus écoutés.
/**
 * Découpe un champ artiste multi-noms ("A & B", "A feat. B") en noms individuels.
 *
 * Portage PHP de splitArtistNames() (js/library.js) : les statistiques agrègent
 * par artiste alors que la colonne est une chaîne libre que SQL ne sait pas
 * découper. Les deux implémentations doivent rester alignées, sinon un même
 * artiste serait compté différemment côté page Artiste et côté Statistiques.
 *
 * @return string[] noms nettoyés, sans doublon d'espaces ni crochet orphelin
 */
function split_artist_names($raw) {
    if ($raw === null || trim($raw) === '') return [];

    // Mention de featuring encadrée : on retire les crochets, on garde le contenu.
    $s = preg_replace('/[(\[]\s*((?:feat|ft|featuring|avec|with)\b\.?\s*[^)\]]*)[)\]]/iu', ' $1 ', $raw);

    $parts = preg_split(
        '/\s*,\s*|\s*&amp;\s*|\s*&\s*|\s+feat\.?\s+|\s+ft\.?\s+|\s+featuring\s+|\s+vs\.?\s+|\s+x\s+|\s+et\s+|\s*;\s*/iu',
        $s
    );

    $out = [];
    foreach ($parts as $part) {
        $p = trim($part);
        // Marqueur de featuring resté en tête après un découpage sur virgule.
        $p = preg_replace('/^(?:feat|ft|featuring|avec|with|and|et|x)\b\.?\s*/iu', '', $p);
        $p = trim(preg_replace('/\s{2,}/u', ' ', $p));

        // Crochets orphelins uniquement (pendant parti dans un autre fragment) :
        // un décapage inconditionnel casserait un alias légitime "Nom (Alias)".
        $guard = 0;
        while ($guard++ < 8) {
            $opens = preg_match_all('/[(\[]/u', $p);
            $closes = preg_match_all('/[)\]]/u', $p);
            if ($closes > $opens && preg_match('/^[)\]]/u', $p)) { $p = trim(mb_substr($p, 1)); continue; }
            if ($opens > $closes && preg_match('/[(\[]$/u', $p)) { $p = trim(mb_substr($p, 0, -1)); continue; }
            if ($closes > $opens && preg_match('/[)\]]$/u', $p)) { $p = trim(mb_substr($p, 0, -1)); continue; }
            if ($opens > $closes && preg_match('/^[(\[]/u', $p)) { $p = trim(mb_substr($p, 1)); continue; }
            break;
        }

        if ($p !== '') $out[] = $p;
    }
    return $out;
}

/**
 * Découpe un champ genre multi-valeurs en genres individuels.
 *
 * Une piste peut porter plusieurs genres, stockés séparés par des virgules dans
 * la même colonne `tracks.genre`. Ce choix plutôt qu'une table de liaison :
 * la colonne reste une chaîne, donc le contrat de l'API Android (qui lit `genre`
 * comme un texte) est inchangé, et aucune migration n'est nécessaire — une piste
 * mono-genre existante est simplement une liste d'un seul élément.
 *
 * @return string[] genres nettoyés, sans doublon (comparaison insensible à la casse)
 */
function split_genres($raw) {
    $out = [];
    $seen = [];
    foreach (preg_split('/\s*[,;\/]\s*/u', (string) $raw) as $g) {
        $g = trim($g);
        if ($g === '') continue;
        $key = mb_strtolower($g);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $g;
    }
    return $out;
}

/**
 * Enregistre un genre dans la table `genres` s'il n'y est pas déjà.
 *
 * Le genre se saisit désormais librement à l'import et à l'édition d'une piste
 * (auparavant : liste fermée, alimentée uniquement depuis le Panel Admin — tout
 * ce qui n'y figurait pas atterrissait dans "Autre", d'où plus de la moitié de la
 * bibliothèque sans genre réel). Sans cet enregistrement, un genre saisi à la main
 * existerait sur la piste mais resterait absent des suggestions et du Panel Admin.
 *
 * Comparaison insensible à la casse : "phonk" et "Phonk" ne doivent pas créer deux
 * entrées. "Autre" n'est jamais enregistré — c'est la valeur de repli, pas un genre.
 */
function register_genre($db, $raw) {
    $stmt = $db->prepare("SELECT 1 FROM genres WHERE LOWER(name) = LOWER(?)");
    $ins = $db->prepare("INSERT OR IGNORE INTO genres (name) VALUES (?)");

    // Le champ pouvant contenir plusieurs genres ("Phonk, Nightcore"), chacun
    // doit rejoindre la liste séparément — sinon la chaîne entière deviendrait
    // une entrée bâtarde proposée telle quelle dans les suggestions.
    foreach (split_genres($raw) as $name) {
        if (mb_strtolower($name) === 'autre') continue;
        $stmt->execute([$name]);
        if ($stmt->fetch()) continue;
        // INSERT OR IGNORE : deux imports simultanés du même genre nouveau ne
        // doivent pas faire échouer le second sur une contrainte d'unicité.
        $ins->execute([$name]);
    }
}

/**
 * Requête HTTP GET JSON simple, partagée par les intégrations externes.
 *
 * Retourne null en cas d'échec réseau, de code non-200 ou de JSON invalide :
 * l'appelant n'a jamais à distinguer ces cas, aucun d'eux ne doit casser la page.
 */
function http_get_json($url, $timeout = 8) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        // Wikipédia exige un User-Agent identifiant l'application et un contact ;
        // les UA génériques y sont limités, voire bloqués.
        CURLOPT_USERAGENT => 'PurpleMusic-Web/1.0 (+https://github.com/Axolat000/PurpleMusic)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err !== 0 || $body === false || $code !== 200) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Résumé Wikipédia d'un artiste, avec résolution des homonymies.
 *
 * Portage serveur de fetchWikipediaSummary() (js/library.js), pour que le
 * résultat puisse être mis en cache en base (voir api/bio.php) au lieu d'être
 * refait par chaque navigateur à chaque visite.
 *
 * @return array{extract:string,url:?string}|null
 */
function wikipedia_summary($name, $lang) {
    $base = "https://{$lang}.wikipedia.org";

    $summary = http_get_json($base . '/api/rest_v1/page/summary/' . rawurlencode($name));
    if ($summary && ($summary['type'] ?? '') !== 'disambiguation' && !empty($summary['extract'])) {
        return ['extract' => $summary['extract'], 'url' => $summary['content_urls']['desktop']['page'] ?? null];
    }

    // Page d'homonymie ou introuvable telle quelle : on prend le premier résultat
    // de recherche qui est une vraie page (ex. "Drake" -> "Drake (musicien)").
    $search = http_get_json($base . '/w/rest.php/v1/search/page?' . http_build_query(['q' => $name, 'limit' => 5]));
    if (!$search) return null;

    foreach (($search['pages'] ?? []) as $page) {
        $title = $page['title'] ?? '';
        if ($title === '' || mb_strtolower($title) === mb_strtolower($name)) continue;
        $alt = http_get_json($base . '/api/rest_v1/page/summary/' . rawurlencode($title));
        if ($alt && ($alt['type'] ?? '') !== 'disambiguation' && !empty($alt['extract'])) {
            return ['extract' => $alt['extract'], 'url' => $alt['content_urls']['desktop']['page'] ?? null];
        }
    }
    return null;
}

/**
 * Moteur de recommandations.
 *
 * Il ne tire rien au sort : il note chaque piste sur des signaux mesures
 * (affinites de genre et d'artiste, tendance recente, taux d'ecoute, likes,
 * popularite amortie) puis garde les meilleures. Ce qui suit decrit ce qui a
 * change, et surtout pourquoi.
 *
 * 1. LES GENRES ET LES ARTISTES SONT DECOUPES DES DEUX COTES. C'etait le defaut
 *    le plus couteux : la comparaison se faisait sur la chaine BRUTE. Depuis que
 *    l'app accepte plusieurs genres par piste ("Phonk, Nightcore"), une piste
 *    ainsi etiquetee ne correspondait JAMAIS a une affinite "Phonk" -- le signal
 *    de genre etait mort pour une grande partie de la bibliotheque sans que rien
 *    ne le signale. Meme probleme pour "A & B" face a une affinite "A".
 *
 * 2. LE GOUT RECENT PESE PLUS LOURD. Les affinites etaient calculees sur tout
 *    l'historique a poids egal : ce qu'on ecoutait il y a un an pesait autant que
 *    cette semaine. Une ecoute des 30 derniers jours compte desormais double.
 *
 * 3. ARTISTES SIMILAIRES, par co-occurrence dans les playlists. Deux artistes que
 *    les gens rangent dans les memes playlists se ressemblent, en pratique, bien
 *    plus surement que deux artistes partageant une etiquette de genre. C'est le
 *    seul signal de similarite disponible sans service externe -- et il a
 *    l'avantage de refleter CE catalogue-ci plutot qu'une base mondiale qui ne
 *    connait ni les montages ni les nightcore.
 *
 * 4. PAS PLUS DE DEUX TITRES PAR ARTISTE. Sans ce plafond, un artiste tres ecoute
 *    remplissait la rangee a lui seul : le classement etait bon, le resultat
 *    inutile. On veut une rangee de decouverte, pas la discographie du dernier
 *    artiste ecoute.
 */
function build_recommendations($db, $userId, $baseUrl, $limit = 20) {
    $sevenDaysAgo = time() - (7 * 24 * 3600);
    $thirtyDaysAgo = time() - (30 * 24 * 3600);

    // --- Affinites de genre et d'artiste ------------------------------------
    // On lit les lignes brutes et on agrege en PHP : split_genres() et
    // split_artist_names() sont les seuls a savoir decouper ces champs, et SQL ne
    // sait pas les appeler. Le poids double des ecoutes recentes est calcule ici.
    $affStmt = $db->prepare(
        "SELECT t.genre AS g, t.artist AS a, le.created_at AS ts
         FROM listen_events le JOIN tracks t ON t.id = le.track_id
         WHERE le.user_id = ? AND le.listened_seconds >= 10"
    );
    $affStmt->execute([$userId]);

    $genreCounts = [];
    $artistCounts = [];
    foreach ($affStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $weight = ((int) $row['ts'] >= $thirtyDaysAgo) ? 2 : 1;
        foreach (split_genres($row['g']) as $g) {
            $k = mb_strtolower($g);
            $genreCounts[$k] = ($genreCounts[$k] ?? 0) + $weight;
        }
        foreach (split_artist_names($row['a']) as $a) {
            $k = mb_strtolower($a);
            $artistCounts[$k] = ($artistCounts[$k] ?? 0) + $weight;
        }
    }
    arsort($genreCounts);
    arsort($artistCounts);

    // Normalisation sur le maximum : une affinite vaut 0 a 1, quel que soit le
    // volume d'ecoute de la personne. Sans ca, un gros auditeur aurait des scores
    // ecrases contre le plafond et un nouveau des scores nuls.
    $topGenres = [];
    $maxGenre = $genreCounts ? max($genreCounts) : 0;
    foreach (array_slice($genreCounts, 0, 5, true) as $g => $c) $topGenres[$g] = $maxGenre ? $c / $maxGenre : 0;

    $topArtists = [];
    $maxArtist = $artistCounts ? max($artistCounts) : 0;
    foreach (array_slice($artistCounts, 0, 8, true) as $a => $c) $topArtists[$a] = $maxArtist ? $c / $maxArtist : 0;

    $hasHistory = count($topGenres) > 0 || count($topArtists) > 0;

    // --- Artistes similaires, par co-occurrence dans les playlists -----------
    // Pour chaque playlist, on regarde quels artistes y cohabitent. Ceux qui
    // partagent une playlist avec un artiste qu'on ecoute deja heritent d'une
    // fraction de son affinite.
    $similarArtists = [];
    if ($hasHistory) {
        $trackArtists = [];
        foreach ($db->query("SELECT id, artist FROM tracks")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $trackArtists[(int) $row['id']] = split_artist_names($row['artist']);
        }
        foreach ($db->query("SELECT song_ids FROM playlists")->fetchAll(PDO::FETCH_COLUMN) as $songIds) {
            $ids = array_filter(array_map('intval', explode(',', (string) $songIds)), fn($v) => $v > 0);
            if (count($ids) < 2) continue;

            $inPlaylist = [];
            foreach ($ids as $id) {
                foreach ($trackArtists[$id] ?? [] as $name) $inPlaylist[mb_strtolower($name)] = true;
            }
            // La playlist compte-t-elle un artiste qu'on aime ? Si oui, tous les
            // autres artistes qui s'y trouvent gagnent un point de similarite.
            $anchor = 0.0;
            foreach (array_keys($inPlaylist) as $name) {
                if (isset($topArtists[$name])) $anchor = max($anchor, $topArtists[$name]);
            }
            if ($anchor <= 0) continue;
            foreach (array_keys($inPlaylist) as $name) {
                if (isset($topArtists[$name])) continue; // deja compte comme affinite directe
                $similarArtists[$name] = ($similarArtists[$name] ?? 0) + $anchor;
            }
        }
        $maxSimilar = $similarArtists ? max($similarArtists) : 0;
        if ($maxSimilar > 0) {
            foreach ($similarArtists as $k => $v) $similarArtists[$k] = $v / $maxSimilar;
        }
    }

    // --- Signaux globaux ----------------------------------------------------
    $ownListenStmt = $db->prepare("SELECT track_id, SUM(listened_seconds) as total FROM listen_events WHERE user_id = ? GROUP BY track_id");
    $ownListenStmt->execute([$userId]);
    $ownListenSeconds = [];
    foreach ($ownListenStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $ownListenSeconds[$row['track_id']] = (int) $row['total']; }

    $recentStmt = $db->prepare("SELECT track_id, COUNT(*) as recent_plays, AVG(listened_seconds) as avg_sec FROM listen_events WHERE created_at > ? GROUP BY track_id");
    $recentStmt->execute([$sevenDaysAgo]);
    $recentPlays = []; $maxRecentPlays = 1;
    $avgListenSeconds = [];
    foreach ($recentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recentPlays[$row['track_id']] = (int) $row['recent_plays'];
        $avgListenSeconds[$row['track_id']] = (float) $row['avg_sec'];
        if ($recentPlays[$row['track_id']] > $maxRecentPlays) $maxRecentPlays = $recentPlays[$row['track_id']];
    }

    $likeCounts = [];
    foreach ($db->query("SELECT track_id, COUNT(*) as cnt FROM likes GROUP BY track_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $likeCounts[$row['track_id']] = (int) $row['cnt'];
    }

    $tracks = $db->query("SELECT id, filename, title, artist, cover, genre, play_count, duration, uploader_id FROM tracks")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tracks)) return [];
    $maxPlayCount = max(1, max(array_column($tracks, 'play_count')));

    $scored = [];
    foreach ($tracks as $t) {
        $id = $t['id'];

        // Meilleure correspondance parmi les genres de la piste, et non la premiere :
        // une piste "Autre, Phonk" doit valoir son Phonk.
        $genreScore = 0.0;
        foreach (split_genres($t['genre']) as $g) {
            $genreScore = max($genreScore, $topGenres[mb_strtolower($g)] ?? 0.0);
        }
        $artistScore = 0.0;
        $similarScore = 0.0;
        foreach (split_artist_names($t['artist']) as $a) {
            $k = mb_strtolower($a);
            $artistScore = max($artistScore, $topArtists[$k] ?? 0.0);
            $similarScore = max($similarScore, $similarArtists[$k] ?? 0.0);
        }

        $trendScore = isset($recentPlays[$id]) ? ($recentPlays[$id] / $maxRecentPlays) : 0.0;
        $completionScore = 0.5; // valeur neutre si on ne sait pas encore
        if (!empty($t['duration']) && isset($avgListenSeconds[$id])) {
            $completionScore = max(0.0, min(1.0, $avgListenSeconds[$id] / $t['duration']));
        }
        $likeBoost = min(0.3, 0.1 * ($likeCounts[$id] ?? 0));
        $popularityDamped = log(1 + (int) $t['play_count']) / log(1 + $maxPlayCount);

        $score = (0.30 * $genreScore)
               + (0.22 * $artistScore)
               + (0.13 * $similarScore)
               + (0.17 * $trendScore)
               + (0.09 * $completionScore)
               + (0.09 * $popularityDamped)
               + $likeBoost;

        // Deja bien connue de cette personne : forte penalite, jamais zero -- un
        // morceau aime doit garder une chance de resurgir.
        $ownSeconds = $ownListenSeconds[$id] ?? 0;
        if ($ownSeconds >= 120) $score *= 0.25;
        elseif ($ownSeconds >= 30) $score *= 0.6;

        // Sans aucun historique : tendance et popularite, les seuls signaux qui
        // existent. Le reste vaut zero pour tout le monde de toute facon.
        if (!$hasHistory) $score = (0.5 * $trendScore) + (0.3 * $popularityDamped) + $likeBoost;

        $scored[] = ['track' => $t, 'score' => $score];
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    // --- Plafond par artiste -------------------------------------------------
    // Deux titres maximum par artiste tant qu'il reste des candidats ailleurs. Les
    // recales sont gardes de cote et servent a completer si la bibliotheque est
    // trop petite pour remplir la rangee autrement -- une rangee courte serait un
    // plus mauvais resultat qu'une rangee un peu repetitive.
    $perArtist = [];
    $picked = [];
    $overflow = [];
    foreach ($scored as $entry) {
        $names = split_artist_names($entry['track']['artist']);
        $key = mb_strtolower($names[0] ?? $entry['track']['artist']);
        if (($perArtist[$key] ?? 0) >= 2) { $overflow[] = $entry; continue; }
        $perArtist[$key] = ($perArtist[$key] ?? 0) + 1;
        $picked[] = $entry;
        if (count($picked) >= $limit) break;
    }
    if (count($picked) < $limit) {
        $picked = array_merge($picked, array_slice($overflow, 0, $limit - count($picked)));
    }

    $result = [];
    foreach ($picked as $entry) {
        $t = $entry['track'];
        $t['like_count'] = $likeCounts[$t['id']] ?? 0;
        $t['cover_url'] = $baseUrl . "api.php?action=cover&q=" . $t['id'] . "&t=" . time();
        $t['stream_url'] = $baseUrl . "api.php?action=stream&q=" . $t['id'];
        $result[] = $t;
    }
    return $result;
}

// --- SÉCURITÉ : Fonction d'authentification pour l'API, double mode ---
// 1) Session PHP (navigateur web, cookie déjà posé par le login classique dans auth.php, inchangé) :
//    pour toute requête POST (donc mutante), exige un csrf_token valide -- même protection
//    qu'actions.php avant sa fusion ici, juste centralisée. Jamais atteint par l'app Android, qui
//    n'envoie pas ce cookie.
// 2) Sinon, comportement historique inchangé : username+password à chaque requête (Android).
function authenticate_api_user($db) {
    if (!empty($_SESSION['user_id'])) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? '';
            if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
                return false;
            }
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'is_admin' => !empty($_SESSION['is_admin'])
        ];
    }

    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        return false;
    }

    $stmt = $db->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'is_admin' => (isset($user['is_admin']) && $user['is_admin'] == 1)
        ];
    }
    return false;
}

// --- CALCULE LA DURÉE MULTI-FORMATS ---
// Les fermetures de $fp sont gardees par is_resource() : selon le chemin emprunte
// (en-tete Xing sans frequence exploitable, table de debit a zero, sortie de
// boucle), le meme descripteur etait ferme deux ou trois fois. Sans consequence en
// PHP 7, TypeError fatale en PHP 8 -- tout fichier non-MP3 (un WAV, un OGG) faisait
// planter l'upload comme l'import, avec une page d'erreur au lieu d'un JSON.
function calculateAudioDuration($path) {
    if (!file_exists($path)) return 0;
    $fp = fopen($path, 'rb');
    if (!$fp) return 0;

    $signature = fread($fp, 4);
    
    // --- 1. CAS DU FLAC NATIF ---
    if ($signature === 'fLaC') {
        fseek($fp, 8);
        $streamInfo = fread($fp, 34);
        if (is_resource($fp)) fclose($fp);
        
        if (strlen($streamInfo) === 34) {
            $fields = unpack('N3', substr($streamInfo, 10, 12));
            $sampleRate = ($fields[1] >> 12) & 0xFFFFF;
            $totalSamples = (($fields[1] & 0x00F) << 32) | $fields[2];
            if ($sampleRate > 0) {
                return round($totalSamples / $sampleRate);
            }
        }
        return 0;
    }
    
    // --- 2. CAS DU M4A / MP4 / AAC CONTENEUR ---
    if (strpos($signature, 'ftyp') !== false || substr($signature, 1, 3) === 'ftyp') {
        fseek($fp, 0);
        $content = fread($fp, 1024 * 400);
        $mvhdPos = strpos($content, 'mvhd');
        if (is_resource($fp)) fclose($fp);
        
        if ($mvhdPos !== false) {
            $version = ord($content[$mvhdPos + 4]);
            $timeScaleOffset = ($version === 1) ? 20 : 12;
            $durationOffset = ($version === 1) ? 24 : 16;
            
            $timeScale = unpack('N', substr($content, $mvhdPos + 4 + $timeScaleOffset, 4))[1];
            $durationUnits = unpack('N', substr($content, $mvhdPos + 4 + $durationOffset, 4))[1];
            
            if ($timeScale > 0) {
                return round($durationUnits / $timeScale);
            }
        }
        return 0;
    }

    // --- 3. CAS DU MP3 TRADITIONNEL (CBR/VBR) ---
    fseek($fp, 0);
    $header = fread($fp, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $b = unpack('C*', substr($header, 6, 4));
        $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
        fseek($fp, $tagSize + 10);
    } else {
        fseek($fp, 0);
    }

    $data = fread($fp, 1024 * 200);
    $offset = 0;
    while ($offset < strlen($data) - 4) {
        if (ord($data[$offset]) === 0xFF && (ord($data[$offset+1]) & 0xE0) === 0xE0) {
            $byte1 = ord($data[$offset+1]);
            $byte2 = ord($data[$offset+2]);
            $mpegVersion = ($byte1 >> 3) & 0x03;
            
            $channelMode = ($byte2 >> 6) & 0x03;
            $xingOffset = ($mpegVersion === 3) ? (($channelMode === 3) ? 17 : 32) : (($channelMode === 3) ? 9 : 17);
            $vbrCheck = substr($data, $offset + 4 + $xingOffset, 4);
            
            if ($vbrCheck === 'Xing' || $vbrCheck === 'Info') {
                $flags = unpack('N', substr($data, $offset + 4 + $xingOffset + 4, 4))[1];
                if ($flags & 0x01) {
                    $frameCount = unpack('N', substr($data, $offset + 4 + $xingOffset + 8, 4))[1];
                    $srTable = [3 => [44100, 48000, 32000, 0], 2 => [22050, 24000, 16000, 0]];
                    $sampleRate = $srTable[$mpegVersion][($byte2 >> 2) & 0x03] ?? 44100;
                    $samplesPerFrame = ($mpegVersion === 3) ? 1152 : 576;
                    if (is_resource($fp)) fclose($fp);
                    if ($sampleRate > 0) return round(($frameCount * $samplesPerFrame) / $sampleRate);
                }
            }
            
            $brTable = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
            $bitrate = $brTable[($byte2 >> 4) & 0x0F] ?? 128;
            if (is_resource($fp)) fclose($fp);
            if ($bitrate > 0) return round((filesize($path) * 8) / ($bitrate * 1000));
            break;
        }
        $offset++;
    }

    if (is_resource($fp)) fclose($fp);
    return round((filesize($path) * 8) / (128 * 1000));
}

// --- HELPER METADATA (ROBUSTE) ---
function extractMp3Data($path) {
    if (!file_exists($path)) return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null];
    $f = fopen($path, 'rb');
    if (!$f) return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null];

    $header = fread($f, 10);
    if (substr($header, 0, 3) !== 'ID3') { fclose($f); return ['artist'=>null, 'title'=>null, 'album'=>null, 'cover'=>null]; }

    $b = unpack('C*', substr($header, 6, 4));
    $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
    $tagData = fread($f, $tagSize);
    fclose($f);

    $result = ['cover' => null, 'artist' => null, 'title' => null, 'album' => null];
    $pos = 0;
    while ($pos < strlen($tagData) - 10) {
        $frameHeader = substr($tagData, $pos, 10);
        $frameName = substr($frameHeader, 0, 4);
        $s = unpack('N', substr($frameHeader, 4, 4));
        $frameSize = $s[1];

        if ($frameSize == 0 || $frameName == "\x00\x00\x00\x00") break;

        if ($frameName === 'TPE1') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['artist'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'TIT2') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['title'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'TALB') {
            $body = substr($tagData, $pos + 10, $frameSize);
            if(strlen($body) > 1) $result['album'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', substr($body, 1)));
        }
        if ($frameName === 'APIC') {
            $body = substr($tagData, $pos + 10, $frameSize);
            $nullPos = strpos($body, "\x00", 1);
            if ($nullPos !== false) {
                $jpgPos = strpos($body, "\xFF\xD8");
                $pngPos = strpos($body, "\x89PNG");
                
                $start = false; $mime = 'image/jpeg';
                if($jpgPos !== false && ($pngPos === false || $jpgPos < $pngPos)) { $start = $jpgPos; }
                elseif($pngPos !== false) { $start = $pngPos; $mime = 'image/png'; }
                
                if($start !== false) {
                    $result['cover'] = ['mime' => $mime, 'data' => substr($body, $start)];
                }
            }
        }
        $pos += 10 + $frameSize;
    }
    return $result;
}

// --- OPTIMISATION : Fonction pour compresser les covers ---
function optimizeImage($sourcePath, $destinationPath, $mime = null) {
    if (!extension_loaded('gd')) return move_uploaded_file($sourcePath, $destinationPath);
    
    $info = getimagesize($sourcePath);
    if (!$info) return false;
    $mime = $mime ?? $info['mime'];
    
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($sourcePath); break;
        case 'image/png': $image = imagecreatefrompng($sourcePath); break;
        case 'image/webp': $image = imagecreatefromwebp($sourcePath); break;
        case 'image/gif': $image = imagecreatefromgif($sourcePath); break;
        default: return false;
    }
    
    if (!$image) return false;

    $width = imagesx($image); $height = imagesy($image); $max_size = 300;
    
    if ($width > $max_size || $height > $max_size) {
        $ratio = min($max_size / $width, $max_size / $height);
        $new_width = round($width * $ratio);
        $new_height = round($height * $ratio);
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        if ($mime == 'image/png') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
        }
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $new_image;
    }

    $success = imagewebp($image, $destinationPath, 80);
    imagedestroy($image);
    if (!$success) move_uploaded_file($sourcePath, $destinationPath);
    return true;
}

/**
 * Résout un nom d'album vers une ligne de la table `albums`, en la créant au besoin.
 *
 * Pendant du register_genre() ci-dessus, et pour la même raison : toute création
 * d'album doit passer par ici, jamais par un INSERT direct. C'est ce qui garantit
 * que `tracks.album` (le texte, lu par l'app Android via action=list) et
 * `tracks.album_id` (la vraie relation) ne divergent jamais — le jour où ils
 * divergent, l'app web et l'app Android rangent la même piste dans deux albums
 * différents, et rien ne le signale.
 *
 * Retourne null pour un nom vide : une piste sans album n'est pas une erreur,
 * c'est le cas le plus courant sur ce catalogue.
 *
 * $artist et $cover ne servent QU'À la création : ils donnent une valeur de départ
 * à un album qu'on découvre. Ils n'écrasent jamais un album existant, dont les
 * métadonnées ont pu être corrigées à la main depuis le Panel Admin.
 */
function resolve_album($db, $name, $artist = '', $cover = null) {
    $name = trim((string) $name);
    if ($name === '') return null;

    $find = $db->prepare("SELECT id FROM albums WHERE name = ? COLLATE NOCASE");
    $find->execute([$name]);
    $id = $find->fetchColumn();
    if ($id !== false) return (int) $id;

    // INSERT OR IGNORE puis relecture : deux imports simultanés du même album
    // nouveau ne doivent pas faire échouer le second sur l'index unique.
    $db->prepare("INSERT OR IGNORE INTO albums (name, artist, cover, created_at) VALUES (?, ?, ?, ?)")
       ->execute([$name, (string) $artist, $cover, time()]);
    $find->execute([$name]);
    $id = $find->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * Journalise une action d'administration.
 *
 * Volontairement sans valeur de retour et sans exception : un journal qui casse
 * l'action qu'il observe serait pire que pas de journal du tout. Si l'écriture
 * échoue (base verrouillée, table absente sur une instance à moitié migrée), la
 * suppression ou la promotion demandée doit aboutir quand même.
 *
 * $target : ce sur quoi on agit, lisible tel quel dans l'interface (un titre de
 * piste, un nom de compte), pas seulement un identifiant — l'objet a souvent
 * disparu au moment où on relit le journal.
 */
function log_admin_action($db, $auth, $action, $target = null, $details = null) {
    try {
        $stmt = $db->prepare(
            "INSERT INTO admin_log (user_id, username, action, target, details, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $auth['id'] ?? null,
            $auth['username'] ?? '',
            (string) $action,
            $target !== null ? mb_substr((string) $target, 0, 200) : null,
            $details !== null ? mb_substr((string) $details, 0, 500) : null,
            time(),
        ]);
    } catch (Exception $e) {
        // Silencieux par conception : voir le commentaire ci-dessus.
    }
}

/**
 * Droit d'écrire dans le CONTENU d'une playlist (ajouter, retirer, réordonner).
 *
 * Distinct de la propriété : un collaborateur peut modifier la liste des morceaux
 * mais pas renommer, changer la visibilité ni supprimer la playlist. Ces
 * actions-là appellent directement la comparaison au creator_id.
 */
function can_edit_playlist_content($db, $auth, $playlistCreatorId, $playlistId) {
    if (!$auth) return false;
    if (!empty($auth['is_admin'])) return true;
    if ((int) $playlistCreatorId === (int) $auth['id']) return true;

    $stmt = $db->prepare("SELECT 1 FROM playlist_collaborators WHERE playlist_id = ? AND user_id = ?");
    $stmt->execute([(int) $playlistId, (int) $auth['id']]);
    return $stmt->fetchColumn() !== false;
}
