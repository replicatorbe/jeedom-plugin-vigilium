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

/* Les rôles, dans l'ordre où on les pense : ce qui protège la maison, puis
   ce qui l'entoure, puis ce qui la surveille. Les clés sont celles de
   vigiliumMoteur::roles(). */
var vigiliumRoles = [
  { cle: 'entree', texte: '{{Parcours d\'entrée}}' },
  { cle: 'perimetre', texte: '{{Périmètre}}' },
  { cle: 'interieur', texte: '{{Intérieur}}' },
  { cle: 'exterieur', texte: '{{Extérieur (caméra)}}' },
  { cle: 'centrale', texte: '{{Centrale}}' },
  { cle: 'sabotage', texte: '{{Sabotage}}' }
]

var vigiliumGenres = {
  info: { texte: '{{Info}}', classe: 'label-default' },
  suspicion: { texte: '{{Suspicion}}', classe: 'label-warning' },
  alarme: { texte: '{{Alarme}}', classe: 'label-danger' },
  confirmee: { texte: '{{Confirmée}}', classe: 'label-danger' },
  sabotage: { texte: '{{Sabotage}}', classe: 'label-danger' },
  defaut: { texte: '{{Défaut}}', classe: 'label-warning' }
}

/* Vrai pendant que printEqLogic remplit la page : les champs qu'il pose ne
   sont pas des modifications de l'utilisateur. */
var vigiliumRendering = false

function vigiliumMarkModified() {
  if (vigiliumRendering) { return }
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

function vigiliumCurrentId() {
  var champ = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return champ !== null ? String(champ.value) : ''
}

/* Le nom lisible d'une commande dans un champ en lecture seule. L'identifiant
   reste dans data-cmd : c'est lui qu'on enregistre, pas le nom. */
function vigiliumAfficherNomCommande(_input, _id) {
  if (_input === null) { return }
  var id = parseInt(_id, 10)
  _input.style.color = ''
  if (isNaN(id) || id <= 0) {
    _input.setAttribute('data-cmd', '')
    _input.value = ''
    return
  }
  _input.setAttribute('data-cmd', String(id))
  _input.value = '#' + id + '#'
  jeedom.cmd.getHumanCmdName({
    id: id,
    error: function () {
      if (_input.getAttribute('data-cmd') !== String(id)) { return }
      _input.value = '#' + id + '# — {{Commande introuvable}}'
      _input.style.color = '#d9534f'
    },
    success: function (human) {
      if (_input.getAttribute('data-cmd') !== String(id)) { return }
      var nom = String(isset(human) && human !== null ? human : '')
      if (nom === '' || nom === '#' + id + '#') {
        _input.value = '#' + id + '# — {{Commande introuvable}}'
        _input.style.color = '#d9534f'
        return
      }
      _input.value = nom
    }
  })
}

/* ================================================================ CAPTEURS */

/* Une ligne du tableau des capteurs, construite en DOM : insertAdjacentHTML
   sur une table crée un <tbody> par insertion. */
function vigiliumAjouterLigneCapteur(_capteur) {
  var capteur = _capteur || {}
  var tr = document.createElement('tr')
  tr.classList.add('vigiliumCapteur')

  /* Pas de rôle par défaut : une caméra ajoutée en « périmètre » par
     inadvertance ferait une alarme immédiate de chaque passage. */
  var options = '<option value="">{{— Choisir —}}</option>'
  for (var i = 0; i < vigiliumRoles.length; i++) {
    options += '<option value="' + vigiliumRoles[i].cle + '">' + vigiliumRoles[i].texte + '</option>'
  }

  tr.innerHTML =
    '<td><div class="input-group">'
    + '<input class="form-control input-sm roundedLeft vigiliumCmd" readonly>'
    + '<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight vigiliumChoisirCmd" data-cible="vigiliumCmd"><i class="fas fa-list-alt"></i></a></span>'
    + '</div></td>'
    + '<td><input class="form-control input-sm vigiliumNom"></td>'
    + '<td><select class="form-control input-sm vigiliumRole">' + options + '</select></td>'
    + '<td><input class="form-control input-sm vigiliumAlerte" placeholder="1"></td>'
    + '<td style="text-align:center;"><input type="checkbox" class="vigiliumTotal"></td>'
    + '<td style="text-align:center;"><input type="checkbox" class="vigiliumNuit"></td>'
    + '<td><input class="form-control input-sm vigiliumMuet" placeholder="{{non}}"></td>'
    + '<td><div class="input-group">'
    + '<input class="form-control input-sm roundedLeft vigiliumEnLigne" readonly>'
    + '<span class="input-group-btn">'
    + '<a class="btn btn-default btn-sm vigiliumChoisirCmd" data-cible="vigiliumEnLigne"><i class="fas fa-list-alt"></i></a>'
    + '<a class="btn btn-default btn-sm roundedRight vigiliumViderEnLigne" title="{{Retirer}}"><i class="fas fa-times"></i></a>'
    + '</span></div></td>'
    + '<td><a class="btn btn-danger btn-xs vigiliumSupprimerCapteur"><i class="fas fa-minus-circle"></i></a></td>'

  document.getElementById('table_vigiliumCapteurs').querySelector('tbody').appendChild(tr)

  vigiliumAfficherNomCommande(tr.querySelector('.vigiliumCmd'), init(capteur.cmd, 0))
  vigiliumAfficherNomCommande(tr.querySelector('.vigiliumEnLigne'), init(capteur.cmd_en_ligne, 0))
  tr.querySelector('.vigiliumNom').value = String(init(capteur.nom, ''))
  tr.querySelector('.vigiliumRole').value = String(init(capteur.role, ''))
  tr.querySelector('.vigiliumAlerte').value = String(init(capteur.alerte, '1'))
  /* Un capteur neuf est actif dans les deux modes : c'est en le retirant du
     mode nuit qu'on décide consciemment que l'étage ne compte pas la nuit. */
  tr.querySelector('.vigiliumTotal').checked = isset(_capteur) ? (String(init(capteur.total, '0')) === '1') : true
  tr.querySelector('.vigiliumNuit').checked = isset(_capteur) ? (String(init(capteur.nuit, '0')) === '1') : true
  var muet = init(capteur.muet_apres, '')
  tr.querySelector('.vigiliumMuet').value = (String(muet) === '0') ? '' : String(muet)
  return tr
}

/* Les lignes incomplètes ne sont pas enregistrées : elles sont signalées,
   pour qu'un capteur saisi à moitié ne disparaisse pas sans un mot. */
function vigiliumLireCapteurs() {
  var capteurs = []
  var incompletes = []
  var vus = {}
  var doublons = []
  var lignes = document.querySelectorAll('#table_vigiliumCapteurs tbody tr.vigiliumCapteur')
  for (var i = 0; i < lignes.length; i++) {
    var ligne = lignes[i]
    var cmd = parseInt(ligne.querySelector('.vigiliumCmd').getAttribute('data-cmd'), 10)
    var nomLigne = String(ligne.querySelector('.vigiliumNom').value).trim() || ('{{ligne}} ' + (i + 1))
    /* Une ligne incomplète est gardée telle quelle — le moteur l'ignore —
       pour que la saisie survive au rechargement qui suit la sauvegarde. */
    if (isNaN(cmd) || cmd <= 0 || ligne.querySelector('.vigiliumRole').value === '') {
      incompletes.push(nomLigne)
    }
    if (!isNaN(cmd) && cmd > 0) {
      if (vus[cmd]) { doublons.push(nomLigne) }
      vus[cmd] = true
    }
    var enLigne = parseInt(ligne.querySelector('.vigiliumEnLigne').getAttribute('data-cmd'), 10)
    var muet = parseInt(ligne.querySelector('.vigiliumMuet').value, 10)
    capteurs.push({
      cmd: (isNaN(cmd) || cmd <= 0) ? 0 : cmd,
      nom: String(ligne.querySelector('.vigiliumNom').value).trim(),
      role: ligne.querySelector('.vigiliumRole').value,
      alerte: String(ligne.querySelector('.vigiliumAlerte').value).trim() === '' ? '1' : String(ligne.querySelector('.vigiliumAlerte').value).trim(),
      total: ligne.querySelector('.vigiliumTotal').checked ? 1 : 0,
      nuit: ligne.querySelector('.vigiliumNuit').checked ? 1 : 0,
      muet_apres: (isNaN(muet) || muet < 0) ? 0 : muet,
      cmd_en_ligne: (isNaN(enLigne) || enLigne <= 0) ? 0 : enLigne
    })
  }
  if (incompletes.length > 0) {
    jeedomUtils.showAlert({ message: '{{Capteurs ignorés par l\'alarme tant qu\'il leur manque une commande ou un rôle}} : ' + incompletes.join(', '), level: 'warning', ttl: 15000 })
  }
  if (doublons.length > 0) {
    jeedomUtils.showAlert({ message: '{{La même commande est désignée deux fois}} : ' + doublons.join(', '), level: 'warning', ttl: 15000 })
  }
  return capteurs
}

/* ================================================================= JOURNAL */

function vigiliumDate(_t) {
  var d = new Date(parseInt(_t, 10) * 1000)
  var deux = function (_n) { return (_n < 10 ? '0' : '') + _n }
  return d.getFullYear() + '-' + deux(d.getMonth() + 1) + '-' + deux(d.getDate())
    + ' ' + deux(d.getHours()) + ':' + deux(d.getMinutes()) + ':' + deux(d.getSeconds())
}

function vigiliumChargerJournal() {
  var id = vigiliumCurrentId()
  var corps = document.querySelector('#table_vigiliumJournal tbody')
  if (corps === null) { return }
  if (id === '') {
    corps.innerHTML = ''
    return
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/vigilium/core/ajax/vigilium.ajax.php',
    data: { action: 'journal', id: id, nombre: 300 },
    dataType: 'json',
    global: false,
    error: function (request, status, error) {
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        return
      }
      /* La page a pu changer d'équipement pendant l'aller-retour. */
      if (vigiliumCurrentId() !== id) { return }
      corps.innerHTML = ''
      var entrees = data.result.entrees || []
      for (var i = 0; i < entrees.length; i++) {
        var entree = entrees[i]
        var genre = vigiliumGenres[entree.genre] || { texte: String(entree.genre), classe: 'label-default' }
        var tr = document.createElement('tr')
        var tdDate = document.createElement('td')
        tdDate.textContent = vigiliumDate(entree.t)
        var tdGenre = document.createElement('td')
        var label = document.createElement('span')
        label.className = 'label ' + genre.classe
        label.textContent = genre.texte
        tdGenre.appendChild(label)
        var tdDetail = document.createElement('td')
        tdDetail.textContent = String(init(entree.detail, ''))
        tr.appendChild(tdDate)
        tr.appendChild(tdGenre)
        tr.appendChild(tdDetail)
        corps.appendChild(tr)
      }
      if (entrees.length === 0) {
        corps.innerHTML = '<tr><td colspan="3">{{Journal vide pour le moment.}}</td></tr>'
      }
      var niveau = document.getElementById('span_vigiliumNiveau')
      if (niveau !== null) { niveau.textContent = '{{Niveau actuel}} : ' + String(init(data.result.niveau, '')) }
    }
  })
}

/* ========================================================= CYCLE DU CŒUR */

function printEqLogic(_eqLogic) {
  vigiliumRendering = true
  try {
    var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
    vigiliumAfficherNomCommande(document.getElementById('in_vigiliumNomMode'), init(configuration.cmd_mode, 0))
    var corps = document.querySelector('#table_vigiliumCapteurs tbody')
    corps.innerHTML = ''
    var capteurs = Array.isArray(configuration.capteurs) ? configuration.capteurs : []
    for (var i = 0; i < capteurs.length; i++) {
      vigiliumAjouterLigneCapteur(capteurs[i])
    }
    var journal = document.querySelector('#table_vigiliumJournal tbody')
    if (journal !== null) { journal.innerHTML = '' }
  } finally {
    vigiliumRendering = false
  }
  vigiliumChargerJournal()
}

/* Les capteurs sont une liste d'objets : data-lXkey ne sait pas les ramasser,
   c'est donc ici qu'ils rejoignent la configuration enregistrée. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.capteurs = vigiliumLireCapteurs()
  return _eqLogic
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<td>'
  /* L'identifiant, caché mais présent : sans lui, chaque enregistrement
     recréerait les commandes et perdrait leur historique. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '</td>'
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : écouteurs posés à la racine du script, en
   délégation sur le conteneur de page, remplacé à chaque navigation. */
var vigiliumContainer = document.getElementById('div_pageContainer') || document.body

vigiliumContainer.addEventListener('click', function (event) {
  var cible = null

  if (cible = event.target.closest('#bt_vigiliumChoisirMode')) {
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!isset(result) || !isset(result.cmd) || !isset(result.cmd.id)) { return }
      document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="cmd_mode"]').value = result.cmd.id
      vigiliumAfficherNomCommande(document.getElementById('in_vigiliumNomMode'), result.cmd.id)
      vigiliumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('#bt_vigiliumAjouterCapteur')) {
    vigiliumAjouterLigneCapteur(null)
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumChoisirCmd')) {
    var ligne = cible.closest('tr')
    var champ = ligne.querySelector('.' + cible.getAttribute('data-cible'))
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!isset(result) || !isset(result.cmd) || !isset(result.cmd.id)) { return }
      vigiliumAfficherNomCommande(champ, result.cmd.id)
      /* Le nom du capteur pré-rempli avec celui de son équipement : c'est ce
         qu'on lira au journal, autant qu'il parle tout de suite. */
      var nom = ligne.querySelector('.vigiliumNom')
      if (champ.classList.contains('vigiliumCmd') && nom.value.trim() === '' && isset(result.human)) {
        var morceaux = String(result.human).replace(/^#|#$/g, '').split('][')
        nom.value = morceaux.length >= 2 ? morceaux[morceaux.length - 2].replace(/^\[|\]$/g, '') : String(result.human)
      }
      vigiliumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('.vigiliumViderEnLigne')) {
    vigiliumAfficherNomCommande(cible.closest('tr').querySelector('.vigiliumEnLigne'), 0)
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumSupprimerCapteur')) {
    cible.closest('tr').remove()
    vigiliumMarkModified()
    return
  }

  if ((cible = event.target.closest('#bt_vigiliumRafraichirJournal')) || (cible = event.target.closest('#bt_vigiliumOngletJournal'))) {
    vigiliumChargerJournal()
  }
})

vigiliumContainer.addEventListener('change', function (event) {
  if (event.target.closest('#table_vigiliumCapteurs')) { vigiliumMarkModified() }
})
