#!/usr/bin/env sh
# Emit a non-secret, DB-only format-9 recovery fingerprint. It deliberately
# hashes all JSON/text preference payloads and never opens a media volume.

set -eu
umask 077
LC_ALL=C
export LC_ALL

fail() {
  printf '%s\n' "V17_SYNTHETIC_RECOVERY_FIXTURE=FAIL code=$1" >&2
  exit 1
}

[ "${CLASS_ARCHIVE_V17_SYNTHETIC_RECOVERY:-}" = 1 ] || fail scope_confirmation_missing
[ "${CLASS_ARCHIVE_RUNTIME_SCOPE:-}" = SYNTHETIC_V4_MIGRATION ] || fail runtime_scope_invalid
case "${DB_HOST:-}:${DB_NAME:-}:${DB_ROOT_PASSWORD:-}" in
  db:*|v17-synthetic-recovery-db:*) ;;
  *) fail database_configuration_invalid ;;
esac
case "${DB_NAME:-}" in ''|*[!A-Za-z0-9_]*) fail database_configuration_invalid ;; esac
case "${DB_ROOT_PASSWORD:-}" in ''|*[!A-Za-z0-9_-]*) fail database_configuration_invalid ;; esac

. /workspace/infra/scripts/class-archive-recovery-contracts.sh
ca_recovery_select_by_format 9 || fail recovery_contract_unavailable
export MYSQL_PWD="$DB_ROOT_PASSWORD"
temporary=$(mktemp /tmp/class-archive-v17-fixture.XXXXXX) || fail fixture_temp_unavailable
cleanup() { unset MYSQL_PWD || true; rm -f -- "$temporary"; }
trap cleanup EXIT HUP INT TERM

set -- $(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COUNT(*),COALESCE(MIN(TABLE_NAME),'') FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME REGEXP '^[A-Za-z0-9_]+class_identity_migration$'")
[ "$#" = 2 ] && [ "$1" = 1 ] || fail migration_table_ambiguous
ci_migration=$2
case "$ci_migration" in ''|*[!A-Za-z0-9_]*) fail migration_table_invalid ;; esac
ci_base=${ci_migration%migration}
ci_version=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
  -e "SELECT COALESCE(MAX(version),0) FROM \`$ci_migration\`")
[ "$ci_version" = "$CA_RECOVERY_SCHEMA_VERSION" ] || fail schema_not_v17
for suffix in collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state; do
  count=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" \
    -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$ci_base$suffix'")
  [ "$count" = 1 ] || fail collection_table_missing
done

append_table() {
  name=$1
  query=$2
  count=$(mariadb --batch --skip-column-names --host="$DB_HOST" --user=root "$DB_NAME" -e "SELECT COUNT(*) FROM \`${ci_base}${name}\`")
  case "$count" in ''|*[!0-9]*) fail fixture_count_invalid ;; esac
  digest=$(mariadb --batch --skip-column-names --raw --host="$DB_HOST" --user=root "$DB_NAME" -e "$query" | sha256sum | awk '{print $1}')
  printf '%s\n' "$digest" | grep -Eq '^[0-9a-f]{64}$' || fail fixture_digest_invalid
  if [ "$first" = 1 ]; then first=0; else printf ',' >> "$temporary"; fi
  printf '"%s":{"count":%s,"sha256":"%s"}' "$name" "$count" "$digest" >> "$temporary"
}

printf '{"fixture_version":9,"class_identity_schema_version":17,"backup_manifest_format":9,"recovery_contract":"FORMAT_9_SCHEMA_17","scope":"DB_ONLY_SYNTHETIC_V17_RECOVERY","summary":{' > "$temporary"
first=1
append_table collection_snapshot "SELECT HEX(\`snapshot_id\`),\`scope\`,\`projection_kind\`,\`state\`,HEX(\`input_revision\`),HEX(\`payload_digest\`),\`item_count\`,\`created_at\`,\`published_at\`,\`superseded_at\`,\`updated_at\` FROM \`${ci_base}collection_snapshot\` ORDER BY \`scope\`,\`projection_kind\`,\`snapshot_id\`"
append_table collection_snapshot_item "SELECT HEX(\`snapshot_id\`),\`ordinal\`,\`item_kind\`,SHA2(CAST(\`item_key\` AS CHAR),256),HEX(\`cover_class_photo_id\`),SHA2(CAST(\`photo_ids_json\` AS CHAR),256),SHA2(CAST(\`payload_json\` AS CHAR),256),SHA2(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(\`payload_json\`,'$.title')),''),256),HEX(\`payload_digest\`),\`created_at\` FROM \`${ci_base}collection_snapshot_item\` ORDER BY \`snapshot_id\`,\`ordinal\`"
append_table collection_snapshot_pointer "SELECT \`scope\`,\`projection_kind\`,HEX(\`active_snapshot_id\`),HEX(\`active_revision\`),\`activated_at\`,\`updated_at\` FROM \`${ci_base}collection_snapshot_pointer\` ORDER BY \`scope\`,\`projection_kind\`"
append_table collection_pin "SELECT HEX(\`pin_id\`),SHA2(CAST(\`principal_id\` AS CHAR),256),\`scope\`,\`projection_kind\`,\`item_kind\`,SHA2(CAST(\`item_key\` AS CHAR),256),\`ordinal\`,\`state\`,\`created_at\`,\`updated_at\`,\`removed_at\` FROM \`${ci_base}collection_pin\` ORDER BY \`pin_id\`"
append_table collection_feedback "SELECT HEX(\`feedback_id\`),SHA2(CAST(\`principal_id\` AS CHAR),256),\`scope\`,\`projection_kind\`,\`item_kind\`,SHA2(CAST(\`item_key\` AS CHAR),256),\`feedback_kind\`,\`state\`,\`created_at\`,\`updated_at\`,\`retracted_at\` FROM \`${ci_base}collection_feedback\` ORDER BY \`feedback_id\`"
append_table collection_maintenance_state "SELECT \`maintenance_key\`,\`state\`,HEX(\`last_input_revision\`),HEX(\`last_snapshot_id\`),\`started_at\`,\`completed_at\`,\`last_error_code\`,\`created_at\`,\`updated_at\` FROM \`${ci_base}collection_maintenance_state\` ORDER BY \`maintenance_key\`"
printf '}}' >> "$temporary"
fixture_sha=$(sha256sum "$temporary" | awk '{print $1}')
printf '%s\n' "$fixture_sha" | grep -Eq '^[0-9a-f]{64}$' || fail fixture_digest_invalid
payload=$(cat "$temporary")
printf '%s,"fixture_sha256":"%s"}\n' "${payload%?}" "$fixture_sha"
