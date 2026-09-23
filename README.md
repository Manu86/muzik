# Muzik

Muzik est un lecteur de musique web auto-hébergé. Il indexe une bibliothèque locale dans SQLite, expose une API PHP et fournit une interface responsive installable comme PWA.

<p align="center">
  <a href="https://github.com/Manu86/muzik/actions"><img src="https://github.com/Manu86/muzik/actions/workflows/quality.yml/badge.svg" alt="CI"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/licence-MIT-blue.svg" alt="Licence MIT"></a>
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg" alt="PHP 8.1+">
  <a href="https://github.com/Manu86/muzik/releases"><img src="https://img.shields.io/github/v/tag/Manu86/muzik?label=release" alt="Dernière release"></a>
</p>

## Aperçu

<p align="center">
  <img src="docs/screenshots/muzik-albums.png" alt="Bibliothèque des albums de Muzik" width="100%">
</p>

<p align="center">
  <a href="docs/screenshots/muzik-artistes.png">
    <img src="docs/screenshots/muzik-artistes.png" alt="Artistes" width="15%">
  </a>
  <a href="docs/screenshots/muzik-albums.png">
    <img src="docs/screenshots/muzik-albums.png" alt="Albums" width="15%">
  </a>
  <a href="docs/screenshots/muzik-genres.png">
    <img src="docs/screenshots/muzik-genres.png" alt="Genres" width="15%">
  </a>
  <a href="docs/screenshots/muzik-selection-aleatoire.png">
    <img src="docs/screenshots/muzik-selection-aleatoire.png" alt="Sélection aléatoire" width="15%">
  </a>
</p>

<p align="center">
  <sub>Cliquez sur une capture pour l'afficher en grand.</sub>
</p>

## Fonctionnalités

- navigation par artistes, albums et genres ;
- recherche dans les artistes, albums et titres ;
- lecture directe avec prise en charge des requêtes HTTP `Range` ;
- transcodage MP3 à la volée avec FFmpeg ;
- file d’attente, lecture aléatoire et répétition ;
- favoris ;
- statistiques des pistes et albums les plus écoutés ;
- historique des écoutes récentes ;
- récupération de jaquettes et de genres depuis Deezer, MusicBrainz et iTunes ;
- installation sur mobile ou ordinateur grâce au manifeste PWA.

## Prérequis

- PHP 8.1 ou supérieur ;
- extensions PHP `pdo_sqlite`, `mbstring`, `json` et `fileinfo` ;
- Composer ;
- FFmpeg pour le transcodage ;
- `curl` pour les scripts d’enrichissement ;
- Python 3 et [Mutagen](https://mutagen.readthedocs.io/) uniquement pour écrire les tags audio.

## Installation

Au premier lancement (aucun compte n’existe encore dans `data/users.db`),
Muzik affiche une page d’installation à `http://…/install.php`. Elle permet de
:

- vérifier les prérequis (PHP, extensions, dépendances `vendor/`, FFmpeg,
  droits d’écriture sur `data/`) ;
- créer le compte utilisateur (identifiant et mot de passe) ;
- indiquer où se trouvent les fichiers de musique (`music_root`) ;
- lancer l’indexation initiale de la bibliothèque. L’indexation démarre en
  arrière-plan (`bin/scan.php --user <login>`) et se poursuit pendant que
  l’interface s’ouvre ; ses journaux sont dans `data/scan-<login>.log`.

Chaque compte possède **sa propre bibliothèque** : son identifiant, son mot de
passe (haché en bcrypt), sa racine musicale et sa base SQLite
(`data/<login>.db`) sont enregistrés dans la base des comptes `data/users.db`.
La protection par mot de passe est active dès qu’au moins un compte existe.

Une installation existante — unique utilisateur dans `config.local.php` (`auth_user`,
`auth_hash`, `music_root`, `db_path`) — est **migrée automatiquement** au premier
lancement : le compte et sa base (`data/muzik.db`) sont conservés tels quels.

### Gérer les comptes en ligne de commande

```bash
php bin/users.php add <login> <password> <music_root>   # crée un compte
php bin/users.php list [--json]                          # liste les comptes
php bin/users.php music-root <login> <chemin>            # change la racine musicale
php bin/users.php password <login> <nouveau>             # change le mot de passe
php bin/users.php delete <login>                         # supprime le compte
```

La suppression d’un compte ne supprime ni sa base SQLite ni ses fichiers.

La procédure manuelle reste possible :

```bash
composer install
php bin/users.php add <login> <password> /path/to/music
php bin/scan.php --user <login>
php -S 127.0.0.1:8080 -t public public/index.php
```

L’application est alors accessible sur `http://127.0.0.1:8080`.
Pour réinitialiser l’installation, supprimez `data/users.db` (les bases et
fichiers de musique sont conservés).

Aucune session n’est ouverte par défaut : `POST /api/login` établit une session,
`POST /api/logout` la ferme, et chaque route protégée renvoie
`401 {"error":"Unauthorized"}` tant qu’aucune session valide n’existe.

Le projet contient aussi une configuration Apache optionnelle pour une
installation sous `/muzik`, **sans** protection HTTP Basic en regard de
celle-ci :

```bash
cp config/apache-muzik.conf.example config/apache-muzik.conf
# Adapter les chemins
sudo bash bin/deploy-apache.sh
```

Le fichier `config/apache-muzik.conf` reste local et est ignoré par Git.
N'exposez pas l'application sur Internet sans HTTPS.

## Configuration

Les valeurs portables se trouvent dans [config/app.php](config/app.php).
[config.php](config.php) charge la configuration de base, puis surcharge,
lorsqu’il existe, le fichier local `config.local.php` :

```php
return [
    'music_root' => '/path/to/music',
    'db_path' => __DIR__ . '/data/muzik.db',
    'ffmpeg' => '/usr/bin/ffmpeg',
    'transcode' => 0,
    'auth_user' => '',
    'auth_hash' => '',
];
```

| Clé | Rôle |
|---|---|
| `ffmpeg` | Exécutable FFmpeg utilisé pour le transcodage |
| `transcode` | Débit par défaut en kbit/s, ou `0` pour la lecture directe |
| `music_root` | Racine musicale par défaut de la base de catalogue par défaut (`db_path`) |
| `db_path` | Base SQLite par défaut utilisée par les commandes historiques |
| `auth_user` / `auth_hash` | **Réservés à la migration** d’une installation unique vers `data/users.db` au premier lancement |

Avec plusieurs comptes, `music_root` et `db_path` sont choisis par utilisateur
dans `data/users.db` (`bin/users.php add …` ou la page d’installation) ; les
clés `auth_user`/`auth_hash` ne sont plus utilisées pour la protection (lue
uniquement lors de la migration automatique). `config.local.php` fournit les
valeurs globales (`ffmpeg`, `transcode`) sur la machine.

Créez éventuellement `config.local.php` à partir de
`config.local.example.php`. Ce fichier n'est jamais versionné : les choix
propres à une machine ne sont donc pas publiés.

Le répertoire `data/` est lui aussi ignoré, à l'exception de son fichier
`.gitkeep`. Les bases SQLite (`users.db`, `muzik.db`, `<login>.db`), les
journaux, les plans de tags et le catalogue local ne doivent jamais être
ajoutés au dépôt.

## Indexer la bibliothèque

```bash
# Scan incrémental d'un utilisateur : ajoute et met à jour les fichiers
php bin/scan.php --user <login>

# Scan complet : supprime aussi de SQLite les entrées dont le fichier a disparu
php bin/scan.php --user <login> --full
```

Sans `--user`, `bin/scan.php` traite le premier compte de `data/users.db`.
Les journaux de chaque exécution sont écrits dans `data/scan-<login>.log`.

Formats reconnus : MP3, FLAC, OGG, M4A et WAV.

Depuis l’interface, la vue « Réglages » (voir le compte connecté) permet de
modifier l’emplacement des fichiers musicaux du compte connecté : `PUT
/api/config` met à jour l’utilisateur dans `data/users.db`, recharge la
configuration et lance une indexation de cet utilisateur. Le même écran
relance un rescannage complet en arrière-plan (équivalent d’un
`bin/scan.php --full` : les pistes dont le fichier a disparu du disque sont
retirées de la base) ; son état (en cours ou dernière exécution) est affiché
en temps réel et visible via l’API `POST /api/scan`.

Titre, artiste, album, année et genre sont lus dans les tags ID3 des fichiers
MP3 (id3v2, repli id3v1). Le genre est normalisé dans une liste canonique
(`App::normalizeGenre()`), ce qui alimente l’onglet « Genres » dès la première
indexation — y compris pendant l’installation. Chaque catalogue démarre d'ailleurs
avec les genres canoniques déjà créés (base vide) : l'onglet n'est jamais vide,
même avant la première indexation (`data/<login>.db`, table `genres`). Les genres
issus des tags viennent compléter cette liste à l'indexation.

Le scanner privilégie les tags audio. Lorsque ceux-ci sont absents ou génériques, il déduit artiste, album, disque, numéro de piste et titre depuis l’arborescence et le nom du fichier.

## Enrichir les métadonnées

### Jaquettes

```bash
php bin/fetch-art.php
php bin/fetch-art.php --limit 10
```

Les sources sont interrogées dans l’ordre suivant : Deezer, MusicBrainz avec Cover Art Archive, puis iTunes. Les images sont enregistrées près des fichiers musicaux sous le nom `cover.jpg` ou `cover.png`.

Sans image dans le dossier, le scanner **extrait la pochette embarquée** (APIC
ID3v2, `covr` MP4…) du premier morceau et l’enregistre dans `data/art/`. Le
nom de fichier découle du dossier : un même album produit toujours le même
chemin, l’extraction est donc idempotente.

Dans la vue des artistes, une jaquette de l'album comportant le plus de pistes
est utilisée automatiquement lorsqu'aucune photo d'artiste n'est disponible.

### Images d'artistes

```bash
# Recherche stricte sur Deezer, sans téléchargement ni modification de la base
php bin/fetch-artist-art.php

# Nouvelle tentative des recherches restées sans résultat
php bin/fetch-artist-art.php --force

# Téléchargement des correspondances exactes et mise à jour de SQLite
php bin/fetch-artist-art.php --apply
```

L'outil ne recherche que les artistes qui n'ont ni portrait ni jaquette
d'album utilisable. Les noms génériques comme `Classique`, `Jazz` ou `BO`
sont ignorés afin d'éviter les images sans rapport. La simulation ouvre
SQLite en lecture seule, ce qui la rend compatible avec un dépôt monté par SMB.

### Genres

```bash
# Simulation et génération du plan
php bin/tag.php

# Écriture dans les fichiers audio et dans SQLite
php bin/tag.php --apply
```

### Albums inconnus

```bash
php bin/album-resolve.php
php bin/album-resolve.php --apply
```

### Renommer un album (nom, année)

Sur la page d'un album, le bouton **✏ Modifier** met à jour immédiatement la base
via `PATCH /api/album/{id}`. Pour répercuter le changement dans les tags des
fichiers audio, lancer ensuite :

```bash
# Simulation (plan généré, aucun tag écrit)
php bin/album-edit.php <id>

# Écriture des tags ID3/Vorbis/MP4
php bin/album-edit.php <id> --apply
```

Sans `--apply`, les scripts de tags fonctionnent en mode simulation. Avec `--apply`, ils modifient les tags des fichiers musicaux. Sauvegardez la bibliothèque avant une opération massive.

Sur une installation multi-comptes, toutes les commandes d’enrichissement
(`fetch-art.php`, `fetch-artist-art.php`, `tag.php`, `album-resolve.php`,
`album-edit.php`, `fix-titles.php`, `year-resolve.php`) ciblent le premier compte
de `data/users.db` par défaut ; passez `--user <login>` pour en traiter un
autre.

## Développement et qualité

```bash
composer test      # PHPUnit
composer lint      # syntaxe de tous les fichiers PHP
composer analyse   # PHPStan niveau 10
composer cs-check  # vérifie le format PER-CS 2.0
composer cs-fix    # corrige le format
composer quality   # exécute tous les contrôles précédents
```

Les tests utilisent uniquement des bases et fichiers sous le répertoire temporaire du système. Ils ne doivent jamais accéder à `data/muzik.db`, `data/users.db` ni à la bibliothèque configurée.

## Publier le dépôt

Les données locales, configurations privées et fichiers d'authentification
sont exclus par `.gitignore`. Vérifiez toutefois toujours la sélection avant un
premier commit :

```bash
git init
# Recommandé lorsque le projet se trouve sur un partage SMB
git config core.fileMode false
git branch -M main
git add .
git status
git diff --cached --summary
```

Les fichiers `config.local.php`, `config/apache-muzik.conf`,
`data/muzik.db` et `data/users.db` ne doivent jamais apparaître dans la liste.
La CI GitHub exécute la validation Composer et `composer quality` avec PHP 8.1
et PHP 8.4.

## Architecture

```text
public/index.php        point d’entrée HTTP et fichiers statiques
public/assets/js/app.js point d’entrée de l’interface JavaScript
public/assets/js/core.js état partagé, API et utilitaires du navigateur
public/assets/js/favorites.js état et actions des favoris
public/assets/js/views.js navigation et vues du catalogue
public/assets/js/player.js lecteur audio, file d’attente et préchargement
public/assets/js/account.js authentification et réglages
public/install.php      page d’installation au premier lancement
config.php              charge la configuration publique puis locale
config/app.php          valeurs portables versionnées
config.local.php        valeurs globales propres à la machine, ignorées par Git
src/Router.php          routage HTTP
src/Api.php             API du catalogue et de la lecture
src/Users.php           comptes utilisateurs (data/users.db) et bases par compte
src/Auth.php            authentification par session
src/Installer.php       contrôle des prérequis et création du premier compte
src/Streamer.php        streaming direct et transcodage
src/DB.php              connexion, schéma et migrations SQLite
src/Scanner.php         indexation de la bibliothèque
bin/                    commandes d’administration (dont bin/users.php)
data/                   bases (users.db, muzik.db, <login>.db), pochettes extraites (art/), caches, journaux
tests/                  tests PHPUnit
```

Le schéma SQLite contient six tables principales : `artists`, `albums`, `songs`, `favorites`, `settings` et `genres`. Les suppressions d’artistes et d’albums sont propagées par clés étrangères. Les jaquettes d’albums et d’artistes sont référencées par `art_path` (fichier image ou pochette extraite de `data/art/`).

## API

L’API est accessible sous `api/` depuis la racine de l’application. Exemples :

```bash
curl http://127.0.0.1:8080/api/summary
curl 'http://127.0.0.1:8080/api/search?q=Massive%20Attack'
curl http://127.0.0.1:8080/api/album/42
curl -H 'Range: bytes=0-1048575' http://127.0.0.1:8080/api/stream/123
```

La description exhaustive et lisible par les outils OpenAPI se trouve dans [openapi.yaml](openapi.yaml).

Points importants :

- `PUT /api/config` change l’emplacement des musiques du **compte connecté** dans `data/users.db` depuis la vue « Réglages » ;
- `DELETE /api/album/{id}` supprime réellement les pistes et les jaquettes situées sous `music_root` ;
- l’ajout et le retrait des favoris utilisent encore des paramètres sur `GET /api/favorites` ;
- la mise à jour d’un réglage utilise encore `GET /api/settings?set_key=...&value=...` ;
- l’authentification est gérée par PHP : sessions et hachage bcrypt des comptes de `data/users.db` (`bin/users.php`), les clés `auth_user`/`auth_hash` de `config.local.php` ne servent qu'à la migration initiale, et la protection Apache HTTP Basic n’est plus utilisée ;
- le service worker conserve l’interface hors ligne, mais pas le catalogue ni les morceaux.

## Documentation pour les contributeurs automatisés

Les consignes destinées aux agents de développement sont dans [AGENTS.md](AGENTS.md). Elles précisent les limites de sécurité, les commandes de validation et les conventions propres au dépôt.

Les propositions de modification sont décrites dans
[CONTRIBUTING.md](CONTRIBUTING.md). Les failles doivent être signalées selon
[SECURITY.md](SECURITY.md), sans publier de données personnelles.

## Licence

Muzik est distribué sous licence **MIT** (voir [LICENSE](LICENSE)). Vous pouvez
l'utiliser, le modifier et le redistribuer librement, à condition de conserver
la notice de copyright.
