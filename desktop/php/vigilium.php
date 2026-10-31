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

if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('vigilium');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
    <div class="col-xs-12">
        <div class="alert alert-info" style="margin:5px;">
            <i class="fas fa-eye"></i> <b>{{Version d'observation : aucune sortie n'est déclenchée.}}</b>
            {{L'alarme suit la centrale, évalue chaque capteur et écrit au journal ce qu'elle aurait fait — suspicion, alarme, alarme confirmée, sabotage. Les sirènes, notifications et lumières viendront quand ce journal aura montré qu'on peut lui faire confiance.}}
        </div>
    </div>

    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor eqLogicAction logoPrimary" data-action="add">
                <i class="fas fa-plus-circle"></i>
                <br>
                <span>{{Ajouter une alarme}}</span>
            </div>
        </div>
        <legend><i class="fas fa-shield-alt"></i> {{Mes alarmes}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<div class="alert alert-info" style="margin:5px;">{{Aucune alarme. Cliquez sur « Ajouter une alarme », désignez la commande qui donne le mode de votre centrale, puis ajoutez vos capteurs avec leur rôle.}}</div>';
        }
        ?>
        <div class="eqLogicThumbnailContainer">
            <?php
            foreach ($eqLogics as $eqLogic) {
                $cmdNiveau = $eqLogic->getCmd('info', 'niveau');
                $niveau = is_object($cmdNiveau) ? (string) $cmdNiveau->execCmd() : '';
                /* La couleur suit la gravité : une alarme ne doit pas avoir
                   l'air d'une information sur la liste des équipements. */
                $couleurs = array(__('Calme', __FILE__) => 'label-success', __('Suspicion', __FILE__) => 'label-warning',
                                  __('Délai d\'entrée', __FILE__) => 'label-warning', __('Alarme', __FILE__) => 'label-danger',
                                  __('Alarme confirmée', __FILE__) => 'label-danger');
                $couleur = isset($couleurs[$niveau]) ? $couleurs[$niveau] : 'label-default';
                echo '<div class="eqLogicDisplayCard cursor ' . ($eqLogic->getIsEnable() ? '' : 'disableCard') . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $plugin->getPathImgIcon() . '">';
                echo '<br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '<br><span class="label ' . $couleur . '">' . htmlspecialchars($niveau, ENT_QUOTES, 'UTF-8') . '</span></span>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
                <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
                <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Alarme}}</a></li>
            <li role="presentation"><a href="#capteurstab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-satellite-dish"></i> {{Capteurs}}</a></li>
            <li role="presentation"><a href="#journaltab" aria-controls="profile" role="tab" data-toggle="tab" id="bt_vigiliumOngletJournal"><i class="fas fa-list"></i> {{Journal}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a></li>
        </ul>
        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <legend><i class="fas fa-wrench"></i> {{Général}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Nom}}</label>
                            <div class="col-sm-4">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'alarme}}">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Objet parent}}</label>
                            <div class="col-sm-4">
                                <select class="eqLogicAttr form-control" data-l1key="object_id">
                                    <option value="">{{Aucun}}</option>
                                    <?php
                                    foreach ((jeeObject::buildTree(null, false)) as $object) {
                                        echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label"></label>
                            <div class="col-sm-4">
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                            </div>
                        </div>

                        <legend><i class="fas fa-shield-alt"></i> {{Centrale}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Commande du mode}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{L'information qui dit si la centrale est désarmée, armée ou en mode nuit. Vigilium n'arme jamais : il suit.}}"></i></sup>
                            </label>
                            <div class="col-sm-4">
                                <div class="input-group">
                                    <input class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="cmd_mode" style="display:none;">
                                    <input class="form-control" id="in_vigiliumNomMode" readonly placeholder="{{Aucune commande}}">
                                    <span class="input-group-btn">
                                        <a class="btn btn-default roundedRight" id="bt_vigiliumChoisirMode"><i class="fas fa-list-alt"></i></a>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « armé total »}}</label>
                            <div class="col-sm-2">
                                <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="valeur_total" placeholder="Armé">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « mode nuit »}}</label>
                            <div class="col-sm-2">
                                <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="valeur_nuit" placeholder="Mode nuit">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « armé partiel »}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{L'armement par groupes de la centrale, lu comme le mode nuit.}}"></i></sup>
                            </label>
                            <div class="col-sm-2">
                                <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="valeur_partiel" placeholder="Armé partiel">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Valeur « désarmé »}}</label>
                            <div class="col-sm-2">
                                <input class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="valeur_desarme" placeholder="Désarmé">
                            </div>
                            <div class="col-sm-5 help-block">{{Vides, ces champs valent les libellés grisés. Une valeur qui n'est aucune des trois garde le mode précédent et se signale au journal : seule la valeur « désarmé » désarme.}}</div>
                        </div>

                        <legend><i class="fas fa-hourglass-half"></i> {{Délais}}</legend>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Délai d'entrée (s)}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Temps laissé, après l'ouverture d'un capteur « parcours d'entrée », pour désarmer la centrale.}}"></i></sup>
                            </label>
                            <div class="col-sm-2"><input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="delai_entree" placeholder="30"></div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Délai de sortie (s)}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Après l'armement, les capteurs « parcours d'entrée » et « intérieur » sont ignorés pendant ce temps.}}"></i></sup>
                            </label>
                            <div class="col-sm-2"><input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="delai_sortie" placeholder="60"></div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Délai de sortie en mode nuit (s)}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Le mode nuit s'arme d'ordinaire maison occupée, souvent tout seul : personne ne sort, et 0 évite qu'une porte ouverte à ce moment passe inaperçue. Vide : comme le mode total.}}"></i></sup>
                            </label>
                            <div class="col-sm-2"><input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="delai_sortie_nuit" placeholder="{{comme total}}"></div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Annulation après désarmement (s)}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Une alarme née de la fin du délai d'entrée, suivie d'un désarmement dans ce délai, est notée « annulée » : la centrale ou la balise a simplement été plus lente que le délai. À régler plus long que le délai d'entrée de la centrale.}}"></i></sup>
                            </label>
                            <div class="col-sm-2"><input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="annulation" placeholder="120"></div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Fenêtre de confirmation (s)}}
                                <sup><i class="fas fa-question-circle tooltips" title="{{Une alarme est confirmée quand un second capteur, différent, se déclenche dans cette fenêtre.}}"></i></sup>
                            </label>
                            <div class="col-sm-2"><input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="fenetre" placeholder="300"></div>
                        </div>
                        <div class="form-group">
                            <label class="col-sm-3 control-label">{{Repos entre deux suspicions (s)}}</label>
                            <div class="col-sm-2"><input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="repos_suspicion" placeholder="300"></div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="capteurstab">
                <div class="alert alert-info" style="margin-top:10px;">
                    <b>{{Rôles}}</b> —
                    <b>{{Parcours d'entrée}}</b> {{: délai d'entrée, ignoré pendant le délai de sortie.}}
                    <b>{{Périmètre}}</b> {{: alarme immédiate.}}
                    <b>{{Intérieur}}</b> {{: suit le délai d'entrée s'il court, sinon alarme.}}
                    <b>{{Extérieur}}</b> {{: une caméra — suspicion seule, confirmation d'une alarme en cours.}}
                    <b>{{Centrale}}</b> {{: l'alarme déclarée par la centrale elle-même.}}
                    <b>{{Sabotage}}</b> {{: autoprotection, caméra masquée, brouillage.}}
                    <br>{{« Valeur d'alerte » : la valeur qui signifie « déclenché ». 1 pour un détecteur ; 0 pour un contact dont l'information vaut 1 quand il est fermé. La colonne « Actuel » montre la valeur lue et ce qu'elle veut dire : vérifiez-la porte fermée.}}
                    <br>{{Un capteur ni « Total » ni « Nuit » n'est jamais surveillé. Un capteur « Sabotage » alerte dans les modes cochés, et se signale en défaut le reste du temps.}}
                    <br>{{Les commandes « Niveau » et « Dernier événement » sont de vraies informations : un scénario branché dessus agit, observation ou pas.}}
                </div>
                <a class="btn btn-success btn-sm pull-right" id="bt_vigiliumAjouterCapteur" style="margin:5px 0;"><i class="fas fa-plus-circle"></i> {{Ajouter un capteur}}</a>
                <div class="table-responsive">
                <table class="table table-bordered table-condensed" id="table_vigiliumCapteurs">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">{{Commande}}</th>
                            <th>{{Nom}}</th>
                            <th>{{Rôle}}</th>
                            <th style="width:90px;">{{Valeur d'alerte}}</th>
                            <th style="width:110px;">{{Actuel}}</th>
                            <th style="width:60px;">{{Total}}</th>
                            <th style="width:60px;">{{Nuit}}</th>
                            <th style="width:90px;">{{Muet après (min)}} <sup><i class="fas fa-question-circle tooltips" title="{{Seulement pour un capteur qui remonte régulièrement (pile, température, battement). Un contact qui ne parle qu'à l'ouverture serait déclaré muet — et donc saboté, alarme armée — après ce temps de calme.}}"></i></sup></th>
                            <th style="min-width:180px;">{{Commande « en ligne »}}</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
                </div>
            </div>

            <div role="tabpanel" class="tab-pane" id="journaltab">
                <div style="margin:10px 0;">
                    <a class="btn btn-default btn-sm" id="bt_vigiliumRafraichirJournal"><i class="fas fa-sync"></i> {{Rafraîchir}}</a>
                    <select class="form-control input-sm" id="sel_vigiliumFiltreJournal" style="display:inline-block;width:auto;margin-left:5px;">
                        <option value="">{{Tout}}</option>
                        <option value="agir">{{Ce qui aurait agi (suspicion, alarme, sabotage)}}</option>
                        <option value="defaut">{{Défauts}}</option>
                        <option value="info">{{Informations}}</option>
                    </select>
                    <span id="span_vigiliumNiveau" style="margin-left:10px;"></span>
                    <span class="help-block" style="display:inline;margin-left:10px;">{{Les 300 dernières entrées, les plus récentes en premier.}}</span>
                </div>
                <table class="table table-condensed table-striped" id="table_vigiliumJournal">
                    <thead><tr><th style="width:160px;">{{Date}}</th><th style="width:120px;">{{Genre}}</th><th>{{Détail}}</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <table id="table_cmd" class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>{{Nom}}</th>
                            <th>{{Type}}</th>
                            <th>{{Options}}</th>
                            <th>{{Action}}</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
/* vigilium.js AVANT plugin.template.js : celui-ci lit l'équipement dès son
 * exécution quand l'URL porte un id, et appelle printEqLogic à la réponse.
 * Les scripts se chargent l'un après l'autre : chargé après, vigilium.js
 * pourrait arriver trop tard, la page resterait sans capteurs, et la
 * sauvegarde suivante les effacerait. */
include_file('desktop', 'vigilium', 'js', 'vigilium');
include_file('core', 'plugin.template', 'js');
?>
