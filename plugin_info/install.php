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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait plus sa classe. */
require_once __DIR__ . '/../core/class/vigilium.class.php';

function vigilium_install() {
}

/*
 * Les équipements existants reçoivent les commandes ajoutées depuis, et leur
 * écouteur est reposé : listener::clean() peut l'avoir emporté, et une alarme
 * sans écouteur ne voit plus rien qu'une fois par minute.
 */
function vigilium_update() {
    foreach (eqLogic::byType('vigilium') as $eqLogic) {
        try {
            $eqLogic->createCommands();
            $eqLogic->syncListener();
        } catch (Throwable $e) {
            log::add('vigilium', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function vigilium_remove() {
    foreach (listener::byClass('vigilium') as $listener) {
        try {
            $listener->remove();
        } catch (Throwable $e) {
            log::add('vigilium', 'debug', __('Écouteur non retiré :', __FILE__) . ' ' . $e->getMessage());
        }
    }
    /* Le journal d'une alarme raconte quand la maison est vide : il ne reste
     * pas sur le disque après la désinstallation. Seuls les fichiers que le
     * plugin écrit sont touchés. */
    try {
        $dossier = vigilium::dossierDonnees();
        foreach (ls($dossier, '*', false, array('files', 'quiet')) as $fichier) {
            if (preg_match('/^(?:journal|etat|sorties)-\d+\./', $fichier)) {
                @unlink($dossier . '/' . $fichier);
            }
        }
    } catch (Throwable $e) {
        log::add('vigilium', 'debug', __('Fichiers non supprimés :', __FILE__) . ' ' . $e->getMessage());
    }
}
