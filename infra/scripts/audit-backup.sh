#!/usr/bin/env sh
set -eu

# Root-only, one-shot verifier for private backup bundles. It never prints or
# copies bundle contents; it publishes only a small non-secret freshness record
# for the unprivileged maintenance runner to consume.

umask 077
LC_ALL=C
export LC_ALL

fail() {
  printf '%s\n' "BACKUP_FRESHNESS=FAILED code=$1" >&2
  exit 1
}

case "${CLASS_ARCHIVE_BACKUP_AUDIT_WRITE:-}" in
  true) ;;
  *) fail backup_audit_not_explicitly_enabled ;;
esac

[ "$(id -u)" = 0 ] || fail backup_audit_requires_root
case "${PIWIGO_UID:-}" in ''|*[!0-9]*) fail invalid_piwigo_uid ;; esac
case "${PIWIGO_GID:-}" in ''|*[!0-9]*) fail invalid_piwigo_gid ;; esac

backup_root=/backup
data_root=/target-data/_data
status_directory="$data_root/class-archive"
status_path="$status_directory/backup-freshness.json"

[ -d "$backup_root" ] && [ ! -L "$backup_root" ] || fail backup_root_untrusted
[ -d "$data_root" ] && [ ! -L "$data_root" ] || fail data_root_untrusted
if [ ! -d "$status_directory" ]; then
  mkdir "$status_directory" || fail status_directory_create_failed
  chown "$PIWIGO_UID:$PIWIGO_GID" "$status_directory" || fail status_directory_owner_failed
  chmod 0770 "$status_directory" || fail status_directory_mode_failed
fi
[ -d "$status_directory" ] && [ ! -L "$status_directory" ] || fail status_directory_untrusted
if [ -e "$status_path" ] && { [ ! -f "$status_path" ] || [ -L "$status_path" ]; }; then
  fail status_path_untrusted
fi

latest_bundle=
latest_name=
for candidate in "$backup_root"/class-archive-*; do
  [ -d "$candidate" ] && [ ! -L "$candidate" ] || continue
  name=${candidate##*/}
  if ! printf '%s' "$name" | grep -Eq '^class-archive-[0-9]{8}T[0-9]{6}Z$'; then
    continue
  fi
  [ -f "$candidate/COMPLETE" ] && [ ! -L "$candidate/COMPLETE" ] || continue
  [ -f "$candidate/SHA256SUMS" ] && [ ! -L "$candidate/SHA256SUMS" ] || continue
  if [ -z "$latest_name" ] || [ "$name" \> "$latest_name" ]; then
    latest_bundle=$candidate
    latest_name=$name
  fi
done

state=MISSING
bundle_name=
backup_timestamp=
age_seconds=0
verified_files=0
if [ -n "$latest_bundle" ]; then
  bundle_name=$latest_name
  manifest="$latest_bundle/SHA256SUMS"
  expected_count=7
  actual_count=$(wc -l < "$manifest" | tr -d '[:space:]')
  business_manifest=0
  schema_contract='"class_identity_schema":{"version":12,"business_tables":["migration","identity","seat","account","principal","token","operation","audit_event","role_group","rate_limit_bucket","submission","archive_image","photo","person","person_merge","person_photo_rule","album","spotlight","photo_source","photo_duplicate","batch_operation","batch_operation_item","native_source_epoch"],"rebuildable_projection_tables":["read_projection","read_photo"],"projection_rebuild":"ALL"}'
  if [ -f "$latest_bundle/MANIFEST.json" ] && [ ! -L "$latest_bundle/MANIFEST.json" ] \
     && grep -Eq '^\{"format":6,"created_at":"[0-9]{8}T[0-9]{6}Z",' "$latest_bundle/MANIFEST.json" \
     && grep -Fq "$schema_contract" "$latest_bundle/MANIFEST.json"; then
    business_manifest=1
  fi
  valid_count=$(grep -Ec '^[0-9a-f]{64}  (database\.sql\.gz|piwigo-data\.tar\.gz|uploads\.tar\.gz|galleries\.tar\.gz|scripts\.tar\.gz|COMPLETE|MANIFEST\.json)$' "$manifest" || true)
  projection_cache_free=0
  if ! gzip -dc "$latest_bundle/database.sql.gz" | grep -Eq '^INSERT INTO `[^`]+class_identity_(read_projection|read_photo)`'; then
    projection_cache_free=1
  fi
  durable_epoch_valid=0
  epoch_insert_stats=$(gzip -dc "$latest_bundle/database.sql.gz" | awk '
    function tuple_count(row, i, char, quoted, escaped, depth, tuples) {
      quoted=0; escaped=0; depth=0; tuples=0
      for (i=1; i<=length(row); i++) {
        char=substr(row,i,1)
        if (quoted) {
          if (escaped) escaped=0
          else if (char == "\\") escaped=1
          else if (char == "\047") quoted=0
        } else if (char == "\047") quoted=1
        else if (char == "(") { if (depth == 0) tuples++; depth++ }
        else if (char == ")") { depth--; if (depth < 0) return -1 }
      }
      return quoted || depth != 0 ? -1 : tuples
    }
    function inspect(row) {
      rows++
      if (row ~ /^\(\047PIWIGO_NATIVE\047,/ && row ~ /\);$/ && tuple_count(row) == 1) valid++
    }
    /^INSERT INTO `[^`]+class_identity_native_source_epoch` VALUES[[:space:]]*\(/ {
      headers++
      row=$0
      sub(/^.* VALUES[[:space:]]*/, "", row)
      inspect(row)
      next
    }
    /^INSERT INTO `[^`]+class_identity_native_source_epoch` VALUES[[:space:]]*$/ {
      headers++
      expect=1
      next
    }
    expect { inspect($0); expect=0 }
    END { print headers+0, rows+0, valid+0 }
  ')
  if gzip -dc "$latest_bundle/database.sql.gz" \
       | awk '/^CREATE TABLE `[^`]+class_identity_native_source_epoch` \(/ { capture=1 } capture { print } capture && /^\) ENGINE=/ { exit }' \
       | grep -Eq '^\) ENGINE=MyISAM ' \
     && [ "$epoch_insert_stats" = '1 1 1' ]; then
    durable_epoch_valid=1
  fi
  native_guard_count=$(gzip -dc "$latest_bundle/database.sql.gz" \
    | grep -Eo 'TRIGGER `?[A-Za-z0-9_]+ci_(projection|source_epoch)_(images|image_category|categories)_b(i|u|d)`?' \
    | sort -u | wc -l | tr -d '[:space:]')
  if [ "$actual_count" = "$expected_count" ] && [ "$valid_count" = "$expected_count" ] \
     && [ "$business_manifest" = 1 ] \
     && [ "$projection_cache_free" = 1 ] \
     && [ "$durable_epoch_valid" = 1 ] \
     && [ "$native_guard_count" = 18 ] \
     && grep -Eq '^[0-9a-f]{64}  database\.sql\.gz$' "$manifest" \
     && grep -Eq '^[0-9a-f]{64}  piwigo-data\.tar\.gz$' "$manifest" \
     && grep -Eq '^[0-9a-f]{64}  uploads\.tar\.gz$' "$manifest" \
     && grep -Eq '^[0-9a-f]{64}  galleries\.tar\.gz$' "$manifest" \
     && grep -Eq '^[0-9a-f]{64}  scripts\.tar\.gz$' "$manifest" \
     && grep -Eq '^[0-9a-f]{64}  COMPLETE$' "$manifest" \
     && grep -Eq '^[0-9a-f]{64}  MANIFEST\.json$' "$manifest" \
     && (cd "$latest_bundle" && sha256sum -c SHA256SUMS >/dev/null 2>&1); then
    complete="$latest_bundle/COMPLETE"
    mtime=$(stat -c %Y "$complete")
    now=$(date +%s)
    age_seconds=$((now - mtime))
    [ "$age_seconds" -ge 0 ] || age_seconds=0
    backup_timestamp=$(date -u -r "$complete" '+%Y-%m-%dT%H:%M:%SZ')
    verified_files=$expected_count
    if [ "$age_seconds" -le 604800 ]; then
      state=FRESH
    else
      state=STALE
    fi
  else
    state=INVALID
  fi
fi

generated_at=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
temporary=$(mktemp "$status_directory/.backup-freshness.XXXXXXXX") || fail status_temp_create_failed
cleanup() { rm -f -- "$temporary"; }
trap cleanup EXIT HUP INT TERM
printf '{"backup_audit_version":1,"timestamp":"%s","state":"%s","bundle":%s,"backup_timestamp":%s,"age_seconds":%s,"verified_files":%s}\n' \
  "$generated_at" "$state" \
  "$(if [ -n "$bundle_name" ]; then printf '"%s"' "$bundle_name"; else printf 'null'; fi)" \
  "$(if [ -n "$backup_timestamp" ]; then printf '"%s"' "$backup_timestamp"; else printf 'null'; fi)" \
  "$age_seconds" "$verified_files" > "$temporary"
chown "$PIWIGO_UID:$PIWIGO_GID" "$temporary" || fail status_owner_failed
# The record has no paths, checksums or secrets, but it still lives under the
# private `_data` tree. Keep it non-world-readable: the directory's inherited
# nginx ACL lets PHP-FPM and the restricted CLI runner read it, while the
# private backup bundle remains root-only and unmounted from Piwigo.
chmod 0660 "$temporary" || fail status_mode_failed
mv -f "$temporary" "$status_path" || fail status_publish_failed
trap - EXIT HUP INT TERM
printf '%s\n' "BACKUP_FRESHNESS=$state verified_files=$verified_files"
