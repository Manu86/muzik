<?php

declare(strict_types=1);

/**
 * Favoris : ajout, retrait, vérification et liste.
 */
final class FavoritesController
{
    public static function favorites(): void
    {
        $action = Request::get('action');
        $songId = Request::get('id');

        if ($action === 'add' && $songId) {
            Favorites::add($songId);
        } elseif ($action === 'remove' && $songId) {
            Favorites::remove($songId);
        } elseif ($action === 'check' && $songId) {
            App::json(['favorited' => Favorites::check($songId)]);
        }

        App::json(Favorites::all());
    }
}
