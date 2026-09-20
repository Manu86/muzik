#!/bin/bash
# Retire la protection HTTP Basic de Caddy pour Muzik (/muzik).
# L'authentification est désormais gérée par l'application PHP (session + bcrypt).
# À exécuter avec sudo.
set -euo pipefail

CONFIG=${MUZIK_CADDY_CONFIG:-/etc/caddy/Caddyfile}

if [ "$(id -u)" != "0" ]; then
  echo "À exécuter en root : sudo $0" >&2
  exit 1
fi

if [ ! -f "$CONFIG" ]; then
  echo "Configuration Caddy absente : $CONFIG" >&2
  exit 1
fi

if ! grep -qE '^[[:space:]]*basic_auth[[:space:]]*\{' "$CONFIG"; then
  echo "Aucun bloc basic_auth dans $CONFIG : rien à faire."
  exit 0
fi

BACKUP="$CONFIG.bak.muzik-auth.$(date +%s)"
cp "$CONFIG" "$BACKUP"
echo "Sauvegarde : $BACKUP"

tmp=$(mktemp)
awk '
  /^[[:space:]]*basic_auth[[:space:]]*\{/ { skip = 1 }
  skip { if ($0 ~ /^[[:space:]]*\}$/) skip = 0; next }
  { print }
' "$CONFIG" > "$tmp"
cat "$tmp" > "$CONFIG"
rm -f "$tmp"

if command -v caddy >/dev/null 2>&1; then
  caddy validate --config "$CONFIG"
fi

systemctl reload caddy
echo "OK : basic_auth retiré de $CONFIG et Caddy rechargé."
