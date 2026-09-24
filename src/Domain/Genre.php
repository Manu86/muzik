<?php

declare(strict_types=1);

/**
 * Libellés canoniques des genres et normalisation des écritures.
 *
 * Domain pure : aucune dépendance vers la base ou l'infrastructure HTTP.
 */
final class Genre
{
    /**
     * Genres canoniques pré-créés pour chaque nouveau catalogue. Ce sont les
     * libellés vers lesquels {@see Genre::normalize()} fusionne les écritures
     * (variantes, orthographes) ; ils sont complétés par les genres réellement
     * lus dans les tags lors de l'indexation.
     *
     * @var list<string>
     */
    public const GENRES = [
        'Alternative',
        'Chanson française',
        'Classique',
        'Electro',
        'Films/Jeux vidéo',
        'Humour / Parlé',
        'Jazz',
        'Latino',
        'Musiques du monde',
        'Pop',
        'R&B',
        'Rap/Hip Hop',
        'Reggae',
        'Rock',
    ];

    /**
     * Ramène un genre (tag ID3, service web…) vers un libellé canonique.
     * Les libellés de même famille sont fusionnés ; une valeur trop typée
     * ("rock / hard rock / metal") retient son premier segment reconnu.
     */
    public static function normalize(?string $genre): ?string
    {
        if ($genre === null || trim($genre) === '') {
            return null;
        }

        // L'espace insécable (U+00A0) fréquente dans les tags est considérée
        // comme un espace ordinaire, sinon elle crée des libellés dupliqués.
        $genre = str_replace("\u{00A0}", ' ', $genre);

        $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $genre)));
        if (in_array($key, ['unknown', 'none', 'inconnu', 'no genre', 'various', 'varia'], true)) {
            return null;
        }

        $aliases = [
            'hip-hop/rap' => 'Rap/Hip Hop',
            'hip hop/rap' => 'Rap/Hip Hop',
            'rap/hip hop' => 'Rap/Hip Hop',
            'hip-hop & rap' => 'Rap/Hip Hop',
            'rap & hip-hop' => 'Rap/Hip Hop',
            'rap & hip hop' => 'Rap/Hip Hop',
            'hip hop' => 'Rap/Hip Hop',
            'rap' => 'Rap/Hip Hop',
            'variété française' => 'Chanson française',
            'variété francaise' => 'Chanson française',
            'variete francaise' => 'Chanson française',
            'raíces' => 'Latino',
            'musique brésilienne' => 'Latino',
            'brésil' => 'Latino',
            'bossa nova' => 'Latino',
            'metal' => 'Rock',
            'métal' => 'Rock',
            'rock' => 'Rock',
            'paroles/interprétation' => 'Chanson française',
            'musique classique' => 'Classique',
            'classique' => 'Classique',
            'classical' => 'Classique',
            'pop' => 'Pop',
            'afro pop' => 'Afro pop',
            'electro' => 'Electro',
            'techno' => 'Electro',
            'house' => 'Electro',
            'dance' => 'Electro',
            'electronica' => 'Electro',
            'deep house' => 'Electro',
            'trance' => 'Electro',
            'jazz' => 'Jazz',
            'reggae' => 'Reggae',
            'alternative' => 'Alternative',
            'indie' => 'Alternative',
            'rock alternatif' => 'Alternative',
            'r&b' => 'R&B',
            'rnb' => 'R&B',
            'r and b' => 'R&B',
            'soul' => 'R&B',
            'musiques du monde' => 'Musiques du monde',
            'world music' => 'Musiques du monde',
            'chanson française' => 'Chanson française',
            'humour' => 'Humour / Parlé',
            'parlé' => 'Humour / Parlé',
            'spoken word' => 'Humour / Parlé',
            'comédie' => 'Humour / Parlé',
            'films/jeux vidéo' => 'Films/Jeux vidéo',
            'film' => 'Films/Jeux vidéo',
            'films' => 'Films/Jeux vidéo',
            'jeux vidéo' => 'Films/Jeux vidéo',
            'jeu video' => 'Films/Jeux vidéo',
            'game' => 'Films/Jeux vidéo',
            'bande originale' => 'Films/Jeux vidéo',
            'soundtrack' => 'Films/Jeux vidéo',
            'ost' => 'Films/Jeux vidéo',
        ];
        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        $parts = preg_split('/\s*\/\s*/', $key);
        if ($parts === false || $parts === []) {
            $parts = [$key];
        }
        foreach ($parts as $segment) {
            if (isset($aliases[$segment])) {
                return $aliases[$segment];
            }
        }

        if (preg_match('/^[0-9]+\s*(.*)$/', $genre, $m) === 1) {
            $genre = $m[1];
        }
        return ucwords(trim((string) preg_replace('/\s+/', ' ', $genre)));
    }
}
