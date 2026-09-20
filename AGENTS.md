# AGENTS.md — guide de travail pour Muzik

Ce fichier s’applique à tout le dépôt.

## Mission du projet

Muzik est un lecteur musical PHP/SQLite sans framework. Le backend indexe des fichiers locaux, expose une API JSON et diffuse l’audio. Le frontend est une SPA/PWA en JavaScript natif.

Avant une modification, lire au minimum `README.md`, `composer.json` et les fichiers concernés. La spécification contractuelle des routes se trouve dans `openapi.yaml`.

## Carte du code

- `public/index.php` initialise l’application, sert les fichiers statiques et délègue au routeur.
- `src/App.php` contient la configuration globale, le PDO et les réponses JSON.
- `src/DB.php` crée et migre le schéma SQLite.
- `src/Router.php` contient la liste des routes et leur dispatch explicite.
- `src/Api.php` exécute les requêtes SQL et construit les réponses.
- `src/Streamer.php` traite les ranges HTTP et le transcodage FFmpeg.
- `src/Scanner.php` parcourt `music_root` et interprète tags, dossiers et noms de fichiers.
- `public/assets/app.js` contient l’état, le routage par hash, les vues et le lecteur.
- `config/app.php` contient les valeurs portables ; `config.local.php`, ignoré
  par Git, surcharge la configuration sur la machine de l'utilisateur.
- `bin/` contient les commandes ayant accès à la bibliothèque réelle et aux services externes.
- `tests/` contient les tests PHPUnit isolés dans `/tmp`.
- `data/` contient des données locales, journaux, caches et sauvegardes ; ce n’est pas du code source.

## Contraintes de sécurité

- Ne jamais lancer `php bin/scan.php --full` sans demande explicite : il supprime de la base les pistes absentes.
- Ne jamais lancer `bin/tag.php --apply`, `bin/album-resolve.php --apply` ou
  `bin/album-edit.php --apply` sans demande explicite : ils modifient les
  fichiers audio.
- Ne jamais appeler `DELETE /api/album/{id}` sur l’instance réelle pendant un test : cette route efface des fichiers.
- Ne pas écrire dans `data/muzik.db` depuis les tests. Utiliser une base unique sous `/tmp`.
- Ne pas interroger Deezer, MusicBrainz ou iTunes dans les tests unitaires. Simuler les dépendances réseau.
- Préserver les fichiers et modifications existants dans `data/`, même s’ils semblent générés.
- Ne jamais versionner `config.local.php`, `config/apache-muzik.conf`
  ni un autre fichier contenant des chemins ou identifiants locaux.

## Conventions de code

- PHP minimum : 8.1.
- Style PHP : PER-CS 2.0, appliqué par `.php-cs-fixer.php`.
- Le projet n’utilise ni namespace applicatif ni framework ; ne pas en introduire partiellement.
- Utiliser PDO avec des requêtes préparées pour toute donnée externe.
- Conserver `declare(strict_types=1)` dans les nouveaux fichiers PHP.
- Échapper tout contenu injecté dans le HTML côté navigateur.
- Pour une nouvelle route, mettre à jour ensemble `src/Router.php`, `src/Api.php`, `openapi.yaml` et les tests.
- Pour une modification du schéma, assurer à la fois la création à neuf et la migration idempotente dans `DB::schema()`.
- Les réponses d’erreur JSON suivent la forme `{ "error": "message" }`.

## Tests

Commande rapide après une modification fonctionnelle :

```bash
composer test
```

Validation obligatoire avant de terminer une tâche :

```bash
composer quality
```

Cette commande exécute le lint PHP, PHPUnit, PHPStan et PHP-CS-Fixer en mode vérification.

Les tests héritent de `tests/Support/TestCase.php`. Cette classe :

- crée un répertoire temporaire unique ;
- intercepte les réponses `App::json()` avec une exception dédiée ;
- remet à zéro les superglobales HTTP ;
- détruit les fichiers temporaires après le test.

Pour tester le streaming ou FFmpeg, utiliser de petits fichiers factices et un faux exécutable. Ne pas dépendre des codecs ou de la bibliothèque musicale de la machine.

## Particularités de l’environnement

Le dépôt peut être monté via SMB. Ce système de fichiers ne prend pas toujours en charge `chmod` et rend l’analyse séquentielle plus fiable :

- appeler les outils Composer avec `@php vendor/bin/...` ;
- garder PHPStan et PHP-CS-Fixer en mode séquentiel ;
- placer leurs caches sous `/tmp` ou les désactiver ;
- ne pas interpréter les avertissements `Operation not supported` de Composer comme des erreurs si l’installation se termine correctement.

## Définition de terminé

Une modification est terminée lorsque :

1. le comportement demandé est implémenté ;
2. les tests couvrent le chemin nominal et les erreurs importantes ;
3. la documentation humaine et OpenAPI est mise à jour si le contrat change ;
4. `composer quality` réussit ;
5. aucun fichier musical ou contenu de production n’a été modifié involontairement.
