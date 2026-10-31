<?php
/* Lance tout le jeu d'essai hors ligne. */
passthru(PHP_BINARY . " " . escapeshellarg(__DIR__ . "/moteur.php"), $code);
exit($code);
