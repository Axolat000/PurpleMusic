<?php
switch ($action) {
    case 'get_lyrics':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["error" => "Non authentifié."]); exit; }

        $trackId = filter_var($_GET['q'] ?? 0, FILTER_VALIDATE_INT);
        if ($trackId === false || $trackId <= 0) { echo json_encode(['error' => "ID de piste invalide"]); exit; }

        $stmt = $db->prepare("SELECT title, artist, lyrics_synced, lyrics_plain, lyrics_checked_at FROM tracks WHERE id = ?");
        $stmt->execute([$trackId]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$track) { echo json_encode(['error' => "Piste introuvable"]); exit; }

        if ($track['lyrics_checked_at'] !== null) {
            $syncedCached = !empty($track['lyrics_synced']) ? $track['lyrics_synced'] : null;
            $plainCached = !empty($track['lyrics_plain']) ? $track['lyrics_plain'] : null;
            echo json_encode(['synced' => $syncedCached, 'plain' => $plainCached, 'found' => ($syncedCached !== null || $plainCached !== null), 'cached' => true]);
            break;
        }

        $queryTitle = html_entity_decode((string) $track['title'], ENT_QUOTES, 'UTF-8');
        $queryArtist = html_entity_decode((string) $track['artist'], ENT_QUOTES, 'UTF-8');
        $lrclibUrl = 'https://lrclib.net/api/get?' . http_build_query(['track_name' => $queryTitle, 'artist_name' => $queryArtist]);

        $synced = null; $plain = null; $found = false; $shouldCache = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($lrclibUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 8,
                // IMPORTANT : lrclib.net (Cloudflare) renvoie 520 pour les User-Agent génériques.
                CURLOPT_USERAGENT => 'PurpleMusic-Web/1.0 (+https://github.com/purplemusic; contact: fujinixx@gmail.com)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);

            if ($curlErrNo === 0 && $response !== false) {
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (is_array($data)) {
                        $synced = !empty($data['syncedLyrics']) ? $data['syncedLyrics'] : null;
                        $plain = !empty($data['plainLyrics']) ? $data['plainLyrics'] : null;
                        $found = ($synced !== null || $plain !== null);
                        $shouldCache = true;
                    }
                } elseif ($httpCode === 404) {
                    $found = false;
                    $shouldCache = true;
                }
            }
        }

        if ($shouldCache) {
            $db->prepare("UPDATE tracks SET lyrics_synced = ?, lyrics_plain = ?, lyrics_checked_at = ? WHERE id = ?")->execute([$synced, $plain, time(), $trackId]);
        }
        echo json_encode(['synced' => $synced, 'plain' => $plain, 'found' => $found, 'cached' => false]);
        break;

}
