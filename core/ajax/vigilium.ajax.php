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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    require_once __DIR__ . '/../class/vigilium.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* Un identifiant venu du navigateur n'est utilisé qu'après avoir vérifié
     * qu'il désigne bien une alarme Vigilium. */
    $getEqLogic = function ($_id) {
        $eqLogic = vigilium::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'vigilium') {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        return $eqLogic;
    };

    if (init('action') == 'journal') {
        $eqLogic = $getEqLogic(init('id'));
        ajax::success(array(
            'entrees' => $eqLogic->journalLire((int) init('nombre', 200)),
            'niveau' => vigilium::libelleNiveau($eqLogic->lireEtat()['niveau']),
        ));
    }

    /* Les valeurs actuelles des commandes désignées dans la page, pour qu'on
     * puisse vérifier le sens de la « valeur d'alerte ». Uniquement des
     * informations : lire une action reviendrait à l'exécuter. */
    if (init('action') == 'valeurs') {
        $valeurs = array();
        foreach (explode(',', (string) init('ids')) as $id) {
            $cmd = cmd::byId((int) $id);
            if (is_object($cmd) && $cmd->getType() === 'info') {
                $valeurs[(int) $id] = $cmd->execCmd();
            }
        }
        ajax::success($valeurs);
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
