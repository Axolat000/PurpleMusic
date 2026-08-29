<?php
/**
 * Manifeste d'application web (PWA).
 *
 * Généré en PHP et non servi en .json statique : le nom du site, la couleur de
 * thème et l'icône sont configurables par instance depuis le Panel Admin. Un
 * fichier figé afficherait "Purple Music" et le violet par défaut sur une
 * instance renommée et recolorée.
 *
 * Le manifeste est ce qui rend l'app installable ; sans lui, aucun navigateur
 * ne propose "Installer l'application" ni ne l'ouvre en fenêtre autonome.
 */

session_start();

$dataDir = getenv('PURPLEMUSIC_DATA_DIR') ?: __DIR__;
$configFile = $dataDir . '/config.php';

$siteName = 'Purple Music';
$themeColor = '#8e44ad';
$bgColor = '#0f0c1d';
$icon = 'favicon.png';

// Instance pas encore installée : on sert un manifeste par défaut plutôt qu'une
// erreur — le navigateur ne doit jamais recevoir de JSON invalide ici.
if (file_exists($configFile)) {
    require_once $configFile;
    try {
        $db = new PDO('sqlite:' . DB_NAME);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $settings = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $siteName = $settings['site_name'] ?? $siteName;
        $themeColor = $settings['color_primary'] ?? $themeColor;
        $bgColor = $settings['color_bg'] ?? $bgColor;
        $icon = $settings['favicon'] ?? $icon;
    } catch (Exception $e) {
        // Base injoignable : on garde les valeurs par défaut ci-dessus.
    }
}

header('Content-Type: application/manifest+json; charset=utf-8');

// purpose "any maskable" : Android recadre l'icône dans la forme du lanceur
// (cercle, carré arrondi...). Sans "maskable", il ajoute lui-même un fond blanc
// autour de l'icône, ce qui la rend étrangère au reste du système.
echo json_encode([
    'name' => $siteName,
    'short_name' => mb_substr($siteName, 0, 12),
    'description' => 'Bibliothèque musicale auto-hébergée',
    'start_url' => './',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => $bgColor,
    'theme_color' => $themeColor,
    'lang' => $_COOKIE['purpleMusicLang'] ?? 'fr',
    'categories' => ['music', 'entertainment'],
    'icons' => [
        ['src' => $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    // Raccourcis du menu contextuel de l'icône installée (appui long sur Android,
    // clic droit sur la barre des tâches).
    'shortcuts' => [
        [
            'name' => 'Playlists',
            'url' => './?page=playlists',
        ],
        [
            'name' => 'Historique',
            'url' => './?page=history-page',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
