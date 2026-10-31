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

/* ------------------------------------------------------------ SIRÈNES */

function sirenes() {
    return vigiliumSorties::nettoyerSirenes(array(
        array('nom' => 'Intérieure', 'marche' => 10, 'arret' => 11, 'duree' => 180, 'alarme' => 1, 'confirmee' => 1),
        array('nom' => 'Extérieure', 'marche' => 20, 'arret' => 21, 'duree' => 120, 'retard' => 60, 'confirmee' => 1),
        array('nom' => 'PANIC', 'marche' => 30, 'arret' => 31, 'duree' => 180, 'alarme' => 1, 'sauf_centrale' => 1),
        array('nom' => 'Caméra', 'marche' => 40, 'arret' => 0, 'duree' => 99999, 'sabotage' => 1),
        array('nom' => 'sans commande', 'marche' => 0),
        array('nom' => 'Après une ligne incomplète', 'marche' => 50, 'arret' => 51),
    ));
}

function sir($_plan) {
    return array_map(function ($_e) { return ($_e['declencheur'] === 'sirene_marche' ? '+' : '-') . $_e['sirene']; }, $_plan);
}

$roles = array(1 => 'perimetre', 5 => 'centrale', 6 => 'sabotage');

scenario('Sirènes : nettoyage, durée toujours bornée', function () {
    $s = sirenes();
    verifier(count($s) === 5 && !isset($s[4]) && $s[5]['marche'] === 50, 'la sirène sans commande est écartée, sans décaler les suivantes');
    verifier($s[3]['duree'] === 900 && $s[3]['arret'] === 0, 'durée bornée à 15 min');
});

scenario('Sirènes : alarme d\'un capteur — intérieure et PANIC, extérieure seulement à la confirmation, après son retard', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    verifier(sir($plan) === array('+0', '+2'), 'obtenu ' . implode(',', sir($plan)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('confirmee', 'C', 1)), 'alarme', 'confirmee', 'total', $etat, sirenes(), $roles, 120);
    verifier(sir($plan) === array(), 'extérieure en attente, intérieure déjà en marche : ' . implode(',', sir($plan)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'confirmee', 'confirmee', 'total', $etat, sirenes(), $roles, 179, true);
    verifier(sir($plan) === array(), 'retard pas encore écoulé');
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'confirmee', 'confirmee', 'total', $etat, sirenes(), $roles, 180, true);
    verifier(sir($plan) === array('+1'), 'extérieure après 60 s : ' . implode(',', sir($plan)));
});

scenario('Sirènes : alarme de la centrale — pas de PANIC', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 5)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    verifier(sir($plan) === array('+0'), 'obtenu ' . implode(',', sir($plan)));
});

scenario('Sirènes : arrêt au bout de la durée, sans redémarrage dans le même incident', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, 280, true);
    verifier(sir($plan) === array('-0', '-2'), 'arrêt à 280 : ' . implode(',', sir($plan)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('confirmee', 'C', 1)), 'alarme', 'confirmee', 'total', $etat, sirenes(), $roles, 300);
    verifier(!in_array('+0', sir($plan), true), 'l\'intérieure ne repart pas dans le même incident');
});

scenario('Sirènes : désarmement — tout s\'arrête, attentes comprises', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1), d('confirmee', 'C', 1)), 'calme', 'confirmee', 'total', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('info', 'Désarmé')), 'confirmee', 'calme', 'desarme', $etat, sirenes(), $roles, 130);
    verifier(sir($plan) === array('-0', '-2'), 'arrêt : ' . implode(',', sir($plan)));
    verifier(count($etat['attentes']) === 0 && count($etat['faites']) === 0, 'attente annulée, incident oublié');
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'calme', 'calme', 'desarme', $etat, sirenes(), $roles, 200, true);
    verifier(sir($plan) === array(), 'l\'extérieure annulée ne part pas');
});

scenario('Sirènes : acquittement — fin de l\'incident, tout s\'arrête', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('info', 'Acquitté')), 'alarme', 'calme', 'total', $etat, sirenes(), $roles, 110);
    verifier(sir($plan) === array('-0', '-2'), 'obtenu ' . implode(',', sir($plan)));
});

scenario('Sirènes : sabotage armé — sirène de sabotage, arrêtée par sa durée', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'S', 6)), 'calme', 'calme', 'nuit', null, sirenes(), $roles, 100);
    verifier(sir($plan) === array('+3'), 'obtenu ' . implode(',', sir($plan)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'S', 6)), 'calme', 'calme', 'nuit', $etat, sirenes(), $roles, 200);
    verifier(sir($plan) === array(), 'pas de second départ pendant qu\'elle sonne');
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'calme', 'calme', 'nuit', $etat, sirenes(), $roles, 1000, true);
    verifier(sir($plan) === array('-3'), 'arrêt au bout de 900 s');
});

scenario('Sirènes : alarme de centrale reçue désarmée (incendie) — aucune sirène de Vigilium', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'Incendie ?', 5)), 'calme', 'calme', 'desarme', null, sirenes(), $roles, 100);
    verifier(sir($plan) === array(), 'obtenu ' . implode(',', sir($plan)));
});

scenario('Sirènes : configuration changée pendant qu\'elle sonne — l\'arrêt garde sa commande', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    $autre = vigiliumSorties::nettoyerSirenes(array(array('nom' => 'Autre', 'marche' => 90, 'arret' => 91, 'alarme' => 1)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'alarme', 'alarme', 'total', $etat, $autre, $roles, 280, true);
    $arrets = array_map(function ($_e) { return $_e['arret']; }, $plan);
    verifier($arrets === array(11, 31), 'arrêts avec les commandes d\'origine : ' . implode(',', $arrets));
});

scenario('Sirènes : mise en marche réelle, passage en observation — l\'arrêt reste réel', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100, array('observation' => false));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('info')), 'alarme', 'calme', 'desarme', $etat, sirenes(), $roles, 110, array('observation' => true));
    verifier(count($plan) === 2 && $plan[0]['reelle'] === true, 'arrêt marqué réel');
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100, array('observation' => true));
    verifier($plan[0]['reelle'] === false, 'en observation, la mise en marche n\'est pas réelle');
});

scenario('Sirènes : capteur muet ou « armé malgré » — pas de sirène de sabotage', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'Capteur muet…', 1), d('sabotage', 'Armé malgré…', 0)), 'calme', 'calme', 'nuit', null, sirenes(), $roles, 100);
    verifier(sir($plan) === array(), 'obtenu ' . implode(',', sir($plan)));
});

scenario('Sirènes : acquittement — tout s\'arrête, même une sirène de sabotage hors alarme', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'S', 6)), 'calme', 'calme', 'nuit', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'calme', 'calme', 'nuit', $etat, sirenes(), $roles, 110, array('acquitter' => true));
    verifier(sir($plan) === array('-3'), 'obtenu ' . implode(',', sir($plan)));
});

scenario('Sirènes : la centrale a déclaré l\'incident — PANIC ni à la confirmation, ni après son retard', function () use ($roles) {
    $avecRetard = vigiliumSorties::nettoyerSirenes(array(array('nom' => 'PANIC', 'marche' => 30, 'duree' => 180, 'retard' => 30, 'alarme' => 1, 'confirmee' => 1, 'sauf_centrale' => 1)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 5)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('confirmee', 'C', 1)), 'alarme', 'confirmee', 'total', $etat, sirenes(), $roles, 110);
    verifier(!in_array('+2', sir($plan), true), 'pas de PANIC à la confirmation : ' . implode(',', sir($plan)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, $avecRetard, $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('confirmee', 'C', 5)), 'alarme', 'confirmee', 'total', $etat, $avecRetard, $roles, 105);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'confirmee', 'confirmee', 'total', $etat, $avecRetard, $roles, 200, true);
    verifier(sir($plan) === array(), 'PANIC en attente annulé quand la centrale déclare : ' . implode(',', sir($plan)));
});

scenario('Sirènes : ligne retirée pendant son retard — elle démarre quand même, pas une autre', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1), d('confirmee', 'C', 1)), 'calme', 'confirmee', 'total', null, sirenes(), $roles, 100);
    $sansExterieure = sirenes();
    unset($sansExterieure[1]);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'confirmee', 'confirmee', 'total', $etat, $sansExterieure, $roles, 160, true);
    verifier(count($plan) === 1 && $plan[0]['marche'] === 20, 'la sirène attendue démarre avec sa commande : ' . json_encode($plan));
});

scenario('Sirènes : la centrale parle APRÈS le contact — PANIC en attente annulé', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null,
        vigiliumSorties::nettoyerSirenes(array(array('nom' => 'PANIC', 'marche' => 30, 'retard' => 30, 'alarme' => 1, 'sauf_centrale' => 1))), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('info', 'La centrale déclare aussi l\'alarme', 5)), 'alarme', 'alarme', 'total', $etat,
        vigiliumSorties::nettoyerSirenes(array(array('nom' => 'PANIC', 'marche' => 30, 'retard' => 30, 'alarme' => 1, 'sauf_centrale' => 1))), $roles, 104);
    verifier(count($etat['attentes']) === 0 && $etat['centrale'] === true, 'attente annulée : ' . json_encode($etat['attentes']));
});

scenario('Sirènes : sabotage puis intrusion — la durée repart pour l\'intrusion', function () use ($roles) {
    $int = vigiliumSorties::nettoyerSirenes(array(array('nom' => 'Int', 'marche' => 10, 'arret' => 11, 'duree' => 180, 'alarme' => 1, 'sabotage' => 1)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'S', 6)), 'calme', 'calme', 'total', null, $int, $roles, 100);
    verifier(sir($plan) === array('+0'), 'sabotage : ' . implode(',', sir($plan)));
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', $etat, $int, $roles, 270);
    verifier($etat['sirenes'][0]['fin'] === 450 && $etat['sirenes'][0]['motif'] === 'alarme', 'durée repartie : ' . json_encode($etat['sirenes'][0]));
});

scenario('Sirènes : sabotage par impulsions — une seule sirène de sabotage par armement', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'S', 6)), 'calme', 'calme', 'nuit', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'calme', 'calme', 'nuit', $etat, sirenes(), $roles, 1100, true);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('sabotage', 'S', 6)), 'calme', 'calme', 'nuit', $etat, sirenes(), $roles, 1200);
    verifier(sir($plan) === array(), 'pas de second départ pour un sabotage : ' . implode(',', sir($plan)));
});

scenario('Sirènes : nouvelle détection pendant une alarme restée sans réponse — les sirènes repartent', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, 400, true);
    $nouvelle = d('alarme', 'Nouvelle détection', 1);
    $nouvelle['nouvelle'] = true;
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array($nouvelle), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, 11000);
    verifier(sir($plan) === array('+0', '+2'), 'obtenu ' . implode(',', sir($plan)));
});

scenario('Sirènes : horloge revenue en arrière — pas une heure de sirène', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 5000);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, 1400, true);
    verifier(in_array('-0', sir($plan), true), 'arrêt attendu : ' . implode(',', sir($plan)));
});

scenario('Sirènes : nouvelles détections répétées — une remise en marche par heure au plus', function () use ($roles) {
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(d('alarme', 'A', 1)), 'calme', 'alarme', 'total', null, sirenes(), $roles, 100);
    list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, 400, true);
    $nouvelle = d('alarme', 'N', 1);
    $nouvelle['nouvelle'] = true;
    $departs = 0;
    foreach (array(4000, 4400, 4800, 7700) as $t) {
        list($plan, $etat) = vigiliumSorties::planifierSirenes(array($nouvelle), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, $t);
        $departs += count(array_filter($plan, function ($_e) { return $_e['declencheur'] === 'sirene_marche'; }));
        list($plan, $etat) = vigiliumSorties::planifierSirenes(array(), 'alarme', 'alarme', 'total', $etat, sirenes(), $roles, $t + 300, true);
    }
    verifier($departs === 4, 'deux remises en marche (2 sirènes chacune) sur ces quatre détections : ' . $departs);
});

scenario('Signal de vie : dû à l\'intervalle, jamais à zéro', function () {
    list($du, $etat) = vigiliumSorties::vieDue(null, 5, 1000);
    verifier($du === true, 'premier signal');
    list($du, $etat) = vigiliumSorties::vieDue($etat, 5, 1240);
    verifier($du === false, 'pas avant 5 min');
    list($du, $etat) = vigiliumSorties::vieDue($etat, 5, 1296);
    verifier($du === true, 'à 5 min, au battement près');
    list($du, $etat) = vigiliumSorties::vieDue(null, 0, 1000);
    verifier($du === false, 'désactivé à zéro');
});

echo "\n" . ($echecs === 0 ? "Réactions : tout est vert" : "Réactions : $echecs échec(s)") . " ($verifications vérifications).\n";
exit($echecs === 0 ? 0 : 1);
