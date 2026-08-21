<?php
// --- SECURE AUTHENTICATION ---

if (isset($_POST['register'])) {
    if (!checkRateLimit('register', 30)) {
        $error = t('err_please_wait');
    } else {
        $regUsername = (string)($_POST['username'] ?? '');
        $regPassword = (string)($_POST['password'] ?? '');
        // confirm_password n'existe que depuis le formulaire web (voir index.php / authForm() dans app.js) : on ne
        // l'exige donc que si envoyé, pour rester rétro-compatible avec tout appelant qui ne le fournirait pas.
        $regConfirmPassword = array_key_exists('confirm_password', $_POST) ? (string)$_POST['confirm_password'] : null;

        // accept_terms n'existe que depuis le formulaire web (case à cocher, voir index.php / authForm()
        // dans app.js) -- absent = pas d'acceptation, refusé, même règle que côté API pour Android
        // (voir case 'register' dans api/auth.php, qui exige le même champ).
        if (trim($regUsername) === '') {
            $error = t('err_username_required');
        } elseif ($regConfirmPassword !== null && $regConfirmPassword !== $regPassword) {
            $error = t('err_password_mismatch');
        } elseif (mb_strlen($regPassword) < 6) {
            $error = t('err_password_too_short');
        } elseif (mb_strlen($regPassword) > 200) {
            $error = t('err_password_too_long');
        } elseif ($terms_enabled && empty($_POST['accept_terms'])) {
            $error = t('err_must_accept_terms');
        } else {
            // terms_accepted_at reflète simplement si la case a été cochée, peu importe si elle était
            // obligatoire -- si les CGU sont désactivées à l'inscription puis activées plus tard par un
            // admin, ce compte n'a jamais rien accepté et doit être bloqué comme n'importe quel compte
            // pré-existant (voir index.php : $terms_accepted).
            $termsAcceptedAt = !empty($_POST['accept_terms']) ? time() : null;
            $hash = password_hash($regPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, password, terms_accepted_at) VALUES (?, ?, ?)");
            try {
                $stmt->execute([$regUsername, $hash, $termsAcceptedAt]);
            } catch (Exception $e) {
                $error = t('err_username_taken');
            }
        }
    }
}

if (isset($_POST['login'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST['password'], $u['password'])) {
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['username'] = $u['username'];
        $_SESSION['is_admin'] = $u['is_admin'] ?? 0;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = t('err_invalid_credentials');
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;

$is_admin = false;
if ($user_id) {
    $stmtAdmin = $db->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmtAdmin->execute([$user_id]);
    $isAdminCol = $stmtAdmin->fetchColumn();
    $is_admin = ($isAdminCol == 1);
}
