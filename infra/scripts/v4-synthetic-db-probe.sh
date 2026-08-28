#!/bin/sh
# Narrow, read-only DB probes for the V4 synthetic migration laboratory.
# This file is mounted only into that isolated MariaDB service. Keeping the
# shell program in a file avoids Windows/WSL re-tokenization of `sh -c`
# strings and never prints a password, SQL dump, or photo metadata.
set -eu

mode="${1:-}"
case "$mode" in
  schema|table-count) ;;
  *)
    printf '%s\n' 'V4_SYNTHETIC_DB_PROBE=FAIL code=mode_invalid' >&2
    exit 64
    ;;
esac

: "${MARIADB_DATABASE:?}"
: "${MARIADB_ROOT_PASSWORD:?}"
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"

if [ "$mode" = 'table-count' ]; then
  exec mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" \
    -e 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'
fi

set -- $(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ]
case "$2" in ''|*[!A-Za-z0-9_]*) exit 23;; esac
exec mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" \
  -e "SELECT COALESCE(MAX(version),0) FROM $2"
