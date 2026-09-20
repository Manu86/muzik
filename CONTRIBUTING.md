# Contribuer à Muzik

Merci de l'intérêt porté à Muzik.

## Installation de développement

```bash
composer install
cp config.local.example.php config.local.php
```

Adaptez `config.local.php` à votre machine. Les tests n'utilisent ni cette
configuration, ni la bibliothèque musicale réelle.

## Proposer une modification

1. Créez une branche dédiée.
2. Ajoutez ou adaptez les tests avec le comportement modifié.
3. Mettez à jour `README.md` et `openapi.yaml` si l'interface change.
4. Exécutez `composer quality`.
5. Ouvrez une pull request courte, avec son objectif et la méthode de test.

Ne joignez jamais de fichier musical, base SQLite réelle, journal, ou
configuration locale à une issue ou une pull request.

Les commandes `bin/tag.php --apply`, `bin/album-resolve.php --apply`,
`bin/album-edit.php --apply` et `php bin/scan.php --full` peuvent modifier des
données. Utilisez-les uniquement sur une bibliothèque sauvegardée.

## Style

- PHP 8.1 minimum et types stricts pour les nouveaux fichiers PHP ;
- style PER-CS 2.0 ;
- requêtes PDO préparées pour les données externes ;
- pas d'appel réseau réel dans les tests.

Les détails destinés aux outils automatisés sont dans `AGENTS.md`.
