<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Réservé à la ligne de commande\n");
}

/**
 * Gestion des comptes Muzik (base méta data/users.db).
 *
 * Usage :
 *   php bin/users.php add <login> <password> <music_root>
 *   php bin/users.php list
 *   php bin/users.php music-root <login> <nouveau_chemin>
 *   php bin/users.php password <login> <nouveau_password>
 *   php bin/users.php delete <login>
 *
 * Options :
 *   --json   sortie JSON (list)
 *
 * Les mots de passe sont hachés (bcrypt) et ne sont jamais stockés en clair.
 * La suppression d'un compte ne supprime ni sa base ni ses fichiers.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/Users.php';

$argv = $_SERVER['argv'] ?? [];
$args = is_array($argv) ? $argv : [];
$json = in_array('--json', $args, true);
$args = array_values(array_filter($args, static fn(string $a): bool => $a !== '--json'));
$command = $args[1] ?? '';

try {
    switch ($command) {
        case 'add':
            if (count($args) < 5) {
                exit("Usage : php bin/users.php add <login> <password> <music_root>\n");
            }
            $login = Users::normalizeLogin($args[2]);
            if ($login === '') {
                exit("Identifiant invalide : {$args[2]}\n");
            }
            Users::create($login, (string) $args[3], (string) $args[4]);
            echo "Compte « {$login} » créé.\n";
            echo 'Base catalogue : ' . Users::defaultDatabasePath($login) . "\n";
            echo "Indexez avec : php bin/scan.php --user {$login}\n";
            break;

        case 'list':
            $users = Users::all();
            if ($json) {
                $rows = array_map(static fn(array $u): array => [
                    'login' => $u['login'],
                    'music_root' => $u['music_root'],
                    'db_path' => $u['db_path'],
                ], $users);
                echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
                break;
            }
            if ($users === []) {
                echo "Aucun compte. Créez-en un avec : php bin/users.php add <login> <password> <music_root>\n";
                break;
            }
            printf("%-16s %-40s %s\n", 'LOGIN', 'MUSIC_ROOT', 'DB_PATH');
            foreach ($users as $u) {
                printf("%-16s %-40s %s\n", $u['login'], $u['music_root'], $u['db_path']);
            }
            break;

        case 'music-root':
            if (count($args) < 4) {
                exit("Usage : php bin/users.php music-root <login> <nouveau_chemin>\n");
            }
            $login = (string) $args[2];
            if (Users::profile($login) === null) {
                exit("Compte inconnu : {$login}\n");
            }
            $path = (string) $args[3];
            if (!is_dir($path) || !is_readable($path)) {
                exit("Le dossier doit exister et être lisible : {$path}\n");
            }
            Users::updateMusicRoot($login, $path);
            echo "music_root de « {$login} » → {$path}\n";
            break;

        case 'password':
            if (count($args) < 4) {
                exit("Usage : php bin/users.php password <login> <nouveau_password>\n");
            }
            $login = (string) $args[2];
            if (Users::profile($login) === null) {
                exit("Compte inconnu : {$login}\n");
            }
            Users::updatePassword($login, (string) $args[3]);
            echo "Mot de passe de « {$login} » mis à jour.\n";
            break;

        case 'delete':
            if (count($args) < 3) {
                exit("Usage : php bin/users.php delete <login>\n");
            }
            $login = (string) $args[2];
            if (Users::profile($login) === null) {
                exit("Compte inconnu : {$login}\n");
            }
            Users::delete($login);
            echo "Compte « {$login} » supprimé.\n";
            echo "Les fichiers (base catalogue, musiques) sont conservés.\n";
            break;

        default:
            echo "Usage : php bin/users.php <add|list|music-root|password|delete> [args]\n";
            echo "  add <login> <password> <music_root>\n";
            echo "  list [--json]\n";
            echo "  music-root <login> <nouveau_chemin>\n";
            echo "  password <login> <nouveau_password>\n";
            echo "  delete <login>\n";
            break;
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, 'Erreur : ' . $throwable->getMessage() . "\n");
    exit(1);
}
