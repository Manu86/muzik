#!/usr/bin/env python3
"""Écrit les tags ID3/Vorbis/MP4 à partir de data/tag-plan.json (généré par bin/tag.php).

Usage :
  python3 tag_apply.py data/tag-plan.json
"""
import json
import sys
import os.path

from mutagen.easyid3 import EasyID3
from mutagen.mp4 import MP4
from mutagen.id3 import ID3
from mutagen.wave import WAVE

MP4_MAP = {
    'title': '\xa9nam',
    'artist': '\xa9ART',
    'album': '\xa9alb',
    'date': '\xa9day',
    'genre': '\xa9gen',
}


def apply_mp3(path: str, t: dict) -> None:
    try:
        audio = EasyID3(path)
    except Exception:
        audio = EasyID3()
    for k, v in (('title', t.get('title')), ('artist', t.get('artist')),
                 ('album', t.get('album')), ('genre', t.get('genre'))):
        if v:
            audio[k] = [str(v)]
    if t.get('year'):
        audio['date'] = [str(t['year'])]
    if t.get('track'):
        audio['tracknumber'] = [str(t['track'])]
    if t.get('disc'):
        audio['discnumber'] = [str(t['disc'])]
    audio.save(path)


def apply_m4a(path: str, t: dict) -> None:
    audio = MP4(path)
    for k, vv in (('title', t.get('title')), ('artist', t.get('artist')),
                  ('album', t.get('album')), ('genre', t.get('genre'))):
        if vv:
            audio[MP4_MAP[k]] = [str(vv)]
    if t.get('year'):
        audio[MP4_MAP['date']] = [str(t['year'])]
    if t.get('track'):
        audio['trkn'] = [(int(t['track']), 0)]
    if t.get('disc'):
        audio['disk'] = [(int(t['disc']), 0)]
    audio.save(path)


def apply_wav(path: str, t: dict) -> None:
    audio = WAVE(path)
    try:
        tags = audio.tags or ID3()
    except Exception:
        tags = ID3()
    from mutagen.id3 import TIT2, TPE1, TALB, TCON, TDRC, TRCK, TPOS
    frames = []
    if t.get('title'):
        frames.append(TIT2(encoding=3, text=t['title']))
    if t.get('artist'):
        frames.append(TPE1(encoding=3, text=t['artist']))
    if t.get('album'):
        frames.append(TALB(encoding=3, text=t['album']))
    if t.get('genre'):
        frames.append(TCON(encoding=3, text=t['genre']))
    if t.get('year'):
        frames.append(TDRC(encoding=3, text=str(t['year'])))
    if t.get('track'):
        frames.append(TRCK(encoding=3, text=str(t['track'])))
    if t.get('disc'):
        frames.append(TPOS(encoding=3, text=str(t['disc'])))
    for f in frames:
        tags.add(f)
    audio.tags = tags
    audio.save()


def main() -> None:
    if len(sys.argv) < 2:
        print('usage: tag_apply.py <plan.json>')
        sys.exit(1)
    with open(sys.argv[1], encoding='utf-8') as f:
        plan = json.load(f)

    ok = 0
    errors = 0
    for t in plan:
        path = t.get('path')
        if not path or not os.path.isfile(path):
            errors += 1
            print(f'MANQUANT: {path}')
            continue
        ext = os.path.splitext(path)[1].lower()
        try:
            if ext == '.mp3':
                apply_mp3(path, t)
            elif ext == '.m4a':
                apply_m4a(path, t)
            elif ext == '.wav':
                apply_wav(path, t)
            elif ext in ('.flac', '.ogg'):
                from mutagen.easyid3 import EasyID3
                audio = EasyID3(path)
                for k, v in (('title', t.get('title')), ('artist', t.get('artist')),
                             ('album', t.get('album')), ('genre', t.get('genre'))):
                    if v:
                        audio[k] = [str(v)]
                audio.save(path)
            else:
                errors += 1
                print(f'SKIP format: {path}')
                continue
            ok += 1
        except Exception as exc:  # noqa: BLE001
            errors += 1
            print(f'ERREUR {path}: {exc}')

    print(f"\nTaggés : {ok}, échecs : {errors}")


if __name__ == '__main__':
    main()