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
 * Le moteur de décision : un état, un événement, une heure → un nouvel état et
 * la liste de ce qu'il faudrait faire.
 *
 * Aucune dépendance à Jeedom, volontairement. C'est le cœur d'une alarme : il
 * doit pouvoir se rejouer hors ligne, scénario par scénario, dans
 * tests/moteur.php — une intrusion simulée ne se teste pas en ouvrant la porte
 * de la maison à trois heures du matin. Tout ce qui touche à Jeedom (lire une
 * commande, écrire un journal, publier un état) vit dans vigilium.class.php,
 * qui traduit le monde en événements et les décisions en effets.
 *
 * Le mode d'armement n'est pas décidé ici : il est lu sur la centrale (Ajax),
 * qui reste seule maîtresse de l'armement. Le moteur ne fait que le suivre.
 *
 * Niveaux d'un incident, du plus faible au plus fort :
 *   calme      rien en cours ;
 *   suspicion  un capteur extérieur (caméra) a vu quelque chose, alarme armée ;
 *   delai      le parcours d'entrée est ouvert, on attend le désarmement ;
 *   alarme     un capteur a déclenché ;
 *   confirmee  un second capteur, différent, l'a confirmé dans la fenêtre.
 */
class vigiliumMoteur {

    const MODE_DESARME = 'desarme';
    const MODE_TOTAL = 'total';
    const MODE_NUIT = 'nuit';

    const ROLE_ENTREE = 'entree';
    const ROLE_PERIMETRE = 'perimetre';
    const ROLE_INTERIEUR = 'interieur';
    const ROLE_EXTERIEUR = 'exterieur';
    const ROLE_CENTRALE = 'centrale';
    const ROLE_SABOTAGE = 'sabotage';

    const NIVEAU_CALME = 'calme';
    const NIVEAU_SUSPICION = 'suspicion';
    const NIVEAU_DELAI = 'delai';
    const NIVEAU_ALARME = 'alarme';
    const NIVEAU_CONFIRMEE = 'confirmee';

    /* Genre d'une décision : ce qu'elle déclencherait une fois les sorties
     * branchées. « info » ne déclenche jamais rien, elle se journalise. */
    const DECISION_INFO = 'info';
    const DECISION_SUSPICION = 'suspicion';
    const DECISION_ALARME = 'alarme';
    const DECISION_CONFIRMEE = 'confirmee';
    const DECISION_SABOTAGE = 'sabotage';
    const DECISION_DEFAUT = 'defaut';

    const DELAI_ENTREE_DEFAUT = 30;
    const DELAI_SORTIE_DEFAUT = 60;
    const FENETRE_DEFAUT = 300;
    const REPOS_SUSPICION_DEFAUT = 300;

    public static function roles() {
        return array(self::ROLE_ENTREE, self::ROLE_PERIMETRE, self::ROLE_INTERIEUR,
                     self::ROLE_EXTERIEUR, self::ROLE_CENTRALE, self::ROLE_SABOTAGE);
    }

    public static function etatInitial() {
        return array(
            'mode' => self::MODE_DESARME,
            'arme_depuis' => 0,
            'niveau' => self::NIVEAU_CALME,
            'incident' => null,
            'delai_fin' => 0,
            'exclus' => array(),
            'recents' => array(),
            'derniere_suspicion' => 0,
            'muets' => array(),
            'defauts' => array(),
        );
    }

    /*
     * Complète un état relu sur disque : un fichier écrit par une version plus
     * ancienne, ou tronqué, ne doit pas faire tomber le moteur sur une clé
     * absente au milieu d'un incident.
     */
    public static function normaliserEtat($_etat) {
        $etat = self::etatInitial();
        if (!is_array($_etat)) {
            return $etat;
        }
        foreach ($etat as $cle => $defaut) {
            if (array_key_exists($cle, $_etat) && gettype($_etat[$cle]) === gettype($defaut)) {
                $etat[$cle] = $_etat[$cle];
            }
        }
        if (isset($_etat['incident']) && is_array($_etat['incident'])) {
            $etat['incident'] = $_etat['incident'];
        }
        return $etat;
    }

    /*
     * La configuration telle que le moteur la lit : capteurs indexés par
     * identifiant de commande, délais bornés. Ce qui manque prend la valeur
     * par défaut plutôt que zéro : un délai d'entrée à zéro par oubli ferait
     * sonner la maison à chaque retour.
     */
    public static function normaliserConfig($_config) {
        $c = is_array($_config) ? $_config : array();
        /* Déjà normalisée : la repasser écarterait tous les capteurs, que la
         * première passe a réindexés par commande et privés de leur clé
         * « cmd ». C'est le chemin réel — vigilium::configMoteur() normalise,
         * puis traiter() — et il doit rester idempotent. */
        if (!empty($c['normalisee'])) {
            return $c;
        }
        $borne = function ($_valeur, $_defaut, $_min, $_max) {
            if ($_valeur === null || $_valeur === '' || !is_numeric($_valeur)) {
                return $_defaut;
            }
            return max($_min, min($_max, (int) $_valeur));
        };
        $config = array(
            'delai_entree' => $borne(isset($c['delai_entree']) ? $c['delai_entree'] : null, self::DELAI_ENTREE_DEFAUT, 0, 600),
            'delai_sortie' => $borne(isset($c['delai_sortie']) ? $c['delai_sortie'] : null, self::DELAI_SORTIE_DEFAUT, 0, 900),
            'fenetre' => $borne(isset($c['fenetre']) ? $c['fenetre'] : null, self::FENETRE_DEFAUT, 30, 3600),
            'repos_suspicion' => $borne(isset($c['repos_suspicion']) ? $c['repos_suspicion'] : null, self::REPOS_SUSPICION_DEFAUT, 0, 3600),
            'capteurs' => array(),
            'normalisee' => true,
        );
        $capteurs = (isset($c['capteurs']) && is_array($c['capteurs'])) ? $c['capteurs'] : array();
        foreach ($capteurs as $capteur) {
            if (!is_array($capteur) || !isset($capteur['cmd']) || (int) $capteur['cmd'] <= 0) {
                continue;
            }
            $id = (int) $capteur['cmd'];
            $role = isset($capteur['role']) ? (string) $capteur['role'] : '';
            if (!in_array($role, self::roles(), true)) {
                continue;
            }
            $modes = array();
            if (!empty($capteur['total'])) {
                $modes[] = self::MODE_TOTAL;
            }
            if (!empty($capteur['nuit'])) {
                $modes[] = self::MODE_NUIT;
            }
            $config['capteurs'][$id] = array(
                'nom' => (isset($capteur['nom']) && trim((string) $capteur['nom']) !== '') ? trim((string) $capteur['nom']) : ('#' . $id),
                'role' => $role,
                'modes' => $modes,
            );
        }
        return $config;
    }

    /*
     * Point d'entrée unique. $_evenement :
     *   array('type' => 'mode', 'mode' => …, 'alertes' => array(id => bool))
     *        — 'alertes' : l'état d'alerte de chaque capteur au moment de
     *          l'armement, pour exclure ce qui est déjà ouvert ;
     *   array('type' => 'capteur', 'id' => …, 'alerte' => bool)
     *   array('type' => 'muet', 'id' => …, 'muet' => bool)
     *   array('type' => 'tick')
     *   array('type' => 'acquitter')
     *
     * Rend array($etat, $decisions). Chaque décision :
     *   array('genre' => DECISION_*, 'detail' => texte, 'capteur' => id|0)
     */
    public static function traiter($_etat, $_config, $_evenement, $_t) {
        $etat = self::normaliserEtat($_etat);
        $config = self::normaliserConfig($_config);
        $t = (int) $_t;
        $decisions = array();
        $type = (is_array($_evenement) && isset($_evenement['type'])) ? $_evenement['type'] : '';

        self::oublierRetires($etat, $config);

        switch ($type) {
            case 'mode':
                self::changerMode($etat, $config, $_evenement, $t, $decisions);
                break;
            case 'mode_inconnu':
                $valeur = isset($_evenement['valeur']) ? (string) $_evenement['valeur'] : '';
                self::defaut($etat, 'mode', true, 'Mode de la centrale inconnu (« ' . $valeur . ' ») : mode précédent conservé', $decisions);
                break;
            case 'capteur':
                /* Le temps d'abord : un délai d'entrée échu entre deux
                 * battements du cron doit être constaté AVANT de classer ce
                 * capteur, sinon un couloir traversé après la fin du délai
                 * passerait pour « suit le délai d'entrée ». */
                self::tick($etat, $config, $t, $decisions);
                self::capteur($etat, $config, (int) $_evenement['id'], !empty($_evenement['alerte']), $t, $decisions);
                break;
            case 'disparu':
                $id = (int) $_evenement['id'];
                self::defaut($etat, 'disparu-' . $id, !empty($_evenement['disparu']),
                    'Commande introuvable pour ' . self::nom($config, $id) . ' : ce capteur n\'est plus surveillé', $decisions, $id);
                break;
            case 'muet':
                self::muet($etat, $config, (int) $_evenement['id'], !empty($_evenement['muet']), $decisions);
                break;
            case 'tick':
                self::tick($etat, $config, $t, $decisions);
                break;
            case 'acquitter':
                if ($etat['niveau'] !== self::NIVEAU_CALME) {
                    $decisions[] = self::decision(self::DECISION_INFO, 'Incident acquitté (niveau ' . $etat['niveau'] . ')');
                }
                self::finIncident($etat);
                $etat['recents'] = array();
                break;
        }
        return array($etat, $decisions);
    }

    public static function estArme($_mode) {
        return ($_mode === self::MODE_TOTAL || $_mode === self::MODE_NUIT);
    }

    private static function decision($_genre, $_detail, $_capteur = 0) {
        return array('genre' => $_genre, 'detail' => $_detail, 'capteur' => (int) $_capteur);
    }

    private static function nom($_config, $_id) {
        return isset($_config['capteurs'][$_id]) ? $_config['capteurs'][$_id]['nom'] : ('#' . $_id);
    }

    private static function finIncident(&$_etat) {
        $_etat['niveau'] = self::NIVEAU_CALME;
        $_etat['incident'] = null;
        $_etat['delai_fin'] = 0;
    }

    /*
     * Un défaut (mode illisible, commande disparue…) : écrit à son apparition
     * et à sa disparition, pas à chaque battement du cron qui le constate.
     */
    private static function defaut(&$_etat, $_cle, $_present, $_detail, &$_decisions, $_capteur = 0) {
        $connu = !empty($_etat['defauts'][$_cle]);
        if ($_present === $connu) {
            return;
        }
        if ($_present) {
            $_etat['defauts'][$_cle] = true;
            $_decisions[] = self::decision(self::DECISION_DEFAUT, $_detail, $_capteur);
        } else {
            unset($_etat['defauts'][$_cle]);
            $_decisions[] = self::decision(self::DECISION_INFO, $_detail, $_capteur);
        }
    }

    /*
     * Un capteur retiré de la configuration ne doit pas rester compté parmi
     * les muets ou les exclus : il ne reviendrait jamais pour s'en effacer.
     */
    private static function oublierRetires(&$_etat, $_config) {
        foreach (array('muets', 'exclus', 'recents') as $cle) {
            foreach (array_keys($_etat[$cle]) as $id) {
                if (!isset($_config['capteurs'][(int) $id])) {
                    unset($_etat[$cle][$id]);
                }
            }
        }
        foreach (array_keys($_etat['defauts']) as $cle) {
            if (preg_match('/^(?:disparu|sabotage)-(\d+)$/', (string) $cle, $m) && !isset($_config['capteurs'][(int) $m[1]])) {
                unset($_etat['defauts'][$cle]);
            }
        }
    }

    /*
     * Armement, désarmement, passage d'un mode à l'autre.
     *
     * Un capteur déjà en alerte au moment de l'armement est EXCLU jusqu'à son
     * retour au repos, comme le fait une centrale : sinon une fenêtre restée
     * ouverte ferait sonner l'alarme à l'instant où l'on s'en va, et l'on
     * finirait par ne plus armer. L'exclusion est écrite au journal, parce
     * qu'une fenêtre exclue est une fenêtre qui ne protège rien.
     *
     * Passer de « total » à « nuit » (ou l'inverse) est un nouvel armement :
     * le périmètre change, les exclusions se recalculent, le délai de sortie
     * repart.
     */
    private static function changerMode(&$_etat, $_config, $_evenement, $_t, &$_decisions) {
        $mode = isset($_evenement['mode']) ? (string) $_evenement['mode'] : self::MODE_DESARME;
        if (!in_array($mode, array(self::MODE_DESARME, self::MODE_TOTAL, self::MODE_NUIT), true)) {
            $mode = self::MODE_DESARME;
        }
        self::defaut($_etat, 'mode', false, 'Mode de la centrale de nouveau reconnu', $_decisions);
        if ($mode === $_etat['mode']) {
            return;
        }
        $ancien = $_etat['mode'];
        $_etat['mode'] = $mode;
        $_etat['exclus'] = array();
        $_etat['recents'] = array();

        if (!self::estArme($mode)) {
            if ($_etat['niveau'] !== self::NIVEAU_CALME) {
                $_decisions[] = self::decision(self::DECISION_INFO, 'Désarmé : fin de l\'incident (niveau ' . $_etat['niveau'] . ')');
            } else {
                $_decisions[] = self::decision(self::DECISION_INFO, 'Désarmé');
            }
            self::finIncident($_etat);
            $_etat['arme_depuis'] = 0;
            return;
        }

        if (self::estArme($ancien) && $_etat['niveau'] !== self::NIVEAU_CALME) {
            $_decisions[] = self::decision(self::DECISION_INFO, 'Changement de mode pendant un incident : incident clos');
        }
        self::finIncident($_etat);
        $_etat['arme_depuis'] = $_t;

        $alertes = (isset($_evenement['alertes']) && is_array($_evenement['alertes'])) ? $_evenement['alertes'] : array();
        $exclus = array();
        foreach ($_config['capteurs'] as $id => $capteur) {
            if (!in_array($mode, $capteur['modes'], true)) {
                continue;
            }
            if (!empty($alertes[$id]) && $capteur['role'] !== self::ROLE_EXTERIEUR && $capteur['role'] !== self::ROLE_SABOTAGE) {
                $_etat['exclus'][$id] = true;
                $exclus[] = $capteur['nom'];
            }
        }
        $libelle = ($mode === self::MODE_TOTAL) ? 'Armé (total)' : 'Armé (nuit)';
        if (count($exclus) > 0) {
            $_decisions[] = self::decision(self::DECISION_INFO, $libelle . ' ; exclus car déjà en alerte : ' . implode(', ', $exclus));
        } else {
            $_decisions[] = self::decision(self::DECISION_INFO, $libelle);
        }
    }

    /*
     * Un capteur change d'état. Le rôle décide de la suite :
     *   entree     délai d'entrée, sauf pendant le délai de sortie ;
     *   interieur  suit le délai d'entrée s'il court, sinon alarme ;
     *   perimetre  alarme immédiate ;
     *   centrale   alarme immédiate — la centrale a déjà appliqué ses délais ;
     *   exterieur  suspicion, ou confirmation d'une alarme en cours ;
     *   sabotage   décision de sabotage, sans toucher au niveau.
     */
    private static function capteur(&$_etat, $_config, $_id, $_alerte, $_t, &$_decisions) {
        if (!isset($_config['capteurs'][$_id])) {
            return;
        }
        $capteur = $_config['capteurs'][$_id];

        /* L'autoprotection veille 24 h/24, comme sur une centrale : armée, un
         * sabotage est une décision de sabotage ; désarmée, un défaut. Une
         * décision par épisode, close au retour au repos : une caméra masquée
         * renvoie la même impulsion tant que dure le masquage. */
        if ($capteur['role'] === self::ROLE_SABOTAGE) {
            $cle = 'sabotage-' . $_id;
            $connu = !empty($_etat['defauts'][$cle]);
            if ($_alerte && !$connu) {
                $_etat['defauts'][$cle] = true;
                $actif = self::estArme($_etat['mode']) && in_array($_etat['mode'], $capteur['modes'], true);
                $_decisions[] = $actif
                    ? self::decision(self::DECISION_SABOTAGE, 'Sabotage : ' . $capteur['nom'], $_id)
                    : self::decision(self::DECISION_DEFAUT, 'Sabotage (alarme non armée) : ' . $capteur['nom'], $_id);
            } elseif (!$_alerte && $connu) {
                unset($_etat['defauts'][$cle]);
                $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' : fin du sabotage', $_id);
            }
            return;
        }
        if (!self::estArme($_etat['mode'])) {
            return;
        }

        if (!$_alerte) {
            if (!empty($_etat['exclus'][$_id])) {
                unset($_etat['exclus'][$_id]);
                $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' revenu au repos : de nouveau surveillé', $_id);
            }
            return;
        }
        if (!in_array($_etat['mode'], $capteur['modes'], true)) {
            return;
        }
        if (!empty($_etat['exclus'][$_id])) {
            return;
        }

        /* Pendant le délai de sortie, ce qui accompagne qui s'en va — la
         * porte, le couloir, la caméra qui le voit partir — est ignoré, et
         * surtout pas mémorisé : sinon il « confirmerait » plus tard une
         * alarme à laquelle il n'a pris aucune part. */
        $enSortie = ($_t < $_etat['arme_depuis'] + $_config['delai_sortie']);
        if ($enSortie && in_array($capteur['role'], array(self::ROLE_ENTREE, self::ROLE_INTERIEUR, self::ROLE_EXTERIEUR), true)) {
            if ($capteur['role'] !== self::ROLE_EXTERIEUR) {
                $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' pendant le délai de sortie : ignoré', $_id);
            }
            return;
        }

        /* La mémoire des alertes récentes, tous rôles confondus : c'est elle
         * qui permet de confirmer une alarme par un capteur ayant parlé AVANT
         * elle — la caméra qui a vu quelqu'un, puis la fenêtre qui s'ouvre. */
        foreach ($_etat['recents'] as $autre => $quand) {
            if ($_t - (int) $quand > $_config['fenetre']) {
                unset($_etat['recents'][$autre]);
            }
        }
        $_etat['recents'][$_id] = $_t;

        $enCours = in_array($_etat['niveau'], array(self::NIVEAU_ALARME, self::NIVEAU_CONFIRMEE), true);

        switch ($capteur['role']) {
            case self::ROLE_EXTERIEUR:
                if ($enCours || $_etat['niveau'] === self::NIVEAU_DELAI) {
                    self::confirmer($_etat, $_config, $_id, $_t, $_decisions);
                    return;
                }
                if ($_etat['derniere_suspicion'] > 0 && $_t - $_etat['derniere_suspicion'] < $_config['repos_suspicion']) {
                    return;
                }
                $_etat['derniere_suspicion'] = $_t;
                $_etat['niveau'] = self::NIVEAU_SUSPICION;
                $_decisions[] = self::decision(self::DECISION_SUSPICION, 'Suspicion : ' . $capteur['nom'], $_id);
                return;

            case self::ROLE_ENTREE:
                if ($enCours || $_etat['niveau'] === self::NIVEAU_DELAI) {
                    self::confirmer($_etat, $_config, $_id, $_t, $_decisions);
                    return;
                }
                if ($_config['delai_entree'] <= 0) {
                    self::lever($_etat, $_config, $_id, $_t, $_decisions);
                    return;
                }
                /* Ce qui a parlé juste avant — la caméra qui a vu quelqu'un
                 * s'approcher — fait partie de l'incident : si le délai expire,
                 * l'alarme sera confirmée par elle. */
                $_etat['niveau'] = self::NIVEAU_DELAI;
                $_etat['delai_fin'] = $_t + $_config['delai_entree'];
                $_etat['incident'] = array('debut' => $_t, 'origine' => $_id, 'capteurs' => $_etat['recents']);
                $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' : délai d\'entrée de ' . $_config['delai_entree'] . ' s', $_id);
                return;

            case self::ROLE_INTERIEUR:
                if ($_etat['niveau'] === self::NIVEAU_DELAI) {
                    /* Le chemin normal de qui rentre : on traverse le couloir
                     * pour aller désarmer. Noté pour la confirmation, sans
                     * raccourcir le délai. */
                    $_etat['incident']['capteurs'][$_id] = $_t;
                    $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' suit le délai d\'entrée', $_id);
                    return;
                }
                self::lever($_etat, $_config, $_id, $_t, $_decisions);
                return;

            case self::ROLE_PERIMETRE:
            case self::ROLE_CENTRALE:
                self::lever($_etat, $_config, $_id, $_t, $_decisions);
                return;
        }
    }

    /*
     * Déclenche l'alarme, ou la confirme si elle court déjà.
     *
     * Un délai d'entrée en cours est écourté : une fenêtre qui s'ouvre pendant
     * que la porte d'entrée attend son code n'est pas quelqu'un qui rentre.
     */
    private static function lever(&$_etat, $_config, $_id, $_t, &$_decisions) {
        if (in_array($_etat['niveau'], array(self::NIVEAU_ALARME, self::NIVEAU_CONFIRMEE), true)) {
            self::confirmer($_etat, $_config, $_id, $_t, $_decisions);
            return;
        }
        $capteurs = ($_etat['niveau'] === self::NIVEAU_DELAI && is_array($_etat['incident']) && isset($_etat['incident']['capteurs']))
            ? $_etat['incident']['capteurs'] : array();
        $capteurs[$_id] = $_t;
        $_etat['niveau'] = self::NIVEAU_ALARME;
        $_etat['delai_fin'] = 0;
        $_etat['incident'] = array('debut' => $_t, 'origine' => $_id, 'capteurs' => $capteurs);
        $_decisions[] = self::decision(self::DECISION_ALARME, 'Alarme : ' . self::nom($_config, $_id), $_id);

        /* Un autre capteur a-t-il déjà parlé dans la fenêtre ? */
        foreach ($_etat['recents'] as $autre => $quand) {
            if ((int) $autre !== $_id && $_t - (int) $quand <= $_config['fenetre']) {
                $_etat['incident']['capteurs'][(int) $autre] = (int) $quand;
                self::passerConfirmee($_etat, $_config, (int) $autre, $_decisions);
                return;
            }
        }
    }

    /*
     * Un capteur parle pendant un incident : s'il est différent de ceux qui ont
     * déjà parlé, l'incident est confirmé. Deux capteurs distincts, et non le
     * même deux fois — une porte qui bat n'est pas une confirmation.
     */
    private static function confirmer(&$_etat, $_config, $_id, $_t, &$_decisions) {
        if (!is_array($_etat['incident'])) {
            return;
        }
        $deja = isset($_etat['incident']['capteurs'][$_id]);
        $_etat['incident']['capteurs'][$_id] = $_t;
        if ($deja || $_etat['niveau'] === self::NIVEAU_CONFIRMEE) {
            return;
        }
        if ($_etat['niveau'] === self::NIVEAU_DELAI) {
            /* Pendant le délai d'entrée, un second capteur ne confirme pas
             * encore : il sera compté si le délai expire sans désarmement. */
            $_decisions[] = self::decision(self::DECISION_INFO, self::nom($_config, $_id) . ' pendant le délai d\'entrée', $_id);
            return;
        }
        self::passerConfirmee($_etat, $_config, $_id, $_decisions);
    }

    private static function passerConfirmee(&$_etat, $_config, $_id, &$_decisions) {
        $_etat['niveau'] = self::NIVEAU_CONFIRMEE;
        $noms = array();
        foreach (array_keys($_etat['incident']['capteurs']) as $id) {
            $noms[] = self::nom($_config, $id);
        }
        $_decisions[] = self::decision(self::DECISION_CONFIRMEE, 'Alarme confirmée : ' . implode(' + ', $noms), $_id);
    }

    /*
     * Le passage du temps : fin du délai d'entrée sans désarmement, fin d'une
     * suspicion restée seule.
     */
    private static function tick(&$_etat, $_config, $_t, &$_decisions) {
        if (!self::estArme($_etat['mode'])) {
            return;
        }
        if ($_etat['niveau'] === self::NIVEAU_DELAI && $_etat['delai_fin'] > 0 && $_t >= $_etat['delai_fin']) {
            $origine = (is_array($_etat['incident']) && isset($_etat['incident']['origine'])) ? (int) $_etat['incident']['origine'] : 0;
            $capteurs = (is_array($_etat['incident']) && isset($_etat['incident']['capteurs'])) ? $_etat['incident']['capteurs'] : array();
            $_etat['niveau'] = self::NIVEAU_ALARME;
            $_etat['delai_fin'] = 0;
            $_decisions[] = self::decision(self::DECISION_ALARME, 'Délai d\'entrée écoulé sans désarmement : ' . self::nom($_config, $origine), $origine);
            if (count($capteurs) > 1) {
                self::passerConfirmee($_etat, $_config, $origine, $_decisions);
            }
            return;
        }
        if ($_etat['niveau'] === self::NIVEAU_SUSPICION && $_t - $_etat['derniere_suspicion'] > $_config['fenetre']) {
            $_etat['niveau'] = self::NIVEAU_CALME;
            $_decisions[] = self::decision(self::DECISION_INFO, 'Suspicion retombée sans autre détection');
        }
    }

    /*
     * Supervision. Un capteur muet alarme armée est un sabotage possible —
     * quelqu'un qui brouille ou arrache ne fait pas sonner un contact, il le
     * fait taire. Désarmé, ce n'est qu'un défaut à réparer.
     *
     * Une décision par épisode : le cron reposera la même question chaque
     * minute, la réponse n'est écrite qu'au changement.
     */
    private static function muet(&$_etat, $_config, $_id, $_muet, &$_decisions) {
        if (!isset($_config['capteurs'][$_id])) {
            return;
        }
        $nom = $_config['capteurs'][$_id]['nom'];
        $connu = !empty($_etat['muets'][$_id]);
        if ($_muet === $connu) {
            return;
        }
        if ($_muet) {
            $_etat['muets'][$_id] = true;
            $actif = self::estArme($_etat['mode']) && in_array($_etat['mode'], $_config['capteurs'][$_id]['modes'], true);
            $_decisions[] = $actif
                ? self::decision(self::DECISION_SABOTAGE, 'Capteur muet alarme armée : ' . $nom, $_id)
                : self::decision(self::DECISION_DEFAUT, 'Capteur muet : ' . $nom, $_id);
        } else {
            unset($_etat['muets'][$_id]);
            $_decisions[] = self::decision(self::DECISION_INFO, $nom . ' de nouveau joignable', $_id);
        }
    }
}
