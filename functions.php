<?php
// --- SÉCURITÉ : Constantes de validation (partagées avec api.php) ---
if (!defined('MAX_AUDIO_SIZE'))  define('MAX_AUDIO_SIZE', 100 * 1024 * 1024); // 100 Mo
if (!defined('MAX_IMAGE_SIZE'))  define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);   // 5 Mo
if (!defined('MAX_FIELD_LENGTH')) define('MAX_FIELD_LENGTH', 200);

// --- SÉCURITÉ : Validation et nettoyage des champs texte ---
function sanitize_text($value, $max_length = MAX_FIELD_LENGTH) {
    $value = trim($value);
    if (mb_strlen($value) > $max_length) {
        $value = mb_substr($value, 0, $max_length);
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// --- SÉCURITÉ : Vérification du type MIME réel d'un fichier audio ---
function is_valid_audio($path, $ext) {
    $allowedExts = ['mp3', 'wav', 'ogg', 'flac'];
    if (!in_array($ext, $allowedExts)) return false;

    $fp = fopen($path, 'rb');
    if (!$fp) return false;
    $sig = fread($fp, 12);
    fclose($fp);

    if (substr($sig, 0, 3) === 'ID3') return true;
    if (strlen($sig) > 1 && (ord($sig[0]) === 0xFF) && ((ord($sig[1]) & 0xE0) === 0xE0)) return true;
    if (substr($sig, 0, 4) === 'RIFF' && substr($sig, 8, 4) === 'WAVE') return true;
    if (substr($sig, 0, 4) === 'OggS') return true;
    if (substr($sig, 0, 4) === 'fLaC') return true;

    return false;
}

// --- FONCTIONS DE CALCUL & EXTRACTION ID3 ---

function calculateAudioDuration($path) {
    if (!file_exists($path)) return 0;
    $fp = fopen($path, 'rb'); if (!$fp) return 0;
    $signature = fread($fp, 4);
    if ($signature === 'fLaC') {
        fseek($fp, 8); $streamInfo = fread($fp, 34); fclose($fp);
        if (strlen($streamInfo) === 34) {
            $fields = unpack('N3', substr($streamInfo, 10, 12));
            $sampleRate = ($fields[1] >> 12) & 0xFFFFF; $totalSamples = (($fields[1] & 0x00F) << 32) | $fields[2];
            if ($sampleRate > 0) return round($totalSamples / $sampleRate);
        }
        return 0;
    }
    if (strpos($signature, 'ftyp') !== false || substr($signature, 1, 3) === 'ftyp') {
        fseek($fp, 0); $content = fread($fp, 1024 * 400); $mvhdPos = strpos($content, 'mvhd'); fclose($fp);
        if ($mvhdPos !== false) {
            $version = ord($content[$mvhdPos + 4]);
            $timeScaleOffset = ($version === 1) ? 20 : 12; $durationOffset = ($version === 1) ? 24 : 16;
            $timeScale = unpack('N', substr($content, $mvhdPos + 4 + $timeScaleOffset, 4))[1];
            $durationUnits = unpack('N', substr($content, $mvhdPos + 4 + $durationOffset, 4))[1];
            if ($timeScale > 0) return round($durationUnits / $timeScale);
        }
        return 0;
    }
    fseek($fp, 0); $header = fread($fp, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $b = unpack('C*', substr($header, 6, 4)); $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
        fseek($fp, $tagSize + 10);
    } else { fseek($fp, 0); }
    $data = fread($fp, 1024 * 200); $offset = 0;
    while ($offset < strlen($data) - 4) {
        if (ord($data[$offset]) === 0xFF && (ord($data[$offset+1]) & 0xE0) === 0xE0) {
            $byte1 = ord($data[$offset+1]); $byte2 = ord($data[$offset+2]); $mpegVersion = ($byte1 >> 3) & 0x03;
            $channelMode = ($byte2 >> 6) & 0x03; $xingOffset = ($mpegVersion === 3) ? (($channelMode === 3) ? 17 : 32) : (($channelMode === 3) ? 9 : 17);
            $vbrCheck = substr($data, $offset + 4 + $xingOffset, 4);
            if ($vbrCheck === 'Xing' || $vbrCheck === 'Info') {
                $flags = unpack('N', substr($data, $offset + 4 + $xingOffset + 4, 4))[1];
                if ($flags & 0x01) {
                    $frameCount = unpack('N', substr($data, $offset + 4 + $xingOffset + 8, 4))[1];
                    $srTable = [3 => [44100, 48000, 32000, 0], 2 => [22050, 24000, 16000, 0]];
                    $sampleRate = $srTable[$mpegVersion][($byte2 >> 2) & 0x03] ?? 44100; $samplesPerFrame = ($mpegVersion === 3) ? 1152 : 576;
                    fclose($fp); if ($sampleRate > 0) return round(($frameCount * $samplesPerFrame) / $sampleRate);
                }
            }
            $brTable = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0]; $bitrate = $brTable[($byte2 >> 4) & 0x0F] ?? 128;
            fclose($fp); if ($bitrate > 0) return round((filesize($path) * 8) / ($bitrate * 1000));
            break;
        }
        $offset++;
    }
    fclose($fp); return round((filesize($path) * 8) / (128 * 1000));
}

function extractMp3Data($path) {
    if (!file_exists($path)) return [];
    $f = fopen($path, 'rb'); if (!$f) return [];
    $header = fread($f, 10); if (substr($header, 0, 3) !== 'ID3') { fclose($f); return []; }
    $b = unpack('C*', substr($header, 6, 4)); $tagSize = ($b[1] << 21) | ($b[2] << 14) | ($b[3] << 7) | $b[4];
    $tagData = fread($f, $tagSize); fclose($f);
    $result = ['cover' => null, 'artist' => null, 'title' => null]; $pos = 0;
    while ($pos < $tagSize) {
        if ($pos + 10 > strlen($tagData)) break;
        $frameHeader = substr($tagData, $pos, 10); $frameName = substr($frameHeader, 0, 4);
        $s = unpack('N', substr($frameHeader, 4, 4)); $frameSize = $s[1];
        if ($frameSize == 0 || $frameName == "\x00\x00\x00\x00") break;
        if ($pos + 10 + $frameSize > strlen($tagData)) break;
        if ($frameName === 'APIC') {
            $frameBody = substr($tagData, $pos + 10, $frameSize); $nullPos = strpos($frameBody, "\x00", 1);
            if ($nullPos !== false) {
                $mime = substr($frameBody, 1, $nullPos - 1); $picTypePos = $nullPos + 1; $imgStart = 0;
                $jpgPos = strpos($frameBody, "\xFF\xD8", $picTypePos); $pngPos = strpos($frameBody, "\x89PNG", $picTypePos);
                if ($mime === 'image/jpeg' || $mime === 'image/jpg') { if ($jpgPos !== false) $imgStart = $jpgPos; }
                elseif ($mime === 'image/png') { if ($pngPos !== false) $imgStart = $pngPos; }
                else {
                    if ($jpgPos !== false && ($pngPos === false || $jpgPos < $pngPos)) { $imgStart = $jpgPos; $mime = 'image/jpeg'; }
                    elseif ($pngPos !== false) { $imgStart = $pngPos; $mime = 'image/png'; }
                }
                if ($imgStart > 0) $result['cover'] = ['mime' => $mime, 'data' => substr($frameBody, $imgStart)];
            }
        }
        if ($frameName === 'TPE1') {
            $frameBody = substr($tagData, $pos + 10, $frameSize);
            if (strlen($frameBody) > 1) {
                $rawText = substr($frameBody, 1); $cleanText = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $rawText));
                if (!empty($cleanText)) $result['artist'] = $cleanText;
            }
        }
        if ($frameName === 'TIT2') {
            $frameBody = substr($tagData, $pos + 10, $frameSize);
            if (strlen($frameBody) > 1) {
                $rawText = substr($frameBody, 1); $cleanText = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $rawText));
                if (!empty($cleanText)) $result['title'] = $cleanText;
            }
        }
        $pos += 10 + $frameSize;
    }
    return $result;
}

function optimizeImage($sourcePath, $destinationPath, $mime = null) {
    if (!extension_loaded('gd')) return move_uploaded_file($sourcePath, $destinationPath);
    $info = getimagesize($sourcePath); if (!$info) return false;
    $mime = $mime ?? $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($sourcePath); break;
        case 'image/png': $image = imagecreatefrompng($sourcePath); break;
        case 'image/webp': $image = imagecreatefromwebp($sourcePath); break;
        case 'image/gif': $image = imagecreatefromgif($sourcePath); break;
        default: return false;
    }
    if (!$image) return false;
    $width = imagesx($image); $height = imagesy($image); $max_size = 300;
    if ($width > $max_size || $height > $max_size) {
        $ratio = min($max_size / $width, $max_size / $height);
        $new_width = round($width * $ratio); $new_height = round($height * $ratio);
        $new_image = imagecreatetruecolor($new_width, $new_height);
        if ($mime == 'image/png') { imagealphablending($new_image, false); imagesavealpha($new_image, true); }
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image); $image = $new_image;
    }
    $success = imagewebp($image, $destinationPath, 80); imagedestroy($image);
    if (!$success) move_uploaded_file($sourcePath, $destinationPath);
    return true;
}

function checkRateLimit($action, $limitSeconds) {
    $key = 'last_' . $action . '_time';
    if (isset($_SESSION[$key]) && (time() - $_SESSION[$key]) < $limitSeconds) {
        return false;
    }
    $_SESSION[$key] = time();
    return true;
}

