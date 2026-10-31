# Vigilium

Plugin Jeedom qui coordonne une alarme multiprotocole autour d'une centrale
existante.

La centrale (Ajax, par exemple) reste maîtresse de l'armement et de ses propres
sirènes. Vigilium suit son mode, donne un rôle à chaque capteur — contacts,
détecteurs, caméras, capteurs Bluetooth — et décide comme le ferait une
centrale : délais d'entrée et de sortie, exclusion des ouvertures restées
ouvertes à l'armement, confirmation d'une intrusion par un second capteur
indépendant, supervision des capteurs muets.

**Version d'observation.** Cette première version ne déclenche aucune sortie :
elle écrit au journal ce qu'elle aurait fait. Les sorties (sirènes,
notifications, lumières) viendront une fois ce journal relu.

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php                    # moteur, hors ligne
php tests/check-classes.php          # pièges du cœur, Jeedom requis
php tests/check-classes.php --statique
```

Licence AGPL v3.
