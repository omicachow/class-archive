#!/usr/bin/env bash
set -euo pipefail

# Minimal GnuPG boundary used by owner-portable-recovery.ps1. Plaintext secret
# payloads and user-entered passphrases are accepted only inside an ignored,
# owner-ACL-protected work directory. The helper emits no filenames or secret
# material and never accesses Docker, the photo sources, or a published bundle.

umask 077
export LC_ALL=C

fail() {
  printf '%s\n' "OWNER_PORTABLE_RECOVERY_HELPER=FAIL code=$1" >&2
  exit 1
}

mode=${1:-}
shift || true
case "$mode" in encrypt|decrypt) ;; *) fail action_invalid ;; esac

work_root=
input=
passphrase_file=
output=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --work-root) [ "$#" -ge 2 ] || fail argument_missing; work_root=$2; shift 2 ;;
    --input) [ "$#" -ge 2 ] || fail argument_missing; input=$2; shift 2 ;;
    --passphrase-file) [ "$#" -ge 2 ] || fail argument_missing; passphrase_file=$2; shift 2 ;;
    --output) [ "$#" -ge 2 ] || fail argument_missing; output=$2; shift 2 ;;
    *) fail argument_invalid ;;
  esac
done

[ -n "$work_root" ] && [ -n "$input" ] && [ -n "$passphrase_file" ] && [ -n "$output" ] \
  || fail argument_missing
[ -d "$work_root" ] && [ ! -L "$work_root" ] || fail work_root_untrusted
case "$work_root" in
  /mnt/c/*/.codex-work/private-real-full/runtime/owner-temporary-backup/*) ;;
  /tmp/class-archive-portable-protocol-*) ;;
  *) fail work_root_invalid ;;
esac

assert_direct_child() {
  path=$1 expected_leaf=$2
  [ "${path%/*}" = "$work_root" ] || fail path_outside_work_root
  [ "${path##*/}" = "$expected_leaf" ] || fail path_name_invalid
}

assert_direct_child "$passphrase_file" portable-recovery-passphrase.txt
[ -f "$passphrase_file" ] && [ ! -L "$passphrase_file" ] || fail passphrase_file_untrusted
[ "$(wc -l < "$passphrase_file" | tr -d '[:space:]')" = 1 ] || fail passphrase_file_invalid
[ "$(wc -c < "$passphrase_file" | tr -d '[:space:]')" -ge 20 ] || fail passphrase_file_invalid
[ "$(wc -c < "$passphrase_file" | tr -d '[:space:]')" -le 1024 ] || fail passphrase_file_invalid

command -v gpg >/dev/null 2>&1 || fail gpg_missing
gpg --version 2>/dev/null | grep -Eq '^gpg \(GnuPG\) 2\.[234]\.' || fail gpg_version_invalid
gpg --version 2>/dev/null | grep -Eq '^Cipher:.*AES256' || fail gpg_aes256_unavailable
gpg_home=$(mktemp -d /tmp/class-archive-portable-gpg.XXXXXXXX) || fail gpg_temp_failed
chmod 0700 "$gpg_home"
export GNUPGHOME="$gpg_home"
cleanup() { rm -rf -- "$gpg_home"; }
trap cleanup EXIT HUP INT TERM

case "$mode" in
  encrypt)
    assert_direct_child "$input" portable-secret-payload.json
    assert_direct_child "$output" portable-key-envelope.gpg
    [ -f "$input" ] && [ ! -L "$input" ] && [ "$(stat -c %s "$input")" -gt 0 ] || fail payload_untrusted
    [ ! -e "$output" ] || fail output_exists
    gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$passphrase_file" \
      --symmetric --cipher-algo AES256 --s2k-mode 3 --s2k-digest-algo SHA512 --s2k-count 65011712 \
      --compress-algo none --force-mdc --output "$output" "$input" 2>/dev/null \
      || fail envelope_encrypt_failed
    [ -f "$output" ] && [ ! -L "$output" ] && [ "$(stat -c %s "$output")" -gt 0 ] || fail envelope_invalid
    ;;
  decrypt)
    assert_direct_child "$input" portable-key-envelope.gpg
    assert_direct_child "$output" portable-secret-payload.json
    [ -f "$input" ] && [ ! -L "$input" ] && [ "$(stat -c %s "$input")" -gt 0 ] || fail envelope_untrusted
    [ -f "$output" ] && [ ! -L "$output" ] && [ "$(stat -c %s "$output")" -eq 0 ] || fail output_untrusted
    gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$passphrase_file" \
      --decrypt --output "$output" "$input" 2>/dev/null || fail envelope_decrypt_failed
    [ -f "$output" ] && [ ! -L "$output" ] && [ "$(stat -c %s "$output")" -gt 0 ] || fail payload_invalid
    ;;
esac

printf '%s\n' "OWNER_PORTABLE_RECOVERY_HELPER=PASS action=$mode"
