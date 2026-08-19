#!/bin/ash

set -eu
umask 077

fail() {
  printf '%s\n' "PIWIGO_USER_SCRIPT=FAILED code=$1" >&2
  exit 1
}

# The official Piwigo image executes this persistent-volume hook on each
# container start. A complete restore must therefore preserve it; this helper
# is deliberately narrow so an older incomplete restore can be repaired
# without copying arbitrary host files into the container.
[ "$(id -u)" = 0 ] || fail root_required
source=/workspace/infra/piwigo-config/user.sh
target_dir=/usr/local/bin/scripts
target="$target_dir/user.sh"

[ -f "$source" ] && [ ! -L "$source" ] || fail source_untrusted
[ -d "$target_dir" ] && [ ! -L "$target_dir" ] || fail target_directory_untrusted
case "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" in
  *[!0-9:]*|:*|*::*) fail invalid_piwigo_owner ;;
esac
if [ -e "$target" ] && { [ ! -f "$target" ] || [ -L "$target" ]; }; then
  fail target_untrusted
fi

temporary=$(mktemp "$target_dir/.class-archive-user.XXXXXXXX") || fail temporary_create_failed
cleanup() { rm -f -- "$temporary"; }
trap cleanup EXIT HUP INT TERM

source_digest=$(sha256sum "$source" | awk '{print $1}')
cat "$source" > "$temporary" || fail copy_failed
[ "$(sha256sum "$temporary" | awk '{print $1}')" = "$source_digest" ] || fail copy_digest_mismatch
chmod 0755 "$temporary" || fail temporary_mode_failed
chown "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" "$temporary" || fail temporary_owner_failed
mv -f "$temporary" "$target" || fail publish_failed
trap - EXIT HUP INT TERM
[ -f "$target" ] && [ ! -L "$target" ] || fail published_target_untrusted
[ "$(sha256sum "$target" | awk '{print $1}')" = "$source_digest" ] || fail published_digest_mismatch

# Execute the exact pinned hook now as well as leaving it for future image
# restarts. It only normalizes the three mounted media/cache roots.
exec /bin/ash "$target"
