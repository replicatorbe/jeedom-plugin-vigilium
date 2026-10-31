<?php
/* Lance tout le jeu d'essai hors ligne. */
$code = 0;
foreach (array('moteur.php', 'sorties.php') as $fichier) {
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/' . $fichier), $retour);
    $code = max($code, $retour);
}
exit($code);
