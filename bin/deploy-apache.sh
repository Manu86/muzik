#!/bin/bash
# Active Muzik (/muzik) dans Apache. À exécuter avec sudo.
set -euo pipefail

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(dirname -- "$SCRIPT_DIR")
INSTALL=${MUZIK_APACHE_CONFIG:-"$PROJECT_ROOT/config/apache-muzik.conf"}
TARGET=${MUZIK_APACHE_TARGET:-/etc/apache2/conf-available/muzik.conf}

if [ "$(id -u)" != "0" ]; then
  echo "À exécuter en root : sudo $0" >&2
  exit 1
fi

if [ ! -f "$INSTALL" ]; then
  echo "Configuration Apache absente : $INSTALL" >&2
  echo "Copiez config/apache-muzik.conf.example vers config/apache-muzik.conf puis adaptez les chemins." >&2
  exit 1
fi

cp "$INSTALL" "$TARGET"
a2enconf muzik
apache2ctl configtest
systemctl reload apache2

IP=$(hostname -I | awk '{print $1}')
echo "OK. Accès : http://$IP/muzik"
