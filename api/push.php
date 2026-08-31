<?php
/**
 * Notifications push : « nouvel album » pour les artistes suivis.
 *
 * PAS DE CHARGE UTILE DANS LE PUSH. C'est le choix qui structure tout ce fichier.
 * Le protocole Web Push permet d'envoyer un contenu chiffré, mais cela demande
 * toute une couche de chiffrement (ECDH, HKDF, AES-GCM) qu'il faudrait
 * réimplémenter à la main ou tirer d'une dépendance — pour un projet qui n'en a
 * aucune hors Alpine.
 *
 * On envoie donc un push VIDE : un simple signal. Le service worker le reçoit,
 * vient lire ce qu'il doit afficher (action=push_pending) et construit la
 * notification lui-même. Deux avantages en plus de la simplicité : le titre du
 * morceau ne transite jamais par le service de push du navigateur (Google,
 * Mozilla, Apple), et le contenu affiché est toujours à jour même si la
 * notification est reçue longtemps après avoir été émise.
 *
 * VAPID reste nécessaire : c'est ce qui identifie le serveur auprès du service de
 * push. La paire de clés est générée à la première utilisation et rangée dans
 * `settings` — pas de fichier à installer, pas de variable d'environnement à
 * renseigner. Si OpenSSL ne sait pas produire de clé EC sur cette machine,
 * l'endpoint le dit et l'interface n'affiche pas le réglage, comme pour ffmpeg.
 */

// Base64 « URL-safe » sans remplissage : la seule forme acceptée partout dans
// Web Push (clés, JWT).
function push_b64(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * Paire de clés VAPID, générée une fois puis conservée.
 *
 * Renvoie null si la génération est impossible : OpenSSL sans configuration
 * utilisable, extension absente. L'appelant doit traiter ce cas — c'est ce qui
 * permet à l'app de fonctionner normalement sans notifications plutôt que de
 * planter.
 */
function push_vapid_keys(PDO $db): ?array
{
    $rows = $db->query("SELECT key, value FROM settings WHERE key IN ('vapid_public', 'vapid_private')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($rows['vapid_public']) && !empty($rows['vapid_private'])) {
        return ['public' => $rows['vapid_public'], 'private' => $rows['vapid_private']];
    }

    if (!extension_loaded('openssl')) return null;
    $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($key === false) return null;
    $details = @openssl_pkey_get_details($key);
    if (!$details || empty($details['ec']['x'])) return null;

    // Cle publique au format point non compresse : 0x04 || X || Y, chaque
    // coordonnee sur 32 octets exactement. OpenSSL peut renvoyer moins d'octets si
    // la valeur commence par des zeros, d'ou le remplissage a gauche.
    $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $public = push_b64("\x04" . $x . $y);

    @openssl_pkey_export($key, $pem);
    if (!$pem) return null;

    $ins = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $ins->execute(['vapid_public', $public]);
    $ins->execute(['vapid_private', $pem]);

    return ['public' => $public, 'private' => $pem];
}

/**
 * Signature ES256 pour le JWT VAPID.
 *
 * openssl_sign produit du DER (une séquence ASN.1 de deux entiers) ; JWS attend
 * R et S bruts, concaténés sur 64 octets. La conversion est faite à la main :
 * c'est le seul endroit délicat de ce fichier, et l'oublier donne une signature
 * refusée par tous les services de push sans message utile.
 */
function push_es256_signature(string $data, string $privatePem): ?string
{
    $key = @openssl_pkey_get_private($privatePem);
    if (!$key) return null;
    $der = '';
    if (!@openssl_sign($data, $der, $key, OPENSSL_ALGO_SHA256)) return null;

    // SEQUENCE(0x30) len INTEGER(0x02) len R INTEGER(0x02) len S
    $offset = 2;
    if (($der[1] ?? '') === "\x81") $offset = 3; // longueur sur un octet supplémentaire
    if (($der[$offset] ?? '') !== "\x02") return null;
    $rLen = ord($der[$offset + 1]);
    $r = substr($der, $offset + 2, $rLen);
    $sStart = $offset + 2 + $rLen;
    if (($der[$sStart] ?? '') !== "\x02") return null;
    $sLen = ord($der[$sStart + 1]);
    $s = substr($der, $sStart + 2, $sLen);

    // Les entiers DER portent un 0x00 de tête quand le bit de poids fort est à 1
    // (pour rester positifs) : il ne fait pas partie de la valeur.
    $r = ltrim($r, "\0");
    $s = ltrim($s, "\0");
    return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
}

/**
 * Un endpoint d'abonnement est-il acceptable ?
 *
 * C'est le garde-fou contre la falsification de requête côté serveur (SSRF).
 * push_send() fait un POST vers cette URL : sans contrôle, n'importe quel compte
 * pourrait faire émettre au serveur des requêtes vers des adresses de son choix,
 * y compris des services internes que lui-même ne peut pas joindre — cas
 * particulièrement concret ici, l'instance vivant sur un réseau local.
 *
 * Deux verrous :
 *   1. HTTPS uniquement, et une URL bien formée ;
 *   2. l'hôte doit résoudre vers une adresse PUBLIQUE. Les plages privées, la
 *      boucle locale et le lien-local sont refusées.
 *
 * Volontairement pas de liste blanche d'hébergeurs (FCM, Mozilla, Apple…) : elle
 * casserait tout navigateur utilisant un autre service de push, ce qui n'est pas
 * à cette application d'arbitrer.
 */
function push_endpoint_is_acceptable(string $endpoint): bool
{
    if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) return false;
    if (stripos($endpoint, 'https://') !== 0) return false;

    $host = parse_url($endpoint, PHP_URL_HOST);
    if (!$host) return false;

    // Une IP littérale est vérifiée telle quelle ; un nom est résolu d'abord.
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
        gethostbynamel($host) ?: [],
        array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')
    );
    if (!$ips) return false;

    foreach ($ips as $ip) {
        // FILTER_FLAG_NO_PRIV_RANGE et NO_RES_RANGE couvrent 10/8, 172.16/12,
        // 192.168/16, 127/8, 169.254/16 et leurs equivalents IPv6.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/** Envoie un push VIDE à un endpoint. Retourne le code HTTP, ou 0 en cas d'échec réseau. */
function push_send(string $endpoint, array $keys, string $subject): int
{
    $parts = parse_url($endpoint);
    if (!$parts || empty($parts['host'])) return 0;

    $header = push_b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = push_b64(json_encode([
        'aud' => $parts['scheme'] . '://' . $parts['host'],
        // 12 heures : au-delà, les services de push refusent le jeton. En deçà, on
        // le régénère pour rien à chaque envoi.
        'exp' => time() + (12 * 3600),
        'sub' => $subject,
    ]));
    $signature = push_es256_signature($header . '.' . $claims, $keys['private']);
    if ($signature === null) return 0;
    $jwt = $header . '.' . $claims . '.' . push_b64($signature);

    // Revérifié À L'ENVOI et pas seulement à l'abonnement : un nom de domaine
    // accepté hier peut pointer vers une adresse interne aujourd'hui (rebinding
    // DNS), et les abonnements vivent longtemps.
    if (!push_endpoint_is_acceptable($endpoint)) return 0;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        // Aucune redirection suivie : sinon le contrôle d'adresse ci-dessus serait
        // contournable par un simple 302 vers une adresse interne.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
            'TTL: 86400',
            'Content-Length: 0',
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/**
 * Prévient les abonnés qui suivent l'artiste d'une piste fraîchement ajoutée.
 *
 * Appelée à l'import et à l'upload. Volontairement silencieuse : une notification
 * qui échoue ne doit jamais faire échouer l'ajout du morceau.
 */
function push_notify_new_track(PDO $db, array $track): void
{
    try {
        $keys = push_vapid_keys($db);
        if (!$keys) return;

        $names = split_artist_names($track['artist'] ?? '');
        if (!$names) return;

        // Qui suit l'un de ces artistes ? Comparaison insensible à la casse, sur
        // chaque nom pris séparément : "A & B" doit prévenir les abonnés de A
        // comme ceux de B.
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $db->prepare("SELECT DISTINCT user_id FROM artist_follows WHERE artist COLLATE NOCASE IN ($placeholders)");
        $stmt->execute($names);
        $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$userIds) return;

        $subject = 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $queue = $db->prepare("INSERT INTO push_queue (user_id, title, body, url, created_at) VALUES (?, ?, ?, ?, ?)");
        $subs = $db->prepare("SELECT endpoint FROM push_subscriptions WHERE user_id = ?");

        foreach ($userIds as $uid) {
            $queue->execute([
                $uid,
                $track['artist'],
                $track['title'],
                'index.php?page=artist-page&name=' . rawurlencode($names[0]),
                time(),
            ]);
            $subs->execute([$uid]);
            foreach ($subs->fetchAll(PDO::FETCH_COLUMN) as $endpoint) {
                $code = push_send($endpoint, $keys, $subject);
                // 404 / 410 : l'abonnement n'existe plus côté navigateur (permission
                // retirée, application désinstallée). On nettoie, sinon on
                // retenterait indéfiniment sur une adresse morte.
                if ($code === 404 || $code === 410) {
                    $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$endpoint]);
                }
            }
        }
    } catch (Exception $e) {
        // Silencieux par conception : voir le commentaire ci-dessus.
    }
}

// Ce fichier est inclus de DEUX facons : par le routeur d'api.php quand l'action
// demandee est une action push, et par api/tracks.php ou api/import.php pour la
// seule fonction push_notify_new_track(). Dans ce second cas, $action vaut
// l'action en cours ("upload", "import_run") : rejouer le switch serait au mieux
// inutile, au pire un second traitement inattendu.
$PUSH_ACTIONS = ['push_config', 'push_subscribe', 'push_unsubscribe', 'push_pending', 'follow_artist', 'unfollow_artist', 'my_follows'];
if (!isset($action) || !in_array($action, $PUSH_ACTIONS, true)) return;

switch ($action) {

    // L'interface demande d'abord si les notifications sont possibles ici.
    case 'push_config':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $keys = push_vapid_keys($db);
        echo json_encode([
            'status' => 'success',
            'available' => $keys !== null,
            'public_key' => $keys['public'] ?? null,
        ]);
        break;

    case 'push_subscribe':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
        if (!push_endpoint_is_acceptable($endpoint)) {
            echo json_encode(["status" => "error", "message" => "Abonnement invalide."]); exit;
        }
        $db->prepare("INSERT OR REPLACE INTO push_subscriptions (user_id, endpoint, created_at) VALUES (?, ?, ?)")
           ->execute([(int) $auth['id'], $endpoint, time()]);
        echo json_encode(['status' => 'success']);
        break;

    case 'push_unsubscribe':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
        // Bornée au compte connecte : on ne desabonne jamais l'appareil de quelqu'un d'autre.
        $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?")
           ->execute([$endpoint, (int) $auth['id']]);
        echo json_encode(['status' => 'success']);
        break;

    // Lu par le service worker a la reception d'un push. Vide la file au passage :
    // une notification affichee ne doit pas revenir au prochain signal.
    //
    // Reste un GET : le service worker vit hors du document et n'a aucun acces au
    // jeton CSRF de la page, un POST protege lui serait donc inaccessible. Or
    // authenticate_api_user() ne verifie le jeton que sur les POST : ce GET
    // SUPPRIME des lignes et pouvait donc etre declenche depuis un site tiers pour
    // vider en silence la file de quelqu'un.
    //
    // La suppression est desormais conditionnee a Sec-Fetch-Site: same-origin, un
    // en-tete pose par le navigateur lui-meme et qu'une page tierce ne peut pas
    // falsifier. Une requete d'origine etrangere obtient donc une reponse en
    // LECTURE SEULE plutot qu'une erreur : elle ne peut de toute facon pas la lire
    // (api.php repond Access-Control-Allow-Origin: *, ce qui interdit justement les
    // requetes creditees), et degrader vaut mieux que casser les notifications sur
    // un navigateur qui n'enverrait pas cet en-tete.
    case 'push_pending':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $stmt = $db->prepare("SELECT id, title, body, url FROM push_queue WHERE user_id = ? ORDER BY id ASC LIMIT 10");
        $stmt->execute([(int) $auth['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sameOrigin = ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') === 'same-origin';
        if ($items && $sameOrigin) {
            $ids = implode(',', array_map(fn($i) => (int) $i['id'], $items));
            $db->exec("DELETE FROM push_queue WHERE id IN ($ids)");
        }
        echo json_encode(['status' => 'success', 'items' => $items], JSON_UNESCAPED_UNICODE);
        break;

    // --- Suivi d'artiste ----------------------------------------------------
    // La fonctionnalite demandee ("nouvel album pour les artistes suivis")
    // supposait un suivi d'artiste, qui n'existait pas.
    case 'follow_artist':
    case 'unfollow_artist':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $artist = sanitize_text($_POST['artist'] ?? '');
        if ($artist === '') { echo json_encode(["status" => "error", "message" => "Artiste invalide."]); exit; }

        if ($action === 'follow_artist') {
            $db->prepare("INSERT OR IGNORE INTO artist_follows (user_id, artist, created_at) VALUES (?, ?, ?)")
               ->execute([(int) $auth['id'], $artist, time()]);
        } else {
            $db->prepare("DELETE FROM artist_follows WHERE user_id = ? AND artist = ? COLLATE NOCASE")
               ->execute([(int) $auth['id'], $artist]);
        }
        echo json_encode(['status' => 'success', 'following' => $action === 'follow_artist']);
        break;

    case 'my_follows':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $stmt = $db->prepare("SELECT artist FROM artist_follows WHERE user_id = ? ORDER BY artist COLLATE NOCASE ASC");
        $stmt->execute([(int) $auth['id']]);
        echo json_encode(['status' => 'success', 'artists' => $stmt->fetchAll(PDO::FETCH_COLUMN)], JSON_UNESCAPED_UNICODE);
        break;
}
