#!/usr/bin/env sh
# Versioned, fail-closed Class Archive recovery contracts.
#
# Format 8 is the historical ClassIdentity schema-v16 recovery contract.  It
# remains immutable so an old synthetic drill can still be interpreted exactly
# as it was recorded.  Format 9 is an additive schema-v17 contract; it is the
# first format that includes the Photos App v4 collection business state.
# Format 10 is the additive schema-v18 contract and is the first format that
# preserves the durable server-side Spotlight rotation checkpoint. Historical
# formats are intentionally not widened when a later schema adds business truth.
#
# This file intentionally contains no environment expansion and no caller
# supplied identifiers.  Consumers must still resolve and validate their own
# ClassIdentity table prefix before interpolating the suffixes below.

CA_RECOVERY_REBUILDABLE_TABLES='read_projection read_photo'

ca_recovery_select_by_format() {
  case "${1:-}" in
    8)
      CA_RECOVERY_FORMAT=8
      CA_RECOVERY_SCHEMA_VERSION=16
      CA_RECOVERY_CONTRACT='FORMAT_8_SCHEMA_16'
      CA_RECOVERY_BUSINESS_TABLES='migration identity seat account principal token operation audit_event role_group rate_limit_bucket submission archive_image photo person person_merge person_photo_rule album spotlight photo_source photo_source_presentation photo_duplicate batch_operation batch_operation_item private_library_collection private_library_folder private_library_import private_library_import_item photo_comment auto_collection auto_collection_photo ai_asset_index ai_index_job native_source_epoch'
      CA_RECOVERY_SCHEMA_JSON='{"version":16,"business_tables":["migration","identity","seat","account","principal","token","operation","audit_event","role_group","rate_limit_bucket","submission","archive_image","photo","person","person_merge","person_photo_rule","album","spotlight","photo_source","photo_source_presentation","photo_duplicate","batch_operation","batch_operation_item","private_library_collection","private_library_folder","private_library_import","private_library_import_item","photo_comment","auto_collection","auto_collection_photo","ai_asset_index","ai_index_job","native_source_epoch"],"rebuildable_projection_tables":["read_projection","read_photo"],"projection_rebuild":"ALL"}'
      ;;
    9)
      CA_RECOVERY_FORMAT=9
      CA_RECOVERY_SCHEMA_VERSION=17
      CA_RECOVERY_CONTRACT='FORMAT_9_SCHEMA_17'
      CA_RECOVERY_BUSINESS_TABLES='migration identity seat account principal token operation audit_event role_group rate_limit_bucket submission archive_image photo person person_merge person_photo_rule album spotlight photo_source photo_source_presentation photo_duplicate batch_operation batch_operation_item private_library_collection private_library_folder private_library_import private_library_import_item photo_comment auto_collection auto_collection_photo ai_asset_index ai_index_job native_source_epoch collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state'
      CA_RECOVERY_SCHEMA_JSON='{"version":17,"business_tables":["migration","identity","seat","account","principal","token","operation","audit_event","role_group","rate_limit_bucket","submission","archive_image","photo","person","person_merge","person_photo_rule","album","spotlight","photo_source","photo_source_presentation","photo_duplicate","batch_operation","batch_operation_item","private_library_collection","private_library_folder","private_library_import","private_library_import_item","photo_comment","auto_collection","auto_collection_photo","ai_asset_index","ai_index_job","native_source_epoch","collection_snapshot","collection_snapshot_item","collection_snapshot_pointer","collection_pin","collection_feedback","collection_maintenance_state"],"rebuildable_projection_tables":["read_projection","read_photo"],"projection_rebuild":"ALL"}'
      ;;
    10)
      CA_RECOVERY_FORMAT=10
      CA_RECOVERY_SCHEMA_VERSION=18
      CA_RECOVERY_CONTRACT='FORMAT_10_SCHEMA_18'
      CA_RECOVERY_BUSINESS_TABLES='migration identity seat account principal token operation audit_event role_group rate_limit_bucket submission archive_image photo person person_merge person_photo_rule album spotlight photo_source photo_source_presentation photo_duplicate batch_operation batch_operation_item private_library_collection private_library_folder private_library_import private_library_import_item photo_comment auto_collection auto_collection_photo ai_asset_index ai_index_job native_source_epoch collection_snapshot collection_snapshot_item collection_snapshot_pointer collection_pin collection_feedback collection_maintenance_state spotlight_rotation_state'
      CA_RECOVERY_SCHEMA_JSON='{"version":18,"business_tables":["migration","identity","seat","account","principal","token","operation","audit_event","role_group","rate_limit_bucket","submission","archive_image","photo","person","person_merge","person_photo_rule","album","spotlight","photo_source","photo_source_presentation","photo_duplicate","batch_operation","batch_operation_item","private_library_collection","private_library_folder","private_library_import","private_library_import_item","photo_comment","auto_collection","auto_collection_photo","ai_asset_index","ai_index_job","native_source_epoch","collection_snapshot","collection_snapshot_item","collection_snapshot_pointer","collection_pin","collection_feedback","collection_maintenance_state","spotlight_rotation_state"],"rebuildable_projection_tables":["read_projection","read_photo"],"projection_rebuild":"ALL"}'
      ;;
    *)
      return 1
      ;;
  esac
  CA_RECOVERY_ALL_TABLES="$CA_RECOVERY_BUSINESS_TABLES $CA_RECOVERY_REBUILDABLE_TABLES"
  return 0
}

ca_recovery_select_by_schema() {
  case "${1:-}" in
    16) ca_recovery_select_by_format 8 ;;
    17) ca_recovery_select_by_format 9 ;;
    18) ca_recovery_select_by_format 10 ;;
    *) return 1 ;;
  esac
}

# The caller must first prove this is a regular, non-symlinked manifest in a
# trusted bundle directory. This reader admits only one short JSON line and
# never accepts a format merely because its number looks familiar.
ca_recovery_read_manifest_header() {
  _ca_manifest=${1:-}
  [ -f "$_ca_manifest" ] && [ ! -L "$_ca_manifest" ] || return 1
  [ "$(wc -c < "$_ca_manifest" | tr -d '[:space:]')" -le 8192 ] || return 1
  [ "$(awk 'END { print NR + 0 }' "$_ca_manifest")" = 1 ] || return 1
  _ca_manifest_contents=$(cat "$_ca_manifest")
  _ca_header=$(printf '%s\n' "$_ca_manifest_contents" | sed -n 's/^[{]"format":\([0-9][0-9]*\),"created_at":"\([0-9]\{8\}T[0-9]\{6\}Z\)",.*/\1 \2/p')
  [ "$(printf '%s\n' "$_ca_header" | wc -l | tr -d '[:space:]')" = 1 ] || return 1
  _ca_format=${_ca_header%% *}
  _ca_timestamp=${_ca_header#* }
  [ "$_ca_format" != "$_ca_timestamp" ] || return 1
  ca_recovery_select_by_format "$_ca_format" || return 1
}

# Generic Piwigo synthetic bundles have an exact fixed file set. This strict
# selector preserves format-8/schema-16 behavior while allowing the additive
# format-9/schema-17 and format-10/schema-18 equivalents; unknown keys or a different file set fail
# before the generic restore clear_target boundary.
ca_recovery_select_manifest() {
  ca_recovery_read_manifest_header "${1:-}" || return 1
  _ca_expected=$(printf '{"format":%s,"created_at":"%s","class_identity_schema":%s,"files":["database.sql.gz","piwigo-data.tar.gz","uploads.tar.gz","galleries.tar.gz","scripts.tar.gz","COMPLETE"]}' \
    "$CA_RECOVERY_FORMAT" "$_ca_timestamp" "$CA_RECOVERY_SCHEMA_JSON")
  [ "$_ca_manifest_contents" = "$_ca_expected" ]
}

# The schema-v17 migration laboratory intentionally uses a DB-only bundle.
# Keep its additional scope markers exact rather than weakening the generic
# full-media selector above.
ca_recovery_select_v17_synthetic_manifest() {
  ca_recovery_read_manifest_header "${1:-}" || return 1
  [ "$CA_RECOVERY_FORMAT" = 9 ] && [ "$CA_RECOVERY_SCHEMA_VERSION" = 17 ] || return 1
  _ca_expected=$(printf '{"format":9,"created_at":"%s","class_identity_schema":%s,"files":["database.sql.gz","COMPLETE"],"scope":"DB_ONLY_SYNTHETIC_V17_RECOVERY","media":"NOT_MOUNTED","media_guard":"NOT_CLAIMED"}' \
    "$_ca_timestamp" "$CA_RECOVERY_SCHEMA_JSON")
  [ "$_ca_manifest_contents" = "$_ca_expected" ]
}
