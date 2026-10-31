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
        return array('relance' => null);
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
