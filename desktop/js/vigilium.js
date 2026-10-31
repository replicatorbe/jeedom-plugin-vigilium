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
  defaut: { texte: '{{Défaut}}', classe: 'label-warning' },
  sortie: { texte: '{{Réaction}}', classe: 'label-primary' }
}

/* Blocs d'action qui n'ont pas de sens hors d'un scénario, ou qui
   retiendraient l'écouteur : les mêmes que vigiliumSorties::BLOCS_REFUSES. */
var vigiliumBlocsRefuses = ['wait', 'sleep', 'ask', 'report', 'exportHistory', 'stop', 'log', 'scenario_return', 'icon', 'tag']

/* Vrai pendant que printEqLogic remplit la page : les champs qu'il pose ne
   sont pas des modifications de l'utilisateur. */
var vigiliumRendering = false

/* L'équipement dont printEqLogic a réellement rempli les capteurs. Tant
   qu'il ne correspond pas à l'équipement ouvert, la sauvegarde est refusée :
   elle enregistrerait un tableau vide et effacerait tous les capteurs. */
var vigiliumChargePour = null

/* Le dernier journal reçu, gardé pour le filtrer sans recharger. */
var vigiliumJournal = []

/*
 * Requête vers le contrôleur du plugin. Un fetch et non domUtils.ajax : sur
 * une réponse HTTP en échec, domUtils.ajax rejoue la requête puis se tait
 * (console.warn seulement, ni error: ni message), et l'écran restait sur des
 * données périmées. Ici, tout échec — HTTP, réseau, JSON illisible, état non
 * « ok » — finit dans un message.
 */
function vigiliumAjax(_action, _data, _success) {
  var corps = new URLSearchParams()
  var donnees = Object.assign({ action: _action }, _data || {})
  for (var cle in donnees) {
    if (Object.prototype.hasOwnProperty.call(donnees, cle)) { corps.append(cle, String(donnees[cle])) }
  }
  fetch('plugins/vigilium/core/ajax/vigilium.ajax.php', {
    method: 'POST',
    body: corps,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    credentials: 'same-origin'
  }).then(function (reponse) {
    return reponse.text().then(function (texte) {
      if (!reponse.ok) {
        throw new Error('{{Le serveur a répondu}} ' + reponse.status + ' — ' + texte.substring(0, 300))
      }
      var data = null
      try {
        data = JSON.parse(texte)
      } catch (erreur) {
        throw new Error('{{Réponse illisible du plugin :}} ' + texte.substring(0, 300))
      }
      if (data === null || data.state != 'ok') {
        throw new Error((data !== null && isset(data.result)) ? String(data.result) : '{{Erreur sans message.}}')
      }
      return data.result
    })
  }).then(_success).catch(function (erreur) {
    if (isset(erreur) && erreur !== null && erreur.name === 'AbortError') { return }
    jeedomUtils.showAlert({ message: String(isset(erreur.message) ? erreur.message : erreur), level: 'danger', ttl: 15000 })
  })
}

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
    + '<td class="vigiliumActuel" style="white-space:nowrap;"></td>'
    + '<td style="text-align:center;"><input type="checkbox" class="vigiliumTotal" title="{{Surveillé en armement total}}" aria-label="{{Total}}"></td>'
    + '<td style="text-align:center;"><input type="checkbox" class="vigiliumNuit" title="{{Surveillé en mode nuit}}" aria-label="{{Nuit}}"></td>'
    + '<td><input type="number" min="0" step="1" class="form-control input-sm vigiliumMuet" placeholder="{{non}}"></td>'
    + '<td><div class="input-group">'
    + '<input class="form-control input-sm roundedLeft vigiliumEnLigne" readonly>'
    + '<span class="input-group-btn">'
    + '<a class="btn btn-default btn-sm vigiliumChoisirCmd" data-cible="vigiliumEnLigne"><i class="fas fa-list-alt"></i></a>'
    + '<a class="btn btn-default btn-sm roundedRight vigiliumViderEnLigne" title="{{Retirer}}"><i class="fas fa-times"></i></a>'
    + '</span></div></td>'
    + '<td><a class="btn btn-danger btn-xs vigiliumSupprimerCapteur" title="{{Retirer ce capteur}}"><i class="fas fa-minus-circle"></i></a></td>'

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

/* Montre, dans la ligne, la valeur actuelle de la commande et ce qu'elle
   veut dire avec la valeur d'alerte saisie : c'est le seul moyen de vérifier
   le sens d'un contact sans attendre qu'il déclenche — ou ne déclenche pas. */
function vigiliumAfficherActuel(_ligne, _valeur) {
  var cellule = _ligne.querySelector('.vigiliumActuel')
  if (cellule === null) { return }
  cellule.textContent = ''
  if (!isset(_valeur) || _valeur === null) { return }
  var attendue = String(_ligne.querySelector('.vigiliumAlerte').value).trim() || '1'
  var valeur = String(_valeur).trim()
  var alerte = (!isNaN(parseFloat(attendue)) && !isNaN(parseFloat(valeur)))
    ? parseFloat(attendue) === parseFloat(valeur)
    : attendue.toLowerCase() === valeur.toLowerCase()
  var badge = document.createElement('span')
  badge.className = 'label ' + (alerte ? 'label-warning' : 'label-success')
  badge.textContent = alerte ? '{{en alerte}}' : '{{au repos}}'
  cellule.appendChild(document.createTextNode(valeur + ' '))
  cellule.appendChild(badge)
}

function vigiliumChargerValeurs() {
  var lignes = document.querySelectorAll('#table_vigiliumCapteurs tbody tr.vigiliumCapteur')
  var ids = []
  for (var i = 0; i < lignes.length; i++) {
    var id = parseInt(lignes[i].querySelector('.vigiliumCmd').getAttribute('data-cmd'), 10)
    if (!isNaN(id) && id > 0) { ids.push(id) }
  }
  if (ids.length === 0) { return }
  vigiliumAjax('valeurs', { ids: ids.join(',') }, function (valeurs) {
    var lignesActuelles = document.querySelectorAll('#table_vigiliumCapteurs tbody tr.vigiliumCapteur')
    for (var j = 0; j < lignesActuelles.length; j++) {
      var cmd = lignesActuelles[j].querySelector('.vigiliumCmd').getAttribute('data-cmd')
      lignesActuelles[j].setAttribute('data-valeur', (valeurs && isset(valeurs[cmd])) ? String(valeurs[cmd]) : '')
      vigiliumAfficherActuel(lignesActuelles[j], (valeurs && isset(valeurs[cmd])) ? valeurs[cmd] : null)
    }
  })
}

/* Les lignes incomplètes ne sont pas enregistrées : elles sont signalées,
   pour qu'un capteur saisi à moitié ne disparaisse pas sans un mot. */
function vigiliumLireCapteurs() {
  var capteurs = []
  var incompletes = []
  var vus = {}
  var doublons = []
  var jamais = []
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
    if (!ligne.querySelector('.vigiliumTotal').checked && !ligne.querySelector('.vigiliumNuit').checked) {
      jamais.push(nomLigne)
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
  if (jamais.length > 0) {
    jeedomUtils.showAlert({ message: '{{Capteurs surveillés dans aucun mode (ni Total ni Nuit)}} : ' + jamais.join(', '), level: 'warning', ttl: 15000 })
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
  if (id === '') {
    vigiliumJournal = []
    vigiliumRenderJournal()
    return
  }
  vigiliumAjax('journal', { id: id, nombre: 300 }, function (resultat) {
    /* La page a pu changer d'équipement pendant l'aller-retour. */
    if (vigiliumCurrentId() !== id) { return }
    vigiliumJournal = resultat.entrees || []
    vigiliumRenderJournal()
    var niveau = document.getElementById('span_vigiliumNiveau')
    if (niveau !== null) { niveau.textContent = '{{Niveau actuel}} : ' + String(init(resultat.niveau, '')) }
  })
}

function vigiliumRenderJournal() {
  var corps = document.querySelector('#table_vigiliumJournal tbody')
  if (corps === null) { return }
  var filtre = document.getElementById('sel_vigiliumFiltreJournal')
  var choix = filtre !== null ? filtre.value : ''
  var agir = ['suspicion', 'alarme', 'confirmee', 'sabotage', 'sortie']
  corps.innerHTML = ''
  var affichees = 0
  for (var i = 0; i < vigiliumJournal.length; i++) {
    var entree = vigiliumJournal[i]
    if (choix === 'agir' && agir.indexOf(entree.genre) < 0) { continue }
    if ((choix === 'defaut' || choix === 'info') && entree.genre !== choix) { continue }
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
    affichees++
  }
  if (affichees === 0) {
    var vide = document.createElement('tr')
    var td = document.createElement('td')
    td.colSpan = 3
    td.textContent = vigiliumJournal.length === 0 ? '{{Journal vide pour le moment.}}' : '{{Aucune entrée de ce genre.}}'
    vide.appendChild(td)
    corps.appendChild(vide)
  }
}

/* ================================================================ RÉACTIONS */

/*
 * Une ligne d'action, calquée sur le sélecteur d'action des scénarios. L'ordre
 * html() → setJeeValues → appendChild → replaceWith est celui du cœur : le
 * HTML des options contient des <script> que seul Element.prototype.html()
 * exécute.
 */
function vigiliumAjouterAction(_declencheur, _action) {
  var conteneur = document.querySelector('.vigiliumActions[data-declencheur="' + _declencheur + '"]')
  if (conteneur === null) { return null }
  var action = _action || {}
  if (!isset(action.options)) { action.options = {} }

  var div = '<div class="vigiliumAction expression" style="margin-bottom:4px;">'
  div += '<input class="expressionAttr" data-l1key="type" style="display:none;" value="action">'
  div += '<div class="form-group" style="margin:0;">'
  div += '<div class="col-sm-1">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="enable" checked title="{{Décocher pour désactiver cette action sans la supprimer}}">'
  div += '<input type="checkbox" class="expressionAttr" data-l1key="options" data-l2key="background" title="{{Exécuter en parallèle des autres actions}}">'
  div += '</div>'
  div += '<div class="col-sm-5"><div class="input-group">'
  div += '<span class="input-group-btn"><a class="btn btn-default btn-sm vigiliumRetirerAction roundedLeft" title="{{Retirer cette action}}"><i class="fas fa-minus-circle"></i></a></span>'
  div += '<input class="expressionAttr form-control input-sm cmdAction" data-l1key="cmd" placeholder="{{Commande à déclencher}}">'
  div += '<span class="input-group-btn">'
  div += '<a class="btn btn-default btn-sm vigiliumChoisirBloc" title="{{Choisir un bloc (message, scénario, variable...)}}"><i class="fas fa-tasks"></i></a>'
  div += '<a class="btn btn-default btn-sm vigiliumChoisirAction roundedRight" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>'
  div += '</span></div></div>'
  div += '<div class="col-sm-6 actionOptions"></div>'
  div += '</div></div>'

  var enveloppe = document.createElement('div')
  enveloppe.html(div)
  enveloppe.setJeeValues(action, '.expressionAttr')
  conteneur.appendChild(enveloppe)
  var noeuds = Array.prototype.slice.call(enveloppe.childNodes)
  enveloppe.replaceWith(...noeuds)
  if (noeuds.length > 0) {
    vigiliumRafraichirOptions(noeuds[0], init(action.cmd, ''), action.options)
  }
  return noeuds[0]
}

/* Réaffiche les options — titre, message — d'après la commande visée. C'est
   le cœur qui les dessine. Tant que sa réponse n'est pas arrivée, les options
   connues restent sur la ligne : sinon l'enregistrement remplacerait par du
   vide un message rédigé de longue date. */
function vigiliumRafraichirOptions(_ligne, _expression, _options) {
  var expression = String(init(_expression, ''))
  if (_ligne.vigiliumExpression === expression) { return }
  _ligne.vigiliumExpression = expression
  _ligne.vigiliumEnAttente = _options || {}
  jeedom.cmd.displayActionOption(expression, _options, function (html) {
    var cible = _ligne.querySelector('.actionOptions')
    if (cible === null) { return }
    if (html === 'Unsupported') {
      cible.textContent = '{{Ce bloc n\'est utilisable que dans un scénario.}}'
      return
    }
    /* Une commande sans option (un bouton « Sirène ON ») n'a rien à
       afficher : le cœur rend alors une chaîne vide. Une commande supprimée
       aussi : les options connues restent donc en attente, pour ne pas
       remplacer à l'enregistrement un message par du vide. */
    cible.html(html)
    jeedomUtils.taAutosize()
    if (html !== '') { _ligne.vigiliumEnAttente = null }
  })
}

function vigiliumLireSorties() {
  var sorties = {}
  document.querySelectorAll('.vigiliumActions').forEach(function (conteneur) {
    var liste = []
    conteneur.querySelectorAll('.vigiliumAction').forEach(function (ligne) {
      var action = ligne.getJeeValues('.expressionAttr')[0]
      if (isset(ligne.vigiliumEnAttente) && ligne.vigiliumEnAttente !== null) {
        action.options = Object.assign({}, ligne.vigiliumEnAttente, action.options)
      }
      liste.push(action)
    })
    sorties[conteneur.getAttribute('data-declencheur')] = liste
  })
  return sorties
}

/* Une caméra pour les photos : sa commande de capture et celle du chemin. */
function vigiliumAjouterPhoto(_camera) {
  var camera = _camera || {}
  var tr = document.createElement('tr')
  tr.classList.add('vigiliumPhoto')
  var champ = function (_classe, _type) {
    return '<td><div class="input-group">'
      + '<input class="form-control input-sm roundedLeft ' + _classe + '" readonly>'
      + '<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight vigiliumChoisirPhoto" data-cible="' + _classe + '" data-type="' + _type + '"><i class="fas fa-list-alt"></i></a></span>'
      + '</div></td>'
  }
  tr.innerHTML = champ('vigiliumCapture', 'action') + champ('vigiliumFichier', 'info')
    + '<td><a class="btn btn-danger btn-xs vigiliumRetirerPhoto" title="{{Retirer cette caméra}}"><i class="fas fa-minus-circle"></i></a></td>'
  document.getElementById('table_vigiliumPhotos').querySelector('tbody').appendChild(tr)
  vigiliumAfficherNomCommande(tr.querySelector('.vigiliumCapture'), init(camera.capture, 0))
  vigiliumAfficherNomCommande(tr.querySelector('.vigiliumFichier'), init(camera.fichier, 0))
}

function vigiliumLirePhotos() {
  var cameras = []
  document.querySelectorAll('#table_vigiliumPhotos tbody tr.vigiliumPhoto').forEach(function (tr) {
    var capture = parseInt(tr.querySelector('.vigiliumCapture').getAttribute('data-cmd'), 10)
    var fichier = parseInt(tr.querySelector('.vigiliumFichier').getAttribute('data-cmd'), 10)
    if (isNaN(fichier) || fichier <= 0) { return }
    cameras.push({ capture: (isNaN(capture) || capture <= 0) ? 0 : capture, fichier: fichier })
  })
  return cameras
}

/* Le bandeau dit, à l'endroit où l'on règle les réactions, si elles partent
   pour de vrai. */
function vigiliumAfficherExecution() {
  var bandeau = document.getElementById('div_vigiliumBandeauExecution')
  var choix = document.getElementById('sel_vigiliumExecution')
  if (bandeau === null || choix === null) { return }
  if (choix.value === 'actif') {
    bandeau.innerHTML = '<div class="alert alert-danger"><i class="fas fa-bolt"></i> <b>{{Actif}}</b> {{: les réactions ci-dessous sont exécutées pour de vrai à chaque décision.}}</div>'
  } else {
    bandeau.innerHTML = '<div class="alert alert-info"><i class="fas fa-eye"></i> <b>{{Observation}}</b> {{: les réactions sont seulement écrites au journal (« Aurait exécuté… »). Le bouton « Tester » les joue pour de vrai.}}</div>'
  }
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
    document.querySelectorAll('.vigiliumActions').forEach(function (conteneur) { conteneur.innerHTML = '' })
    var sorties = (isset(configuration.sorties) && typeof configuration.sorties === 'object' && configuration.sorties !== null) ? configuration.sorties : {}
    Object.keys(sorties).forEach(function (declencheur) {
      if (Array.isArray(sorties[declencheur])) {
        sorties[declencheur].forEach(function (action) { vigiliumAjouterAction(declencheur, action) })
      }
    })
    document.getElementById('table_vigiliumPhotos').querySelector('tbody').innerHTML = ''
    ;(Array.isArray(configuration.photos) ? configuration.photos : []).forEach(function (camera) { vigiliumAjouterPhoto(camera) })
    /* Une alarme enregistrée avant l'existence du réglage est en observation. */
    var execution = document.getElementById('sel_vigiliumExecution')
    if (execution !== null && execution.value === '') { execution.value = 'observation' }
    vigiliumAfficherExecution()
    vigiliumJournal = []
    vigiliumRenderJournal()
    vigiliumChargePour = String(init(_eqLogic.id, ''))
  } finally {
    vigiliumRendering = false
  }
  vigiliumChargerJournal()
  vigiliumChargerValeurs()
}

/* Les capteurs sont une liste d'objets : data-lXkey ne sait pas les ramasser,
   c'est donc ici qu'ils rejoignent la configuration enregistrée. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  if (vigiliumChargePour === null || vigiliumChargePour !== String(init(_eqLogic.id, ''))) {
    /* La page n'a pas fini de charger cet équipement : ses capteurs ne sont
       pas à l'écran, et les enregistrer maintenant les effacerait. */
    jeedomUtils.showAlert({ message: '{{La page n\'a pas fini de charger les capteurs : rechargez-la avant d\'enregistrer.}}', level: 'danger' })
    /* Le cœur a déjà marqué la page « enregistrée » : sans cela, on pourrait
       la quitter sans avertissement et perdre la saisie. */
    if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
    window.modifyWithoutSave = true
    throw new Error('vigilium : capteurs non chargés')
  }
  _eqLogic.configuration.capteurs = vigiliumLireCapteurs()
  _eqLogic.configuration.sorties = vigiliumLireSorties()
  _eqLogic.configuration.photos = vigiliumLirePhotos()
  if (String(init(_eqLogic.configuration.execution, '')) === '') { _eqLogic.configuration.execution = 'observation' }
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
      if (champ.classList.contains('vigiliumCmd')) { vigiliumChargerValeurs() }
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

  if (cible = event.target.closest('.vigiliumAjouterAction')) {
    vigiliumAjouterAction(cible.getAttribute('data-declencheur'), null)
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumRetirerAction')) {
    cible.closest('.vigiliumAction').remove()
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumChoisirAction')) {
    var ligneAction = cible.closest('.vigiliumAction')
    /* Le sélecteur rend #[Objet][Équipement][Commande]# : le cœur le convertit
       en identifiant à l'enregistrement, un renommage ne casse donc rien. */
    jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
      ligneAction.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      vigiliumRafraichirOptions(ligneAction, result.human, '')
      vigiliumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('.vigiliumChoisirBloc')) {
    var ligneBloc = cible.closest('.vigiliumAction')
    jeedom.getSelectActionModal({}, function (result) {
      if (vigiliumBlocsRefuses.indexOf(result.human) !== -1) {
        jeedomUtils.showAlert({ message: '{{Ce bloc n\'est pas utilisable ici : il retiendrait l\'alarme ou n\'a de sens que dans un scénario. Passez par l\'action « Scénario ».}}', level: 'warning', ttl: 10000 })
        return
      }
      ligneBloc.querySelector('.expressionAttr[data-l1key="cmd"]').jeeValue(result.human)
      vigiliumRafraichirOptions(ligneBloc, result.human, '')
      vigiliumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('#bt_vigiliumAjouterPhoto')) {
    vigiliumAjouterPhoto(null)
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumRetirerPhoto')) {
    cible.closest('tr').remove()
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumChoisirPhoto')) {
    var champPhoto = cible.closest('tr').querySelector('.' + cible.getAttribute('data-cible'))
    jeedom.cmd.getSelectModal({ cmd: { type: cible.getAttribute('data-type') } }, function (result) {
      if (!isset(result) || !isset(result.cmd) || !isset(result.cmd.id)) { return }
      vigiliumAfficherNomCommande(champPhoto, result.cmd.id)
      vigiliumMarkModified()
    })
    return
  }

  if (cible = event.target.closest('.vigiliumTesterSorties')) {
    var idTest = vigiliumCurrentId()
    if (idTest === '') { return }
    if ((typeof jeeFrontEnd !== 'undefined' && jeeFrontEnd.modifyWithoutSave) || window.modifyWithoutSave) {
      jeedomUtils.showAlert({ message: '{{Sauvegardez d\'abord : le test joue les réactions enregistrées.}}', level: 'warning' })
      return
    }
    var declencheur = cible.getAttribute('data-declencheur')
    jeeDialog.confirm('{{Jouer pour de vrai les réactions}} « ' + declencheur + ' » ? {{Notifications, lumières et sirènes partiront, avec un texte d\'essai.}}', function (ok) {
      if (!ok) { return }
      vigiliumAjax('tester', { id: idTest, declencheur: declencheur }, function (resultat) {
        if (resultat.nombre === 0) {
          jeedomUtils.showAlert({ message: '{{Aucune réaction enregistrée pour ce déclencheur.}}', level: 'warning' })
        } else if (resultat.echecs.length > 0) {
          jeedomUtils.showAlert({ message: '{{Échec :}} ' + resultat.echecs.join(' ; '), level: 'danger', ttl: 15000 })
        } else {
          jeedomUtils.showAlert({ message: resultat.nombre + ' {{action(s) jouée(s).}}', level: 'success' })
        }
      })
    })
    return
  }

  if (cible = event.target.closest('.vigiliumViderEnLigne')) {
    vigiliumAfficherNomCommande(cible.closest('tr').querySelector('.vigiliumEnLigne'), 0)
    vigiliumMarkModified()
    return
  }

  if (cible = event.target.closest('.vigiliumSupprimerCapteur')) {
    var ligneASupprimer = cible.closest('tr')
    jeeDialog.confirm('{{Retirer ce capteur de l\'alarme ?}}', function (ok) {
      if (!ok) { return }
      ligneASupprimer.remove()
      vigiliumMarkModified()
    })
    return
  }

  if ((cible = event.target.closest('#bt_vigiliumRafraichirJournal')) || (cible = event.target.closest('#bt_vigiliumOngletJournal'))) {
    vigiliumChargerJournal()
  }
})

vigiliumContainer.addEventListener('change', function (event) {
  if (event.target.closest('#sel_vigiliumFiltreJournal')) {
    vigiliumRenderJournal()
    return
  }
  if (event.target.closest('#sel_vigiliumExecution')) {
    vigiliumAfficherExecution()
    return
  }
  /* Capteurs et réactions ne sont pas des .eqLogicAttr : le cœur ne les voit
     pas changer, et quitterait la page sans prévenir. */
  if (event.target.closest('#table_vigiliumCapteurs') || event.target.closest('.vigiliumAction')) { vigiliumMarkModified() }
})

/* Une commande tapée ou collée à la main : ses options sont redessinées quand
   on quitte le champ. */
vigiliumContainer.addEventListener('focusout', function (event) {
  var champ = event.target.closest('.vigiliumAction .cmdAction')
  if (champ === null) { return }
  var ligne = champ.closest('.vigiliumAction')
  var actuelle = ligne.getJeeValues('.expressionAttr')[0]
  /* Les options encore en attente d'un premier rendu comptent : sans elles,
     le message pas encore dessiné serait remplacé par du vide. */
  var enAttente = (isset(ligne.vigiliumEnAttente) && ligne.vigiliumEnAttente !== null) ? ligne.vigiliumEnAttente : {}
  vigiliumRafraichirOptions(ligne, champ.jeeValue(), Object.assign({}, enAttente, init(actuelle.options, {})))
})

/* La valeur d'alerte change : le verdict « en alerte / au repos » de la ligne
   suit la saisie. */
vigiliumContainer.addEventListener('input', function (event) {
  var champ = event.target.closest('.vigiliumAlerte')
  if (champ === null) { return }
  var ligne = champ.closest('tr')
  var valeur = ligne.getAttribute('data-valeur')
  vigiliumAfficherActuel(ligne, (valeur === null || valeur === '') ? null : valeur)
})
