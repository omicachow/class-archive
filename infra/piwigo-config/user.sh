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
