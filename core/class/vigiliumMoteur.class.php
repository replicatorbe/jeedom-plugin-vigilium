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
 *
 * Le rôle « centrale » — l'alarme déclarée par la centrale elle-même — ne
 * confirme jamais rien : c'est d'ordinaire l'écho du contact qui vient de
 * s'ouvrir, et compter deux fois le même événement physique viderait la
 * confirmation de son sens.
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
    const ANNULATION_DEFAUT = 120;

    public static function roles() {
        return array(self::ROLE_ENTREE, self::ROLE_PERIMETRE, self::ROLE_INTERIEUR,
                     self::ROLE_EXTERIEUR, self::ROLE_CENTRALE, self::ROLE_SABOTAGE);
    }


    public static function etatInitial() {
        return array(
            'mode' => self::MODE_DESARME,
            'arme_depuis' => 0,
            /* Vrai une fois la fin du délai de sortie traitée : ce qui est
             * resté ouvert à cet instant doit alors être évalué. */
            'sortie_close' => true,
            'niveau' => self::NIVEAU_CALME,
            'incident' => null,
            'delai_fin' => 0,
            'exclus' => array(),
            'recents' => array(),
            'derniere_detection_ext' => 0,
            'muets' => array(),
            'defauts' => array(),
            /* Le dernier état connu de chaque capteur, alerte ou repos, tenu
             * même désarmé : la fin du délai de sortie et le passage d'un mode
             * à l'autre en ont besoin, et aucun événement ne le redonnera. */
            'actifs' => array(),
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
        $borne = function ($_cle, $_defaut, $_min, $_max) use ($c) {
            $valeur = isset($c[$_cle]) ? $c[$_cle] : null;
            if ($valeur === null || $valeur === '' || !is_numeric($valeur)) {
                return $_defaut;
            }
            return max($_min, min($_max, (int) $valeur));
        };
        $sortie = $borne('delai_sortie', self::DELAI_SORTIE_DEFAUT, 0, 900);
        $config = array(
            'delai_entree' => $borne('delai_entree', self::DELAI_ENTREE_DEFAUT, 0, 600),
            'delai_sortie' => $sortie,
            /* Le mode nuit s'arme d'ordinaire tout seul, maison occupée : sans
             * réglage propre, il hérite du délai de sortie du mode total. */
            'delai_sortie_nuit' => $borne('delai_sortie_nuit', $sortie, 0, 900),
            'fenetre' => $borne('fenetre', self::FENETRE_DEFAUT, 30, 3600),
            'repos_suspicion' => $borne('repos_suspicion', self::REPOS_SUSPICION_DEFAUT, 0, 3600),
            'annulation' => $borne('annulation', self::ANNULATION_DEFAUT, 0, 900),
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
     *   array('type' => 'mode', 'mode' => …, 'alertes' => array(id => bool),
     *         'depuis' => array(id => horodatage))
     *        — 'alertes' : l'état de chaque capteur au moment de l'armement ;
     *          'depuis' : depuis quand il est dans cet état, pour le dire ;
     *   array('type' => 'mode_inconnu', 'valeur' => …)
     *   array('type' => 'capteur', 'id' => …, 'alerte' => bool)
     *   array('type' => 'muet', 'id' => …, 'muet' => bool)
     *   array('type' => 'disparu', 'id' => …, 'disparu' => bool)
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
                /* Pas de passage du temps avant un changement de mode : un
                 * désarmement arrivé juste après la fin du délai d'entrée, mais
                 * avant le battement qui l'aurait constatée, est un retour
                 * normal et non une alarme. */
                self::changerMode($etat, $config, $_evenement, $t, $decisions);
                break;
            case 'mode_inconnu':
                $valeur = isset($_evenement['valeur']) ? (string) $_evenement['valeur'] : '';
                self::defaut($etat, 'mode', true, 'Mode de la centrale inconnu (« ' . $valeur . ' ») : le mode précédent est conservé', $decisions);
                break;
            case 'capteur':
                /* Le temps d'abord : un délai d'entrée échu entre deux
                 * battements du cron doit être constaté AVANT de classer ce
                 * capteur, sinon un couloir traversé après la fin du délai
                 * passerait pour « suit le délai d'entrée ». Mais l'état du
                 * capteur AVANT le temps : une porte refermée juste après la
                 * fin du délai de sortie ne doit pas être vue encore ouverte
                 * par la reprise de fin de sortie. */
                $id = (int) $_evenement['id'];
                if (isset($config['capteurs'][$id]) && empty($_evenement['alerte'])) {
                    $etat['actifs'][$id] = false;
                }
                self::tick($etat, $config, $t, $decisions);
                self::capteur($etat, $config, (int) $_evenement['id'], !empty($_evenement['alerte']), $t, $decisions);
                break;
            case 'muet':
                self::muet($etat, $config, (int) $_evenement['id'], !empty($_evenement['muet']), $decisions);
                break;
            case 'disparu':
                $id = (int) $_evenement['id'];
                self::defaut($etat, 'disparu-' . $id, !empty($_evenement['disparu']),
                    !empty($_evenement['disparu'])
                        ? 'Commande introuvable pour ' . self::nom($config, $id) . ' : ce capteur n\'est plus surveillé'
                        : 'Commande de ' . self::nom($config, $id) . ' retrouvée', $decisions, $id);
                break;
            case 'tick':
                self::tick($etat, $config, $t, $decisions);
                break;
            case 'acquitter':
                self::acquitter($etat, $decisions);
                break;
        }
        return array($etat, $decisions);
    }

    public static function estArme($_mode) {
        return ($_mode === self::MODE_TOTAL || $_mode === self::MODE_NUIT);
    }

    public static function libelleNiveau($_niveau) {
        $libelles = array(
            self::NIVEAU_CALME => 'calme',
            self::NIVEAU_SUSPICION => 'suspicion',
            self::NIVEAU_DELAI => 'délai d\'entrée',
            self::NIVEAU_ALARME => 'alarme',
            self::NIVEAU_CONFIRMEE => 'alarme confirmée',
        );
        return isset($libelles[$_niveau]) ? $libelles[$_niveau] : $_niveau;
    }

    private static function libelleMode($_mode) {
        $libelles = array(self::MODE_DESARME => 'désarmé', self::MODE_TOTAL => 'total', self::MODE_NUIT => 'nuit');
        return isset($libelles[$_mode]) ? $libelles[$_mode] : $_mode;
    }

    private static function decision($_genre, $_detail, $_capteur = 0) {
        return array('genre' => $_genre, 'detail' => $_detail, 'capteur' => (int) $_capteur);
    }

    private static function nom($_config, $_id) {
        return isset($_config['capteurs'][$_id]) ? $_config['capteurs'][$_id]['nom'] : ('#' . $_id);
    }

    private static function role($_config, $_id) {
        return isset($_config['capteurs'][$_id]) ? $_config['capteurs'][$_id]['role'] : '';
    }

    private static function actifDans($_config, $_id, $_mode) {
        return isset($_config['capteurs'][$_id]) && in_array($_mode, $_config['capteurs'][$_id]['modes'], true);
    }

    private static function delaiSortie($_config, $_mode) {
        return ($_mode === self::MODE_NUIT) ? $_config['delai_sortie_nuit'] : $_config['delai_sortie'];
    }

    /* Vrai pendant le délai de sortie. Une horloge revenue en arrière (avant
     * l'armement) ne prolonge pas le délai : elle le termine. */
    private static function enSortie($_etat, $_config, $_t) {
        return ($_t >= $_etat['arme_depuis'] && $_t < $_etat['arme_depuis'] + self::delaiSortie($_config, $_etat['mode']));
    }

    private static function finIncident(&$_etat) {
        $_etat['niveau'] = self::NIVEAU_CALME;
        $_etat['incident'] = null;
        $_etat['delai_fin'] = 0;
    }

    private static function date($_t) {
        return date('d/m H:i', (int) $_t);
    }

    /*
     * Un défaut (mode illisible, commande disparue, sabotage…) : écrit à son
     * apparition et à sa disparition, pas à chaque battement qui le constate.
     */
    private static function defaut(&$_etat, $_cle, $_present, $_detail, &$_decisions, $_capteur = 0, $_genre = self::DECISION_DEFAUT) {
        $connu = !empty($_etat['defauts'][$_cle]);
        if ($_present === $connu) {
            return;
        }
        if ($_present) {
            $_etat['defauts'][$_cle] = true;
            $_decisions[] = self::decision($_genre, $_detail, $_capteur);
        } else {
            unset($_etat['defauts'][$_cle]);
            $_decisions[] = self::decision(self::DECISION_INFO, $_detail, $_capteur);
        }
    }

    /*
     * Un capteur retiré de la configuration ne doit pas rester compté parmi
     * les muets, les exclus ou les défauts : il ne reviendrait jamais pour
     * s'en effacer.
     */
    private static function oublierRetires(&$_etat, $_config) {
        foreach (array('muets', 'exclus', 'recents', 'actifs') as $cle) {
            foreach (array_keys($_etat[$cle]) as $id) {
                if (!isset($_config['capteurs'][(int) $id])) {
                    unset($_etat[$cle][$id]);
                }
            }
        }
        foreach (array_keys($_etat['defauts']) as $cle) {
            if (preg_match('/^(?:disparu|sabotage|centrale)-(\d+)$/', (string) $cle, $m) && !isset($_config['capteurs'][(int) $m[1]])) {
                unset($_etat['defauts'][$cle]);
            }
        }
    }

    /*
     * Armement, désarmement, passage d'un mode armé à l'autre.
     */
    private static function changerMode(&$_etat, $_config, $_evenement, $_t, &$_decisions) {
        $mode = isset($_evenement['mode']) ? (string) $_evenement['mode'] : self::MODE_DESARME;
        if (!in_array($mode, array(self::MODE_DESARME, self::MODE_TOTAL, self::MODE_NUIT), true)) {
            $mode = self::MODE_DESARME;
        }
        self::defaut($_etat, 'mode', false, 'Mode de la centrale de nouveau reconnu', $_decisions);
        $alertes = (isset($_evenement['alertes']) && is_array($_evenement['alertes'])) ? $_evenement['alertes'] : array();
        foreach ($alertes as $id => $alerte) {
            if (isset($_config['capteurs'][(int) $id])) {
                $_etat['actifs'][(int) $id] = (bool) $alerte;
            }
        }
        if ($mode === $_etat['mode']) {
            return;
        }
        $ancien = $_etat['mode'];
        $_etat['mode'] = $mode;

        if (!self::estArme($mode)) {
            self::desarmer($_etat, $_config, $_t, $_decisions);
            return;
        }

        $depuis = (isset($_evenement['depuis']) && is_array($_evenement['depuis'])) ? $_evenement['depuis'] : array();

        /* D'un mode armé à l'autre — le mode nuit automatique qui tombe sur une
         * maison armée en total : l'incident en cours est GARDÉ, le délai de
         * sortie ne repart pas, et seuls les capteurs que l'ancien mode ne
         * surveillait pas peuvent être exclus. Sinon le changement de mode
         * effacerait l'intrusion et exclurait la fenêtre forcée. */
        if (self::estArme($ancien)) {
            foreach (array_keys($_etat['exclus']) as $id) {
                if (!self::actifDans($_config, (int) $id, $mode)) {
                    unset($_etat['exclus'][$id]);
                }
            }
            $exclus = self::exclure($_etat, $_config, $mode, $alertes, $depuis, $ancien);
            $detail = 'Passage du mode ' . self::libelleMode($ancien) . ' au mode ' . self::libelleMode($mode);
            if ($_etat['niveau'] !== self::NIVEAU_CALME) {
                $detail .= ' ; incident en cours conservé (' . self::libelleNiveau($_etat['niveau']) . ')';
            }
            if (count($exclus) > 0) {
                $detail .= ' ; exclus car déjà en alerte : ' . implode(', ', $exclus);
            }
            $_decisions[] = self::decision(self::DECISION_INFO, $detail);
            self::signalerDefautsArmement($_etat, $_config, $mode, $_decisions);
            return;
        }

        self::finIncident($_etat);
        $_etat['recents'] = array();
        $_etat['exclus'] = array();
        $_etat['arme_depuis'] = $_t;
        $_etat['sortie_close'] = (self::delaiSortie($_config, $mode) <= 0);
        $exclus = self::exclure($_etat, $_config, $mode, $alertes, $depuis, null);
        $detail = 'Armé (' . self::libelleMode($mode) . ')';
        if (count($exclus) > 0) {
            $detail .= ' ; exclus car déjà en alerte : ' . implode(', ', $exclus);
        }
        $_decisions[] = self::decision(self::DECISION_INFO, $detail);
        self::signalerDefautsArmement($_etat, $_config, $mode, $_decisions);
    }

    /*
     * Exclut les capteurs déjà en alerte, comme le fait une centrale : sinon
     * une fenêtre restée ouverte ferait sonner l'alarme à l'instant où l'on
     * s'en va. Jamais l'extérieur (une caméra n'est pas « ouverte »), jamais
     * le sabotage, jamais la centrale : exclure l'alarme de la centrale parce
     * qu'elle est restée à 1 rendrait ses alarmes suivantes invisibles.
     *
     * $_ancien : le mode armé précédent, dont les capteurs surveillés et
     * membres de l'incident ne sont pas exclus. Rend les noms, avec la date
     * quand l'alerte est ancienne — une fenêtre « ouverte » depuis un mois est
     * un capteur à réparer, pas une fenêtre.
     */
    private static function exclure(&$_etat, $_config, $_mode, $_alertes, $_depuis, $_ancien) {
        $noms = array();
        foreach ($_config['capteurs'] as $id => $capteur) {
            if (!in_array($_mode, $capteur['modes'], true) || empty($_alertes[$id]) || !empty($_etat['exclus'][$id])) {
                continue;
            }
            if (in_array($capteur['role'], array(self::ROLE_EXTERIEUR, self::ROLE_SABOTAGE, self::ROLE_CENTRALE), true)) {
                continue;
            }
            if ($_ancien !== null && in_array($_ancien, $capteur['modes'], true)) {
                continue;
            }
            $_etat['exclus'][$id] = true;
            $nom = $capteur['nom'];
            if (isset($_depuis[$id]) && (int) $_depuis[$id] > 0) {
                $nom .= ' (depuis le ' . self::date($_depuis[$id]) . ')';
            }
            $noms[] = $nom;
        }
        return $noms;
    }

    /*
     * Ce qui était déjà en défaut avant l'armement — sabotage en cours,
     * capteur muet — ne s'est signalé qu'une fois, alarme désarmée, comme un
     * simple défaut. L'armement le rappelle comme un sabotage : la maison
     * s'arme avec un trou, et c'est maintenant qu'il compte.
     */
    private static function signalerDefautsArmement($_etat, $_config, $_mode, &$_decisions) {
        $noms = array();
        foreach ($_config['capteurs'] as $id => $capteur) {
            if (!in_array($_mode, $capteur['modes'], true)) {
                continue;
            }
            if (!empty($_etat['muets'][$id])) {
                $noms[] = $capteur['nom'] . ' (muet)';
            }
            if (!empty($_etat['defauts']['sabotage-' . $id])) {
                $noms[] = $capteur['nom'] . ' (sabotage en cours)';
            }
            if (!empty($_etat['defauts']['disparu-' . $id])) {
                $noms[] = $capteur['nom'] . ' (commande introuvable)';
            }
        }
        if (count($noms) > 0) {
            $_decisions[] = self::decision(self::DECISION_SABOTAGE, 'Armé malgré : ' . implode(', ', $noms));
        }
    }

    /*
     * Désarmement. Le journal dit en clair ce qu'il clôt : un désarmement
     * pendant le délai d'entrée est un retour normal, un désarmement juste
     * après une alarme née de ce délai en est très probablement un aussi (la
     * centrale ou la balise a été plus lente que le délai).
     */
    private static function desarmer(&$_etat, $_config, $_t, &$_decisions) {
        $incident = is_array($_etat['incident']) ? $_etat['incident'] : array();
        $origine = isset($incident['origine']) ? (int) $incident['origine'] : 0;
        switch ($_etat['niveau']) {
            case self::NIVEAU_DELAI:
                $ecart = $_t - (int) (isset($incident['debut']) ? $incident['debut'] : $_t);
                $detail = 'Désarmé ' . $ecart . ' s après l\'ouverture de ' . self::nom($_config, $origine) . ' : retour normal';
                break;
            case self::NIVEAU_ALARME:
            case self::NIVEAU_CONFIRMEE:
                $ecart = $_t - (int) (isset($incident['alarme_t']) ? $incident['alarme_t'] : $_t);
                if (!empty($incident['via_delai']) && $ecart <= $_config['annulation']) {
                    $detail = 'Désarmé ' . $ecart . ' s après la fin du délai d\'entrée (' . self::nom($_config, $origine)
                            . ') : alarme annulée, très probablement un retour normal';
                } else {
                    $detail = 'Désarmé : fin de l\'incident (' . self::libelleNiveau($_etat['niveau']) . ', ' . self::nom($_config, $origine) . ')';
                }
                break;
            default:
                $detail = 'Désarmé';
        }
        $_decisions[] = self::decision(self::DECISION_INFO, $detail, $origine);
        self::finIncident($_etat);
        $_etat['arme_depuis'] = 0;
        $_etat['sortie_close'] = true;
        $_etat['exclus'] = array();
        $_etat['recents'] = array();
    }

    private static function acquitter(&$_etat, &$_decisions) {
        /* Pendant le délai d'entrée, acquitter reviendrait à annuler une
         * intrusion possible sans désarmer : refusé. Seule la centrale y met
         * fin, en désarmant. */
        if ($_etat['niveau'] === self::NIVEAU_DELAI) {
            $_decisions[] = self::decision(self::DECISION_INFO, 'Acquittement refusé pendant le délai d\'entrée : seul le désarmement y met fin');
            return;
        }
        if ($_etat['niveau'] !== self::NIVEAU_CALME) {
            $_decisions[] = self::decision(self::DECISION_INFO, 'Incident acquitté (' . self::libelleNiveau($_etat['niveau']) . ')');
        }
        self::finIncident($_etat);
        $_etat['recents'] = array();
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
        $_etat['actifs'][$_id] = $_alerte;
        $arme = self::estArme($_etat['mode']);
        $actif = $arme && in_array($_etat['mode'], $capteur['modes'], true);

        /* L'autoprotection veille 24 h/24, comme sur une centrale : armée, un
         * sabotage est une décision de sabotage ; désarmée, un défaut. Une
         * décision par épisode, close au retour au repos : une caméra masquée
         * renvoie la même impulsion tant que dure le masquage. */
        if ($capteur['role'] === self::ROLE_SABOTAGE) {
            self::defaut($_etat, 'sabotage-' . $_id, $_alerte,
                $_alerte ? ($actif ? 'Sabotage : ' : 'Sabotage, alarme non armée : ') . $capteur['nom'] : $capteur['nom'] . ' : fin du sabotage',
                $_decisions, $_id, $actif ? self::DECISION_SABOTAGE : self::DECISION_DEFAUT);
            return;
        }

        /* L'alarme de la centrale alors que Vigilium ne la surveille pas —
         * désarmé : incendie, panique, désarmement sous contrainte. Elle ne
         * fait pas monter le niveau (rien n'est armé), mais elle s'écrit. */
        if ($capteur['role'] === self::ROLE_CENTRALE && !$_alerte && !empty($_etat['defauts']['centrale-' . $_id])) {
            self::defaut($_etat, 'centrale-' . $_id, false, $capteur['nom'] . ' : retour au repos', $_decisions, $_id);
        }
        if ($capteur['role'] === self::ROLE_CENTRALE && !$actif) {
            self::defaut($_etat, 'centrale-' . $_id, $_alerte,
                $_alerte ? 'Alarme de la centrale alors que l\'alarme n\'est pas armée (incendie, panique, contrainte ?) : ' . $capteur['nom']
                         : $capteur['nom'] . ' : retour au repos',
                $_decisions, $_id, self::DECISION_ALARME);
            return;
        }
        if (!$arme) {
            return;
        }

        if (!$_alerte) {
            if (!empty($_etat['exclus'][$_id])) {
                unset($_etat['exclus'][$_id]);
                $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' revenu au repos : de nouveau surveillé', $_id);
            }
            return;
        }
        if (!$actif || !empty($_etat['exclus'][$_id])) {
            return;
        }

        /* Pendant le délai de sortie, ce qui accompagne qui s'en va — la
         * porte, le couloir, la caméra qui le voit partir — est ignoré, et
         * surtout pas mémorisé : sinon il « confirmerait » plus tard une
         * alarme à laquelle il n'a pris aucune part. Ce qui reste ouvert à la
         * fin du délai est repris par tick(). */
        if (self::enSortie($_etat, $_config, $_t)
            && in_array($capteur['role'], array(self::ROLE_ENTREE, self::ROLE_INTERIEUR, self::ROLE_EXTERIEUR), true)) {
            if ($capteur['role'] !== self::ROLE_EXTERIEUR) {
                $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' pendant le délai de sortie : ignoré', $_id);
            }
            return;
        }

        self::memoriser($_etat, $_config, $_id, $_t);
        $enCours = in_array($_etat['niveau'], array(self::NIVEAU_ALARME, self::NIVEAU_CONFIRMEE), true);

        switch ($capteur['role']) {
            case self::ROLE_EXTERIEUR:
                if ($enCours || $_etat['niveau'] === self::NIVEAU_DELAI) {
                    self::confirmer($_etat, $_config, $_id, $_t, $_decisions);
                    return;
                }
                /* Repos glissant : tant que la caméra continue de voir du
                 * mouvement — le chat qui traverse toutes les minutes — une
                 * seule suspicion. Elle ne se renouvelle qu'après un silence
                 * aussi long que le repos. */
                $silence = ($_etat['derniere_detection_ext'] > 0) ? $_t - $_etat['derniere_detection_ext'] : PHP_INT_MAX;
                $_etat['derniere_detection_ext'] = $_t;
                if ($silence < $_config['repos_suspicion']) {
                    return;
                }
                $_etat['niveau'] = self::NIVEAU_SUSPICION;
                $_decisions[] = self::decision(self::DECISION_SUSPICION, 'Suspicion : ' . $capteur['nom'], $_id);
                return;

            case self::ROLE_ENTREE:
                if ($enCours || $_etat['niveau'] === self::NIVEAU_DELAI) {
                    self::confirmer($_etat, $_config, $_id, $_t, $_decisions);
                    return;
                }
                self::ouvrirDelai($_etat, $_config, $_id, $_t, $_decisions);
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

    /* La mémoire des alertes récentes : c'est elle qui permet de confirmer une
     * alarme par un capteur ayant parlé AVANT elle — la caméra qui a vu
     * quelqu'un, puis la fenêtre qui s'ouvre. La centrale n'y entre pas. Une
     * date « dans le futur » (horloge revenue en arrière) est oubliée. */
    private static function memoriser(&$_etat, $_config, $_id, $_t) {
        foreach ($_etat['recents'] as $autre => $quand) {
            if ($_t - (int) $quand > $_config['fenetre'] || (int) $quand > $_t) {
                unset($_etat['recents'][$autre]);
            }
        }
        if (self::role($_config, $_id) !== self::ROLE_CENTRALE) {
            $_etat['recents'][$_id] = $_t;
        }
    }

    private static function ouvrirDelai(&$_etat, $_config, $_id, $_t, &$_decisions) {
        if ($_config['delai_entree'] <= 0) {
            self::lever($_etat, $_config, $_id, $_t, $_decisions);
            return;
        }
        /* Ce qui a parlé juste avant — la caméra qui a vu quelqu'un
         * s'approcher — fait partie de l'incident : si le délai expire,
         * l'alarme sera confirmée par elle. */
        $capteurs = $_etat['recents'];
        $capteurs[$_id] = $_t;
        $_etat['niveau'] = self::NIVEAU_DELAI;
        $_etat['delai_fin'] = $_t + $_config['delai_entree'];
        $_etat['incident'] = array('debut' => $_t, 'origine' => $_id, 'capteurs' => $capteurs);
        $_decisions[] = self::decision(self::DECISION_INFO, self::nom($_config, $_id) . ' : délai d\'entrée de ' . $_config['delai_entree'] . ' s', $_id);
    }

    /* Les capteurs distincts d'un incident qui comptent pour la confirmation :
     * tous, sauf la centrale. */
    private static function independants($_etat, $_config) {
        $ids = array();
        if (is_array($_etat['incident']) && isset($_etat['incident']['capteurs'])) {
            foreach (array_keys($_etat['incident']['capteurs']) as $id) {
                if (self::role($_config, (int) $id) !== self::ROLE_CENTRALE) {
                    $ids[] = (int) $id;
                }
            }
        }
        return $ids;
    }

    /*
     * Déclenche l'alarme, ou la confirme si elle court déjà.
     *
     * Un délai d'entrée en cours est écourté : une fenêtre qui s'ouvre pendant
     * que la porte d'entrée attend son code n'est pas quelqu'un qui rentre.
     */
    private static function lever(&$_etat, $_config, $_id, $_t, &$_decisions, $_viaDelai = false) {
        if (in_array($_etat['niveau'], array(self::NIVEAU_ALARME, self::NIVEAU_CONFIRMEE), true)) {
            self::confirmer($_etat, $_config, $_id, $_t, $_decisions);
            return;
        }
        $capteurs = ($_etat['niveau'] === self::NIVEAU_DELAI && is_array($_etat['incident']) && isset($_etat['incident']['capteurs']))
            ? $_etat['incident']['capteurs'] : array();
        foreach ($_etat['recents'] as $autre => $quand) {
            $capteurs[(int) $autre] = (int) $quand;
        }
        $capteurs[$_id] = $_t;
        $origine = ($_viaDelai && is_array($_etat['incident'])) ? (int) $_etat['incident']['origine'] : $_id;
        $_etat['niveau'] = self::NIVEAU_ALARME;
        $_etat['delai_fin'] = 0;
        $_etat['incident'] = array('debut' => $_t, 'origine' => $origine, 'capteurs' => $capteurs,
                                   'via_delai' => $_viaDelai, 'alarme_t' => $_t);
        $_decisions[] = self::decision(self::DECISION_ALARME,
            ($_viaDelai ? 'Délai d\'entrée écoulé sans désarmement : ' : 'Alarme : ') . self::nom($_config, $origine), $origine);
        if (count(self::independants($_etat, $_config)) >= 2) {
            self::passerConfirmee($_etat, $_config, $_id, $_decisions);
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
        if (count(self::independants($_etat, $_config)) >= 2) {
            self::passerConfirmee($_etat, $_config, $_id, $_decisions);
        }
    }

    private static function passerConfirmee(&$_etat, $_config, $_id, &$_decisions) {
        $_etat['niveau'] = self::NIVEAU_CONFIRMEE;
        $noms = array();
        foreach (self::independants($_etat, $_config) as $id) {
            $noms[] = self::nom($_config, $id);
        }
        $_decisions[] = self::decision(self::DECISION_CONFIRMEE, 'Alarme confirmée : ' . implode(' + ', $noms), $_id);
    }

    /*
     * Le passage du temps : fin du délai de sortie (ce qui est resté ouvert
     * compte alors), fin du délai d'entrée sans désarmement, fin d'une
     * suspicion restée seule.
     */
    private static function tick(&$_etat, $_config, $_t, &$_decisions) {
        if (!self::estArme($_etat['mode'])) {
            return;
        }
        if (!$_etat['sortie_close'] && !self::enSortie($_etat, $_config, $_t)) {
            $_etat['sortie_close'] = true;
            /* Une porte ouverte pendant le délai de sortie et jamais refermée
             * ne renverra aucun événement : c'est maintenant qu'elle compte,
             * comme si elle venait de s'ouvrir. */
            foreach ($_config['capteurs'] as $id => $capteur) {
                if (empty($_etat['actifs'][$id]) || !empty($_etat['exclus'][$id]) || !in_array($_etat['mode'], $capteur['modes'], true)) {
                    continue;
                }
                if ($capteur['role'] === self::ROLE_ENTREE && in_array($_etat['niveau'], array(self::NIVEAU_CALME, self::NIVEAU_SUSPICION), true)) {
                    $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' toujours ouvert à la fin du délai de sortie', $id);
                    self::memoriser($_etat, $_config, $id, $_t);
                    self::ouvrirDelai($_etat, $_config, $id, $_t, $_decisions);
                } elseif ($capteur['role'] === self::ROLE_INTERIEUR && $_etat['niveau'] !== self::NIVEAU_DELAI) {
                    $_decisions[] = self::decision(self::DECISION_INFO, $capteur['nom'] . ' toujours en alerte à la fin du délai de sortie', $id);
                    self::memoriser($_etat, $_config, $id, $_t);
                    self::lever($_etat, $_config, $id, $_t, $_decisions);
                }
            }
        }
        if ($_etat['niveau'] === self::NIVEAU_DELAI && $_etat['delai_fin'] > 0 && $_t >= $_etat['delai_fin']) {
            self::lever($_etat, $_config, (int) $_etat['incident']['origine'], $_t, $_decisions, true);
            return;
        }
        if ($_etat['niveau'] === self::NIVEAU_SUSPICION && $_t - $_etat['derniere_detection_ext'] > $_config['fenetre']) {
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
                ? self::decision(self::DECISION_SABOTAGE, 'Capteur muet alors que l\'alarme est armée : ' . $nom, $_id)
                : self::decision(self::DECISION_DEFAUT, 'Capteur muet : ' . $nom, $_id);
        } else {
            unset($_etat['muets'][$_id]);
            $_decisions[] = self::decision(self::DECISION_INFO, $nom . ' de nouveau joignable', $_id);
        }
    }
}
