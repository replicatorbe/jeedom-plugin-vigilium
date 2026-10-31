<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Un bouclier et un œil ouvert : une alarme qui veille. Dessiné en 1024 puis
 * réduit en 256 pour lisser les bords, que GD ne lisse pas sur un remplissage
 * direct. */

if (PHP_SAPI !== 'cli') {
    exit;
}

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$ardoise = imagecolorallocate($grand, 0x37, 0x47, 0x4F);
$veille  = imagecolorallocate($grand, 0xE5, 0x39, 0x35);
$blanc   = imagecolorallocate($grand, 0xFF, 0xFF, 0xFF);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

$polygone = function ($_image, $_points, $_couleur) {
    if (PHP_VERSION_ID >= 80000) {
        imagefilledpolygon($_image, $_points, $_couleur);
        return;
    }
    imagefilledpolygon($_image, $_points, (int) (count($_points) / 2), $_couleur);
};

/* Le bouclier : un haut droit, des flancs qui se resserrent vers la pointe. */
$points = array($e(128), $e(14), $e(226), $e(48), $e(226), $e(120));
for ($a = 0; $a <= 90; $a += 6) {
    $r = deg2rad($a);
    $points[] = $e(128 + 98 * cos($r));
    $points[] = $e(120 + 122 * sin($r));
}
for ($a = 90; $a <= 180; $a += 6) {
    $r = deg2rad($a);
    $points[] = $e(128 + 98 * cos($r));
    $points[] = $e(120 + 122 * sin($r));
}
array_push($points, $e(30), $e(120), $e(30), $e(48));
$polygone($grand, $points, $ardoise);

/* L'œil : une amande blanche, un iris rouge, une pupille ardoise. */
imagefilledellipse($grand, $e(128), $e(124), $e(150), $e(84), $blanc);
imagefilledellipse($grand, $e(128), $e(124), $e(62), $e(62), $veille);
imagefilledellipse($grand, $e(128), $e(124), $e(24), $e(24), $ardoise);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/vigilium_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
