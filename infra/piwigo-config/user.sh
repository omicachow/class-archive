#!/bin/ash

set -eu

# The official image grants the nginx account an explicit ACL, so media trees
# do not need world permissions. Keep existing entries private and make the
# same rule the default for new directories and files.
for media_path in \
  /var/www/html/piwigo/upload \
  /var/www/html/piwigo/galleries \
  /var/www/html/piwigo/_data
do
  mkdir -p "$media_path"
  find "$media_path" -type d -exec \
    setfacl -m u:nginx:rwx,o::---,d:u:nginx:rwx,d:o::--- '{}' +
  # Core may create derivatives as 0644 and inherited ACL masks can retain a
  # group execute bit. Normalize all existing media/cache files to private,
  # non-executable data before applying the nginx service-account ACL.
  find "$media_path" -type f -exec chmod 0660 '{}' +
  find "$media_path" -type f -exec \
    setfacl -m u:nginx:rw-,g::rw-,m::rw-,o::--- '{}' +
done

# A container-image upgrade may change the numeric nginx uid while the Piwigo
# volume retains its configured PIWIGO_UID owner. Warmup markers are durable
# work records, so migrate only entries whose regular-file identity, mode,
# link count, canonical filename and exact path-free JSON payload all verify.
# Strictly named .pending-<24hex> files are internal atomic-write remnants.
# The queue implementation takes its filesystem lock, recovers a complete exact
# payload, or moves a small trusted partial payload byte-for-byte to private
# quarantine. Unknown/untrusted entries are never deleted or rewritten:
# startup fails closed for operator inspection.
warmup_queue=/var/www/html/piwigo/_data/class-archive/derivative-warmup
warmup_lock=/var/www/html/piwigo/_data/class-archive/derivative-warmup.lock
warmup_class=/var/www/html/piwigo/plugins/ClassArchivePolicy/src/DerivativeWarmupQueue.php

# The lock is a permanent empty inode outside the scanned queue. Validate its
# complete identity before changing an older runtime uid; this must happen
# before invoking the locked PHP recovery below.
if [ -e "$warmup_lock" ]; then
  [ -f "$warmup_lock" ] && [ ! -L "$warmup_lock" ] \
    && [ "$(stat -c '%h' "$warmup_lock")" = 1 ] \
    && [ "$(stat -c '%s' "$warmup_lock")" = 0 ] \
    && [ "$(stat -c '%a' "$warmup_lock")" = 660 ] || exit 67
  chown "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" "$warmup_lock" || exit 68
  chmod 0660 "$warmup_lock" || exit 69
fi

if [ -e "$warmup_queue" ]; then
  [ -d "$warmup_queue" ] && [ ! -L "$warmup_queue" ] || exit 71
  queue_owner=$(stat -c '%u' "$warmup_queue")
  configured_owner=${PIWIGO_UID:-1000}
  find "$warmup_queue" -mindepth 1 -maxdepth 1 -print | while IFS= read -r marker; do
    [ -f "$marker" ] && [ ! -L "$marker" ] || exit 72
    [ "$(stat -c '%h' "$marker")" = 1 ] && [ "$(stat -c '%a' "$marker")" = 660 ] || exit 73
    name=${marker##*/}
    if printf '%s\n' "$name" | grep -Eq '^[0-9a-f]{12}[1-5][0-9a-f]{3}[89ab][0-9a-f]{15}-[1-9][0-9]{0,9}\.pending$'; then
      stem=${name%.pending}
      image_id=${stem##*-}
      uuid_hex=${stem%-*}
      uuid=$(printf '%s\n' "$uuid_hex" | sed -E 's/^(.{8})(.{4})(.{4})(.{4})(.{12})$/\1-\2-\3-\4-\5/')
      expected=$(printf '{"version":1,"class_photo_id":"%s","piwigo_image_id":%s}' "$uuid" "$image_id")
      expected_size=$((${#expected} + 1))
      [ "$(stat -c '%s' "$marker")" = "$expected_size" ] \
        && [ "$(wc -l < "$marker" | tr -d ' ')" = 1 ] \
        && [ "$(tail -c 1 "$marker" | od -An -tu1 | tr -d ' ')" = 10 ] \
        && [ "$(cat "$marker")" = "$expected" ] || exit 75
      chown "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" "$marker" || exit 76
      chmod 0660 "$marker" || exit 77
    elif printf '%s\n' "$name" | grep -Eq '^\.pending-[0-9a-f]{24}$'; then
      size=$(stat -c '%s' "$marker")
      owner=$(stat -c '%u' "$marker")
      [ "$size" -le 512 ] \
        && { [ "$owner" = "$configured_owner" ] || [ "$owner" = "$queue_owner" ]; } \
        || exit 78
    else
      exit 74
    fi
  done

  if find "$warmup_queue" -mindepth 1 -maxdepth 1 -name '.pending-*' -print -quit | grep -q .; then
    [ -f "$warmup_class" ] && [ ! -L "$warmup_class" ] || exit 79
  fi
  if [ -f "$warmup_class" ] && [ ! -L "$warmup_class" ]; then
    php -d display_errors=stderr -r \
      'define("PHPWG_ROOT_PATH", "/var/www/html/piwigo/"); require $argv[1]; ClassArchiveDerivativeWarmupQueue::pending();' \
      "$warmup_class" >/dev/null || exit 80
  fi

  # Recovery may have published a canonical marker. Re-validate its exact
  # payload before migrating ownership; a temp must no longer remain here.
  find "$warmup_queue" -mindepth 1 -maxdepth 1 -print | while IFS= read -r marker; do
    [ -f "$marker" ] && [ ! -L "$marker" ] || exit 81
    [ "$(stat -c '%h' "$marker")" = 1 ] && [ "$(stat -c '%a' "$marker")" = 660 ] || exit 82
    name=${marker##*/}
    printf '%s\n' "$name" | grep -Eq '^[0-9a-f]{12}[1-5][0-9a-f]{3}[89ab][0-9a-f]{15}-[1-9][0-9]{0,9}\.pending$' || exit 83
    stem=${name%.pending}
    image_id=${stem##*-}
    uuid_hex=${stem%-*}
    uuid=$(printf '%s\n' "$uuid_hex" | sed -E 's/^(.{8})(.{4})(.{4})(.{4})(.{12})$/\1-\2-\3-\4-\5/')
    expected=$(printf '{"version":1,"class_photo_id":"%s","piwigo_image_id":%s}' "$uuid" "$image_id")
    expected_size=$((${#expected} + 1))
    [ "$(stat -c '%s' "$marker")" = "$expected_size" ] \
      && [ "$(wc -l < "$marker" | tr -d ' ')" = 1 ] \
      && [ "$(tail -c 1 "$marker" | od -An -tu1 | tr -d ' ')" = 10 ] \
      && [ "$(cat "$marker")" = "$expected" ] || exit 84
    chown "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" "$marker" || exit 85
    chmod 0660 "$marker" || exit 86
  done
fi

if [ -e "$warmup_lock" ]; then
  [ -f "$warmup_lock" ] && [ ! -L "$warmup_lock" ] \
    && [ "$(stat -c '%h' "$warmup_lock")" = 1 ] \
    && [ "$(stat -c '%s' "$warmup_lock")" = 0 ] \
    && [ "$(stat -c '%a' "$warmup_lock")" = 660 ] || exit 87
  chown "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" "$warmup_lock" || exit 88
  chmod 0660 "$warmup_lock" || exit 89
fi
