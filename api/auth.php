<?php
switch ($action) {
    case 'login':
        // --- SÉCURITÉ : Rate limiting sur les tentatives de login ---
        if (!check_rate_limit($db)) {
            http_response_code(429);
            echo json_encode(["status" => "error", "message" => "Trop de tentatives. Réessayez dans 15 minutes."]);
            exit;
        }
        record_login_attempt($db);

        $auth = authenticate_api_user($db);
        if ($auth) {
            // terms_accepted/terms_url : présents uniquement sur une instance à jour qui gère les CGU --
            // un client (Android) qui ne connaît pas encore ces champs les ignore simplement, donc un
            // ancien serveur (sans cette colonne) reste compatible sans rien renvoyer de spécial.
            $termsStmt = $db->prepare("SELECT terms_accepted_at FROM users WHERE id = ?");
            $termsStmt->execute([$auth['id']]);
            echo json_encode([
                "status" => "success", "user_id" => $auth['id'], "username" => $auth['username'], "is_admin" => $auth['is_admin'],
                "terms_accepted" => (bool) $termsStmt->fetchColumn(),
                "terms_url" => $baseUrl . "cgu.php",
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Identifiants invalides"]);
        }
        break;

    case 'register':
        $u = $_POST['username'] ?? ''; $p = $_POST['password'] ?? '';
        if(empty($u) || empty($p)) { echo json_encode(["status" => "error", "message" => "Données manquantes"]); exit; }

        // --- SÉCURITÉ : Validation longueur username/password ---
        if (mb_strlen($u) > 50) { echo json_encode(["status" => "error", "message" => "Nom d'utilisateur trop long (50 caractères max)"]); exit; }
        if (mb_strlen($p) < 6)  { echo json_encode(["status" => "error", "message" => "Mot de passe trop court (6 caractères min)"]); exit; }
        if (mb_strlen($p) > 200) { echo json_encode(["status" => "error", "message" => "Mot de passe trop long"]); exit; }
        // accept_terms : requis aussi bien depuis le web (case à cocher, voir auth.php) que depuis
        // Android (voir AcceptTermsScreen -- montré uniquement si le serveur expose terms_url sur login).
        if (empty($_POST['accept_terms'])) { echo json_encode(["status" => "error", "message" => "Les CGU doivent être acceptées pour créer un compte"]); exit; }

        $u = htmlspecialchars(trim($u), ENT_QUOTES, 'UTF-8');
        try {
            $db->prepare("INSERT INTO users (username, password, terms_accepted_at) VALUES (?, ?, ?)")->execute([$u, password_hash($p, PASSWORD_DEFAULT), time()]);
            echo json_encode(["status" => "success"]);
        } catch(Exception $e) {
            echo json_encode(["status" => "error", "message" => "Nom d'utilisateur déjà pris"]);
        }
        break;

    // Accepte les CGU pour l'utilisateur authentifié -- utilisée par le web (écran de blocage, voir
    // templates/terms-gate.php) et par Android (AcceptTermsScreen, déclenché quand login renvoie
    // terms_accepted=false). Session ou username+password, comme toute action de ce fichier.
    case 'accept_terms':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }
        $db->prepare("UPDATE users SET terms_accepted_at = ? WHERE id = ?")->execute([time(), $auth['id']]);
        echo json_encode(["status" => "success"]);
        break;

    case 'change_password':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$auth['id']]);
        $u = $stmt->fetch();

        if (!$u || !password_verify($currentPassword, $u['password'])) {
            echo json_encode(['status' => 'error', 'message' => "Mot de passe actuel invalide."]); exit;
        }
        if ($newPassword !== $confirmPassword) { echo json_encode(['status' => 'error', 'message' => "Les mots de passe ne correspondent pas."]); exit; }
        if (mb_strlen($newPassword) < 6) { echo json_encode(['status' => 'error', 'message' => "Mot de passe trop court."]); exit; }
        if (mb_strlen($newPassword) > 200) { echo json_encode(['status' => 'error', 'message' => "Mot de passe trop long."]); exit; }

        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $auth['id']]);
        echo json_encode(['status' => 'success']);
        break;

    case 'admin_reset_password':
        $auth = authenticate_api_user($db);
        if (!$auth || !$auth['is_admin']) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $targetId = filter_var($_POST['target_user_id'] ?? '', FILTER_VALIDATE_INT);
        if ($targetId === false) { echo json_encode(['status' => 'error', 'message' => "Utilisateur introuvable."]); exit; }
        if ($targetId == $auth['id']) { echo json_encode(['status' => 'error', 'message' => "Impossible de te modifier toi-même."]); exit; }

        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        if (!$stmt->fetch()) { echo json_encode(['status' => 'error', 'message' => "Utilisateur introuvable."]); exit; }

        $newPassword = bin2hex(random_bytes(6));
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
        echo json_encode(['status' => 'success', 'password' => $newPassword]);
        break;

}
