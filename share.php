<?php
/**
 * Page publique d'une playlist partagée, en lecture seule.
 *
 * Atteinte par share.php?t=<jeton>, sans compte et sans session. C'est la SEULE
 * page de l'app accessible sans authentification en dehors de la connexion, donc
 * la seule qui mérite qu'on énumère explicitement ce qu'elle expose :
 *
 *   - le nom de la playlist, sa pochette et ses pistes (titre, artiste, durée) ;
 *   - le prénom d'affichage du créateur, parce qu'une playlist partagée sans
 *     auteur n'a pas de sens ;
 *   - rien d'autre. Aucune navigation vers le reste de la bibliothèque, aucune
 *     liste d'utilisateurs, aucun compteur d'écoute, aucun jeton CSRF, aucun
 *     script applicatif : cette page ne charge pas app.js et n'a pas de store.
 *
 * Le jeton est la seule autorisation. Il est aléatoire sur 128 bits, révocable à
 * tout moment par le propriétaire, et NULL par défaut — une playlist n'est jamais
 * exposée ici tant que quelqu'un n'a pas explicitement demandé un lien.
 *
 * Note sur le flux audio : api.php?action=stream est déjà public pour tous
 * (contrat historique de l'app Android, qui ne présente pas de session pour
 * lire). Cette page n'ouvre donc aucun accès nouveau au fichier lui-même : elle
 * ne fait que révéler QUELLES pistes composent la playlist.
 */

require_once __DIR__ . '/i18n.php';

$dataDir = getenv('PURPLEMUSIC_DATA_DIR') ?: __DIR__;
$configFile = $dataDir . '/config.php';
if (!file_exists($configFile)) { http_response_code(404); exit('Not found'); }
require_once $configFile;

$token = (string) ($_GET['t'] ?? '');
// Format vérifié avant toute requête : un jeton est 32 caractères hexadécimaux,
// tout le reste est refusé sans même consulter la base.
if (!preg_match('/^[0-9a-f]{32}$/', $token)) { http_response_code(404); exit('Not found'); }

try {
    $db = new PDO('sqlite:' . DB_NAME);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.cover, p.song_ids, u.username
         FROM playlists p JOIN users u ON u.id = p.creator_id
         WHERE p.share_token = ?"
    );
    $stmt->execute([$token]);
    $playlist = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500); exit('Error');
}

// Jeton inconnu ou révoqué : même réponse que pour un jeton mal formé. On ne
// distingue pas les deux cas, sinon la page confirmerait l'existence d'un lien
// qui vient d'être révoqué.
if (!$playlist) { http_response_code(404); exit('Not found'); }

// song_ids est une liste d'identifiants séparés par des virgules (format
// historique de la table). L'ordre de la playlist EST cet ordre : on ne trie donc
// pas le résultat SQL, on le réordonne selon la liste.
$ids = array_values(array_filter(array_map('intval', explode(',', (string) $playlist['song_ids'])), fn($v) => $v > 0));
$tracks = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tstmt = $db->prepare("SELECT id, title, artist, album, cover, duration FROM tracks WHERE id IN ($placeholders)");
    $tstmt->execute($ids);
    $byId = [];
    foreach ($tstmt->fetchAll(PDO::FETCH_ASSOC) as $t) $byId[(int) $t['id']] = $t;
    foreach ($ids as $id) {
        if (isset($byId[$id])) $tracks[] = $byId[$id];
    }
}

$totalSeconds = 0;
foreach ($tracks as $t) $totalSeconds += (int) $t['duration'];

function share_duration(int $seconds): string {
    if ($seconds <= 0) return '';
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return $m . ':' . str_pad((string) $s, 2, '0', STR_PAD_LEFT);
}

$siteName = 'Purple Music';
try {
    $siteName = $db->query("SELECT value FROM settings WHERE key = 'site_name'")->fetchColumn() ?: 'Purple Music';
} catch (Exception $e) { /* réglages absents : le nom par défaut suffit */ }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($playlist['name']); ?> — <?php echo htmlspecialchars($siteName); ?></title>
    <?php /* noindex : un lien de partage se transmet de la main à la main. Qu'il
             finisse dans un index de moteur de recherche viderait le jeton de son
             sens -- il ne serait plus secret, juste long. */ ?>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preload" href="fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/ui.css">
    <style>
        /* Page autonome : pas de barre latérale, pas de lecteur flottant, donc pas
           de la réserve de 160px que base.css laisse en bas pour la mini-barre. */
        body { padding-bottom: 40px; }
        .share-wrap { max-width: 820px; margin: 0 auto; padding: var(--space-8) var(--space-5); }
        .share-head { display: flex; align-items: center; gap: var(--space-6); margin-bottom: var(--space-8); flex-wrap: wrap; }
        .share-cover { width: 148px; height: 148px; border-radius: var(--radius-lg); object-fit: cover; box-shadow: var(--elev-2, 0 20px 50px rgba(0,0,0,0.4)); flex-shrink: 0; }
        .share-meta { min-width: 0; }
        .share-kind { font-size: var(--text-sm); letter-spacing: var(--tracking-caps); text-transform: uppercase; color: var(--text-muted); font-weight: var(--weight-bold); }
        .share-title { font-size: var(--text-2xl); font-weight: 800; margin: var(--space-2) 0; overflow-wrap: anywhere; }
        .share-sub { color: var(--text-muted); font-size: var(--text-sm); }
        .share-row { display: grid; grid-template-columns: 34px 44px minmax(0, 1fr) auto; align-items: center; gap: var(--space-3); padding: var(--space-2) var(--space-3); border-radius: var(--radius-md); cursor: pointer; }
        .share-row:hover { background: var(--tint-1); }
        .share-row.playing { background: var(--tint-2); }
        .share-index { color: var(--text-muted); font-family: var(--font-mono); font-size: var(--text-sm); text-align: right; }
        .share-thumb { width: 44px; height: 44px; border-radius: var(--radius-xs); object-fit: cover; }
        .share-track-title { font-weight: var(--weight-medium); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .share-track-artist { color: var(--text-muted); font-size: var(--text-sm); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .share-dur { color: var(--text-muted); font-family: var(--font-mono); font-size: var(--text-sm); }
        .share-player { position: sticky; bottom: 0; background: var(--bg-panel); border: 1px solid var(--hairline); border-radius: var(--radius-lg); padding: var(--space-3) var(--space-4); margin-top: var(--space-6); }
        .share-player audio { width: 100%; }
        .share-footer { margin-top: var(--space-8); text-align: center; color: var(--text-muted); font-size: var(--text-sm); }
        .share-footer a { color: var(--accent); }
    </style>
</head>
<body>
    <main class="share-wrap">
        <header class="share-head">
            <img class="share-cover" src="covers/<?php echo htmlspecialchars($playlist['cover'] ?: 'default.png'); ?>"
                 alt="" onerror="this.src='covers/default.png'">
            <div class="share-meta">
                <p class="share-kind"><?php echo t('share_kind'); ?></p>
                <h1 class="share-title"><?php echo htmlspecialchars($playlist['name']); ?></h1>
                <p class="share-sub">
                    <?php echo t('share_by', ['name' => htmlspecialchars($playlist['username'])]); ?>
                    · <?php echo t('tracks_count_label', ['n' => count($tracks)]); ?>
                    <?php if ($totalSeconds > 0): ?> · <?php echo share_duration($totalSeconds); ?><?php endif; ?>
                </p>
            </div>
        </header>

        <?php if (!$tracks): ?>
            <p style="color:var(--text-muted);"><?php echo t('share_empty'); ?></p>
        <?php else: ?>
            <div class="track-list">
                <?php foreach ($tracks as $i => $t): ?>
                <div class="share-row" data-src="api.php?action=stream&amp;q=<?php echo (int) $t['id']; ?>"
                     data-title="<?php echo htmlspecialchars($t['title']); ?>"
                     role="button" tabindex="0"
                     aria-label="<?php echo htmlspecialchars($t['title'] . ' — ' . $t['artist']); ?>">
                    <span class="share-index"><?php echo $i + 1; ?></span>
                    <img class="share-thumb" src="covers/<?php echo htmlspecialchars($t['cover'] ?: 'default.png'); ?>"
                         alt="" loading="lazy" onerror="this.src='covers/default.png'">
                    <span>
                        <span class="share-track-title"><?php echo htmlspecialchars($t['title']); ?></span><br>
                        <span class="share-track-artist"><?php echo htmlspecialchars($t['artist']); ?></span>
                    </span>
                    <span class="share-dur"><?php echo share_duration((int) $t['duration']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php /* Le lecteur natif du navigateur, volontairement : cette page n'a pas
                     de file d'attente, pas d'egaliseur, pas de theme dynamique. Y
                     rembarquer le lecteur de l'app supposerait d'y charger tout son
                     JavaScript -- pour une page qui n'a le droit de rien faire. */ ?>
            <div class="share-player">
                <p id="share-now" class="share-sub" style="margin:0 0 var(--space-2);"><?php echo t('share_pick'); ?></p>
                <audio id="share-audio" controls preload="none"></audio>
            </div>
        <?php endif; ?>

        <p class="share-footer">
            <?php echo t('share_footer', ['site' => htmlspecialchars($siteName)]); ?>
        </p>
    </main>

    <script>
        // Une trentaine de lignes, en clair dans la page : aucun script applicatif
        // n'est charge ici (voir l'en-tete du fichier).
        (function () {
            var audio = document.getElementById('share-audio');
            var now = document.getElementById('share-now');
            if (!audio) return;
            var rows = Array.prototype.slice.call(document.querySelectorAll('.share-row'));

            function play(row) {
                rows.forEach(function (r) { r.classList.toggle('playing', r === row); });
                audio.src = row.getAttribute('data-src');
                now.textContent = row.getAttribute('data-title');
                audio.play();
            }

            rows.forEach(function (row, i) {
                row.addEventListener('click', function () { play(row); });
                row.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); play(row); }
                });
                // Enchainement automatique : sans lui, une playlist partagee
                // s'arreterait apres chaque titre et il faudrait cliquer 20 fois.
                row.dataset.index = i;
            });

            audio.addEventListener('ended', function () {
                var current = document.querySelector('.share-row.playing');
                if (!current) return;
                var next = rows[parseInt(current.dataset.index, 10) + 1];
                if (next) play(next);
            });
        })();
    </script>
</body>
</html>
