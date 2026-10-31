# Vigilium

Plugin Jeedom qui coordonne une alarme multiprotocole autour d'une centrale
existante.

La centrale (Ajax, par exemple) reste maîtresse de l'armement et de ses propres
sirènes. Vigilium suit son mode, donne un rôle à chaque capteur — contacts,
détecteurs, caméras, capteurs Bluetooth — et décide comme le ferait une
centrale : délais d'entrée et de sortie, exclusion des ouvertures restées
ouvertes à l'armement, confirmation d'une intrusion par un second capteur
indépendant, supervision des capteurs muets.

Les réactions — notifications avec photos, lumières, sirènes, relances — se
règlent par listes d'actions. Une alarme neuve est en **observation** : elle
écrit au journal ce qu'elle aurait fait, sans rien exécuter, jusqu'à ce qu'on
la passe en « Actif ».

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php                    # moteur, hors ligne
php tests/check-classes.php          # pièges du cœur, Jeedom requis
php tests/check-classes.php --statique
```

Licence AGPL v3.
