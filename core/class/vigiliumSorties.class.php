<?php
/* This file is part of Vigilium, a plugin for Jeedom.
 * Copyright (C) sMug (Jérôme Fafchamps)
 *
 * Vigilium is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Vigilium is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Vigilium. If not, see <https://www.gnu.org/licenses/>.
 */

/*
 * Les réactions : ce que l'alarme fait de ses décisions.
 *
 * Deux moitiés, comme pour le moteur :
 *  - planifier() est pur : à partir des décisions, du niveau avant et après,
 *    et de l'état des relances, il dit QUELLES listes d'actions jouer. Il se
 *    teste hors ligne (tests/sorties.php) ;
 *  - executer() parle à Jeedom : photos, puis actions, ou seulement leur
 *    trace au journal tant que l'alarme est en observation.
 *
 * Une liste d'actions par « déclencheur » :
 *   suspicion, alarme, confirmee, sabotage, defaut  — le genre de la décision ;
 *   fin      — un incident d'alarme se termine (désarmement, acquittement) ;
 *   relance  — rejouée à intervalle tant que l'alarme court.
 */
class vigiliumSorties {

    const DECLENCHEURS = array('suspicion', 'alarme', 'confirmee', 'sabotage', 'defaut', 'fin', 'relance');

    /* Blocs d'action qui n'ont pas de sens hors d'un scénario, ou qui
     * retiendraient l'écouteur ou le cron (attente, question posée). */
    const BLOCS_REFUSES = array('wait', 'sleep', 'ask', 'report', 'exportHistory',
                                'stop', 'log', 'scenario_return', 'icon', 'tag');

    const RELANCE_MINUTES_DEFAUT = 5;
    const RELANCE_MAX_DEFAUT = 3;

    /* Déclencheurs pour lesquels des photos sont prises avant les actions. */
    const AVEC_PHOTOS = array('suspicion', 'alarme', 'confirmee');

    public static function etatInitial() {
        return array('relance' => null, 'sirenes' => array(), 'attentes' => array(), 'faites' => array(), 'centrale' => false, 'vie' => 0, 'remise' => 0);
    }

    const SIRENE_DUREE_DEFAUT = 180;
    const SIRENE_DUREE_MAX = 900;
    const SIRENE_RETARD_MAX = 600;
    const SIRENE_ESSAI = 3;

    /* Intervalle minimal entre deux remises en marche des sirènes pour une
     * nouvelle détection, pendant une même alarme sans réponse. */
    const REMISE_SIRENES_MIN = 3600;

    /*
     * Les sirènes telles que le planificateur les lit. Une sirène sans
     * commande de marche est écartée. La durée est TOUJOURS bornée : une
     * sirène qui sonnerait jusqu'au retour des occupants, la nuit, chez les
     * voisins, finit débranchée — et une alarme débranchée ne protège plus.
     */
    public static function nettoyerSirenes($_sirenes) {
        /* Les index d'origine sont gardés : ce sont eux que la page envoie
         * pour « Tester », et eux que l'état des sirènes en marche retient —
         * une ligne incomplète ne doit pas décaler les suivantes. */
        $propres = array();
        foreach (is_array($_sirenes) ? $_sirenes : array() as $index => $sirene) {
            if (!is_array($sirene) || !isset($sirene['marche']) || (int) $sirene['marche'] <= 0) {
                continue;
            }
            $duree = (isset($sirene['duree']) && is_numeric($sirene['duree']) && (int) $sirene['duree'] > 0) ? (int) $sirene['duree'] : self::SIRENE_DUREE_DEFAUT;
            $retard = (isset($sirene['retard']) && is_numeric($sirene['retard'])) ? (int) $sirene['retard'] : 0;
            $propres[(int) $index] = array(
                'nom' => (isset($sirene['nom']) && trim((string) $sirene['nom']) !== '') ? trim((string) $sirene['nom']) : ('#' . (int) $sirene['marche']),
                'marche' => (int) $sirene['marche'],
                'arret' => isset($sirene['arret']) ? max(0, (int) $sirene['arret']) : 0,
                'duree' => max(5, min(self::SIRENE_DUREE_MAX, $duree)),
                'retard' => max(0, min(self::SIRENE_RETARD_MAX, $retard)),
                'alarme' => !empty($sirene['alarme']),
                'confirmee' => !empty($sirene['confirmee']),
                'sabotage' => !empty($sirene['sabotage']),
                'sauf_centrale' => !empty($sirene['sauf_centrale']),
            );
        }
        return $propres;
    }

    /*
     * Les sirènes à mettre en marche ou à arrêter. Rend array($plan, $etat) ;
     * chaque entrée : array('declencheur' => 'sirene_marche'|'sirene_arret',
     * 'sirene' => index, 'nom', 'marche', 'arret', 'duree', 'reelle',
     * 'detail', 'capteur').
     *
     *  - une décision alarme ou confirmee met en marche les sirènes qui la
     *    suivent, tout de suite ou après leur retard ; « sabotage » seulement
     *    pour un capteur de rôle sabotage — un capteur muet ou un « armé
     *    malgré » ne réveille pas les voisins ;
     *  - une fois par incident : arrivée au bout de sa durée, une sirène ne
     *    repart pas à la détection suivante ;
     *  - « sauf centrale » : pas quand la centrale a déclaré l'alarme de cet
     *    incident, même si la décision vient d'un autre capteur, et un retard
     *    en cours est annulé quand elle la déclare — le réglage de PANIC ;
     *  - tout s'arrête, retards compris, au désarmement, à l'acquittement ou
     *    à la fin de l'incident ; chaque sirène s'arrête seule au bout de sa
     *    durée, constatée au battement.
     *
     * L'arrêt reprend la commande ENREGISTRÉE à la mise en marche, et non la
     * configuration du moment : une ligne retirée ou modifiée pendant que la
     * sirène sonne ne doit pas envoyer l'arrêt à une autre. Il part pour de
     * vrai si la mise en marche était réelle, même si l'alarme est repassée
     * en observation depuis.
     *
     * $_roles : array(id du capteur => rôle).
     * $_options : array('battement' => bool, 'acquitter' => bool,
     *                   'observation' => bool).
     */
    public static function planifierSirenes($_decisions, $_avant, $_apres, $_modeApres, $_etat, $_sirenes, $_roles, $_t, $_options = array()) {
        $options = is_array($_options) ? $_options : array('battement' => (bool) $_options);
        $battement = !empty($options['battement']);
        $observation = !empty($options['observation']);
        $etat = is_array($_etat) ? array_merge(self::etatInitial(), $_etat) : self::etatInitial();
        $plan = array();
        $enAlarme = function ($_niveau) {
            return in_array($_niveau, array('alarme', 'confirmee'), true);
        };
        $arret = function ($_index, $_detail) use (&$etat, $_sirenes) {
            $enMarche = $etat['sirenes'][$_index];
            if (!is_array($enMarche)) {
                /* Un état écrit avant que la commande d'arrêt n'y soit gardée :
                 * à défaut de mieux, celle de la configuration actuelle. */
                $enMarche = array('fin' => (int) $enMarche, 'reelle' => true,
                                  'arret' => isset($_sirenes[$_index]) ? $_sirenes[$_index]['arret'] : 0,
                                  'nom' => isset($_sirenes[$_index]) ? $_sirenes[$_index]['nom'] : '#' . $_index);
            }
            return array('declencheur' => 'sirene_arret', 'sirene' => (int) $_index, 'nom' => (string) $enMarche['nom'],
                         'arret' => (int) $enMarche['arret'], 'reelle' => !empty($enMarche['reelle']),
                         'detail' => $_detail, 'capteur' => 0);
        };
        $arreterTout = function ($_detail) use (&$etat, &$plan, $arret) {
            foreach (array_keys($etat['sirenes']) as $index) {
                $plan[] = $arret($index, $_detail);
            }
            $etat['sirenes'] = array();
            $etat['attentes'] = array();
            $etat['faites'] = array();
            $etat['centrale'] = false;
        };
        $marche = function ($_index, $_sirene, $_detail, $_capteur, $_motif) use (&$etat, &$plan, $_t, $observation) {
            $etat['sirenes'][$_index] = array('fin' => $_t + $_sirene['duree'], 'arret' => $_sirene['arret'],
                                              'nom' => $_sirene['nom'], 'reelle' => !$observation,
                                              'duree' => $_sirene['duree'], 'motif' => $_motif);
            $etat['faites'][($_motif === 'sabotage' ? 's:' : '') . $_index] = true;
            $plan[] = array('declencheur' => 'sirene_marche', 'sirene' => (int) $_index, 'nom' => $_sirene['nom'],
                            'marche' => $_sirene['marche'], 'arret' => $_sirene['arret'], 'duree' => $_sirene['duree'],
                            'reelle' => !$observation, 'detail' => $_detail, 'capteur' => $_capteur);
        };

        if ($_modeApres === 'desarme' || ($enAlarme($_avant) && !$enAlarme($_apres)) || !empty($options['acquitter'])) {
            $arreterTout($_modeApres === 'desarme' ? 'Désarmé' : (!empty($options['acquitter']) ? 'Acquitté' : 'Fin de l\'incident'));
            return array($plan, $etat);
        }

        foreach (is_array($_decisions) ? $_decisions : array() as $decision) {
            $genre = isset($decision['genre']) ? $decision['genre'] : '';
            $capteur = isset($decision['capteur']) ? (int) $decision['capteur'] : 0;
            $role = isset($_roles[$capteur]) ? $_roles[$capteur] : '';
            /* Toute décision de la centrale pendant une alarme — y compris
             * l'information « elle déclare aussi l'alarme », quand elle parle
             * après le contact — retient les sirènes « sauf centrale ». */
            if ($role === 'centrale' && $enAlarme($_apres)) {
                $etat['centrale'] = true;
                foreach (array_keys($etat['attentes']) as $index) {
                    $attendue = isset($etat['attentes'][$index]['sirene']) ? $etat['attentes'][$index]['sirene']
                              : (isset($_sirenes[$index]) ? $_sirenes[$index] : null);
                    if (is_array($attendue) && !empty($attendue['sauf_centrale'])) {
                        unset($etat['attentes'][$index]);
                    }
                }
            }
            if (!in_array($genre, array('alarme', 'confirmee', 'sabotage'), true)) {
                continue;
            }
            /* Une alarme de centrale reçue désarmée (incendie, panique) ne fait
             * pas monter le niveau : rien n'arrêterait ces sirènes-là, sinon
             * leur durée. Les sirènes de la centrale, elles, sonnent déjà. */
            if ($genre === 'alarme' && !$enAlarme($_apres)) {
                continue;
            }
            if ($genre === 'sabotage' && $role !== 'sabotage') {
                continue;
            }
            /* Une nouvelle détection pendant une alarme restée sans réponse :
             * les sirènes arrivées au bout de leur durée peuvent repartir. */
            /* Au plus une fois par heure : une alarme laissée sans réponse ne
             * doit pas faire sonner le quartier en boucle. */
            if (!empty($decision['nouvelle']) && $_t - (int) (isset($etat['remise']) ? $etat['remise'] : 0) >= self::REMISE_SIRENES_MIN) {
                $etat['remise'] = $_t;
                foreach (array_keys($etat['faites']) as $fait) {
                    if (strpos((string) $fait, 's:') !== 0 && !isset($etat['sirenes'][(int) $fait])) {
                        unset($etat['faites'][$fait]);
                    }
                }
            }
            $motif = ($genre === 'sabotage') ? 'sabotage' : 'alarme';
            foreach ($_sirenes as $index => $sirene) {
                if (empty($sirene[$genre]) || ($sirene['sauf_centrale'] && !empty($etat['centrale']))) {
                    continue;
                }
                /* Déjà en marche pour un sabotage, elle passe à l'alarme : sa
                 * durée repart de maintenant, l'intrusion a droit à la sienne. */
                if ($motif === 'alarme' && isset($etat['sirenes'][$index]) && is_array($etat['sirenes'][$index])
                    && isset($etat['sirenes'][$index]['motif']) && $etat['sirenes'][$index]['motif'] === 'sabotage') {
                    $etat['sirenes'][$index]['fin'] = $_t + $sirene['duree'];
                    $etat['sirenes'][$index]['motif'] = 'alarme';
                    $etat['faites'][$index] = true;
                    continue;
                }
                $fait = ($motif === 'sabotage' ? 's:' : '') . $index;
                if (isset($etat['sirenes'][$index]) || isset($etat['attentes'][$index]) || !empty($etat['faites'][$fait])) {
                    continue;
                }
                /* La sirène attendue est gardée telle quelle : retirer une ligne
                 * pendant son retard ne doit pas en faire démarrer une autre. */
                if ($sirene['retard'] > 0) {
                    $etat['attentes'][$index] = array('debut' => $_t + $sirene['retard'], 'detail' => (string) $decision['detail'],
                                                      'capteur' => $capteur, 'sirene' => $sirene, 'motif' => $motif);
                    continue;
                }
                $marche($index, $sirene, (string) $decision['detail'], $capteur, $motif);
            }
        }

        if ($battement) {
            foreach ($etat['attentes'] as $index => $attente) {
                if ($_t < (int) $attente['debut']) {
                    continue;
                }
                unset($etat['attentes'][$index]);
                $sirene = (isset($attente['sirene']) && is_array($attente['sirene'])) ? $attente['sirene']
                        : (isset($_sirenes[$index]) ? $_sirenes[$index] : null);
                if ($sirene === null) {
                    continue;
                }
                $marche($index, $sirene, $attente['detail'], (int) $attente['capteur'], isset($attente['motif']) ? $attente['motif'] : 'alarme');
            }
            foreach ($etat['sirenes'] as $index => $enMarche) {
                $fin = is_array($enMarche) ? (int) $enMarche['fin'] : (int) $enMarche;
                /* Une horloge revenue en arrière ne doit pas prolonger la
                 * sirène : une fin plus lointaine que sa durée l'arrête aussi. */
                $duree = (is_array($enMarche) && isset($enMarche['duree'])) ? (int) $enMarche['duree'] : self::SIRENE_DUREE_MAX;
                if ($_t >= $fin || $fin - $_t > $duree + 60) {
                    $plan[] = $arret($index, 'Durée maximale atteinte');
                    unset($etat['sirenes'][$index]);
                }
            }
        }
        /* Plus d'alarme en cours : la mémoire de l'incident repart à zéro
         * quand plus rien ne sonne ni n'attend. */
        /* La mémoire « sabotage » (s:…), elle, ne repart qu'au désarmement : une
         * sirène de sabotage sonne une fois par armement, pas à chaque
         * impulsion d'une caméra masquée. */
        if (!$enAlarme($_apres) && count($etat['sirenes']) == 0 && count($etat['attentes']) == 0) {
            foreach (array_keys($etat['faites']) as $fait) {
                if (strpos((string) $fait, 's:') !== 0) {
                    unset($etat['faites'][$fait]);
                }
            }
            $etat['centrale'] = false;
        }
        return array($plan, $etat);
    }

    /* Le signal de vie est-il dû ? Rend array(dû, nouvel état). */
    public static function vieDue($_etat, $_minutes, $_t) {
        $etat = is_array($_etat) ? array_merge(self::etatInitial(), $_etat) : self::etatInitial();
        if ((int) $_minutes <= 0 || $_t - (int) $etat['vie'] < (int) $_minutes * 60 - 5) {
            return array(false, $etat);
        }
        $etat['vie'] = $_t;
        return array(true, $etat);
    }

    /* Les listes d'actions, au format du sélecteur des scénarios. Une ligne
     * sans commande est retirée : elle ne ferait rien. */
    public static function nettoyer($_sorties) {
        $propres = array();
        foreach (self::DECLENCHEURS as $declencheur) {
            $propres[$declencheur] = array();
            if (!is_array($_sorties) || !isset($_sorties[$declencheur]) || !is_array($_sorties[$declencheur])) {
                continue;
            }
            foreach ($_sorties[$declencheur] as $action) {
                if (!is_array($action) || !isset($action['cmd']) || trim((string) $action['cmd']) === '') {
                    continue;
                }
                $propres[$declencheur][] = array(
                    'cmd' => trim((string) $action['cmd']),
                    'options' => (isset($action['options']) && is_array($action['options'])) ? $action['options'] : array(),
                );
            }
        }
        return $propres;
    }

    /*
     * Ce qu'il faut jouer. Rend array($plan, $etatSorties), où $plan est une
     * liste de array('declencheur' => …, 'detail' => …, 'capteur' => id, 'genre' => …).
     *
     *  - chaque décision d'un genre qui agit donne son déclencheur ;
     *  - une alarme (ou sa confirmation) arme les relances ;
     *  - le retour au calme après une alarme joue « fin » et coupe les
     *    relances ;
     *  - un battement ($_battement) joue la relance due.
     *
     * $_relance : array('minutes' => …, 'max' => …, 'listes' => bool) — la
     * dernière clé dit si une liste « relance » existe : sans elle, rien à
     * programmer.
     */
    public static function planifier($_decisions, $_avant, $_apres, $_etatSorties, $_relance, $_t, $_battement = false) {
        $etat = is_array($_etatSorties) ? array_merge(self::etatInitial(), $_etatSorties) : self::etatInitial();
        $plan = array();
        $enAlarme = function ($_niveau) {
            return in_array($_niveau, array('alarme', 'confirmee'), true);
        };
        $minutes = isset($_relance['minutes']) ? max(0, (int) $_relance['minutes']) : self::RELANCE_MINUTES_DEFAUT;
        $max = isset($_relance['max']) ? max(0, (int) $_relance['max']) : self::RELANCE_MAX_DEFAUT;
        $relancer = !empty($_relance['listes']) && $minutes > 0 && $max > 0;

        $dernier = '';
        foreach (is_array($_decisions) ? $_decisions : array() as $decision) {
            $genre = isset($decision['genre']) ? $decision['genre'] : '';
            if (!in_array($genre, array('suspicion', 'alarme', 'confirmee', 'sabotage', 'defaut'), true)) {
                continue;
            }
            $plan[] = array('declencheur' => $genre, 'genre' => $genre, 'detail' => (string) $decision['detail'],
                            'capteur' => isset($decision['capteur']) ? (int) $decision['capteur'] : 0);
            if ($genre === 'alarme' || $genre === 'confirmee') {
                $dernier = (string) $decision['detail'];
            }
        }

        /* Une alarme de centrale reçue désarmée (incendie, panique) est une
         * décision « alarme » sans que le niveau monte : elle agit, mais ne
         * programme pas de relance, que rien ne viendrait arrêter. */
        /* La confirmation d'une alarme déjà relancée ne remet pas le compteur
         * à zéro : elle ne fait que changer le texte des relances suivantes. */
        if ($dernier !== '' && $enAlarme($_apres) && $relancer) {
            if (is_array($etat['relance'])) {
                $etat['relance']['detail'] = $dernier;
            } else {
                $etat['relance'] = array('prochaine' => $_t + $minutes * 60, 'restantes' => $max, 'faites' => 0, 'detail' => $dernier);
            }
        }

        /* La fin : retour au calme après une alarme, ou retour au repos d'une
         * alarme de centrale reçue désarmée (marque « fin » du moteur). */
        $finMarquee = false;
        foreach (is_array($_decisions) ? $_decisions : array() as $decision) {
            if (!empty($decision['fin'])) {
                $finMarquee = true;
            }
        }
        if (($enAlarme($_avant) && !$enAlarme($_apres)) || ($finMarquee && !$enAlarme($_apres))) {
            $plan[] = array('declencheur' => 'fin', 'genre' => 'fin', 'detail' => self::detailFin($_decisions), 'capteur' => 0);
            $etat['relance'] = null;
        }
        if (!$enAlarme($_apres)) {
            $etat['relance'] = null;
        }

        if ($_battement && is_array($etat['relance']) && $_t >= (int) $etat['relance']['prochaine']) {
            $etat['relance']['faites']++;
            $etat['relance']['restantes']--;
            $plan[] = array('declencheur' => 'relance', 'genre' => 'relance', 'capteur' => 0,
                            'detail' => $etat['relance']['detail'] . ' (relance ' . $etat['relance']['faites'] . ')');
            if ($etat['relance']['restantes'] <= 0) {
                $etat['relance'] = null;
            } else {
                $etat['relance']['prochaine'] = $_t + $minutes * 60;
            }
        }
        return array($plan, $etat);
    }

    /* Le texte de fin : la décision qui a ramené le calme, si elle est là. */
    private static function detailFin($_decisions) {
        foreach (array_reverse(is_array($_decisions) ? $_decisions : array()) as $decision) {
            if (isset($decision['genre']) && $decision['genre'] === 'info') {
                return (string) $decision['detail'];
            }
        }
        return 'Fin de l\'incident';
    }

    /*
     * Les remplacements des jetons propres à Vigilium dans les options.
     *
     * Un bloc ou une action en tâche de fond repasse ses options dans
     * l'évaluateur du cœur, qui prend « mot( » pour un appel de fonction. Un
     * nom de capteur ou une valeur de centrale ne doit pas pouvoir en
     * appeler une : une espace est glissée entre le mot et la parenthèse.
     */
    public static function jetons($_alarme, $_entree, $_niveau, $_mode, $_capteur, $_photos) {
        $neutre = function ($_texte) {
            return preg_replace('/(\w)\(/u', '$1 (', (string) $_texte);
        };
        return array(
            '#alarme#' => $neutre($_alarme),
            '#detail#' => $neutre($_entree['detail']),
            '#declencheur#' => $neutre($_entree['declencheur']),
            '#niveau#' => $neutre($_niveau),
            '#mode#' => $neutre($_mode),
            '#capteur#' => $neutre($_capteur),
            '#photo#' => count($_photos) > 0 ? (string) $_photos[0] : '',
            '#photos#' => implode(',', $_photos),
        );
    }

    /* Vrai si une des actions cite une photo : sans cela, aucune capture —
     * elle retarderait la première notification pour rien. */
    public static function demandePhotos($_actions) {
        foreach (is_array($_actions) ? $_actions : array() as $action) {
            foreach (isset($action['options']) && is_array($action['options']) ? $action['options'] : array() as $valeur) {
                if (is_string($valeur) && strpos($valeur, '#photo') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function cmdIdDe($_expression) {
        return preg_match('/^#(\d+)#$/', trim((string) $_expression), $m) ? (int) $m[1] : null;
    }

    /* ======================================================= CÔTÉ JEEDOM */

    /*
     * Joue une entrée du plan pour une alarme. En observation, rien n'est
     * exécuté — pas même une photo — et chaque action est écrite au journal
     * telle qu'elle serait partie. Rend la liste des échecs.
     */
    public static function executer($_eqLogic, $_entree, $_etatMoteur, $_config, $_observation, $_essai = false, &$_photosDuLot = null) {
        if ($_entree['declencheur'] === 'sirene_marche' || $_entree['declencheur'] === 'sirene_arret') {
            return self::sirene($_eqLogic, $_entree, $_observation, $_essai);
        }
        /* Le signal de vie n'est jamais tracé : une ligne par minute noierait
         * le journal. En observation, il ne part pas. */
        if ($_entree['declencheur'] === 'vie') {
            $cmd = cmd::byId((int) $_eqLogic->getConfiguration('vie_cmd', 0));
            if (!$_observation && is_object($cmd) && $cmd->getType() === 'action') {
                $cmd->execCmd();
            }
            return array('nombre' => 1, 'echecs' => array());
        }
        $sorties = self::nettoyer($_eqLogic->getConfiguration('sorties', array()));
        $actions = isset($sorties[$_entree['declencheur']]) ? $sorties[$_entree['declencheur']] : array();
        /* Les photos d'un même lot servent une fois : une alarme et sa
         * confirmation dans le même événement ne photographient pas deux fois. */
        $photos = array();
        if (in_array($_entree['declencheur'], self::AVEC_PHOTOS, true) && self::demandePhotos($actions)) {
            if (!is_array($_photosDuLot)) {
                $_photosDuLot = self::photos($_eqLogic, $_observation);
            }
            $photos = $_photosDuLot;
        }
        $capteur = (isset($_config['capteurs'][$_entree['capteur']])) ? $_config['capteurs'][$_entree['capteur']]['nom'] : '';
        $jetons = self::jetons($_eqLogic->getName(), $_entree, vigilium::libelleNiveau($_etatMoteur['niveau']),
                               vigilium::libelleMode($_etatMoteur['mode']), $capteur, $photos);
        $echecs = array();
        $jouees = 0;
        foreach ($actions as $action) {
            $options = $action['options'];
            if (isset($options['enable']) && $options['enable'] == 0) {
                continue;
            }
            $jouees++;
            /* Les jetons du cœur d'abord (variable(), une commande citée), les
             * nôtres ensuite : dans l'autre sens, un détail contenant des
             * parenthèses serait relu comme une expression. */
            $scenario = null;
            foreach ($options as $cle => $valeur) {
                if (!is_string($valeur) || $cle === 'enable' || $cle === 'background') {
                    continue;
                }
                $valeur = scenarioExpression::setTags($valeur, $scenario);
                $options[$cle] = str_replace(array_keys($jetons), array_values($jetons), $valeur);
            }
            if ($_observation) {
                $_eqLogic->journalAjouter(array('genre' => 'sortie', 'observation' => true, 'capteur' => (int) $_entree['capteur'],
                    'detail' => __('Aurait exécuté', __FILE__) . ' (' . $_entree['declencheur'] . ') : '
                              . self::decrire($action['cmd'], $options)));
                continue;
            }
            try {
                self::jouer($_eqLogic, $action['cmd'], $options);
            } catch (Throwable $e) {
                $echecs[] = jeedom::toHumanReadable($action['cmd']) . ' — ' . $e->getMessage();
                log::add('vigilium', 'error', $_eqLogic->getHumanName() . ' ' . __('action en échec :', __FILE__)
                    . ' ' . jeedom::toHumanReadable($action['cmd']) . ' — ' . $e->getMessage());
            }
        }
        if (!$_observation && $jouees > 0) {
            $_eqLogic->journalAjouter(array('genre' => 'sortie', 'observation' => false, 'capteur' => (int) $_entree['capteur'],
                'detail' => ($_essai ? '[' . __('Essai', __FILE__) . '] ' : '') . $jouees . ' ' . __('action(s)', __FILE__)
                          . ' (' . $_entree['declencheur'] . ')' . (count($echecs) > 0 ? ' ; ' . __('échecs :', __FILE__) . ' ' . implode(' ; ', $echecs) : '')));
        }
        return array('nombre' => $jouees, 'echecs' => $echecs);
    }

    /*
     * Met en marche ou arrête une sirène, avec les commandes que porte
     * l'entrée du plan — celles de la mise en marche, pour l'arrêt. Une
     * sirène sans commande d'arrêt ne peut pas être coupée par l'alarme :
     * l'arrêt est alors seulement noté.
     *
     * Un arrêt part pour de vrai dès que la mise en marche l'était
     * ('reelle'), même si l'alarme est repassée en observation depuis.
     */
    private static function sirene($_eqLogic, $_entree, $_observation, $_essai) {
        $marche = ($_entree['declencheur'] === 'sirene_marche');
        $nom = isset($_entree['nom']) ? (string) $_entree['nom'] : ('#' . (int) $_entree['sirene']);
        $id = $marche ? (int) (isset($_entree['marche']) ? $_entree['marche'] : 0) : (int) (isset($_entree['arret']) ? $_entree['arret'] : 0);
        $reel = $marche ? !$_observation : (!$_observation || !empty($_entree['reelle']));
        $duree = isset($_entree['duree']) ? (int) $_entree['duree'] : 0;
        $prefixe = $_essai ? '[' . __('Essai', __FILE__) . '] ' : '';
        if ($id <= 0) {
            $_eqLogic->journalAjouter(array('genre' => $marche ? 'defaut' : 'sortie', 'observation' => !$reel, 'capteur' => 0,
                'detail' => $prefixe . ($marche
                    ? sprintf(__('Sirène « %s » : aucune commande de mise en marche', __FILE__), $nom)
                    : sprintf(__('Sirène « %s » : aucune commande d\'arrêt, elle s\'arrêtera d\'elle-même', __FILE__), $nom))));
            return array('nombre' => 0, 'echecs' => array());
        }
        if (!$reel) {
            $_eqLogic->journalAjouter(array('genre' => 'sortie', 'observation' => true, 'capteur' => (int) $_entree['capteur'],
                'detail' => ($marche
                    ? sprintf(__('Aurait mis en marche la sirène « %s » (%d s au plus)', __FILE__), $nom, $duree)
                    : sprintf(__('Aurait arrêté la sirène « %s »', __FILE__), $nom)) . ' — ' . $_entree['detail']));
            return array('nombre' => 1, 'echecs' => array());
        }
        $libelle = $marche
            ? sprintf(__('Sirène « %s » mise en marche (%d s au plus)', __FILE__), $nom, $duree)
            : sprintf(__('Sirène « %s » arrêtée', __FILE__), $nom);
        $echecs = array();
        try {
            $cmd = cmd::byId($id);
            if (!is_object($cmd) || $cmd->getType() !== 'action') {
                throw new Exception(__('commande introuvable ou qui n\'est pas une action :', __FILE__) . ' #' . $id . '#');
            }
            $cmd->execCmd();
        } catch (Throwable $e) {
            $echecs[] = $e->getMessage();
            log::add('vigilium', 'error', $_eqLogic->getHumanName() . ' ' . $libelle . ' — ' . $e->getMessage());
        }
        try {
            $_eqLogic->journalAjouter(array('genre' => count($echecs) > 0 ? 'defaut' : 'sortie', 'observation' => false, 'capteur' => (int) $_entree['capteur'],
                'detail' => $prefixe . $libelle . ' — ' . $_entree['detail'] . (count($echecs) > 0 ? ' ; ' . __('échec :', __FILE__) . ' ' . implode(' ; ', $echecs) : '')));
        } catch (Throwable $e) {
            log::add('vigilium', 'error', $_eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
        return array('nombre' => 1, 'echecs' => $echecs);
    }

    /* Une action, lisible au journal : la commande et son titre ou message. */
    private static function decrire($_cmd, $_options) {
        $texte = jeedom::toHumanReadable($_cmd);
        foreach (array('title', 'message') as $cle) {
            if (isset($_options[$cle]) && trim((string) $_options[$cle]) !== '') {
                $valeur = trim((string) $_options[$cle]);
                $texte .= ' — ' . (mb_strlen($valeur) > 120 ? mb_substr($valeur, 0, 120) . '…' : $valeur);
            }
        }
        return $texte;
    }

    private static function jouer($_eqLogic, $_expression, $_options) {
        $expression = trim((string) $_expression);
        if (in_array($expression, self::BLOCS_REFUSES, true)) {
            throw new Exception(__('bloc inutilisable ici :', __FILE__) . ' ' . $expression);
        }
        /* Une commande restée sous sa forme lisible n'a pas été retrouvée à
         * l'enregistrement : le cœur la prendrait pour un bloc, et l'échec
         * serait silencieux. */
        if (preg_match('/^#\[.*\]#$/', $expression)) {
            throw new Exception(__('commande introuvable :', __FILE__) . ' ' . $expression);
        }
        $id = self::cmdIdDe($expression);
        if ($id === null) {
            /* Un bloc : message, scénario, variable… Le cœur rattrape lui-même
             * ses échecs : ils ne remontent qu'à son log « scenario ». */
            $_options['source'] = $_eqLogic->getHumanName();
            scenarioExpression::createAndExec('action', $expression, $_options);
            return;
        }
        $cmd = cmd::byId($id);
        if (!is_object($cmd)) {
            throw new Exception(__('commande introuvable :', __FILE__) . ' ' . $expression);
        }
        if ($cmd->getType() != 'action') {
            throw new Exception(__('ce n\'est pas une commande d\'action :', __FILE__) . ' ' . $cmd->getHumanName());
        }
        if (isset($_options['background']) && $_options['background'] == 1) {
            scenarioExpression::createAndExec('action', $expression, $_options);
            return;
        }
        unset($_options['enable'], $_options['background']);
        $cmd->execCmd($_options);
    }

    /*
     * Les photos des caméras réglées : la commande de capture (action), puis
     * le chemin du fichier (information). En observation, aucune capture :
     * le chemin de la dernière image connue sert à rédiger la trace.
     */
    const PHOTOS_DUREE_MAX = 20;

    public static function photos($_eqLogic, $_observation) {
        $chemins = array();
        $cameras = $_eqLogic->getConfiguration('photos', array());
        $debut = microtime(true);
        foreach (is_array($cameras) ? $cameras : array() as $camera) {
            /* Au-delà d'un temps borné, les caméras suivantes ne sont plus
             * capturées : chacune peut attendre son délai réseau, et la sirène
             * attend les photos. Leur dernière image connue sert alors. */
            $tropTard = (microtime(true) - $debut) > self::PHOTOS_DUREE_MAX;
            $capture = cmd::byId(isset($camera['capture']) ? (int) $camera['capture'] : 0);
            $fichier = cmd::byId(isset($camera['fichier']) ? (int) $camera['fichier'] : 0);
            if (!is_object($fichier) || $fichier->getType() !== 'info') {
                continue;
            }
            if (!$_observation && !$tropTard && is_object($capture) && $capture->getType() === 'action') {
                try {
                    $capture->execCmd();
                } catch (Throwable $e) {
                    log::add('vigilium', 'warning', $_eqLogic->getHumanName() . ' ' . __('photo impossible :', __FILE__)
                        . ' ' . $capture->getHumanName() . ' — ' . $e->getMessage());
                    continue;
                }
            }
            $chemin = trim((string) $fichier->execCmd());
            if ($chemin !== '') {
                $chemins[] = $chemin;
            }
        }
        return $chemins;
    }
}
