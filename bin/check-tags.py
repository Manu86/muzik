#!/usr/bin/env python3
"""Audit : compare les tags embarqués des pistes audio avec la base SQLite.

Lecture seule (aucune écriture). Colonnes comparées :
  title, track, disc (song) ; year, genre (album) ; artist, album (artists/albums).

Usage :
  python3 bin/check-tags.py [--samples N] [--all FICHIER.CSV] [--user LOGIN]
"""
import argparse
import re
import sqlite3
import sys

from pathlib import Path

from mutagen.easyid3 import EasyID3
from mutagen.flac import FLAC
from mutagen.id3 import ID3
from mutagen.mp4 import MP4
from mutagen.oggvorbis import OggVorbis
from mutagen.wave import WAVE

sys.path.insert(0, str(Path(__file__).resolve().parent / 'lib'))
from muzik_db import catalog_db  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent

MP4_MAP = {
    'title': '\xa9nam',
    'artist': '\xa9ART',
    'album': '\xa9alb',
    'date': '\xa9day',
    'genre': '\xa9gen',
}

ID3_MAP = {
    'title': 'TIT2',
    'artist': 'TPE1',
    'album': 'TALB',
    'genre': 'TCON',
    'date': 'TDRC',
    'track': 'TRCK',
    'disc': 'TPOS',
}

JUNK = re.compile(
    r'^(?:no artist|unknown(?: artist)?|artist[ée] inconnu|inconnu(?:e)?|'
    r'nouvel artiste|none|artiste|no album|no title|sans album|sans titre|'
    r'nouveau titre|untitled|g[eé]n[eé]rique|audio ?track|track|piste|unknown|'
    r'nouveau titre)[\s\d]*$',
    re.IGNORECASE,
)


def canon_genre(value):
    """Équivalent de normalizeGenre() : fusionne les libellés de même famille."""
    if value is None:
        return None
    key = ' '.join(value.strip().lower().split())
    aliases = {
        'hip-hop/rap': 'Rap/Hip Hop',
        'hip hop/rap': 'Rap/Hip Hop',
        'hip-hop & rap': 'Rap/Hip Hop',
        'rap & hip-hop': 'Rap/Hip Hop',
        'rap & hip hop': 'Rap/Hip Hop',
        'hip hop': 'Rap/Hip Hop',
        'variété française': 'Chanson française',
        'variété francaise': 'Chanson française',
        'variete francaise': 'Chanson française',
        'raíces': 'Latino',
        'musique brésilienne': 'Latino',
        'metal': 'Rock',
        'métal': 'Rock',
        'paroles/interprétation': 'Chanson française',
        'musique classique': 'Classique',
    }
    return aliases.get(key, key)


def norm(value):
    """Normalisation souple pour la comparaison (casse + espaces)."""
    if value is None:
        return None
    return ' '.join(str(value).strip().lower().split())


def first(value):
    """Première valeur d'un tag (liste) ou valeur directe."""
    if isinstance(value, list):
        return str(value[0]) if value else None
    if value is None or str(value).strip() == '':
        return None
    return str(value)


def first_int(value):
    """Premier entier d'une valeur (ex: '3/12' → 3)."""
    if value is None:
        return None
    match = re.match(r'\d+', str(value).strip())
    return int(match.group()) if match else None


def read_tags(path: str) -> dict:
    """Lit les tags selon le format ; renvoie {clé: valeur_texte} ou {} sans tags."""
    ext = path.rsplit('.', 1)[-1].lower() if '.' in path else ''
    tags: dict = {}

    if ext == 'mp3':
        try:
            audio = EasyID3(path)
        except Exception:
            return tags
        for key, tag in (('title', 'title'), ('artist', 'artist'),
                         ('album', 'album'), ('genre', 'genre')):
            if tag in audio:
                tags[key] = first(audio[tag])
        if 'date' in audio:
            year = first_int(first(audio['date']))
            if year is not None:
                tags['year'] = year
        if 'tracknumber' in audio:
            tags['track'] = first_int(first(audio['tracknumber']))
        if 'discnumber' in audio:
            tags['disc'] = first_int(first(audio['discnumber']))

    elif ext in ('flac', 'ogg'):
        try:
            audio = FLAC(path) if ext == 'flac' else OggVorbis(path)
        except Exception:
            return tags
        for key in ('title', 'artist', 'album', 'genre'):
            if key in audio:
                tags[key] = first(audio[key])
        year = first(audio.get('date')) or first(audio.get('year'))
        if year:
            tags['year'] = first_int(year)
        track = first(audio.get('tracknumber')) or first(audio.get('track'))
        if track:
            tags['track'] = first_int(track)
        disc = first(audio.get('discnumber')) or first(audio.get('disc'))
        if disc:
            tags['disc'] = first_int(disc)

    elif ext == 'm4a':
        try:
            audio = MP4(path)
        except Exception:
            return tags
        for key, tag in (('title', 'title'), ('artist', 'artist'),
                         ('album', 'album'), ('genre', 'genre')):
            value = audio.get(MP4_MAP[tag])
            if value:
                tags[key] = first(value)
        if audio.get(MP4_MAP['date']):
            tags['year'] = first_int(first(audio.get(MP4_MAP['date'])))
        trkn = audio.get('trkn')
        if trkn and isinstance(trkn[0], tuple):
            tags['track'] = int(trkn[0][0])
        disk = audio.get('disk')
        if disk and isinstance(disk[0], tuple):
            tags['disc'] = int(disk[0][0])

    elif ext == 'wav':
        try:
            audio = ID3(path)
        except Exception:
            return tags
        for key, frame in ID3_MAP.items():
            f = audio.get(frame)
            if f is not None:
                text = f.text if hasattr(f, 'text') else list(f)
                value = first(text)
                if key in ('track', 'disc', 'date'):
                    if value:
                        tags[{'track': 'track', 'disc': 'disc', 'date': 'year'}[key]] = first_int(value)
                else:
                    tags[key] = value

    return tags


def clean_value(value, default=None):
    if value is None:
        return default
    if isinstance(value, list):
        value = value[0] if value else default
    return value


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--samples', type=int, default=12,
                        help='nombre d\u2019écarts affichés par colonne')
    parser.add_argument('--all', metavar='FICHIER',
                        help='écris tous les écarts dans un CSV')
    parser.add_argument('--user', metavar='LOGIN',
                        help='compte ciblé (défaut : data/muzik.db ou premier utilisateur)')
    args = parser.parse_args()

    conn = sqlite3.connect(catalog_db())
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        'SELECT s.id, s.path, s.title AS db_title, s.track AS db_track, '
        's.disc AS db_disc, ar.name AS db_artist, al.name AS db_album, '
        'al.year AS db_year, al.genre AS db_genre '
        'FROM songs s '
        'JOIN artists ar ON ar.id = s.artist_id '
        'JOIN albums al ON al.id = s.album_id'
    ).fetchall()
    conn.close()

    total = len(rows)
    missing_file = 0
    stats = {
        key: {'ok': 0, 'diff': 0, 'notag': 0}
        for key in ('title', 'artist', 'album', 'track', 'disc', 'year', 'genre')
    }
    diffs = {key: [] for key in stats}

    for row in rows:
        path = row['path']
        if not path or not Path(path).is_file():
            missing_file += 1
            continue

        tags = read_tags(path)

        compares = {
            'title': (norm(row['db_title']), norm(tags.get('title'))),
            'artist': (norm(row['db_artist']), norm(tags.get('artist'))),
            'album': (norm(row['db_album']), norm(tags.get('album'))),
            'track': (row['db_track'], tags.get('track')),
            'disc': (row['db_disc'], tags.get('disc')),
            'year': (row['db_year'], tags.get('year')),
            'genre': (norm(canon_genre(row['db_genre'])), norm(canon_genre(tags.get('genre')))),
        }

        for key in stats:
            db_value, file_value = compares[key]
            if file_value is None or (isinstance(file_value, str) and JUNK.match(file_value)):
                stats[key]['notag'] += 1
                continue
            if key in ('track', 'disc', 'year'):
                equal = (db_value or 0) == file_value
            else:
                equal = (db_value or '') == file_value
            if equal:
                stats[key]['ok'] += 1
            else:
                stats[key]['diff'] += 1
                diffs[key].append((row['id'], path, db_value, file_value))

    print(f'Total pistes : {total}  (fichiers absents : {missing_file})\n')

    for key in stats:
        s = stats[key]
        total_compare = total - missing_file
        pct = lambda n: f'{n} ({100.0 * n / total_compare:.1f}%)'  # noqa: E731
        print(f'[{key}]  identiques : {pct(s["ok"])}  |  écart : {pct(s["diff"])}  '
              f'|  sans tag fichier : {pct(s["notag"])}')
        if s['diff'] and args.samples > 0:
            shown = diffs[key][: args.samples]
            for sid, path, db_value, file_value in shown:
                print(f'   - #{sid} « {path} »\n       base : {db_value!r}\n       fichier : {file_value!r}')
            if len(diffs[key]) > len(shown):
                print(f'   … {len(diffs[key]) - len(shown)} autres écarts')

    if args.all:
        with open(args.all, 'w', encoding='utf-8') as fh:
            fh.write('colonne;id;piste;base;fichier\n')
            for key, items in diffs.items():
                for sid, path, db_value, file_value in items:
                    fh.write(f'{key};{sid};{path};{db_value};{file_value}\n')
        print(f'\nCSV des écarts : {args.all}')


if __name__ == '__main__':
    main()