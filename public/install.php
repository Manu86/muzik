<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../vendor/autoload.php') || !is_dir(__DIR__ . '/../vendor')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Installation · Muzik</title>
</head>
<body style="font-family:system-ui,sans-serif;background:#121212;color:#e8e8e8;line-height:1.6;margin:0;padding:2rem 1rem;">
  <main style="max-width:40rem;margin:0 auto;">
    <img class="logo" src="assets/icon-192.png?v=3" alt="Muzik" width="128" height="128"
         style="display:block;width:128px;height:128px;margin:0 auto 1.25rem;border-radius:24px;">
    <h1 style="color:#27d397;text-align:center;">Dépendances manquantes</h1>
    <p>Les dépendances PHP (<code>vendor/</code>) ne sont pas installées. Le script
    d'installation ne peut pas démarrer sans elles.</p>
    <p>Depuis le terminal, à la racine du projet :</p>
    <pre style="background:#1e1e1e;border:1px solid #2e2e2e;border-radius:8px;padding:1rem;">composer install</pre>
    <p>Puis rechargez la page <a href="./install.php" style="color:#27d397;">./install.php</a>.</p>
  </main>
</body>
</html>
    <?php
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/App.php';
require __DIR__ . '/../src/Users.php';
require __DIR__ . '/../src/Installer.php';
require __DIR__ . '/../src/Scanner.php';

$base = require __DIR__ . '/../config.php';
$projectRoot = dirname(__DIR__);

Users::ensureSchema($projectRoot);

if (Installer::installed($projectRoot)) {
    header('Location: ./');
    exit;
}

/** @var list<string> $errors */
$errors = [];
/** @var array{added: ?int, scan_error: ?string, background: bool}|null $result */
$result = null;

$postUser = trim((string) ($_POST['user'] ?? ''));
$postMusicRoot = trim((string) ($_POST['music_root'] ?? ''));
$postPassDefined = isset($_POST['pass']);
$adminPass = $postPassDefined ? (string) $_POST['pass'] : '';
$adminPass2 = array_key_exists('pass2', $_POST) ? (string) $_POST['pass2'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (Users::normalizeLogin($postUser) === '') {
        $errors[] = 'Identifiant invalide (minuscules, chiffres, tirets, 1 à 32 caractères).';
    } elseif ($postUser === '') {
        $errors[] = 'Indiquez un nom d\'utilisateur.';
    }

    if (!is_string($postMusicRoot) || $postMusicRoot === '') {
        $errors[] = 'Indiquez le dossier contenant vos fichiers de musique.';
    } elseif (!is_dir($postMusicRoot) || !is_readable($postMusicRoot)) {
        $errors[] = 'Le dossier de musique doit exister et être lisible par le serveur.';
    }

    if (mb_strlen($adminPass) < 8) {
        $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = 'Le mot de passe et sa confirmation diffèrent.';
    }

    if ($errors === []) {
        try {
            Users::create($postUser, $adminPass, $postMusicRoot);
            $createdLogin = Users::normalizeLogin($postUser);

            if (Installer::startBackgroundScan($projectRoot, $createdLogin)) {
                $result = ['added' => null, 'scan_error' => null, 'background' => true];
            } else {
                App::initConfig(Users::resolveConfig($createdLogin, $base));
                $before = (int) App::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn();
                $scanError = null;
                set_time_limit(0);
                try {
                    ob_start();
                    (new Scanner())->run(false);
                    ob_end_clean();
                } catch (Throwable $throwable) {
                    ob_end_clean();
                    $scanError = $throwable->getMessage();
                }
                $after = (int) App::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn();

                $result = ['added' => max(0, $after - $before), 'scan_error' => $scanError, 'background' => false];
            }
        } catch (Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }
    }
}

$requirements = Installer::requirements($projectRoot);
$hasFatalRequirement = false;
foreach ($requirements as $check) {
    if ($check['ok'] === false && $check['level'] === 'required') {
        $hasFatalRequirement = true;
    }
}

$formRoot = $errors !== [] ? $postMusicRoot : (is_string($base['music_root'] ?? null) ? $base['music_root'] : '');
$formUser = $errors !== [] ? $postUser : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Installation · Muzik</title>
  <style>
    :root { color-scheme: light dark; }
    body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
           background:#121212; color:#e8e8e8; line-height:1.5; }
    .wrap { max-width:720px; margin:0 auto; padding:2rem 1rem 4rem; }
    .logo { display:block; width:128px; height:128px; margin:0 auto 1.25rem; border-radius:24px; }
    .sub { color:#9b9b9b; margin:0 0 1.5rem; text-align:center; }
    .card { background:#1e1e1e; border:1px solid #2e2e2e; border-radius:10px;
            padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
    h2 { font-size:1.05rem; margin:0 0 1rem; }
    table { width:100%; border-collapse:collapse; font-size:.95rem; }
    td { padding:.35rem 0; border-bottom:1px solid #2a2a2a; }
    td:last-child { text-align:right; color:#bbb; }
    .ok { color:#27d397; font-weight:600; }
    .bad { color:#e5484d; font-weight:600; }
    .warn { color:#e8a33d; font-weight:600; }
    label { display:block; font-weight:600; margin:1rem 0 .35rem; }
    input[type=text], input[type=password] { width:100%; box-sizing:border-box;
      padding:.6rem .7rem; border-radius:6px; border:1px solid #3a3a3a;
      background:#141414; color:#e8e8e8; font-size:1rem; }
    .hint { color:#9b9b9b; font-size:.9rem; margin:.3rem 0 0; }
    .check { margin:.4rem 0; }
    button.primary { margin-top:1.5rem; padding:.8rem 1.4rem; font-size:1.05rem;
      background:#27d397; color:#082015; border:0; border-radius:8px;
      font-weight:700; cursor:pointer; }
    button.primary:hover:not(:disabled) { filter: brightness(1.08); }
    button.primary:disabled { background:#27d397; color:#082015; opacity:.4;
      cursor:not-allowed; }
    .error { background:#3a1516; border:1px solid #e5484d; border-radius:8px;
             padding:1rem 1.25rem; margin-bottom:1.25rem; }
    .error ul { margin:0; padding-left:1.25rem; }
    .success { background:#15301f; border:1px solid #27d397; border-radius:8px;
             padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
    .details { font-size:.9rem; color:#a5a5a5; }
    footer { color:#6f6f6f; font-size:.85rem; text-align:center; margin-top:2rem; }
  </style>
</head>
<body>
<div class="wrap">
  <img class="logo" src="assets/icon-192.png?v=3" alt="Muzik" width="128" height="128">
  <p class="sub">Installation de votre lecteur de musique</p>

<?php if ($result !== null): ?>
    <section class="success">
      <h2>Installation terminée</h2>
<?php if ($result['background']): ?>
      <p>Le compte est créé et l'indexation de votre bibliothèque
      est lancée en arrière-plan. Elle se poursuit pendant quelques minutes.</p>
      <p class="details">Vous pouvez suivre la progression dans
      <code>data/scan-<?= App::e($createdLogin ?? '') ?>.log</code>. L'interface
      va s'ouvrir automatiquement.</p>
<?php elseif ($result['scan_error'] !== null): ?>
      <p>
        La bibliothèque a été indexée
        (<?= $result['added'] ?> nouvelle<?= $result['added'] > 1 ? 's' : '' ?> piste<?= $result['added'] > 1 ? 's' : '' ?>).
      </p>
      <p class="details">
        Le scan s'est interrompu avant la fin : <?= App::e((string) $result['scan_error']) ?>.<br>
        Vous pourrez terminer l'indexation avec <code>php bin/scan.php --user login</code> depuis la ligne de commande.
      </p>
<?php else: ?>
      <p>
        La bibliothèque a été indexée
        (<?= $result['added'] ?> nouvelle<?= $result['added'] > 1 ? 's' : '' ?> piste<?= $result['added'] > 1 ? 's' : '' ?>).
      </p>
      <p class="details">L'interface va s'ouvrir automatiquement. Si rien ne se passe, <a href="./" style="color:#27d397">lancez Muzik</a>.</p>
<?php endif; ?>
    </section>
    <meta http-equiv="refresh" content="3;url=./">
<?php else: ?>
<?php if ($errors !== []): ?>
    <div class="error" role="alert">
      <ul><?php foreach ($errors as $error): ?><li><?= App::e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

    <form method="post" action="./install.php">
      <section class="card">
        <h2>1. Prérequis</h2>
        <table>
<?php foreach ($requirements as $check): ?>
          <tr>
            <td><?= App::e($check['check']) ?></td>
            <td class="<?= $check['level'] === 'optional' ? 'warn' : ($check['ok'] ? 'ok' : 'bad') ?>">
              <?= App::e($check['detail']) ?>
            </td>
          </tr>
<?php endforeach; ?>
        </table>
        <p class="hint">
          <?= $hasFatalRequirement
              ? 'Corrigez les prérequis indispensables avant de continuer.'
              : 'FFmpeg est facultatif : sans lui, la lecture directe reste disponible.' ?>
        </p>
      </section>

      <section class="card">
        <h2>2. Compte utilisateur</h2>
        <p class="hint">Chaque compte possède sa propre bibliothèque et son propre index.</p>
        <label for="user">Identifiant</label>
        <input type="text" id="user" name="user" required value="<?= App::e($formUser) ?>"
               placeholder="ex : paul" autocomplete="username" pattern="[a-zA-Z0-9_-]+">
        <label for="pass">Mot de passe</label>
        <input type="password" id="pass" name="pass" required autocomplete="new-password" minlength="8">
        <label for="pass2">Confirmer le mot de passe</label>
        <input type="password" id="pass2" name="pass2" required autocomplete="new-password">
      </section>

      <section class="card">
        <h2>3. Bibliothèque musicale</h2>
        <label for="music_root">Où sont vos fichiers de musique ?</label>
        <input type="text" id="music_root" name="music_root" required
               value="<?= App::e($formRoot) ?>" placeholder="/chemin/vers/ma/musique"
               autocomplete="off">
        <p class="hint">Chemin absolu du dossier contenant les pistes (MP3, FLAC, OGG, M4A, WAV).</p>
      </section>

      <section class="card">
        <h2>4. Installation et premier scan</h2>
        <p class="hint">
          Le compte est enregistré dans <code>data/users.db</code>, puis une
          indexation incrémentale de la bibliothèque est lancée (aucune
          suppression). Pour une très grande bibliothèque, lancez ensuite
          <code>php bin/scan.php --user votre_login</code> en CLI.
        </p>
        <button type="submit" class="primary" name="install"
                <?= $hasFatalRequirement ? 'disabled' : '' ?>>Installer et scanner</button>
      </section>
    </form>
    <script>
      (function () {
        var form = document.querySelector('form[action="./install.php"]');
        if (!form) { return; }
        form.addEventListener('submit', function () {
          var button = form.querySelector('button[type="submit"]');
          if (button) {
            button.disabled = true;
            button.textContent = 'Installation en cours…';
          }
        });
      })();
    </script>
<?php endif; ?>

  <footer>Muzik · lecteur de musique auto-hébergé</footer>
</div>
</body>
</html>