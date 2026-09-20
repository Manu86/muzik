#!/bin/bash
# Ajoute le dépôt GitHub distant et pousse la branche main.
# Utilise SSH (clé) par défaut, car GitHub refuse le mot de passe HTTPS.
# Prérequis : le dépôt https://github.com/Manu86/muzik existe et votre clé
# publique SSH est enregistrée dans GitHub (Settings → SSH and GPG keys).
# Alternative HTTPS : exporter MUZIK_GITHUB_URL=https://github.com/Manu86/muzik.git
# et utiliser une clé d'accès personnelle (PAT) au lieu du mot de passe.
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(dirname -- "$SCRIPT_DIR")
REPO_URL=${MUZIK_GITHUB_URL:-git@github.com:Manu86/muzik.git}
REPO_SLUG=Manu86/muzik

echo "Vérification de l'existence du dépôt GitHub (sans invite de connexion)…"
if ! GIT_TERMINAL_PROMPT=0 GIT_SSH_COMMAND='ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new' \
  git ls-remote "$REPO_URL" HEAD >/dev/null 2>&1; then
  echo "Impossible de joindre le dépôt $REPO_URL." >&2
  echo "Causes possibles :" >&2
  echo " 1. Le dépôt https://github.com/$REPO_SLUG n'est pas encore créé (créez-le vide sur GitHub)." >&2
  echo " 2. Votre clé SSH n'est pas enregistrée dans GitHub (Settings → SSH and GPG keys)." >&2
  echo "Vérifiez avec: ssh -T git@github.com" >&2
  exit 1
fi

CURRENT=$(git -C "$PROJECT_ROOT" remote get-url origin 2>/dev/null || true)
if [ -z "$CURRENT" ]; then
  git -C "$PROJECT_ROOT" remote add origin "$REPO_URL"
  echo "Origin ajouté : $REPO_URL"
elif [ "$CURRENT" != "$REPO_URL" ]; then
  git -C "$PROJECT_ROOT" remote set-url origin "$REPO_URL"
  echo "Origin mis à jour : $CURRENT → $REPO_URL"
else
  echo "Origin pointe déjà vers $REPO_URL"
fi

git -C "$PROJECT_ROOT" push -u origin main
echo "OK. Poussé sur https://github.com/$REPO_SLUG (branche main)."