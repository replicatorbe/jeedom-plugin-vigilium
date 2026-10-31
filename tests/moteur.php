<?php
/* Jeu d'essai hors ligne du moteur de décision.
 *
 *   php tests/moteur.php
 *
 * Chaque scénario rejoue une suite d'événements datés et vérifie les décisions
 * et le niveau atteint. Aucun Jeedom n'est nécessaire : le moteur n'en dépend
 * pas, c'est précisément pour pouvoir écrire ces scénarios-là. */

require_once __DIR__ . '/../core/class/vigiliumMoteur.class.php';

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

/* La maison de référence : porte d'entrée, baie, détecteur du couloir, une
 * caméra, l'alarme de la centrale, l'autoprotection, et une fenêtre d'étage
 * qui n'est surveillée qu'en armement total. */
function configReference() {
    return array(
        'delai_entree' => 30,
        'delai_sortie' => 60,
        'fenetre' => 300,
        'repos_suspicion' => 300,
        'capteurs' => array(
            array('cmd' => 1, 'nom' => 'Porte d\'entrée', 'role' => 'entree', 'total' => 1, 'nuit' => 1),
            array('cmd' => 2, 'nom' => 'Baie salon', 'role' => 'perimetre', 'total' => 1, 'nuit' => 1),
            array('cmd' => 3, 'nom' => 'Couloir', 'role' => 'interieur', 'total' => 1, 'nuit' => 0),
            array('cmd' => 4, 'nom' => 'Caméra sud', 'role' => 'exterieur', 'total' => 1, 'nuit' => 1),
            array('cmd' => 5, 'nom' => 'Centrale', 'role' => 'centrale', 'total' => 1, 'nuit' => 1),
            array('cmd' => 6, 'nom' => 'Autoprotection', 'role' => 'sabotage', 'total' => 1, 'nuit' => 1),
            array('cmd' => 7, 'nom' => 'Fenêtre chambre', 'role' => 'perimetre', 'total' => 1, 'nuit' => 0),
            array('cmd' => 0, 'nom' => 'ignoré, sans commande', 'role' => 'perimetre', 'total' => 1),
            array('cmd' => 8, 'nom' => 'ignoré, rôle inconnu', 'role' => 'nimporte', 'total' => 1),
        ),
    );
}

/* Rejoue des événements array(t, événement) et rend l'état final et toutes
 * les décisions, chacune datée. */
function rejouer($_evenements, $_config = null, $_etat = null) {
    $config = ($_config === null) ? configReference() : $_config;
    $etat = ($_etat === null) ? vigiliumMoteur::etatInitial() : $_etat;
    $toutes = array();
    foreach ($_evenements as $ligne) {
        list($etat, $decisions) = vigiliumMoteur::traiter($etat, $config, $ligne[1], $ligne[0]);
        foreach ($decisions as $decision) {
            $decision['t'] = $ligne[0];
            $toutes[] = $decision;
        }
    }
    return array($etat, $toutes);
}

function genres($_decisions) {
    $genres = array();
    foreach ($_decisions as $decision) {
        $genres[] = $decision['genre'];
    }
    return $genres;
}

function compter($_decisions, $_genre) {
    return count(array_filter($_decisions, function ($_d) use ($_genre) { return $_d['genre'] === $_genre; }));
}

function armer($_mode = 'total', $_alertes = array()) {
    return array('type' => 'mode', 'mode' => $_mode, 'alertes' => $_alertes);
}

function capteur($_id, $_alerte = true) {
    return array('type' => 'capteur', 'id' => $_id, 'alerte' => $_alerte);
}

function scenario($_titre, $_fonction) {
    echo "- $_titre\n";
    $_fonction();
}

scenario('Désarmé : rien ne déclenche, le sabotage se signale', function () {
    list($etat, $d) = rejouer(array(
        array(100, capteur(2)), array(110, capteur(4)), array(120, capteur(6)),
    ));
    verifier($etat['niveau'] === 'calme', 'niveau calme attendu, obtenu ' . $etat['niveau']);
    verifier(genres($d) === array('defaut'), 'seul le sabotage se signale, en défaut : ' . implode(',', genres($d)));
});

scenario('Retour normal : porte puis désarmement pendant le délai', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()),
        array(1000, capteur(1)),
        array(1010, capteur(3)),
        array(1015, array('type' => 'tick')),
        array(1020, armer('desarme')),
        array(1100, array('type' => 'tick')),
    ));
    verifier($etat['niveau'] === 'calme', 'niveau calme attendu, obtenu ' . $etat['niveau']);
    verifier(compter($d, 'alarme') === 0, 'aucune alarme attendue');
    verifier(compter($d, 'confirmee') === 0, 'aucune confirmation attendue');
});

scenario('Délai d\'entrée écoulé : alarme, confirmée par le couloir', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()),
        array(1000, capteur(1)),
        array(1010, capteur(3)),
        array(1029, array('type' => 'tick')),
        array(1031, array('type' => 'tick')),
    ));
    verifier($etat['niveau'] === 'confirmee', 'niveau confirmee attendu, obtenu ' . $etat['niveau']);
    verifier(compter($d, 'alarme') === 1, 'une alarme attendue');
    verifier(compter($d, 'confirmee') === 1, 'une confirmation attendue');
});

scenario('Délai d\'entrée écoulé, porte seule : alarme non confirmée', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()),
        array(1000, capteur(1)),
        array(1031, array('type' => 'tick')),
    ));
    verifier($etat['niveau'] === 'alarme', 'niveau alarme attendu, obtenu ' . $etat['niveau']);
});

scenario('Baie ouverte armé : alarme immédiate', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(2))));
    verifier($etat['niveau'] === 'alarme', 'niveau alarme attendu');
    verifier(genres($d) === array('info', 'alarme'), 'armement puis alarme attendus : ' . implode(',', genres($d)));
});

scenario('Même capteur deux fois : pas de confirmation', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()), array(500, capteur(2)), array(501, capteur(2, false)), array(502, capteur(2)),
    ));
    verifier($etat['niveau'] === 'alarme', 'niveau alarme attendu, obtenu ' . $etat['niveau']);
    verifier(compter($d, 'confirmee') === 0, 'aucune confirmation attendue');
});

scenario('Caméra puis baie dans la fenêtre : alarme confirmée', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(4)), array(600, capteur(2))));
    verifier($etat['niveau'] === 'confirmee', 'niveau confirmee attendu, obtenu ' . $etat['niveau']);
    verifier(genres($d) === array('info', 'suspicion', 'alarme', 'confirmee'), 'suite inattendue : ' . implode(',', genres($d)));
});

scenario('Caméra puis baie hors fenêtre : alarme simple', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(4)), array(900, capteur(2))));
    verifier($etat['niveau'] === 'alarme', 'niveau alarme attendu, obtenu ' . $etat['niveau']);
});

scenario('Baie puis caméra : la caméra confirme', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(2)), array(520, capteur(4))));
    verifier($etat['niveau'] === 'confirmee', 'niveau confirmee attendu, obtenu ' . $etat['niveau']);
});

scenario('Caméra seule : suspicion, repos, puis retombée', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()),
        array(500, capteur(4)),
        array(600, capteur(4)),
        array(700, array('type' => 'tick')),
        array(801, array('type' => 'tick')),
    ));
    verifier(compter($d, 'suspicion') === 1, 'une seule suspicion attendue pendant le repos');
    verifier($etat['niveau'] === 'calme', 'retour au calme attendu, obtenu ' . $etat['niveau']);
});

scenario('Délai de sortie : couloir et porte ignorés, baie non', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(10, capteur(3)), array(20, capteur(1))));
    verifier($etat['niveau'] === 'calme', 'niveau calme attendu pendant la sortie, obtenu ' . $etat['niveau']);
    list($etat, $d) = rejouer(array(array(0, armer()), array(10, capteur(2))));
    verifier($etat['niveau'] === 'alarme', 'la baie déclenche même pendant la sortie');
});

scenario('Fenêtre ouverte à l\'armement : exclue puis de nouveau surveillée', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer('total', array(2 => true))),
        array(500, capteur(2)),
    ));
    verifier($etat['niveau'] === 'calme', 'capteur exclu : pas d\'alarme');
    verifier(isset($etat['exclus'][2]), 'la baie doit être exclue');
    verifier(strpos($d[0]['detail'], 'Baie salon') !== false, 'l\'exclusion doit nommer la baie');
    list($etat, $d) = rejouer(array(array(600, capteur(2, false)), array(700, capteur(2))), null, $etat);
    verifier($etat['niveau'] === 'alarme', 'refermée puis rouverte : alarme attendue, obtenu ' . $etat['niveau']);
});

scenario('Mode nuit : couloir et fenêtre de chambre inactifs, baie active', function () {
    list($etat, $d) = rejouer(array(array(0, armer('nuit')), array(500, capteur(3)), array(510, capteur(7))));
    verifier($etat['niveau'] === 'calme', 'capteurs inactifs la nuit : calme attendu, obtenu ' . $etat['niveau']);
    list($etat, $d) = rejouer(array(array(520, capteur(2))), null, $etat);
    verifier($etat['niveau'] === 'alarme', 'la baie déclenche la nuit');
});

scenario('Centrale : alarme immédiate même pendant la sortie', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(5, capteur(5))));
    verifier($etat['niveau'] === 'alarme', 'niveau alarme attendu');
});

scenario('Sabotage : décision sans changer de niveau', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(6))));
    verifier($etat['niveau'] === 'calme', 'niveau calme attendu');
    verifier(compter($d, 'sabotage') === 1, 'un sabotage attendu');
});

scenario('Désarmement pendant une alarme : fin d\'incident', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(2)), array(600, armer('desarme'))));
    verifier($etat['niveau'] === 'calme' && $etat['incident'] === null, 'incident clos attendu');
});

scenario('Acquittement : retour au calme, toujours armé', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(2)), array(600, array('type' => 'acquitter'))));
    verifier($etat['niveau'] === 'calme' && $etat['mode'] === 'total', 'calme et armé attendus');
});

scenario('Supervision : muet armé = sabotage, désarmé = défaut, une fois par épisode', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()),
        array(100, array('type' => 'muet', 'id' => 2, 'muet' => true)),
        array(160, array('type' => 'muet', 'id' => 2, 'muet' => true)),
        array(220, array('type' => 'muet', 'id' => 2, 'muet' => false)),
    ));
    verifier(genres($d) === array('info', 'sabotage', 'info'), 'suite inattendue : ' . implode(',', genres($d)));
    list($etat, $d) = rejouer(array(array(100, array('type' => 'muet', 'id' => 2, 'muet' => true))));
    verifier(genres($d) === array('defaut'), 'défaut attendu désarmé : ' . implode(',', genres($d)));
});

scenario('Total vers nuit : nouvel armement, incident clos', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(500, capteur(2)), array(600, armer('nuit'))));
    verifier($etat['niveau'] === 'calme' && $etat['mode'] === 'nuit' && $etat['arme_depuis'] === 600, 'nouvel armement attendu');
});

scenario('Robustesse : état et configuration abîmés', function () {
    list($etat, $d) = vigiliumMoteur::traiter('nimporte', null, array('type' => 'tick'), 10);
    verifier($etat['niveau'] === 'calme', 'état neuf attendu');
    list($etat, $d) = vigiliumMoteur::traiter(array('niveau' => 42, 'exclus' => 'x'), array(), capteur(99), 10);
    verifier($etat['niveau'] === 'calme' && $etat['exclus'] === array(), 'clés invalides remplacées');
    $config = vigiliumMoteur::normaliserConfig(array('delai_entree' => '', 'fenetre' => 5, 'capteurs' => 'x'));
    verifier($config['delai_entree'] === 30 && $config['fenetre'] === 30 && $config['capteurs'] === array(), 'bornes et défauts');
    verifier(count(vigiliumMoteur::normaliserConfig(configReference())['capteurs']) === 7, 'capteurs sans commande ou de rôle inconnu écartés');
});

scenario('Mode inconnu : le mode et l\'incident sont conservés, un défaut par épisode', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()),
        array(500, capteur(2)),
        array(540, array('type' => 'mode_inconnu', 'valeur' => '')),
        array(600, array('type' => 'mode_inconnu', 'valeur' => '')),
        array(660, armer()),
        array(670, capteur(3)),
    ));
    verifier($etat['mode'] === 'total' && $etat['arme_depuis'] === 0, 'mode total conservé, pas de nouvel armement');
    verifier($etat['niveau'] === 'confirmee', 'incident conservé puis confirmé par le couloir, obtenu ' . $etat['niveau']);
    verifier(compter($d, 'defaut') === 1, 'un seul défaut de mode attendu');
});

scenario('Délai de sortie : rien de ce qui est ignoré ne confirme ensuite', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()), array(10, capteur(3)), array(20, capteur(4)), array(40, capteur(1)), array(200, capteur(2)),
    ));
    verifier($etat['niveau'] === 'alarme', 'alarme simple attendue, obtenu ' . $etat['niveau']);
    verifier(compter($d, 'suspicion') === 0, 'pas de suspicion pendant la sortie');
});

scenario('Délai échu entre deux battements : constaté au capteur suivant', function () {
    list($etat, $d) = rejouer(array(array(0, armer()), array(100, capteur(1)), array(145, capteur(3))));
    verifier($etat['niveau'] === 'confirmee', 'alarme confirmée attendue, obtenu ' . $etat['niveau']);
    verifier($d[2]['t'] === 145 && $d[2]['genre'] === 'alarme', 'l\'alarme est constatée à 145');
});

scenario('Sabotage désarmé : signalé comme défaut', function () {
    list($etat, $d) = rejouer(array(array(100, capteur(6))));
    verifier(genres($d) === array('defaut'), 'défaut attendu : ' . implode(',', genres($d)));
});

scenario('Caméra puis porte, délai échu : alarme confirmée par la caméra', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()), array(500, capteur(4)), array(520, capteur(1)), array(551, array('type' => 'tick')),
    ));
    verifier($etat['niveau'] === 'confirmee', 'confirmée attendue, obtenu ' . $etat['niveau']);
});

scenario('Acquittement : les alertes d\'avant ne confirment plus', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()), array(500, capteur(2)), array(510, array('type' => 'acquitter')), array(520, capteur(7)),
    ));
    verifier($etat['niveau'] === 'alarme', 'alarme simple attendue, obtenu ' . $etat['niveau']);
});

scenario('Capteur retiré de la configuration : oublié des muets et des exclus', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer('total', array(2 => true))),
        array(100, array('type' => 'muet', 'id' => 7, 'muet' => true)),
    ));
    $config = configReference();
    $config['capteurs'] = array_slice($config['capteurs'], 2);
    list($etat, $d) = rejouer(array(array(160, array('type' => 'tick'))), $config, $etat);
    verifier(!isset($etat['exclus'][2]), 'la baie retirée n\'est plus exclue');
    list($etat, $d) = rejouer(array(array(170, array('type' => 'tick'))), array_merge($config, array('capteurs' => array_slice(configReference()['capteurs'], 0, 2))), $etat);
    verifier(count($etat['muets']) === 0, 'la fenêtre retirée n\'est plus muette');
});

scenario('Sabotage répété : une décision par épisode, close au repos', function () {
    list($etat, $d) = rejouer(array(
        array(0, armer()), array(100, capteur(6)), array(105, capteur(6)), array(110, capteur(6, false)), array(200, capteur(6)),
    ));
    verifier(genres($d) === array('info', 'sabotage', 'info', 'sabotage'), 'suite inattendue : ' . implode(',', genres($d)));
    list($etat, $d) = rejouer(array(array(100, capteur(6)), array(105, capteur(6))));
    verifier(genres($d) === array('defaut'), 'un seul défaut désarmé : ' . implode(',', genres($d)));
});

scenario('Commande disparue : un défaut, puis un retour', function () {
    list($etat, $d) = rejouer(array(
        array(0, array('type' => 'disparu', 'id' => 2, 'disparu' => true)),
        array(60, array('type' => 'disparu', 'id' => 2, 'disparu' => true)),
        array(120, array('type' => 'disparu', 'id' => 2, 'disparu' => false)),
    ));
    verifier(genres($d) === array('defaut', 'info'), 'défaut puis retour attendus : ' . implode(',', genres($d)));
});

echo "\n" . ($echecs === 0 ? "Moteur : tout est vert" : "Moteur : $echecs échec(s)") . " ($verifications vérifications).\n";
exit($echecs === 0 ? 0 : 1);
