# Vigilium

Vigilium fait d'un ensemble de capteurs de toutes origines une seule alarme :
une centrale existante donne le mode d'armement, et chaque capteur — contact
de la centrale, caméra, capteur Bluetooth — reçoit un rôle qui dit comment il
doit réagir.

> **Observation par défaut.** Une alarme neuve n'exécute rien : le journal dit
> ce qu'elle aurait fait, réactions comprises. On la passe en « Actif » dans
> l'onglet Réactions une fois ce journal relu.

## Principe

- **La centrale reste maîtresse.** Vigilium n'arme ni ne désarme jamais : il
  lit le mode de la centrale. Si Jeedom s'arrête, la centrale continue de
  protéger la maison avec ses propres capteurs.
- **Un rôle par capteur**, et non une liste de scénarios.
- **Deux capteurs distincts valent mieux qu'un.** Une alarme est « confirmée »
  quand un second capteur, différent du premier, se déclenche dans la fenêtre
  de confirmation — par exemple une caméra qui a vu quelqu'un, puis une
  fenêtre qui s'ouvre.

## Configuration de l'alarme

| Champ | Rôle |
|---|---|
| Commande du mode | L'information de la centrale qui dit désarmé, armé ou nuit. |
| Valeurs « armé total », « mode nuit », « armé partiel », « désarmé » | Le texte exact de ces modes ; vides, ils valent « Armé », « Mode nuit », « Armé partiel » et « Désarmé » (les libellés d'Ajax). L'armement partiel est lu comme le mode nuit. La casse et les espaces autour sont ignorés. **Seule la valeur « désarmé » désarme** : une valeur inconnue — vide pendant un redémarrage du plugin de la centrale, libellé renommé — garde le mode précédent et écrit un défaut au journal. |
| Délai d'entrée | Secondes laissées pour désarmer après l'ouverture d'un capteur « parcours d'entrée ». 30 par défaut. |
| Délai de sortie | Secondes, après l'armement, pendant lesquelles les capteurs « parcours d'entrée », « intérieur » et « extérieur » sont ignorés. 60 par défaut. Ce qui est encore ouvert à la fin du délai compte alors comme une ouverture : la porte laissée ouverte lance le délai d'entrée. |
| Délai de sortie en mode nuit | Le même, pour le mode nuit, qui s'arme d'ordinaire maison occupée. Vide : comme le mode total. 0 recommandé si le mode nuit s'arme tout seul. |
| Annulation après désarmement | Une alarme née de la fin du délai d'entrée, suivie d'un désarmement dans ce délai (120 s par défaut), est notée « annulée, très probablement un retour normal » : la centrale ou la balise a été plus lente que le délai. |
| Fenêtre de confirmation | Secondes pendant lesquelles un second capteur confirme une alarme. 300 par défaut. |
| Repos entre deux suspicions | Repos glissant : tant qu'une caméra continue de voir du mouvement, une seule suspicion ; la suivante ne vient qu'après un silence aussi long. |

## Capteurs

| Rôle | Réaction, alarme armée |
|---|---|
| Parcours d'entrée | Délai d'entrée ; alarme s'il expire sans désarmement. Ignoré pendant le délai de sortie. |
| Périmètre | Alarme immédiate. |
| Intérieur | Suit le délai d'entrée s'il court (le chemin de qui rentre), sinon alarme. Ignoré pendant le délai de sortie. |
| Extérieur | Une caméra : suspicion seule, ou confirmation d'une alarme en cours. |
| Centrale | L'alarme déclarée par la centrale : alarme immédiate, ses délais sont déjà appliqués. Elle ne sert jamais de confirmation (c'est d'ordinaire l'écho d'un contact déjà compté) et n'est jamais exclue. Désarmé, elle s'écrit quand même au journal : incendie, panique, désarmement sous contrainte. |
| Sabotage | Autoprotection, caméra masquée, brouillage : décision de sabotage. |

Pour chaque capteur :

- **Valeur d'alerte** : la valeur qui veut dire « déclenché ». 1 pour un
  détecteur ; 0 pour un contact dont l'information vaut 1 quand il est fermé.
- **Total / Nuit** : les modes où il est surveillé. En mode nuit, on retire
  d'ordinaire les détecteurs des pièces où l'on dort.
- **Muet après (min)** : au-delà de ce temps sans aucune remontée, le capteur
  est déclaré muet. Vide : pas de supervision par le temps. **Seulement pour
  un capteur qui remonte régulièrement** (pile, température, battement) : un
  contact qui ne parle qu'à l'ouverture serait déclaré muet après ce temps de
  calme.
- **Commande « en ligne »** : une information qui vaut 0 quand l'appareil est
  injoignable.

Un capteur muet alarme armée est un **sabotage** possible ; désarmé, c'est un
**défaut** à réparer. Un capteur « sabotage » veille 24 h/24 : désarmé, il
s'inscrit comme défaut.

Une commande de capteur supprimée (réinstallation d'un plugin qui change les
identifiants) est signalée comme défaut, et l'écouteur est vérifié chaque
minute.

Un capteur déjà en alerte à l'armement — une fenêtre restée ouverte — est
**exclu** jusqu'à son retour au repos, et le journal le dit, avec la date
depuis laquelle il est en alerte quand elle est ancienne.

À l'armement, ce qui était déjà en défaut — sabotage en cours, capteur muet,
commande introuvable — est rappelé en **sabotage** : « Armé malgré : … ».

Passer d'un mode armé à l'autre (total vers nuit) **garde** l'incident en
cours et ne relance pas le délai de sortie. Un délai d'entrée en cours continue jusqu'à son terme,
même si son capteur n'est pas surveillé dans le nouveau mode.

### Capteurs Shelly BLU (Bluetooth)

Un contact Shelly BLU Door/Window arrive par mqttbe comme un équipement
Bluetooth. Pour en faire un capteur d'alarme :

1. **Chiffrez-le** dans l'application Shelly et déclarez sa clé dans mqttbe
   (configuration, « Clés BTHome ») : en clair, n'importe quel émetteur
   Bluetooth pourrait imiter ou rejouer ses trames — ouvrir ou refermer la
   fenêtre aux yeux de l'alarme.
2. **Commande** : son information « window » (ou « door »), **valeur
   d'alerte 1** (1 = ouvert en BTHome).
3. **Rôle** : périmètre, ou parcours d'entrée pour une porte par laquelle on
   rentre. Décochez « Nuit » pour une fenêtre de l'étage où l'on dort.
4. **Supervision** : comme commande « en ligne », sa **présence** dans mqttbe,
   qui passe à 0 quand plus aucune passerelle ne l'a entendu. Pour un capteur
   chiffré, seule une trame authentifiée compte : l'imiter ne le garde pas
   « présent ». Un contact qui ne parle qu'à l'ouverture ne se prête pas à
   « Muet après » ; vérifiez aussi que le délai d'absence de mqttbe est plus
   long que l'intervalle entre deux trames du capteur au repos.

Le Bluetooth se brouille facilement : placez au moins deux passerelles à portée
de chaque capteur, et gardez la supervision — un capteur muet alarme armée est
un sabotage.

## Réactions

L'onglet **Réactions** dit ce que l'alarme fait de ses décisions : une liste
d'actions par déclencheur, avec le même sélecteur que les scénarios (une
commande, ou un bloc : message, scénario, variable…).

| Déclencheur | Quand |
|---|---|
| Suspicion | Une caméra a vu quelqu'un, alarme armée, sans autre détection. |
| Alarme | Un capteur a déclenché (ou la centrale, même désarmée : incendie, panique, contrainte). |
| Alarme confirmée | Un second capteur, différent, a confirmé l'alarme. |
| Relance | Rejouée toutes les N minutes, au plus M fois, tant que l'alarme n'est ni désarmée ni acquittée. |
| Fin d'incident | L'alarme se termine : désarmement, acquittement, retour au repos d'une alarme de centrale reçue désarmée, ou désactivation de l'alarme pendant un incident. |
| Sabotage | Autoprotection, brouillage, caméra masquée, capteur muet, alarme armée ; « Armé malgré ». |
| Défaut | Capteur muet désarmé, commande introuvable, mode illisible. |

**Exécution** : « Observation » (par défaut) écrit chaque action au journal,
« Aurait exécuté (alarme) : … », sans rien exécuter, pas même une photo.
« Actif » exécute. Le bouton **Tester** d'une liste la joue pour de vrai,
après confirmation, avec un texte d'essai, sans toucher à l'état de l'alarme.

**Photos** : avant les réactions de suspicion, d'alarme et d'alarme
confirmée **qui citent `#photo#` ou `#photos#`**, chaque caméra réglée est
photographiée (commande de capture), puis le chemin de l'image est lu
(commande d'information) — pour une caméra du plugin dahua : « Capturer une
image » et « Fichier de l'image ». Les captures sont bornées à 20 secondes
au total : au-delà, la dernière image connue des caméras restantes sert.
Une alarme et sa confirmation dans le même événement partagent les photos.

Les réactions s'exécutent dans l'ordre où elles ont été décidées : une fin
d'incident ne peut pas passer avant l'alarme qu'elle termine.

La confirmation d'une alarme déjà relancée ne remet pas le compteur de
relances à zéro. Passer de « Actif » à « Observation » pendant une alarme
laisse en place ce qui a été déclenché : la fin d'incident n'est alors plus
qu'écrite au journal.

**Jetons** dans les titres et messages : `#detail#` (la décision en clair),
`#niveau#`, `#mode#`, `#capteur#`, `#alarme#`, `#declencheur#`, `#photo#`
(la première image), `#photos#` (toutes, séparées par des virgules). Pour une
notification JeedomConnect avec image : `title=Intrusion | files=#photo#`.

Une action en échec n'empêche pas les suivantes ; l'échec d'une commande est
écrit au journal et au log. L'échec d'un bloc (message, scénario, variable)
ou d'une action en tâche de fond est rattrapé par Jeedom lui-même et n'apparaît
que dans son log « scenario ». Les blocs qui attendent (pause, attente, question) sont
refusés : ils retiendraient l'alarme.

## Sirènes

Les listes d'actions ne savent ni arrêter une sirène au bout d'un temps, ni la
retarder : les sirènes ont leur propre réglage, dans l'onglet Réactions.

| Champ | Rôle |
|---|---|
| Mise en marche / Arrêt | Deux commandes d'action. Sans arrêt, l'alarme ne peut pas couper la sirène : elle s'arrête d'elle-même. |
| Durée max | 180 s par défaut, 900 s au plus. Toujours bornée : une sirène qui sonne jusqu'au retour des occupants finit débranchée. |
| Retard | Secondes entre la décision et la mise en marche : par exemple, la sirène extérieure 60 s après la confirmation. |
| Alarme / Confirmée / Sabotage | Les niveaux qui la mettent en marche. Sabotage : seulement pour un capteur de rôle Sabotage, alarme armée — pas pour un capteur muet ni un « Armé malgré ». |
| Sauf centrale | Pas quand la centrale a déclaré l'alarme de cet incident, même si la décision suivante vient d'un autre capteur ; un retard en cours est annulé quand elle la déclare. C'est le réglage d'une action PANIC envoyée à la centrale. |

Une sirène sonne une fois par incident : arrivée au bout de sa durée, elle ne
repart pas tant que l'incident n'est pas terminé (désarmement, acquittement) —
une alarme laissée sans réponse pendant des heures ne refait pas sonner les
sirènes à une nouvelle détection.

L'arrêt utilise la commande enregistrée à la mise en marche : retirer ou
modifier une sirène pendant qu'elle sonne n'envoie pas l'arrêt à une autre.
Une sirène réellement mise en marche est arrêtée pour de vrai même si
l'alarme est repassée en observation entre-temps.

Les retards et les durées sont constatés par le battement du cron, à la
minute près : un retard de 10 s peut devenir une minute. Si Jeedom s'arrête,
plus rien n'arrête la sirène : réglez aussi une durée de coupure sur
l'appareil (durée de sirène de la caméra, extinction automatique du relais).

Tout s'arrête — sirènes en marche et
retards en cours — au désarmement, à l'acquittement, à la désactivation ou à
la suppression de l'alarme. Une alarme de centrale reçue désarmée (incendie,
panique) ne met aucune sirène de Vigilium en marche : celles de la centrale
sonnent déjà.

Le bouton **Tester** d'une sirène la met en marche 3 secondes, pour de vrai,
puis l'arrête — sauf si elle sonne pour une alarme en cours.

## Signal de vie

Une action envoyée toutes les N minutes tant que l'alarme tourne — par exemple
l'impulsion d'un relais câblé sur une entrée de la centrale : si Jeedom
s'arrête, le signal cesse et la centrale le voit. Il n'est jamais écrit au
journal et ne part jamais en observation : une centrale qui surveille ce
signal verra alors Jeedom comme arrêté.

## Commandes

| Commande | Contenu |
|---|---|
| Mode | Désarmé, Total ou Nuit, tel que lu sur la centrale. |
| Niveau | Calme, Suspicion, Délai d'entrée, Alarme, Alarme confirmée. |
| Dernier événement | La dernière décision, en clair. |
| Capteurs exclus | Les capteurs exclus à l'armement. |
| Capteurs muets | Leur nombre. |
| Acquitter | Ramène le niveau au calme sans toucher à l'armement. |

Les commandes « Niveau » et « Dernier événement » sont de vraies
informations : un scénario branché dessus agit, même pendant l'observation.

Une valeur de mode qui reste inconnue garde indéfiniment le dernier mode connu
(un seul défaut est écrit) : si la centrale publie un libellé nouveau, le
renseigner dans le champ correspondant.

## Journal

L'onglet Journal liste les décisions, les plus récentes en premier. Les
genres « Suspicion », « Alarme », « Confirmée » et « Sabotage » sont ceux qui
déclencheront une sortie dans les versions suivantes ; « Défaut » signale ce
qui empêche l'alarme de bien voir.

Les décisions sont aussi écrites dans le log `vigilium`, visible si son
niveau est réglé sur « info » ou plus dans la configuration du plugin. Le
journal de l'onglet est, lui, toujours écrit.

Le temps est vérifié à chaque événement et chaque minute : la fin d'un délai
d'entrée sans autre événement est constatée avec au plus une minute de
retard.
