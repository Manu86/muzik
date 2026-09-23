#!/usr/bin/env python3
"""Renseigne songs.track manquant à partir du numéro embarqué des fichiers.

Lecture seule ; écriture : songs.track en base (avec --apply).
Les pistes sans numéro dans le fichier ET sans numéro en base sont laissées
au repos (singles, compilations) : inventer un numéro n'aurait pas de sens.

Usage :
  python3 bin/track-resolve.py [--apply] [--samples N] [--user LOGIN]
"""
import argparse
import json
import re
import sqlite3
import sys

from pathlib import Path

from mutagen.easyid3 import EasyID3
from mutagen.flac import FLAC
from mutagen.id3 import ID3
from mutagen.mp4 import MP4
from mutagen.oggvorbis import OggVorbis

sys.path.insert(0, str(Path(__file__).resolve().parent / 'lib'))
from muzik_db import catalog_db  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
PLAN = ROOT / 'data' / 'track-plan.json'


def file_track(path: str):
    """Numéro de piste (int) du fichier, ou None."""
    ext = path.rsplit('.', 1)[-1].lower() if '.' in path else ''
    value = None
    try:
        if ext == 'mp3':
            value = EasyID3(path).get('tracknumber')
        elif ext in ('flac', 'ogg'):
            value = (FLAC(path) if ext == 'flac'
                     else OggVorbis(path)).get('tracknumber')
        elif ext == 'm4a':
            trkn = MP4(path).get('trkn')
            if trkn and isinstance(trkn, list) and isinstance(trkn[0], tuple):
                value = [str(trkn[0][0])]
            else:
                value = None
        elif ext == 'wav':
            frame = ID3(path).get('TRCK')
            value = [str(t) for t in frame.text] if frame is not None else None
    except Exception:
        return None
    if not value:
        return None
    if isinstance(value, list):
        value = value[0] if value else None
    match = re.match(r'\d+', str(value))
    return int(match.group()) if match else None


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true',
                        help='écrit songs.track en base')
    parser.add_argument('--samples', type=int, default=10)
    parser.add_argument('--user', metavar='LOGIN',
                        help='compte ciblé (défaut : data/muzik.db ou premier utilisateur)')
    args = parser.parse_args()

    conn = sqlite3.connect(catalog_db())
    conn.row_factory = sqlite3.Row
    songs = conn.execute(
        'SELECT id, path, track, album_id, title FROM songs '
        'WHERE track IS NULL OR track = 0 ORDER BY album_id, id'
    ).fetchall()

    plan = []
    skipped = 0
    for song in songs:
        ft = file_track(song['path'])
        if ft is None or not 1 <= ft <= 999:
            skipped += 1
            continue
        plan.append((int(song['id']), ft, song['path']))

    print(f'Pistes sans numéro en base : {len(songs)}  '
          f'-> numéro fichier adopté : {len(plan)}, '
          f'sans numéro nulle part : {skipped}\n')

    for sid, ft, path in plan[: args.samples]:
        print(f'  #{sid} « {path.split("/")[-1]} » -> piste {ft}')
    if len(plan) > args.samples:
        print(f'  … {len(plan) - args.samples} autres')

    PLAN.write_text(
        json.dumps(
            [{'id': sid, 'track': ft, 'path': path} for sid, ft, path in plan],
            ensure_ascii=False, indent=2,
        ),
        encoding='utf-8',
    )

    if not args.apply:
        print('\nDRY-RUN — base non modifiée (--apply pour écrire).')
        return

    conn.executemany(
        'UPDATE songs SET track = ? WHERE id = ?',
        [(ft, sid) for sid, ft, _path in plan],
    )
    conn.commit()
    conn.close()
    print(f'\nÉcrit : {len(plan)} numéros en base (+ data/track-plan.json).')


if __name__ == '__main__':
    main()