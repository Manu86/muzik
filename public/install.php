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
    <h1 style="color:#1db954;">♪ Muzik — Dépendances manquantes</h1>
    <p>Les dépendances PHP (<code>vendor/</code>) ne sont pas installées. Le script
    d'installation ne peut pas démarrer sans elles.</p>
    <p>Depuis le terminal, à la racine du projet :</p>
    <pre style="background:#1e1e1e;border:1px solid #2e2e2e;border-radius:8px;padding:1rem;">composer install</pre>
    <p>Puis rechargez la page <a href="./install.php" style="color:#1db954;">./install.php</a>.</p>
  </main>
</body>
</html>
    <?php
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/DB.php';
require __DIR__ . '/../src/App.php';
require __DIR__ . '/../src/Installer.php';
require __DIR__ . '/../src/Scanner.php';

App::initConfig(require __DIR__ . '/../config.php');

$projectRoot = dirname(__DIR__);

if (Installer::installed($projectRoot)) {
    header('Location: ./');
    exit;
}

/** @var list<string> $errors */
$errors = [];
/** @var array{added: int, scan_error: ?string}|null $result */
$result = null;

$musicRoot = App::config('music_root');
$ffmpegDefault = Installer::detectFfmpeg() ?? '';
$transcodeDefault = App::config('transcode');

$postMusicRoot = trim((string) ($_POST['music_root'] ?? ''));
$postFfmpeg = trim((string) ($_POST['ffmpeg'] ?? ''));
$postTranscode = (int) ($_POST['transcode'] ?? -1);
$postUser = trim((string) ($_POST['auth_user'] ?? ''));
$postPassDefined = isset($_POST['auth_pass']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $musicRootValue = $postMusicRoot;
    $ffmpegValue = $postFfmpeg;
    $transcodeValue = in_array($postTranscode, [0, 128, 192, 256, 320], true) ? $postTranscode : -1;
    $adminUser = trim((string) $postUser);
    $adminPass = $postPassDefined ? (string) $_POST['auth_pass'] : '';
    $adminPass2 = array_key_exists('auth_pass2', $_POST)
        ? (string) $_POST['auth_pass2']
        : '';

    if (!is_string($musicRootValue) || $musicRootValue === '') {
        $errors[] = 'Indiquez le dossier contenant vos fichiers de musique.';
    } elseif (!is_dir($musicRootValue) || !is_readable($musicRootValue)) {
        $errors[] = 'Le dossier de musique doit exister et être lisible par le serveur.';
    }

    if ($ffmpegValue !== '' && !is_executable($ffmpegValue)) {
        $errors[] = 'Le chemin FFmpeg indiqué n\'est pas un exécutable accessible.';
    }

    if ($transcodeValue === -1) {
        $errors[] = 'Le débit de transcodage choisi est invalide.';
    }

    if ($adminUser !== '' && $adminPass === '') {
        $errors[] = 'L\'utilisateur ne peut pas être défini sans mot de passe.';
    }
    if ($adminPass !== '') {
        if (mb_strlen($adminPass) < 8) {
            $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = 'Le mot de passe et sa confirmation diffèrent.';
        }
    }

    if ($errors === []) {
        $dbPath = App::config('db_path');
        $dbPathValue = is_string($dbPath) && $dbPath !== '' ? $dbPath : $projectRoot . '/data/muzik.db';

        $adminAuth = $adminPass !== '' ? [
            'auth_user' => $adminUser,
            'auth_hash' => Installer::hashPassword($adminPass),
        ] : [];

        $written = Installer::writeConfig($projectRoot . '/config.local.php', array_replace([
            'music_root' => $musicRootValue,
            'db_path' => $dbPathValue,
            'ffmpeg' => $ffmpegValue,
            'transcode' => $transcodeValue,
        ], $adminAuth));

        if ($written) {
            unset($musicRootValue, $ffmpegValue, $transcodeValue, $adminAuth);
            App::initConfig(require $projectRoot . '/config.php');
        }

        if ($written) {
            Installer::markInstalled();

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

            $result = ['added' => max(0, $after - $before), 'scan_error' => $scanError];
        } else {
            $errors[] = 'Impossible d\'écrire la configuration (config.local.php). '
                . 'Vérifiez les droits d\'écriture du dossier.';
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

$formRoot = $errors !== [] ? $postMusicRoot : (is_string($musicRoot) ? $musicRoot : '');
$formFfmpeg = $errors !== [] ? $postFfmpeg : $ffmpegDefault;
$formTranscode = $errors !== [] ? (in_array($postTranscode, [0, 128, 192, 256, 320], true) ? $postTranscode : $transcodeDefault) : $transcodeDefault;
$formUser = $errors !== [] ? $postUser : 'muzik';
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
    h1 { font-size:1.6rem; margin:0 0 .25rem; }
    h1 .ico { color:#1db954; }
    .sub { color:#9b9b9b; margin:0 0 1.5rem; }
    .card { background:#1e1e1e; border:1px solid #2e2e2e; border-radius:10px;
            padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
    h2 { font-size:1.05rem; margin:0 0 1rem; }
    table { width:100%; border-collapse:collapse; font-size:.95rem; }
    td { padding:.35rem 0; border-bottom:1px solid #2a2a2a; }
    td:last-child { text-align:right; color:#bbb; }
    .ok { color:#1db954; font-weight:600; }
    .bad { color:#e5484d; font-weight:600; }
    .warn { color:#e8a33d; font-weight:600; }
    label { display:block; font-weight:600; margin:1rem 0 .35rem; }
    input[type=text], input[type=password] { width:100%; box-sizing:border-box;
      padding:.6rem .7rem; border-radius:6px; border:1px solid #3a3a3a;
      background:#141414; color:#e8e8e8; font-size:1rem; }
    .hint { color:#9b9b9b; font-size:.9rem; margin:.3rem 0 0; }
    select { padding:.6rem; border-radius:6px; border:1px solid #3a3a3a;
             background:#141414; color:#e8e8e8; font-size:1rem; }
    .check { margin:.4rem 0; }
    button.primary { margin-top:1.5rem; padding:.8rem 1.4rem; font-size:1.05rem;
      background:#1db954; color:#000; border:0; border-radius:8px;
      font-weight:700; cursor:pointer; }
    button.primary:disabled { background:#3a5a46; color:#8a8a8a; cursor:not-allowed; }
    .error { background:#3a1516; border:1px solid #e5484d; border-radius:8px;
             padding:1rem 1.25rem; margin-bottom:1.25rem; }
    .error ul { margin:0; padding-left:1.25rem; }
    .success { background:#15301f; border:1px solid #1db954; border-radius:8px;
               padding:1.25rem 1.5rem; margin-bottom:1.25rem; }
    .details { font-size:.9rem; color:#a5a5a5; }
    footer { color:#6f6f6f; font-size:.85rem; text-align:center; margin-top:2rem; }
  </style>
</head>
<body>
<div class="wrap">
  <h1><span class="ico">♪</span> Muzik</h1>
  <p class="sub">Installation de votre lecteur de musique</p>

<?php if ($result !== null): ?>
    <section class="success">
      <h2>Installation terminée</h2>
      <p>
        La bibliothèque a été indexée
        (<?= $result['added'] ?> nouvelle<?= $result['added'] > 1 ? 's' : '' ?> piste<?= $result['added'] > 1 ? 's' : '' ?>).
      </p>
<?php if ($result['scan_error'] !== null): ?>
      <p class="details">
        Le scan s'est interrompu avant la fin : <?= App::e((string) $result['scan_error']) ?>.<br>
        Vous pourrez terminer l'indexation avec <code>php bin/scan.php</code> depuis la ligne de commande.
      </p>
<?php else: ?>
      <p class="details">L'interface va s'ouvrir automatiquement. Si rien ne se passe, <a href="./" style="color:#1db954">lancez Muzik</a>.</p>
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
        <h2>2. Bibliothèque musicale</h2>
        <label for="music_root">Où sont vos fichiers de musique ?</label>
        <input type="text" id="music_root" name="music_root" required
               value="<?= App::e($formRoot) ?>" placeholder="/chemin/vers/ma/musique"
               autocomplete="off">
        <p class="hint">Chemin absolu du dossier contenant les pistes (MP3, FLAC, OGG, M4A, WAV).</p>
      </section>

      <section class="card">
        <h2>3. Options</h2>
        <label for="ffmpeg">FFmpeg (transcodage)</label>
        <input type="text" id="ffmpeg" name="ffmpeg"
               value="<?= App::e($formFfmpeg) ?>" placeholder="Aucun (lecture directe)">
        <p class="hint">Laisser vide pour désactiver le transcodage. Nécessaire pour diffuser autre chose que du MP3 au débit choisi.</p>

        <label for="transcode">Débit de transcodage</label>
        <select id="transcode" name="transcode">
<?php foreach ([0 => 'Lecture directe (aucun transcodage)', 128 => '128 kbit/s', 192 => '192 kbit/s', 256 => '256 kbit/s', 320 => '320 kbit/s'] as $value => $label): ?>
          <option value="<?= $value ?>"<?= (int) $formTranscode === $value ? ' selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
        </select>

        <label for="auth_user">Utilisateur (optionnel, connexion de l'application)</label>
        <input type="text" id="auth_user" name="auth_user"
               value="<?= App::e($formUser) ?>" autocomplete="username">

        <label for="auth_pass">Mot de passe</label>
        <input type="password" id="auth_pass" name="auth_pass" autocomplete="new-password">
        <label for="auth_pass2">Confirmer le mot de passe</label>
        <input type="password" id="auth_pass2" name="auth_pass2" autocomplete="new-password">
        <p class="hint">
          Active la protection par mot de passe de l'application
          (hachage bcrypt stocké dans <code>config.local.php</code>).
          HTTPS recommandé avant exposition.
        </p>
      </section>

      <section class="card">
        <h2>4. Installation et premier scan</h2>
        <p class="hint">
          Configuration écrite dans <code>config.local.php</code>, puis indexation
          incrémentale de la bibliothèque (aucune suppression). Pour une très
          grande bibliothèque, lancez ensuite <code>php bin/scan.php</code> en CLI.
        </p>
        <button type="submit" class="primary" name="install"
                <?= $hasFatalRequirement ? 'disabled' : '' ?>>Installer et scanner</button>
      </section>
    </form>
<?php endif; ?>

  <footer>Muzik · lecteur de musique auto-hébergé</footer>
</div>
</body>
</html>