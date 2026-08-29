<?php
/**
 * Biographie d'artiste, avec cache serveur.
 *
 * Le client interrogeait Wikipédia directement, à chaque ouverture d'une page
 * artiste : deux à trois requêtes réseau (résumé, puis recherche en cas
 * d'homonymie, puis résumé de la page trouvée) refaites par chaque visiteur et à
 * chaque visite, pour un contenu qui ne change pratiquement jamais.
 *
 * Ici la recherche est faite une seule fois côté serveur, puis mise en cache en
 * base — même approche que les paroles lrclib.net (voir api/lyrics.php).
 *
 * Un artiste sans page Wikipédia est mis en cache lui aussi (extract vide) :
 * sinon la recherche complète serait relancée à chaque affichage de sa page,
 * précisément le cas le plus coûteux puisqu'il épuise toutes les tentatives.
 */
switch ($action) {
    case 'artist_bio':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(['error' => 'Non authentifié.']); exit; }

        $name = trim((string) ($_GET['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) { echo json_encode(['error' => 'Nom invalide']); exit; }

        $lang = ($_GET['lang'] ?? 'fr') === 'en' ? 'en' : 'fr';
        // Clé insensible à la casse : "Ava Max" et "ava max" désignent le même
        // artiste et doivent partager une seule entrée.
        $key = mb_strtolower($name) . '|' . $lang;

        $db->exec("CREATE TABLE IF NOT EXISTS artist_bios (
            cache_key TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            lang TEXT NOT NULL,
            extract TEXT,
            url TEXT,
            fetched_at INTEGER NOT NULL
        )");

        // 30 jours : une biographie bouge rarement, mais un artiste sans page
        // aujourd'hui peut en avoir une plus tard — un cache éternel figerait
        // définitivement le "pas de bio".
        $TTL = 30 * 86400;

        $stmt = $db->prepare("SELECT extract, url, fetched_at FROM artist_bios WHERE cache_key = ?");
        $stmt->execute([$key]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cached && (time() - (int) $cached['fetched_at']) < $TTL) {
            echo json_encode([
                'extract' => $cached['extract'] ?: null,
                'url' => $cached['url'] ?: null,
                'found' => !empty($cached['extract']),
                'cached' => true,
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        $result = wikipedia_summary($name, $lang);
        // Repli sur l'anglais : beaucoup d'artistes n'ont pas de page française.
        if (!$result && $lang !== 'en') $result = wikipedia_summary($name, 'en');

        $extract = $result['extract'] ?? null;
        $url = $result['url'] ?? null;

        $db->prepare("INSERT INTO artist_bios (cache_key, name, lang, extract, url, fetched_at)
                      VALUES (?, ?, ?, ?, ?, ?)
                      ON CONFLICT(cache_key) DO UPDATE SET extract = excluded.extract, url = excluded.url, fetched_at = excluded.fetched_at")
           ->execute([$key, $name, $lang, $extract, $url, time()]);

        echo json_encode([
            'extract' => $extract,
            'url' => $url,
            'found' => $extract !== null,
            'cached' => false,
        ], JSON_UNESCAPED_UNICODE);
        break;
}
