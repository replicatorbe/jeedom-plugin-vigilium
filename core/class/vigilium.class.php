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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
/* L'autoload du cœur ne connaît que la classe qui porte le nom du plugin : le
 * moteur se charge par elle, depuis le cron comme depuis l'écouteur. */
require_once __DIR__ . '/vigiliumMoteur.class.php';

/*
 * Un équipement Vigilium = une alarme coordonnée : une centrale qui donne le
 * mode d'armement, et des capteurs de toutes origines (Ajax, caméras, Shelly
 * BLU…) qui ont chacun un rôle.
 *
 * Ce fichier traduit Jeedom pour le moteur : les changements de commandes
 * deviennent des événements, les décisions du moteur deviennent un journal et
 * des commandes d'état. Il n'y a pas encore de sortie : le plugin OBSERVE. Il
 * écrit ce qu'il aurait fait, et c'est en relisant ce journal qu'on décidera
 * de lui confier les sirènes.
 */
class vigilium extends eqLogic {

    /* Taille au-delà de laquelle le journal est réduit à sa seconde moitié. */
    const JOURNAL_MAX_LIGNES = 5000;

    /* ================================================================ CYCLE */

    public function postSave() {
        $this->createCommands();
        $this->syncListener();
        /* Désactivée, l'alarme oublie son état : réactivée des heures plus
         * tard, un délai d'entrée laissé en cours lèverait sinon une alarme
         * au premier battement. */
        if ($this->getIsEnable() != 1 && file_exists($this->fichierEtat())) {
            @unlink($this->fichierEtat());
        }
    }

    public function preRemove() {
        $this->removeListener();
        foreach (array($this->fichierEtat(), $this->fichierJournal(), $this->fichierVerrou()) as $fichier) {
            if (file_exists($fichier)) {
                @unlink($fichier);
            }
        }
    }

    /*
     * Les commandes publiées par l'alarme. Créées si absentes, jamais
     * renommées : un nom changé à la main doit survivre aux mises à jour.
     */
    public function createCommands() {
        $definitions = array(
            'mode' => array(__('Mode', __FILE__), 'info', 'string'),
            'niveau' => array(__('Niveau', __FILE__), 'info', 'string'),
            'evenement' => array(__('Dernier événement', __FILE__), 'info', 'string'),
            'exclus' => array(__('Capteurs exclus', __FILE__), 'info', 'string'),
            'muets' => array(__('Capteurs muets', __FILE__), 'info', 'numeric'),
            'acquitter' => array(__('Acquitter', __FILE__), 'action', 'other'),
        );
        $ordre = 0;
        foreach ($definitions as $logicalId => $definition) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                $cmd = new vigiliumCmd();
                $cmd->setLogicalId($logicalId);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition[0]);
                $cmd->setType($definition[1]);
                $cmd->setSubType($definition[2]);
                $cmd->setOrder($ordre);
                $cmd->setIsVisible(1);
                $cmd->save();
            }
            /* Deux décisions de même texte — une seconde suspicion après le
             * repos — sont deux événements : sans cela, la seconde ne
             * déclencherait aucun scénario. */
            if ($logicalId === 'evenement' && $cmd->getConfiguration('repeatEventManagement', 'auto') !== 'always') {
                $cmd->setConfiguration('repeatEventManagement', 'always');
                $cmd->save();
            }
            $ordre++;
        }
    }

    /* ============================================================ ÉCOUTEUR */

    /*
     * Toutes les commandes qui intéressent l'alarme : celle du mode, et celle
     * de chaque capteur. Un seul écouteur par équipement, reposé à chaque
     * enregistrement, par vigilium_update(), et par le cron s'il ne couvre
     * plus toutes les commandes : listener::clean() retire en silence
     * l'événement d'une commande disparue, et l'écouteur entier s'il n'en
     * reste aucun.
     */
    public function commandesEcoutees() {
        $ids = array();
        $mode = (int) $this->getConfiguration('cmd_mode', 0);
        if ($mode > 0) {
            $ids[] = $mode;
        }
        foreach ($this->capteursConfigures() as $capteur) {
            $ids[] = (int) $capteur['cmd'];
        }
        $existantes = array();
        foreach (array_unique($ids) as $id) {
            if (is_object(cmd::byId($id))) {
                $existantes[] = $id;
            }
        }
        return $existantes;
    }

    public function syncListener() {
        $option = array('id' => (int) $this->getId());
        $listener = listener::byClassAndFunction(__CLASS__, 'onEvenement', $option);
        $ids = $this->commandesEcoutees();
        if ($this->getIsEnable() != 1 || count($ids) == 0) {
            if (is_object($listener)) {
                $listener->remove();
            }
            return;
        }
        if (!is_object($listener)) {
            $listener = new listener();
            $listener->setClass(__CLASS__);
            $listener->setFunction('onEvenement');
            $listener->setOption($option);
        }
        $listener->emptyEvent();
        foreach ($ids as $id) {
            $listener->addEvent($id);
        }
        $listener->save();
    }

    public function removeListener() {
        $listener = listener::byClassAndFunction(__CLASS__, 'onEvenement', array('id' => (int) $this->getId()));
        if (is_object($listener)) {
            $listener->remove();
        }
    }

    /*
     * Rappel de l'écouteur, dans un processus séparé (jeeListener.php). Ce qui
     * serait levé ici ne remonterait à personne : tout est rattrapé et écrit.
     */
    public static function onEvenement($_option) {
        try {
            $id = (is_array($_option) && isset($_option['id'])) ? (int) $_option['id'] : 0;
            $eqLogic = ($id > 0) ? eqLogic::byId($id) : null;
            if (!is_object($eqLogic) || $eqLogic->getEqType_name() !== __CLASS__) {
                $listener = listener::byClassAndFunction(__CLASS__, 'onEvenement', array('id' => $id));
                if (is_object($listener)) {
                    $listener->remove();
                }
                return;
            }
            if ($eqLogic->getIsEnable() != 1) {
                return;
            }
            $cmdId = isset($_option['event_id']) ? (int) $_option['event_id'] : 0;
            $valeur = isset($_option['value']) ? $_option['value'] : null;
            $eqLogic->recevoir($cmdId, $valeur);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', __('Écouteur :', __FILE__) . ' ' . $e->getMessage());
        }
    }

    /*
     * Une commande a changé : le mode, ou un capteur. Les deux à la fois si
     * l'on a désigné la même commande (sans objet, mais sans danger).
     */
    public function recevoir($_cmdId, $_valeur) {
        $estMode = ($_cmdId > 0 && $_cmdId === (int) $this->getConfiguration('cmd_mode', 0));
        $capteurs = array();
        foreach ($this->capteursConfigures() as $capteur) {
            if ((int) $capteur['cmd'] === $_cmdId) {
                $capteurs[] = array('type' => 'capteur', 'id' => $_cmdId, 'alerte' => self::enAlerte($capteur, $_valeur));
            }
        }
        if (!$estMode && count($capteurs) == 0) {
            return;
        }
        /* Le mode est RELU sous le verrou, et non pris dans l'événement :
         * chaque événement de l'écouteur part dans son propre processus, et
         * « Armé » puis « Désarmé » rapprochés peuvent prendre le verrou dans
         * le désordre. La valeur courante, elle, est toujours la bonne. Un
         * capteur garde au contraire sa valeur d'événement : une impulsion de
         * caméra est déjà retombée quand on la relit. */
        $this->appliquer(function () use ($estMode, $capteurs) {
            $evenements = array();
            if ($estMode) {
                $evenement = $this->evenementModeCourant();
                if ($evenement !== null) {
                    $evenements[] = $evenement;
                }
            }
            return array_merge($evenements, $capteurs);
        });
    }

    /* ================================================================= CRON */

    /*
     * Chaque minute : le passage du temps (fin du délai d'entrée), la
     * supervision des capteurs, et le rattrapage du mode — si l'écouteur a été
     * perdu, l'alarme ne doit pas rester désarmée dans la tête du moteur
     * pendant que la maison est armée.
     */
    public static function cron() {
        foreach (eqLogic::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->battement();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    public function battement() {
        $this->verifierEcouteur();
        /* Tout est lu sous le verrou, mode compris : un mode lu avant, puis
         * appliqué après un désarmement traité entre-temps par l'écouteur,
         * réarmerait l'alarme avec une valeur périmée. */
        $this->appliquer(function () {
            return $this->evenementsBattement();
        });
    }

    private function evenementsBattement() {
        $evenements = array();
        $evenement = $this->evenementModeCourant();
        if ($evenement !== null) {
            $evenements[] = $evenement;
        }
        $evenements[] = array('type' => 'tick');
        $maintenant = time();
        $etat = $this->lireEtat();
        foreach ($this->capteursConfigures() as $capteur) {
            $id = (int) $capteur['cmd'];
            $cmd = cmd::byId($id);
            /* Une commande action désignée comme capteur serait EXÉCUTÉE par
             * execCmd() à chaque lecture : elle compte comme introuvable. */
            $utilisable = is_object($cmd) && $cmd->getType() === 'info';
            $evenements[] = array('type' => 'disparu', 'id' => $id, 'disparu' => !$utilisable);
            if (!$utilisable) {
                continue;
            }
            $muet = $this->capteurMuet($capteur, $maintenant);
            if ($muet !== null) {
                $evenements[] = array('type' => 'muet', 'id' => $id, 'muet' => $muet);
            }
            /* Un capteur exclu à l'armement ne revient sous surveillance que
             * sur son retour au repos. Ce retour peut avoir été manqué par
             * l'écouteur — valeur jamais renvoyée, événements traités dans le
             * désordre : la valeur courante est donc relue ici. Seul le repos
             * est injecté ; rejouer une alerte chaque minute serait faux. */
            /* Même rattrapage pour un sabotage ou une alarme de centrale restés
             * « en cours » : leur retour à 0 a pu être traité avant leur 1. */
            $enCours = !empty($etat['exclus'][$id]) || !empty($etat['defauts']['sabotage-' . $id]) || !empty($etat['defauts']['centrale-' . $id]);
            if ($enCours && !self::enAlerte($capteur, $cmd->execCmd())) {
                $evenements[] = array('type' => 'capteur', 'id' => $id, 'alerte' => false);
            }
        }
        return $evenements;
    }

    /* L'événement de mode tiré de la valeur actuelle de la centrale, ou null
     * si aucune commande de mode n'est désignée. */
    private function evenementModeCourant() {
        $mode = (int) $this->getConfiguration('cmd_mode', 0);
        $cmdMode = ($mode > 0) ? cmd::byId($mode) : null;
        if (!is_object($cmdMode) || $cmdMode->getType() !== 'info') {
            return null;
        }
        return $this->evenementMode($cmdMode->execCmd());
    }

    /*
     * L'écouteur doit exister et couvrir chaque commande encore présente. Sinon
     * il est reposé, et le journal le dit : pendant qu'il manquait, l'alarme ne
     * voyait les capteurs qu'une fois par minute, ou pas du tout.
     */
    private function verifierEcouteur() {
        $ids = $this->commandesEcoutees();
        if (count($ids) == 0) {
            return;
        }
        $listener = listener::byClassAndFunction(__CLASS__, 'onEvenement', array('id' => (int) $this->getId()));
        $manque = !is_object($listener);
        if (!$manque) {
            $evenements = $listener->getEvent();
            foreach ($ids as $id) {
                if (!in_array('#' . $id . '#', is_array($evenements) ? $evenements : array(), true)) {
                    $manque = true;
                    break;
                }
            }
        }
        if ($manque) {
            $this->syncListener();
            $this->journalAjouter(array('genre' => vigiliumMoteur::DECISION_DEFAUT,
                'detail' => __('Écouteur incomplet ou absent : reposé', __FILE__), 'capteur' => 0, 'observation' => true));
            log::add(__CLASS__, 'warning', $this->getHumanName() . ' : ' . __('écouteur reposé', __FILE__));
        }
    }

    /*
     * Vrai si le capteur est muet, faux s'il parle, null s'il n'est pas
     * supervisé. Deux indices, au choix par capteur :
     *  - « muet après » : plus de N minutes sans aucune remontée (collectDate).
     *    Réservé aux capteurs qui remontent périodiquement (pile, température,
     *    battement) : collectDate n'avance que si le plugin source rappelle
     *    event(), et un contact qui ne parle qu'au changement serait déclaré
     *    muet — donc saboté, alarme armée — après N minutes de calme ;
     *  - une commande « en ligne » qui vaut 0.
     */
    public function capteurMuet($_capteur, $_maintenant) {
        $supervise = false;
        $minutes = isset($_capteur['muet_apres']) ? (int) $_capteur['muet_apres'] : 0;
        if ($minutes > 0) {
            $supervise = true;
            $cmd = cmd::byId((int) $_capteur['cmd']);
            if (is_object($cmd)) {
                $date = $cmd->getCollectDate();
                if ($date == '' || $_maintenant - strtotime($date) > $minutes * 60) {
                    return true;
                }
            }
        }
        $enLigne = isset($_capteur['cmd_en_ligne']) ? (int) $_capteur['cmd_en_ligne'] : 0;
        if ($enLigne > 0) {
            $supervise = true;
            $cmd = cmd::byId($enLigne);
            if (is_object($cmd) && $cmd->getType() === 'info' && (string) $cmd->execCmd() === '0') {
                return true;
            }
        }
        return $supervise ? false : null;
    }

    /* ================================================================ MOTEUR */

    /*
     * Fait passer une suite d'événements dans le moteur, sous verrou : le
     * cron et l'écouteur tournent dans des processus distincts, et deux
     * lectures-écritures croisées de l'état perdraient un délai d'entrée.
     *
     * $_evenements : un tableau, ou une fonction qui le construit une fois le
     * verrou pris — pour ce qui doit être lu au même instant que l'état.
     */
    public function appliquer($_evenements) {
        $verrou = fopen($this->fichierVerrou(), 'c');
        if ($verrou === false) {
            throw new Exception(__('Verrou impossible à créer', __FILE__));
        }
        try {
            if (!flock($verrou, LOCK_EX)) {
                throw new Exception(__('Verrou impossible à poser', __FILE__));
            }
            $evenements = is_callable($_evenements) ? call_user_func($_evenements) : $_evenements;
            $etat = $this->lireEtat();
            $config = $this->configMoteur();
            $maintenant = time();
            $toutes = array();
            foreach ($evenements as $evenement) {
                list($etat, $decisions) = vigiliumMoteur::traiter($etat, $config, $evenement, $maintenant);
                foreach ($decisions as $decision) {
                    $toutes[] = $decision;
                }
            }
            $this->ecrireEtat($etat);
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
        /* L'état d'abord, la décision ensuite : un scénario déclenché par
         * « Dernier événement » doit lire le niveau qui va avec. */
        $this->publier($etat, $config);
        foreach ($toutes as $decision) {
            $this->decider($decision);
        }
    }

    /*
     * Une décision du moteur. En observation, la seule conséquence est le
     * journal : ce qui y est marqué « aurait déclenché » est exactement ce que
     * les sorties feront le jour où elles seront branchées.
     */
    private function decider($_decision) {
        $genre = $_decision['genre'];
        $agirait = in_array($genre, array(
            vigiliumMoteur::DECISION_SUSPICION, vigiliumMoteur::DECISION_ALARME,
            vigiliumMoteur::DECISION_CONFIRMEE, vigiliumMoteur::DECISION_SABOTAGE,
        ), true);
        $this->journalAjouter(array(
            'genre' => $genre,
            'detail' => $_decision['detail'],
            'capteur' => $_decision['capteur'],
            'observation' => true,
        ));
        $niveauLog = $agirait ? 'warning' : 'info';
        log::add(__CLASS__, $niveauLog, $this->getHumanName() . ' : ' . $_decision['detail']
            . ($agirait ? ' ' . __('(observation : aucune sortie déclenchée)', __FILE__) : ''));
        $this->checkAndUpdateCmd('evenement', $_decision['detail']);
    }

    private function publier($_etat, $_config) {
        $modes = array(
            vigiliumMoteur::MODE_DESARME => __('Désarmé', __FILE__),
            vigiliumMoteur::MODE_TOTAL => __('Total', __FILE__),
            vigiliumMoteur::MODE_NUIT => __('Nuit', __FILE__),
        );
        $this->checkAndUpdateCmd('mode', isset($modes[$_etat['mode']]) ? $modes[$_etat['mode']] : $_etat['mode']);
        $this->checkAndUpdateCmd('niveau', self::libelleNiveau($_etat['niveau']));
        $exclus = array();
        foreach (array_keys($_etat['exclus']) as $id) {
            $exclus[] = isset($_config['capteurs'][$id]) ? $_config['capteurs'][$id]['nom'] : ('#' . $id);
        }
        /* Jamais vide : checkAndUpdateCmd() relance l'événement à chaque appel
         * quand l'ancienne valeur est une chaîne vide, soit une fois par minute. */
        $this->checkAndUpdateCmd('exclus', count($exclus) > 0 ? implode(', ', $exclus) : __('aucun', __FILE__));
        $this->checkAndUpdateCmd('muets', count($_etat['muets']));
    }

    public static function libelleNiveau($_niveau) {
        $libelles = array(
            vigiliumMoteur::NIVEAU_CALME => __('Calme', __FILE__),
            vigiliumMoteur::NIVEAU_SUSPICION => __('Suspicion', __FILE__),
            vigiliumMoteur::NIVEAU_DELAI => __('Délai d\'entrée', __FILE__),
            vigiliumMoteur::NIVEAU_ALARME => __('Alarme', __FILE__),
            vigiliumMoteur::NIVEAU_CONFIRMEE => __('Alarme confirmée', __FILE__),
        );
        return isset($libelles[$_niveau]) ? $libelles[$_niveau] : $_niveau;
    }

    /* ======================================================= CONFIGURATION */

    public function capteursConfigures() {
        $capteurs = $this->getConfiguration('capteurs', array());
        if (!is_array($capteurs)) {
            return array();
        }
        $propres = array();
        foreach ($capteurs as $capteur) {
            if (is_array($capteur) && isset($capteur['cmd']) && (int) $capteur['cmd'] > 0) {
                $propres[] = $capteur;
            }
        }
        return $propres;
    }

    public function configMoteur() {
        return vigiliumMoteur::normaliserConfig(array(
            'delai_entree' => $this->getConfiguration('delai_entree', ''),
            'delai_sortie' => $this->getConfiguration('delai_sortie', ''),
            'delai_sortie_nuit' => $this->getConfiguration('delai_sortie_nuit', ''),
            'annulation' => $this->getConfiguration('annulation', ''),
            'fenetre' => $this->getConfiguration('fenetre', ''),
            'repos_suspicion' => $this->getConfiguration('repos_suspicion', ''),
            'capteurs' => $this->capteursConfigures(),
        ));
    }

    /*
     * La valeur brute de la centrale traduite en mode. Comparaison en texte,
     * sans casse ni espaces autour : « Mode nuit » et « mode nuit » sont le
     * même mode, et une centrale qui change de majuscule à une mise à jour ne
     * doit pas désarmer l'alarme dans la tête du moteur.
     */
    public function evenementMode($_valeur) {
        $valeur = mb_strtolower(trim((string) $_valeur));
        $mode = null;
        foreach (array(
            vigiliumMoteur::MODE_TOTAL => $this->valeurMode('valeur_total'),
            vigiliumMoteur::MODE_NUIT => $this->valeurMode('valeur_nuit'),
            /* L'armement partiel (groupes) d'Ajax : un périmètre réduit, lu
             * comme le mode nuit. */
            'partiel' => $this->valeurMode('valeur_partiel'),
            vigiliumMoteur::MODE_DESARME => $this->valeurMode('valeur_desarme'),
        ) as $candidat => $attendue) {
            if ($valeur === mb_strtolower($attendue)) {
                $mode = ($candidat === 'partiel') ? vigiliumMoteur::MODE_NUIT : $candidat;
                break;
            }
        }
        /* Seule la valeur « désarmé » explicite désarme. Une valeur inconnue —
         * chaîne vide pendant que le plugin de la centrale redémarre, libellé
         * renommé par une mise à jour — garde le mode précédent : la lire comme
         * un désarmement clorait l'incident en cours et relancerait le délai de
         * sortie au retour de la vraie valeur. */
        if ($mode === null) {
            return array('type' => 'mode_inconnu', 'valeur' => (string) $_valeur);
        }
        /* L'état de chaque capteur, et depuis quand il est en alerte : une
         * fenêtre exclue « depuis le 29/09 » est un capteur à réparer. */
        $alertes = array();
        $depuis = array();
        foreach ($this->capteursConfigures() as $capteur) {
            $cmd = cmd::byId((int) $capteur['cmd']);
            if (is_object($cmd) && $cmd->getType() === 'info') {
                $id = (int) $capteur['cmd'];
                $alertes[$id] = self::enAlerte($capteur, $cmd->execCmd());
                if ($alertes[$id] && $cmd->getValueDate() != '') {
                    $depuis[$id] = (int) strtotime($cmd->getValueDate());
                }
            }
        }
        return array('type' => 'mode', 'mode' => $mode, 'alertes' => $alertes, 'depuis' => $depuis);
    }

    /*
     * Les trois libellés de la centrale, avec ceux d'Ajax pour défaut : un
     * champ laissé vide ne doit pas rendre l'alarme incapable de s'armer.
     */
    public function valeurMode($_cle) {
        $defauts = array('valeur_total' => 'Armé', 'valeur_nuit' => 'Mode nuit', 'valeur_partiel' => 'Armé partiel', 'valeur_desarme' => 'Désarmé');
        $valeur = trim((string) $this->getConfiguration($_cle, ''));
        return ($valeur === '') ? $defauts[$_cle] : $valeur;
    }

    /*
     * Un capteur est en alerte quand sa valeur égale la « valeur d'alerte »
     * configurée : 1 pour un détecteur, 0 pour un contact Ajax dont l'info
     * dit « fermé ». Comparaison numérique si les deux sont des nombres —
     * « 1 », 1 et 1.0 sont la même alerte —, en texte sinon.
     */
    public static function enAlerte($_capteur, $_valeur) {
        $attendue = isset($_capteur['alerte']) ? trim((string) $_capteur['alerte']) : '1';
        if ($attendue === '') {
            $attendue = '1';
        }
        $valeur = trim((string) $_valeur);
        if (is_numeric($attendue) && is_numeric($valeur)) {
            return ((float) $attendue === (float) $valeur);
        }
        return (mb_strtolower($attendue) === mb_strtolower($valeur));
    }

    /* ============================================================== FICHIERS */

    public static function dossierDonnees() {
        $dossier = __DIR__ . '/../../data';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0775, true);
        }
        return $dossier;
    }

    public function fichierEtat() {
        return self::dossierDonnees() . '/etat-' . (int) $this->getId() . '.json';
    }

    public function fichierJournal() {
        return self::dossierDonnees() . '/journal-' . (int) $this->getId() . '.jsonl';
    }

    public function fichierVerrou() {
        return self::dossierDonnees() . '/etat-' . (int) $this->getId() . '.lock';
    }

    public function lireEtat() {
        $fichier = $this->fichierEtat();
        if (!file_exists($fichier)) {
            return vigiliumMoteur::etatInitial();
        }
        return vigiliumMoteur::normaliserEtat(json_decode((string) file_get_contents($fichier), true));
    }

    /* Écriture atomique : un état à moitié écrit, relu au battement suivant,
     * effacerait l'incident en cours. */
    private function ecrireEtat($_etat) {
        $fichier = $this->fichierEtat();
        $temporaire = $fichier . '.tmp';
        $json = json_encode($_etat, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || file_put_contents($temporaire, $json) === false || !rename($temporaire, $fichier)) {
            /* L'état précédent reste en place : l'incident n'est pas perdu, mais
             * cette décision-ci l'est, et il faut que cela se voie. */
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . __('état de l\'alarme non enregistré dans', __FILE__) . ' ' . $fichier);
        }
    }

    public function journalAjouter($_entree) {
        $_entree = array_merge(array('t' => time()), $_entree);
        $fichier = $this->fichierJournal();
        if (file_put_contents($fichier, json_encode($_entree, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX) === false) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . __('journal non écrit :', __FILE__) . ' ' . $fichier);
            return;
        }
        /* Réduction rare : un contrôle de taille à chaque ligne coûterait plus
         * que le journal lui-même. Lecture et réécriture sous le même verrou
         * que les ajouts, sinon une ligne écrite entre les deux serait perdue. */
        if (mt_rand(1, 200) === 1) {
            $flux = @fopen($fichier, 'c+');
            if ($flux !== false && flock($flux, LOCK_EX)) {
                $lignes = preg_split('/\n/', (string) stream_get_contents($flux), -1, PREG_SPLIT_NO_EMPTY);
                if (count($lignes) > self::JOURNAL_MAX_LIGNES) {
                    $garde = array_slice($lignes, -(int) (self::JOURNAL_MAX_LIGNES / 2));
                    ftruncate($flux, 0);
                    rewind($flux);
                    fwrite($flux, implode("\n", $garde) . "\n");
                }
                flock($flux, LOCK_UN);
            }
            if ($flux !== false) {
                fclose($flux);
            }
        }
    }

    /* Les dernières entrées, les plus récentes en premier. */
    public function journalLire($_nombre = 200) {
        $fichier = $this->fichierJournal();
        if (!file_exists($fichier)) {
            return array();
        }
        $lignes = @file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lignes)) {
            return array();
        }
        $entrees = array();
        foreach (array_reverse(array_slice($lignes, -max(1, (int) $_nombre))) as $ligne) {
            $entree = json_decode($ligne, true);
            if (is_array($entree)) {
                $entrees[] = $entree;
            }
        }
        return $entrees;
    }
}

class vigiliumCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if ($this->getLogicalId() === 'acquitter' && is_object($eqLogic)) {
            $eqLogic->appliquer(array(array('type' => 'acquitter')));
        }
    }
}
