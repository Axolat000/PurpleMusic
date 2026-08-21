<?php
switch ($action) {
    case 'report_listen':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        $seconds = filter_var($_POST['seconds'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0 || $seconds === false || $seconds < 0) {
            echo json_encode(["status" => "error", "message" => "Paramètres invalides"]); exit;
        }
        $seconds = min($seconds, 24 * 3600); // garde-fou anti-abus, une piste ne dure jamais 24h

        $db->prepare("INSERT INTO listen_events (track_id, user_id, listened_seconds, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$track_id, $auth['id'], $seconds, time()]);

        $counted = $seconds >= 10;
        if ($counted) {
            $db->prepare("UPDATE tracks SET play_count = play_count + 1 WHERE id = ?")->execute([$track_id]);
        }
        echo json_encode(["status" => "success", "counted" => $counted]);
        break;

    case 'toggle_like':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $track_id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($track_id === false || $track_id <= 0) { echo json_encode(["status" => "error", "message" => "ID invalide"]); exit; }

        $existing = $db->prepare("SELECT 1 FROM likes WHERE user_id = ? AND track_id = ?");
        $existing->execute([$auth['id'], $track_id]);
        if ($existing->fetch()) {
            $db->prepare("DELETE FROM likes WHERE user_id = ? AND track_id = ?")->execute([$auth['id'], $track_id]);
            $liked = false;
        } else {
            $db->prepare("INSERT INTO likes (user_id, track_id, created_at) VALUES (?, ?, ?)")->execute([$auth['id'], $track_id, time()]);
            $liked = true;
        }
        $countStmt = $db->prepare("SELECT COUNT(*) FROM likes WHERE track_id = ?");
        $countStmt->execute([$track_id]);
        echo json_encode(["status" => "success", "liked" => $liked, "like_count" => (int) $countStmt->fetchColumn()]);
        break;

    case 'my_likes':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $stmt = $db->prepare("SELECT track_id FROM likes WHERE user_id = ?");
        $stmt->execute([$auth['id']]);
        echo json_encode(["liked_ids" => array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))]);
        break;

    case 'recommendations':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        // full=1 : classement complet plutôt que le top 20 -- voir la même option côté index.php.
        $recoLimit = !empty($_GET['full']) || !empty($_POST['full']) ? PHP_INT_MAX : 20;
        echo json_encode(build_recommendations($db, $auth['id'], $baseUrl, $recoLimit));
        break;

}
