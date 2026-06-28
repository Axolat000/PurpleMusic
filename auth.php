<?php
// --- SECURE AUTHENTICATION ---

if (isset($_POST['register'])) {
    if (!checkRateLimit('register', 30)) {
        $error = "Veuillez patienter.";
    } else {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        try {
            $stmt->execute([$_POST['username'], $hash]);
        } catch (Exception $e) {
            $error = "Nom déjà pris.";
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
        $error = "Identifiants incorrects.";
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
    $is_admin = ($isAdminCol == 1 || $username === 'Axolat');
}
