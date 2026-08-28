#!/usr/bin/env sh
set -eu

# Read-only schema probe for the fixed V18 synthetic laboratory. It has no
# caller-supplied host/path/table selector and never emits credentials.

fail() {
  printf '%s\n' "V18_SYNTHETIC_DB_PROBE=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_RUNTIME_SCOPE:-}" = SYNTHETIC_V4_MIGRATION ] || fail scope_confirmation_missing
[ "${CLASS_ARCHIVE_V18_SYNTHETIC_PROOF:-}" = 1 ] || fail proof_confirmation_missing
case "${1:-}" in
  schema|table-count) ;;
  *) fail mode_invalid ;;
esac
case "${DB_NAME:-}" in
  piwigo) ;;
  *) fail database_invalid ;;
esac
[ -n "${DB_ROOT_PASSWORD:-}" ] || fail root_password_missing

case "$1" in
  schema)
    value=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -uroot "$DB_NAME" -e "SELECT COALESCE(MAX(\`version\`),0) FROM \`piwigo_class_identity_migration\`" 2>/dev/null) || fail query_failed
    case "$value" in
      16|17|18) printf '%s\n' "$value" ;;
      *) fail schema_invalid ;;
    esac
    ;;
  table-count)
    value=$(MYSQL_PWD="$DB_ROOT_PASSWORD" mariadb -N -B -uroot information_schema -e "SELECT COUNT(*) FROM TABLES WHERE TABLE_SCHEMA='${DB_NAME}'" 2>/dev/null) || fail query_failed
    case "$value" in
      ''|*[!0-9]*) fail count_invalid ;;
      *) printf '%s\n' "$value" ;;
    esac
    ;;
esac
