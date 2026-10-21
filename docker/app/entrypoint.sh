#!/bin/sh
# Gets a checkout ready, then runs the command (the dev server by default):
# dependencies, .env and its key, migrations as the migrate account, and a
# frontend build. Each step is skipped when already done, so restarts are
# quick. vendor/ and node_modules/ live in volumes (Linux builds, apart from
# the host's).
set -eu
cd /app

[ -f vendor/autoload.php ] || composer install --no-interaction
[ -f node_modules/.package-lock.json ] || npm ci

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --ansi

echo "Waiting for the database..."
tries=0
until php artisan migrate:status --database=mariadb_migrate >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -lt 30 ] || { echo "The database didn't answer; check docker compose logs mariadb." >&2; exit 1; }
    sleep 2
done

php artisan migrate --database=mariadb_migrate --force
[ -f public/build/manifest.json ] || npm run build

exec "$@"
