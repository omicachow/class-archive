#!/usr/bin/env bash
set -euo pipefail
umask 077
export LC_ALL=C

fail() { printf '%s\n' "PORTABLE_RECOVERY_DECRYPT=FAIL code=$1" >&2; exit 1; }
[ "$#" -eq 2 ] || fail usage
command -v realpath >/dev/null 2>&1 || fail realpath_missing
bundle=$(realpath -e -- "$1") || fail bundle_untrusted
raw_output=$2
output_parent=$(realpath -e -- "${raw_output%/*}") || fail secret_output_untrusted
output=$output_parent/${raw_output##*/}
case "${bundle##*/}" in owner-full-v2-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) fail bundle_untrusted ;; esac
[ -d "$bundle" ] && [ ! -L "$bundle" ] || fail bundle_untrusted
kit=$bundle/recovery-kit
[ -d "$kit" ] && [ ! -L "$kit" ] || fail kit_untrusted
[ ! -e "$output" ] && [ -d "$output_parent" ] && [ ! -L "$output_parent" ] || fail secret_output_untrusted
case "$output" in "$bundle"/*) fail secret_output_untrusted ;; esac

expected='README-PORTABLE-RESTORE.txt
container-lock.json
manifest.json
migration-info.json
ml-artifact-manifest.json
portable-key-envelope.gpg
restore.ps1
restore.sh'
actual=$(sed -n 's/^[0-9a-f]\{64\}  \([A-Za-z0-9._-]*\)$/\1/p' "$kit/checksums.sha256" | sort) || fail kit_checksums_invalid
[ "$actual" = "$expected" ] || fail kit_inventory_invalid
(cd "$kit" && sha256sum --quiet -c checksums.sha256) || fail kit_sha256_mismatch
command -v gpg >/dev/null 2>&1 || fail gpg_missing
command -v python3 >/dev/null 2>&1 || fail python3_missing

phrase_file=$(mktemp /tmp/class-archive-portable-phrase.XXXXXXXX) || fail temp_failed
cleanup() { rm -f -- "$phrase_file"; }
trap cleanup EXIT HUP INT TERM
IFS= read -r -s -p 'Enter portable recovery phrase (hidden): ' phrase || fail secure_user_secret_entry_unavailable
printf '\n' >&2
[ -n "$phrase" ] || fail portable_phrase_invalid
case "$phrase" in *$'\n'*|*$'\r'*) fail portable_phrase_invalid ;; esac
printf '%s\n' "$phrase" > "$phrase_file"
phrase=
gpg_home=$(mktemp -d /tmp/class-archive-portable-gpg.XXXXXXXX) || fail temp_failed
chmod 0700 "$gpg_home"
GNUPGHOME=$gpg_home gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$phrase_file" \
  --decrypt --output "$output" "$kit/portable-key-envelope.gpg" 2>/dev/null || { rm -rf -- "$gpg_home"; fail envelope_decrypt_failed; }
rm -rf -- "$gpg_home"
chmod 0600 "$output"
python3 - "$output" "${bundle##*/}" <<'PY' || { rm -f -- "$output"; fail decrypted_payload_contract_invalid; }
import json, sys
with open(sys.argv[1], 'r', encoding='utf-8') as handle:
    payload = json.load(handle)
required = {'anonymous_pseudonym_secret','claim_code_pepper','gpg_passphrase','piwigo_db_password'}
assert payload.get('format') == 'owner-portable-recovery-secrets-v1'
assert payload.get('version') == 1
assert payload.get('backup_id') == sys.argv[2]
assert payload.get('scope') == 'OWNER_PRIVATE_FULL'
assert set(payload.get('secrets', {})) == required
assert all(isinstance(value, str) and 32 <= len(value) <= 512 and '\n' not in value and '\r' not in value for value in payload['secrets'].values())
PY
printf '%s\n' "PORTABLE_RECOVERY_DECRYPT=PASS backup_id=${bundle##*/} dpapi_used=NO"
