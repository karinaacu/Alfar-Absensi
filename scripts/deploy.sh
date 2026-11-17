#!/usr/bin/env bash
# Executed remotely by GitHub Actions. No credentials or application .env are copied.
set -Eeuo pipefail
app_path=${1:?Absolute deployment directory required}
expected_commit=${2:?Commit SHA required}
[[ "$app_path" = /* && "$expected_commit" =~ ^[a-f0-9]{40}$ ]] || exit 2
cd "$app_path"
test -f .env || { echo 'Provision the production .env before deployment.' >&2; exit 1; }
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
exec 9>storage/deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
test -z "$(git status --porcelain --untracked-files=no)" || { echo 'Tracked server files have local changes; deployment stopped.' >&2; exit 1; }
[[ "$(git branch --show-current)" = main ]] || { echo 'Server checkout must use main.' >&2; exit 1; }
command -v composer >/dev/null
command -v npm >/dev/null
php -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);'
# Never reset or discard server changes. A read-only deploy key must allow server-side Git fetch.
git fetch origin refs/heads/main:refs/remotes/origin/main
[[ "$(git rev-parse origin/main)" = "$expected_commit" ]] || { echo 'main advanced since this run; deploy the latest successful run.' >&2; exit 1; }
git merge-base --is-ancestor HEAD "$expected_commit"
# This intentionally simple in-place deployment requires a short maintenance window.
php artisan down --retry=30
trap 'result=$?; if (( result != 0 )); then echo "Deployment failed; application stays in maintenance. Inspect logs, fix, then run php artisan up." >&2; fi; exit "$result"' EXIT
git merge --ff-only "$expected_commit"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
php artisan migrate --force
if [[ ! -e public/storage ]]; then php artisan storage:link; fi
php artisan optimize
php artisan queue:restart
php artisan up
trap - EXIT
echo "Deployed $expected_commit"
