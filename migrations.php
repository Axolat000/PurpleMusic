<?php
/**
 * Migrations de schéma partagées entre index.php et api.php.
 *
 * Les deux scripts ouvrent la MÊME base (music_app.db) et doivent donc appliquer
 * les mêmes migrations : selon qu'un visiteur arrive par la page web ou que
 * l'app Android tape l'API en premier, c'est l'un ou l'autre qui crée les
 * colonnes manquantes. Historiquement, chacun portait sa propre copie du bloc de
 * migration, recopiée à la main et signalée par un commentaire « miroir de la
 * migration dans l'autre fichier » — deux copies à garder alignées, exactement le
 * genre de duplication qui finit par diverger.
 *
 * Les nouvelles migrations vivent ici, appelées par les deux. Les anciennes n'ont
 * pas été déplacées : elles sont déjà appliquées sur toutes les instances
 * existantes, les rejouer n'apporterait rien et les toucher pour rien serait un
 * risque gratuit sur une base de production.
 */

/**
 * Modèle Album : table dédiée + lien depuis les pistes.
 *
 * AVANT : un album n'était qu'une chaîne de caractères recopiée dans
 * `tracks.album`. Aucun endroit où stocker une pochette d'album, une année de
 * sortie ou un artiste d'album ; renommer un album demandait de réécrire toutes
 * les pistes une par une ; et deux orthographes (« Meteora » / « meteora  »)
 * donnaient deux albums distincts dans l'interface.
 *
 * MAINTENANT : une ligne par album, et `tracks.album_id` qui pointe dessus.
 *
 * `tracks.album` (le texte) est CONSERVÉ et tenu synchronisé, ce n'est pas un
 * oubli : c'est le champ que l'app Android lit dans action=list depuis toujours.
 * Le supprimer casserait un contrat déjà déployé sur des téléphones qu'on ne met
 * pas à jour d'un claquement de doigts. Le texte reste donc la valeur d'affichage,
 * l'identifiant devient la vraie relation — resolve_album() (api/helpers.php)
 * garantit que les deux ne divergent jamais.
 *
 * Unicité sur le NOM SEUL, pas sur (nom, artiste) : c'est déjà la règle de
 * regroupement de l'app aujourd'hui, et sur ce catalogue précis (rips YouTube,
 * multiples « feat. », beaucoup d'« Artiste inconnu ») grouper par artiste
 * éclaterait un même album en plusieurs dès qu'une piste porte un invité.
 * Conséquence assumée : deux « Greatest Hits » d'artistes différents fusionnent,
 * comme c'est déjà le cas.
 */
function purplemusic_migrate_albums(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS albums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        artist TEXT DEFAULT '',
        cover TEXT DEFAULT NULL,
        year INTEGER DEFAULT NULL,
        created_at INTEGER
    )");
    // COLLATE NOCASE : « Meteora » et « meteora » sont le même album, et l'index
    // le fait respecter par la base plutôt que par la discipline des appelants.
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_albums_name ON albums(name COLLATE NOCASE)");

    $cols = $db->query("PRAGMA table_info(tracks)")->fetchAll(PDO::FETCH_ASSOC);
    $hasAlbumId = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'album_id') $hasAlbumId = true;
    }
    if (!$hasAlbumId) {
        $db->exec("ALTER TABLE tracks ADD COLUMN album_id INTEGER DEFAULT NULL");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_tracks_album_id ON tracks(album_id)");
    }

    // Reprise des albums déjà saisis en texte. Ne s'exécute que sur les pistes
    // qui ont un nom d'album mais pas encore de lien : la migration est donc
    // rejouable sans effet de bord, et rattrape aussi les pistes importées par
    // une version antérieure du code après coup.
    $orphans = $db->query(
        "SELECT id, album, artist, cover FROM tracks
         WHERE album_id IS NULL AND TRIM(COALESCE(album, '')) <> ''
         ORDER BY play_count DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$orphans) return;

    $find = $db->prepare("SELECT id FROM albums WHERE name = ? COLLATE NOCASE");
    $insert = $db->prepare("INSERT INTO albums (name, artist, cover, created_at) VALUES (?, ?, ?, ?)");
    $link = $db->prepare("UPDATE tracks SET album_id = ? WHERE id = ?");

    $db->beginTransaction();
    try {
        foreach ($orphans as $row) {
            $name = trim($row['album']);
            $find->execute([$name]);
            $albumId = $find->fetchColumn();
            if ($albumId === false) {
                // Les pistes arrivent triées par nombre de lectures décroissant :
                // l'album hérite donc de l'artiste et de la pochette de sa piste
                // la plus écoutée, la plus représentative de ce qu'on a sous la main.
                $insert->execute([$name, $row['artist'] ?? '', $row['cover'] ?? null, time()]);
                $albumId = (int) $db->lastInsertId();
            }
            $link->execute([(int) $albumId, (int) $row['id']]);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Forme d'onde des pistes.
 *
 * Une colonne de plus sur `tracks` plutôt qu'une table : c'est une donnée
 * strictement 1-pour-1 avec la piste, dérivée d'elle, et qui meurt avec elle.
 *
 * Le calcul n'est PAS fait par le serveur. Extraire des pics demanderait ffmpeg,
 * une dépendance binaire qu'une instance auto-hébergée n'a pas forcément — et le
 * jour où elle manque, la fonctionnalité disparaît sans explication. C'est donc
 * le navigateur qui décode la piste qu'il est déjà en train de lire, calcule les
 * pics une seule fois, et les renvoie ici (action=waveform_save) : le prochain
 * auditeur, sur n'importe quel appareil, les reçoit tout faits.
 */
function purplemusic_migrate_waveform(PDO $db): void
{
    $cols = $db->query("PRAGMA table_info(tracks)")->fetchAll(PDO::FETCH_ASSOC);
    $have = [];
    foreach ($cols as $c) $have[$c['name']] = true;

    if (!isset($have['waveform'])) $db->exec("ALTER TABLE tracks ADD COLUMN waveform TEXT DEFAULT NULL");

    // Niveau sonore moyen (RMS en dBFS) et crete reelle (0-1), produits par la MEME
    // passe de decodage que la forme d'onde -- decoder une piste coute cher, on ne
    // le fait qu'une fois et on en tire tout ce dont on a besoin.
    //
    // La crete est indispensable a la normalisation : elle seule permet de savoir
    // de combien on peut remonter un morceau discret SANS le faire saturer. Sans
    // elle il faudrait un limiteur en bout de chaine, qui modifierait le son de
    // tout le monde au lieu de se contenter d'ajuster un gain.
    if (!isset($have['loudness'])) $db->exec("ALTER TABLE tracks ADD COLUMN loudness REAL DEFAULT NULL");
    if (!isset($have['peak_amp'])) $db->exec("ALTER TABLE tracks ADD COLUMN peak_amp REAL DEFAULT NULL");
}

/**
 * Journal des actions d'administration.
 *
 * Rien ne gardait trace de qui avait supprimé une piste, rétrogradé un compte ou
 * fusionné deux genres. Sur une instance à plusieurs administrateurs, la seule
 * réponse possible à « qui a supprimé ça ? » était : personne ne sait.
 *
 * Le nom d'utilisateur est recopié À CÔTÉ de l'identifiant, volontairement
 * dénormalisé : supprimer un compte ne doit pas effacer la trace de ce qu'il a
 * fait, et une jointure sur `users` rendrait justement ces lignes illisibles
 * après coup. C'est un journal, pas une table relationnelle vivante.
 */
function purplemusic_migrate_admin_log(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS admin_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        action TEXT NOT NULL,
        target TEXT,
        details TEXT,
        created_at INTEGER NOT NULL
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_admin_log_created ON admin_log(created_at)");
}

/**
 * Lien de partage d'une playlist, en lecture seule.
 *
 * Un jeton aléatoire par playlist, indépendant de son identifiant : sans lui, il
 * suffirait d'incrémenter un numéro pour tomber sur les playlists des autres. Le
 * jeton est aussi révocable — regénérer invalide instantanément tous les liens
 * déjà partagés, ce qu'un identifiant ne permet pas.
 *
 * NULL par défaut : une playlist n'est jamais partagée tant que son propriétaire
 * ne l'a pas explicitement demandé.
 */
function purplemusic_migrate_playlist_share(PDO $db): void
{
    $cols = $db->query("PRAGMA table_info(playlists)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if ($c['name'] === 'share_token') return;
    }
    $db->exec("ALTER TABLE playlists ADD COLUMN share_token TEXT DEFAULT NULL");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_playlists_share ON playlists(share_token)");
}

/**
 * Profil d'écoute public, par utilisateur.
 *
 * Désactivé par défaut, et ce n'est pas un détail : ce que quelqu'un écoute est
 * une donnée personnelle. Elle ne devient visible que si son propriétaire
 * l'active, jamais par défaut ni par décision d'un administrateur.
 */
function purplemusic_migrate_public_profile(PDO $db): void
{
    $cols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if ($c['name'] === 'profile_public') return;
    }
    $db->exec("ALTER TABLE users ADD COLUMN profile_public INTEGER DEFAULT 0");
}
