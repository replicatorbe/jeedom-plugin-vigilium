<?php
/* Jeu d'essai hors ligne du planificateur de réactions.
 *
 *   php tests/sorties.php
 *
 * vigiliumSorties::planifier() est pur : il dit quelles listes d'actions
 * jouer, et quand relancer. L'exécution, elle, demande Jeedom. */

require_once __DIR__ . '/../core/class/vigiliumSorties.class.php';

$echecs = 0;
$verifications = 0;

function verifier($_condition, $_message) {
    global $echecs, $verifications;
    $verifications++;
    if (!$_condition) {
        $echecs++;
        echo "  ÉCHEC : $_message\n";
    }
}

function scenario($_titre, $_fonction) {
    echo "- $_titre\n";
    $_fonction();
}

function d($_genre, $_detail = 'x', $_capteur = 0) {
    return array('genre' => $_genre, 'detail' => $_detail, 'capteur' => $_capteur);
}

function declencheurs($_plan) {
    return array_map(function ($_e) { return $_e['declencheur']; }, $_plan);
}

$relance = array('minutes' => 5, 'max' => 3, 'listes' => true);

scenario('Chaque genre qui agit donne son déclencheur, les infos rien', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('info'), d('suspicion'), d('sabotage'), d('defaut')), 'calme', 'suspicion', null, $relance, 100);
    verifier(declencheurs($plan) === array('suspicion', 'sabotage', 'defaut'), 'obtenu ' . implode(',', declencheurs($plan)));
    verifier($etat['relance'] === null, 'pas de relance hors alarme');
});

scenario('Alarme : déclencheur et relance programmée', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme', 'Alarme : Baie')), 'calme', 'alarme', null, $relance, 100);
    verifier(declencheurs($plan) === array('alarme'), 'obtenu ' . implode(',', declencheurs($plan)));
    verifier(is_array($etat['relance']) && $etat['relance']['prochaine'] === 400 && $etat['relance']['restantes'] === 3, 'relance à 400, 3 restantes');
});

scenario('Relances : dues au battement seulement, au plus trois, puis plus rien', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme', 'Alarme : Baie')), 'calme', 'alarme', null, $relance, 100);
    list($plan, $etat) = vigiliumSorties::planifier(array(), 'alarme', 'alarme', $etat, $relance, 399, true);
    verifier(count($plan) === 0, 'pas encore due');
    list($plan, $etat) = vigiliumSorties::planifier(array(), 'alarme', 'alarme', $etat, $relance, 400, false);
    verifier(count($plan) === 0, 'pas de relance hors battement');
    $toutes = array();
    foreach (array(400, 700, 1000, 1300, 1600) as $t) {
        list($plan, $etat) = vigiliumSorties::planifier(array(), 'alarme', 'alarme', $etat, $relance, $t, true);
        foreach ($plan as $e) { $toutes[] = $e['detail']; }
    }
    verifier(count($toutes) === 3, 'trois relances, obtenu ' . count($toutes));
    verifier(isset($toutes[2]) && strpos($toutes[2], '(relance 3)') !== false, 'numérotées : ' . implode(' | ', $toutes));
});

scenario('Désarmement pendant l\'alarme : « fin », relance coupée', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme')), 'calme', 'alarme', null, $relance, 100);
    list($plan, $etat) = vigiliumSorties::planifier(array(d('info', 'Désarmé : fin de l\'incident')), 'alarme', 'calme', $etat, $relance, 200);
    verifier(declencheurs($plan) === array('fin'), 'obtenu ' . implode(',', declencheurs($plan)));
    verifier($plan[0]['detail'] === 'Désarmé : fin de l\'incident', 'détail de fin repris');
    verifier($etat['relance'] === null, 'relance coupée');
});

scenario('Fin seulement après une alarme : pas après une suspicion ou un délai', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('info')), 'suspicion', 'calme', null, $relance, 100);
    verifier(count($plan) === 0, 'rien après une suspicion');
    list($plan, $etat) = vigiliumSorties::planifier(array(d('info')), 'delai', 'calme', null, $relance, 100);
    verifier(count($plan) === 0, 'rien après un délai d\'entrée désarmé');
});

scenario('Alarme puis confirmation : deux déclencheurs, relance reprogrammée', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme', 'A'), d('confirmee', 'C')), 'calme', 'confirmee', null, $relance, 100);
    verifier(declencheurs($plan) === array('alarme', 'confirmee'), 'obtenu ' . implode(',', declencheurs($plan)));
    verifier($etat['relance']['detail'] === 'C', 'la relance reprend le dernier détail');
});

scenario('Alarme de centrale désarmée (incendie) : réagit, sans relance', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme', 'Alarme de la centrale…')), 'calme', 'calme', null, $relance, 100);
    verifier(declencheurs($plan) === array('alarme'), 'la décision agit');
    verifier($etat['relance'] === null, 'aucune relance que rien n\'arrêterait');
});

scenario('Retour au repos d\'une alarme de centrale désarmée : « fin »', function () use ($relance) {
    $retour = d('info', 'Centrale : retour au repos');
    $retour['fin'] = true;
    list($plan, $etat) = vigiliumSorties::planifier(array($retour), 'calme', 'calme', null, $relance, 100);
    verifier(declencheurs($plan) === array('fin'), 'obtenu ' . implode(',', declencheurs($plan)));
});

scenario('Confirmation après l\'alarme : le compteur de relances n\'est pas remis à zéro', function () use ($relance) {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme', 'A')), 'calme', 'alarme', null, $relance, 100);
    list($plan, $etat) = vigiliumSorties::planifier(array(), 'alarme', 'alarme', $etat, $relance, 400, true);
    list($plan, $etat) = vigiliumSorties::planifier(array(d('confirmee', 'C')), 'alarme', 'confirmee', $etat, $relance, 500);
    verifier($etat['relance']['restantes'] === 2 && $etat['relance']['prochaine'] === 700 && $etat['relance']['detail'] === 'C', 'échéance et compteur gardés, texte changé');
});

scenario('Sans liste de relance, ou à zéro : aucune relance', function () {
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme')), 'calme', 'alarme', null, array('minutes' => 5, 'max' => 3, 'listes' => false), 100);
    verifier($etat['relance'] === null, 'pas de liste');
    list($plan, $etat) = vigiliumSorties::planifier(array(d('alarme')), 'calme', 'alarme', null, array('minutes' => 0, 'max' => 3, 'listes' => true), 100);
    verifier($etat['relance'] === null, 'zéro minute');
});

scenario('Nettoyage des listes et jetons', function () {
    $propres = vigiliumSorties::nettoyer(array('alarme' => array(array('cmd' => ' #12# ', 'options' => array('message' => 'm')), array('cmd' => ''), 'x'), 'inconnu' => array(array('cmd' => '#1#'))));
    verifier(count($propres['alarme']) === 1 && $propres['alarme'][0]['cmd'] === '#12#', 'ligne vide retirée, commande nettoyée');
    verifier(!isset($propres['inconnu']) && isset($propres['fin']) && $propres['fin'] === array(), 'déclencheurs connus seulement');
    $jetons = vigiliumSorties::jetons('Maison', array('detail' => 'D', 'declencheur' => 'alarme'), 'Alarme', 'Nuit', 'Baie', array('/a.jpg', '/b.jpg'));
    verifier($jetons['#photo#'] === '/a.jpg' && $jetons['#photos#'] === '/a.jpg,/b.jpg' && $jetons['#detail#'] === 'D', 'jetons');
    verifier(vigiliumSorties::jetons('A', array('detail' => 'Mode inconnu (« system(x) »)', 'declencheur' => 'defaut'), '', '', 'exec(1)', array())['#detail#'] === 'Mode inconnu (« system (x) »)', 'un « mot( » est neutralisé');
    verifier(vigiliumSorties::demandePhotos(array(array('cmd' => '#1#', 'options' => array('title' => 'files=#photo#')))) === true
        && vigiliumSorties::demandePhotos(array(array('cmd' => '#1#', 'options' => array('message' => 'x')))) === false, 'photos seulement si citées');
    verifier(vigiliumSorties::cmdIdDe('#45#') === 45 && vigiliumSorties::cmdIdDe('message') === null, 'identifiant de commande');
});

echo "\n" . ($echecs === 0 ? "Réactions : tout est vert" : "Réactions : $echecs échec(s)") . " ($verifications vérifications).\n";
exit($echecs === 0 ? 0 : 1);
