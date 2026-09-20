#!/usr/bin/env python3
"""Renseigne albums.year à partir des années portées par les fichiers audio.

Lecture des tags seule ; écriture : albums.year en base (avec --apply) et
data/years.json (cache partagé avec bin/year-resolve.php).

Consensus retenu quand une majorité de fichiers de l'album portent la même
année (au moins 2 fichiers, ratio >= 50 %). Les albums composites
(« Sans album », dossiers mélangeant plusieurs années) sont laissés à la
résolution en ligne.

Usage :
  python3 bin/year-from-files.py [--apply] [--samples N]
"""
import argparse
import json
import re
import sqlite3
import sys

from collections import Counter
from pathlib import Path

from mutagen.easyid3 import EasyID3
from mutagen.flac import FLAC
from mutagen.id3 import ID3
from mutagen.mp4 import MP4
from mutagen.oggvorbis import OggVorbis

ROOT = Path(__file__).resolve().parent.parent
DB = ROOT / 'data' / 'muzik.db'
CACHE = ROOT / 'data' / 'years.json'


def file_year(path: str):
    """Année (int) lue dans les tags du fichier, ou None."""
    ext = path.rsplit('.', 1)[-1].lower() if '.' in path else ''
    value = None
    try:
        if ext == 'mp3':
            audio = EasyID3(path)
            value = audio.get('date') or audio.get('year') \
                or audio.get('originaldate') or audio.get('recordingdate')
        elif ext in ('flac', 'ogg'):
            audio = FLAC(path) if ext == 'flac' else OggVorbis(path)
            value = audio.get('date') or audio.get('year')
        elif ext == 'm4a':
            audio = MP4(path)
            value = audio.get('\xa9day')
        elif ext == 'wav':
            audio = ID3(path)
            frame = audio.get('TDRC') or audio.get('TYER')
            value = [str(t) for t in frame.text] if frame is not None else None
    except Exception:
        return None
    if not value:
        return None
    if isinstance(value, list):
        value = value[0] if value else None
    match = re.search(r'(19[0-9]{2}|20[0-9]{2})', str(value))
    return int(match.group()) if match else None


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true',
                        help='écrit albums.year et data/years.json')
    parser.add_argument('--samples', type=int, default=10,
                        help='écarts affichés')
    args = parser.parse_args()

    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    albums = conn.execute(
        "SELECT id, name, artist_id, year FROM albums "
        "WHERE year IS NULL OR year = '' ORDER BY id"
    ).fetchall()
    songs = conn.execute('SELECT album_id, path FROM songs').fetchall()

    per_album: dict = {}
    for song in songs:
        per_album.setdefault(song['album_id'], []).append(song['path'])

    cache = {}
    if CACHE.is_file():
        cache = json.loads(CACHE.read_text(encoding='utf-8')) or {}

    decided = []
    skipped = []
    for album in albums:
        aid = album['id']
        years = [y for p in per_album.get(aid, []) if (y := file_year(p))]
        if not years:
            skipped.append((aid, album['name'], {}))
            continue
        counts = Counter(years)
        top, top_count = counts.most_common(1)[0]
        single = len(years) == 1
        if (top_count >= 2 and top_count / len(years) >= 0.5) or single:
            decided.append((aid, album['name'], top, top_count, len(years)))
            cache[str(aid)] = top
        else:
            skipped.append((aid, album['name'], dict(counts)))

    print(f'Albums sans année : {len(albums)}  '
          f'-> renseignés par les fichiers : {len(decided)}, '
          f'laissés en attente : {len(skipped)}\n')

    for aid, name, year, num, total in decided[: args.samples]:
        print(f'  #{aid} « {name} » -> {year} ({num}/{total} fichiers)')
    if len(decided) > args.samples:
        print(f'  … {len(decided) - args.samples} autres')

    if not args.apply:
        print('\nDRY-RUN — base et cache non modifiés (--apply pour écrire).')
        return

    CACHE.write_text(
        json.dumps(cache, ensure_ascii=False, indent=2),
        encoding='utf-8',
    )
    conn.executemany(
        'UPDATE albums SET year = ? WHERE id = ?',
        [(y, aid) for aid, _name, y, _n, _t in decided],
    )
    conn.commit()
    conn.close()
    print(f'\nÉcrit : {len(decided)} années en base + data/years.json '
          f'({len(cache)} clés).')


if __name__ == '__main__':
    main()