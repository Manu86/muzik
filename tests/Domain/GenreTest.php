<?php

declare(strict_types=1);

final class GenreTest extends TestCase
{
    public function testCanonicalListIsSeededInSchema(): void
    {
        $pdo = DB::init($this->temporaryDirectory . '/genres.sqlite');
        DB::schema();

        $genres = $pdo->query('SELECT name FROM genres ORDER BY name')->fetchColumnValues();
        self::assertSame(Genre::GENRES, $genres);
    }

    public function testNormalizeMergesFamiliesAndDropsJunk(): void
    {
        self::assertSame('Rap/Hip Hop', Genre::normalize('rap/hip hop'));
        self::assertSame('Rap/Hip Hop', Genre::normalize('Hip-Hop/Rap'));
        self::assertSame('Rock', Genre::normalize('rock / hard rock / metal'));
        self::assertSame('Rock', Genre::normalize('Métal'));
        self::assertSame('Films/Jeux vidéo', Genre::normalize('films/jeux vidéo'));
        self::assertSame('Films/Jeux vidéo', Genre::normalize('Bande originale'));
        self::assertSame('Humour / Parlé', Genre::normalize('humour / parlé'));
        self::assertSame('Humour / Parlé', Genre::normalize('Spoken Word'));
        self::assertSame('Electro', Genre::normalize('Deep House'));
        self::assertSame('Easy Listening', Genre::normalize(' easy listening '));
        self::assertSame('Easy Listening', Genre::normalize('1 easy listening'));
        self::assertSame('Afro pop', Genre::normalize('Afro pop'));
        self::assertSame('Afro pop', Genre::normalize("Afro\u{00A0}pop"));
        self::assertNull(Genre::normalize('unknown'));
        self::assertNull(Genre::normalize(''));
        self::assertNull(Genre::normalize('   '));
        self::assertNull(Genre::normalize(null));
    }
}
