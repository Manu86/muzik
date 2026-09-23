<?php

declare(strict_types=1);

/**
 * Valeurs globales propres à la machine. Les comptes utilisateurs (identifiant,
 * mot de passe bcrypt, racine musicale, base par utilisateur) vivent dans
 * data/users.db ; `auth_user`/`auth_hash` ne sont lus qu'une fois, pour migrer
 * une installation historique vers un premier compte.
 */
return [
    'music_root' => '/path/to/music',
    'db_path' => __DIR__ . '/data/muzik.db',
    'ffmpeg' => '/usr/bin/ffmpeg',
    'transcode' => 0,
    'auth_user' => '',
    'auth_hash' => '',
];
