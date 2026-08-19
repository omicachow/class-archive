#!/usr/bin/env sh
set -eu

project_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$project_root"
exec docker compose --env-file .env.piwigo -f infra/docker-compose.yml \
  exec -T --user nginx piwigo php /workspace/infra/scripts/run-maintenance.php "$@"
