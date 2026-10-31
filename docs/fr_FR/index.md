# Vigilium

Vigilium fait d'un ensemble de capteurs de toutes origines une seule alarme :
une centrale existante donne le mode d'armement, et chaque capteur — contact
de la centrale, caméra, capteur Bluetooth — reçoit un rôle qui dit comment il
doit réagir.

> **Version d'observation.** Aucune sortie n'est déclenchée. Le journal dit ce
> que l'alarme aurait fait ; c'est en le relisant pendant quelques semaines
> qu'on décide de lui confier sirènes et notifications.

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
cours et ne relance pas le délai de sortie.

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
