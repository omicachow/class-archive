#!/bin/ash

set -eu

probe_path=/var/www/html/piwigo/upload/.class-archive-acl-probe
cleanup_probe() {
  rm -f "$probe_path/file"
  rmdir "$probe_path" 2>/dev/null || true
}
trap cleanup_probe 0 1 2 15

for media_path in \
  /var/www/html/piwigo/upload \
  /var/www/html/piwigo/galleries \
  /var/www/html/piwigo/_data
do
  mode="$(stat -c '%a' "$media_path")"
  case "$mode" in
    *0) ;;
    *)
      echo "Media path grants permissions to other users: $mode $media_path" >&2
      exit 1
      ;;
  esac

  if test -n "$(find "$media_path" -perm /0007 -print -quit)"; then
    echo "Media tree contains an entry accessible to other users: $media_path" >&2
    exit 1
  fi

  if test -n "$(find "$media_path" -type f -perm /0111 -print -quit)"; then
    echo "Media tree contains an executable file: $media_path" >&2
    exit 1
  fi

  acl="$(getfacl -cp "$media_path")"
  printf '%s\n' "$acl" | grep -Fqx 'user:nginx:rwx'
  printf '%s\n' "$acl" | grep -Fqx 'default:other::---'
done

mkdir "$probe_path"
: > "$probe_path/file"
probe_dir_acl="$(getfacl -cp "$probe_path")"
probe_file_acl="$(getfacl -cp "$probe_path/file")"
printf '%s\n' "$probe_dir_acl" | grep -Fqx 'user:nginx:rwx'
printf '%s\n' "$probe_dir_acl" | grep -Fqx 'other::---'
printf '%s\n' "$probe_file_acl" | grep -Eq '^user:nginx:(rw-|rwx[[:space:]]+#effective:rw-)$'
printf '%s\n' "$probe_file_acl" | grep -Fqx 'other::---'
cleanup_probe
trap - 0 1 2 15

echo 'MEDIA_PERMISSIONS=PASS'
