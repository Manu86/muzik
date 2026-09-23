#!/usr/bin/env python3
"""Résolution du catalogue ciblé pour les commandes bin/*.py.

Miroir Python de muzik_cli_config() (bin/lib/bootstrap.php) :
  * « --user <login> » cible le catalogue (db_path) de ce compte, lu dans
    data/users.db ;
  * sans --user, on conserve le comportement historique : data/muzik.db, ou le
    premier utilisateur (ordre alphabétique) si la base des comptes existe.
"""
from __future__ import annotations

import sqlite3
import sys

from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
USERS_DB = ROOT / 'data' / 'users.db'
DEFAULT_DB = ROOT / 'data' / 'muzik.db'


def catalog_db(argv=None):
    """Chemin SQLite du catalogue à traiter (Path), ou sortie en erreur."""
    argv = list(sys.argv[1:] if argv is None else argv)

    user = None
    for index, arg in enumerate(argv):
        if arg == '--user' and index + 1 < len(argv):
            user = argv[index + 1]
            break

    if user is None or user == '':
        first = _first_user_db_path()
        return first if first is not None else DEFAULT_DB

    if not USERS_DB.is_file():
        sys.exit(f'Base des comptes introuvable : {USERS_DB}')
    try:
        conn = sqlite3.connect(USERS_DB)
        try:
            row = conn.execute(
                'SELECT db_path FROM users WHERE login = ?', (user,),
            ).fetchone()
        finally:
            conn.close()
    except sqlite3.Error:
        sys.exit(f'Base des comptes illisible : {USERS_DB}')
    if row is None or not row[0]:
        sys.exit(f'Utilisateur inconnu : {user}')

    return Path(row[0])


def _first_user_db_path():
    if not USERS_DB.is_file():
        return None
    try:
        conn = sqlite3.connect(USERS_DB)
        try:
            row = conn.execute(
                'SELECT db_path FROM users ORDER BY login LIMIT 1',
            ).fetchone()
        finally:
            conn.close()
    except sqlite3.Error:
        return None
    if row is None or not row[0]:
        return None

    return Path(row[0])