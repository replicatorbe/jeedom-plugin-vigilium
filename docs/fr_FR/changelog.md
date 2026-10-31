# Changelog Vigilium

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
