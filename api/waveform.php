<?php
/**
 * Forme d'onde d'une piste : lecture et dépôt des pics.
 *
 * Le serveur ne calcule rien. Extraire des pics demanderait ffmpeg, une
 * dépendance binaire qu'une instance auto-hébergée n'a pas forcément, et dont
 * l'absence ferait disparaître la fonctionnalité sans explication. C'est donc le
 * navigateur qui décode la piste qu'il est déjà en train de lire, calcule les
 * pics une seule fois, et les dépose ici — le prochain auditeur, sur n'importe
 * quel appareil, les reçoit tout faits (voir js/waveform.js).
 *
 * Le serveur reste néanmoins le gardien du format : il n'accepte qu'un tableau
 * de la bonne taille, d'entiers dans la bonne plage. Sans cette validation,
 * n'importe quel client authentifié pourrait écrire n'importe quoi dans une
 * colonne qui finit dans le DOM de tout le monde.
 */

// Nombre de pics par piste. 120 : assez pour lire la structure d'un morceau
// (couplets, refrains, silence final) sur une barre large, assez peu pour tenir
// dans quelques centaines d'octets et rester net sur une barre étroite.
const WAVEFORM_POINTS = 120;

switch ($action) {

    case 'waveform':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $id = filter_var($_GET['q'] ?? 0, FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) { echo json_encode(["status" => "error", "message" => "Piste invalide."]); exit; }

        $stmt = $db->prepare("SELECT waveform, loudness, peak_amp FROM tracks WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // peaks = null signale au client qu'il doit le calculer lui-même. C'est un
        // cas normal (piste jamais encore lue), pas une erreur.
        $peaks = null;
        if (!empty($row['waveform'])) {
            $decoded = json_decode($row['waveform'], true);
            if (is_array($decoded) && count($decoded) === WAVEFORM_POINTS) $peaks = $decoded;
        }

        // Le niveau sonore voyage avec la forme d'onde : les deux sortent de la même
        // passe de décodage, et l'écran qui affiche l'une a besoin de l'autre au même
        // instant. Deux endpoints auraient doublé les allers-retours pour rien.
        echo json_encode([
            'status' => 'success',
            'track_id' => $id,
            'peaks' => $peaks,
            'loudness' => isset($row['loudness']) ? (float) $row['loudness'] : null,
            'peak_amp' => isset($row['peak_amp']) ? (float) $row['peak_amp'] : null,
        ]);
        break;

    case 'waveform_save':
        $auth = authenticate_api_user($db);
        if (!$auth) { echo json_encode(["status" => "error", "message" => "Accès refusé."]); exit; }

        $id = filter_var($_POST['track_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$id || $id <= 0) { echo json_encode(["status" => "error", "message" => "Piste invalide."]); exit; }

        $peaks = json_decode((string) ($_POST['peaks'] ?? ''), true);
        if (!is_array($peaks) || count($peaks) !== WAVEFORM_POINTS) {
            echo json_encode(["status" => "error", "message" => "Forme d'onde invalide."]); exit;
        }
        $clean = [];
        foreach ($peaks as $p) {
            if (!is_int($p) && !(is_string($p) && ctype_digit($p))) {
                echo json_encode(["status" => "error", "message" => "Forme d'onde invalide."]); exit;
            }
            $v = (int) $p;
            if ($v < 0 || $v > 100) { echo json_encode(["status" => "error", "message" => "Forme d'onde invalide."]); exit; }
            $clean[] = $v;
        }

        // Niveau sonore : facultatif (un client plus ancien n'en envoie pas), mais
        // si présent il doit être plausible. -70 dBFS est déjà du quasi-silence,
        // 0 dBFS le plein échelle : hors de cette plage, c'est une mesure fausse.
        $loudness = null;
        if (isset($_POST['loudness']) && $_POST['loudness'] !== '') {
            $l = filter_var($_POST['loudness'], FILTER_VALIDATE_FLOAT);
            if ($l === false || $l < -70 || $l > 0) { echo json_encode(["status" => "error", "message" => "Niveau sonore invalide."]); exit; }
            $loudness = $l;
        }
        $peakAmp = null;
        if (isset($_POST['peak_amp']) && $_POST['peak_amp'] !== '') {
            $pk = filter_var($_POST['peak_amp'], FILTER_VALIDATE_FLOAT);
            if ($pk === false || $pk <= 0 || $pk > 1) { echo json_encode(["status" => "error", "message" => "Crête invalide."]); exit; }
            $peakAmp = $pk;
        }

        // On n'écrase JAMAIS une analyse déjà déposée. Elles décrivent toutes le même
        // fichier : la seconde n'apporterait rien, et cette règle retire au passage
        // tout intérêt à en pousser une fausse par-dessus une bonne.
        $upd = $db->prepare("UPDATE tracks SET waveform = ?, loudness = ?, peak_amp = ? WHERE id = ? AND waveform IS NULL");
        $upd->execute([json_encode($clean), $loudness, $peakAmp, $id]);

        echo json_encode(['status' => 'success', 'stored' => $upd->rowCount() > 0]);
        break;
}
