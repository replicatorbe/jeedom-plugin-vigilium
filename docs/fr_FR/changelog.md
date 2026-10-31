# Changelog Vigilium

## 0.5 — relecture d'ensemble

- Nouvelle détection pendant une alarme restée sans réponse : réactions et
  sirènes repartent (elle passait en silence).
- Confirmation limitée à la fenêtre ; une alarme née du délai d'entrée n'est
  confirmée que par un capteur du périmètre.
- Mode de centrale inconnu : désarmé après 10 minutes, défaut à chaque
  nouvelle valeur.
- « Sauf centrale » retient PANIC même quand la centrale parle après le
  contact.
- Sabotage par impulsions : un épisode, une sirène par armement ; une sirène
  de sabotage repart pour une intrusion ; horloge qui recule sans effet sur
  la durée des sirènes.
- Désactiver le plugin n'efface plus le journal ; un verrou au mauvais
  propriétaire ne rend plus l'alarme muette (message au centre de messages
  si l'état ne peut être écrit) ; suppression concurrente sans sirène
  orpheline ; test de sirène revérifié sous verrou ; battement qui ne
  retient plus le cron des autres plugins.

## 0.4.1 — capteurs Shelly BLU

- Documentation : brancher un contact Shelly BLU (chiffrement BTHome dans
  mqttbe 0.11, commande, rôle, supervision par sa présence).

## 0.4 — sirènes

- Sirènes avec mise en marche, arrêt, durée maximale toujours bornée, retard,
  niveaux déclencheurs (alarme, confirmée, sabotage) et option « sauf
  centrale » pour PANIC.
- Arrêt garanti au désarmement, à l'acquittement, au bout de la durée, à la
  désactivation ou à la suppression de l'alarme ; une fois par incident.
- Bouton « Tester » par sirène : 3 secondes, puis arrêt.
- Signal de vie à intervalle régulier pour une centrale qui surveille Jeedom.

## 0.3 — réactions

- Onglet Réactions : une liste d'actions par déclencheur (suspicion, alarme,
  alarme confirmée, relance, fin d'incident, sabotage, défaut), avec le
  sélecteur d'action des scénarios et des jetons (#detail#, #photo#…).
- Mode d'exécution par alarme : observation (par défaut, tout est écrit au
  journal sans rien exécuter) ou actif.
- Photos des caméras prises avant les réactions de suspicion et d'alarme.
- Relances tant que l'alarme n'est ni désarmée ni acquittée ; réactions de fin
  au retour au calme.
- Bouton « Tester » par liste, qui joue les réactions pour de vrai après
  confirmation.

## 0.2 — corrections de la campagne d'observation

- Passer de total à nuit garde l'incident en cours et n'exclut plus le capteur
  forcé ; le délai de sortie ne repart pas.
- Une porte ouverte pendant le délai de sortie et laissée ouverte lance le
  délai d'entrée à la fin de la sortie. Délai de sortie propre au mode nuit.
- À l'armement, sabotages en cours et capteurs muets sont rappelés.
- La centrale ne confirme plus sa propre alarme, n'est jamais exclue, et ses
  alarmes hors armement (incendie, panique, contrainte) sont journalisées.
- Désarmement juste après la fin du délai d'entrée : alarme notée « annulée ».
- Mode relu sous verrou (plus de réarmement sur une valeur périmée), « Armé
  partiel » reconnu, sabotages et alarmes de centrale restés en cours
  rattrapés par le cron, repos des suspicions glissant, horloge revenue en
  arrière sans effet sur le délai de sortie, acquittement refusé pendant le
  délai d'entrée, journal en clair.
- Interface : chargement sûr (plus de risque d'effacer les capteurs), valeur
  actuelle de chaque capteur avec son verdict, filtre du journal, erreurs
  affichées, réglages de délai de sortie nuit et d'annulation.

## 0.1 — version d'observation

- Suivi du mode d'une centrale existante (désarmé, total, nuit).
- Capteurs de toutes origines avec un rôle : parcours d'entrée, périmètre,
  intérieur, extérieur (caméra), centrale, sabotage ; actifs en total, en nuit
  ou les deux.
- Délais d'entrée et de sortie, exclusion des capteurs déjà en alerte à
  l'armement jusqu'à leur retour au repos.
- Niveaux : suspicion, délai d'entrée, alarme, alarme confirmée par un second
  capteur distinct dans une fenêtre réglable.
- Supervision : capteur sans remontée depuis N minutes, ou commande « en
  ligne » à 0 ; sabotage si l'alarme est armée, défaut sinon.
- Journal de ce que l'alarme aurait fait ; aucune sortie déclenchée.
