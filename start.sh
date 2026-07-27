#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")"
if ! php -m | grep -qi pdo_sqlite; then
  echo "PDO SQLite belum aktif. Aktifkan extension pdo_sqlite terlebih dahulu."
  exit 1
fi
php -S 127.0.0.1:8080 router.php
