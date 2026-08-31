<?php
/**
 * Choix de la qualité de streaming.
 *
 * PRÉCONDITION HONNÊTE : l'app ne stocke qu'UN fichier par piste. Proposer
 * plusieurs débits suppose donc de les fabriquer, c'est-à-dire de réencoder — ce
 * qui demande ffmpeg. Une instance auto-hébergée ne l'a pas forcément, et l'image
 * Docker du projet ne l'embarque pas aujourd'hui (elle pèserait ~80 Mo de plus).
 *
 * Le mécanisme est donc entièrement construit et se DÉTECTE lui-même :
 *   - ffmpeg présent  -> les débits réduits sont proposés et servis ;
 *   - ffmpeg absent   -> l'endpoint le dit, l'interface n'affiche pas le réglage,
 *                        et tout le monde continue d'écouter l'original.
 *
 * Pour l'activer, une seule ligne à ajouter au Dockerfile (voir le commentaire
 * dans ce fichier). C'est un choix qui appartient à celui qui héberge : il paie
 * la taille de l'image et le CPU du réencodage.
 */

// Débits proposés, en kbit/s. Liste FERMÉE : la valeur vient du client et sert à
// construire une ligne de commande, elle ne peut donc jamais être libre.
const QUALITY_BITRATES = [96, 128, 192];

function quality_ffmpeg_path(): ?string
{
    // Chemin explicite d'abord : permet de pointer un binaire hors du PATH sans
    // toucher au code.
    $configured = getenv('PURPLEMUSIC_FFMPEG');
    if ($configured && is_executable($configured)) return $configured;

    // Sinon on cherche dans le PATH. `command -v` plutôt que `which`, présent
    // partout, et 2>/dev/null pour ne pas polluer la sortie si rien n'est trouvé.
    $found = @shell_exec('command -v ffmpeg 2>/dev/null');
    $found = $found ? trim($found) : '';
    return ($found !== '' && is_executable($found)) ? $found : null;
}

/**
 * Chemin du fichier réencodé pour une piste et un débit, ou null.
 *
 * Le résultat est mis en cache sur disque : réencoder à chaque lecture brûlerait
 * le processeur du serveur pour produire exactement le même fichier. Le cache vit
 * dans music/_cache, à côté des sources.
 */
function quality_transcoded_path(string $musicDir, int $trackId, string $sourceFile, int $bitrate, bool $create = true): ?string
{
    if (!in_array($bitrate, QUALITY_BITRATES, true)) return null;

    $cacheDir = $musicDir . '/_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

    $dest = $cacheDir . '/' . $trackId . '_' . $bitrate . '.mp3';
    if (is_file($dest) && filesize($dest) > 0) return $dest;
    if (!$create) return null;

    $ffmpeg = quality_ffmpeg_path();
    if (!$ffmpeg) return null;

    $src = $musicDir . '/' . basename($sourceFile);
    if (!is_file($src)) return null;

    // Écriture dans un fichier temporaire puis renommage : sans ça, deux lectures
    // simultanées de la même piste liraient un fichier à moitié écrit. Le
    // renommage, lui, est atomique.
    $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.part';
    $cmd = escapeshellcmd($ffmpeg)
        . ' -nostdin -loglevel error -y -i ' . escapeshellarg($src)
        . ' -vn -c:a libmp3lame -b:a ' . (int) $bitrate . 'k '
        . escapeshellarg($tmp);
    @shell_exec($cmd);

    if (!is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); return null; }
    if (!@rename($tmp, $dest)) { @unlink($tmp); return null; }
    return $dest;
}

switch ($action) {

    // L'interface demande ce que le serveur sait faire avant de proposer quoi que
    // ce soit. Sans cet appel, on afficherait un réglage sans effet.
    case 'quality_options':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        echo json_encode([
            'status' => 'success',
            'available' => quality_ffmpeg_path() !== null,
            'bitrates' => QUALITY_BITRATES,
        ]);
        break;
}
