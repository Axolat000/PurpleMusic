<?php
// Page publique, volontairement AUCUNE authentification requise : un visiteur pas encore inscrit doit
// pouvoir la lire avant de créer un compte (lien en bas de la page de connexion), et un compte dont
// terms_accepted_at est NULL doit pouvoir la lire depuis l'écran de blocage (voir templates/terms-gate.php).
session_start();
require_once 'i18n.php';

$dataDir = getenv('PURPLEMUSIC_DATA_DIR') ?: __DIR__;
$configFile = $dataDir . '/config.php';
if (!file_exists($configFile)) { http_response_code(503); exit; }
require_once $configFile;

$site_name = 'Purple Music';
// Placeholder générique par défaut (template open source) : chaque instance auto-hébergée renseigne son
// propre contact légal depuis le Panel Admin (onglet Général), jamais codé en dur dans le dépôt public.
$legal_contact_email = '[email]';
try {
    $db = new PDO('sqlite:' . DB_NAME);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $settingsRaw = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $site_name = $settingsRaw['site_name'] ?? $site_name;
    $legal_contact_email = $settingsRaw['legal_contact_email'] ?? $legal_contact_email;
    $color_bg = $settingsRaw['color_bg'] ?? '#0f0c1d';
    $color_panel = $settingsRaw['color_panel'] ?? '#1b1429';
    $color_primary = $settingsRaw['color_primary'] ?? '#8e44ad';
    $color_accent = $settingsRaw['color_accent'] ?? '#bb86fc';
    $color_text = $settingsRaw['color_text'] ?? '#e0e0e0';
    $color_text_muted = $settingsRaw['color_text_muted'] ?? '#a196b4';
    $color_border = $settingsRaw['color_border'] ?? '#3d2b56';
} catch (Exception $e) {
    $color_bg = '#0f0c1d'; $color_panel = '#1b1429'; $color_primary = '#8e44ad';
    $color_accent = '#bb86fc'; $color_text = '#e0e0e0'; $color_text_muted = '#a196b4'; $color_border = '#3d2b56';
}

$emailHtml = htmlspecialchars($legal_contact_email);
$emailLink = ($legal_contact_email !== '[email]') ? '<a href="mailto:' . $emailHtml . '">' . $emailHtml . '</a>' : $emailHtml;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($site_name); ?> — Conditions Générales d'Utilisation</title>
<style>
    :root {
        --bg-dark: <?php echo $color_bg; ?>;
        --bg-panel: <?php echo $color_panel; ?>;
        --primary: <?php echo $color_primary; ?>;
        --accent: <?php echo $color_accent; ?>;
        --text: <?php echo $color_text; ?>;
        --text-muted: <?php echo $color_text_muted; ?>;
        --border-color: <?php echo $color_border; ?>;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: var(--bg-dark); color: var(--text);
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        line-height: 1.6; padding: 48px 20px 100px;
    }
    .wrap { max-width: 720px; margin: 0 auto; }
    .back { display: inline-block; margin-bottom: 24px; color: var(--text-muted); text-decoration: none; font-size: 0.9em; }
    .back:hover { color: var(--accent); }
    h1 { font-size: 1.7em; margin: 0 0 6px; }
    .sub { color: var(--text-muted); font-size: 0.9em; margin: 0 0 40px; }
    article { background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 18px; padding: 24px 28px; margin-bottom: 18px; }
    article h2 { font-size: 1.15em; margin: 0 0 12px; color: var(--accent); }
    article p { margin: 0 0 12px; }
    article p:last-child { margin-bottom: 0; }
    article ul { margin: 0 0 12px; padding-left: 1.3em; }
    article li { margin-bottom: 6px; }
    .warn { border-color: rgba(255,71,87,0.35); background: rgba(255,71,87,0.06); }
    .warn h2 { color: #ff6b7a; }
    table { width: 100%; border-collapse: collapse; margin: 4px 0 12px; font-size: 0.92em; }
    td { padding: 8px 0; border-bottom: 1px solid var(--border-color); vertical-align: top; }
    td:first-child { color: var(--text); font-weight: 600; width: 38%; white-space: nowrap; }
    td:last-child { color: var(--text-muted); }
    a { color: var(--accent); }
    footer { text-align: center; color: var(--text-muted); font-size: 0.8em; margin-top: 30px; }
</style>
</head>
<body>
<div class="wrap">
    <a class="back" href="index.php">&larr; Retour à <?php echo htmlspecialchars($site_name); ?></a>
    <h1>Conditions Générales d'Utilisation</h1>
    <p class="sub">Version 1.0 — en vigueur depuis le 21 août 2026</p>

    <article>
        <h2>1. Objet</h2>
        <p><?php echo htmlspecialchars($site_name); ?> est un service de streaming musical communautaire, accessible via un site web et une application Android. Les présentes Conditions Générales d'Utilisation (« CGU ») définissent les règles applicables à toute personne créant un compte ou utilisant le service (« l'Utilisateur »), quelle que soit la plateforme d'accès.</p>
        <p>La création d'un compte ou l'usage du service vaut acceptation pleine et entière des présentes CGU. Un Utilisateur qui n'accepte pas ces conditions doit s'abstenir d'utiliser <?php echo htmlspecialchars($site_name); ?>.</p>
    </article>

    <article>
        <h2>2. Éditeur du service</h2>
        <p>Ce service est un projet personnel, non-commercial, édité et hébergé par une seule personne physique :</p>
        <table>
            <tr><td>Contact</td><td><?php echo $emailLink; ?></td></tr>
            <tr><td>Hébergement</td><td>Infrastructure personnelle de l'éditeur</td></tr>
            <tr><td>Nature du service</td><td>Gratuit, sans but commercial, à but non lucratif</td></tr>
        </table>
    </article>

    <article>
        <h2>3. Accès au service et compte utilisateur</h2>
        <p>L'inscription est ouverte à toute personne physique. La création d'un compte nécessite un nom d'utilisateur et un mot de passe ; aucune autre information personnelle n'est requise.</p>
        <ul>
            <li>L'Utilisateur s'engage à fournir un mot de passe qu'il n'utilise pas ailleurs et à ne le communiquer à personne.</li>
            <li>L'Utilisateur est seul responsable de toute activité effectuée depuis son compte, connexion et mots de passe compris.</li>
            <li>Un compte est personnel et ne doit pas être partagé ni transféré à un tiers.</li>
        </ul>
        <p>Le service n'étant pas destiné aux mineurs sans accord parental, l'Utilisateur certifie disposer de la capacité juridique nécessaire ou, à défaut, avoir obtenu l'autorisation de son représentant légal.</p>
    </article>

    <article class="warn">
        <h2>4. Contenus déposés par les utilisateurs</h2>
        <p><?php echo htmlspecialchars($site_name); ?> permet à chaque Utilisateur d'importer des fichiers musicaux (titre, artiste, pochette, genre) qui deviennent alors accessibles aux autres Utilisateurs du service.</p>
        <p><?php echo htmlspecialchars($site_name); ?> n'exerce <strong>aucun contrôle éditorial préalable</strong> sur les fichiers importés et ne vérifie pas les droits dont dispose l'Utilisateur sur les morceaux qu'il dépose. <strong>Chaque Utilisateur est seul et entièrement responsable de la légalité du contenu qu'il importe</strong>, y compris du respect du droit d'auteur et des droits voisins attachés à ce contenu. L'éditeur du service n'est en aucun cas responsable des morceaux mis en ligne par ses utilisateurs.</p>
        <p>En important un fichier, l'Utilisateur déclare et garantit :</p>
        <ul>
            <li>qu'il dispose des droits nécessaires sur ce fichier (œuvre dont il est l'auteur, contenu libre de droits, ou autorisation explicite du titulaire des droits) ;</li>
            <li>que la mise à disposition de ce fichier aux autres Utilisateurs ne viole aucun droit de tiers, notamment de droit d'auteur.</li>
        </ul>
        <p>Tout ayant droit estimant qu'un contenu porte atteinte à ses droits peut en demander le retrait à tout moment en écrivant à <?php echo $emailLink; ?>, en précisant le morceau concerné et la nature de l'atteinte. Ce contenu sera retiré dans les meilleurs délais, sans préjudice d'éventuelles suites données au compte de l'Utilisateur à l'origine du dépôt.</p>
    </article>

    <article>
        <h2>5. Playlists et contenus dérivés</h2>
        <p>Chaque Utilisateur peut créer des playlists à partir des morceaux disponibles sur le service, et choisir de les rendre <strong>publiques</strong> (visibles par tous les Utilisateurs) ou <strong>privées</strong> (visibles uniquement par leur créateur).</p>
        <p>Le nom et la pochette d'une playlist sont soumis aux mêmes règles de comportement que le reste du service (article 6), même sur une playlist privée.</p>
    </article>

    <article>
        <h2>6. Usages interdits</h2>
        <p>Sans que cette liste soit exhaustive, il est interdit d'utiliser le service pour :</p>
        <ul>
            <li>déposer, partager ou diffuser un contenu illicite, ou dont l'Utilisateur ne détient pas les droits ;</li>
            <li>harceler, menacer ou porter atteinte à un tiers ou à un autre Utilisateur ;</li>
            <li>tenter de contourner les mesures de sécurité du service ;</li>
            <li>accéder ou tenter d'accéder à des comptes, données ou fonctions qui ne sont pas les siens ;</li>
            <li>perturber le fonctionnement normal du service ;</li>
            <li>usurper l'identité d'un tiers.</li>
        </ul>
    </article>

    <article>
        <h2>7. Modération et sanctions</h2>
        <p>L'éditeur se réserve le droit, à sa discrétion et sans préavis, de retirer tout contenu contraire aux présentes CGU ou à la loi, et de suspendre ou supprimer tout compte à l'origine d'un manquement à ces règles.</p>
    </article>

    <article>
        <h2>8. Logiciel et propriété intellectuelle</h2>
        <p>Le code source de <?php echo htmlspecialchars($site_name); ?> (applications web et Android) est publié sous licence open source <strong>MIT</strong> et disponible publiquement. Cette licence porte uniquement sur le logiciel lui-même ; elle ne concerne en rien les contenus musicaux hébergés par une instance du service, dont le régime est défini à l'article 4.</p>
    </article>

    <article>
        <h2>9. Données personnelles</h2>
        <p>Conformément au Règlement Général sur la Protection des Données (RGPD) :</p>
        <table>
            <tr><td>Identifiants</td><td>Nom d'utilisateur et mot de passe (jamais stocké en clair, seule son empreinte l'est).</td></tr>
            <tr><td>Historique d'écoute</td><td>Morceaux écoutés et durée d'écoute, utilisés pour les compteurs de lecture et les recommandations personnalisées.</td></tr>
            <tr><td>Likes</td><td>Morceaux « likés » par l'Utilisateur.</td></tr>
            <tr><td>Contenus déposés</td><td>Fichiers musicaux, pochettes et playlists importés ou créés par l'Utilisateur.</td></tr>
            <tr><td>Adresse IP</td><td>Conservée brièvement lors des tentatives de connexion, uniquement pour limiter les tentatives abusives.</td></tr>
        </table>
        <p>Ces données ne sont utilisées que pour faire fonctionner le service lui-même. Aucune donnée n'est vendue, cédée ou transmise à un tiers à des fins commerciales ou publicitaires. Conformément au RGPD, l'Utilisateur dispose d'un droit d'accès, de rectification, d'effacement, de limitation et de portabilité sur les données le concernant, en écrivant à <?php echo $emailLink; ?>.</p>
    </article>

    <article>
        <h2>10. Cookies et stockage local</h2>
        <p>Le service utilise un cookie de session, strictement nécessaire pour maintenir la connexion et protéger contre les attaques CSRF, ainsi qu'un cookie de préférence de langue. Aucun cookie publicitaire ou de traçage tiers n'est utilisé. Certaines préférences d'affichage sont enregistrées localement dans le navigateur et ne sont jamais transmises au serveur.</p>
    </article>

    <article>
        <h2>11. Disponibilité et responsabilité</h2>
        <p>Ce service est un projet personnel fourni « en l'état », sans garantie de disponibilité, de continuité ou d'absence d'erreur. Dans les limites autorisées par la loi, l'éditeur ne saurait être tenu responsable des dommages directs ou indirects résultant de l'utilisation ou de l'impossibilité d'utiliser le service.</p>
    </article>

    <article>
        <h2>12. Résiliation</h2>
        <p>L'Utilisateur peut cesser d'utiliser le service à tout moment et demander la suppression de son compte et des données associées en écrivant à <?php echo $emailLink; ?>. L'éditeur peut suspendre ou supprimer un compte en cas de manquement aux présentes CGU.</p>
    </article>

    <article>
        <h2>13. Modification des CGU</h2>
        <p>Les présentes CGU peuvent être modifiées à tout moment. La date de mise à jour en tête de ce document fait foi.</p>
    </article>

    <article>
        <h2>14. Droit applicable et contact</h2>
        <p>Les présentes CGU sont soumises au droit français. Pour toute question, un seul contact : <?php echo $emailLink; ?>.</p>
    </article>

    <footer><?php echo htmlspecialchars($site_name); ?> — CGU v1.0</footer>
</div>
</body>
</html>
